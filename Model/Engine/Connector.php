<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Engine\Exception\EngineException;

/**
 * Connecting Campaign Intelligence, in one place for both connect paths —
 * the admin Connect (Controller/Adminhtml/Api/EngineExchange) and the
 * configuration save, also `bin/magento config:set`
 * (Model/Config/Backend/EngineSetupToken): exchange the pasted setup URL or
 * token, store the connection, then start the imports a connect starts
 * (ImportsOnConnect: the catalog, the customers, then the orders). Each caller words
 * the outcome for its own merchant; none of them repeats a step.
 */
class Connector
{
    public function __construct(
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly ImportsOnConnect $importsOnConnect
    ) {
    }

    /**
     * Connect with a setup URL or token; nothing is stored or started when the exchange fails.
     *
     * @return array{exchange: array<string, mixed>, started: array{catalog: bool, customers: bool, orders: bool}}
     *     the engine's setup-exchange answer, and per import whether this connect started it
     * @throws EngineException when the engine does not accept the setup URL or token
     */
    public function connect(string $setupInput): array
    {
        $exchange = $this->client->setupExchange($setupInput);
        $this->settings->storeExchange($exchange);

        return [
            'exchange' => $exchange,
            'started' => $this->importsOnConnect->start(),
        ];
    }
}
