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
namespace Getnet\MagePayments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Getnet\MagePayments\Model\Directpost;

class AfterOrderPlaceObserver implements ObserverInterface
{
  /**
   * Sets order status to pending
   * @param \Magento\Framework\Event\Observer $observer
   * @return void
   */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        if ($payment->getMethod() == Directpost::METHOD_CODE) {
            $order->setState('pending_payment');
            $order->setStatus('pending_payment');
        }
    }
}
