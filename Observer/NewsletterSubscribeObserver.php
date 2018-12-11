<?php

namespace Smaily\SmailyForMagento\Observer;

use Magento\Framework\Event\ObserverInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class NewsletterSubscribeObserver implements ObserverInterface
{
    protected $helper;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        // Get status code of subscribe action 1 = subscribe
        $status = $observer->getEvent()->getSubscriber()->getSubscriberStatus();
        $subscriber = $observer->getEvent()->getSubscriber()->getSubscriberEmail();
        // If user subscribed call Smaily api with user email
        if ($status === 1) {
            $this->helper->subscribe($subscriber);
        }
    }
}
