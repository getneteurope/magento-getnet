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


class UpdateStatus extends \Magento\Framework\App\Action\Action
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

        $this->logger->debug('Update Status --> '.$orderId);
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
                           $url = 'https://api-test.getneteurope.com/engine/rest/';
                           
                 } else { //produccion
                           $url = 'https://api.getneteurope.com/engine/rest/';
                 }

                 
                if (str_contains($requestID, '-')) {
                  $arrayBody = explode("-", $requestID);
                  $requestID  = $arrayBody[0];
                }


                $url = $url. 'merchants/' .$merchantAccountID. '/payments/search?payment.request-id=' .$requestID;

                $this->logger->debug($url);
                    
                try {
                    $credentials = base64_encode( $username . ':' . $password);
                     $this->logger->debug("Basic ".$credentials);
      
                    $this->_curl->addHeader("Content-Type", "application/json");
                    $this->_curl->addHeader("Accept", "application/json");
                    $this->_curl->addHeader("Authorization", "Basic ".$credentials);

                    //get method
                    $this->_curl->get($url);

                    //response will contain the output of curl request
                    $response = $this->_curl->getBody();

                      
                     $this->logger->debug('Response --> ' .$response);  
                    
                    
                    if (str_contains($response, 'transaction-type')) {
                             //json to object
                            $jsonResponse=json_decode($response , true);
                            
                            $newStatus = $jsonResponse["payment"]["transaction-state"];
                            $newTransactionType = $jsonResponse["payment"]["transaction-type"];
                            
                            if($transactionType != $newTransactionType){
                                        $payment = $order->getPayment();
                                        $payment->setAdditionalInformation('transaction-type',$newTransactionType);
                                        $payment->save();
                             
                                         $order->addStatusToHistory('processing',__('New transaction-type ->').$newTransactionType, false);
                                         $order->save();
                                         
                                         $this->messageManager->addSuccessMessage(__('Status updated'));  
                            } 
                                    
                    } else {
                         $order->addStatusToHistory('processing',__('transaction-type ->').$transactionType, false);
                         $order->save();
                        $this->messageManager->addSuccessMessage(__('The query returned the same current status'));
                    }
                     
                } catch (\Magento\Framework\Exception\Exception $e) {
                     $this->logger->debug('Error update gtatus');
                }
            
        } else {
                    $order->addStatusToHistory('processing',__('Invalid Status '), false);
                    $order->save();
                        $this->logger->debug('Status canceled');
                        $this->messageManager->addErrorMessage(__('Invalid Operation.'));
        }
        


        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        $this->logger->debug('--- End Update --');
                
        return $resultRedirect;
    }
    
}