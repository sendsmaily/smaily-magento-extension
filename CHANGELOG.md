# Changelog


### 2.7.0

- Add store, store group and website to abandoned cart payload - [[#96](https://github.com/sendsmaily/smaily-magento-extension/pull/96)]


### 2.6.0

- Compare subscriber status change timestamp on newsletter subscriber sync [[#91](https://github.com/sendsmaily/smaily-magento-extension/pull/91)]
- Fix newsletter subscribers sync unsubscribed status value [[#92](https://github.com/sendsmaily/smaily-magento-extension/pull/92)]


### 2.5.0

- Add product image URL to abandoned cart data payload [[#88](https://github.com/sendsmaily/smaily-magento-extension/pull/88)]


### 2.4.0

- Include more context in CRON job logs [[#82](https://github.com/sendsmaily/smaily-magento-extension/pull/82)]
- Fix CRON job logging duplicate lines [[#83](https://github.com/sendsmaily/smaily-magento-extension/pull/83)]
- Optimize abandoned cart CRON job by excluding sent carts [[#84](https://github.com/sendsmaily/smaily-magento-extension/pull/84)]


### 2.3.1

- Test for Magento 2.4.4 compatibility - [[#78](https://github.com/sendsmaily/smaily-magento-extension/pull/78)]
- Convert module schema and data setup to declarative schema - [[#77](https://github.com/sendsmaily/smaily-magento-extension/pull/77)]


### 2.3.0

- Newsletter Subscribers synchronization tracking per website - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]
- Make last synchronization datetime configurable in module settings - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]


### 2.2.0

- Include store group and website in opt-in form and synchronized data [[#67](https://github.com/sendsmaily/smaily-magento-extension/pull/67)]
- Add automation workflow selection to Newsletter Subscriber settings [[#68](https://github.com/sendsmaily/smaily-magento-extension/pull/68)]


### 2.1.0

- Magento 2.4 compatibility [[#63](https://github.com/sendsmaily/smaily-magento-extension/pull/63)]


### 2.0.0

This is a complete rework of the module. The aim was to make the module configurable by website, i.e. abandoned cart, newsletter subscribers synchronization, opt-in form and Smaily API could be configured for each website. Only reasonable solution was to rebuild the module from ground up, because most (if not all) of the functionality was "Default configuration"-centric.

- Improves efficiency of Newsletter Subscribers and Abandoned Cart CRON jobs [[#36](https://github.com/sendsmaily/smaily-magento-extension/issues/36)]
- Reduces bloatiness of data Helper [[#37](https://github.com/sendsmaily/smaily-magento-extension/issues/37)]
- Fixes double CAPTCHA input fields [[#46](https://github.com/sendsmaily/smaily-magento-extension/issues/46)]


### 1.2.0

- Align synchronization customer first and last name with abandoned cart [[#52](https://github.com/sendsmaily/smaily-magento-extension/pull/52)]


### 1.1.0

- Add new fields ` first_name ` and `last_name` for abandoned cart export
- Changes `product_qty` field to `product_quantity` to unify template variables across integrations


### 1.0.2

- Fix RSS-feed not rendering with special characters


### 1.0.1

- Fix PHP 5.6 compilation issues


### 1.0.0

- Make using CAPTCHA optional for better integration with pop-up forms


### 0.9.3

- Add Magento CAPTCHA and Google reCAPTCHA option for newsletter sign-up form


### 0.9.2

- Fix compilation issues

### 0.9.1

- Subdomain is now parsed from full URL
- Newsletter signup form uses opt-in autoresponder workflow
- Updated cron frequency values
- Updated abandoned cart timing values
- Customer synchronization is now more efficient as it uses data batching
- Customer unsubscribed status is also updated in store's database
- Uninstall cleans up created tables and columns
- Removed custom newsletter and email template blocks
- Removed subscriber observer as synchronization provides same functionality
- Fixed broken links in settings from


### 0.9.0

- This is the first public release
