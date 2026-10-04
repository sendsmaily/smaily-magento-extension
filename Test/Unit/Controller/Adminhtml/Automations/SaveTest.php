<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Automations;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Automations\Save;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;

/**
 * PRO-3734: after a save, the Automations tab shows each trigger's state as
 * the engine stored it (contract §12), not the state the merchant asked for —
 * the engine keeps a trigger that asks for real sends in test mode until a
 * Smaily operator switches real sends on (§13).
 */
class SaveTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $response = [];

    public function testASaveAskingForRealSendsAnswersTheTestModeTheEngineStored(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('putAutomationsConfig')
            ->with(self::callback(static fn (array $rows): bool => $rows[0]['enabled'] === true
                && $rows[0]['test_mode'] === false));
        $client->method('getAutomationsConfig')->willReturn(['configs' => [
            ['trigger_key' => 'replenish_due', 'enabled' => true, 'test_mode' => true],
            ['trigger_key' => 'winback_risk', 'enabled' => true, 'test_mode' => false],
        ]]);

        $this->controller($client, [
            'replenish_due' => ['enabled' => '1', 'workflow_id' => '123'],
            'winback_risk' => ['enabled' => '1', 'workflow_id' => '456'],
            'browse_abandon' => ['workflow_id' => ''],
        ])->execute();

        self::assertSame(
            [
                'saved' => true,
                'errors' => [],
                'states' => [
                    'replenish_due' => ['enabled' => true, 'test_mode' => true],
                    // Real sends an operator already switched on stay on.
                    'winback_risk' => ['enabled' => true, 'test_mode' => false],
                    // No stored row: the page's fail-closed defaults.
                    'browse_abandon' => ['enabled' => false, 'test_mode' => true],
                ],
            ],
            $this->response
        );
    }

    public function testAFailedReadAfterTheSaveAnswersNoStates(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getAutomationsConfig')->willThrowException(new EngineException('timeout'));

        $this->controller($client, ['replenish_due' => ['enabled' => '1', 'workflow_id' => '123']])->execute();

        self::assertSame(['saved' => true, 'errors' => [], 'states' => null], $this->response);
    }

    public function testARefusedSaveDoesNotReadTheStoredState(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('putAutomationsConfig')->willThrowException(new EngineException('down'));
        $client->expects(self::never())->method('getAutomationsConfig');

        $this->controller($client, ['replenish_due' => ['workflow_id' => '']])->execute();

        self::assertSame(['saved' => false, 'errors' => ['Saving failed: down']], $this->response);
    }

    /**
     * @param array<string, array<string, string>> $triggers
     */
    private function controller(Client $client, array $triggers): Save
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->with('triggers')->willReturn($triggers);
        $request->method('isXmlHttpRequest')->willReturn(true);

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_JSON)->willReturn($result);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $context->method('getMessageManager')->willReturn($this->createMock(ManagerInterface::class));

        $smailyClient = $this->createMock(SmailyClient::class);
        $smailyClient->method('getAutomationWorkflows')->willThrowException(new SmailyClientException('n/a'));
        $smailyClientProvider = $this->createMock(SmailyClientProvider::class);
        $smailyClientProvider->method('forStore')->willReturn($smailyClient);

        return new Save($context, $client, $smailyClientProvider, new ConfigRowNormalizer());
    }
}
