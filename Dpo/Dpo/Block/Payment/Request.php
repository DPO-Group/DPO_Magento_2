<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Block\Payment;

use Dpo\Dpo\Model\Dpo;
use Magento\Checkout\Model\Session;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\OrderFactory;

class Request extends Template
{
    /**
     * @var Dpo $_paymentMethod
     */
    protected $_paymentMethod;

    /**
     * @var OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var ReadFactory $readFactory
     */
    protected $readFactory;

    /**
     * @var Reader $reader
     */
    protected $reader;

    /**
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param Session $checkoutSession
     * @param ReadFactory $readFactory
     * @param Reader $reader
     * @param Dpo $paymentMethod
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        Session $checkoutSession,
        ReadFactory $readFactory,
        Reader $reader,
        Dpo $paymentMethod,
        array $data = []
    ) {
        $this->_orderFactory    = $orderFactory;
        $this->_checkoutSession = $checkoutSession;
        parent::__construct($context, $data);
        $this->_isScopePrivate = true;
        $this->readFactory     = $readFactory;
        $this->reader          = $reader;
        $this->_paymentMethod  = $paymentMethod;
    }
}
