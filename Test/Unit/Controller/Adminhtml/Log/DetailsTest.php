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
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Api\Queue\PausableEventHandlerInterface;
use Smaily\Connect\Controller\Adminhtml\Log\Details;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Log\AccountWait;
use Smaily\Connect\Model\Log\AttemptHistory;
use Smaily\Connect\Model\Log\EngineStore;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

/**
 * PRO-3565: the Details panel offers "Send again" only where the guard
 * (PRO-2454) clears the row — never where it would double-send — and hands
 * the template the row's status pill and its attempts in order.
 */
class DetailsTest extends TestCase
{
    use EngineConnectionsStub;

    /** @var array<string, mixed> */
    private array $blockData = [];

    /** Whether Campaign Intelligence refuses the account in render(). */
    private bool $refused = false;

    /** The connections render() reads; null: one store, the primary only. */
    private ?Connections $connections = null;

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

    /**
     * PRO-3753: while the account is refused, a pending engine-bound row is
     * not sent at the next flush — an ingest row, or a row of a paused
     * marketing handler — so Details says it waits for the account.
     */
    public function testAPendingEngineBoundRowWaitsForTheAccountWhileItIsRefused(): void
    {
        $this->refused = true;
        $pending = ['status' => 'pending', 'attempts' => '0'];

        $this->render(['source' => 'intelligence', 'type' => 'catalog'] + $pending + $this->failedRow(), '');
        self::assertTrue($this->blockData['waits_for_account']);

        $this->render(['type' => 'engine.profiling_consent'] + $pending + $this->failedRow(), '');
        self::assertTrue($this->blockData['waits_for_account']);

        $this->render($pending + $this->failedRow(), '');
        self::assertFalse($this->blockData['waits_for_account'], 'A contact sync is sent meanwhile');

        $this->render(['source' => 'intelligence', 'type' => 'catalog'] + $this->failedRow(), '');
        self::assertFalse($this->blockData['waits_for_account'], 'A failed row waits for nothing');

        $this->refused = false;
        $this->render(['source' => 'intelligence', 'type' => 'catalog'] + $pending + $this->failedRow(), '');
        self::assertFalse($this->blockData['waits_for_account']);
        $this->render(['type' => 'engine.profiling_consent'] + $pending + $this->failedRow(), '');
        self::assertFalse($this->blockData['waits_for_account']);
    }

    /**
     * CON-237: under one Campaign Intelligence connection the panel shows
     * no engine store, as before.
     */
    public function testOneConnectionShowsNoEngineStore(): void
    {
        $this->render(['source' => 'intelligence', 'type' => 'catalog', 'store_id' => '1'] + $this->failedRow(), '');

        self::assertNull($this->blockData['engine_store']);
    }

    /**
     * CON-237: with several connections an engine row names the engine
     * store it goes to and the websites that store serves — an ingest row
     * by its store view (NULL: the primary), an engine row of the
     * marketing queue by its website; a contact sync names none, and a row
     * no connection serves says so.
     */
    public function testSeveralConnectionsNameTheEngineStoreOfAnEngineRow(): void
    {
        $this->connections = $this->separateStores();
        $ingest = ['source' => 'intelligence', 'type' => 'catalog'] + $this->failedRow();

        $this->render(['store_id' => '3'] + $ingest, '');
        self::assertSame('Shop LV (Latvia, Lithuania)', $this->blockData['engine_store']);

        $this->render(['store_id' => null] + $ingest, '');
        self::assertSame('Shop EE (Estonia)', $this->blockData['engine_store']);

        $this->render(['type' => 'engine.identity_merge', 'website_id' => '3'] + $this->failedRow(), '');
        self::assertSame('Shop LV (Latvia, Lithuania)', $this->blockData['engine_store']);

        $this->render($this->failedRow(), '');
        self::assertNull($this->blockData['engine_store'], 'A contact sync goes to Smaily');

        $this->render(['store_id' => '9'] + $ingest, '');
        self::assertSame(
            "None: no Campaign Intelligence store is connected for this row's website.",
            $this->blockData['engine_store']
        );
    }

    /**
     * CON-237: a pending ingest row waits for the account of its own
     * connection.
     */
    public function testAPendingIngestRowWaitsForTheAccountOfItsOwnConnection(): void
    {
        $this->connections = $this->separateStores(latviaRefused: true);
        $pending = ['source' => 'intelligence', 'type' => 'catalog', 'status' => 'pending', 'attempts' => '0'];

        $this->render(['store_id' => '2'] + $pending + $this->failedRow(), '');
        self::assertTrue($this->blockData['waits_for_account']);

        $this->render(['store_id' => '1'] + $pending + $this->failedRow(), '');
        self::assertFalse($this->blockData['waits_for_account']);
    }

    public function testAMissingRowOffersNothing(): void
    {
        $this->render(null, '');

        self::assertNull($this->blockData['resend_url']);
        self::assertSame([], $this->blockData['history']);
    }

    /**
     * Separate stores: the primary "Shop EE" serves website 1 (store view
     * 1); "Shop LV" websites 2 and 3 (store views 2 and 3).
     */
    private function separateStores(bool $latviaRefused = false): Connections
    {
        $estonia = $this->createMock(EngineSettings::class);
        $estonia->method('getTenantName')->willReturn('Shop EE');
        $latvia = $this->createMock(EngineSettings::class);
        $latvia->method('getTenantName')->willReturn('Shop LV');
        $latvia->method('isRefused')->willReturn($latviaRefused);

        return $this->engineConnections([
            [$this->engineConnection(0, $estonia, [1], [1], 1), null],
            [$this->engineConnection(2, $latvia, [2, 3], [2, 3], 2), null],
        ]);
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

        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isRefused')->willReturn($this->refused);
        $paused = $this->createMock(PausableEventHandlerInterface::class);
        $paused->method('isPaused')->willReturn($this->refused);
        $handlerPool = new HandlerPool([
            'contact.sync' => $this->createMock(EventHandlerInterface::class),
            'engine.profiling_consent' => $paused,
        ]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturnCallback(
            function (mixed $websiteId): WebsiteInterface {
                $website = $this->createMock(WebsiteInterface::class);
                $website->method('getName')->willReturn(
                    [1 => 'Estonia', 2 => 'Latvia', 3 => 'Lithuania'][(int)$websiteId] ?? ''
                );

                return $website;
            }
        );
        $engineStore = new EngineStore(
            $this->connections ?? $this->oneEngineConnection($engineSettings),
            $storeManager
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
            $url,
            new AccountWait($handlerPool, $engineStore),
            $engineStore
        ))->execute();
    }
}
