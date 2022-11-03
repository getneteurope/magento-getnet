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

class Constants 
{
    private $_spIds = [
            'USER' => '515272-ShopPluginTest',
            'PASS' => 'SK6OWY5gdi6;',
            'SECRET' => '9acaf138-7ecb-4bc0-8f4f-b38efaec45df',
            'MERCHANT_ACCOUNT' => '515272-ResShopPlugin',
            'CREDITOR' => 'DE98ZZZ09999999999',
        ];


    public function getParams()
    {
        return [
            'test' => $this->getReturnUrl(),
            'params'        =>  $this->_spIds,
        ];
    }
    
    
}
