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
use Dpo\Dpo\Model\Config;
use Dpo\Dpo\Model\Dpo;
use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index extends AbstractDpo
{
    public const CARTURL = "checkout/cart";
    /**
     * @var PageFactory
     */
    protected PageFactory $resultPageFactory;
    /**
     * Config method type
     *
     * @var string|Dpo
     */
    protected string|Dpo $configMethod = Config::METHOD_CODE;

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->_initCheckout();
        } catch (LocalizedException $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
            $this->_redirect(self::CARTURL);
        } catch (Exception $e) {
            $this->logger->error($pre . $e->getMessage());
            $this->messageManager->addExceptionMessage($e, __('We can\'t start DPO Pay Checkout.'));
            $this->_redirect(self::CARTURL);
        }
        $block = $page_object->getLayout()->getBlock('dpo')->setPaymentFormData(null);

        $formData = $block->getFormData();
        if (isset($formData['success']) && $formData['success'] === false) {
            $this->messageManager->addErrorMessage(__($formData["resultExplanation"]));
            $this->_redirect(self::CARTURL);
        } elseif (!$formData) {
            $this->logger->error("We can\'t start DPO Pay Checkout.");
            $this->_redirect(self::CARTURL);
        }

        return $page_object;
    }

    #Magento\Checkout\Controller\Express\RedirectLoginInterface::getCustomerBeforeAuthUrl
    public function getCustomerBeforeAuthUrl()
    {
        //  Class contains 1 abstract method and must therefore be declared abstract or implement the remaining methods
    }
}
