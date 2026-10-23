<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Discount;

use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;

/**
 * The discount code an automation's email carries (CON-198), made when the
 * queue sends the row — after the workflow is found and the reminder's
 * consent check passed, so a row that is skipped makes no code — from the
 * cart price rule of the automation's settings for the event's website.
 *
 * Once per queue row: the code and its last valid day go into the row's
 * stored address before it is posted (EventQueue::addToPayload()), so a
 * retry, a stale row picked up again and the Log's Send again post the
 * same code and make no second one. A rule that no longer gives codes for
 * the website today leaves the code out: the email still goes, and the
 * row's payload keeps the reason under NOTICE_KEY (shown in the Log's
 * Details), written to var/log/smaily_connect.log as well. A row stored
 * that way makes no code on a retry either.
 */
class AutomationDiscount
{
    /** Payload key of the reason an email went without its discount code. */
    public const NOTICE_KEY = 'discount_notice';

    /** %1 is the cart price rule's id. */
    public const NOTICE_RULE_UNAVAILABLE = 'Sent without a discount code: the cart price rule %1 gives no codes'
        . ' for this website today (deleted, inactive, without Use Auto Generation, not for this website, or'
        . ' outside its From and To dates).';

    public function __construct(
        private readonly Config $config,
        private readonly CodeIssuer $issuer,
        private readonly EventQueue $eventQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * The row's address with the automation's discount code fields, or as
     * it is when its "Send a discount code" is off.
     *
     * @param array<int|string, mixed> $payload the row's decoded payload
     * @return array<string, mixed>
     * @throws \Exception when Magento cannot generate or store the code (the row is retried)
     */
    public function address(Event $event, array $payload, string $trigger, int $storeId, int $websiteId): array
    {
        $address = (array)$payload['address'];
        $fields = Trigger::COUPON_FIELDS[$trigger] ?? null;
        if ($fields === null
            || isset($address[$fields[0]])
            || isset($payload[self::NOTICE_KEY])
            || !$this->config->isCouponEnabled($trigger, $websiteId)
        ) {
            return $address;
        }

        $ruleId = $this->config->getCouponRuleId($trigger, $websiteId);
        $coupon = $ruleId > 0
            ? $this->issuer->issue($ruleId, $this->config->getCouponValidDays($trigger, $websiteId), $storeId, $websiteId)
            : null;
        if ($coupon === null) {
            $notice = str_replace('%1', (string)$ruleId, self::NOTICE_RULE_UNAVAILABLE);
            $this->eventQueue->addToPayload($event, [self::NOTICE_KEY => $notice]);
            $this->logger->error($notice, ['trigger' => $trigger, 'website_id' => $websiteId]);

            return $address;
        }

        $address[$fields[0]] = $coupon['code'];
        $address[$fields[1]] = $coupon['expires_at'];
        $this->eventQueue->addToPayload($event, ['address' => $address]);

        return $address;
    }
}
