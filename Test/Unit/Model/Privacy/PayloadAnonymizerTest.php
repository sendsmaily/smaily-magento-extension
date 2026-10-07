<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Privacy;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Privacy\PayloadAnonymizer;

/**
 * The matcher/anonymiser behind the Art. 17 erasure (PRO-2452): what counts
 * as this contact's row, and what a row carries afterwards.
 */
class PayloadAnonymizerTest extends TestCase
{
    private PayloadAnonymizer $anonymizer;

    protected function setUp(): void
    {
        $this->anonymizer = new PayloadAnonymizer();
    }

    public function testMatchesAnAddressNestedAnywhereInThePayload(): void
    {
        $payload = (string)json_encode([
            'store_id' => 1,
            'contact' => ['email' => 'erase-test-1@example.test', 'name' => 'Test Erasure'],
        ]);

        self::assertTrue($this->mentions($payload, 'erase-test-1@example.test'));
        self::assertFalse($this->mentions($payload, 'erase-test-2@example.test'));
    }

    public function testMatchingIsCaseInsensitiveOnBothSides(): void
    {
        $payload = (string)json_encode(['contact' => ['email' => 'Erase-Test-1@Example.Test']]);

        self::assertTrue($this->mentions($payload, 'ERASE-TEST-1@example.test'));
    }

    /**
     * The sibling's open PRO-2448: json_encode escapes non-ASCII by default,
     * so the address reaches the column with its umlauts as backslash-u
     * sequences and a raw substring search finds nothing. Decoding does.
     */
    public function testMatchesAnAddressJsonEscapedByNonAsciiCharacters(): void
    {
        $email = 'mõni@näide.test';
        $payload = (string)json_encode(['contact' => ['email' => $email, 'name' => 'Tõnu Käär']]);

        self::assertStringNotContainsString($email, $payload, 'The address is escaped in the stored bytes');
        self::assertTrue($this->mentions($payload, $email));
    }

    public function testMatchesAnAddressQuotedInsideALongerValue(): void
    {
        $response = (string)json_encode(['message' => 'Rejected recipient erase-test-1@example.test (bounced)']);

        self::assertTrue($this->mentions($response, 'erase-test-1@example.test'));
    }

    public function testFallsBackToAPlainTextSearchForNonJsonBlobs(): void
    {
        $error = 'HTTP 422: erase-test-1@example.test is not a valid contact';

        self::assertTrue($this->mentions($error, 'erase-test-1@example.test'));
        self::assertFalse($this->mentions($error, 'erase-test-2@example.test'));
    }

    public function testEmptyInputNeverMatches(): void
    {
        self::assertFalse($this->mentions(null, 'erase-test-1@example.test'));
        self::assertFalse($this->mentions('', 'erase-test-1@example.test'));
        self::assertFalse($this->mentions('{"email":"erase-test-1@example.test"}', '  '));
    }

    public function testAnonymizingKeepsTheKeysAndTheStructureAndValidJson(): void
    {
        $payload = (string)json_encode([
            'store_id' => 1,
            'contact' => ['email' => 'erase-test-1@example.test', 'name' => 'Test Erasure'],
            'items' => [['sku' => 'TEST-1', 'qty' => 2]],
        ]);

        $anonymized = (string)$this->redacted($payload);
        $decoded = json_decode($anonymized, true);

        self::assertSame([
            'store_id' => '[erased]',
            'contact' => ['email' => '[erased]', 'name' => '[erased]'],
            'items' => [['sku' => '[erased]', 'qty' => '[erased]']],
        ], $decoded);
    }

    public function testAnAnonymizedPayloadNoLongerMatchesTheContact(): void
    {
        $payload = (string)json_encode(['contact' => ['email' => 'mõni@näide.test']]);
        $anonymized = (string)$this->redacted($payload);

        self::assertFalse($this->mentions($anonymized, 'mõni@näide.test'));
    }

    public function testAnUndecodableBlobIsReplacedWholesale(): void
    {
        self::assertSame(
            Erasure::PLACEHOLDER,
            $this->redacted('<html>erase-test-1@example.test</html>')
        );
    }

    public function testNullAndEmptyBlobsAreLeftAlone(): void
    {
        self::assertNull($this->redacted(null));
        self::assertSame('', $this->redacted(''));
    }

    /**
     * The pair as a caller uses it: decode the blob once, then ask.
     */
    private function mentions(?string $stored, string $email): bool
    {
        return $this->anonymizer->matches($stored, $this->anonymizer->decode($stored), $email);
    }

    private function redacted(?string $stored): ?string
    {
        return $this->anonymizer->anonymize($stored, $this->anonymizer->decode($stored));
    }
}
