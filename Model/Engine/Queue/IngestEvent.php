<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Magento\Framework\Model\AbstractModel;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;

/**
 * A row in the durable engine ingest queue (smaily_ingest_queue).
 */
class IngestEvent extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(IngestEventResource::class);
    }

    public function getDomain(): string
    {
        return (string)$this->getData('domain');
    }

    public function getEntityId(): ?string
    {
        $value = $this->getData('entity_id');

        return $value === null ? null : (string)$value;
    }

    public function getEventUuid(): string
    {
        return (string)$this->getData('event_uuid');
    }

    public function getPayload(): string
    {
        return (string)$this->getData('payload');
    }

    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    public function getAttempts(): int
    {
        return (int)$this->getData('attempts');
    }
}
