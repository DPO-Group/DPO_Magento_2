<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Redirect;

use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\Dpo;
use Exception;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Dpo\Dpo\Service\CheckoutProcessor;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface
{
    public const CARTURL = "checkout/cart";
    /**
     * @var PageFactory
     */
    protected PageFactory $pageFactory;
    private ResultFactory $resultFactory;

    private ManagerInterface $messageManager;
    /**
     * @var Dpo
     */
    private Dpo $paymentMethod;
    private Config $config;
    private CheckoutProcessor $checkoutProcessor;
    private UrlInterface $urlBuilder;
    private PaymentTokenManagementInterface $paymentTokenManagement;
    /**
     * @var CheckoutSession $checkoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $response;
    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;
    /**
     * @var  Order $order
     */
    protected Order $order;
    /**
     * @var RedirectInterface
     */
    protected RedirectInterface $redirect;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     * @param UrlInterface $urlBuilder
     * @param Config $config
     * @param ResultFactory $resultFactory
     * @param ManagerInterface $messageManager
     * @param PaymentTokenManagementInterface $paymentTokenManagement
     * @param Dpo $paymentMethod
     * @param CheckoutProcessor $checkoutProcessor
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        CheckoutSession $checkoutSession,
        LoggerInterface $logger,
        UrlInterface $urlBuilder,
        Config $config,
        ResultFactory $resultFactory,
        ManagerInterface $messageManager,
        PaymentTokenManagementInterface $paymentTokenManagement,
        Dpo $paymentMethod,
        CheckoutProcessor $checkoutProcessor,
        OrderRepositoryInterface $orderRepository,
    ) {
        $pre = __METHOD__ . " : ";

        $this->logger = $logger;

        $this->logger->debug($pre . 'bof');

        $this->checkoutSession        = $checkoutSession;
        $this->pageFactory            = $pageFactory;
        $this->resultFactory          = $resultFactory;
        $this->messageManager         = $messageManager;
        $this->paymentMethod          = $paymentMethod;
        $this->config                 = $config;
        $this->checkoutProcessor      = $checkoutProcessor;
        $this->urlBuilder             = $urlBuilder;
        $this->paymentTokenManagement = $paymentTokenManagement;

        $this->logger->debug($pre . 'eof');
    }

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->checkoutProcessor->initCheckout();
        } catch (LocalizedException $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());

            return $this->checkoutProcessor->getRedirectToCartObject();
        } catch (Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start DPO Pay Checkout.'));

            return $this->checkoutProcessor->getRedirectToCartObject();
        }
        $block = $page_object->getLayout()->getBlock('dpo')->setPaymentFormData(null);

        $formData = $block->getFormData();
        if (isset($formData['success']) && $formData['success'] === false) {
            $resultExplanation = $formData['resultExplanation'] ?? 'Explanation not available';
            $this->messageManager->addErrorMessage(__($resultExplanation));

            return $this->checkoutProcessor->getRedirectToCartObject();
        } elseif (!$formData) {
            $this->logger->error("We can\'t start DPO Pay Checkout.");

            return $this->checkoutProcessor->getRedirectToCartObject();
        }

        return $page_object;
    }

    #Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl
    public function getCustomerBeforeAuthUrl()
    {
        //  Class contains 1 abstract method and must therefore be declared abstract or implement the remaining methods
    }

    /**
     * Retrieve request object
     *
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Retrieve response object
     *
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Set redirect into response
     *
     * @param string $path
     * @param array $arguments
     *
     * @return ResponseInterface
     */
    protected function redirect(string $path, array $arguments = []): ResponseInterface
    {
        $this->redirect->redirect($this->getResponse(), $path, $arguments);

        return $this->getResponse();
    }
}
