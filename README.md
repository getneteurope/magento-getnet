## Copy module
1. From the Magento root folder, enter the app/code path
2. Inside there unzip the provided ZIP called Getnet_MagePayments 1.0.0

## How to install the Module:

1. Enter the initial path of your Magento 2 installation (root folder)
2. Once inside please run the following commands one by one.
```
php bin/magento cache:disable
php bin/magento setup:upgrade

php bin/magento setup:di:compile
phpbin/magentocache:enable
```

## Configure the Payment module
1. Go to Stores/Configuration/Sales/Payment Methods option
It should already appear as a Getnet payment option.
2. Enter the credentials that were granted by your Payment Center advisor, the currency to be placed
it can be pesos or dollars, please enter the code USD or MXN.

Doubts about the installation please contact your advisor or email integraciones@mitec.com.mx

Compatible up to Magento 2.3.7-p3
