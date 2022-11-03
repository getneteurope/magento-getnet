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


class Denied extends \Magento\Framework\App\Action\Action
{

    private $_quote;
    
    private $orderRepository;
    
    private $quoteManagement;
    
    private $eventManager;
    
    private $maskedQuoteIdToQuoteId;
    
    protected $cart;
    
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
        \Magento\Checkout\Model\Cart $cart,
        OrderSender $orderSender
    ) {
        $this->urlDecoder = $urlDecoder;
        $this->_request = $request;
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
        $this->scopeConfig = $scopeConfig;
        $this->order = $order;
        $this->cart = $cart;
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
       $body = $this->getRequest()->getContent();
       $this->logger->debug('-------------------Response Denied-------------------');
       $resultStatus= ' ';
       

    if($body == null){  //Response Get
                    $enc = $this->getRequest()->getParam('enc');
                    
                    //quitamos el Encode de la url
                      $enc = urldecode($enc);
                      
                      //lo parseamos de base64 a String
                      $email = $this->urlDecoder->decode($enc);
            
        } else {  //Response POST
                   $this->logger->debug('body -- > '.$body);

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
                      $jsonNew = urldecode($message64);
                      
                      //lo parseamos de base64 a String
                      $jsonClean = $this->urlDecoder->decode( str_replace('response-base64=','',$jsonNew));
                
//                      $this->logger->debug($jsonClean);
            
                      //se convirtio a un array de Json
                      $jsondata=json_decode($jsonClean , true);
                      
                      $this->logger->debug('-------------Request ID-------------------');
                      $this->logger->debug($jsondata["payment"]["request-id"]);
                      
                      
                      $requestID= $jsondata["payment"]["request-id"];
                      $trxId= $jsondata["payment"]["transaction-id"];
                      $email = $jsondata["payment"]["consumer"]["email"];
                      $requestID = str_replace('-get-url','',$requestID);
                      $resultStatus= $jsondata["payment"]["statuses"];
                      
                      $resultStatus = 'requestID: ' .$requestID .' ,' .'transactionID: ' .$trxId .' ,' .json_encode($resultStatus);
                      
            } catch (\Exception $e) {
                    $this->logger->debug($e->getMessage());
            }
            
        }


    try {
              $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
              $orderDatamodel = $objectManager->get('Magento\Sales\Model\Order')->getCollection();
              $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();
               
              $order = $objectManager->create('\Magento\Sales\Model\Order')->load($orderDatamodel->getId());
        
              $quoteId = $order->getId();
              $this->logger->debug($quoteId);
              $this->logger->debug($order->getGrandTotal());
       
              $quote = $this->quoteRepository->get($quoteId);
              $order = $this->orderRepository->get($quoteId);

              $order->save();
            
        } catch (\Exception $e) {
              $this->logger->debug($e);
        } 
        
        
        
        try {
                $order->addStatusToHistory('pending_payment', $resultStatus, false);
        } catch (\Exception $e) {
              $this->logger->debug('Error in code Description Error');
        } 
        
        try {
                $quote = $objectManager->create('Magento\Quote\Model\QuoteFactory')->create()->load($order->getQuoteId());

                 $this->checkoutSession
                      ->setLastQuoteId($quote->getId())
                      ->setLastSuccessQuoteId($quote->getId())
                      ->clearHelperData();

                $quote->setReservedOrderId(null);
                $quote->setIsActive(true);
                $quote->removePayment();
                $quote->save();
                

                $this->checkoutSession->replaceQuote($quote);
                //OR add quote to cart
                $this->cart->setQuote($quote);  
                //if your last order is still in the session (getLastRealOrder() returns order data) you can achieve what you need with this one line without loading the order:
                $this->checkoutSession->restoreQuote();
                
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
               $this->logger->debug('Error restore quote');
        }

        $order->addStatusToHistory('canceled',__('Payment Denied'), false);
        $order->save();

/*                
        //Order::STATE_PAYMENT_REVIEW
        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED);
        $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
        $this->orderRepository->save($order);
*/        
        
        $this->messageManager->addErrorMessage(__('Payment Denied'));
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('checkout/cart');
        
                $this->logger->debug('Termino rechazo');

   return $resultRedirect;
  }
  

}