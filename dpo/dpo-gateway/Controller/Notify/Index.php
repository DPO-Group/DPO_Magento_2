<?php
/** @noinspection PhpUnused */

/** @noinspection PhpUndefinedNamespaceInspection */

/*
 * Copyright (c) 2022 DPO Group
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

class Index extends AbstractDpo
{
    public int $storeId;
    /**
     * @var array|false
     */
    private array|false $dpo_data;

    /**
     * indexAction
     *
     */
    public function execute()
    {
        // Dpo API expects response of 'OK' for Notify function
        echo "OK";

        // Get notify data
        $this->dpo_data = $this->getPostData();


        if ( ! empty($this->dpo_data) && $this->securitySignatureIsValid()) {
            $this->updateOrderAdditionalPaymentInfo();
        }
    }

    //check if signature is valid

    public function getPostData(): bool|array
    {
        // Posted variables from ITN
        $nData = $_POST;

        // Strip any slashes in data
        foreach ($nData as $key => $val) {
            $nData[$key] = stripslashes($val);
        }

        // Return "false" if no data was received
        if (empty($nData)) {
            return (false);
        } else {
            return ($nData);
        }
    }

    #Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl
    public function getCustomerBeforeAuthUrl()
    {
        //  Class contains 1 abstract method and must therefore be declared abstract or implement the remaining methods
    }

    // Retrieve post data

    /**
     * saveInvoice
     *
     * @throws LocalizedException
     */
    protected function saveInvoice()
    {
        // Check for mail msg
        $invoice = $this->_order->prepareInvoice();

        $invoice->register()->capture();

        /**
         * @var Transaction $transaction
         */
        $transaction = $this->_transactionFactory->create();
        $transaction->addObject($invoice)
                    ->addObject($invoice->getOrder())
                    ->save();

        $this->_order->addStatusHistoryComment(__('Notified customer about invoice #%1.', $invoice->getIncrementId()));
        $this->_order->setIsCustomerNotified(true);
        $this->_order->save();
    }

    private function securitySignatureIsValid(): bool
    {
        $notify_data    = array();
        $checkSumParams = '';


        foreach ($this->dpo_data as $key => $val) {
            $notify_data[$key] = stripslashes($val);

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

        $checkSumParams = md5($checkSumParams);

        if ($checkSumParams != $notify_data['CHECKSUM']) {
            return false;
        }

        return true;
    }

    private function updateOrderAdditionalPaymentInfo()
    {
        $orderId       = $this->dpo_data['REFERENCE'];
        $this->_order  = $this->_orderFactory->create()->loadByIncrementId($orderId);
        $this->storeId = $this->_order->getStoreId();

        $status = $this->dpo_data['TRANSACTION_STATUS'];


        if ($status == 1) {
            $this->_order->setStatus(Order::STATE_PROCESSING);
            $this->_order->save();
            $this->_order->addStatusHistoryComment(
                "Notify Response, Transaction has been approved, TransactionID: " . $this->dpo_data['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            )->setIsCustomerNotified(false)->save();
        } elseif ($status == 2) {
            $this->_order->setStatus(Order::STATE_CANCELED);
            $this->_order->save();
            $this->_order->addStatusHistoryComment(
                "Notify Response, The User Failed to make Payment with Dpo due to transaction being declined, TransactionID: " . $this->dpo_data['TRANSACTION_ID'],
                Order::STATE_PROCESSING
            )->setIsCustomerNotified(false)->save();
        } elseif ($status == 0 || $status == 4) {
            $this->_order->setStatus(Order::STATE_CANCELED);
            $this->_order->save();
            $this->_order->addStatusHistoryComment(
                "Notify Response, The User Cancelled Payment with Dpo, PayRequestID: " . $this->dpo_data['PAY_REQUEST_ID'],
                Order::STATE_CANCELED
            )->setIsCustomerNotified(false)->save();
        }
    }
}
