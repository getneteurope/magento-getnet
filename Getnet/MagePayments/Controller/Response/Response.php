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
namespace Getnet\MagePayments\Controller\Response;

use Magento\Framework\Controller\ResultFactory;
use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use \Magento\Quote\Model\QuoteFactory as QuoteFactory;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use \stdClass;
use \Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Framework\UrlInterface;


/**
 * Webhook Receiver Controller for Paystand
 */
class Response extends \Magento\Framework\App\Action\Action
{
    const USER_ID = 'payment/paymagento/user_id';
    
    const PASS_KEY = 'payment/paymagento/pasw_key';
    
    const TEST_OPTION = 'payment/paymagento/test_payments';
    
    const MERCHANT_SECRET_KEY = 'payment/paymagento/secret_key';
    
    const MAID = 'payment/paymagento/maid';
    
    private $_quote;
    
    private $modelCart;
    
    private $orderRepository;
    
    private $quoteManagement;
    
    private $eventManager;
    
    private $maskedQuoteIdToQuoteId;
    
    protected $_urlInterface;
    
    protected $urlBuilder;
    
    protected $_curl;
    
    protected $transactionBuilder;
    
    protected $orderSender;
    
    protected $_quoteFactory;

    protected $quoteIdMaskFactory;

    protected $_request;

    protected $_jsonResultFactory;

    protected $quoteRepository;
    
    protected $checkoutSession;
    
    protected $customerSession;
    
