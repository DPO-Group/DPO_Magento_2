<?php
/**
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
namespace Dpo\Dpo\Block;

use Dpo\Dpo\Helper\Data;
use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\ConfigFactory;
use Magento\Customer\Helper\Session\CurrentCustomer;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\Template\Context;

class Form extends \Magento\Payment\Block\Form
{
    /**
     * @var string Payment method code
     */
    protected $_methodCode = Config::METHOD_CODE;

    /**
     * @var Data
     */
    protected $_dpoData;

    /**
     * @var ConfigFactory
     */
    protected $dpoConfigFactory;

    /**
     * @var ResolverInterface
     */
    protected $_localeResolver;

    /**
     * @var Config
     */
    protected $_config;

    /**
     * @var bool
     */
    protected $_isScopePrivate;

    /**
     * @var CurrentCustomer
     */
    protected $currentCustomer;

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
        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );
        $this->_dpoData         = $dpoData;
        $this->dpoConfigFactory = $dpoConfigFactory;
        $this->_localeResolver  = $localeResolver;
        $this->_config          = null;
        $this->_isScopePrivate  = true;
        $this->currentCustomer  = $currentCustomer;
        parent::__construct( $context, $data );
        $this->_logger->debug( $pre . "eof" );
    }

    /**
     * Set template and redirect message
     *
     * @return null
     */
    protected function _construct()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );
        $this->_config = $this->dpoConfigFactory->create()->setMethod( $this->getMethodCode() );
        parent::_construct();
    }

    /**
     * Payment method code getter
     *
     * @return string
     */
    public function getMethodCode()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );

        return $this->_methodCode;
    }

}
