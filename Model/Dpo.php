<?php

/*
 * Copyright (c) 2024 DPO Group
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
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedExceptionFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use SimpleXMLElement;
use Dpo\Dpo\Block\Form;
use Dpo\Dpo\Block\Payment\Info;

class Dpo extends AbstractMethod
{
    private const SECURE = ['_secure' => true];
    /**
     * @var TransactionDataFactory
     */
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
    protected $_formBlockType = Form::class;
    /**
     * @var string
     */
    protected $_infoBlockType = Info::class;
    /**
     * @var string
     */
    protected $configType = Config::class;
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
    protected $config;
    /**
     * Payment additional information key for payment action
     *
     * @var string
     */
    protected $isOrderPaymentActionKey = 'is_order_action';
    /**
     * Payment additional information key for number of used authorizations
     *
     * @var string
     */
    protected $authorizationCountKey = 'authorization_count';
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;
    /**
     * @var FormKey|UrlInterface
     */
    protected $formKey;
    /**
     * @var Session
     */
    protected $checkoutSession;
    /**
     * @var LocalizedExceptionFactory
     */
    protected $exception;
    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;
    /**
     * @var Transaction\BuilderInterface
     */
    protected BuilderInterface $transactionBuilder;
    /**
     * @var Curl
     */
    private Curl $curl;

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
     * @param Curl $curl
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
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
        Curl $curl,
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
        $this->storeManager          = $storeManager;
        $this->urlBuilder            = $urlBuilder;
        $this->formKey               = $formKey;
        $this->checkoutSession       = $checkoutSession;
        $this->exception             = $exception;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder    = $transactionBuilder;
        $this->_scopeConfig          = $scopeConfig;
        $parameters                  = ['params' => [$this->_code]];

        $this->config      = $configFactory->create($parameters);
        $this->dataFactory = $dataFactory;
        $this->curl        = $curl;
    }

    /**
     * Store setter and updates store ID in config object
     *
     * @param int $storeId
     *
     * @return $this
     */
    public function setStore($storeId): static
    {
        $store = null;
        if ($storeId === null) {
            $store = $this->storeManager->getDefaultStoreView();
        }

        $storeId = ($store && is_object($store)) ? $store->getId() : $storeId;

        $this->setData('store', (int)$storeId);

        $this->config->setStoreId((int)$storeId);

        return $this;
    }

    /**
     * Whether method is available for specified currency
     *
     * @param string $currencyCode
     *
     * @return bool
     */
    public function canUseForCurrency($currencyCode): bool
    {
        return $this->config->isCurrencyCodeSupported($currencyCode);
    }

    /**
     * Payment action getter compatible with payment model
     *
     * @return string
     * @see \Magento\Sales\Model\Payment::place()
     */
    public function getConfigPaymentAction(): string
    {
        return $this->config->getPaymentAction();
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
        return parent::isAvailable($quote) && $this->config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form to Dpo
     */
    public function getStandardCheckoutFormFields(): bool|array
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $order    = $this->checkoutSession->getLastRealOrder();
        $testMode = $this->_scopeConfig->getValue('payment/dpo/test_mode');
        $this->_logger->debug($pre . 'serverMode : ' . $testMode);

        $dpo          = new Dpopay($this->_logger, $this->curl, $testMode);
        $companyToken = $this->getCompanyToken($testMode);
        $serviceType  = $this->getServiceType($testMode);
        $payUrl       = $dpo->getDpoGateway();

        $billing = $order->getBillingAddress();
        $data    = $this->preparePaymentData($order, $billing, $companyToken, $serviceType);

        $tokens = $dpo->createToken($data);

        return $this->processTokenResponse(
            $tokens,
            $data,
            $dpo,
            $payUrl,
            $companyToken,
            $order->getRealOrderId(),
            $testMode
        );
    }

    /**
     * Prepare the payment data
     *
     * @param object $order
     * @param object $billing
     * @param string $companyToken
     * @param string $serviceType
     *
     * @return array
     */
    private function preparePaymentData($order, $billing, $companyToken, $serviceType): array
    {
        $paymentCurrency = $order->getOrderCurrencyCode();
        if ($paymentCurrency == 'ZWD') {
            $paymentCurrency = 'ZWL';
        }

        return [
            'companyToken'       => $companyToken,
            'accountType'        => $serviceType,
            'paymentAmount'      => $order->getGrandTotal(),
            'paymentCurrency'    => $paymentCurrency,
            'customerFirstName'  => $billing->getFirstname(),
            'customerLastName'   => $billing->getLastname(),
            'customerAddress'    => $billing->getStreet()[0],
            'customerCity'       => $billing->getCity(),
            'customerPhone'      => $billing->getTelephone(),
            'customerEmail'      => $billing->getEmail(),
            'redirectURL'        => $this->getPaidSuccessUrl(),
            'backUrl'            => $this->getPaidSuccessUrl(),
            'companyRef'         => $order->getRealOrderId(),
            'payment_country'    => $billing->getCountryId(),
            'payment_country_id' => $billing->getCountryId(),
            'payment_postcode'   => $billing->getPostcode()
        ];
    }

    /**
     * Process the token from the response
     *
     * @param $tokens
     * @param $data
     * @param $dpo
     * @param $payUrl
     * @param $companyToken
     * @param $companyRef
     * @param $testMode
     *
     * @return bool|array
     */
    private function processTokenResponse(
        $tokens,
        $data,
        $dpo,
        $payUrl,
        $companyToken,
        $companyRef,
        $testMode
    ): bool|array {
        if ($tokens['success'] === false) {
            return $tokens;
        } elseif ($tokens['success'] === true) {
            $transToken = $data['transToken'] = $tokens['transToken'];
            $verify     = $dpo->verifyToken($data);

            if (!empty($verify) && $verify != '') {
                try {
                    $verify = new SimpleXMLElement($verify);
                } catch (Exception $e) {
                    $this->_logger->debug($e->getMessage());
                }
                if ($verify->Result->__toString() === '900') {
                    return $this->prepareResponseData(
                        $payUrl,
                        $tokens['transToken'],
                        $testMode,
                        $companyToken,
                        $companyRef
                    );
                }
            }
        }

        return false;
    }

    /**
     * Prepares the response data
     *
     * @param string $payUrl
     * @param string $transToken
     * @param int $testMode
     * @param string $companyToken
     * @param string $companyRef
     *
     * @return array
     */
    private function prepareResponseData($payUrl, $transToken, $testMode, $companyToken, $companyRef): array
    {
        $data = [
            'payUrl' => $payUrl,
            'ID'     => $transToken
        ];

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
        } catch (Exception $e) {
            $this->_logger->error($e->getMessage());
        }

        return $data;
    }

    /**
     * Get the total amount from the order
     *
     * @param object $order
     *
     * @return string
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
     * Format the amount
     *
     * @param string $number
     *
     * @return string
     */
    public function getNumberFormat(string $number): string
    {
        return number_format($number, 2, '.', '');
    }

    /**
     * Returns the redirect url to the success page
     *
     * @return string
     */
    public function getPaidSuccessUrl(): string
    {
        return $this->urlBuilder->getUrl('dpo/redirect/success', self::SECURE);
    }

    /**
     * Called dynamically by checkout's framework + DpoConfigProvider
     *
     * @return string
     * @see Quote\Payment::getCheckoutRedirectUrl()
     * @see \Magento\Checkout\Controller\Onepage::savePaymentAction()
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return $this->urlBuilder->getUrl('dpo/redirect');
    }

    /**
     * Initialize the order state and status
     *
     * @param string $paymentAction
     * @param object $stateObject
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
     * Returns the notify url
     *
     * @return string
     */
    public function getPaidNotifyUrl(): string
    {
        return $this->urlBuilder->getUrl('dpo/notify', self::SECURE);
    }

    /**
     * Sends a curl request and gets a response
     *
     * @param string $url
     * @param array $fields
     *
     * @return bool|string
     */
    public function curlPost($url, $fields): bool|string
    {
        try {
            // Initialize Curl instance
            $this->curl->post($url, $fields);

            // Get the response
            return $this->curl->getBody();
        } catch (\Exception $e) {
            $this->_logger->error('Curl error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Searches for a match of the country code in the country data
     *
     * @param string $code2
     *
     * @return mixed|null
     */
    #[Pure] public function getCountryDetails($code2): mixed
    {
        $countryData = new CountryData();
        $countries   = $countryData->getCountries();

        foreach ($countries as $key => $val) {
            if ($key == $code2) {
                return $val[2];
            }
        }

        return null;
    }

    /**
     * Returns the store name
     *
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

    /**
     * Returns the company token
     *
     * @param bool $testMode
     *
     * @return string
     */
    private function getCompanyToken(bool $testMode): string
    {
        if ($testMode != 1) {
            $companyToken = $this->_scopeConfig->getValue('payment/dpo/company_token');
        } else {
            $companyToken = '9F416C11-127B-4DE2-AC7F-D5710E4C5E0A';
        }

        return $companyToken;
    }

    /**
     * Returns the service type
     *
     * @param bool $testMode
     *
     * @return string
     */
    private function getServiceType(bool $testMode): string
    {
        if ($testMode != 1) {
            $serviceType = $this->_scopeConfig->getValue('payment/dpo/service_type');
        } else {
            $serviceType = '3854';
        }

        return $serviceType;
    }
}
