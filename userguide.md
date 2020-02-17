# USER GUIDE

## Smaily E-Commerce extension for Magento 2.0+
>[Smaily](https://smaily.com/) is an intentionally simple tool for sending beautiful email newsletters. 

## Installation

1. You can get **Smaily E-Commerce extension for Magento 2.0+** from [Magento marketplace](https://marketplace.magento.com/smaily-smailyformagento.html) or download it from our [GitHub repository](https://github.com/sendsmaily/smaily-magento-extension).

2. To install our extension follow the [Magento extension installing guide](https://docs.magento.com/marketplace/user_guide/buyers/install-extension.html)

## Connecting to Smaily account

3. To start using Smaily extension navigate to **Stores -> Configuration** section. On the configuration page, find **Smaily Email Marketing and Automation** tab, then click on **Module Configuration**

![Module configuration path](assets/stores_config_module.png)

4. On extension configuration page enter your Smaily API credentials - **subdomain, username, and password**. You must create your API account in Smaily first. You can follow our [tutorial](http://help.smaily.com/en/support/solutions/articles/16000062943-create-api-user) to create one. After that, you can **validate connection** by saving configuration.

![API credentials section](assets/general_settings.png)

## Newsletter subscription form

4. You can **collect newsletter subscribers directly to your Smaily account** using Magento built-in newsletter subscription form.

5. We recommend to **use CATPCHA** to prevent bots from polluting your newsletter subscribers list. You can use two options - **Magento`s text-based CAPTCHA or Google reCAPTCHA**.

![Newletter subscription form](assets/newsletter_subscription_form.png)

## Subscribers synchronization

6. Enable automatic subscribers synchronization feature under **Subscribers synchronization** section. 

7. There is an option to **import additional fields** available from store into Smaily to personalize newsletter emails.

8. The synchronization **frequency can be adjusted**. Available values are: every 4 hours, twice a day, every day and once a week.

![Subscribers automatic sync section](assets/subscribers_sync.png)

## Abandoned cart emails

9. Enable abandoned cart emails feature under **Abandoned Cart** section to send cart reminder emails to store customers.

10. You need to create *form submitted* workflow in Smaily prior to activating this feature. You can follow our [creating automation workflows tutorial](http://help.smaily.com/en/support/solutions/articles/16000092458-creating-automation-workflows).

11. After creating automation in Smaily you can find this automation under **Autoresponder ID**.

12. You can **choose timing** when cart is considered abandoned form 20 minutes up to 12 hours.

13. There is also an option to **add additional parameters** about abandoned carts to send personalized reminder emails.

![Abandoned cart emails](assets/abandoned_cart.png)
