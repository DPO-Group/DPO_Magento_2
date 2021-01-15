<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
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
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Success extends AbstractDpo implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;
    protected $_messageManager;
    protected $dataFactory;

    /**
     * @var Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder
     */
    protected $_transactionBuilder;

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
     * @param TransactionDataFactory $dataFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $pageFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Framework\Session\Generic $DpoSession,
        \Magento\Framework\Url\Helper\Data $urlHelper,
        \Magento\Customer\Model\Url $customerUrl,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        Dpo $paymentMethod, \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $OrderSender,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        \Magento\Sales\Model\Order\Payment\Transaction\Builder $_transactionBuilder,
        TransactionDataFactory $dataFactory
    ) {
        $this->dataFactory         = $dataFactory;
        $this->_transactionBuilder = $_transactionBuilder;

        parent::__construct( $context, $pageFactory, $customerSession, $checkoutSession, $orderFactory, $DpoSession, $urlHelper, $customerUrl, $logger, $transactionFactory, $invoiceService, $invoiceSender, $paymentMethod, $urlBuilder, $orderRepository, $storeManager, $OrderSender, $date );
    }

    public function createCsrfValidationException( RequestInterface $request ):  ? InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf( RequestInterface $request ) :  ? bool
    {
        return true;
    }

    /**
     * Execute
     */
    public function execute()
    {
        $order       = $this->_checkoutSession->getLastRealOrder();
        $request     = $this->getRequest();
        $Requestdata = $request->getParams();
        $pre         = __METHOD__ . " : ";
        $this->_logger->debug( $pre . 'bof' );
        $page_object = $this->pageFactory->create();
        $transaction = $this->dataFactory->create();
        try {
            //Get the user session
            $this->_order = $this->_checkoutSession->getLastRealOrder();
            $baseurl      = $this->_storeManager->getStore()->getBaseUrl();
            if ( isset( $Requestdata['TransactionToken'] ) ) {
                $transToken = $Requestdata['TransactionToken'];
                $reference  = $Requestdata['CompanyRef'];
                $testText   = substr( $reference, -6 );
                $reference  = substr( $reference, 0, -6 );

                $dpo                  = new Dpopay( $testText === 'teston' ? true : false );
                $data                 = [];
                $data['transToken']   = $transToken;
                $data['companyToken'] = $this->getPaymentConfig( 'company_token' );
                $verify               = $dpo->verifyToken( $data );

                if ( $verify != '' ) {
                    $verify = new \SimpleXMLElement( $verify );
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

                switch ( $status ) {
                    case 1:
                        $this->prepareTransactionData( $order, $Requestdata, 'captured' );
                        $status = \Magento\Sales\Model\Order::STATE_PROCESSING;
                        if ( $this->getConfigData( 'Successful_Order_status' ) != "" ) {
                            $status = $this->getConfigData( 'Successful_Order_status' );
                        }
                        $message = __(
                            'Redirect Response, Transaction has been approved: REFERENCE: "%1"',
                            $reference
                        );
                        $this->_order->setStatus( $status ); //configure the status
                        $this->_order->setState( $status )->save(); //try and configure the status
                        $this->_order->save();
                        $order = $this->_order;

                        $model                  = $this->_paymentMethod;
                        $order_successful_email = $model->getConfigData( 'order_email' );

                        if ( $order_successful_email != '0' ) {
                            $this->OrderSender->send( $order );
                            $order->addStatusHistoryComment( __( 'Notified customer about order #%1.', $order->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        // Capture invoice when payment is successful
                        $invoice = $this->_invoiceService->prepareInvoice( $order );
                        $invoice->setRequestedCaptureCase( \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE );
                        $invoice->register();

                        // Save the invoice to the order
                        $transaction = $this->_objectManager->create( 'Magento\Framework\DB\Transaction' )
                            ->addObject( $invoice )
                            ->addObject( $invoice->getOrder() );

                        $transaction->save();

                        // Magento\Sales\Model\Order\Email\Sender\InvoiceSender
                        $send_invoice_email = $model->getConfigData( 'invoice_email' );
                        if ( $send_invoice_email != '0' ) {
                            $this->invoiceSender->send( $invoice );
                            $order->addStatusHistoryComment( __( 'Notified customer about invoice #%1.', $invoice->getId() ) )->setIsCustomerNotified( true )->save();
                        }

                        // Invoice capture code completed
                        echo '<script>parent.location="' . $baseurl . 'checkout/onepage/success";</script>';
                        break;
                    case 2:
                        $this->prepareTransactionData( $order, $Requestdata, 'declined' );
                        $this->messageManager->addNotice( 'Transaction has been declined.' );
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been declined, Reference: ' . $order->getIncrementId() ) )->setIsCustomerNotified( false );
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                        break;
                    case 0:
                    case 4:
                        $this->prepareTransactionData( $order, $Requestdata, 'cancelled' );
                        $this->messageManager->addNotice( 'Transaction has been cancelled' );
                        $this->_order->addStatusHistoryComment( __( 'Redirect Response, Transaction has been cancelled, Reference: ' . $order->getIncrementId() ) )->setIsCustomerNotified( false );
                        $this->_order->cancel()->save();
                        $this->_checkoutSession->restoreQuote();
                        echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
                        break;
                    default:
                        break;
                }
            }
        } catch ( \Magento\Framework\Exception\LocalizedException $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, $e->getMessage() );
            echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
        } catch ( \Exception $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, __( 'We can\'t start Dpo Checkout.' ) );
            echo '<script>window.top.location.href="' . $baseurl . 'checkout/cart/";</script>';
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
            $payment->setLastTransId( $paymentData['txn_id'] )
                ->setTransactionId( $paymentData['txn_id'] )
                ->setAdditionalInformation( [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array) $paymentData] );
            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __( 'The authorized amount is %1.', $formatedPrice );
            if ( $paymentData['status'] == "captured" ) {
                $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
            } else {
                $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_VOID;
            }
            // Get builder class
            $trans       = $this->_transactionBuilder;
            $transaction = $trans->setPayment( $payment )
                ->setOrder( $order )
                ->setTransactionId( $paymentData['txn_id'] )
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array) $paymentData['additional_data']]
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
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order         = $objectManager->get( '\Magento\Sales\Model\Order' )->loadByIncrementId( $incrementId );
        return $order;
    }

    public function getPaymentConfig( $field )
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $scopeConfig   = $objectManager->get( 'Magento\Framework\App\Config\ScopeConfigInterface' );
        return $scopeConfig->getValue( "payment/dpo/$field" );
    }
}
