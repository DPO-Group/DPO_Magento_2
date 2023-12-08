<?php

/** @noinspection PhpUnused */

/** @noinspection PhpUnusedParameterInspection */

/** @noinspection PhpUndefinedFieldInspection */

/** @noinspection PhpUndefinedNamespaceInspection */

/** @noinspection PhpUndefinedMethodInspection */

/*
 * Copyright (c) 2023 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Redirect;

use Dpo\Dpo\Controller\AbstractDpo;
use Dpo\Dpo\Model\{Dpo, Dpopay, TransactionDataFactory};
use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\{Session as CustomerSession, Url};
use Magento\Framework\{App\Config\ScopeConfigInterface,
    Data\Form\FormKey,
    DB\Transaction as DBTransaction,
    DB\TransactionFactory,
    Exception\LocalizedException,
    Session\Generic,
    Stdlib\DateTime\DateTime,
    Url\Helper\Data,
    UrlInterface,
    View\Result\PageFactory};
use Magento\Framework\App\{Action\Context,
    CsrfAwareActionInterface,
    ObjectManager,
    Request\InvalidRequestException,
    RequestInterface};
use Magento\Sales\{Api\OrderRepositoryInterface,
    Model\Order,
    Model\Order\Email\Sender\InvoiceSender,
    Model\Order\Email\Sender\OrderSender,
    Model\Order\Invoice,
    Model\Order\Payment\Transaction,
    Model\Order\Payment\Transaction\Builder,
    Model\OrderFactory,
    Model\Service\InvoiceService};
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

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
    protected object $_messageManager;
    protected TransactionDataFactory $dataFactory;
    protected $baseurl;


    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder|Builder $_transactionBuilder
     */
    protected Magento\Sales\Model\Order\Payment\Transaction\Builder|Builder $_transactionBuilder;
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
    private Order $order;
    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfigInterface;

    /**
     * Success constructor.
     *
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
     * @param Builder $_transactionBuilder
     * @param TransactionDataFactory $dataFactory
     * @param DBTransaction $dbTransaction
     * @param Order $order
     * @param ScopeConfigInterface $scopeConfigInterface
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
        Builder $_transactionBuilder,
        TransactionDataFactory $dataFactory,
        DBTransaction $dbTransaction,
        Order $order,
        ScopeConfigInterface $scopeConfigInterface,
        FormKey $formKey
    ) {
        $this->scopeConfigInterface = $scopeConfigInterface;
        $this->order                = $order;
        $this->dbTransaction        = $dbTransaction;
        $this->dataFactory          = $dataFactory;
        $this->_transactionBuilder  = $_transactionBuilder;
        $this->baseurl              = $storeManager->getStore()->getBaseUrl();
        $this->redirectToCartScript = '<script>window.top.location.href="'
            . $this->baseurl . 'checkout/cart/";</script>';

        parent::__construct(
            $context,
            $pageFactory,
            $customerSession,
            $checkoutSession,
            $orderFactory,
            $DpoSession,
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
            $OrderSender,
            $date,
            $formKey
        );
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    //must be implemented Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl
    public function getCustomerBeforeAuthUrl()
    {
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute
     */
    public function execute(): string
    {
        $lastRealOrder = $this->_checkoutSession->getLastRealOrder();
        $request       = $this->getRequest();
        $Requestdata   = $request->getParams();
        $pre           = __METHOD__ . " : ";
        $this->_logger->debug($pre . 'bof');
        $this->pageFactory->create();
        $this->dataFactory->create();
        try {
            //Get the user session
            $this->_order = $this->_checkoutSession->getLastRealOrder();

            if (isset($Requestdata['TransactionToken'])) {
                $transToken = $Requestdata['TransactionToken'];
                $reference  = $Requestdata['CompanyRef'];
                $testText   = substr($reference, -6);
                $status = $this->getStatus($transToken, $testText);

                // Decline the transaction if the reference and order ID are not the same
                // (i.e. this prevents possible fraud trans.)
                if (explode("_", $reference)[0] !== $this->_order->getRealOrderId()) {
                    $status = 2;
                }

                switch ($status) {
                    case 1:
                        $this->prepareTransactionData($lastRealOrder, $Requestdata, 'captured');
                        $status = Order::STATE_PROCESSING;

                        $this->_order->setStatus($status); //configure the status
                        $this->_order->setState($status)->save(); //try and configure the status
                        $this->_order->save();
                        $lastRealOrder = $this->_order;

                        $model                  = $this->_paymentMethod;
                        $order_successful_email = $model->getConfigData('order_email');

                        if ($order_successful_email != '0') {
                            $this->OrderSender->send($lastRealOrder);
                            $lastRealOrder->addStatusHistoryComment(
                                __('Notified customer about order #%1.', $lastRealOrder->getId())
                            )->setIsCustomerNotified(true)->save();
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->_invoiceService->prepareInvoice($lastRealOrder);
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
                        echo '<script>parent.location="' . $this->baseurl . 'checkout/onepage/success";</script>';
                        break;
                    case 2:
                        $this->prepareTransactionData($lastRealOrder, $Requestdata, 'declined');
                        $this->messageManager->addNotice('Transaction has been declined.');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been declined, Reference: '
                                . $lastRealOrder->getIncrementId()
                            )
                        )->setIsCustomerNotified(false);
                        $this->cancelOrder();
                        break;
                    case 0:
                    case 4:
                        $this->prepareTransactionData($lastRealOrder, $Requestdata, 'cancelled');
                        $this->messageManager->addNotice('Transaction has been cancelled');
                        $this->_order->addStatusHistoryComment(
                            __(
                                'Redirect Response, Transaction has been cancelled, Reference: '
                                . $lastRealOrder->getIncrementId(
                                )
                            )
                        )->setIsCustomerNotified(false);
                        $this->cancelOrder();
                        break;
                    default:
                        break;
                }
            }
        } catch (LocalizedException $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            echo $this->redirectToCartScript;
        } catch (Exception $e) {
            $this->_logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start Dpo Checkout.'));
            echo $this->redirectToCartScript;
        }

        return '';
    }

    public function prepareTransactionData($order, $data, $paymentStatus)
    {
        $Payment                    = array();
        $Payment['status']          = $paymentStatus;
        $Payment['reference']       = $order->getIncrementId();
        $Payment['txn_id']          = $data['TransID'];
        $Payment['additional_data'] = $data;
        $order                      = $this->getOrderByIncrementId($Payment['reference']);

        $this->createTransaction($order, $Payment);
    }

    public function createTransaction($order, $paymentData = array()): bool
    {
        $response = false;
        try {
            // Get payment object from order object
            $payment = $order->getPayment();
            if (! $payment) {
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
            $trans       = $this->_transactionBuilder;
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

    public function getOrderByIncrementId($incrementId)
    {
        return $this->order->loadByIncrementId($incrementId);
    }

    public function getPaymentConfig($field)
    {
        return $this->scopeConfigInterface->getValue("payment/dpo/$field");
    }

    public function cancelOrder()
    {
        $this->_order->cancel()->save();
        $this->_checkoutSession->restoreQuote();
        echo $this->redirectToCartScript;
    }

    private function getStatus($transToken, $testText): int
    {
        $dpo                  = new Dpopay($this->_logger, $testText === 'teston');
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
