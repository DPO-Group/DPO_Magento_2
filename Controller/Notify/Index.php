<?php


/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Notify;

use Exception;
use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\OrderFactory;
use Dpo\Dpo\Service\CheckoutProcessor;
use Magento\Sales\Api\OrderRepositoryInterface;

class Index
{
    /**
     * Store id as an interger
     *
     * @var int
     */
    public int $storeId;
    /**
     * DPO data
     *
     * @var array|false
     */
    private array|false $dpoData;
    /**
     * @var TransactionFactory
     */
    protected TransactionFactory $transactionFactory;
    /**
     * @var  Order $order
     */
    protected Order $order;
    /**
     * @var OrderFactory
     */
    protected OrderFactory $orderFactory;
    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;
    /**
     * @var ObjectManagerInterface
     */
    protected ObjectManagerInterface $objectManager;
    /**
     * @var CheckoutProcessor
     */
    private CheckoutProcessor $checkoutProcessor;
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @param TransactionFactory $transactionFactory
     * @param Order $order
     * @param OrderFactory $orderFactory
     * @param ResultFactory $resultFactory
     * @param ObjectManagerInterface $objectManager
     * @param CheckoutProcessor $checkoutProcessor
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        TransactionFactory $transactionFactory,
        Order $order,
        OrderFactory $orderFactory,
        ResultFactory $resultFactory,
        ObjectManagerInterface $objectManager,
        CheckoutProcessor $checkoutProcessor,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->transactionFactory = $transactionFactory;
        $this->order              = $order;
        $this->orderFactory       = $orderFactory;
        $this->resultFactory      = $resultFactory;
        $this->objectManager      = $objectManager;
        $this->checkoutProcessor  = $checkoutProcessor;
        $this->orderRepository    = $orderRepository;
    }


    /**
     * Executes the class methods
     */
    public function execute()
    {
        // Dpo API expects response of 'OK' for Notify function
        // Dpo API expects response of 'OK' for Notify function
        $resultFactory = $this->getResultFactory();
        $result        = $resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents("OK");

        // Get notify data
        $this->dpoData = $this->getPostData();

        if (!empty($this->dpoData) && $this->securitySignatureIsValid()) {
            $this->updateOrderAdditionalPaymentInfo();
        }
    }

    //check if signature is valid

    /**
     * Check if signature is valid
     *
     * @return bool|array
     */
    public function getPostData(): bool|array
    {
        // Posted variables from ITN
        $nData = $_POST;

        // Strip any slashes in data
        foreach ($nData as $key => $val) {
            $nData[$key] = str_replace('\\', '', $val);
        }

        // Return "false" if no data was received
        if (empty($nData)) {
            return false;
        } else {
            return $nData;
        }
    }

    #Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl

    /**
     *  Returns the URL where the customer should be redirected before authentication
     *
     * @return void
     */
    public function getCustomerBeforeAuthUrl()
    {
        //  Class contains 1 abstract method and must therefore be declared abstract or implement the remaining methods
    }

    // Retrieve post data

    /**
     * Saves the invoice
     *
     * @throws LocalizedException
     */
    protected function saveInvoice()
    {
        // Check for mail msg
        $invoice = $this->order->prepareInvoice();

        $invoice->register()->capture();

        /**
         * @var Transaction $transaction
         */
        $transaction = $this->transactionFactory->create();
        $transaction->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();

        $this->order->addCommentToStatusHistory(
            __('Notified customer about invoice #%1.', $invoice->getIncrementId())
        )->setIsCustomerNotified(true);

        $this->orderRepository->save($this->order);
    }

    /**
     * Validates the security signature
     *
     * @return bool
     */
    private function securitySignatureIsValid(): bool
    {
        $notify_data    = [];
        $checkSumParams = '';

        foreach ($this->dpoData as $key => $val) {
            $notify_data[$key] = str_replace('\\', '', $val);

            if ($key == 'DPO_ID') {
                $checkSumParams .= $val;
            }
            if ($key != 'CHECKSUM' && $key != 'DPO_ID') {
                $checkSumParams .= $val;
            }

            if (empty($notify_data)) {
                return false;
            }
        }
        if ($this->checkoutProcessor->getConfigData('test_mode') != '0') {
            $service_type = 'secret';
        } else {
            $service_type = $this->checkoutProcessor->getConfigData('service_type');
        }
        $checkSumParams .= $service_type;
        //@codingStandardsIgnoreStart
        $checkSumParams = md5($checkSumParams);
        //@codingStandardsIgnoreEnd
        if ($checkSumParams != $notify_data['CHECKSUM']) {
            return false;
        }

        return true;
    }

    /**
     * Updates the order with additional payment info
     *
     * @return void
     * @throws Exception
     */
    private function updateOrderAdditionalPaymentInfo()
    {
        $orderId       = $this->dpoData['REFERENCE'];
        $this->order   = $this->orderFactory->create()->loadByIncrementId($orderId);
        $this->storeId = $this->order->getStoreId();

        $status = $this->dpoData['TRANSACTION_STATUS'];

        if ($status == 1) {
            $this->order->setStatus(Order::STATE_PROCESSING);
            $this->orderRepository->save($this->order);

            $history = $this->order->addCommentToStatusHistory(
                "Notify Response, Transaction has been approved, TransactionID: " . $this->dpoData['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            );

            $history->setIsCustomerNotified(false);
            $this->orderRepository->save($this->order);
        } elseif ($status == 2) {
            $this->order->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($this->order);

            $history = $this->order->addCommentToStatusHistory(
                "Notify Response, The User Failed to make Payment with Dpo due to transaction being declined,
                 TransactionID: " . $this->dpoData['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            );

            $history->setIsCustomerNotified(false);
            $this->orderRepository->save($this->order);
        } elseif ($status == 0 || $status == 4) {
            $this->order->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($this->order);

            $history = $this->order->addCommentToStatusHistory(
                "Notify Response, The User Cancelled Payment with Dpo, PayRequestID: "
                . $this->dpoData['PAY_REQUEST_ID'],
                Order::STATE_CANCELED
            );

            $history->setIsCustomerNotified(false);
            $this->orderRepository->save($this->order);
        }
    }

    /**
     * Get result factory
     *
     * @return ResultFactory
     */
    protected function getResultFactory()
    {
        return $this->resultFactory ?: $this->objectManager->get(ResultFactory::class);
    }
}
