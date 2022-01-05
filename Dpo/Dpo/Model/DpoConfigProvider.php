<?php
/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
namespace Dpo\Dpo\Model;

use Dpo\Dpo\Helper\Data as DpoHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Payment\Helper\Data as PaymentHelper;

class DpoConfigProvider implements ConfigProviderInterface
{
    /**
     * @var ResolverInterface
     */
    protected $localeResolver;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var \Magento\Customer\Helper\Session\CurrentCustomer
     */
    protected $currentCustomer;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var DpoHelper
     */
    protected $dpoHelper;

    /**
     * @var string[]
     */
    protected $methodCodes = [
        Config::METHOD_CODE,
    ];

    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod[]
     */
    protected $methods = [];

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @param ConfigFactory $configFactory
     * @param ResolverInterface $localeResolver
     * @param CurrentCustomer $currentCustomer
     * @param DpoHelper $paymentHelper
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        ConfigFactory $configFactory,
        ResolverInterface $localeResolver,
        CurrentCustomer $currentCustomer,
        DpoHelper $dpoHelper,
        PaymentHelper $paymentHelper
    ) {
        $this->_logger = $logger;
        $pre           = __METHOD__ . ' : ';
        $this->_logger->debug( $pre . 'bof' );

        $this->localeResolver  = $localeResolver;
        $this->config          = $configFactory->create();
        $this->currentCustomer = $currentCustomer;
        $this->dpoHelper       = $dpoHelper;
        $this->paymentHelper   = $paymentHelper;

        foreach ( $this->methodCodes as $code ) {
            $this->methods[$code] = $this->paymentHelper->getMethodInstance( $code );
        }

        $this->_logger->debug( $pre . 'eof and this  methods has : ', $this->methods );
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug( $pre . 'bof' );
        $inlineConfig = [
            'payment' => [
                'dpo' => [
                    'paymentAcceptanceMarkSrc'  => $this->config->getPaymentMarkImageUrl(),
                    'paymentAcceptanceMarkHref' => $this->config->getPaymentMarkWhatIsDpo(),
                ],
            ],
        ];

        foreach ( $this->methodCodes as $code ) {
            if ( $this->methods[$code]->isAvailable() ) {
                $inlineConfig['payment']['dpo']['redirectUrl'][$code]          = $this->getMethodRedirectUrl( $code );
                $inlineConfig['payment']['dpo']['billingAgreementCode'][$code] = $this->getBillingAgreementCode( $code );

            }
        }
        $this->_logger->debug( $pre . 'eof', $inlineConfig );
        return $inlineConfig;
    }

    /**
     * Return redirect URL for method
     *
     * @param string $code
     * @return mixed
     */
    protected function getMethodRedirectUrl( $code )
    {
        $pre = __METHOD__ . ' : ';
        $this->_logger->debug( $pre . 'bof' );

        $methodUrl = $this->methods[$code]->getOrderPlaceRedirectUrl();

        $this->_logger->debug( $pre . 'eof' );
        return $methodUrl;
    }

    /**
     * Return billing agreement code for method
     *
     * @param string $code
     * @return null|string
     */
    protected function getBillingAgreementCode( $code )
    {

        $pre = __METHOD__ . ' : ';
        $this->_logger->debug( $pre . 'bof' );

        $customerId = $this->currentCustomer->getCustomerId();
        $this->config->setMethod( $code );

        $this->_logger->debug( $pre . 'eof' );

        // Always return null
        return $this->dpoHelper->shouldAskToCreateBillingAgreement( $this->config, $customerId );
    }
}
