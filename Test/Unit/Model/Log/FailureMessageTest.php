<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

class FailureMessageTest extends TestCase
{
    private FailureMessage $failureMessage;

    protected function setUp(): void
    {
        $this->failureMessage = new FailureMessage(new PayloadRedactor());
    }

    public function testShowsTheServerMessageWithoutTheInternalPrefix(): void
    {
        self::assertSame(
            'Invalid credentials for subdomain demo.',
            $this->failureMessage->forDisplay(
                'permanent_http_401: Invalid credentials for subdomain demo.'
            )
        );
    }

    public function testKeepsTheInternalClassForTheDrawer(): void
    {
        self::assertSame(
            'permanent_http_401',
            $this->failureMessage->failureClass('permanent_http_401: Invalid credentials.')
        );
    }

    public function testRetryableFailuresKeepTheirWording(): void
    {
        self::assertSame(
            'Connection timed out after 30s',
            $this->failureMessage->forDisplay('Connection timed out after 30s')
        );
        self::assertSame('', $this->failureMessage->failureClass('Connection timed out after 30s'));
    }

    public function testShowsContactsQuotedByTheServerInFull(): void
    {
        self::assertSame(
            'Address jane.doe@example.com is not valid',
            $this->failureMessage->forDisplay(
                'permanent_http_400: Address jane.doe@example.com is not valid'
            )
        );
    }

    /**
     * PRO-3628: the row holds the English source text; the admin reads it
     * in the admin's own language.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function storedErrors(): array
    {
        return [
            'refusal' => [
                'permanent_http_404: Smaily API request failed with HTTP 404',
                'Smaily API päring ebaõnnestus (HTTP 404)',
            ],
            // PRO-3749: Smaily's answer after the status, kept as it is.
            'refusal with an answer' => [
                'permanent_http_400: Smaily API request failed with HTTP 400: Field birthday: not a date',
                'Smaily API päring ebaõnnestus (HTTP 400): Field birthday: not a date',
            ],
            'error envelope' => [
                'Smaily API returned code 203: Invalid data',
                'Smaily API tagastas koodi 203: Invalid data',
            ],
            'server message with a colon' => [
                'Smaily API returned code 203: Field birthday: not a date',
                'Smaily API tagastas koodi 203: Field birthday: not a date',
            ],
            'network failure' => [
                'Smaily API request failed: cURL error 28: Connection timed out',
                'Smaily API päring ebaõnnestus: cURL error 28: Connection timed out',
            ],
            'credentials refused' => [
                'permanent_http_401: Smaily API credentials were rejected',
                'Smaily lükkas API kasutajaandmed tagasi',
            ],
            'not configured' => [
                'Smaily API credentials are not configured (store scope: 1)',
                'Smaily API kasutajaandmed on seadistamata (poe skoop: 1)',
            ],
            // PRO-3565: the reason a skipped row was closed without sending.
            'skipped, not a contact' => [
                'Skipped: Smaily does not have this contact, and the purchase marker'
                    . ' would create it as a subscriber. Nothing was sent.',
                'Vahele jäetud: Smailys ei ole seda kontakti ja ostu märge looks selle'
                    . ' tellijana. Midagi ei saadetud.',
            ],
            // PRO-3634: the other rows the queue closes without sending.
            'skipped, no workflow mapped' => [
                'Skipped: no Smaily workflow is mapped to this automation trigger. Nothing was sent.',
                'Vahele jäetud: selle automaatika päästikuga pole seotud ühtegi Smaily töövoogu.'
                    . ' Midagi ei saadetud.',
            ],
            'skipped, newer personalization preference' => [
                'Skipped: the shopper has since changed their personalization preference, and the newer'
                    . ' preference is sent in its own row. Nothing was sent.',
                'Vahele jäetud: ostja on vahepeal oma personaliseerimise eelistust muutnud ja uuem eelistus'
                    . ' saadetakse eraldi real. Midagi ei saadetud.',
            ],
            'skipped, opted out of personalization' => [
                'Skipped: the shopper opted out of personalized recommendations, so their browsing is not'
                    . ' linked to their address. Nothing was sent.',
                'Vahele jäetud: ostja loobus personaalsetest soovitustest, seega tema sirvimist ei seota'
                    . ' tema e-posti aadressiga. Midagi ei saadetud.',
            ],
            // PRO-3693: one abandoned-cart reminder per address in 24 hours.
            'skipped, recently reminded' => [
                'Skipped: this address already got an abandoned-cart reminder for another cart'
                    . ' in the last 24 hours. Nothing was sent.',
                'Vahele jäetud: sellele aadressile saadeti viimase 24 tunni jooksul juba teise ostukorvi'
                    . ' meeldetuletus. Midagi ei saadetud.',
            ],
        ];
    }

    /**
     * @dataProvider storedErrors
     */
    public function testAStoredErrorReadsInTheAdminsLanguage(string $stored, string $estonian): void
    {
        StoreLocale::use('et_EE');

        self::assertSame($estonian, $this->failureMessage->forDisplay($stored));
    }

