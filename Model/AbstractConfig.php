<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Model\Method\ConfigInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Class AbstractConfig
 */
abstract class AbstractConfig implements ConfigInterface
{
    /**
     * Payment actions
     */
    public const PAYMENT_ACTION_SALE = 'Sale';

    public const PAYMENT_ACTION_AUTH = 'Authorization';

    public const PAYMENT_ACTION_ORDER = 'Order';
    /**
     * Core store config
     *
     * @var ScopeConfigInterface
     */
    public ScopeConfigInterface $scopeConfig;
    /**
     * Current payment method code
     *
     * @var string
     */
    protected string $methodCode;
    /**
     * Current store id
     *
     * @var int
     */
    protected int $storeId;
    /**
     * @var string
     */
    protected string $pathPattern;
    /**
     * @var MethodInterface
     */
    protected MethodInterface $methodInstance;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Sets method instance used for retrieving method specific data
     *
     * @param MethodInterface $method
     *
     * @return $this
     */
    public function setMethodInstance(MethodInterface $method): static
    {
        $this->methodInstance = $method;

        return $this;
    }

    /**
     * Method code setter
     *
     * @param string|MethodInterface $method
     *
     * @return $this
     */
    public function setMethod(MethodInterface|string $method): static
    {
        if ($method instanceof MethodInterface) {
            $this->methodCode = $method->getCode();
        } else {
            $this->methodCode = $method;
        }

        return $this;
    }

    /**
     * Payment method instance code getter
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        return $this->methodCode;
    }

    /**
     * Store ID setter
     *
     * @param int $storeId
     *
     * @return $this
     */
    public function setStoreId(int $storeId): static
    {
        $this->storeId = $storeId;

        return $this;
    }

    /**
     * Returns payment configuration value
     *
     * @param string $key
     * @param int $storeId
     *
     * @return null|string
     *
     */
    public function getValue($key, $storeId = null): string|null
    {
        $underscored = strtolower(preg_replace('/(.)([A-Z])/', "$1_$2", $key));
        $path        = $this->_getSpecificConfigPath($underscored);

        if ($path !== null) {
            $value = $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_STORE,
                $this->storeId
            );

            return $this->_prepareValue($underscored, $value);
        }

        return null;
    }

    /**
     * Sets method code
     *
     * @param string $methodCode
     *
     * @return void
     */
    public function setMethodCode($methodCode)
    {
        $this->methodCode = $methodCode;
    }

    /**
     * Sets path pattern
     *
     * @param string $pathPattern
     *
     * @return void
     */
    public function setPathPattern($pathPattern)
    {
        $this->pathPattern = $pathPattern;
    }

    /**
     * Check whether method available for checkout or not
     *
     * @param string $methodCode
     *
     * @return bool
     */
    public function isMethodAvailable($methodCode = null): bool
    {
        $methodCode = $methodCode ?: $this->methodCode;

        return $this->isMethodActive($methodCode);
    }

    /**
     * Check whether method active in configuration and supported for merchant country or not
     *
     * @param string $method Method code
     *
     * @return bool
     *
     */
    public function isMethodActive(string $method): bool
    {
        if ($method == Config::METHOD_CODE) {
            $isEnabled = $this->scopeConfig->isSetFlag(
                'payment/' . Config::METHOD_CODE . '/active',
                ScopeInterface::SCOPE_STORE,
                $this->storeId
            );
        } else {
            $isEnabled = $this->scopeConfig->isSetFlag(
                "payment/$method/active",
                ScopeInterface::SCOPE_STORE,
                $this->storeId
            );
        }

        return $this->isMethodSupportedForCountry($method) && $isEnabled;
    }

    /**
     * Check whether method supported for specified country or not
     *
     * @param string|null $method
     * @param string|null $countryCode
     *
     * @return bool
     *
     */
    public function isMethodSupportedForCountry(string $method = null, string $countryCode = null): bool
    {
        return true;
    }

    /**
     * Map any supported payment method into a config path by specified field name
     *
     * @param string $fieldName
     *
     * @return string|null
     */
    protected function _getSpecificConfigPath(string $fieldName): ?string
    {
        if ($this->pathPattern) {
            return sprintf($this->pathPattern, $this->methodCode, $fieldName);
        }

        return "payment/$this->methodCode/$fieldName";
    }

    /**
     * Perform additional config value preparation and return new value if needed
     *
     * @param string $key Underscored key
     * @param string $value Old value
     *
     * @return string Modified value or old value
     */
    protected function _prepareValue(string $key, string $value): string
    {
        return $value;
    }
}
