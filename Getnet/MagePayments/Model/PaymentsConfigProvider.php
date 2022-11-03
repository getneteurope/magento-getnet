<?php
/**
 *
 * Copyright © 2022 PagoNxt Merchant Solutions S.L. 
 * and Santander España Merchant Services, 
 * Entidad de Pago, S.L.U.  
 * 
 * All rights reserved.
 *
 */
namespace Getnet\MagePayments\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;

class PaymentsConfigProvider implements ConfigProviderInterface
{
  /**
   * @var \Magento\Framework\App\Config\ScopeConfigInterface
   */

    protected $scopeConfig;

    const PUBLISHABLE_KEY = 'payment/paymagento/pasw_key';

    const CUSTOMER_ID = 'payment/paymagento/user_id';

    const CURRENCY_STORE = 'payment/paymagento/merchant_account';
    
    const TEST_PAYMENT = 'payment/paymagento/test_payments';
    
    const MAID = 'payment/paymagento/maid';
    
 
    public function __construct(
        ScopeConfig $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }
 


    public function getConfig()
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

        $config = [
        'payment' => [
        'paymagento' => [
          'pasw_key' => $this->scopeConfig->getValue(self::PUBLISHABLE_KEY, $storeScope),
          'user_id' => $this->scopeConfig->getValue(self::CUSTOMER_ID, $storeScope),
          'merchant_account' => $this->scopeConfig->getValue(self::CURRENCY_STORE, $storeScope),
          'test_payments' => $this->scopeConfig->getValue(self::TEST_PAYMENT, $storeScope),
          'maid' => $this->scopeConfig->getValue(self::MAID, $storeScope)
                        ]
                    ]
                ];

        return $config;
    }
}
