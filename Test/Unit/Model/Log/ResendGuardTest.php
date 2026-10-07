<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

class ResendGuardTest extends TestCase
{
    private ResendGuard $guard;
    private EventQueue&MockObject $eventQueue;

    protected function setUp(): void
    {
        $this->eventQueue = $this->createMock(EventQueue::class);
        $this->guard = new ResendGuard($this->eventQueue);
    }

    public function testWithdrawnRowIsRefusedFromTheStoredMarker(): void
    {
        $reason = $this->guard->refusalReason(Collection::SOURCE_SMAILY, 7, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_SENT,
            'last_response' => EventQueue::CANCELLED_RESPONSE,
        ]);

        self::assertSame(ResendGuard::REASON_WITHDRAWN, $reason);
    }

    public function testWithdrawnRowIsRefusedFromTheDerivedGridStatus(): void
    {
        $reason = $this->guard->refusalReason(Collection::SOURCE_SMAILY, 7, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Collection::STATUS_WITHDRAWN,
        ]);

        self::assertSame(ResendGuard::REASON_WITHDRAWN, $reason);
    }

    public function testErasedRowIsRefusedInBothQueues(): void
    {
        foreach ([Collection::SOURCE_SMAILY, Collection::SOURCE_INTELLIGENCE] as $source) {
            $reason = $this->guard->refusalReason($source, 3, [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => Erasure::PLACEHOLDER,
                'status' => Event::STATUS_FAILED,
            ]);

            self::assertSame(ResendGuard::REASON_ERASED, $reason, $source);
        }
    }

    public function testContactSyncAndIngestRowsAreSafe(): void
    {
        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_SMAILY, 3, [
            'type' => EventType::CONTACT_SYNC,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_FAILED,
        ]));
        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_INTELLIGENCE, 4, [
            'type' => 'catalog',
            'entity_id' => 'SKU-1',
            'status' => Event::STATUS_FAILED,
        ]));
    }

    public function testOnlyAutomationRowsCostASupersedeLookup(): void
    {
        $asked = [];
        $this->eventQueue->method('laterDeliveredOfSameTrigger')->willReturnCallback(
            static function (array $entityIds) use (&$asked): array {
                $asked = $entityIds;
                return [];
            }
        );

        $this->guard->refusalReasons(Collection::SOURCE_SMAILY, [
            3 => [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ],
            5 => [
                'type' => EventType::AUTOMATION_TRIGGER,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ],
        ]);

        self::assertSame([5 => 'jane@example.com'], $asked);
    }

    public function testAutomationRowIsRefusedWhenALaterRowOfTheSameTriggerWasSent(): void
    {
        $this->eventQueue->method('laterDeliveredOfSameTrigger')->willReturn([5]);

        self::assertSame(ResendGuard::REASON_SUPERSEDED, $this->guard->refusalReason(
            Collection::SOURCE_SMAILY,
            5,
            [
                'type' => EventType::AUTOMATION_TRIGGER,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ]
        ));
    }

    public function testAutomationRowIsSafeWhenTheLaterRowIsAnotherTrigger(): void
    {
        self::assertSame('', $this->guard->refusalReason(Collection::SOURCE_SMAILY, 5, [
            'type' => EventType::AUTOMATION_TRIGGER,
            'entity_id' => 'jane@example.com',
            'status' => Event::STATUS_FAILED,
        ]));
    }

    public function testARowThatNeverFailedHasNothingToSendAgain(): void
    {
        self::assertSame(
            ResendGuard::REASON_NOT_FAILED,
            $this->guard->refusalReason(Collection::SOURCE_SMAILY, 3, [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_PENDING,
            ])
        );
    }

    public function testMassRetryLearnsWhichSelectedRowsToSkip(): void
    {
        $refused = $this->guard->refusalReasons(Collection::SOURCE_SMAILY, [
            3 => [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => Erasure::PLACEHOLDER,
                'status' => Event::STATUS_FAILED,
            ],
            4 => [
                'type' => EventType::CONTACT_SYNC,
                'entity_id' => 'jane@example.com',
                'status' => Event::STATUS_FAILED,
            ],
        ]);

        self::assertSame([3 => ResendGuard::REASON_ERASED], $refused);
    }

    public function testEveryReasonHasItsOwnSentence(): void
    {
        $messages = [
            (string)$this->guard->message(ResendGuard::REASON_WITHDRAWN),
            (string)$this->guard->message(ResendGuard::REASON_SUPERSEDED),
            (string)$this->guard->message(ResendGuard::REASON_ERASED),
            (string)$this->guard->message(ResendGuard::REASON_NOT_FAILED),
            (string)$this->guard->message(ResendGuard::REASON_NIGHTLY),
        ];

        self::assertCount(5, array_unique($messages));
        self::assertStringContainsString('withdrawn', $messages[0]);
        self::assertStringContainsString('twice', $messages[1]);
        self::assertStringContainsString('erased', $messages[2]);
        self::assertStringContainsString('failed', $messages[3]);
        self::assertStringContainsString('each night', $messages[4]);
    }

    /**
     * PRO-3854: a nightly catalog manifest row holds that night's list; the
     * next night sends a new one, so a failed one is never sent again.
     */
    public function testANightlyCatalogManifestRowIsNeverSentAgain(): void
    {
        $refused = $this->guard->refusalReasons(Collection::SOURCE_INTELLIGENCE, [
            5 => ['type' => CatalogManifest::DOMAIN, 'entity_id' => null, 'status' => Event::STATUS_FAILED],
            6 => ['type' => CatalogManifest::DOMAIN, 'entity_id' => null, 'status' => Event::STATUS_SENT],
            7 => ['type' => Client::DOMAIN_CATALOG, 'entity_id' => '7', 'status' => Event::STATUS_FAILED],
        ]);

        self::assertSame([5 => ResendGuard::REASON_NIGHTLY, 6 => ResendGuard::REASON_NIGHTLY], $refused);
    }
}
