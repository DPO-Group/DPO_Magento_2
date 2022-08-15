<?php
/** @noinspection PhpMissingFieldTypeInspection */

/** @noinspection PhpUnused */

/** @noinspection PhpUnusedParameterInspection */

/** @noinspection SpellCheckingInspection */

/** @noinspection PhpUndefinedFieldInspection */

/** @noinspection PhpUndefinedNamespaceInspection */

/** @noinspection PhpUndefinedMethodInspection */

/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\ConfigFactory;
use Exception;
use JetBrains\PhpStorm\Pure;
use Magento\Checkout\Model\Session;
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
use Magento\Payment\{Helper\Data, Model\Method\AbstractMethod, Model\Method\Logger};
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\{Api\Data\OrderPaymentInterface,
    Api\Data\TransactionInterface,
    Api\TransactionRepositoryInterface,
    Model\Order,
    Model\Order\Payment,
    Model\Order\Payment\Transaction,
    Model\Order\Payment\Transaction\BuilderInterface};
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use SimpleXMLElement;


/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Dpo extends AbstractMethod
{
    private const SECURE = array('_secure' => true);
    protected $dataFactory;
    /**
     * @var Magento\Framework\App\Config\ScopeConfigInterface|ScopeConfigInterface $_scopeConfig
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
     * @var Config $config
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
     * @var StoreManagerInterface
     */
    protected $_storeManager;
    /**
     * @var UrlInterface
     */
    protected $_urlBuilder;
    /**
     * @var FormKey|UrlInterface
     */
    protected $_formKey;
    /**
     * @var Session
     */
    protected $_checkoutSession;
    /**
     * @var LocalizedExceptionFactory
     */
    protected $_exception;
    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;
    /**
     * @var Transaction\BuilderInterface
     */
    protected BuilderInterface $transactionBuilder;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param ConfigFactory $configFactory
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param FormKey $formKey
     * @param Session $checkoutSession
     * @param LocalizedExceptionFactory $exception
     * @param TransactionRepositoryInterface $transactionRepository
     * @param Transaction\BuilderInterface $transactionBuilder
     * @param TransactionDataFactory $dataFactory
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
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
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_storeManager         = $storeManager;
        $this->_urlBuilder           = $urlBuilder;
        $this->_formKey              = $formKey;
        $this->_checkoutSession      = $checkoutSession;
        $this->_exception            = $exception;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder    = $transactionBuilder;
        $this->_scopeConfig          = $scopeConfig;
        $parameters                  = ['params' => [$this->_code]];

        $this->_config     = $configFactory->create($parameters);
        $this->dataFactory = $dataFactory;
    }

    /**
     * Store setter
     * Also updates store ID in config object
     *
     * @param $store
     *
     * @return $this
     */
    public function setStore($store): static
    {
        $this->setData('store', $store);

        $this->_config->setStoreId(is_object($store) ? $store->getId() : $store);

        return $this;
    }

    /**
     * Whether method is available for specified currency
     *
     * @param $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode): bool
    {
        return $this->_config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction(): string
    {
        return $this->_config->getPaymentAction();
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|null $quote
     *
     * @return bool
     */
    public function isAvailable(CartInterface $quote = null): bool
    {
        return parent::isAvailable($quote) && $this->_config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form to Dpo
     */
    public function getStandardCheckoutFormFields(): bool|array
    {
        $response = false;
        $pre      = __METHOD__ . ' : ';
        // Variable initialization

        $order = $this->_checkoutSession->getLastRealOrder();

        $testMode = $this->_scopeConfig->getValue('payment/dpo/test_mode');

        $this->_logger->debug($pre . 'serverMode : ' . $testMode);
        $dpo = new Dpopay($this->_logger, $testMode);

        $companyToken = $this->getCompanyToken($testMode);
        $serviceType  = $this->getServiceType($testMode);

        $payUrl = $dpo->getDpoGateway();

        $billing       = $order->getBillingAddress();
        $country_code2 = $billing->getCountryId();

        $paymentCurrency = $order->getOrderCurrencyCode();

        if ($paymentCurrency == 'ZWD') {
            $paymentCurrency = 'ZWL';
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
        $companyRef                = $data['companyRef'] = $order->getRealOrderId();

        $data['payment_country']    = $country_code2;
        $data['payment_country_id'] = $country_code2;
        $data['payment_postcode']   = $billing->getPostcode();

        $tokens = $dpo->createToken($data);
        if ($tokens['success'] === true) {
            $transToken = $data['transToken'] = $tokens['transToken'];
            $verify     = $dpo->verifyToken($data);

            if ( ! empty($verify) && $verify != '') {
                try {
                    $verify = new SimpleXMLElement($verify);
                } catch (Exception $e) {
                    $this->logger->debug($e->getMessage());
                }
                if ($verify->Result->__toString() === '900') {
                    $data           = [];
                    $data['payUrl'] = $payUrl;
                    $data['ID']     = $tokens['transToken'];

                    try {
                        $this->dataFactory->create()->addData(
                            ['recordtype' => 'dpotest', 'recordid' => $transToken, 'recordval' => $testMode]
                        )->save();
                        $this->dataFactory->create()->addData(
                            ['recordtype' => 'dpoclient', 'recordid' => $transToken, 'recordval' => $companyToken]
                        )->save();
                        $this->dataFactory->create()->addData(
                            ['recordtype' => 'dporef', 'recordid' => $transToken, 'recordval' => $companyRef]
                        )->save();

                        $response = $data;
                    } catch (Exception $e) {
                        echo $e->getMessage();
                    }
                }
            }
        }

        return $response;
    }

    /**
     * getTotalAmount
     * @noinspection PhpUndefinedMethodInspection
     */
    public function getTotalAmount($order): string
    {
        if ($this->getConfigData('use_store_currency')) {
            $price = $this->getNumberFormat($order->getGrandTotal());
        } else {
            $price = $this->getNumberFormat($order->getBaseGrandTotal());
        }

        return $price;
    }

    /**
     * getNumberFormat
     */
    public function getNumberFormat($number): string
    {
        return number_format($number, 2, '.', '');
    }

    /**
     * getPaidSuccessUrl
     */
    public function getPaidSuccessUrl()
    {
        return $this->_urlBuilder->getUrl('dpo/redirect/success', self::SECURE);
    }

    /**
     * called dynamically by checkout's framework + DpoConfigProvider
     * @return string
     * @see Quote\Payment::getCheckoutRedirectUrl()
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->_urlBuilder->getUrl('dpo/redirect');
    }

    /**
     *
     * @param $paymentAction
     * @param $stateObject
     *
     * @return $this
     */
    public function initialize($paymentAction, $stateObject): static
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return parent::initialize($paymentAction, $stateObject);
    }

    /**
     * getPaidNotifyUrl
     */
    public function getPaidNotifyUrl()
    {
        return $this->_urlBuilder->getUrl('dpo/notify', self::SECURE);
    }

    public function curlPost($url, $fields): bool|string
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, count($fields));
        curl_setopt($curl, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($curl);
        curl_close($curl);

        return $response;
    }

    #[Pure] public function getCountryDetails($code2)
    {
        $countries = CountryData::getCountries();

        foreach ($countries as $key => $val) {
            if ($key == $code2) {
                return $val[2];
            }
        }

        return null;
    }

    /**
     * @return mixed
     */
    protected function getStoreName(): mixed
    {
        return $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Place an order with authorization or capture action
     *
     * @param Payment $payment
     * @param float $amount
     *
     * @return null
     */
    protected function _placeOrder(Payment $payment, float $amount)
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

        return null;
    }

    /**
     * Get transaction with type order
     *
     * @param OrderPaymentInterface $payment
     *
     * @return false|TransactionInterface
     */
    protected function getOrderTransaction(OrderPaymentInterface $payment): bool|TransactionInterface
    {
        return $this->transactionRepository->getByTransactionType(
            Transaction::TYPE_ORDER,
            $payment->getId(),
            $payment->getOrder()->getId()
        );
    }

    private function getCompanyToken($testMode): string
    {
        if ($testMode != 1) {
            $companyToken = $this->_scopeConfig->getValue('payment/dpo/company_token');
        } else {
            $companyToken = '9F416C11-127B-4DE2-AC7F-D5710E4C5E0A';
        }

        return $companyToken;
    }

    private function getServiceType($testMode): string
    {
        if ($testMode != 1) {
            $serviceType = $this->_scopeConfig->getValue('payment/dpo/service_type');
        } else {
            $serviceType = '3854';
        }

        return $serviceType;
    }

}
