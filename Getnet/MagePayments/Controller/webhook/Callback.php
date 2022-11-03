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
use Magento\Framework\Locale\Resolver;


/**
 * Webhook Receiver Controller for Paystand
 */
class Callback extends \Magento\Framework\App\Action\Action
{

    const REDIRECT_URL = 'redirect-url';
    
    const USER_ID = 'payment/paymagento/user_id';
    
    const PASS_KEY = 'payment/paymagento/pasw_key';
    
    const MERCHANT_ACCOUNT_ID = 'payment/paymagento/merchant_account';
    
    const TEST_OPTION = 'payment/paymagento/test_payments';
    
    const CREDITOR_ID = 'payment/paymagento/creditor_id';
    
    protected $scopeConfig;
    
    protected $resultFactory;
    
    private $remoteAddress;
    
    private $_quote;
    
    private $modelCart;
    
    private $orderRepository;
    
    private $quoteManagement;
    
    private $eventManager;
    
    private $maskedQuoteIdToQuoteId;
    
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
    
    protected $timezone;
    
    private $localeResolver;
    
    
     
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Controller\ResultFactory $resultFactory,
        \Magento\Framework\Serialize\Serializer\Json $json,
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
        \Magento\Framework\Url\EncoderInterface $encoder,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezone,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        QuoteFactory $quoteFactory,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        ScopeConfig $scopeConfig,
        \Magento\Sales\Model\Order $order,
        OrderSender $orderSender,
        Resolver $localeResolver,
        \Magento\Checkout\Model\Cart $modelCart
    ) {
        $this->_request = $request;
        $this->_curl = $curl;
        $this->timezone = $timezone;
        $this->_jsonResultFactory = $jsonResultFactory;
        $this->_objectManager = $objectManager;
        $this->quoteManagement = $quoteManagement;
        $this->quoteRepository = $quoteRepository;
        $this->orderRepository = $orderRepository;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
        $this->encoder = $encoder;
        $this->remoteAddress = $remoteAddress;
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
        $this->localeResolver = $localeResolver;

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
    
        $email = $this->getRequest()->getParam('email');
        $cartId = $this->getRequest()->getParam('cartId');
        $dominio = $this->getRequest()->getParam('dominio');
                
        $this->logger->debug('Callback with email ');
        $this->logger->debug($cartId);
        $this->logger->debug('----------------------');
        
        try {
              $objectManager =  \Magento\Framework\App\ObjectManager::getInstance();
              
              $orderDatamodel = $objectManager->get('Magento\Sales\Model\Order')->getCollection();
              $orderDatamodel = $orderDatamodel->addFieldToFilter('customer_email', ['eq' => $email])->getLastItem();
               
              $order = $objectManager->create('\Magento\Sales\Model\Order')->load($orderDatamodel->getId());
        
              $quoteId = $order->getId();
              $this->logger->debug($order->getGrandTotal());
       
              $quote = $this->quoteRepository->get($quoteId);
              $order = $this->orderRepository->get($quoteId);
              
              $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
              $magentoVersion = $productMetadata->getVersion();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
              $this->logger->debug($e);
              try { 
                $quote = $this->quoteRepository->get($refID);
                $order = $this->orderRepository->get($quoteId);
              } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $this->logger->debug('No se pudo leer la ultima orden se probara con checkoutSession');
                $quote = $this->checkoutSession->getQuote();
                $order = $this->orderRepository->get($quoteId);
              }
        }



      ////////////////////////////////////////////////////////////
      //////////------- GET CURRENCY AND AMOUNT -----////////////
      $currency = $order->getOrderCurrencyCode();
      $amount = $order->getGrandTotal();

      $referencia = time().'C' .$cartId;
      $referencia = str_replace('_','00',$referencia);
      
      if(strlen($referencia) > 27){
          $referencia = substr($referencia, 0, 27);
      }
      
      
     $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE; 
     $username =    $this->scopeConfig->getValue(self::USER_ID, $storeScope);
     $password =    $this->scopeConfig->getValue(self::PASS_KEY, $storeScope);
     $merchantID =  $this->scopeConfig->getValue(self::MERCHANT_ACCOUNT_ID, $storeScope);
     $test       =  $this->scopeConfig->getValue(self::TEST_OPTION, $storeScope);
     $creditorID =  $this->scopeConfig->getValue(self::CREDITOR_ID, $storeScope);
     
     
     ////////////////////////////////////////////////////////
     ///////////////// MODE TEST = 1 ////////////////////////
     if($test == '1'){
               $url = 'https://paymentpage-test.getneteurope.com/api/payment/register';
               
     } else { //produccion
               $url = 'https://paymentpage.getneteurope.com/api/payment/register';
     }

     $this->logger->debug('Init send');
     
     $orderData = $this->orderRepository->get($quoteId);
     $shippingAmount = (float)$orderData->getShippingAmount();
     $this->logger->debug($shippingAmount);
     

      ////////////////////////////////////
     //------- send request Getnet -----//
      $response = $this->sendGetnet($merchantID, $referencia, $amount, $currency, $dominio, $username, $password, $url, $creditorID, $cartId, $order, $quoteId, $shippingAmount, $magentoVersion);
      

      try {
              $this->logger->debug('-----------------------------------------');
 //             $this->logger->debug($response);
           
              $responseJson=json_decode($response , true);
              $urlFinal=$responseJson['payment-redirect-url'];

              $this->logger->debug('-----------------End------------------------');
            
              $this->getResponse()->setContent($urlFinal);
              
        } catch (\Exception $e) {
             $this->logger->debug('Error generate HPP');
             $this->getResponse()->setContent("-");
        }


  }
  
  
  
  /**
   *
   * Api Register
   * 
   */ 
      protected function sendGetnet($merchantID, $referencia, $amount, $currency, $dominio, $username, $password, $url, $creditorID, $cartId, $order, $quoteId, $shippingAmount, $magentoVersion) {


    try{
        $email = $order->getCustomerEmail();
        
        $firstname = $order->getCustomerFirstname();
        $middlename = $order->getCustomerMiddlename();
        $lastname = $order->getCustomerLastname();
        $clientID = $order->getCustomerId();
        $idM = $this->encoder->encode($email);

        $ip = $this->remoteAddress->getRemoteAddress();
        
         $shippingAddress = $order->getShippingAddress();
                $city_ship = $shippingAddress->getCity();
                $state_ship = $shippingAddress->getRegion();
                $telefono_ship = $order->getShippingAddress()->getTelephone();
                $street_ship = str_replace( PHP_EOL, ',' , $shippingAddress->getData('street'));
                $country_ship = $order->getShippingAddress()->getCountryId();
                $region_ship = $order->getShippingAddress()->getRegionCode ();
                $shippingAmmount = $order->getShippingAddress()->getShippingAmount();
                $postCode_ship = $shippingAddress->getData('postcode');
                $shippingAmmountCode = $shippingAddress->getData('shippingamount');
                $this->logger->debug('-----ShippingAddres-----');
                $this->logger->debug('ShippingAmmount -->'.$shippingAmmount);

                
        $billingAddress = $order->getBillingAddress($quoteId);
                $street_bil = str_replace( PHP_EOL, ' ,' , $billingAddress->getData('street'));
                $city_bil = $billingAddress->getCity();
                $state_bil = $billingAddress->getRegion();
                $telefono_bil = $order->getBillingAddress()->getTelephone();
                $country_bil = $order->getBillingAddress()->getCountryId();
                $region_bil = $order->getBillingAddress()->getRegionCode ();
                $postCode_bil = $shippingAddress->getData('postcode');
        
       } catch (\Exception $ee) {
            $this->logger->debug($ee);
      }


        //Split
       $arrayBody = explode("_", $cartId);
       $refID  = $arrayBody[0];
       

       $currentLocaleCode = $this->localeResolver->getLocale(); 
       $languageCode = strstr($currentLocaleCode, '_', true);
       
       $items = $order->getAllVisibleItems();

       $this->logger->debug('----------basket-----------');
       $this->logger->debug($languageCode);

       
         
       $carritoBody = '';     
            foreach ($items as $item) {
                $totalAmount = $item->getBasePriceInclTax();
                $taxAmount = $item->getBaseTaxAmount();
                $quantity = str_replace(".0000", "", $item->getQtyOrdered());
                $tax_rate = $this->calculateTax($taxAmount, $totalAmount);

                
            $carritoBody = $carritoBody.'{
    			"amount": {
    				"currency": "'.$currency.'",
    				"value": '.$item->getPrice().'
    			},
    			"article-number": "'.$item->getProductId().'",
    			"description": "'.$item->getDescription().'",
    			"name": "'.$item->getName().'",
    			"quantity": '.$quantity.',
    			"tax-rate": '.$tax_rate.'
    		},';
        }
        
        
        
      //Add ShippingCost 
      if($shippingAmount > 0){
            $carritoBody = $carritoBody.'
            {
    			"amount": {
    				"currency": "'.$currency.'",
    				"value": '.$shippingAmount.'
    			},
    			"article-number": "0",
    			 "description": "",
    			"name": "ShippingCost",
    			"quantity": 1
    		},';
       }

        
    $carritoBody = substr($carritoBody, 0, -1);

    //P24 fails with items    
    if($currency == 'PLN'){
        $cartItems = '';
        
    } else {
        $cartItems = '"order-items": {
            "order-item": ['.$carritoBody.'
            ] 
        },';
    }



    $orderNumber= '"order-number": "'.$referencia.'",';
    $descriptor= '"descriptor": "'.$referencia.'",';
    $creditorID = '"creditor-id": "'.$creditorID.'",';
    $mandate_id='"mandate": {
            "mandate-id": "'.$refID.'"
        },';

