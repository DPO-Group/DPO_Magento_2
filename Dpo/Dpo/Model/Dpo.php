<?php
/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

use Magento\Framework\{Api\AttributeValueFactory,
    Api\ExtensionAttributesFactory,
    App\Config\ScopeConfigInterface,
    Data\Collection\AbstractDb,
    Data\Form\FormKey,
    Exception\LocalizedExceptionFactory,
    Model\Context,
    Model\ResourceModel\AbstractResource,
    Registry,
    UrlInterface};

use Magento\Sales\{Api\Data\OrderPaymentInterface,
    Api\TransactionRepositoryInterface,
    Model\Order\Payment,
    Model\Order\Payment\Transaction,
    Model\Order\Payment\Transaction\BuilderInterface};

use Magento\Payment\{Model\Method\Logger,
    Helper\Data};


use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote;
use Dpo\Dpo\Model\ConfigFactory;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Dpo extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $dataFactory;

    /**
     * @var Magento\Framework\App\Config\ScopeConfigInterface $_scopeConfig
     */
    protected $_scopeConfig;

    /**
     * @var string
     */
    protected $_code = Config::METHOD_CODE;

    /**
     * @var string
     */
    protected $_formBlockType = 'Dpo\Dpo\Block\Form';

    /**
     * @var string
     */
    protected $_infoBlockType = 'Dpo\Dpo\Block\Payment\Info';

    /**
     * @var string
     */
    protected $_configType = 'Dpo\Dpo\Model\Config';

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_isGateway = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canOrder = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canVoid = false;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canFetchTransactionInfo = true;

    /**
     * Availability option
     *
     * @var bool
     */
    protected $_canReviewPayment = true;

    /**
     * Website Payments Pro instance
     *
     * @var \Dpo\Dpo\Model\Config $config
     */
    protected $_config;

    /**
     * Payment additional information key for payment action
     *
     * @var string
     */
    protected $_isOrderPaymentActionKey = 'is_order_action';

    /**
     * Payment additional information key for number of used authorizations
     *
     * @var string
     */
    protected $_authorizationCountKey = 'authorization_count';

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_formKey;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Framework\Exception\LocalizedExceptionFactory
     */
    protected $_exception;

    /**
     * @var \Magento\Sales\Api\TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * @var Transaction\BuilderInterface
     */
    protected $transactionBuilder;

    private const SECURE = array( '_secure' => true );

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Dpo\Dpo\Model\ConfigFactory $configFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\Exception\LocalizedExceptionFactory $exception
     * @param \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
     * @param Transaction\BuilderInterface $transactionBuilder
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param TransactionDataFactory $dataFactory
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        Session $checkoutSession,
        LocalizedExceptionFactory $exception,
        TransactionRepositoryInterface $transactionRepository,
        BuilderInterface $transactionBuilder,
        TransactionDataFactory $dataFactory,
        AbstractResource $resource = null,
        AbstractDb $resourceCollection = null,
        array $data = [] ) {
        parent::__construct( $context, $registry, $extensionFactory, $customAttributeFactory, $paymentData, $scopeConfig, $logger, $resource, $resourceCollection, $data );
        $this->_storeManager         = $storeManager;
        $this->_urlBuilder           = $urlBuilder;
        $this->_formKey              = $formKey;
        $this->_checkoutSession      = $checkoutSession;
        $this->_exception            = $exception;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder    = $transactionBuilder;
        $this->_scopeConfig          = $scopeConfig;
        $parameters                  = ['params' => [$this->_code]];

        $this->_config     = $configFactory->create( $parameters );
        $this->dataFactory = $dataFactory;
    }

    /**
     * Store setter
     * Also updates store ID in config object
     *
     * @param \Magento\Store\Model\Store|int $store
     *
     * @return $this
     */
    public function setStore( $store )
    {
        $this->setData( 'store', $store );

        if ( null === $store ) {
            $store = $this->_storeManager->getStore()->getId();
        }
        $this->_config->setStoreId( is_object( $store ) ? $store->getId() : $store );

        return $this;
    }

    /**
     * Whether method is available for specified currency
     *
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency( $currencyCode )
    {
        return $this->_config->isCurrencyCodeSupported( $currencyCode );
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction()
    {
        return $this->_config->getPaymentAction();
    }

    /**
     * Check whether payment method can be used
     *
     * @param \Magento\Quote\Api\Data\CartInterface|Quote|null $quote
     *
     * @return bool
     */
    public function isAvailable( \Magento\Quote\Api\Data\CartInterface $quote = null )
    {
        return parent::isAvailable( $quote ) && $this->_config->isMethodAvailable();
    }

    /**
     * @return mixed
     */
    protected function getStoreName()
    {

        return $this->_scopeConfig->getValue(
            'general/store_information/name',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Place an order with authorization or capture action
     *
     * @param Payment $payment
     * @param float $amount
     *
     * @return $this
     */
    protected function _placeOrder( Payment $payment, $amount )
    {

        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );

    }

    /**
     * This is where we compile data posted by the form to Dpo
     * @return array
     */
    public function getStandardCheckoutFormFields()
    {
        $pre = __METHOD__ . ' : ';
        // Variable initialization

        $order = $this->_checkoutSession->getLastRealOrder();

        $testMode = $this->_scopeConfig->getValue( 'payment/dpo/test_mode' );

        $this->_logger->debug( $pre . 'serverMode : ' . $testMode );
        $dpo     = new Dpopay($this->_logger, $testMode);

        $companyToken = $this->getCompanyToken($testMode);
        $serviceType  = $this->getServiceType($testMode);
       
        $payUrl = $dpo->getDpoGateway();

        $billing       = $order->getBillingAddress();
        $country_code2 = $billing->getCountryId();

        $paymentCurrency = $order->getOrderCurrencyCode();

        switch ($paymentCurrency) {
            case 'ZWD':
                $paymentCurrency = 'ZWL';
                break;
            
            default:
                # Do nothing
                break;
        }

        $orderTotal                = $order->getGrandTotal();
        $data                      = [];
        $data['companyToken']      = $companyToken;
        $data['accountType']       = $serviceType;
        $data['paymentAmount']     = $orderTotal;
        $data['paymentCurrency']   = $paymentCurrency;
        $data['customerFirstName'] = $billing->getFirstname();
        $data['customerLastName']  = $billing->getLastname();
        $data['customerAddress']   = $billing->getStreet()[0];
        $data['customerCity']      = $billing->getCity();
        $data['customerPhone']     = $billing->getTelephone();
        $data['customerEmail']     = $billing->getEmail();
        $data['redirectURL']       = $this->getPaidSuccessUrl();
        $data['backUrl']           = $this->getPaidSuccessUrl();
        $companyRef                = $data['companyRef']                = $order->getRealOrderId();

        $data['payment_country']    = $country_code2;
        $data['payment_country_id'] = $country_code2;
        $data['payment_postcode'] = $billing->getPostcode();

        $tokens = $dpo->createToken( $data );
        if ( $tokens['success'] === true ) {
            $transToken = $data['transToken'] = $tokens['transToken'];
            $verify     = $dpo->verifyToken( $data );

            if ( !empty( $verify ) && $verify != '' ) {
                $verify = new \SimpleXMLElement( $verify );
                if ( $verify->Result->__toString() === '900' ) {
                    $data           = [];
                    $data['payUrl'] = $payUrl;
                    $data['ID']     = $tokens['transToken'];

                    try {
                        $this->dataFactory->create()->addData( ['recordtype' => 'dpotest', 'recordid' => $transToken, 'recordval' => $testMode] )->save();
                        $this->dataFactory->create()->addData( ['recordtype' => 'dpoclient', 'recordid' => $transToken, 'recordval' => $companyToken] )->save();
                        $this->dataFactory->create()->addData( ['recordtype' => 'dporef', 'recordid' => $transToken, 'recordval' => $companyRef] )->save();

                        return $data;
                    } catch ( \Exception $e ) {
                        echo $e->getMessage();
                    }
                }
            }
        }
    }

    /**
     * getTotalAmount
     */
    public function getTotalAmount( $order )
    {
        if ( $this->getConfigData( 'use_store_currency' ) ) {
            $price = $this->getNumberFormat( $order->getGrandTotal() );
        } else {
            $price = $this->getNumberFormat( $order->getBaseGrandTotal() );
        }

        return $price;
    }

    /**
     * getNumberFormat
     */
    public function getNumberFormat( $number )
    {
        return number_format( $number, 2, '.', '' );
    }

    /**
     * getPaidSuccessUrl
     */
    public function getPaidSuccessUrl()
    {
        return $this->_urlBuilder->getUrl( 'dpo/redirect/success', self::SECURE);
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     *
     * @return false|\Magento\Sales\Api\Data\TransactionInterface
     */
    protected function getOrderTransaction( $payment )
    {
        return $this->transactionRepository->getByTransactionType( Transaction::TYPE_ORDER, $payment->getId(), $payment->getOrder()->getId() );
    }

    /**
     * called dynamically by checkout's framework + DpoConfigProvider
     * @return string
     * @see Quote\Payment::getCheckoutRedirectUrl()
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     */
    public function getOrderPlaceRedirectUrl()
    {
        return $this->_urlBuilder->getUrl( 'dpo/redirect' );

    }

    /**
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     */
    public function initialize( $paymentAction, $stateObject )
    {
        $stateObject->setState( \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT );
        $stateObject->setStatus( 'pending_payment' );
        $stateObject->setIsNotified( false );

        return parent::initialize( $paymentAction, $stateObject );

    }

    /**
     * getPaidNotifyUrl
     */
    public function getPaidNotifyUrl()
    {
        return $this->_urlBuilder->getUrl( 'dpo/notify', self::SECURE );
    }

    public function curlPost( $url, $fields )
    {
        $curl = curl_init( $url );
        curl_setopt( $curl, CURLOPT_POST, count( $fields ) );
        curl_setopt( $curl, CURLOPT_POSTFIELDS, $fields );
        curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
        $response = curl_exec( $curl );
        curl_close( $curl );
        return $response;
    }

    public function getCountryDetails( $code2 )
    {
        $countries = CountryData::getCountries();

        foreach ( $countries as $key => $val ) {

            if ( $key == $code2 ) {
                return $val[2];
            }

        }
    }

    private function getCompanyToken($testMode): string
    {

        if ( $testMode != 1 ) {
            $companyToken = $this->_scopeConfig->getValue( 'payment/dpo/company_token' );

        } else {
            $companyToken = '9F416C11-127B-4DE2-AC7F-D5710E4C5E0A';
        }

        return $companyToken;
    }

    private function getServiceType($testMode): string
    {

        if ( $testMode != 1 ) {
            $serviceType  = $this->_scopeConfig->getValue( 'payment/dpo/service_type' );
        } else {
            $serviceType  = '3854';
        }

        return $serviceType;
    }

}
