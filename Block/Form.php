<?php

/**
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Block;

use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\Dpo;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\Template\Context;
use Psr\Log\LoggerInterface;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * @var string|Dpo Payment method code
     */
    protected string|Dpo $methodCode = Config::METHOD_CODE;

    /**
     * @var ResolverInterface
     */
    protected ResolverInterface $localeResolver;

    /**
     * @var CurrentCustomer
     */
    protected CurrentCustomer $currentCustomer;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @param Context $context
     * @param ResolverInterface $localeResolver
     * @param CurrentCustomer $currentCustomer
     * @param LoggerInterface $logger
     * @param array $data
     */
    public function __construct(
        Context $context,
        ResolverInterface $localeResolver,
        CurrentCustomer $currentCustomer,
        LoggerInterface $logger,
        array $data = []
    ) {
        $this->logger = $logger;
        $pre          = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $this->localeResolver  = $localeResolver;
        $this->currentCustomer = $currentCustomer;
        parent::__construct($context, $data);
        $this->logger->debug($pre . "eof");
    }

    /**
     * Payment method code getter
     *
     * @return Dpo|string
     */
    public function getMethodCode(): Dpo|string
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');

        return $this->methodCode;
    }

    /**
     * Set template and redirect message
     *
     * @return null
     */
    protected function _construct()
    {
        $pre = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        parent::_construct();

        return null;
    }
}
