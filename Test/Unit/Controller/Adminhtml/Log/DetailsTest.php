<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Log\Details;
use Smaily\Connect\Model\Log\AttemptHistory;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

/**
 * PRO-3565: the Details panel offers "Send again" only where the guard
 * (PRO-2454) clears the row — never where it would double-send — and hands
 * the template the row's status pill and its attempts in order.
 */
class DetailsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $blockData = [];

    public function testAFailedRowTheGuardClearsOffersSendAgain(): void
    {
        $this->render($this->failedRow(), '');

        self::assertSame('https://admin.test/smaily_connect/log/resend/log_id/smaily-7/', $this->blockData['resend_url']);
        self::assertNull($this->blockData['refusal']);
        self::assertSame('failed', $this->blockData['status_pill']);
        self::assertSame(
            [AttemptHistory::QUEUED, AttemptHistory::FAILED],
            array_column($this->blockData['history'], 'result')
        );
    }

    public function testAFailedRowTheGuardRefusesOffersNothingAndSaysWhy(): void
    {
        $this->render($this->failedRow(), ResendGuard::REASON_SUPERSEDED);

        self::assertNull($this->blockData['resend_url']);
        self::assertSame('superseded sentence', (string)$this->blockData['refusal']);
    }

    public function testADeliveredRowOffersNothing(): void
    {
        $this->render(['status' => 'sent'] + $this->failedRow(), ResendGuard::REASON_NOT_FAILED);

        self::assertNull($this->blockData['resend_url']);
        self::assertNull($this->blockData['refusal']);
        self::assertSame('sent', $this->blockData['status_pill']);
    }

    /**
     * PRO-2511: a delivered automation row that a later delivery of the
     * same kind superseded was never a candidate for sending again, so its
     * drawer reads as delivered, without the refusal sentence.
     */
    public function testADeliveredRowALaterDeliverySupersededReadsAsDelivered(): void
    {
        $this->render(['status' => 'sent'] + $this->automationRow(), ResendGuard::REASON_SUPERSEDED);

        self::assertNull($this->blockData['resend_url']);
        self::assertNull($this->blockData['refusal']);
        self::assertSame('sent', $this->blockData['status_pill']);
    }

    public function testAFailedRowALaterDeliverySupersededSaysWhyItIsNotSentAgain(): void
    {
        $this->render($this->automationRow(), ResendGuard::REASON_SUPERSEDED);

        self::assertNull($this->blockData['resend_url']);
        self::assertSame('superseded sentence', (string)$this->blockData['refusal']);
    }

    public function testAWithdrawnReminderSaysItWasWithdrawn(): void
    {
        $this->render(['status' => 'withdrawn'] + $this->automationRow(), ResendGuard::REASON_WITHDRAWN);

        self::assertNull($this->blockData['resend_url']);
        self::assertSame('withdrawn sentence', (string)$this->blockData['refusal']);
    }

    /**
     * PRO-3765: a profiling-consent row's Entity is the shopper's keyed
     * hash, shown short as in the grid; any other Entity as stored.
     */
    public function testTheEntityReadsAsTheGridShowsIt(): void
    {
        $hash = hash_hmac('sha256', 'u1@example.invalid', 'unit-test-crypt-key');
        $this->render(['type' => 'engine.profiling_consent', 'entity_id' => $hash] + $this->failedRow(), '');
        self::assertSame(substr($hash, 0, 12) . '…', $this->blockData['entity']);

        $this->render($this->failedRow(), '');
        self::assertSame('jane@example.com', $this->blockData['entity']);
    }

    public function testAMissingRowOffersNothing(): void
    {
        $this->render(null, '');

        self::assertNull($this->blockData['resend_url']);
        self::assertSame([], $this->blockData['history']);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedRow(): array
    {
        return [
            'id' => '7',
            'source' => 'smaily',
            'type' => 'contact.sync',
            'entity_id' => 'jane@example.com',
            'status' => 'failed',
            'attempts' => '1',
            'payload' => '{"email":"jane@example.com"}',
            'last_error' => 'Connection timed out',
            'created_at' => '2026-10-02 10:00:00',
            'updated_at' => '2026-10-02 10:05:00',
            'next_retry_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function automationRow(): array
    {
        return [
            'type' => 'automation.trigger',
            'payload' => '{"trigger_type":"abandoned_cart"}',
        ] + $this->failedRow();
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function render(?array $row, string $refusalReason): void
    {
        require_once __DIR__ . '/../../../Support/Stub/RawFactory.php';
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->with('log_id')->willReturn('smaily-7');
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $raw = $this->createMock(Raw::class);
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $block = $this->createMock(Template::class);
        $block->method('toHtml')->willReturn('');
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('createBlock')->willReturnCallback(
            function (string $type, string $name, array $arguments) use ($block) {
                $this->blockData = $arguments['data'];

                return $block;
            }
        );

        $rowLoader = $this->createMock(QueueRowLoader::class);
        $rowLoader->method('load')->willReturn($row);
        $guard = $this->createMock(ResendGuard::class);
        $guard->method('refusalReason')->willReturn($refusalReason);
        $guard->method('message')->willReturn(new Phrase($refusalReason . ' sentence'));
        $resend = $this->createMock(Resend::class);
        $resend->method('recordOf')->willReturn(null);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            fn (string $route, array $params) => 'https://admin.test/' . $route . '/log_id/' . $params['log_id'] . '/'
        );

        (new Details(
            $context,
            $rawFactory,
            $layout,
            $rowLoader,
            new PayloadRedactor(),
            new FailureMessage(new PayloadRedactor()),
            $guard,
            $resend,
            new PayloadDecoder(new Json()),
            new QueueStatusOptions(),
            new AttemptHistory(),
            new StatusPill(),
            $url
        ))->execute();
    }
}
