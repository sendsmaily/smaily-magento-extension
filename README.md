Smaily email marketing and automation extension for Magento.

Automatically synchronize newsletter subscribers to a Smaily subscribers list, generate RSS-feed based on products for easy template import and send new newsletter subscribers directly to Smaily.

## Features

### Magento Newsletter Subscribers

- Add subscribers to Smaily subscribers list;
- Trigger all opt-in or a specific automation workflow;
- Magento built in subscribe newsletter form sends subscribers directly to Smaily;
- Magento built-in CAPTCHA and Google reCAPTCHA support.

### Magento Products RSS-feed

- Generate default RSS-feed with 50 latest products for easy import to Smaily template;
- Option to customize generated RSS-feed based on product categories;
- Option to limit generated RSS-feed products amount with preferred value.

### Two-way synchronization between Smaily and Magento

- Get unsubscribers from Smaily unsubscribed list;
- Collect new user data for subscribed users;
- Generate data log for each update.

### Abandoned cart notification

- Automatically notify customers about their abandoned cart;
- Send abandoned cart information to Smaily for easy use on templates.

## Requirements

This extension is built for Magento 2.3 and newer.

Check specific PHP, web server, database, etc requirements for your preferred Magento version from [Magento technology stack requirements](https://devdocs.magento.com/guides/v2.0/install-gde/system-requirements-tech.html).

## Documentation & Support

Online documentation and code samples are available via our [Help Center](https://smaily.com/help/user-manual/integrations-et/smaily-for-magento-2/).

## Contribute

All development for Smaily for Magento is [handled via GitHub](https://github.com/sendsmaily/smaily-magento-extension). Opening new issues and submitting pull requests are welcome.

## Installation

Make sure you have Magento 2.3 (or newer) installed.

### Installing via Composer (recommended)

In Magento's root directory run:

    $ composer require smaily/smailyformagento:version

### Manual installation

1. Download ZIP-file from [Magento Marketplace](https://marketplace.magento.com) or repository's [releases](https://github.com/sendsmaily/smaily-magento-extension/releases) section;
2. Extract downloaded ZIP-file to your Magento's `app/code/Smaily/SmailyForMagento` directory.

### After installation

Ensure Smaily for Magento is enabled:

    $ php bin/magento module:status Smaily_SmailyForMagento

> You should see "Module is enabled".

If extension is disabled, you can enable it by running:

    $ php bin/magento module:enable Smaily_SmailyForMagento

Ensure Magento extension updates are applied:

    $ php bin/magento setup:upgrade

## Usage

1. Go to `Stores` → `Configuration` → `Smaily email marketing and automation` → and click `Module Configuration`;
2. Open `General Settings` section;
3. Insert your Smaily API credentials and press `Save Config` to get started;
4. Under `Newsletter subscription form` section select if you like to send newsletters subscribers to Smaily on sign-up;
5. Under `Subscribers synchronization` section you can enable automatic newsletter subscribers syncronization, configure synchronized fields, synchronization frequency and last synchronization datetime;
6. Under `Abandoned Cart` section you can enable automatic reminder emails for abandoned carts, configure abandoned cart automation, fields and delay time;
7. That's it, your Magento store is now integrated with Smaily!

## Frequently Asked Questions

### Where I can find data-log for CRON?

CRON update data-log is stored in the `var/log/` folder of Magento store. Newsletter subscribers synchronization log is saved in `smly_customer_cron.log` file and Abandoned Cart log is stored in `smly_cart_cron.log`.

### How can I filter RSS-feed output by category and limit results?

You can access RSS feed by visiting ulr `store_url/smaily/rss/feed` and you can add parameters (category and limit) by appending them to URL separated with slashes. For example `store_url/smaily/rss/feed/category/bikes/limit/10`. Regular RSS-feed shows 50 last products.

### How can I access additional Abandoned cart parameters in Smaily template editor?

Here is a list of all the parameters available in Smaily email templating engine:

- Customer first name: `{{ first_name }}`;
- Customer last name: `{{ last_name }}`.

Up to 10 products can be received in Smaily templating engine. You can refrence each product with number 1-10 behind parameter name:

- Product name: `{{ product_name_[1-10] }}`;

- Product description: `{{ product_description_[1-10] }}`;

- Product image URL: `{{ product_image_url_[1-10] }}`;

- Product SKU: `{{ product_sku_[1-10] }}`;

- Product quantity: `{{ product_quantity_[1-10] }}`;

- Product price: `{{ product_price_[1-10] }}`;

- Product base price: `{{ product_base_price_[1-10] }}`.

Also you can determine if customer had more than 10 items in cart:

- More than 10 items: `{{ over_10_products }}`.

## Troubleshooting

### Regular export fails to run

Usually a good place to start would be to check Magento CRON's Schedule Ahead for value. We have found that value of 60 works the best, if you are running daily exports.
