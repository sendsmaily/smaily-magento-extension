<?php

namespace Smaily\SmailyForMagento\Model;

use Smaily\SmailyForMagento\Helper\Data as Helper;
use Smaily\SmailyForMagento\Model\Cron\Orders;

const UNSUBSCRIBERS_BATCHES_LIMIT = 1000;

class Cron
{
    protected $helperData;
    protected $orders;

    public function __construct(
        Helper $helperData,
        Orders $orders
    ) {
        $this->helperData = $helperData;
        $this->orders = $orders;
    }

    public function abandonedCartSync()
    {
        if ($this->helperData->isAbandonedCartEnabled()) {
            // Send abandoned cart data to smaily autoresponder
            $this->helperData->cronAbandonedcart($this->orders->getList());
        }
        return $this;
    }
}
