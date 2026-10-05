<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Backfills a website's existing contacts into Smaily: the audience of the
 * website's contact-sync mode (Model\Backfill\ContactAudience, PRO-3582).
 * A newsletter subscriber goes with its real subscription status; under
 * "All customers" (the soft opt-in, PRO-3610) every other customer goes
 * without is_unsubscribed, as the live sync sends them: Smaily keeps an
 * existing contact's status and creates a new contact as subscribed.
 *
 * The cursor is the last processed subscriber_id while the walk is in the
 * subscribers; under "All customers" it then moves on to the other
 * registered customers as "customer:{entity_id}". Each tick processes pages
 * until the time budget is spent, so a large base imports across several
 * cron runs without ever blocking the cron group.
 *
 * The website's stored "Enable subscriber synchronization" answer gates
 * this import exactly as it gates the live paths and the reconcile tick —
 * one stored answer owns every outbound contact path (PRO-1764). The mode is
 * read on every tick, so a mode changed mid-import decides what the rest of
 * the walk sends.
 */
class ContactsProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 500;
    private const TIME_BUDGET_SECONDS = 20;
    private const CUSTOMER_CURSOR = 'customer:';

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly ContactAudience $audience,
        private readonly Mode $mode,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SubscriberPayloadBuilder $payloadBuilder,
        private readonly SmailyClientProvider $clientProvider,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        // Same stored answer, same website resolution as the live paths
        // (Observer\SubscriberSaveAfter, Cron\ContactReconcile). A job that
        // never started completes having sent nothing, its total 0 rather
        // than a contact count promising a sync that will not happen; a
        // job switched off mid-import stops at this page boundary like an
        // admin cancel, so the total it discovered and the contacts it has
        // already sent are reported as they really are. "Checkout opt-in
        // only" imports nobody, so it ends the same way.
        $websiteId = $job->getWebsiteId();
        $mode = $this->mode->mode($websiteId);
        if (!$this->config->isSyncEnabled($websiteId) || $mode === SyncMode::MODE_CHECKOUT_OPTIN) {
            if ($job->getData('total_count') === null) {
                $job->setData('total_count', 0);
                $this->jobManager->complete($job);
            } else {
                $this->jobManager->cancel($job);
            }
            $this->logger->info('Contacts backfill stopped — the contact-sync settings import nobody', [
                'website_id' => $websiteId,
                'mode' => $mode,
            ]);

            return;
        }

        if (!$this->audience->storeIds($websiteId)) {
            $this->jobManager->fail($job, sprintf('Website %d has no stores', $websiteId));

            return;
        }

        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $this->audience->count($websiteId, $mode));
        }
        $this->jobManager->markRunning($job);

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            [$page, $newCursor] = $this->loadPage($websiteId, $mode, (string)$job->getCursorValue());
            if (!$page) {
                $this->jobManager->complete($job);

                return;
            }

            [$processed, $failed] = $this->sendPage($page);
            $this->jobManager->recordProgress($job, $processed, $failed, $newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }
        } while (microtime(true) < $deadline);
    }

    /**
     * The next page after $cursor and the cursor that follows it: the
     * subscribers first, then — under "All customers" only — the other
     * registered customers.
     *
     * @return array{list<array{id: int, email: string, store_id: int, customer_id: int, subscribed: ?bool}>, string}
     */
    private function loadPage(int $websiteId, string $mode, string $cursor): array
    {
        if (!str_starts_with($cursor, self::CUSTOMER_CURSOR)) {
            $page = $this->audience->subscriberPage($websiteId, (int)$cursor, self::PAGE_SIZE);
            if ($page) {
                return [$page, (string)$page[count($page) - 1]['id']];
            }
            $cursor = self::CUSTOMER_CURSOR . '0';
        }
        if ($mode !== SyncMode::MODE_LEGITIMATE_INTEREST) {
            return [[], $cursor];
        }

        $afterId = (int)substr($cursor, strlen(self::CUSTOMER_CURSOR));
        $page = $this->audience->customerPage($websiteId, $afterId, self::PAGE_SIZE);

        return [$page, $page ? self::CUSTOMER_CURSOR . $page[count($page) - 1]['id'] : $cursor];
    }

    /**
     * @param list<array{id: int, email: string, store_id: int, customer_id: int, subscribed: ?bool}> $page
     * @return array{int, int} processed, failed
     */
    private function sendPage(array $page): array
    {
        $customers = $this->loadCustomers($page);

        $byStore = [];
        foreach ($page as $contact) {
            if ($contact['email'] === '') {
                continue;
            }

            $byStore[$contact['store_id']][] = $this->payloadBuilder->build(
                $contact['email'],
                $contact['store_id'],
                $contact['subscribed'] === null ? null : !$contact['subscribed'],
                $customers[$contact['customer_id']] ?? null
            );
        }

        $processed = 0;
        $failed = 0;
        foreach ($byStore as $storeId => $contacts) {
            $sent = $this->post($storeId, $contacts);
            $processed += $sent;
            $failed += count($contacts) - $sent;
        }

        return [$processed, $failed];
    }

    /**
     * Post one store's contacts in one request; how many of them Smaily
     * took. A group Smaily refuses as invalid data (203) goes again one
     * contact per request, so only the refused contact counts as failed
     * (PRO-3753, as the queued contact sync).
     *
     * @param list<array<string, mixed>> $contacts
     */
    private function post(int $storeId, array $contacts, ?SmailyClient $client = null): int
    {
        try {
            $client ??= $this->clientProvider->forStore($storeId);
            $client->post(SmailyClient::ENDPOINT_CONTACT, $contacts);

            return count($contacts);
        } catch (SmailyClientException $exception) {
            if (count($contacts) > 1 && $exception instanceof ApiException && $exception->isInvalidData()) {
                $sent = 0;
                foreach ($contacts as $contact) {
                    $sent += $this->post($storeId, [$contact], $client);
                }

                return $sent;
            }
            $this->logger->error('Contacts backfill batch failed', [
                'store_id' => $storeId,
                'count' => count($contacts),
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param list<array{id: int, email: string, store_id: int, customer_id: int, subscribed: ?bool}> $page
     * @return array<int, CustomerInterface>
     */
    private function loadCustomers(array $page): array
    {
        $customerIds = [];
        foreach ($page as $contact) {
            if ($contact['customer_id'] > 0) {
                $customerIds[] = $contact['customer_id'];
            }
        }
        if (!$customerIds) {
            return [];
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', array_unique($customerIds), 'in')
            ->create();

        $customers = [];
        foreach ($this->customerRepository->getList($criteria)->getItems() as $customer) {
            $customers[(int)$customer->getId()] = $customer;
        }

        return $customers;
    }
}
