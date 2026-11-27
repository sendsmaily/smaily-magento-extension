<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\EmailTestMode;
use Smaily\Connect\Model\Engine\PrivacyRequestBudget;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

/**
 * CON-458: a Campaign Intelligence email's test send (contract §19) and
 * test mode (§20) in the admin's words, through the real engine client on
 * a faked engine. After every call the engine answered, the card's state
 * is read again (§12); a refusal is never sent again.
 */
class EmailTestModeTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    protected function tearDown(): void
    {
        StoreLocale::reset();
    }

    /**
     * @dataProvider setupItems
     * @param array<string, mixed> $item
     * @param array{test_mode: bool, mode: string, status: string, notice: string} $expected
     */
    public function testTheCardShowsTheSetupStateInPlainWords(array $item, array $expected): void
    {
        self::assertSame($expected, EmailTestMode::describe($item));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function setupItems(): array
    {
        return [
            'off: test mode on' => [
                ['setup_state' => 'off', 'go_live_blockers' => ['no_workflow']],
                ['test_mode' => true, 'mode' => 'off', 'status' => '', 'notice' => ''],
            ],
            'test, waiting for the sender domain' => [
                ['setup_state' => 'test', 'notice' => 'starts_when_sender_verified'],
                [
                    'test_mode' => true,
                    'mode' => 'test',
                    'status' => '',
                    'notice' => 'Starts when your sender domain is verified in Smaily',
                ],
            ],
            'live at the ramp\'s second step' => [
                ['setup_state' => 'live', 'daily_limit' => 25, 'notice' => null],
                [
                    'test_mode' => false,
                    'mode' => 'active',
                    'status' => 'Sending to real customers · up to 25 emails today',
                    'notice' => '',
                ],
            ],
            'live without a limit' => [
                ['setup_state' => 'live', 'daily_limit' => null],
                ['test_mode' => false, 'mode' => 'active', 'status' => 'Sending to real customers', 'notice' => ''],
            ],
            'stopped until Smaily reviews it' => [
                [
                    'setup_state' => 'stopped',
                    'stop_reason' => 'auto_stop_complaint',
                    'go_live_blockers' => ['stopped_needs_review'],
                ],
                [
                    'test_mode' => true,
                    'mode' => 'stopped',
                    'status' => 'Smaily reviews this stop before the email can send again',
                    'notice' => '',
                ],
            ],
            'test mode: the pill it showed before' => [
                ['setup_state' => 'test'],
                ['test_mode' => true, 'mode' => 'test', 'status' => '', 'notice' => ''],
            ],
            'an unknown notice shows nothing' => [
                ['setup_state' => 'set_up', 'notice' => 'something_new'],
                ['test_mode' => true, 'mode' => 'off', 'status' => '', 'notice' => ''],
            ],
        ];
    }

    public function testAnEngineWithoutSetupStatesGivesNoCardState(): void
    {
        self::assertSame([], EmailTestMode::states(['configs' => []]));
        self::assertSame(
            ['replenish_due'],
            array_keys(EmailTestMode::states(['setup' => [['trigger_key' => 'replenish_due'], 'x']]))
        );
    }

    public function testAnAcceptedTestSendSaysSoAndShowsTheStateReadAfter(): void
    {
        $answer = $this->testMode([
            new Response(200, [], '{"ok":true,"trigger_key":"replenish_due","accepted_at":"2026-11-26T08:00:00Z"}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'test']),
        ])->testSend('replenish_due', 'owner@shop.example');

        self::assertSame(200, $answer['status']);
        self::assertSame([
            'ok' => true,
            'message' => 'Smaily accepted the test email. Check the inbox.',
            'state' => ['test_mode' => true, 'mode' => 'test', 'status' => '', 'notice' => ''],
        ], $answer['body']);
        self::assertSame(
            ['/api/v1/automations/replenish_due/test-send', '/api/v1/automations/config'],
            $this->paths()
        );
    }

    /**
     * @dataProvider refusedTestSends
     * @param string[] $reasons
     */
    public function testARefusedTestSendIsAnsweredInPlainWordsAndNotSentAgain(
        Response $refusal,
        int $status,
        string $message,
        array $reasons,
        bool $stateRead
    ): void {
        $answer = $this->testMode([
            $refusal,
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'test']),
        ])->testSend('replenish_due', 'owner@shop.example');

        self::assertSame($status, $answer['status']);
        self::assertFalse($answer['body']['ok']);
        self::assertSame($message, $answer['body']['message']);
        self::assertSame($reasons, $answer['body']['reasons']);
        self::assertSame($stateRead, $answer['body']['state'] !== null);
        self::assertCount($stateRead ? 2 : 1, $this->history, 'A refused test send is never sent again.');
    }

    /**
     * @return array<string, array{Response, int, string, string[], bool}>
     */
    public static function refusedTestSends(): array
    {
        return [
            'not ready' => [
                new Response(409, [], (string)json_encode(['error' => 'not_ready', 'details' => [
                    'reasons' => ['no_workflow', 'workflow_not_active', 'no_products', 'brand_new', 'also_new'],
                ]])),
                409,
                'The test email cannot be sent yet:',
                [
                    'No Smaily workflow sends this email yet',
                    'The email\'s Smaily workflow is not active yet, for example because your sender domain'
                        . ' is not verified',
                    'No customer has recommendations for this email yet',
                    'Setup is not complete',
                ],
                true,
            ],
            'sender refused' => [
                new Response(409, [], (string)json_encode(['error' => 'sender_refused', 'details' => [
                    'sender_state' => 'unverified',
                    'from_address' => 'news@shop.example',
                ]])),
                409,
                'Smaily refused to send from news@shop.example. Check your sender domain in Smaily.',
                [],
                true,
            ],
            'not an address' => [
                new Response(422, [], '{"error":"validation_failed","errors":[{"field":"email","message":"x"}]}'),
                422,
                'Enter one valid email address.',
                [],
                true,
            ],
            'too many' => [
                new Response(429, [], '{"error":"rate_limit_exceeded","retry_after_seconds":3000}'),
                429,
                'Too many test emails were sent in the last hour. Try again later.',
                [],
                false,
            ],
            'Smaily did not answer' => [
                new Response(502, [], '{"error":"smaily_unavailable"}'),
                502,
                'Smaily did not answer, so nothing was sent. Press Send test email to try again.',
                [],
                false,
            ],
            'not offered' => [
                new Response(404, [], '{"error":"unknown_automation"}'),
                404,
                'Campaign Intelligence does not offer this for this email yet.',
                [],
                true,
            ],
            'connection not accepted' => [
                new Response(401, [], '{"error":"unauthorised"}'),
                401,
                'Campaign Intelligence did not accept this store\'s connection.'
                    . ' Connect again under Settings > Intelligence.',
                [],
                false,
            ],
            'account not active' => [
                new Response(403, [], '{"error":"tenant_inactive"}'),
                403,
                'Your Campaign Intelligence account is not active, so its automations cannot be read or saved.'
                    . ' Once Smaily tells you the account is active, press Check again under'
                    . ' Settings > Intelligence.',
                [],
                false,
            ],
        ];
    }

    public function testTurningTestModeOffSwitchesRealSendsOnAndShowsTheStateReadAfter(): void
    {
        $answer = $this->testMode([
            new Response(200, [], '{"ok":true,"changed":true,"setup_state":"live","daily_limit":10}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'live', 'daily_limit' => 10]),
        ])->switchTestMode('replenish_due', false, '17');

        self::assertSame(200, $answer['status']);
        self::assertSame([
            'ok' => true,
            'message' => '',
            'state' => [
                'test_mode' => false,
                'mode' => 'active',
                'status' => 'Sending to real customers · up to 10 emails today',
                'notice' => '',
            ],
        ], $answer['body']);
        self::assertSame(
            '{"live":true,"confirmed":true,"switched_by":"17"}',
            (string)$this->history[0]['request']->getBody()
        );
    }

    public function testTurningTestModeOnAgainSendsLiveFalseOnly(): void
    {
        $this->testMode([
            new Response(200, [], '{"ok":true,"changed":true,"setup_state":"test"}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'test']),
        ])->switchTestMode('replenish_due', true, '17');

        self::assertSame('{"live":false}', (string)$this->history[0]['request']->getBody());
    }

    /**
     * A stop Smaily has not reviewed, or any other blocker, keeps test mode
     * on and is listed in plain words — in the admin's language.
     */
    public function testABlockedSwitchListsTheBlockersInTheAdminsLanguage(): void
    {
        $refusal = (string)json_encode(['error' => 'go_live_blocked', 'details' => [
            'blockers' => ['stopped_needs_review', 'missing_subject', 'operator_switch_required'],
        ]]);
        $stopped = [
            'trigger_key' => 'replenish_due',
            'setup_state' => 'stopped',
            'go_live_blockers' => ['stopped_needs_review'],
        ];

        $answer = $this->testMode([new Response(409, [], $refusal), $this->configAnswer($stopped)])
            ->switchTestMode('replenish_due', false, '17');

        self::assertSame(409, $answer['status']);
        self::assertSame('Test mode stays on:', $answer['body']['message']);
        self::assertSame([
            'Smaily reviews this stop before the email can send again',
            'The email has no subject',
            'Only Smaily can turn off test mode for this email',
        ], $answer['body']['reasons']);
        self::assertSame(
            'Smaily reviews this stop before the email can send again',
            $answer['body']['state']['status']
        );
        self::assertSame('stopped', $answer['body']['state']['mode'], 'The pill still says Stopped (CON-466).');

        StoreLocale::use('et_EE');
        $answer = $this->testMode([new Response(409, [], $refusal), $this->configAnswer($stopped)])
            ->switchTestMode('replenish_due', false, '17');

        self::assertSame('Testrežiim jääb sisse:', $answer['body']['message']);
        self::assertSame([
            'Smaily vaatab peatamise üle, enne kui kiri saab uuesti saata',
            'Kirjal pole teemat',
            'Selle kirja testrežiimi saab välja lülitada ainult Smaily',
        ], $answer['body']['reasons']);
    }

    /**
     * Every phrase the card shows has its Estonian twin.
     */
    public function testEveryRefusalIsTranslated(): void
    {
        StoreLocale::use('et_EE');

        $answer = $this->testMode([new Response(502, [], '{"error":"smaily_unavailable"}')])
            ->testSend('replenish_due', 'owner@shop.example');

        self::assertSame(
            'Smaily ei vastanud, nii et midagi ei saadetud. Uuesti proovimiseks vajuta Saada testkiri.',
            $answer['body']['message']
        );
        self::assertSame(
            'Saadab päris klientidele · täna kuni 25 kirja',
            EmailTestMode::describe(['setup_state' => 'live', 'daily_limit' => 25])['status']
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function configAnswer(array $item): Response
    {
        return new Response(200, [], (string)json_encode(['configs' => [], 'setup' => [$item]]));
    }

    /**
     * @return string[]
     */
    private function paths(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getUri()->getPath(),
            $this->history
        );
    }

    /**
     * The model on the real engine client, its HTTP answered by `$responses`
     * in turn (the mocked HttpClientFactory pattern; no request leaves).
     *
     * @param Response[] $responses
     */
    private function testMode(array $responses): EmailTestMode
    {
        $this->history = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config): HttpClient => new HttpClient(['handler' => $handlerStack] + $config)
        );
        $settings = $this->createMock(Settings::class);
        $settings->method('getApiKey')->willReturn('sk_test_key');
        $settings->method('getEndpoint')->willReturn(null);
        $settings->method('getEngineBaseUrl')->willReturn('https://engine.example');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->expects(self::never())->method('sleep');

        return new EmailTestMode(new Client(
            $settings,
            $factory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $sleeper,
            new StorefrontScript($storeManager, $this->createMock(Http::class)),
            $this->createMock(ConnectionHealth::class),
            $this->createMock(PrivacyRequestBudget::class)
        ));
    }
}
