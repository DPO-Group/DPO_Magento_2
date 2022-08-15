<?php
/** @noinspection PhpUnused */

/** @noinspection PhpPropertyOnlyWrittenInspection */

/** @noinspection PhpUndefinedFieldInspection */

/** @noinspection PhpUndefinedNamespaceInspection */

/** @noinspection PhpUndefinedMethodInspection */

/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller;

use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\Dpo;
use Magento\Checkout\Controller\Express\RedirectLoginInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Framework\App\Action\Action as AppAction;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Checkout Controller
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractDpo extends AppAction implements RedirectLoginInterface
{
    /**
     * Internal cache of checkout models
     *
     * @var array
     */

    /**
     * @var Config
     */

    /**
     * @var bool|Quote
     */
    protected Quote|bool $_quote = false;

    /**
     * Config mode type
     *
     * @var string
     */

    /** Config method type @var string|Dpo */
    protected string|Dpo $_configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */

    /**
     * @var CustomerSession
     */
    protected CustomerSession $_customerSession;

    /**
     * @var CheckoutSession $_checkoutSession
     */
    protected CheckoutSession $_checkoutSession;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $_orderFactory;

    /**
     * @var Generic
     */
    protected Generic $DpoSession;

    /**
     * @var Data|Helper
     */
    protected Helper|Data $_urlHelper;

    /**
     * @var Url
     */
    protected Url $_customerUrl;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $_logger;

    /**
     * @var  Order $_order
     */
    protected Order $_order;

    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;

    /**
     * @var TransactionFactory
     */
    protected TransactionFactory $_transactionFactory;
    protected StoreManagerInterface $_storeManager;
    /**
     * @var Dpo $_paymentMethod
     */
    protected Dpo $_paymentMethod;
    protected OrderRepositoryInterface $orderRepository;
    protected InvoiceService $_invoiceService;
    protected InvoiceSender $invoiceSender;
    protected OrderSender $OrderSender;
    private UrlInterface $_urlBuilder;
    private DateTime $_date;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $DpoSession
     * @param Data $urlHelper
     * @param Url $customerUrl
     * @param LoggerInterface $logger
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param Dpo $paymentMethod
     * @param UrlInterface $urlBuilder
     * @param OrderRepositoryInterface $orderRepository
     * @param StoreManagerInterface $storeManager
     * @param OrderSender $OrderSender
     * @param DateTime $date
     * @param FormKey $formKey
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Generic $DpoSession,
        Data $urlHelper,
        Url $customerUrl,
        LoggerInterface $logger,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Dpo $paymentMethod,
        UrlInterface $urlBuilder,
        OrderRepositoryInterface $orderRepository,
        StoreManagerInterface $storeManager,
        OrderSender $OrderSender,
        DateTime $date,
        FormKey $formKey
    ) {
        // CsrfAwareAction Magento2.3 compatibility
        if (interface_exists("\Magento\Framework\App\CsrfAwareActionInterface")) {
            $request = $this->getRequest();
            if ($request instanceof HttpRequest && $request->isPost() && empty($request->getParam('form_key'))) {
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }

        $pre = __METHOD__ . " : ";

        $this->_logger = $logger;

        $this->_logger->debug($pre . 'bof');

        $this->_customerSession    = $customerSession;
        $this->_checkoutSession    = $checkoutSession;
        $this->_orderFactory       = $orderFactory;
        $this->DpoSession          = $DpoSession;
        $this->_urlHelper          = $urlHelper;
        $this->_customerUrl        = $customerUrl;
        $this->pageFactory         = $pageFactory;
        $this->_invoiceService     = $invoiceService;
        $this->invoiceSender       = $invoiceSender;
        $this->OrderSender         = $OrderSender;
        $this->_transactionFactory = $transactionFactory;
        $this->_paymentMethod      = $paymentMethod;
        $this->_urlBuilder         = $urlBuilder;
        $this->orderRepository     = $orderRepository;
        $this->_storeManager       = $storeManager;
        $this->_date               = $date;

        parent::__construct($context);

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field i.e company_token, test_mode
     *
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfigData(string $field): mixed
    {
        return $this->_paymentMethod->getConfigData($field);
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     * @return array
     */
    public function getActionFlagList(): array
    {
        return [];
    }

    /**
     * Returns login url parameter for redirect
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->_customerUrl->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     * @return string
     */
    public function getRedirectActionName(): string
    {
        return 'index';
    }

    /**
     * Redirect to login page
     *
     * @return void
     */
    public function redirectLogin()
    {
        $this->_actionFlag->set('', 'no-dispatch', true);
        $this->_customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->_urlHelper->addRequestParam($this->_customerUrl->getLoginUrl(), ['context' => 'checkout'])
        );
    }

    /**
     * Instantiate
     *
     * @return void
     * @throws LocalizedException
     */
    protected function _initCheckout()
    {
        $pre = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->_order = $this->_checkoutSession->getLastRealOrder();

        if ( ! $this->_order->getId()) {
            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->_order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->_order->setState(
                Order::STATE_PENDING_PAYMENT
            )->save();
        }

        // Set the initial order status and state to that configured in the payment method

        $this->orderRepository->save($this->_order);

        if ($this->_order->getQuoteId()) {
            $this->_checkoutSession->setDpoQuoteId($this->_checkoutSession->getQuoteId());
            $this->_checkoutSession->setDpoSuccessQuoteId($this->_checkoutSession->getLastSuccessQuoteId());
            $this->_checkoutSession->setDpoRealOrderId($this->_checkoutSession->getLastRealOrderId());
            $this->_checkoutSession->getQuote()->setIsActive(false)->save();
        }

        $this->_logger->debug($pre . 'eof');
    }

    /**
     * Dpo session instance getter
     *
     * @return Generic
     */
    protected function _getSession(): Generic
    {
        return $this->dpoSession;
    }

    /**
     * Return checkout session object
     *
     * @return CheckoutSession
     */
    protected function _getCheckoutSession(): CheckoutSession
    {
        return $this->_checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return bool|Quote
     */
    protected function _getQuote(): bool|Quote
    {
        if ( ! $this->_quote) {
            $this->_quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->_quote;
    }

}
