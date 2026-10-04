<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Log;

use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

/**
 * PRO-2509: the Log's error filter matches the text the error column shows
 * (FailureMessage::forDisplay), not the stored value.
 */
class ErrorFilterTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $smaily = EventResource::TABLE_NAME;
        $intelligence = IngestEventResource::TABLE_NAME;
        $this->insert($smaily, 'event_type', 'permanent_http_400: Address jane@example.com is not valid');
        $this->insert($smaily, 'event_type', 'Connection timed out');
        $this->insert($smaily, 'event_type', 'permanent_http_401: Smaily API credentials were rejected');
        $this->insert($intelligence, 'domain', '{"api_key":"hunter2","message":"bad key"}');
        $this->insert($intelligence, 'domain', 'Engine request failed with HTTP 422: price: must be numeric');
    }

    protected function tearDown(): void
    {
        StoreLocale::reset();
    }

    public function testFindsARefusalByTheServersWordsAndNotByTheInternalPrefix(): void
    {
        self::assertSame(['smaily-1'], $this->filtered('Address jane'));
        self::assertSame(['smaily-1'], $this->filtered('ADDRESS JANE@EXAMPLE.COM IS NOT VALID'));
        self::assertSame([], $this->filtered('permanent'));
        self::assertSame([], $this->filtered('http_400'));
        self::assertSame([], $this->filtered('400: Address'));
    }

    public function testFindsUnprefixedErrorsAsStored(): void
    {
        self::assertSame(['smaily-2'], $this->filtered('timed out'));
        self::assertSame(['intelligence-2'], $this->filtered('HTTP 422: price'));
    }

    public function testFindsAClientMessageByTheWordsTheAdminReads(): void
    {
        StoreLocale::use('et_EE');

        self::assertSame(['smaily-3'], $this->filtered('lükkas API kasutajaandmed'));
    }

    /**
     * The column shows a JSON error with its secrets redacted; the filter
     * must not find the row by what the column hides.
     */
    public function testARedactedErrorIsNotFoundByWhatTheColumnHides(): void
    {
        self::assertSame([], $this->filtered('hunter2'));
        self::assertSame([], $this->filtered('api_key'));
    }

    public function testTheGridsEscapedWildcardsStayLiteral(): void
    {
        // Only the hidden prefix and the redacted key hold an underscore.
        self::assertSame([], $this->filtered('_'));
        self::assertSame([], $this->filtered('%'));
    }

    private function insert(string $table, string $typeColumn, string $lastError): void
    {
        static $uuid = 0;
        $this->connection->insert($table, [
            $typeColumn => 'x',
            'event_uuid' => sprintf('00000000-0000-0000-0000-%012d', ++$uuid),
            'payload' => '{}',
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => $lastError,
        ]);
    }

    /**
     * The grid's text filter, as Magento\Ui\Component\Filters\Type\Input
     * hands it to the collection.
     *
     * @return string[] log ids of the matching rows
     */
    private function filtered(string $typed): array
    {
        /** @var Collection $collection */
        $collection = $this->objectManager->create(Collection::class);
        $collection->addFieldToFilter(
            'last_error',
            ['like' => '%' . str_replace(['%', '_'], ['\%', '\_'], $typed) . '%']
        );
        $ids = array_column($collection->getData(), 'log_id');
        sort($ids);

        return $ids;
    }
}
