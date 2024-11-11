<?php

/*
 * Copyright (c) 2024 DPO Group
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
     * @var Dpo $paymentMethod
     */
    protected Dpo $paymentMethod;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;

    /**
     * @var Session
     */
    protected Session $checkoutSession;

    /**
     * @var ReadFactory $readFactory
     */
    protected ReadFactory $readFactory;

    /**
     * @var Reader $reader
     */

    /**
     * Request class constructor
     *
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
        $this->orderFactory    = $orderFactory;
        $this->checkoutSession = $checkoutSession;
        parent::__construct($context, $data);
        $this->readFactory   = $readFactory;
        $this->reader        = $reader;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Preparing global layout
     *
     * You can redefine this method in child classes for changing layout
     *
     * @return $this
     */
    public function _prepareLayout()
    {
        $this->setMessage('Redirecting to DPO Pay')
             ->setId('dpo_checkout')
             ->setName('dpo_checkout')
             ->setFormData($this->paymentMethod->getStandardCheckoutFormFields())
             ->setSubmitForm(
                 '<script type="text/javascript">document.getElementById( "dpo_checkout" ).submit();</script>'
             );

        return parent::_prepareLayout();
    }
}