$params = '{
  "payment": {
        "merchant-account-resolver-category": "'.$merchantID.'",
        "request-id": "'.$referencia.'",
        "transaction-type": "auto-sale",
        "requested-amount": {
            "value": '.$amount.',
            "currency": "'.$currency.'"
        },
    	'.$descriptor.'
    	"locale": "'.$languageCode.'",
        '.$mandate_id.'
        '.$cartItems.'
		"three-d": {
            "attempt-three-d": "true",
            "version": "2.2"
        },
        "consumer": {
            "email":"'.$email.'"
        },
        	"account-holder": {
            "merchant-crm-id": "'.$clientID.'",
            "first-name": "'.$firstname.'",
            "last-name": "'.$lastname.'",
            "phone": "+'.$telefono_bil.'",
            "email": "'.$email.'",
            "address": {
                "street1": "'.$street_bil.'",
                "city": "'.$city_bil.'",
                "postal-code": "'.$postCode_bil.'",
                "country": "'.$country_bil.'"
            }
        },
        "shipping": {
            "shipping-method": "01",
            "address": {
                "street1": "'.$street_ship.'",
                "city": "'.$city_ship.'",
                "postal-code": "'.$postCode_ship.'",
                "country": "'.$country_ship.'"
            },
            "email": "'.$email.'"
        },
         "shop":{
         "system-name":"Magento",
         "system-version":"'.$magentoVersion.'",
         "plugin-name":"Magento_getnet_plugin",
         "plugin-version":"1.0.0",
         "integration-type":"redirect"
        },
        '.$creditorID.'
        '.$orderNumber.'
        "ip-address": "'.$ip.'",
        "success-redirect-url": "'.$dominio.'/Response/Response",
        "fail-redirect-url": "'.$dominio.'/Response/Fail?enc='.$idM.'",
        "cancel-redirect-url": "'.$dominio.'/Response/Denied?enc='.$idM.'"
    }
}';


