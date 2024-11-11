# DPO Magento 2

## DPO Pay Magento 2 plugin v1.3.0 for Magento v2.4.7

This is the DPO Group plugin for Magento 2. Please feel free to contact
the [DPO Group support team](https://dpogroup.com/contact-us/) should you require any assistance.

## Installation

### Option 1 - Automatic Installation

Install the module using the following composer command:

```console
composer require dpo/dpo-gateway
```

### Option 2 - Manual Installation

Navigate to the [releases page](https://github.com/DPO-Group/DPO_Magento_2/releases) and download [Dpo.zip](https://github.com/DPO-Group/DPO_Magento_2/releases/download/1.3.0/Dpo.zip).
Extract the contents of the mentioned zip file, then upload the newly created **Dpo** directory into your Magento
app/code directory (e.g. magentorootfolder/app/code/).

### Magento CLI Commands

Run the following Magento CLI commands:

```console
composer require dpo/dpo-pay-common:1.0.2
php bin/magento module:enable Dpo_Dpo
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento indexer:reindex
php bin/magento cache:clean
```

### Configuration

Login to the admin panel and navigate to **Stores** > **Configuration** > **Sales** > **Payment Methods** and click on
**DPO Pay**. Configure the module according to your needs, then click the **Save Config** button.

## Collaboration

Please submit pull requests with any tweaks, features or fixes you would like to share.
