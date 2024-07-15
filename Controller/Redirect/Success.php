<?php

/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Redirect;

use Dpo\Dpo\Controller\AbstractDpo;
use Dpo\Dpo\Model\Dpo;
use Dpo\Dpo\Model\Dpopay;
use Dpo\Dpo\Model\TransactionDataFactory;
use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DB\Transaction as DBTransaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Session\Generic;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Url\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\Builder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Magento\Framework\Controller\ResultFactory;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends AbstractDpo implements CsrfAwareActionInterface
{
    /**
     * @var PageFactory
     */
    protected PageFactory $resultPageFactory;
    /**
     * @var object
     */
    protected $messageManager;
    /**
     * @var TransactionDataFactory
     */
    protected TransactionDataFactory $dataFactory;
    /**
     * @var string
     */
    protected $baseurl;
    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder|Builder $transactionBuilder
     */
    protected Magento\Sales\Model\Order\Payment\Transaction\Builder|Builder $transactionBuilder;
    /**
     * @var string
     */
    private string $redirectToCartScript;
    /**
     * @var DBTransaction
     */
    private DBTransaction $dbTransaction;
    /**
     * @var Order
     */
    protected Order $order;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfigInterface;
    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * Success constructor.
     *
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
     * @param Builder $_transactionBuilder
     * @param TransactionDataFactory $dataFactory
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param ScopeConfigInterface $scopeConfigInterface
     * @param FormKey $formKey
     * @param Curl $curl
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
        Builder $_transactionBuilder,
        TransactionDataFactory $dataFactory,
        DBTransaction $dbTransaction,
        Order $order,
        ScopeConfigInterface $scopeConfigInterface,
        FormKey $formKey,
        Curl $curl
    ) {
        $this->scopeConfigInterface = $scopeConfigInterface;
        $this->order                = $order;
        $this->dbTransaction        = $dbTransaction;
        $this->dataFactory          = $dataFactory;
        $this->transactionBuilder   = $_transactionBuilder;
        $this->baseurl              = $storeManager->getStore()->getBaseUrl();
        $this->redirectToCartScript = '<script>window.top.location.href="'
                                      . $this->baseurl . 'checkout/cart/";</script>';
        $this->curl                 = $curl;

        parent::__construct(
            $context,
            $pageFactory,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $dpoSession,
            $urlHelper,
            $customerUrl,
            $logger,
            $transactionFactory,
            $invoiceService,
            $invoiceSender,
            $paymentMethod,
            $urlBuilder,
            $orderRepository,
            $storeManager,
            $orderSender,
            $date,
            $formKey
        );
    }

    /**
     * CSRF validation exception handler
     *
     * @param RequestInterface $request
     *
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    //must be implemented Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl

    /**
     * Returns the URL where the customer should be redirected before authentication
     *
     * @return void
     */
    public function getCustomerBeforeAuthUrl()
    {
    }

    /**
     * Validate for CSRF
     *
     * @param RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute
     */
    public function execute(): ResultInterface
    {
        $lastRealOrder = $this->checkoutSession->getLastRealOrder();
        $request       = $this->getRequest();
        $requestData   = $request->getParams();
        $pre           = __METHOD__ . " : ";
        $this->logger->debug($pre . 'bof');
        $this->pageFactory->create();
        $this->dataFactory->create();

        $result = null;

        try {
            // Get the user session
            $this->order = $this->checkoutSession->getLastRealOrder();

            if (isset($requestData['TransactionToken'])) {
                $transToken = $requestData['TransactionToken'];
                $reference  = $requestData['CompanyRef'];
                $testText   = substr($reference, -6);
                $status     = $this->getStatus($transToken, $testText);

                // Decline the transaction if the reference and order ID are not the same
                // (i.e. this prevents possible fraud trans.)
                if (explode("_", $reference)[0] !== $this->order->getRealOrderId()) {
                    $status = 2;
                }

                switch ($status) {
                    case 1:
                        $this->prepareTransactionData($lastRealOrder, $requestData, 'captured');
                        $status = Order::STATE_PROCESSING;

                        $this->order->setStatus($status); // configure the status
                        $this->order->setState($status)->save(); // try and configure the status
                        $this->order->save();
                        $lastRealOrder = $this->order;

                        $model                  = $this->paymentMethod;
                        $order_successful_email = $model->getConfigData('order_email');

                        if ($order_successful_email != '0') {
                            $this->orderSender->send($lastRealOrder);
                            $lastRealOrder->addStatusHistoryComment(
                                __('Notified customer about order #%1.', $lastRealOrder->getId())
                            )->setIsCustomerNotified(true)->save();
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->invoiceService->prepareInvoice($lastRealOrder);
                        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                        $invoice->register();

                        // Save the invoice to the order
                        $transaction = $this->dbTransaction
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder());

                        $transaction->save();

                        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                        $send_invoice_email = $model->getConfigData('invoice_email');

                        if ($send_invoice_email != '0') {
                            $this->invoiceSender->send($invoice);
                            $lastRealOrder->addStatusHistoryComment(
                                __('Notified customer about invoice #%1.', $invoice->getId())
                            )->setIsCustomerNotified(true)->save();
                        }

                        // Invoice capture code completed
                        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
                        $script = '<script>parent.location="' . $this->baseurl . 'checkout/onepage/success";</script>';
                        $result->setContents($script);
                        break;

                    case 2:
                        $this->prepareTransactionData($lastRealOrder, $requestData, 'declined');
                        $this->messageManager->addNotice('Transaction has been declined.');
                        $this->order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been declined, Reference: '
                                . $lastRealOrder->getIncrementId()
                            )
                        )->setIsCustomerNotified(false);
                        $this->cancelOrder();
                        break;

                    case 0:
                    case 4:
                        $this->prepareTransactionData($lastRealOrder, $requestData, 'cancelled');
                        $this->messageManager->addNotice('Transaction has been cancelled');
                        $this->order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been cancelled, Reference: '
                                . $lastRealOrder->getIncrementId()
                            )
                        )->setIsCustomerNotified(false);
                        $this->cancelOrder();
                        break;

                    default:
                        break;
                }
            }
        } catch (LocalizedException $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setContents($this->redirectToCartScript);
        } catch (Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Dpo Checkout.'));
            $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
            $result->setContents($this->redirectToCartScript);
        }

        if (!$result) {
            $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        }

        return $result;
    }

    /**
     * Prepares the transaction data from DPO
     *
     * @param object $order
     * @param array $data
     * @param string $paymentStatus
     *
     * @return void
     */
    public function prepareTransactionData($order, $data, $paymentStatus)
    {
        $payment                    = [];
        $payment['status']          = $paymentStatus;
        $payment['reference']       = $order->getIncrementId();
        $payment['txn_id']          = $data['TransID'];
        $payment['additional_data'] = $data;
        $order                      = $this->getOrderByIncrementId($payment['reference']);

        $this->createTransaction($order, $payment);
    }

    /**
     * Creates the transaction from the payment data from DPO
     *
     * @param object $order
     * @param array $paymentData
     *
     * @return bool
     */
    public function createTransaction($order, $paymentData = []): bool
    {
        $response = false;
        try {
            // Get payment object from order object
            $payment = $order->getPayment();
            if (!$payment) {
                return false;
            }
            $payment->setLastTransId($paymentData['txn_id'])
                    ->setTransactionId($paymentData['txn_id'])
                    ->setAdditionalInformation([Transaction::RAW_DETAILS => (array)$paymentData]);
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('The authorized amount is %1.', $formatedPrice);
            if ($paymentData['status'] == "captured") {
                $type = Transaction::TYPE_CAPTURE;
            } else {
                $type = Transaction::TYPE_VOID;
            }
            // Get builder class
            $trans       = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                                 ->setOrder($order)
                                 ->setTransactionId($paymentData['txn_id'])
                                 ->setAdditionalInformation(
                                     [Transaction::RAW_DETAILS => (array)$paymentData['additional_data']]
                                 )
                                 ->setFailSafe(true)
                // Build method creates the transaction and returns the object
                                 ->build($type);

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId(null);
            $payment->save();
            $order->save();

            $response = $transaction->save()->getTransactionId();
        } catch (Exception $e) {
            $this->logger->debug($e->getMessage());
        }

        return $response;
    }

    /**
     * Get the order
     *
     * @param int $incrementId
     *
     * @return Order
     */
    public function getOrderByIncrementId($incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    /**
     * Get the config data
     *
     * @param string $field
     *
     * @return mixed
     */
    public function getPaymentConfig($field)
    {
        return $this->scopeConfigInterface->getValue("payment/dpo/$field");
    }

    /**
     * Cancels an order
     *
     * @return ResultInterface
     * @throws Exception
     */
    public function cancelOrder(): ResultInterface
    {
        $this->order->cancel()->save();
        $this->checkoutSession->restoreQuote();
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($this->redirectToCartScript);

        return $result;
    }

    /**
     * Gets the order status from DPO
     *
     * @param string $transToken
     * @param string $testText
     *
     * @return int
     */
    private function getStatus($transToken, $testText): int
    {
        $dpo                  = new Dpopay($this->logger, $this->curl, $testText === 'teston');
        $data                 = [];
        $data['transToken']   = $transToken;
        $data['companyToken'] = $this->getPaymentConfig('company_token');
        $verify               = $dpo->verifyToken($data);

        if ($verify != '') {
            try {
                try {
                    $verify = new SimpleXMLElement($verify);
                } catch (Exception $e) {
                    $this->logger->debug($e->getMessage());
                }
            } catch (Exception $e) {
                $this->logger->critical($e->getMessage());
            }
            $status = match ($verify->Result->__toString()) {
                '000' => 1,
                '901' => 2,
                default => 4,
            };
        } else {
            $status = 0;
        }

        return $status;
    }
}
