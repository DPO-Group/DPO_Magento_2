<?php


/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

namespace Dpo\Dpo\Controller\Notify;

use Dpo\Dpo\Controller\AbstractDpo;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Framework\Controller\ResultFactory;

class Index extends AbstractDpo
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

        $this->order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getIncrementId()));
        $this->order->setIsCustomerNotified(true);
        $this->order->save();
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
        if ($this->getConfigData('test_mode') != '0') {
            $service_type = 'secret';
        } else {
            $service_type = $this->getConfigData('service_type');
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
     * @throws \Exception
     */
    private function updateOrderAdditionalPaymentInfo()
    {
        $orderId       = $this->dpoData['REFERENCE'];
        $this->order   = $this->orderFactory->create()->loadByIncrementId($orderId);
        $this->storeId = $this->order->getStoreId();

        $status = $this->dpoData['TRANSACTION_STATUS'];

        if ($status == 1) {
            $this->order->setStatus(Order::STATE_PROCESSING);
            $this->order->save();
            $this->order->addStatusHistoryComment(
                "Notify Response, Transaction has been approved, TransactionID: " . $this->dpoData['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            )->setIsCustomerNotified(false)->save();
        } elseif ($status == 2) {
            $this->order->setStatus(Order::STATE_CANCELED);
            $this->order->save();
            $this->order->addStatusHistoryComment(
                "Notify Response, The User Failed to make Payment with Dpo due to transaction being declined,
                 TransactionID: " . $this->dpoData['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            )->setIsCustomerNotified(false)->save();
        } elseif ($status == 0 || $status == 4) {
            $this->order->setStatus(Order::STATE_CANCELED);
            $this->order->save();
            $this->order->addStatusHistoryComment(
                "Notify Response, The User Cancelled Payment with Dpo, PayRequestID: "
                . $this->dpoData['PAY_REQUEST_ID'],
                Order::STATE_CANCELED
            )->setIsCustomerNotified(false)->save();
        }
    }

    /**
     * Get result factory
     *
     * @return ResultFactory
     */
    protected function getResultFactory()
    {
        return $this->resultFactory ?: $this->_objectManager->get(ResultFactory::class);
    }
}
