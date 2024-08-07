<?php

/**
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Block;

use Dpo\Dpo\Helper\Data;
use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\ConfigFactory;
use Dpo\Dpo\Model\Dpo;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\Template\Context;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * @var string|Dpo Payment method code
     */
    protected string|Dpo $methodCode = Config::METHOD_CODE;

    /**
     * @var Data
     */
    protected Data $dpoData;

    /**
     * @var ConfigFactory
     */
    protected ConfigFactory $dpoConfigFactory;

    /**
     * @var ResolverInterface
     */
    protected ResolverInterface $localeResolver;

    /**
     * @var Config|null
     */
    protected ?Config $config;

    /**
     * @var bool
     */
    protected $_isScopePrivate;

    /**
     * @var CurrentCustomer
     */
    protected CurrentCustomer $currentCustomer;

    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @param Context $context
     * @param ConfigFactory $dpoConfigFactory
     * @param ResolverInterface $localeResolver
     * @param Data $dpoData
     * @param CurrentCustomer $currentCustomer
     * @param array $data
     */
    public function __construct(
        Context $context,
        ConfigFactory $dpoConfigFactory,
        ResolverInterface $localeResolver,
        Data $dpoData,
        CurrentCustomer $currentCustomer,
        array $data = []
    ) {
        $this->_logger = $context->getLogger();
        $pre           = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->dpoData          = $dpoData;
        $this->dpoConfigFactory = $dpoConfigFactory;
        $this->localeResolver   = $localeResolver;
        $this->config           = null;
        $this->_isScopePrivate  = true;
        $this->currentCustomer  = $currentCustomer;
        parent::__construct($context, $data);
        $this->_logger->debug($pre . "eof");
    }

    /**
     * Payment method code getter
     *
     * @return Dpo|string
     */
    public function getMethodCode(): Dpo|string
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');

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
        $this->_logger->debug($pre . 'bof');
        $this->config = $this->dpoConfigFactory->create()->setMethod($this->getMethodCode());
        parent::_construct();

        return null;
    }
}
