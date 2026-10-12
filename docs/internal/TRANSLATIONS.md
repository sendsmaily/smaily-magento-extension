# Storefront translations awaiting native review

Four dictionaries hold only the texts a shopper sees, and they are machine
translations that no native speaker has reviewed yet:

| File | Language |
|---|---|
| `i18n/ru_RU.csv` | Russian |
| `i18n/fi_FI.csv` | Finnish |
| `i18n/lv_LV.csv` | Latvian |
| `i18n/lt_LT.csv` | Lithuanian |

The admin stays English and Estonian (`en_US.csv`, `et_EE.csv`); a phrase
missing from these four files shows in English. A Magento CSV has no comment
syntax, so this page is the marker: a file leaves the table once a native
speaker has reviewed it.

## The shopper texts

The Personalization page and its My Account link (both the Luma and the Hyvä
templates), the message after saving it, the checkout newsletter checkbox,
the "Recommended for you" heading, and the three saved-cart link messages
(`Controller/Cart/Restore.php`). `Test/Unit/StorefrontTranslationsTest.php`
finds them in the storefront templates, layouts and controllers and fails
when one of the four files lacks a shopper text, holds any other phrase,
leaves a phrase untranslated or changes its placeholders.

## How to review

1. Open the file; each row is `"English phrase","translation"`.
2. Read each translation where the shopper meets it (a store view with that
   locale): the wording should be the polite, plain language of a web shop.
3. Change only the second column; keep every placeholder (`%1`) and the
   quoting.
4. Run `vendor/bin/phpunit --filter StorefrontTranslationsTest`, then remove
   the file's row from the table above in the same pull request.
