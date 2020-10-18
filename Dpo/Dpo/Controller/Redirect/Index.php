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
use Dpo\Dpo\Model\Config;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index extends AbstractDpo
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = Config::METHOD_CODE;

    /**
     * Execute
     */
    public function execute()
    {
        $pre = __METHOD__ . " : ";

        $page_object = $this->pageFactory->create();

        try {
            $this->_initCheckout();
        } catch ( \Magento\Framework\Exception\LocalizedException $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, $e->getMessage() );
            $this->_redirect( 'checkout/cart' );
        } catch ( \Exception $e ) {
            $this->_logger->error( $pre . $e->getMessage() );
            $this->messageManager->addExceptionMessage( $e, __( 'We can\'t start Dpo Checkout.' ) );
            $this->_redirect( 'checkout/cart' );
        }

        return $page_object;
    }


}
