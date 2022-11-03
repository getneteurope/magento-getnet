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
declare(strict_types=1);

namespace Getnet\MagePayments\Plugin\Block\Widget\Button;

use Magento\Sales\Block\Adminhtml\Order\Create;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Backend\Block\Widget\Button\ButtonList;
use Magento\Backend\Block\Widget\Button\Toolbar as ToolbarContext;

class ToolbarPlugin
{
    
    public function beforePushButtons(
        ToolbarContext $toolbar,
        AbstractBlock $context,
        ButtonList $buttonList
        
    ): array {
        $order = false;
        $state='';
        $nameInLayout = $context->getNameInLayout();
        if ('sales_order_edit' == $nameInLayout) {
            $order = $context->getOrder();
            $orderID = $order->getId();
            $state = $order->getState();
            $payment = $order->getPayment();
            $domain = $payment->getAdditionalInformation('domain');
            $paymentMethod =  $payment->getAdditionalInformation('payment-methods');
            $trxType =  $payment->getAdditionalInformation('transaction-type');
        }


      try{
                if ($order) {
                    
                    $dateTrx = date_create($order->getCreatedAt());
                    $dateTrx = date_format($dateTrx,"d/m/Y");
                    $sysdate = date("d/m/Y");
                    $merchantAccounID = 'ba261be8-af94-11df-ab78-00163e5eafd7';

                        if(str_contains($trxType, 'pending')){
                            $urlRefund = $domain.'webhook/UpdateStatus?id='.$orderID;
                            $message = __('Do you want to check if there are new order statuses?');
                            
                	        $buttonList->add(
                	            'refreshOrder_button',
                	            [
                	                'label' => __('Status Update'),
                	                'onclick' => "confirmSetLocation('{$message}', '{$urlRefund}')",
                	            	'sort_order' => 3,
                	                'id' => 'refund_button'
                	            ]
                	        );
                        }
                        
                    if($state == 'processing'){

                        if($trxType == 'capture-authorization' || $trxType == 'purchase' || $trxType == 'debit'){    
                            $urlRefund = $domain.'webhook/refund?id='.$orderID;
                            $message = __('Are you sure you want to refund this order?');
                            
                	        $buttonList->add(
                	            'refund_button',
                	            [
                	                'label' => __('Refund'),
                	                'onclick' => "confirmSetLocation('{$message}', '{$urlRefund}')",
                	            	'sort_order' => 3,
                	                'id' => 'refund_button'
                	            ]
                	        );
                        }
                        
            	       
            	       if($paymentMethod == 'creditcard' || $paymentMethod == 'paypal'){
            	           if($trxType == 'authorization'){
                	             $urlCapture = $domain.'webhook/capture?id='.$orderID;
                	             $message = __('Are you sure you want to capture this order?');
                    	         $buttonList->add(
                    	            'capture_button',
                    	            [
                    	                'label' => __('Capture'),
                    	                'onclick' => "confirmSetLocation('{$message}', '{$urlCapture}')",
                    	            	'sort_order' => 2,
                    	                'id' => 'capture_button'
                    	            ]
                    	        );  
            	           }
            	       } 
            	       
            	       
            	       if($paymentMethod == 'alipay-xborder' || $paymentMethod == 'p24' || $paymentMethod == 'sofortbanking' || $paymentMethod == 'ideal' || $paymentMethod == 'blik'){
            	           $buttonList->remove('order-view-cancel-button');
            	       }
            	       
                    }
                }
                
        
            } catch (\Exception $e) {
                $this->logger->debug('---error---'.$e.'-');
            }

        return [$context, $buttonList];        
    }
}