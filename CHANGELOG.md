# Changelog


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
