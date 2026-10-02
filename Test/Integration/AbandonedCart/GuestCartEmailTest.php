<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Validator\EmailAddress;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Smaily\Connect\Model\AbandonedCart\GuestCartEmail;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\FakeCache;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3693: the email a guest types at checkout reaches the cart before the
 * shopper submits it — against real quote and quote_id_mask tables. Only an
 * active guest cart with items takes it; every refusal changes nothing.
 */
class GuestCartEmailTest extends IntegrationTestCase
{
    private const MASK = 'maskedCartId0000000000000000001';

    private SchemaInstaller $schema;

    private FakeCache $cache;

    /**
     * @var RemoteAddress&MockObject
     */
    private RemoteAddress $remoteAddress;

    private string $ip = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createQuote();
        $this->schema->createQuoteIdMask();
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_ABANDONED_ENABLED, '1');

        // The limits live in the application cache; the harness's own cache
        // forgets everything, so these tests keep theirs in memory.
        $this->cache = new class extends FakeCache {
            /**
             * @var array<string, string>
             */
            private array $data = [];

            /**
             * @param string $identifier
             * @return string|false
             */
            public function load($identifier)
            {
                return $this->data[$identifier] ?? false;
            }

            /**
             * @param string $data
             * @param string $identifier
             * @param array<int, string> $tags
             * @param int|null $lifeTime
             * @return bool
             */
            public function save($data, $identifier, $tags = [], $lifeTime = null)
            {
                $this->data[$identifier] = (string)$data;

                return true;
            }
        };
        $this->remoteAddress = $this->createMock(RemoteAddress::class);
        $this->remoteAddress->method('getRemoteAddress')->willReturnCallback(fn () => $this->ip);
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `quote_id_mask`');
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

    public function testAGuestCartWithItemsTakesTheTypedEmail(): void
    {
        $this->seedCart(1, self::MASK);

        self::assertTrue($this->service()->set(self::MASK, '  guest@example.com '));
        self::assertSame('guest@example.com', $this->emailOf(1));
    }

    public function testTheEmailCanChangeAndTheSameEmailAgainWritesNothing(): void
    {
        $this->seedCart(1, self::MASK);
        $service = $this->service();

        self::assertTrue($service->set(self::MASK, 'first@example.com'));
        self::assertTrue($service->set(self::MASK, 'second@example.com'));
        self::assertSame('second@example.com', $this->emailOf(1));

        // The same address in another case is the address the cart has.
        self::assertTrue($service->set(self::MASK, 'SECOND@example.com'));
        self::assertSame('second@example.com', $this->emailOf(1));
    }

    public function testACustomerCartIsRefused(): void
    {
        $this->seedCart(1, self::MASK, ['customer_id' => 42, 'customer_email' => 'customer@example.com']);

        self::assertFalse($this->service()->set(self::MASK, 'victim@example.com'));
        self::assertSame('customer@example.com', $this->emailOf(1));
    }

    public function testAnInactiveCartIsRefused(): void
    {
        $this->seedCart(1, self::MASK, ['is_active' => 0]);

        self::assertFalse($this->service()->set(self::MASK, 'guest@example.com'));
        self::assertNull($this->emailOf(1));
    }

    public function testAnEmptyCartIsRefused(): void
    {
        $this->seedCart(1, self::MASK, ['items_count' => 0]);

        self::assertFalse($this->service()->set(self::MASK, 'guest@example.com'));
        self::assertNull($this->emailOf(1));
    }

    public function testAnUnknownMaskedIdIsRefused(): void
    {
        $this->seedCart(1, self::MASK);

        self::assertFalse($this->service()->set('someOtherMaskedId', 'guest@example.com'));
        self::assertFalse($this->service()->set('', 'guest@example.com'));
        // The numeric cart id is not a masked id.
        self::assertFalse($this->service()->set('1', 'guest@example.com'));
        self::assertNull($this->emailOf(1));
    }

    /**
     * @dataProvider invalidEmails
     */
    public function testAnInvalidEmailIsRefused(string $email): void
    {
        $this->seedCart(1, self::MASK);

        self::assertFalse($this->service()->set(self::MASK, $email));
        self::assertNull($this->emailOf(1));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmails(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'no domain' => ['guest@'],
            'no at sign' => ['guest.example.com'],
            'still typing' => ['guest@exa'],
            'longer than the column' => [str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.'
                . str_repeat('c', 60) . '.' . str_repeat('d', 60) . '.' . str_repeat('e', 20) . '.example.com'],
        ];
    }

    public function testNothingIsKeptWhileAbandonedCartRemindersAreOff(): void
    {
        $this->seedCart(1, self::MASK);
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_ABANDONED_ENABLED, '0');

        self::assertFalse($this->service()->set(self::MASK, 'guest@example.com'));
        self::assertNull($this->emailOf(1));
    }

    public function testOneAddressIsLimitedToThirtyRequestsInTenMinutes(): void
    {
        $this->seedCart(1, self::MASK);
        $service = $this->service();

        // Refused requests count too: the limit is on the caller.
        for ($i = 0; $i < GuestCartEmail::RATE_LIMIT_PER_WINDOW; $i++) {
            $service->set('unknownMask', 'guest@example.com');
        }
        self::assertFalse($service->set(self::MASK, 'guest@example.com'));
        self::assertNull($this->emailOf(1));

        // Another address is not limited by the first one.
        $this->ip = '198.51.100.9';
        self::assertTrue($service->set(self::MASK, 'guest@example.com'));

        // The first address is served again in the next window.
        $this->ip = '203.0.113.7';
        $this->clock->travel(GuestCartEmail::RATE_WINDOW_SECONDS);
        self::assertTrue($service->set(self::MASK, 'again@example.com'));
        self::assertSame('again@example.com', $this->emailOf(1));
    }

    public function testAnIpv6CallerIsLimitedByItsSlash64(): void
    {
        $this->seedCart(1, self::MASK);
        $service = $this->service();

        // A fresh address in the same /64 for every request is one caller.
        for ($i = 1; $i <= GuestCartEmail::RATE_LIMIT_PER_WINDOW; $i++) {
            $this->ip = sprintf('2001:db8:aa:bb:%x::1', $i);
            $service->set('unknownMask', 'guest@example.com');
        }
        $this->ip = '2001:db8:aa:bb:ffff:ffff:ffff:ffff';
        self::assertFalse($service->set(self::MASK, 'guest@example.com'));
        self::assertNull($this->emailOf(1));

        // The next /64 is another caller.
        $this->ip = '2001:db8:aa:bc::1';
        self::assertTrue($service->set(self::MASK, 'guest@example.com'));
    }

    public function testAnIpv4MappedAddressCountsAsItsIpv4Address(): void
    {
        $this->seedCart(1, self::MASK);
        $service = $this->service();

        for ($i = 0; $i < GuestCartEmail::RATE_LIMIT_PER_WINDOW; $i++) {
            $service->set('unknownMask', 'guest@example.com');
        }
        $this->ip = '::ffff:203.0.113.7';
        self::assertFalse($service->set(self::MASK, 'guest@example.com'), 'The same caller as 203.0.113.7');

        // Another IPv4 caller in mapped form is not limited by the first.
        $this->ip = '::ffff:198.51.100.9';
        self::assertTrue($service->set(self::MASK, 'guest@example.com'));
    }

    public function testTheStoreTakesAtMostTwoThousandAddressesAnHour(): void
    {
        $carts = intdiv(GuestCartEmail::MAX_WRITES_PER_HOUR, GuestCartEmail::MAX_WRITES_PER_CART) + 1;
        $rows = [];
        $masks = [];
        for ($quoteId = 1; $quoteId <= $carts; $quoteId++) {
            $rows[] = ['entity_id' => $quoteId, 'store_id' => 1, 'is_active' => 1, 'items_count' => 1];
            $masks[] = ['quote_id' => $quoteId, 'masked_id' => 'mask' . $quoteId];
        }
        $this->connection->insertMultiple('quote', $rows);
        $this->connection->insertMultiple('quote_id_mask', $masks);
        $service = $this->service();

        // Every request from its own caller, each cart up to its own cap:
        // only the store-wide ceiling is left to stop them.
        $accepted = 0;
        for ($write = 0; $write < GuestCartEmail::MAX_WRITES_PER_HOUR; $write++) {
            $this->ip = sprintf('10.%d.%d.1', intdiv($write, 256), $write % 256);
            $quoteId = intdiv($write, GuestCartEmail::MAX_WRITES_PER_CART) + 1;
            $accepted += (int)$service->set('mask' . $quoteId, sprintf('guest%d@example.com', $write));
        }
        self::assertSame(GuestCartEmail::MAX_WRITES_PER_HOUR, $accepted);

        $this->ip = '192.0.2.1';
        self::assertFalse($service->set('mask' . $carts, 'one-more@example.com'));
        self::assertNull($this->emailOf($carts));
        // The address a cart already has is still answered as set: it writes nothing.
        self::assertTrue($service->set('mask1', 'guest4@example.com'));

        // The next hour starts a fresh count.
        $this->clock->travel(GuestCartEmail::STORE_WINDOW_SECONDS);
        self::assertTrue($service->set('mask' . $carts, 'one-more@example.com'));
        self::assertSame('one-more@example.com', $this->emailOf($carts));
    }

    public function testOneCartTakesAtMostFiveAddresses(): void
    {
        $this->seedCart(1, self::MASK);
        $service = $this->service();

        for ($i = 1; $i <= GuestCartEmail::MAX_WRITES_PER_CART; $i++) {
            // A fresh caller address each time, so only the cart cap applies.
            $this->ip = '198.51.100.' . $i;
            self::assertTrue($service->set(self::MASK, sprintf('guest%d@example.com', $i)));
        }

        $this->ip = '198.51.100.99';
        self::assertFalse($service->set(self::MASK, 'victim@example.com'));
        self::assertSame('guest5@example.com', $this->emailOf(1));
        // The address the cart has is still answered as set.
        self::assertTrue($service->set(self::MASK, 'guest5@example.com'));
    }

    public function testAnotherCartIsNotTouched(): void
    {
        $this->seedCart(1, self::MASK);
        $this->seedCart(2, 'maskedCartId0000000000000000002', ['customer_email' => 'other@example.com']);

        self::assertTrue($this->service()->set(self::MASK, 'guest@example.com'));
        self::assertSame('other@example.com', $this->emailOf(2));
    }

    private function service(): GuestCartEmail
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new GuestCartEmail(
            $this->objectManager->get(ResourceConnection::class),
            new EmailAddress(),
            $this->objectManager->get(Config::class),
            $storeManager,
            $this->cache,
            $this->clock,
            $this->remoteAddress
        );
    }

    /**
     * A guest cart with one item behind a masked id; $columns overrides.
     *
     * @param array<string, mixed> $columns
     */
    private function seedCart(int $quoteId, string $maskedId, array $columns = []): void
    {
        $this->schema->seedQuote($quoteId, array_merge(
            ['customer_id' => null, 'is_active' => 1, 'items_count' => 1, 'customer_email' => null],
            $columns
        ));
        $this->connection->insert('quote_id_mask', ['quote_id' => $quoteId, 'masked_id' => $maskedId]);
    }

    private function emailOf(int $quoteId): ?string
    {
        $email = $this->fetchRow('quote', $quoteId, 'entity_id')['customer_email'];

        return $email === null ? null : (string)$email;
    }
}
