# Getting published in Magento marketplace

>Every new version of Smaily For Magento extension published needs to pass automatic and manual testing by the Magento review team!

## Testing module before submission to marketplace
It is **MANDATORY** to run tests before applying for the Magento review. Most issues before have been with compatibility with PHP 5.6.

## Limitations in Magento review
1. You can only submit a single version once. **Don't release the new version in GitHub before acceptance in Magento marketplace**.
2. Magento marketplace version and Packagist version must match
3. Our module supports Magneto 2.0 version, so we need to provide support for PHP 5.6

### PHP CodeSniffer, VS Code and Magento 2 coding standard integration
1. One option for automatic testing while writing code is to use [SquizLabs condesniffer](https://github.com/squizlabs/PHP_CodeSniffer). You can view standards that are provided by default in `/src/Standards/` folder in the CodeSniffer directory.
2. Magento 2 has released its own [standard](https://github.com/magento/magento-coding-standard) to use with this code sniffer. You can add this standard directly to Magento 2 installation with composer or use a global install and copy Magento2 folder from their GitHub repo to your CodeSniffer standards directory. I prefer to use the last option as it makes integration with VS Code simpler.
3. You can use VS Code [phpcs extension](https://marketplace.visualstudio.com/items?itemName=ikappas.phpcs) to see errors while writing code in your IDE. You can check if you have global CodeSniffer installation and Magento 2 in the list by running `phpcs -i` in your terminal.
4. The idea is to have a global CodeSniffer installation and provide Magento 2 standard to use in VS Code `settings.json` file. You need to install a phpcs extension for VS Code and add a Magento standard to `settings.json`.
5. Open settings (`ctrl-shift-p -> open settings JSON`) Add a line `{"phpcs.standard":"Magento2"}`

### Magento 2 module package testing
1. Magento has released an automatic package [validation tool](https://github.com/magento/marketplace-tools) that you can use to test the package before sending it to review.
2. It is also recommended to use phpcs and Magento 2 standards to test the module folder before submission.
3. `phpcs route\to\folder\SmailyForMagento --standard=Magento2 --severity=10` This needs to return no value as all LVL 10 errors will fail automatic review.

## Compilation and production mode testing
1. Magento 2 needs to enable production mode with no errors. Run `php bin/magento deploy:mode:set production` in root folder of Magento install.
2. Magento 2 needs to compile with no errors. Run `php bin/magento setup:di:compile`. **It should also compile with PHP 5.6 and Magento 2.0.x version**.

## Checklist for Magento marketplace
These are steps to create a submission in the marketplace.
1. After successful pull-request, you need to create a compressed package with module contents
2. Package components so that they are at the root level of the package. **Don't package whole Smaily/SmailyForMagento folder structure**
2. Remove all unrelated files/directories. For example `.gitignore` file and `.git` folder
3. Make sure that `composer.json` version and created version in Magento store match
4. After SUCCESSFUL review you can create a new release in GitHub.
5. GitHub and Packagist have been already connected so new release from GitHub will release a new version in Packagist
