<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

use Dpo\Dpo\Model\TransactionDataRepository;
use Exception;
use GuzzleHttp\Client;
use JetBrains\PhpStorm\Pure;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedExceptionFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\UrlInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Dpo\Dpo\Block\Form;
use Dpo\Dpo\Block\Payment\Info;
use Dpo\Common\Dpo as DpoCommon;
use Magento\Payment\Model\MethodInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Framework\Event\ManagerInterface;
use GuzzleHttp\Exception\RequestException;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderAddressRepositoryInterface;

class Dpo implements MethodInterface
{
    private const SECURE = ['_secure' => true];
    /**
     * @var TransactionDataFactory
     */
    protected $dataFactory;
    /**
     * @var Magento\Framework\App\Config\ScopeConfigInterface|ScopeConfigInterface $scopeConfig
     */
    protected $scopeConfig;
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
    protected Session $checkoutSession;
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
     * @var Client
     */
    protected Client $client;
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var string
     */
    private $formBlockType = Form::class;
    /**
     * @var string
     */
    private $infoBlockType = Info::class;
    /**
     * @var ManagerInterface
     */
    private ManagerInterface $eventManager;
    private InfoInterface $infoInstance;
    private DirectoryHelper $directoryHelper;
    /**
     * @var TransactionDataRepository
     */
    private TransactionDataRepository $transactionDataRepository;
    /**
     * @var OrderAddressRepositoryInterface
     */
    private OrderAddressRepositoryInterface $orderAddressRepository;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ConfigFactory $configFactory,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        FormKey $formKey,
        Session $checkoutSession,
        LocalizedExceptionFactory $exception,
        TransactionRepositoryInterface $transactionRepository,
        BuilderInterface $transactionBuilder,
        TransactionDataFactory $dataFactory,
        LoggerInterface $logger,
        DirectoryHelper $directoryHelper,
        ManagerInterface $eventManager,
        TransactionDataRepository $transactionDataRepository,
        Client $client,
        OrderAddressRepositoryInterface $orderAddressRepository
    ) {
        $this->storeManager          = $storeManager;
        $this->urlBuilder            = $urlBuilder;
        $this->formKey               = $formKey;
        $this->checkoutSession       = $checkoutSession;
        $this->exception             = $exception;
        $this->transactionRepository = $transactionRepository;
        $this->transactionBuilder    = $transactionBuilder;
        $this->scopeConfig           = $scopeConfig;
        $parameters                  = ['params' => [$this->_code]];

        $this->config                    = $configFactory->create($parameters);
        $this->dataFactory               = $dataFactory;
        $this->client                    = $client;
        $this->logger                    = $logger;
        $this->directoryHelper           = $directoryHelper;
        $this->eventManager              = $eventManager;
        $this->transactionDataRepository = $transactionDataRepository;
        $this->orderAddressRepository    = $orderAddressRepository;
    }

    /**
     * Store setter; also updates store ID in config object
     *
     * @param Store|int $store
     *
     * @return $this
     * @throws NoSuchEntityException
     */
    public function setStore($store)
    {
        if (null === $store) {
            $store = $this->storeManager->getStore()->getId();
        }
        $this->config->setStoreId(is_object($store) ? $store->getId() : $store);

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
        return '';
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
        return $this->config->isMethodAvailable();
    }

    /**
     * This is where we compile data posted by the form to Dpo
     */
    public function getStandardCheckoutFormFields(): bool|array
    {
        $pre = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $order    = $this->checkoutSession->getLastRealOrder();
        $testMode = $this->scopeConfig->getValue('payment/dpo/test_mode');
        $this->logger->debug($pre . 'serverMode : ' . $testMode);

        $dpo       = new Dpopay($this->logger, $this->client, $testMode);
        $dpoCommon = new DpoCommon(true);

        $data                  = [];
        $companyToken          = $this->getCompanyToken($testMode);
        $serviceType           = $this->getServiceType($testMode);
        $payUrl                = $dpo->getDpoGateway();
        $data                  = $this->preparePaymentData($order, $companyToken, $serviceType);
        $data['serviceType']   = $serviceType;
        $data['companyAccRef'] = $order->getId();

        $tokens = $dpoCommon->createToken($data);

        return $this->processTokenResponse(
            $tokens,
            $data,
            $dpoCommon,
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
     * @param string $companyToken
     * @param string $serviceType
     *
     * @return array
     */
    private function preparePaymentData(object $order, string $companyToken, string $serviceType): array
    {
        // Try to get the billing address using the order address repository
        try {
            $billingAddress = $this->orderAddressRepository->get($order->getBillingAddressId());
        } catch (\Exception $e) {
            // Handle cases where the billing address is not found
            $billingAddress = null;
        }

        return $this->buildPaymentDataArray($order, $billingAddress, $companyToken, $serviceType);
    }

    private function buildPaymentDataArray($order, $billing, $companyToken, $serviceType): array
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
            'customerFirstName'  => $billing ? $billing->getFirstname() : '',
            'customerLastName'   => $billing ? $billing->getLastname() : '',
            'customerAddress'    => $billing ? $billing->getStreet()[0] : '',
            'customerCity'       => $billing ? $billing->getCity() : '',
            'customerPhone'      => $billing ? $billing->getTelephone() : '',
            'customerEmail'      => $billing ? $billing->getEmail() : '',
            'redirectURL'        => $this->getPaidSuccessUrl(),
            'backURL'            => $this->getPaidSuccessUrl(),
            'companyRef'         => $order->getRealOrderId(),
            'payment_country'    => $billing ? $billing->getCountryId() : '',
            'payment_country_id' => $billing ? $billing->getCountryId() : '',
            'payment_postcode'   => $billing ? $billing->getPostcode() : ''
        ];
    }

    /**
     * Process the token from the response
     *
     * @param $tokens
     * @param $data
     * @param DpoCommon $dpo
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
                    $this->logger->debug($e->getMessage());
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
            // First transaction record
            $transactionData = $this->dataFactory->create();
            $transactionData->addData([
                                          'recordtype' => 'dpotest',
                                          'recordid'   => $transToken,
                                          'recordval'  => $testMode
                                      ]);
            $this->transactionDataRepository->save($transactionData);

            // Second transaction record
            $transactionData = $this->dataFactory->create();
            $transactionData->addData([
                                          'recordtype' => 'dpoclient',
                                          'recordid'   => $transToken,
                                          'recordval'  => $companyToken
                                      ]);
            $this->transactionDataRepository->save($transactionData);

            // Third transaction record
            $transactionData = $this->dataFactory->create();
            $transactionData->addData([
                                          'recordtype' => 'dporef',
                                          'recordid'   => $transToken,
                                          'recordval'  => $companyRef
                                      ]);
            $this->transactionDataRepository->save($transactionData);
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
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

        return $this;
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
            $response = $this->client->post($url, $fields);

            // Get the response
            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            $this->logger->error('Request failed: ' . $e->getMessage());

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
        return $this->scopeConfig->getValue(
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
        $this->logger->debug($pre . 'bof');

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
            $companyToken = $this->scopeConfig->getValue('payment/dpo/company_token');
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
            $serviceType = $this->scopeConfig->getValue('payment/dpo/service_type');
        } else {
            $serviceType = '3854';
        }

        return $serviceType;
    }

    /**
     * Retrieve payment method code
     *
     * @return string
     *
     */
    public function getCode()
    {
        return Config::METHOD_CODE;
    }

    /**
     * Retrieve block type for method form generation
     *
     * @return string
     *
     * @deprecated 100.0.2
     */
    public function getFormBlockType()
    {
        return $this->formBlockType;
    }

    /**
     * Retrieve payment method title
     *
     * @return string
     *
     */
    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    /**
     * Store id getter
     * @return int
     */
    public function getStore()
    {
        return $this->config->getStoreId();
    }

    /**
     * Check order availability
     *
     * @return bool
     *
     */
    public function canOrder()
    {
        return true;
    }

    /**
     * Check authorize availability
     *
     * @return bool
     *
     */
    public function canAuthorize()
    {
        return true;
    }

    /**
     * Check capture availability
     *
     * @return bool
     *
     */
    public function canCapture()
    {
        return true;
    }

    /**
     * Check partial capture availability
     *
     * @return bool
     *
     */
    public function canCapturePartial()
    {
        return false;
    }

    /**
     * Check whether capture can be performed once and no further capture possible
     *
     * @return bool
     *
     */
    public function canCaptureOnce()
    {
        return false;
    }

    /**
     * Check refund availability
     *
     * @return bool
     *
     */
    public function canRefund()
    {
        return false;
    }

    /**
     * Check partial refund availability for invoice
     *
     * @return bool
     *
     */
    public function canRefundPartialPerInvoice()
    {
        return false;
    }

    /**
     * Check void availability
     * @return bool
     *
     */
    public function canVoid()
    {
        return false;
    }

    /**
     * Using internal pages for input payment data
     * Can be used in admin
     *
     * @return bool
     */
    public function canUseInternal()
    {
        return true;
    }

    /**
     * Can be used in regular checkout
     *
     * @return bool
     */
    public function canUseCheckout()
    {
        return true;
    }

    /**
     * Can be edit order (renew order)
     *
     * @return bool
     *
     */
    public function canEdit()
    {
        return true;
    }

    /**
     * Check fetch transaction info availability
     *
     * @return bool
     *
     */
    public function canFetchTransactionInfo()
    {
        return true;
    }

    /**
     * Fetch transaction info
     *
     * @param InfoInterface $payment
     * @param string $transactionId
     *
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        return [];
    }

    /**
     * Retrieve payment system relation flag
     *
     * @return bool
     *
     */
    public function isGateway()
    {
        return false;
    }

    /**
     * Retrieve payment method online/offline flag
     *
     * @return bool
     *
     */
    public function isOffline()
    {
        return false;
    }

    /**
     * Flag if we need to run payment initialize while order place
     *
     * @return bool
     *
     */
    public function isInitializeNeeded()
    {
        return true;
    }

    /**
     * To check billing country is allowed for the payment method
     *
     * @param string $country
     *
     * @return bool
     */
    public function canUseForCountry($country)
    {
        /*
        for specific country, the flag will set up as 1
        */
        if ($this->getConfigData('allowspecific') === 1) {
            $availableCountries = explode(',', $this->getConfigData('specificcountry') ?? '');
            if (!in_array($country, $availableCountries)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve block type for display method information
     *
     * @return string
     *
     * @deprecated 100.0.2
     */
    public function getInfoBlockType()
    {
        return $this->infoBlockType;
    }

    /**
     * Retrieve payment information model object
     *
     * @return InfoInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @deprecated 100.0.2
     */
    public function getInfoInstance()
    {
        return $this->infoInstance;
    }

    /**
     * Retrieve payment information model object
     *
     * @param InfoInterface $info
     *
     * @return void
     *
     * @deprecated 100.0.2
     */
    public function setInfoInstance(InfoInterface $info)
    {
        $this->infoInstance = $info;
    }

    /**
     * Validate payment method information object
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     */
    public function validate()
    {
        /**
         * to validate payment method is allowed for billing country or not
         */
        $paymentInfo = $this->getPaymentInfo();

        if ($paymentInfo instanceof Payment) {
            $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
        }
        $billingCountry = $billingCountry ?: $this->directoryHelper->getDefaultCountry();

        if (!$this->canUseForCountry($billingCountry)) {
            throw new LocalizedException(
                __('You can\'t use the payment type you selected to make payments to the billing country.')
            );
        }

        return $this;
    }

    /**
     * Order payment abstract method
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return $this
     *
     */
    public function order(InfoInterface $payment, $amount)
    {
        if (!$this->canOrder()) {
            throw new LocalizedException(__('The order action is not available.'));
        }

        return $this;
    }

    /**
     * Authorize payment abstract method
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return $this
     *
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        if (!$this->canAuthorize()) {
            throw new LocalizedException(__('The authorize action is not available.'));
        }

        return $this;
    }

    /**
     * Capture payment abstract method
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return $this
     *
     */
    public function capture(InfoInterface $payment, $amount)
    {
        if (!$this->canCapture()) {
            throw new LocalizedException(__('The capture action is not available.'));
        }

        return $this;
    }

    /**
     * Refund specified amount for payment
     *
     * @param InfoInterface $payment
     * @param float $amount
     *
     * @return $this
     *
     */
    public function refund(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Cancel payment abstract method
     *
     * @param InfoInterface $payment
     *
     * @return $this
     *
     */
    public function cancel(InfoInterface $payment)
    {
        return $this;
    }

    /**
     * Void payment abstract method
     *
     * @param InfoInterface $payment
     *
     * @return $this
     *
     */
    public function void(InfoInterface $payment)
    {
        if (!$this->canVoid()) {
            throw new LocalizedException(__('The void action is not available.'));
        }

        return $this;
    }

    /**
     * Whether this method can accept or deny payment
     * @return bool
     *
     */
    public function canReviewPayment()
    {
        return true;
    }

    /**
     * Attempt to accept a payment that us under review
     *
     * @param InfoInterface $payment
     *
     * @return false
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     */
    public function acceptPayment(InfoInterface $payment)
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * Attempt to deny a payment that us under review
     *
     * @param InfoInterface $payment
     *
     * @return false
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     */
    public function denyPayment(InfoInterface $payment)
    {
        if (!$this->canReviewPayment()) {
            throw new LocalizedException(__('The payment review action is unavailable.'));
        }

        return false;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|\Magento\Store\Model\Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        if (null === $storeId) {
            $storeId = $this->getStore();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;

        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Assign data to info model instance
     *
     * @param DataObject $data
     *
     * @return $this
     *
     */
    public function assignData(DataObject $data)
    {
        $this->eventManager->dispatch(
            'payment_method_assign_data_' . $this->getCode(),
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getPaymentInfo(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        $this->eventManager->dispatch(
            'payment_method_assign_data',
            [
                AbstractDataAssignObserver::METHOD_CODE => $this,
                AbstractDataAssignObserver::MODEL_CODE  => $this->getPaymentInfo(),
                AbstractDataAssignObserver::DATA_CODE   => $data
            ]
        );

        return $this;
    }

    /**
     * Is active
     *
     * @param int|null $storeId
     *
     * @return bool
     *
     */
    public function isActive($storeId = null)
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    /**
     * Get the current payment information dynamically
     *
     * @return OrderPayment|QuotePayment
     * @throws LocalizedException
     */
    protected function getPaymentInfo()
    {
        // This assumes you're in a context where you can access the current order or quote
        $quote = $this->checkoutSession->getQuote();
        $order = $this->checkoutSession->getLastRealOrder();

        if ($order && $order->getPayment()) {
            return $order->getPayment(); // Return OrderPayment
        } elseif ($quote && $quote->getPayment()) {
            return $quote->getPayment(); // Return QuotePayment
        }

        throw new LocalizedException(__('No payment information available.'));
    }
}
