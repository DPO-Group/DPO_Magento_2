<?php
/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Redirect;

use Magento\Customer\Model\{
    Url,
    Session as CustomerSession};

use Dpo\Dpo\Model\{
    Dpo,
    Dpopay,
    TransactionDataFactory};

use Magento\Framework\App\{
    ObjectManager,
    RequestInterface,
    CsrfAwareActionInterface,
    Request\InvalidRequestException,
    Action\Context};

use Magento\Framework\{
    DB\TransactionFactory,
    Exception\LocalizedException,
    Session\Generic,
    Stdlib\DateTime\DateTime,
    Url\Helper\Data,
    View\Result\PageFactory,
    UrlInterface,
    DB\Transaction as DBTransaction,
    App\Config\ScopeConfigInterface,
    Data\Form\FormKey};

use Magento\Sales\{
    Api\OrderRepositoryInterface,
    Model\OrderFactory,
    Model\Order\Email\Sender\InvoiceSender,
    Model\Order\Email\Sender\OrderSender,
    Model\Order\Invoice,
    Model\Order\Payment\Transaction,
    Model\Order\Payment\Transaction\Builder,
    Model\Service\InvoiceService,
    Model\Order};

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Store\Model\StoreManagerInterface;
use Dpo\Dpo\Controller\AbstractDpo;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Exception;

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
    protected $resultPageFactory;
    protected $_messageManager;
    protected $dataFactory;
    protected $baseurl;


    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;
    /**
     * @var string
     */
    private $redirectToCartScript;
    /**
     * @var DBTransaction
     */
    private $dbTransaction;
    /**
     * @var Order
     */
    private $order;
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfigInterface;

    /**
     * Success constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $pageFactory
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Framework\Session\Generic $DpoSession
     * @param \Magento\Framework\Url\Helper\Data $urlHelper
     * @param \Magento\Customer\Model\Url $customerUrl
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param Dpo $paymentMethod
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $OrderSender
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param \Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
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
        $this->order = $order;
        $this->dbTransaction = $dbTransaction;
        $this->dataFactory         = $dataFactory;
        $this->_transactionBuilder = $_transactionBuilder;
        $this->baseurl= $storeManager->getStore()->getBaseUrl();
        $this->redirectToCartScript = '<script>window.top.location.href="' . $this->baseurl . 'checkout/cart/";</script>';

        parent::__construct( $context, $pageFactory, $customerSession, $checkoutSession, $orderFactory, $DpoSession, $urlHelper, $customerUrl, $logger, $transactionFactory, $invoiceService, $invoiceSender, $paymentMethod, $urlBuilder, $orderRepository, $storeManager, $OrderSender, $date,$formKey );
    }

    public function createCsrfValidationException( RequestInterface $request ):  ? InvalidRequestException
    {
        return null;
    }

    //must be implemented Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl
    public function getCustomerBeforeAuthUrl(){}

    public function validateForCsrf( RequestInterface $request ) :  ? bool
    {
        return true;
    }

    /**
     * Execute
     */
    public function execute()
    {
        $lastRealOrder       = $this->_checkoutSession->getLastRealOrder();
        $request     = $this->getRequest();
        $Requestdata = $request->getParams();
        $pre         = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );
        $this->pageFactory->create();
        $this->dataFactory->create();
        try {
            //Get the user session
            $this->_order = $this->_checkoutSession->getLastRealOrder();

            if ( isset( $Requestdata['TransactionToken'] ) ) {

                $transToken = $Requestdata['TransactionToken'];
                $reference  = $Requestdata['CompanyRef'];
                $testText   = substr( $reference, -6 );

                $status = $this->getStatus($transToken, $testText);


                switch ( $status ) {
                    case 1:
                        $this->prepareTransactionData( $lastRealOrder, $Requestdata, 'captured' );
                        $status = \Magento\Sales\Model\Order::STATE_PROCESSING;

                        if ( $this->getConfigData( 'Successful_Order_status' ) != "" ) {
                            $status = $this->getConfigData( 'Successful_Order_status' );
                        }
                        $this->_order->setStatus( $status ); //configure the status
                        $this->_order->setState( $status )->save(); //try and configure the status
                        $this->_order->save();
                        $lastRealOrder = $this->_order;

                        $model

                            = $this->_paymentMethod;
                        $order_successful_email = $model->getConfigData( 'order_email' );

                        if ( $order_successful_email != '0' ) {
                            $this->OrderSender->send( $lastRealOrder );
                            $lastRealOrder->addStatusHistoryComment( __( 'Notified customer about order #%1.', $lastRealOrder->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->_invoiceService->prepareInvoice( $lastRealOrder );
                        $invoice->setRequestedCaptureCase( Invoice::CAPTURE_ONLINE );
                        $invoice->register();

                        // Save the invoice to the order
                        $transaction = $this->dbTransaction
                            ->addObject( $invoice )
                            ->addObject( $invoice->getOrder() );

                        $transaction->save();

                        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                        $send_invoice_email = $model->getConfigData( 'invoice_email' );
                        if ( $send_invoice_email != '0' ) {
                            $this->invoiceSender->send( $invoice );
                            $lastRealOrder->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        // Invoice capture code completed
                        echo '<script>parent.location="' . $this->baseurl . 'checkout/onepage/success";</script>';
                        break;
                    case 2:
                        $this->prepareTransactionData( $lastRealOrder, $Requestdata, 'declined' );
                        $this->messageManager->addNotice( 'Transaction has been declined.' );
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been declined, Reference: ' . $lastRealOrder->getIncrementId() ) )->setIsCustomerNotified( false );
                        $this->cancelOrder();
                        break;
                    case 0:
                    case 4:
                        $this->prepareTransactionData( $lastRealOrder, $Requestdata, 'cancelled' );
                        $this->messageManager->addNotice( 'Transaction has been cancelled' );
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been cancelled, Reference: ' . $lastRealOrder->getIncrementId() ) )->setIsCustomerNotified( false );
                        $this->cancelOrder();
                        break;
                    default:
                        break;
                }
            }

        } catch ( LocalizedException $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, $e->getMessage() );
            echo $this->redirectToCartScript;
        } catch ( \Exception $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, __( 'We can\'t start Dpo Checkout.' ) );
            echo $this->redirectToCartScript;
        }

        return '';
    }

    public function prepareTransactionData( $order, $data, $paymentStatus )
    {
        $Payment                    = array();
        $Payment['status']          = $paymentStatus;
        $Payment['reference']       = $order->getIncrementId();
        $Payment['txn_id']          = $data['TransID'];
        $Payment['additional_data'] = $data;
        $order                      = $this->getOrderByIncrementId( $Payment['reference'] );

        $this->createTransaction( $order, $Payment );
    }

    public function createTransaction( $order, $paymentData = array() )
    {
        try {
            // Get payment object from order object
            $payment = $order->getPayment();
            if(!$payment){
                return false;
            }
            $payment->setLastTransId( $paymentData['txn_id'] )
                ->setTransactionId( $paymentData['txn_id'] )
                ->setAdditionalInformation( [Transaction::RAW_DETAILS => (array) $paymentData] );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __( 'The authorized amount is %1.', $formatedPrice );
            if ( $paymentData['status'] == "captured" ) {
                $type = Transaction::TYPE_CAPTURE;
            } else {
                $type = Transaction::TYPE_VOID;
            }
            // Get builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment( $payment )
                ->setOrder( $order )
                ->setTransactionId( $paymentData['txn_id'] )
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => (array) $paymentData['additional_data']]
                )
                ->setFailSafe( true )
            // Build method creates the transaction and returns the object
                ->build( $type );

            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId( null );
            $payment->save();
            $order->save();

            return $transaction->save()->getTransactionId();
        } catch ( Exception $e ) {
            // Log errors here if needed
        }
    }

    public function getOrderByIncrementId( $incrementId )
    {
        return $this->order->loadByIncrementId( $incrementId );
    }

    public function getPaymentConfig( $field )
    {
        return $this->scopeConfigInterface->getValue( "payment/dpo/$field" );
    }

    public function cancelOrder(){
        $this->_order->cancel()->save();
        $this->_checkoutSession->restoreQuote();
        echo $this->redirectToCartScript;
    }

    private function getStatus($transToken, $testText): int
    {
        $dpo                  = new Dpopay($this->_logger, $testText === 'teston');
        $data                 = [];
        $data['transToken']   = $transToken;
        $data['companyToken'] = $this->getPaymentConfig( 'company_token' );
        $verify               = $dpo->verifyToken( $data );

        if ( $verify != '' ) {
            $verify = new SimpleXMLElement( $verify );
            switch ( $verify->Result->__toString() ) {
                case '000' :
                    $status = 1;
                    break;
                case '901':
                    $status = 2;
                    break;
                case '904':
                default:
                    $status = 4;
                    break;
            }
        } else {
            $status = 0;
        }

        return $status;

    }
}
