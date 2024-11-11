<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Model;

use JetBrains\PhpStorm\ArrayShape;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Psr\Log\LoggerInterface;

class DpoConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ResolverInterface
     */
    protected ResolverInterface $localeResolver;
    /**
     * @var Config
     */
    protected Config $config;
    /**
     * @var CurrentCustomer
     */
    protected CurrentCustomer $currentCustomer;
    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;
    /**
     * @var string[]
     */
    protected array $methodCodes = [
        Config::METHOD_CODE,
    ];
    /**
     * @var MethodInterface[]
     */
    protected array $methods = [];
    /**
     * @var PaymentHelper
     */
    protected PaymentHelper $paymentHelper;
    /**
     * Cache for shouldAskToCreateBillingAgreement()
     *
     * @var bool
     */
    protected static bool $shouldAskToCreateBillingAgreement = false;

    /**
     * @param LoggerInterface $logger
     * @param ConfigFactory $configFactory
     * @param ResolverInterface $localeResolver
     * @param CurrentCustomer $currentCustomer
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(
        LoggerInterface $logger,
        ConfigFactory $configFactory,
        ResolverInterface $localeResolver,
        CurrentCustomer $currentCustomer,
        PaymentHelper $paymentHelper
    ) {
        $this->logger = $logger;
        $pre          = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $this->localeResolver  = $localeResolver;
        $this->config          = $configFactory->create();
        $this->currentCustomer = $currentCustomer;
        $this->paymentHelper   = $paymentHelper;

        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $this->paymentHelper->getMethodInstance($code);
        }

        $this->logger->debug($pre . 'eof and this  methods has : ', $this->methods);
    }

    /**
     * Get the config settings
     *
     * @return array|array[]
     */
    #[ArrayShape(['payment' => "array[]"])] public function getConfig(): array
    {
        $pre = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');
        $inlineConfig = [
            'payment' => [
                'dpo' => [
                    'paymentAcceptanceMarkSrc'  => $this->config->getPaymentMarkImageUrl(),
                    'paymentAcceptanceMarkHref' => $this->config->getPaymentMarkWhatIsDpo(),
                ],
            ],
        ];

        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                $inlineConfig['payment']['dpo']['redirectUrl'][$code]          = $this->getMethodRedirectUrl($code);
                $inlineConfig['payment']['dpo']['billingAgreementCode'][$code] = $this->getBillingAgreementCode($code);
            }
        }
        $this->logger->debug($pre . 'eof', $inlineConfig);

        return $inlineConfig;
    }

    /**
     * Return redirect URL for method
     *
     * @param string $code
     *
     * @return mixed
     */
    protected function getMethodRedirectUrl(string $code): mixed
    {
        $pre = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $methodUrl = $this->methods[$code]->getOrderPlaceRedirectUrl();

        $this->logger->debug($pre . 'eof');

        return $methodUrl;
    }

    /**
     * Return billing agreement code for method
     *
     * @param string $code
     *
     * @return bool
     */
    protected function getBillingAgreementCode(string $code): bool
    {
        $pre = __METHOD__ . ' : ';
        $this->logger->debug($pre . 'bof');

        $this->config->setMethod($code);

        $this->logger->debug($pre . 'eof');

        // Always return null
        return $this->shouldAskToCreateBillingAgreement();
    }

    /**
     * Check whether customer should be asked confirmation whether to sign a billing agreement. Returns false.
     *
     * @return bool
     */
    public function shouldAskToCreateBillingAgreement(): bool
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . "bof");
        $this->logger->debug($pre . "eof");

        return self::$shouldAskToCreateBillingAgreement;
    }
}
