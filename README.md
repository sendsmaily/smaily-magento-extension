# Smaily for Magento 2

> **DISCLAIMER**
> Plugin is still in development and currently being reviewed by Magento. Use at your own discretion before fully finished. All bug reports are welcome under issues tab.

## Description

Smaily email marketing and automation extension module for Magento.

Automatically synchronize newsletter subscribers to a Smaily subscribers list, generate rss-feed based on products for easy template import and send new newsletter subscribers directly to Smaily.

NOTE! One of the most noticeable shortcoming of the extension is that it allows only one-way synchronization, i.e. subscribers can only be exported from Magento to Smaily. It will be addressed in the next major version, after Smaily has added the capability for data export through the API.

## Features

### Magento Newsletter Subscribers

- Add subscribers to Smaily subscribers list
- Magento built in subscribe newsletter form sends subscribers directly to smaily

### Magento Products RSS-feed

- Generate default RSS-feed with 50 latest products for easy import to Smaily template
- Option to customize generated RSS-feed based on products categories
- Option to limit generated RSS-feed products amount with prefered value

### Two-way synchronization between Smaily and Magento

- Get unsubscribers from Smaily unsubscribed list
- Collect new user data for subscribed users
- Generate data log for each update

### Abandoned cart notification

- Automatically notify customers about their abandoned cart
- Send abandoned cart information to smaily for easy use on templates

## Requirements

You'll need to be running Magento 2.0+ for this extension to work. Check specific PHP, Web server, Database etc. requirements for your prefered Magento version from [Magento technology stack requirements](https://devdocs.magento.com/guides/v2.0/install-gde/system-requirements-tech.html)

## Documentation & Support

Online documentation and code samples are available via our [Help Center](http://help.smaily.com/en/support/home).

## Contribute

All development for Smaily for Magento is [handled via GitHub](https://github.com/sendsmaily/smaily-magento-extension). Opening new issues and submitting pull requests are welcome.

## Installation

1. Make sure you have Magento 2.0 and above installed.
2. Install Smaily extension with composer. Run `composer require smaily/smailyformagento:version` in magento root directory.
3. For manual installation upload or extract the `SmailyForMagento` folder to your site's `/app/code/Smaily` directory. Folder structure needs to be `magento_root/app/code/Smaily/SmailyForMagento` for this module to work.
4. Run "php bin/magento setup:upgrade".
5. Extension configuration can be found from Magento administration interface, under "Stores → Configuration → Smaily Email Marketing and Automation".

## Usage

1. Go to Stores -> Configuration -> Smaily email marketing and automation -> and click Module Configuration
2. Open General Settings Tab
3. Insert your Smaily API authentication information to get started and press Save Config
4. Under Newsletter subscription form tab select if you like to send subscribers directly to Smaily
5. Under Subscribers synchronization tab you can enable syncronization, select additional fields to sync and frequency
6. Under Abandoned cart tab you can enable automatic reminder emails for abandoned carts
7. Select autoresponder and delay time. You can also add aditional template parameters
8. When finished selecting all your preferences press Save Config
9. That's it, your Magento store is now integrated with Smaily Plugin!

## Frequently Asked Questions

### Where I can find data-log for Cron?

Cron update data-log is stored in the `root/var/log/` folder of Magento store. Contacts synchronization log is saved in "smly_customer_cron.log" file and Abandoned Cart log is stored in "smly_cart_cron.log".

### How can I filter RSS-feed output by category and limit results?

You can access RSS feed by visiting ulr `store_url/smaily/rss/feed` and you can add parameters (category and limit) by appending them to url separated with slashes. For example `store_url/smaily/rss/feed/category/bikes/limit/10`. Regular RSS-feed shows 50 last products.

### How can I access additional Abandoned cart parameters in Smaily template editor?

Here is a list of all the parameters available in Smaily email templating engine:

- Customer name: `{{ name }}`.

Up to 10 products can be received in Smaily templating engine. You can refrence each product with number 1-10 behind parameter name.

- Product name: `{{ product_name_[1-10] }}`.

- Product description: `{{ product_description_[1-10] }}`.

- Product SKU: `{{ product_sku_[1-10] }}`.

- Product quantity: `{{ product_qty_[1-10] }}`.

- Product price: `{{ product_price_[1-10] }}`.

- Product base price: `{{ product_base_price_[1-10] }}`.

Also you can determine if customer had more than 10 items in cart

- More than 10 items: `{{ over_10_products }}`.

## Troubleshooting

### Regular export fails to run

Usually a good place to start would be to check Magento CRON's Schedule Ahead for value. We have found that value of 60 works the best, if you are running daily exports.

## Changelog

### 0.9.0 - 2018

- This is the first public release.