    protected $logger;
     
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
        CheckoutSession $checkoutSession,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Sales\Model\OrderRepository $orderRepository,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        \Magento\Framework\Url\DecoderInterface $urlDecoder,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig,
        \Magento\Sales\Model\Order $order,
        OrderSender $orderSender,
        \Magento\Checkout\Model\Cart $modelCart
    ) {
        $this->urlDecoder = $urlDecoder;
        $this->_request = $request;
        $this->urlBuilder = $context->getUrl();
        $this->_curl = $curl;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->eventManager = $eventManager;
        $this->_quoteFactory = $quoteFactory;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->transactionBuilder = $transactionBuilder;
        $this->modelCart = $modelCart;
        $this->scopeConfig = $scopeConfig;
        $this->order = $order;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->customerSession = $customerSession;
        $this->logger = $logger;

        parent::__construct(
                $context,
                $quoteManagement,
                $quoteRepository,
                $customerSession,
                $eventManager,
                $logger);
    }

    /**
     * Receives webhook events from Roadrunner
     */
    public function execute()
{
       $this->logger->debug('----------------------------------------------');
       $this->logger->debug('-------------------Response-------------------');
       $body = $this->getRequest()->getContent();

       //base64_decode()
       $arrayBody = explode("&", $body);
       $signatureBase64  = $arrayBody[0];
       $algoritmo   = $arrayBody[1];
       $message64 = $arrayBody[2];

        //inicializamos algunos valores
        $auth = '';
        $message = '';
        $amount= '0';

    try {
        //quitamos el Encode de la url
          $message64 = urldecode($message64);
          $signatureBase64 = urldecode($signatureBase64);

          //lo parseamos de base64 a String
          $jsonClean = $this->urlDecoder->decode( str_replace('response-base64=','',$message64));
          
          
          //$this->logger->debug($jsonClean);

          //se convirtio a un array de Json
          $jsondata=json_decode($jsonClean , true);
          
          $this->logger->debug('-------------Request ID-------------------');
          
          
          $requestID= $jsondata["payment"]["request-id"];
          $email = $jsondata["payment"]["consumer"]["email"];
          $trxId= $jsondata["payment"]["transaction-id"];
          $status = $jsondata["payment"]["transaction-state"];
          $merchantAccountID = $jsondata["payment"]["merchant-account-id"]["value"];
//          $requestID = str_replace('-get-url','',$requestID);
          
          if (str_contains($requestID, '-')) {
              $arrayBody = explode("-", $requestID);
              $requestID  = $arrayBody[0];
          }
          
          $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE; 
          $merchantSecreyKey =  $this->scopeConfig->getValue(self::MERCHANT_SECRET_KEY, $storeScope);
          $username =    $this->scopeConfig->getValue(self::USER_ID, $storeScope);
          $password =    $this->scopeConfig->getValue(self::PASS_KEY, $storeScope);
          $maid =    $this->scopeConfig->getValue(self::MAID, $storeScope);
          $test     =    $this->scopeConfig->getValue(self::TEST_OPTION, $storeScope);


            try{
                //Data para cancel

                $typeMethod = "";
                $methods=  $jsondata["payment"]["payment-methods"]["payment-method"];
                foreach($methods as $me){
                   $typeMethod = $me["name"];
                
                }
                
                $typeTrx = $jsondata["payment"]["transaction-type"];
                $this->logger->debug($typeMethod);
            } catch (\Exception $ee) {
                $this->logger->debug($ee);
            }
            
            try{
                //Data para cancel (SEPA no lo regresa)
                $parent= $jsondata["payment"]["parent-transaction-id"];
            } catch (\Exception $ee) {
                $parent= ' ';
            }
            
            
             try{
                $iban=     $jsondata["payment"]["bank-account"]["iban"];
                $name=     $jsondata["payment"]["account-holder"]["first-name"];
                $lastname= $jsondata["payment"]["account-holder"]["last-name"];
            } catch (\Exception $ee) {
                $iban= '';
                $name='';
                $lastname='';
            }
            
            try{
                $bic=  $jsondata["payment"]["bank-account"]["bic"];
            } catch (\Exception $ee) {
                $bic= ' ';
            }


          //Paypal e Ideal dont return  
          $auth = $jsondata["payment"]["authorization-code"];
          $amount= $jsondata["payment"]["parent-transaction-amount"]["value"];

          $message = "Payment made with authorization number: " . $auth;
    } catch (\Exception $e) {
            $this->logger->debug($e);
            //paypal, iDeal
            $amount= $jsondata["payment"]["requested-amount"]["value"];
    }
    
            ///////////////////////////////////////////////////////////////////////////////
            ///////////////////////////////////////////////////////////////////////////////
              $valid = $this->isValidSignature($message64, $signatureBase64, $merchantSecreyKey);
            
            // The message was validated with the signature
            if ($valid == '1'){
                        try {
                          $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
                          $orderDatamodel = $objectManager->get('Magento\Sales\Model\Order')->getCollection();
                          $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();
                           
                          $order = $objectManager->create('\Magento\Sales\Model\Order')->load($orderDatamodel->getId());
                    
                          $quoteId = $order->getId();
                          $this->logger->debug('Total --> '.$order->getGrandTotal().'');
                   
                          $quote = $this->quoteRepository->get($quoteId);
                          $order = $this->orderRepository->get($quoteId);
                          
                          $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
                          $magentoVersion = $productMetadata->getVersion();
              
                    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                        
                            //intentamos para SEPA con el correo de la respuesta
                               try {
                                      $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
                                      $orderDatamodel = $objectManager->get('Magento\Sales\Model\Order')->getCollection();
                                      $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();
                                       
                                      $order = $objectManager->create('\Magento\Sales\Model\Order')->load($orderDatamodel->getId());
                                
                                      $quoteId = $order->getId();
                                      $this->logger->debug('Total --> '.$order->getGrandTotal().'');
                               
                                      $quote = $this->quoteRepository->get($quoteId);
                                      $order = $this->orderRepository->get($quoteId);
                                    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                                        $this->logger->debug('Not found email again');
                            
                                          try { 
                                            $quote = $this->quoteRepository->get($requestID);
                                            $order = $this->orderRepository->get($quoteId);
                                          } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                                            $this->logger->debug('The last order could not be read, it will be tested with checkoutSession');
                                            $quote = $this->checkoutSession->getQuote();
                                            $order = $this->orderRepository->get($quoteId);
                                          }
                                   }
                    }
        

                if($status == 'success'){

                        $this->checkoutSession
                            ->setLastQuoteId($quote->getId())
                            ->setLastSuccessQuoteId($quote->getId())
                            ->clearHelperData();
                            
                            
                                if ($order) {
                                    try {

                                            if (!$this->customerSession->isLoggedIn()) {
                                                $quote->setCheckoutMethod('guest');
                                                $quote->setCustomerIsGuest(true);
                                                $quote->setCustomerEmail($email);
                                            }
                                        
                                        
                                        
                                        $this->checkoutSession
                                            ->setLastOrderId($order->getId())
                                            ->setLastRealOrderId($order->getIncrementId())
                                            ->setLastOrderStatus($order->getStatus());
                                        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                                        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                                        $this->orderSender->send($order);
                                        
                                        $order->addStatusToHistory('processing',$typeTrx, false);
                                        $order->addStatusToHistory('processing',__('Payment process with ').$typeMethod .', requestID: ' .$requestID, false);
                                        $order->save();
                        
        
                                        $payment = $order->getPayment();
                                        $payment->setTransactionId($trxId);
                                        $payment->setIsTransactionClosed(0)
                                                ->setTransactionAdditionalInfo(
                                                        $typeMethod,
                                                        htmlentities($trxId)
                                                    );
                                        $payment->setLastTransId($trxId);
                                        $payment->setIsTransactionClosed(true);
                                        $payment->setShouldCloseParentTransaction(true);
                                        $transaction = $payment->addTransaction(
                                               \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH
                                             );
                                        
        
                            			//set Json String with additional data
                            			$additional = '{"transaction-type":"'.$typeTrx.'", "requestID":"'.$requestID.'", "transaction-id":"'.$trxId.'"}';
                            			$payment->setAdditionalData($additional);
                            			$payment->setParentTransactionId($parent);
                            			
                            			$payment->setIsTransactionPending(false);
                            			$payment->setIsTransactionApproved(true);
                            			
                            			$addresss = $objectManager->get('\Magento\Customer\Model\AddressFactory');
                            			$address = $addresss->create();
        			                    $address = $order->getBillingAddress();
        						        $address->setIsDefaultBilling(false)
        								        ->setIsDefaultShipping('1')
        								        ->setSaveInAddressBook('1');
        						        $address->save();
        
                                        //for Refund and Capture
                                        $domain = $this->urlBuilder->getRouteUrl('paymagento');
        
                                        $payment->setAdditionalInformation('requestID', $requestID);
                                        $payment->setAdditionalInformation('transaction-id', $trxId);
                                        $payment->setAdditionalInformation('payment-methods', $typeMethod);
                                        $payment->setAdditionalInformation('transaction-type', $typeTrx);
                                        $payment->setAdditionalInformation('merchantAccountID', $merchantAccountID);
                                        
                                        $payment->setAdditionalInformation('bic', $bic);
                                        $payment->setAdditionalInformation('iban', $iban);
                                        $payment->setAdditionalInformation('name', $name);
                                        $payment->setAdditionalInformation('lastname', $lastname);
                                        $payment->setAdditionalInformation('maid', $maid);
                                        $payment->setAdditionalInformation('domain', $domain);
                                        $payment->setAdditionalInformation('test', $test);
                                        $payment->setAdditionalInformation('magVersion', $magentoVersion);
                                        $payment->setAdditionalInformation('settings', base64_encode($username.'&&&'.$password));
                                        
                                        
        
                            			$transaction = $this->transactionBuilder->setPayment($payment)
                            				->setOrder($order)
                            				->setTransactionId($trxId)
                            				->setAdditionalInformation($payment->getTransactionAdditionalInfo())
                            				->build(Transaction::TYPE_AUTH);
                            			$payment->addTransactionCommentsToOrder($transaction, $message);
                                        $payment->save();
                                        $transaction->save();
        
        
         /*                                  
                                        //  GENERATE INVOICE
                                        $invoice = $objectManager->create('Magento\Sales\Model\Service\InvoiceService')->prepareInvoice($order);
                            			$invoice = $invoice->setTransactionId($payment->getTransactionId())
                                            ->addComment("Invoice created.")
                            				->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                            
                            			$invoice->setGrandTotal($amount);
                                        $invoice->setBaseGrandTotal($amount);
                            
                                        $invoice->register()
                                            ->pay();
                            			$invoice->save();
                    
        
                                        //GENERATE INVOICE TRANSACCION
                            			// Save the invoice to the order
                            			$transaction = $this->_objectManager->create('Magento\Framework\DB\Transaction')
                            				->addObject($invoice)
                            				->addObject($invoice->getOrder());
                            			$transaction->save();
                            
                            			
                            			$order->addStatusHistoryComment(
                            				__('Invoice #%1.', $invoice->getId())
                            			)
                            			->setIsCustomerNotified(true);
                            			
                            			$order->save();
        */                                    
                                        
                                        if ($order) {
                                                $this->checkoutSession->setLastOrderId($order->getId())
                                                                   ->setLastRealOrderId($order->getIncrementId())
                                                                   ->setLastOrderStatus($order->getStatus());
                                            }
                                            
                                        $this->messageManager->addSuccessMessage(__('Your payment was processed correctly'));
                        
                                    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                                                $this->messageManager->addErrorMessage(__('ERROR WHILE SAVING THE TRANSACTION'));
                                    }
                                
                                
                                    $cart = $this->modelCart;
                                    $cart->truncate();
                                    $cart->save();
                                    $items = $quote->getAllVisibleItems();
                                    foreach ($items as $item) {
                                        $itemId = $item->getItemId();
                                        $cart->removeItem($itemId)->save();
                                    }
                    
                                    
                                    $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                                    $resultRedirect->setPath('checkout/onepage/success');
                                    $this->logger->debug('Finished order complete controller.');
                            } // finaliza el $order
            
                }
                    
            } else { //Error in response
                    $this->messageManager->addErrorMessage(__('Invalid Response'));
                    $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
                    $resultRedirect->setPath('checkout/cart');
                    $this->logger->debug('The response did not pass the validation');
            }


   return $resultRedirect;
  }
  
  
  
  /**
     * @param string $responseBase64
     * @param string $signatureBase64
     * @return bool
     */
     
    protected function isValidSignature($responseBase64, $signatureBase64, $merchantSecreyKey)
    {
        $result = '';
         $this->logger->debug('---Validating----');
         
         $signatureBase64= str_replace('response-signature-base64=','',$signatureBase64);
         $responseBase64= str_replace('response-base64=','',$responseBase64);
         
         try{

                  $signatureLocal = base64_encode(hash_hmac('sha256', $responseBase64, $merchantSecreyKey, true));

                  $result = hash_equals($signatureLocal, $signatureBase64);
                  $this->logger->debug($result);
                  $this->logger->debug('---end--');
            } catch (\Exception $ee) {
                $this->logger->debug('Error in validation HASH --> '.$ee.'');
            }

        return $result;
    }
  

}
