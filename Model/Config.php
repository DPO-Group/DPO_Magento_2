<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */


namespace Dpo\Dpo\Model;

use JetBrains\PhpStorm\Pure;
use Magento\Directory\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Config model that is aware of all \Dpo\Dpo payment methods
 * Works with Dpo-specific system configuration
 */
class Config extends AbstractConfig
{
    /**
     * @var Dpo this is a model which we will use.
     */
    public const METHOD_CODE = 'dpo';

    /**
     * Core
     * data @var Data
     */
    protected Data $directoryHelper;

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;

    protected array $supportedBuyerCountryCodes = ['ZA'];

    /**
     * Currency codes supported by Dpo methods
     * @var string[]
     */
    protected array $supportedCurrencyCodes = [
        'USD',
        'EUR',
        'GBP',
        'ZAR', // South Africa, Lesotho, Namibia
        'BWP', // Botswana
        'XOF', // Burkina Faso, Ivory Coast, Senegal, Togo
        'CDF', // DR Congo
        'SZL', // Eswatini
        'ETB', // Ethiopia
        'GHS', // Ghana
        'KES', // Kenya
        'LSL', // Lesotho
        'MWK', // Malawi
        'MUR', // Mauritius
        'NGN', // Nigeria
        'RWF', // Rwanda
        'TZS', // Tanzania, Zanzibar
        'AED', // UAE
        'UGX', // Uganda
        'ZMW', // Zambia
        'ZWL', // Zimbabwe
    ];

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $_logger;

    /**
     * @var UrlInterface
     */
    protected UrlInterface $urlBuilder;

    /**
     * @var Repository
     */
    protected Repository $assetRepo;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Data $directoryHelper
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param Repository $assetRepo
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Data $directoryHelper,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        Repository $assetRepo
    ) {
        $this->_logger = $logger;
        parent::__construct($scopeConfig);
        $this->directoryHelper = $directoryHelper;
        $this->storeManager    = $storeManager;
        $this->assetRepo       = $assetRepo;

        $this->setMethod('dpo');
        $currentStoreId = $this->storeManager->getStore()->getStoreId();
        $this->setStoreId($currentStoreId);
    }

    /**
     * Check whether method available for checkout or not
     * Logic based on merchant country, methods dependence
     *
     * @param string|null $methodCode
     *
     * @return bool
     */
    public function isMethodAvailable($methodCode = null): bool
    {
        return parent::isMethodAvailable($methodCode);
    }

    /**
     * Return buyer country codes supported by Dpo
     *
     * @return string[]
     */
    public function getSupportedBuyerCountryCodes(): array
    {
        return $this->supportedBuyerCountryCodes;
    }

    /**
     * Return merchant country code, use default country if it is not specified in the General settings
     *
     * @return string
     */
    public function getMerchantCountry(): string
    {
        return $this->directoryHelper->getDefaultCountry($this->storeId);
    }

    /**
     * Check whether method supported for specified country or not
     * Use $methodCode and merchant country by default
     *
     * @param string|null $method
     * @param string|null $countryCode
     *
     * @return bool
     */
    public function isMethodSupportedForCountry(string $method = null, string $countryCode = null): bool
    {
        if ($method === null) {
            $method = $this->getMethodCode();
        }

        if ($countryCode === null) {
            $countryCode = $this->getMerchantCountry();
        }

        return in_array($method, $this->getCountryMethods($countryCode));
    }

    /**
     * Return list of allowed methods for specified country iso code
     *
     * @param string|null $countryCode 2-letters iso code
     *
     * @return array
     */
    public function getCountryMethods(string $countryCode = null): array
    {
        $countryMethods = [
            'other' => [
                self::METHOD_CODE,
            ],

        ];
        if ($countryCode === null) {
            return $countryMethods;
        }

        return $countryMethods[$countryCode] ?? $countryMethods['other'];
    }

    /**
     * Get Dpo "mark" image URL
     *
     * @return string
     */
    public function getPaymentMarkImageUrl(): string
    {
        return $this->assetRepo->getUrl('Dpo_Dpo::images/logo.svg');
    }

    /**
     * Get "What Is Dpo" localized URL
     * Supposed to be used with "mark" as popup window
     *
     * @return string
     */
    public function getPaymentMarkWhatIsDpo(): string
    {
        return 'Dpo Payment gateway';
    }

    /**
     * Mapper from Dpo-specific payment actions to Magento payment actions
     *
     * @return string|null
     */
    public function getPaymentAction(): ?string
    {
        $paymentAction = null;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug($pre . 'bof');

        $action = $this->getValue('paymentAction');

        switch ($action) {
            case self::PAYMENT_ACTION_AUTH:
                $paymentAction = AbstractMethod::ACTION_AUTHORIZE;
                break;
            case self::PAYMENT_ACTION_SALE:
                $paymentAction = AbstractMethod::ACTION_AUTHORIZE_CAPTURE;
                break;
            case self::PAYMENT_ACTION_ORDER:
                $paymentAction = AbstractMethod::ACTION_ORDER;
                break;
            default:
                break;
        }

        $this->_logger->debug($pre . 'eof : paymentAction is ' . $paymentAction);

        return $paymentAction;
    }

    /**
     * Check whether specified currency code is supported
     *
     * @param string $code
     *
     * @return bool
     */
    public function isCurrencyCodeSupported(string $code): bool
    {
        $supported = false;
        $pre       = __METHOD__ . ' : ';

        $this->_logger->debug($pre . "bof and code: $code");

        if (in_array($code, $this->supportedCurrencyCodes)) {
            $supported = true;
        }

        $this->_logger->debug($pre . "eof and supported : $supported");

        return $supported;
    }

    /**
     * Check whether specified locale code is supported. Fallback to en_US
     *
     * @param string|null $localeCode
     *
     * @return string
     */
    protected function _getSupportedLocaleCode(string $localeCode = null): string
    {
        if (!$localeCode || !in_array($localeCode, $this->_supportedImageLocales)) {
            return 'en_US';
        }

        return $localeCode;
    }

    /**
     * _mapDpoFieldset
     * Map Dpo config fields
     *
     * @param string $fieldName
     *
     * @return string|null
     */
    protected function _mapDpoFieldset(string $fieldName): ?string
    {
        return "payment/$this->methodCode/$fieldName";
    }

    /**
     * Map any supported payment method into a config path by specified field name
     *
     * @param string $fieldName
     *
     * @return string|null
     */
    #[Pure] protected function _getSpecificConfigPath(string $fieldName): ?string
    {
        return $this->_mapDpoFieldset($fieldName);
    }
}
