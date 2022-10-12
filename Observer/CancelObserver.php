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
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;

class CancelObserver implements ObserverInterface
{
   protected $messageManager;

   protected $_curl;
    
   protected $order;
   
   private $remoteAddress;
   
   protected $logger;

    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Psr\Log\LoggerInterface $logger,
        Order $order
    )
    {
        $this->messageManager = $messageManager;
        $this->remoteAddress = $remoteAddress;
        $this->_curl = $curl;
        $this->order = $order;
        $this->logger = $logger;
    }
    
    
    
   /**
   * Sets order status to pending
   * @param \Magento\Framework\Event\Observer $observer
   * @return void
   */
    public function execute(\Magento\Framework\Event\Observer $observer){
          $this->logger->debug('--------------------------------------------');
          $this->logger->debug('----Cancel observer');
      
          $order = $observer->getEvent()->getOrder();
    
          $state = $order->getState();
      
      
          //////////////////////////////////////////////////////
          //////// validate date for Cancel or Refund //////////
          //////////////////////////////////////////////////////
          $dateTrx = date_create($order->getCreatedAt());
          $dateTrx = date_format($dateTrx,"d/m/Y");
          $sysdate = date("d/m/Y");
          $this->logger->debug($dateTrx);
    
          
          $payment = $order->getPayment();
          $amount = $order->getGrandTotal();
          $currency = $order->getOrderCurrencyCode();

    
          $ip = $this->remoteAddress->getRemoteAddress();
    

         /////////////////   ADDITIONAL INFORMATION   //////////////////  
        try{
            $requestID = $payment->getAdditionalInformation('requestID');
            $parentTrx =  $payment->getAdditionalInformation('transaction-id');
            $paymentMethod =  $payment->getAdditionalInformation('payment-methods');
            $transactionType = $payment->getAdditionalInformation('transaction-type');
            $merchantAccountID = $payment->getAdditionalInformation('merchantAccountID');
            $magentoVersion = $payment->getAdditionalInformation('magVersion');
            $test = $payment->getAdditionalInformation('test');
            $settings = $payment->getAdditionalInformation('settings');
    
            $settings= base64_decode($settings);
            $arrayBody = explode("&&&", $settings);
            
            $username = $arrayBody[0];
            $password = $arrayBody[1];
        } catch (\Exception $e) {
            $this->logger->debug('---error---'.$e.'-');
        }

      
        $this->logger->debug('paymentMethod --> '.$paymentMethod);
        $this->logger->debug('transactionType --> '.$transactionType);
      
      if($transactionType == 'debit' ||  str_contains($transactionType, 'refund') || ($transactionType == 'authorization' && $paymentMethod == 'sepadirectdebit')){
          $this->logger->debug('Invalid Operation --> '.$transactionType);
          throw new \Magento\Framework\Exception\LocalizedException(__("Invalid Operation"));
          
      } else { 

         ////////////////////////////////////////////////////////
         //           1 --> Test Mode activated
         if($test == '1'){ 
                   $url = 'https://api-test.getneteurope.com/engine/rest/payments/';
    
         } else { //produccion
                   $url = 'https://api.getneteurope.com/engine/rest/payments/';
         }
         
         
         
           ///////////////////////////////////////////////////////////////
           //////      Debit transactions are not canceled      /////////
            if (str_contains($transactionType, 'debit')) {
                
                     if($dateTrx == $sysdate){ //Same day but is debit, You can not cancel
                            throw new \Magento\Framework\Exception\LocalizedException(__("The payment was with a debit card, cancellation not allowed"));
        
                     } else { //Different day,  No cancel but you can make the return
                         $action = 'refund';
                     }
                 
            } else {
                    // Set the transaction type according to the action (void => cancel , refund => refund)
                      if($dateTrx == $sysdate){ 
                          $action = 'void';
            
                      } else { 
                          $action = 'refund';
                      }
            }
    
                  
                //  table status
                $transactionType = $this->getTransactionType($action, $transactionType, $paymentMethod);  
        
                $newRequestID = time().'POS'.substr($requestID,10,20);
                $varAmount = '';
                $account = '';
                
                
                $this->logger->debug('Payment Method --> ' . $paymentMethod);
        
                if($paymentMethod == 'alipay-xborder'){
                   $varAmount = '"requested-amount": {
                                    "value": '.$amount.',
                                    "currency": "'.$currency.'"
                    },'; 
                }
    
    
             $xml = '{
                        "payment": {
                            "merchant-account-id": {
                                "value": "'.$merchantAccountID.'"
                            },
                            "shop":{
                                "system-name":"Magento",
                                "system-version":"'.$magentoVersion.'",
                                "plugin-name":"Magento_getnet_plugin",
                                "plugin-version":"1.0.0",
                                "integration-type":"redirect"
                            },
                            '.$varAmount.'
                            "request-id": "'.$newRequestID.'",
                            "transaction-type": "'.$transactionType.'",
                            "ip-address": "'.$ip.'",
                            "parent-transaction-id": "'.$parentTrx.'"
                        }
                    }';
    
               
    
    
                $this->logger->debug($xml);  
                    
                try {
                    $credentials = base64_encode( $username . ':' . $password);
                     $this->logger->debug("Basic ".$credentials);
      
                    $this->_curl->addHeader("Content-Type", "application/json");
                    $this->_curl->addHeader("Accept", "application/json");
                    $this->_curl->addHeader("Authorization", "Basic ".$credentials);
                    $this->_curl->post($url, $xml);
                    $response = $this->_curl->getBody();
                      
                     $this->logger->debug($response);  
                } catch (\Magento\Framework\Exception\Exception $e) {
                     $this->logger->debug('Error generate HPP');
                }
    
    
    
            if (str_contains($response, '"transaction-state":"success"') ) {
    
                  if ($action == 'refund') {
                        $this->messageManager->addSuccessMessage(__('Refund of the order placed '));
                        $order->addStatusToHistory('canceled',__('Refund of the order placed '), false);
                   } else {
                       $order->addStatusToHistory('canceled',__('Cancel placed '), false);
                   }
                   
                $order->save();
                      
            } else {
                $order->addStatusToHistory('processing',__('Fail to Cancel'), false);
                $order->save();
                throw new \Magento\Framework\Exception\LocalizedException(__("Technical error in the cancellation"));
            }

      }


    $this->logger->debug('--fin--');
    }
    
  
  
  
  
  
  /**
   * 
   * 
   * 
   */
    private function getTransactionType($action, $transactionType, $paymentMethod)
    {
        
       if($paymentMethod  == 'creditcard'){
            if($action == 'void'){
                        if($transactionType == 'purchase'){
                            $NewtransactionType = 'void-purchase';
                            
                        } else if($transactionType == 'capture-authorization'){
                            $NewtransactionType = 'void-capture';

                        } else {
                            $NewtransactionType = 'void-authorization';
                        }
                        
            } else if($action == 'refund'){
                       if($transactionType == 'purchase'){
                            $NewtransactionType = 'refund-purchase';
                        } else {
                            $NewtransactionType = 'refund-capture';
                        }
            }
            
        } else if($paymentMethod  == 'paypal'){
            if($action == 'void'){
                        if($transactionType == 'authorization'){
                            $NewtransactionType = 'void-authorization';
                            
                        } else if($transactionType == 'capture-authorization'){
                            $NewtransactionType = 'void-capture';
                        }
                        
            } else if($action == 'refund'){
                        if($transactionType == 'debit'){
                            $NewtransactionType = 'refund-debit';
                        } else {
                            $NewtransactionType = 'refund-capture';
                        }
            }
        
        } else if($paymentMethod  == 'alipay-xborder'){
                        $NewtransactionType = 'refund-debit';
        
        } else if($paymentMethod  == 'p24'){
                        $NewtransactionType = 'refund-request';
        
        } else if($paymentMethod  == 'blik'){ //review word
                        $NewtransactionType = 'refund-debit';
        }
        
        return $NewtransactionType;    
    }
    
    
}