    public function testAnEnglishAdminReadsTheStoredEnglishText(): void
    {
        StoreLocale::use('en_US');

        self::assertSame(
            'Smaily API request failed with HTTP 404',
            $this->failureMessage->forDisplay('permanent_http_404: Smaily API request failed with HTTP 404')
        );
    }

    public function testATranslatedMessageKeepsTheServersPartAsItIs(): void
    {
        StoreLocale::use('et_EE');

        self::assertSame(
            'Smaily API tagastas koodi 203: Address jane.doe@example.com is not valid',
            $this->failureMessage->forDisplay('Smaily API returned code 203: Address jane.doe@example.com is not valid')
        );
    }

    /**
     * A row stored before PRO-3628 holds the text in the store's language;
     * it is shown as it was stored.
     */
    public function testAnErrorStoredInAnotherLanguageIsShownAsStored(): void
    {
        StoreLocale::use('en_US');

        self::assertSame(
            'Smaily API päring ebaõnnestus (HTTP 404)',
            $this->failureMessage->forDisplay('permanent_http_404: Smaily API päring ebaõnnestus (HTTP 404)')
        );
    }

    public function testEveryTranslatedErrorIsInBothDictionaries(): void
    {
        foreach (['en_US', 'et_EE'] as $locale) {
            $csv = (string)file_get_contents(dirname(__DIR__, 4) . '/i18n/' . $locale . '.csv');
            foreach (FailureMessage::TRANSLATED as $source) {
                self::assertStringContainsString(
                    '"' . str_replace('"', '""', $source) . '","',
                    $csv,
                    sprintf('%s is missing from %s.csv', $source, $locale)
                );
            }
        }
    }

    /**
     * PRO-2509: the grid's error filter finds a translated client message by
     * the words the admin reads, its filled-in values matching anything.
     */
    public function testATranslatedMessageIsFoundByItsTranslatedWords(): void
    {
        StoreLocale::use('et_EE');

        self::assertSame(
            ['Smaily API credentials were rejected', 'Smaily API credentials are not configured (store scope: %)'],
            $this->failureMessage->storedPatternsShowing('KASUTAJAANDMED')
        );
        self::assertSame(
            ['Smaily API request failed with HTTP %: %', 'Smaily API request failed with HTTP %'],
            $this->failureMessage->storedPatternsShowing('päring ebaõnnestus (HTTP')
        );
    }

    public function testAWordThatIsNotInATranslationFindsNoStoredMessage(): void
    {
        StoreLocale::use('et_EE');
        self::assertSame([], $this->failureMessage->storedPatternsShowing('Invalid data'));
        self::assertSame([], $this->failureMessage->storedPatternsShowing(''));

        // An English admin reads the stored text itself: nothing to add.
        StoreLocale::use('en_US');
        self::assertSame([], $this->failureMessage->storedPatternsShowing('credentials'));
    }

    protected function tearDown(): void
    {
        StoreLocale::reset();
    }

    public function testEmptyErrorStaysEmpty(): void
    {
        self::assertSame('', $this->failureMessage->forDisplay(null));
        self::assertSame('', $this->failureMessage->forDisplay('   '));
        self::assertSame('', $this->failureMessage->failureClass(null));
    }
}
