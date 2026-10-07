<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\Config\ScopeInterface;

/**
 * Static configuration scope (App\State dependency).
 */
class FakeConfigScope implements ScopeInterface
{
    /**
     * @var string
     */
    private $currentScope = 'primary';

    /**
     * @inheritDoc
     */
    public function getCurrentScope()
    {
        return $this->currentScope;
    }

    /**
     * @inheritDoc
     */
    public function setCurrentScope($scope)
    {
        $this->currentScope = (string)$scope;
    }
}
