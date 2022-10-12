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
namespace Getnet\MagePayments\Controller\Webhook;

use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Framework\Controller\ResultFactory;


class Refund extends \Magento\Framework\App\Action\Action
{
    protected $messageManager;

    protected $logger;
        
    protected $resultPageFactory;

    protected $orderRepository;

    protected $_curl;
    
    private $remoteAddress;

    protected $orderSender;

    /**
     * @param \Magento\Framework\App\Action\Context       $context
     * @param \Magento\Framework\View\Result\PageFactory  $resultPageFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender
        
    ) {

        $this->remoteAddress = $remoteAddress;
        $this->_curl = $curl;
        $this->messageManager = $messageManager;
        $this->resultPageFactory = $resultPageFactory;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->logger = $logger;
        parent::__construct($context);
    }
    /**
     * Detect Mobile view or Desktop View
     *
     * @return void
     */
    public function execute()
    {
        
        $orderId = $this->getRequest()->getParam('id');        

        $this->logger->debug('Entro Refund con id --> '.$orderId);
        $this->logger->debug('----------------------');
        
        $order = $this->orderRepository->get($orderId);
        $state = $order->getState();
        
        $this->logger->debug('Get status --> '.$state);
        
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
        
        
        if($state == 'processing'){
            
             /////////////////   ADDITIONAL INFORMATION   //////////////////  
                try{
                    $requestID = $payment->getAdditionalInformation('requestID');
                    $parentTrx =  $payment->getAdditionalInformation('transaction-id');
                    $paymentMethod =  $payment->getAdditionalInformation('payment-methods');
                    $transactionType = $payment->getAdditionalInformation('transaction-type');
                    $merchantAccountID = $payment->getAdditionalInformation('merchantAccountID');
                    $magentoVersion = $payment->getAdditionalInformation('magVersion');
                    $maid = $payment->getAdditionalInformation('maid');
                    $iban = $payment->getAdditionalInformation('iban');
                    $bic =  $payment->getAdditionalInformation('bic');
                    $firstname = $payment->getAdditionalInformation('name');
                    $lastname = $payment->getAdditionalInformation('lastname');
                    $test = $payment->getAdditionalInformation('test');
                    $settings = $payment->getAdditionalInformation('settings');
            
                    $settings= base64_decode($settings);
                    $arrayBody = explode("&&&", $settings);
                    
                    $username = $arrayBody[0];
                    $password = $arrayBody[1];
                } catch (\Exception $e) {
                    $this->logger->debug('---error---'.$e.'-');
                }
        
        
                 ////////////////////////////////////////////////////////
                 //           1 --> Test Mode activated
                 if($test == '1'){ 
                           $url = 'https://api-test.getneteurope.com/engine/rest/payments/';
                           
                           if($paymentMethod == 'sofortbanking' || $paymentMethod == 'ideal' || $paymentMethod == 'sepadirectdebit'){
                              $url = 'https://api-test.getneteurope.com/engine/rest/paymentmethods/'; 
                           }
            
                 } else { //produccion
                           $url = 'https://api.getneteurope.com/engine/rest/payments/';
                           
                          if($paymentMethod == 'sofortbanking' || $paymentMethod == 'ideal' || $paymentMethod == 'sepadirectdebit'){
                              $url = 'https://api.getneteurope.com/engine/rest/paymentmethods/'; 
                           }
                 }
            
            
            
                $action = 'refund';
                
    
                //  table status
                $transactionType = $this->getTransactionRefund($action, $transactionType, $paymentMethod);  
        
                $newRequestID = time().'REFUND'.substr($requestID,10,20);
                $varAmount = '';
                $account = '';
                
                
                $this->logger->debug('Payment Method --> ' . $paymentMethod);
        
            if($paymentMethod == 'sofortbanking' || $paymentMethod == 'alipay-xborder' || $paymentMethod == 'ideal' || $paymentMethod == 'sepadirectdebit'){
               $varAmount = '"requested-amount": {
                                "value": '.$amount.',
                                "currency": "'.$currency.'"
                },'; 
            }

            if($paymentMethod == 'ideal'){
                $account = '"account-holder": {
                "first-name": "'.$firstname.'",
                "last-name": "'.$lastname.'"
                },
                "bank-account" : {
                  "bic" : "'.$bic.'",
                  "iban" : "'.$iban.'"
                },';
                
            } else if($paymentMethod == 'sepadirectdebit' || $paymentMethod == 'sofortbanking'){
                $account = '"account-holder": {
                "first-name": "'.$firstname.'",
                "last-name": "'.$lastname.'"
                },
                "bank-account" : {
                      "iban" : "'.$iban.'"
                    },';
    
            }
            
    $shopVersion = '"shop":{
                    "system-name":"Magento",
                    "system-version":"'.$magentoVersion.'",
                    "plugin-name":"Magento_getnet_plugin",
                    "plugin-version":"1.0.0",
                    "integration-type":"redirect"
                },';    
            
    
        if($paymentMethod == 'ideal' || $paymentMethod == 'sepadirectdebit' ||  $paymentMethod == 'sofortbanking'){
             $xml = '{
                    "payment": {
                        "merchant-account-id": {
                            "value": "'.$maid.'"
                        },
                        "request-id": "'.$newRequestID.'",
                        "transaction-type": "'.$transactionType.'",
                        "payment-methods": {
                            "payment-method": [
                                {
                                    "name": "sepacredit"
                                }   
                            ]
                        },
                        '.$varAmount.'
                        '.$account.'
                        '.$shopVersion.'
                        "ip-address": "'.$ip.'",
                        "parent-transaction-id": "'.$parentTrx.'"
                    }
                }';
            
        } else {
             $xml = '{
                        "payment": {
                            "merchant-account-id": {
                                "value": "'.$merchantAccountID.'"
                            },
                            '.$varAmount.'
                            '.$shopVersion.'
                            "request-id": "'.$newRequestID.'",
                            "transaction-type": "'.$transactionType.'",
                            "ip-address": "'.$ip.'",
                            "parent-transaction-id": "'.$parentTrx.'"
                        }
                    }';
        }


               // $this->logger->debug($xml);  
                    
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
                     $this->logger->debug('Error Refund');
                }
            
            
             //json to object
                $jsonResponse=json_decode($response , true);
                
                $newStatus = $jsonResponse["payment"]["transaction-state"];
                $newTransactionType = $jsonResponse["payment"]["transaction-type"];
                $newRequestID = $jsonResponse["payment"]["request-id"];
                $newMerchantAccountID = $jsonResponse["payment"]["merchant-account-id"]["value"];
                
                $success = '';
                $more = '';
                
                if (str_contains($newStatus, 'success') ) {

                        //start refund
                        $payment = $order->getPayment();
                        $payment->setAdditionalInformation('requestID', $newRequestID);
                        $payment->setAdditionalInformation('transaction-type', $newTransactionType);
                        $payment->setAdditionalInformation('merchantAccountID', $newMerchantAccountID);
                        $payment->save();
                                    
                        if($paymentMethod == 'sepadirectdebit'){
                            if($newTransactionType == 'debit' || $newTransactionType == 'credit'){
                                   $success = 'yes';
                                   $more = ' - ' . $newTransactionType;
                            } else {
                                   $success = 'no';
                            }
    
                        } else {
                           $success = 'yes';
                        }
            

                } else {
                        $success = 'no';
                        $more = ' - ' . $newTransactionType;
                }
                
               if (str_contains($newRequestID, '-')) {
                  $arrayBody = explode("-", $newRequestID);
                  $newRequestID  = $arrayBody[0];
                }
                
                
                if($success == 'yes'){
                           $order->addStatusToHistory('canceled',__('Refund of the order placed ') . $more , false);
                           $order->save();
                           $this->messageManager->addSuccessMessage(__('Refund Success.'));

                } else {
                      if($paymentMethod == 'sepadirectdebit'){
                          $order->addStatusToHistory('processing',__('New transaction-type ->').$newTransactionType .', requestID: ' .$newRequestID, false);
                      } else {
                          $order->addStatusToHistory('processing',__('Refund Failed '), false);
                      }
                        
                        $order->save();
                            $this->messageManager->addErrorMessage(__('Refund Failed.'));
                }
                    
        
            
        } else {
                    $order->addStatusToHistory('processing',__('Invalid Status '), false);
                    $order->save();
                        $this->logger->debug('Status canceled');
                        $this->messageManager->addErrorMessage(__('Invalid Operation.'));
        }
        


        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        $this->logger->debug('--- End refund --');
                
        return $resultRedirect;
    }
    
    
    
    
    
  /**
   * 
   * 
   * 
   */
    private function getTransactionRefund($action, $transactionType, $paymentMethod)
    {
         $this->logger->debug('Transaction Type --> ' . $transactionType);
        
        if($paymentMethod  == 'creditcard'){
            if($transactionType == 'capture-authorization'){
                $newtransactionType= 'refund-capture';
                
            } else if($transactionType == 'purchase'){
                $newtransactionType= 'refund-purchase';
            }


        } else if($paymentMethod  == 'paypal'){
                if($transactionType == 'capture-authorization'){
                    $newtransactionType= 'refund-capture';
                    
                } else if($transactionType == 'debit'){
                    $newtransactionType= 'refund-debit';
                }
                        

        } else if($paymentMethod  == 'ideal'){
                        $newtransactionType= 'credit';
                        
        } else if($paymentMethod  == 'sepadirectdebit'){
                        $newtransactionType= 'credit';
                                    
        } else if($paymentMethod  == 'sofortbanking'){
                        $newtransactionType= 'credit';
        
        } else if($paymentMethod  == 'alipay-xborder'){ 
                        $newtransactionType= 'refund-debit';
        
        } else if($paymentMethod  == 'p24'){
                        $newtransactionType= 'refund-request';
        
        } else if($paymentMethod  == 'blik'){ //review word
                        $newtransactionType= 'refund-debit';
        }
        
        $this->logger->debug('newtransaction Type --> ' . $newtransactionType);
        
        return $newtransactionType;    
    }
}