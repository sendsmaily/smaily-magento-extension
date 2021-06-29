<?php

namespace Smaily\SmailyForMagento\Model\Logger\Handler;

class Cron extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Logging level.
     *
     * @var int
     */
    protected $loggerType = \Monolog\Logger::DEBUG;
}
