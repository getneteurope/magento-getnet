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


class Capture extends \Magento\Framework\App\Action\Action
{
    protected $messageManager;

    protected $logger;
        
    protected $resultPageFactory;

    protected $orderRepository;

    protected $_curl;
    
    private $remoteAddress;

    protected $orderSender;
    
    protected $transactionBuilder;

    /**
     * @param \Magento\Framework\App\Action\Context       $context
     * @param \Magento\Framework\View\Result\PageFactory  $resultPageFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
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
        $this->transactionBuilder = $transactionBuilder;
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

        $this->logger->debug('Entro Capture con id --> '.$orderId);
        $this->logger->debug('----------------------');
        
        $order = $this->orderRepository->get($orderId);
        $state = $order->getState();
        
        $this->logger->debug('Get status --> '.$state);
        

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
            
                 } else { //produccion
                           $url = 'https://api.getneteurope.com/engine/rest/payments/';
                 }
            

                $newRequestID = time().'CAPT'.substr($requestID,10,20);
                $this->logger->debug('Payment Method --> ' . $paymentMethod);
        
    
    
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
                        "request-id": "'.$newRequestID.'",
                        "transaction-type": "capture-authorization",
                        "ip-address": "'.$ip.'",
                        "parent-transaction-id": "'.$parentTrx.'"
                    }
                }';


//                $this->logger->debug($xml);  
                    
                try {
                    $credentials = base64_encode( $username . ':' . $password);
                     $this->logger->debug("Basic ".$credentials);
      
                    $this->_curl->addHeader("Content-Type", "application/json");
                    $this->_curl->addHeader("Accept", "application/json");
                    $this->_curl->addHeader("Authorization", "Basic ".$credentials);
                    $this->_curl->post($url, $xml);
                    $responseJson = $this->_curl->getBody();
                      
//                     $this->logger->debug($responseJson);
                     
                     $jsondata=json_decode($responseJson , true);
                     $trxId= $jsondata["payment"]["transaction-id"];
                     $status = $jsondata["payment"]["transaction-state"]; 

                     $this->logger->debug('----');

                     if ($status== 'success') {
                                $payment = $order->getPayment();
                                $typeTrx = $jsondata["payment"]["transaction-type"];
                                $payment->setAdditionalInformation('transaction-type','capture-authorization');
                                $payment->setAdditionalInformation('transaction-id', $trxId);
                                $payment->save();


                                $order->addStatusToHistory('processing',__('Capture placed '), false);
                                $order->save();
                                    $this->messageManager->addSuccessMessage(__('Capture placed '));
                                    
                        } else {
                                $order->addStatusToHistory('processing',__('Invalid Operation '), false);
                                $order->save();
                                    $this->messageManager->addErrorMessage(__('Invalid Operation.'));
                        }

                } catch (\Magento\Framework\Exception\Exception $e) {
                     $this->logger->debug('Error Capture');
                }
            

        } else {
                    $order->addStatusToHistory('processing',__('Invalid Operation'), false);
                    $order->save();
                    
                    $this->logger->debug('Invalid Operation');
                    $this->messageManager->addErrorMessage(__('Invalid Operation.'));
        }
        


        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);


        $resultRedirect->setUrl($this->_redirect->getRefererUrl());
        $this->logger->debug('--- End Capture --');
                
        return $resultRedirect;
    }
    
    
}