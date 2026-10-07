<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Model\AbstractModel;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;

/**
 * A chunked historical import job (smaily_backfill_job).
 */
class Job extends AbstractModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_CONTACTS = 'contacts';
    public const TYPE_CATALOG = 'catalog';
    public const TYPE_CUSTOMERS = 'customers';
    public const TYPE_ORDERS = 'orders';

    public const TARGET_SMAILY = 'smaily';
    public const TARGET_ENGINE = 'engine';

    /** Engine ingest is one tenant per installation, so its jobs are website 0. */
    public const ENGINE_WEBSITE_ID = 0;

    /** Job type -> delivery target. */
    public const TYPE_TARGETS = [
        self::TYPE_CONTACTS => self::TARGET_SMAILY,
        self::TYPE_CATALOG => self::TARGET_ENGINE,
        self::TYPE_CUSTOMERS => self::TARGET_ENGINE,
        self::TYPE_ORDERS => self::TARGET_ENGINE,
    ];

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init(JobResource::class);
    }

    public function getJobType(): string
    {
        return (string)$this->getData('job_type');
    }

    public function getTarget(): string
    {
        return (string)$this->getData('target');
    }

    public function getWebsiteId(): int
    {
        return (int)$this->getData('website_id');
    }

    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    public function getCursorValue(): string
    {
        return (string)$this->getData('cursor_value');
    }

    public function getProcessedCount(): int
    {
        return (int)$this->getData('processed_count');
    }

    public function getFailedCount(): int
    {
        return (int)$this->getData('failed_count');
    }
}
