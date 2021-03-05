First off, thanks for taking the time to contribute!


# Table of contents

- [Getting started](#getting-started)
- [Internals](#internals)
  - [Structure of the repository](#structure-of-the-repository)
- [Development](#development)
  - [Starting the environment](#starting-the-environment)
  - [Stopping the environment](#stopping-the-environment)
  - [Resetting the environment](#resetting-the-environment)
- [Publishing to Magento marketplace](#publishing-to-magento-marketplace)
  - [Testing module before submission to marketplace](#testing-module-before-submission-to-marketplace)
  - [Limitations in Magento review](#limitations-in-magento-review)
    - [PHP CodeSniffer, VS Code and Magento 2 coding standard integration](#php-codeSniffer,-vs-code-and-magento-2-coding-standard-integration)
    - [Magento 2 module package testing](#magento-2-module-package-testing)
  - [Compilation and production mode testing](#compilation-and-production-mode-testing)
  - [Checklist for Magento marketplace](#checklist-for-magento-marketplace)


# Getting started

The development environment requires [Docker](https://docs.docker.com/) and [Docker Compose](https://docs.docker.com/compose/) to run.
Please refer to the official documentation for step-by-step installation guide.

Clone the repository:

    $ git clone git@github.com:sendsmaily/smaily-magento-extension.git

Next, change your working directory to the local repository:

    $ cd smaily-magento-extension

And run the environment:

    $ docker-compose up

> During container start-up Magento and its sample data will be installed. It can take a while.

To disable sample data installation on first run, change `MAGENTO_SAMPLEDATA` environment variable value to `0` in `docker-compose.yaml`.


# Internals

## Structure of the repository

The repository is split into multiple parts:

- `assets` - screenshots for extension's user guide;
- `src` - extension files;

In addition there are system directories:

- `.github` - GitHub issue and pull request templates, and GitHub Actions workflows;
- `.sandbox` - files needed for running the development environment;


# Development

## Starting the environment

You can run the environment by executing:

    $ docker-compose up

> **Note!** Make sure you do not have any other process(es) listening on ports 8080 and 8888.

## Stopping the environment

Environment can be stopped by executing:

    $ docker-compose down

## Resetting the environment

If you need to reset the installation in the development environment, just simply delete environment's Docker volumes. Easiest way to achieve this is by running:

    $ docker-compose down -v

## Set up PHP CodeSniffer in Visual Studio Code

Magento uses [SquizLabs CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) to ensure every extension is up to par with [Magento Coding Standards](https://github.com/magento/magento-coding-standard). If you are rocking [Visual Studio Code](https://code.visualstudio.com/) as your preferred code editor, then you can look into setting up [PHPCS](https://marketplace.visualstudio.com/items?itemName=ikappas.phpcs) extension to ease Magento extension development.


# Publishing to Magento Marketplace

Every new release of Smaily For Magento extension published needs to pass automatic and manual testing by the Magento review team!

## Testing module before submitting for review

It is a **MANDATORY** step before applying for the Magento review.

Start the development environment and open shell into running `magento2` container:

    $ docker exec -it magento2 /bin/bash

In the container test the package code:

    $ vendor/bin/phpcs app/code/Smaily/SmailyForMagento --standard=Magento2 --severity=10

> This needs to return no value as all severity level 10 errors will fail automatic review.

Ensure production mode compiles without errors:

    $ bin/magento deploy:mode:set production

Once everything checks out, reset your development environment:

    $ rm -rf generated/metadata/* generated/code/*
    $ bin/magento deploy:mode:set default

## Magento Marketplace review

1. Go to [Magento Developer Portal](https://developer.magento.com/) and log in;
2. Navigate to Extensions section;
3. Find `Smaily Ecommerce Integration` for `M2`;
4. Submit a New Version for review.

**Note!** You can only submit a single version once, and Magento Marketplace and package version must match.
