<?php

/*
 * Copyright (c) 2024 DPO Group
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
use Magento\Framework\App\CsrfAwareActionInterface;
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
    protected Quote|bool $quote = false;

    /**
     * Config mode type
     *
     * @var string
     */

    /**
     * Config method type
     *
     * @var string|Dpo
     */
    protected string|Dpo $configMethod = Config::METHOD_CODE;

    /**
     * Checkout mode type
     *
     * @var string
     */

    /**
     * @var CustomerSession
     */
    protected CustomerSession $customerSession;

    /**
     * @var CheckoutSession $checkoutSession
     */
    protected CheckoutSession $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;

    /**
     * @var Generic
     */
    protected Generic $dpoSession;

    /**
     * @var Data|Helper
     */
    protected Helper|Data $urlHelper;

    /**
     * @var Url
     */
    protected Url $customerUrl;

    /**
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @var  Order $order
     */
    protected Order $order;

    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;

    /**
     * @var TransactionFactory
     */
    protected TransactionFactory $transactionFactory;
    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $storeManager;
    /**
     * @var Dpo $paymentMethod
     */
    protected Dpo $paymentMethod;
    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;
    /**
     * @var InvoiceService
     */
    protected InvoiceService $invoiceService;
    /**
     * @var InvoiceSender
     */
    protected InvoiceSender $invoiceSender;
    /**
     * @var OrderSender
     */
    protected OrderSender $orderSender;
    /**
     * @var UrlInterface
     */
    private UrlInterface $urlBuilder;
    /**
     * @var DateTime
     */
    private DateTime $date;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param CheckoutSession $checkoutSession
     * @param OrderFactory $orderFactory
     * @param Generic $dpoSession
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
     * @param OrderSender $orderSender
     * @param DateTime $date
     * @param FormKey $formKey
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        CustomerSession $customerSession,
        CheckoutSession $checkoutSession,
        OrderFactory $orderFactory,
        Generic $dpoSession,
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
        OrderSender $orderSender,
        DateTime $date,
        FormKey $formKey
    ) {
        // CsrfAwareAction Magento2.3 compatibility
        if (interface_exists(CsrfAwareActionInterface::class)) {
            $request = $this->getRequest();
            if ($request instanceof HttpRequest && $request->isPost() && empty($request->getParam('form_key'))) {
                $request->setParam('form_key', $formKey->getFormKey());
            }
        }

        $pre = __METHOD__ . " : ";

        $this->logger = $logger;

        $this->logger->debug($pre . 'bof');

        $this->customerSession    = $customerSession;
        $this->checkoutSession    = $checkoutSession;
        $this->orderFactory       = $orderFactory;
        $this->dpoSession         = $dpoSession;
        $this->urlHelper          = $urlHelper;
        $this->customerUrl        = $customerUrl;
        $this->pageFactory        = $pageFactory;
        $this->invoiceService     = $invoiceService;
        $this->invoiceSender      = $invoiceSender;
        $this->orderSender        = $orderSender;
        $this->transactionFactory = $transactionFactory;
        $this->paymentMethod      = $paymentMethod;
        $this->urlBuilder         = $urlBuilder;
        $this->orderRepository    = $orderRepository;
        $this->storeManager       = $storeManager;
        $this->date               = $date;

        parent::__construct($context);

        $this->logger->debug($pre . 'eof');
    }

    /**
     * Custom getter for payment configuration
     *
     * @param string $field i.e company_token, test_mode
     *
     * @return mixed
     */
    public function getConfigData(string $field): mixed
    {
        return $this->paymentMethod->getConfigData($field);
    }

    /**
     * Returns a list of action flags [flag_key] => boolean
     *
     * @return array
     */
    public function getActionFlagList(): array
    {
        return [];
    }

    /**
     * Returns login url parameter for redirect
     *
     * @return string
     */
    public function getLoginUrl(): string
    {
        return $this->customerUrl->getLoginUrl();
    }

    /**
     * Returns action name which requires redirect
     *
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
        $this->customerSession->setBeforeAuthUrl($this->_redirect->getRefererUrl());
        $this->getResponse()->setRedirect(
            $this->urlHelper->addRequestParam($this->customerUrl->getLoginUrl(), ['context' => 'checkout'])
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
        $this->logger->debug($pre . 'bof');
        $this->order = $this->checkoutSession->getLastRealOrder();

        if (!$this->order->getId()) {
            $this->getResponse()->setStatusHeader(404, '1.1', 'Not found');
            throw new LocalizedException(__('We could not find "Order" for processing'));
        }

        if ($this->order->getState() != Order::STATE_PENDING_PAYMENT) {
            $this->order->setState(
                Order::STATE_PENDING_PAYMENT
            )->save();
        }

        // Set the initial order status and state to that configured in the payment method

        $this->orderRepository->save($this->order);

        if ($this->order->getQuoteId()) {
            $this->checkoutSession->setDpoQuoteId($this->checkoutSession->getQuoteId());
            $this->checkoutSession->setDpoSuccessQuoteId($this->checkoutSession->getLastSuccessQuoteId());
            $this->checkoutSession->setDpoRealOrderId($this->checkoutSession->getLastRealOrderId());
            $this->checkoutSession->getQuote()->setIsActive(false)->save();
        }

        $this->logger->debug($pre . 'eof');
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
        return $this->checkoutSession;
    }

    /**
     * Return checkout quote object
     *
     * @return bool|Quote
     */
    protected function _getQuote(): bool|Quote
    {
        if (!$this->quote) {
            $this->quote = $this->_getCheckoutSession()->getQuote();
        }

        return $this->quote;
    }
}
