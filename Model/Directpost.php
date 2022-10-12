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

class Directpost extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'paymagento';

    /**
     * Payment code
     *
     * @var string
     */
    protected $_code = 'paymagento'; 

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isOffline = false;

    /**
     * Check whether there are CC types set in configuration
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        return parent::isAvailable($quote)
        && $this->getConfigData('pasw_key', $quote ? $quote->getStoreId() : null);
    }
}