/*
        ///////////////////////  for test ///////////////////////////
            $order->addStatusToHistory('pending_payment', 'Initial Request --> ' .$params, false);
            $order->save();
            
            $this->logger->debug($params);
        /////////////////////////////////////////////////////
*/



          try {
            $this->_curl->setCredentials($username, $password);
            $this->_curl->addHeader("Content-Type", "application/json");
            $this->_curl->post($url, $params);
            $response = $this->_curl->getBody();
              
        } catch (\Magento\Framework\Exception\Exception $e) {
             $this->logger->debug('Error generate HPP');
        }
        


        return $response;
    }





/**
 * Add items
 * 
*/
    private function addOrderItemsToBasket(OrderDto $orderDto)
    {
        $items = $orderDto->quote->getAllVisibleItems();
        $currency = $orderDto->quote->getBaseCurrencyCode();
        
        foreach ($items as $orderItem) {
            $totalAmount = $orderItem->getBasePriceInclTax();
            $taxAmount = $orderItem->getBaseTaxAmount();
            $item = new Item(
                $orderItem->getName(),
                new Amount((float)$totalAmount, $currency),
                $orderItem->getQty()
            );
            $item->setTaxAmount(new Amount((float)$taxAmount, $currency));
            $item->setTaxRate($this->calculateTax($taxAmount, $totalAmount));
        }
        
        return $itemsJson;    
    }
    
    
    
    
    /**
     * 
     */
    public function divide($dividend, $divisor)
    {
        if (empty((float)$divisor)) {
            return (float)0;
        }

        return (float)($dividend / $divisor);
    }
    
    
   /**
     * 
     */ 
    public function calculateTax($taxAmount, $grossAmount, $decimals = 2)
    {
        return number_format(
            $this->divide($taxAmount, $grossAmount) * 100,
            $decimals
        );
    }
}
