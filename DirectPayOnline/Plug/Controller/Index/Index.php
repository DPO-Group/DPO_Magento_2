<?php
/**
 * Copyright © 2020 DPO Group. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace DirectPayOnline\Plug\Controller\Index;

use DirectPayOnline\Plug\Controller\Index\DirectPayCurl;
use Magento\Framework\UrlInterface;

/**
 * Main Controller
 */
class Index extends \Magento\Framework\App\Action\Action
{
    protected $_paymentPlugin;
    protected $_scopeConfig;
    protected $_session;
    protected $_order;
    protected $_messageManager;
    protected $_redirect;
    protected $_orderId;
    protected $_storeManager;
    protected $_orderManagement;
    protected $_urlBuilder;
    protected $_billingDetails;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \DirectPayOnline\Plug\Model\Payment $paymentPlugin,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $session,
        \Magento\Sales\Model\Order $order,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        UrlInterface $urlBuilder
    ) {
        $this->_paymentPlugin   = $paymentPlugin;
        $this->_scopeConfig     = $scopeConfig;
        $this->_session         = $session;
        $this->_order           = $order;
        $this->_messageManager  = $messageManager;
        $this->_storeManager    = $storeManager;
        $this->_orderManagement = $orderManagement;
        $this->_urlBuilder      = $urlBuilder;
        parent::__construct( $context );
    }

    public function execute()
    {
        /** check if isset success from 3g gateway */
        $success = filter_input( INPUT_GET, 'success' );
        $cancel  = filter_input( INPUT_GET, 'cancel' );

        if ( isset( $success ) && !empty( $success ) ) {

            $orderId          = $success;
            $transactionToken = filter_input( INPUT_GET, 'TransactionToken' );
            $this->verifyTokenResponse( $transactionToken, $orderId );
        }
        /** check if isset cancel from 3g gateway */
        elseif ( isset( $cancel ) && !empty( $cancel ) ) {

            $orderId      = $cancel;
            $errorMessage = _( 'Payment canceled by customer' );
            $this->restoreOrderToCart( $errorMessage, $orderId );
        } else {
            /** @var \Magento\Checkout\Model\Session  $session*/
            $orderId = $this->_session->getLastRealOrderId();

            if ( !isset( $orderId ) || !$orderId ) {
                $message = 'Invalid order ID, please try again later';
                /** @var  \Magento\Framework\Message\ManagerInterface $messageManager */
                $this->_messageManager->addError( $message );
                return $this->_redirect( 'checkout/cart' );
            }

            $comment = 'Payment has not been processed yet';
            $this->setCommentToOrder( $orderId, $comment );

            /** @var  \Magento\Sales\Api\OrderManagementInterface $orderManagement */
            $this->_orderManagement->hold( $orderId ); //cancel the order

            $this->_orderId = $orderId;
            $billingDetails = $this->getBillingDetailsByOrderId( $orderId );
            $configDetails  = $this->getPaymentConfig();

            /** Set new directPayCurl object */
            $directPayCurl = new DirectPayCurl( $billingDetails, $configDetails );
            $response      = $directPayCurl->directPaytTokenResult();
            $this->checkDirectPayResponse( $response );

            $writer = new \Zend\Log\Writer\Stream( BP . '/var/log/returnResponse.log' );
            $logger = new \Zend\Log\Logger();
            $logger->addWriter( $writer );
            $logger->info( $response ); // Simple Text Log

        }

    }

    public function setCommentToOrder( $orderId, $comment )
    {
        $order = $this->_order->load( $orderId );
        $order->addStatusHistoryComment( $comment );
        $order->save();
    }

    public function verifyTokenResponse( $transactionToken, $orderId )
    {
        if ( !isset( $transactionToken ) ) {
            $errorMessage = _( 'Transaction Token error, please contact support center' );
            $this->restoreOrderToCart( $errorMessage, $orderId );
        }

        /** get verify token response from 3g */
        $response = $this->verifyToken( $transactionToken );
        if ( $response ) {

            if ( $response->Result[0] == '000' ) {

                $this->_orderManagement->unHold( $orderId );
                $comment = 'Payment has been processed successfully';
                $this->setCommentToOrder( $orderId, $comment );
                return $this->_redirect( 'checkout/onepage/success' );
            } else {

                $errorCode    = $response->Result[0];
                $errorDesc    = $response->ResultExplanation[0];
                $errorMessage = _( 'Payment Failed: ' . $errorCode . ', ' . $errorDesc );
                $this->restoreOrderToCart( $errorMessage, $orderId );
            }
        }
    }

    /**
     * Verify paymnet token from 3G
     */
    public function verifyToken( $transactionToken )
    {
        $configDetails = $this->getPaymentConfig();

        $inputXml = '<?xml version="1.0" encoding="utf-8"?>
                    <API3G>
                      <CompanyToken>' . $configDetails['company_token'] . '</CompanyToken>
                      <Request>verifyToken</Request>
                      <TransactionToken>' . $transactionToken . '</TransactionToken>
                    </API3G>';

        $url = $configDetails['gateway_url'] . "/API/v6/";

        $writer = new \Zend\Log\Writer\Stream( BP . '/var/log/verifyToken.log' );
        $logger = new \Zend\Log\Logger();
        $logger->addWriter( $writer );
        $logger->info( $inputXml ); // Simple Text Log

        $ch = curl_init();

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: text/xml' ) );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $inputXml );

        $response = curl_exec( $ch );

        curl_close( $ch );

        if ( $response !== false ) {
            /** convert the XML result into array *///$response = mb_convert_encoding($response, 'ISO-8859-1' /*, $optionalOriginalEncoding */);
            $xml = simplexml_load_string( $response );
            return $xml;
        }
        return false;
    }
    /**
     * Check Direct pay response for the first request
     */
    public function checkDirectPayResponse( $response )
    {
        if ( $response === false ) {

            /** cancel order and restore quote with error message */
            $errorMessage = _( 'Payment error: Unable to connect to the payment gateway, please try again later' );
            $this->restoreOrderToCart( $errorMessage, $this->_orderId );
        } else {
            /** manage xml response */
            $this->getXmlResponse( $response );
        }
    }
    /**
     * Get and check first xml response
     */
    public function getXmlResponse( $response )
    {
        /** convert the XML result into array */
        $xml = simplexml_load_string( $response );

        /** if the result have error, cancel the order */
        if ( $xml->Result[0] != '000' ) {
            /**  create error message */
            $errorMessage = _( 'Payment error code: ' . $xml->Result[0] . ', ' . $xml->ResultExplanation[0] );
            /** cancel the order */
            $this->restoreOrderToCart( $errorMessage, $this->_orderId );
        }

        /** get 3G gateway paymnet URL from config */
        $param = $this->getPaymentConfig();
        /** create url to redirect */
        $paymnetURL = $param['gateway_url'] . "/payv2.php?ID=" . $xml->TransToken[0];

        return $this->_redirect( $paymnetURL );
    }
    /**
     * Restore quote and cancel the order
     *
     */
    public function restoreOrderToCart( $errorMessage, $orderId )
    {

        /** @var \Magento\Sales\Api\OrderManagementInterface $orderManagement */
        $this->_orderManagement->unHold( $orderId ); //remove from hold

        $this->_orderManagement->cancel( $orderId ); //cancel the order
        /** add msg to cancel */
        $this->setCommentToOrder( $orderId, $errorMessage );

        /** @var \Magento\Checkout\Model\Session $session */
        $this->_session->restoreQuote(); //Restore quote

        /** show error message on checkout/cart */
        $this->_messageManager->addError( $errorMessage );

        /** and redirect to chechout /cart*/
        return $this->_redirect( 'checkout/cart' );
    }

    /**
     * Get Billing Details By Order Id
     * @return array $param
     */
    public function getBillingDetailsByOrderId( $orderId )
    {
        /** @var Magento\Sales\Model\Order $order */
        $order_information = $this->_order->loadByIncrementId( $orderId );
        $billingDetails    = $order_information->getBillingAddress();
        $ordered_items     = $order_information->getAllItems();

        $customerData     = $this->getCustomerDialCode( $billingDetails );
        $customerDialCode = $customerData['customerDialCode'];
        $customerPhone    = $customerData['customerPhone'];

        /** New products array */
        $productsArr = [];

        foreach ( $ordered_items as $key => $item ) {
            /** Product name */
            $productsArr[$key] = $item->getName();
        }

        $param = [
            'order_id'    => $orderId,
            'amount'      => number_format( $order_information->getGrandTotal(), 2, '.', '' ),
            'currency'    => $this->_storeManager->getStore()->getCurrentCurrency()->getCode(),
            'first_name'  => $billingDetails->getFirstName(),
            'last_name'   => $billingDetails->getLastname(),
            'email'       => $billingDetails->getEmail(),
            'phone'       => $customerPhone,
            'address'     => $billingDetails->getStreetLine( 1 ),
            'city'        => $billingDetails->getCity(),
            'zipcode'     => $billingDetails->getPostcode(),
            'country'     => $billingDetails->getCountryId(),
            'dialcode'    => $customerDialCode,
            'redirectURL' => $this->_urlBuilder->getUrl( 'directpayonline/index/index?success=' . $orderId ),
            'backURL'     => $this->_urlBuilder->getUrl( 'directpayonline/index/index?cancel=' . $orderId ),
            'products'    => $productsArr,
        ];

        return $param;
    }

    private function getCustomerDialCode( $addressDetails )
    {
        $telephone = $addressDetails->getTelephone();
        $telephone = str_replace( ' ', '', $telephone );

        if ( preg_match( '/^0([\d]+)/', $telephone, $m ) === 1 ) {
            return [
                'customerDialCode' => $addressDetails->getCountryId(),
                'customerPhone'    => $m[1],
            ];
        }

        if ( preg_match( '/^\+/', $telephone ) === 1 ) {
            foreach ( $this->dial_codes as $dial_code ) {
                $pattern = '/^\+' . $dial_code['dial'] . '([\d]+)/';
                if ( preg_match( $pattern, $telephone, $m ) === 1 ) {
                    return [
                        'customerDialCode' => $dial_code['alpha2'],
                        'customerPhone'    => $m[1],
                    ];
                }
            }
        }

        return [
            'customerDialCode' => $addressDetails->getCountryId(),
            'customerPhone'    => $telephone,
        ];
    }

    /**
     * Get configuration values (Store -> Sales -> Payment Method ->DirectPayModule)
     * @return array $paramArr
     */
    public function getPaymentConfig()
    {
        /** get types of configuration */
        $param = $this->configArr();
        /** create new array */
        $paramArr = [];

        foreach ( $param as $single_param ) {
            /** get config values */
            $paramArr[$single_param] = $this->_scopeConfig->getValue( 'payment/directpayonline_plug/' . $single_param, \Magento\Store\Model\ScopeInterface::SCOPE_STORE );
        }

        return $paramArr;
    }
    /**
     * Set Configuration Array
     * @return array $param
     */
    public function configArr()
    {
        $param = ['active', 'company_token', 'gateway_url', 'ptl_type', 'ptl', 'service_type'];
        return $param;
    }

    /**
     * @var \string[][]
     */
    private $dial_codes = [
        [
            "alpha2" => "AD",
            "name"   => "Andorra",
            "dial"   => "376",
        ],
        [
            "alpha2" => "AE",
            "name"   => "United Arab Emirates",
            "dial"   => "971",
        ],
        [
            "alpha2" => "AF",
            "name"   => "Afghanistan",
            "dial"   => "93",
        ],
        [
            "alpha2" => "AG",
            "name"   => "Antigua & Barbuda",
            "dial"   => "1-268",
        ],
        [
            "alpha2" => "AI",
            "name"   => "Anguilla",
            "dial"   => "1-264",
        ],
        [
            "alpha2" => "AL",
            "name"   => "Albania",
            "dial"   => "355",
        ],
        [
            "alpha2" => "AM",
            "name"   => "Armenia",
            "dial"   => "374",
        ],
        [
            "alpha2" => "AO",
            "name"   => "Angola",
            "dial"   => "244",
        ],
        [
            "alpha2" => "AQ",
            "name"   => "Antarctica",
            "dial"   => "672",
        ],
        [
            "alpha2" => "AR",
            "name"   => "Argentina",
            "dial"   => "54",
        ],
        [
            "alpha2" => "AS",
            "name"   => "American Samoa",
            "dial"   => "1-684",
        ],
        [
            "alpha2" => "AT",
            "name"   => "Austria",
            "dial"   => "43",
        ],
        [
            "alpha2" => "AU",
            "name"   => "Australia",
            "dial"   => "61",
        ],
        [
            "alpha2" => "AW",
            "name"   => "Aruba",
            "dial"   => "297",
        ],
        [
            "alpha2" => "AX",
            "name"   => "Åland Islands",
            "dial"   => "358",
        ],
        [
            "alpha2" => "AZ",
            "name"   => "Azerbaijan",
            "dial"   => "994",
        ],
        [
            "alpha2" => "BA",
            "name"   => "Bosnia",
            "dial"   => "387",
        ],
        [
            "alpha2" => "BB",
            "name"   => "Barbados",
            "dial"   => "1-246",
        ],
        [
            "alpha2" => "BD",
            "name"   => "Bangladesh",
            "dial"   => "880",
        ],
        [
            "alpha2" => "BE",
            "name"   => "Belgium",
            "dial"   => "32",
        ],
        [
            "alpha2" => "BF",
            "name"   => "Burkina Faso",
            "dial"   => "226",
        ],
        [
            "alpha2" => "BG",
            "name"   => "Bulgaria",
            "dial"   => "359",
        ],
        [
            "alpha2" => "BH",
            "name"   => "Bahrain",
            "dial"   => "973",
        ],
        [
            "alpha2" => "BI",
            "name"   => "Burundi",
            "dial"   => "257",
        ],
        [
            "alpha2" => "BJ",
            "name"   => "Benin",
            "dial"   => "229",
        ],
        [
            "alpha2" => "BL",
            "name"   => "St. Barthélemy",
            "dial"   => "590",
        ],
        [
            "alpha2" => "BM",
            "name"   => "Bermuda",
            "dial"   => "1-441",
        ],
        [
            "alpha2" => "BN",
            "name"   => "Brunei",
            "dial"   => "673",
        ],
        [
            "alpha2" => "BO",
            "name"   => "Bolivia",
            "dial"   => "591",
        ],
        [
            "alpha2" => "BQ",
            "name"   => "Caribbean Netherlands",
            "dial"   => "599",
        ],
        [
            "alpha2" => "BR",
            "name"   => "Brazil",
            "dial"   => "55",
        ],
        [
            "alpha2" => "BS",
            "name"   => "Bahamas",
            "dial"   => "1-242",
        ],
        [
            "alpha2" => "BT",
            "name"   => "Bhutan",
            "dial"   => "975",
        ],
        [
            "alpha2" => "BV",
            "name"   => "Bouvet Island",
            "dial"   => "47",
        ],
        [
            "alpha2" => "BW",
            "name"   => "Botswana",
            "dial"   => "267",
        ],
        [
            "alpha2" => "BY",
            "name"   => "Belarus",
            "dial"   => "375",
        ],
        [
            "alpha2" => "BZ",
            "name"   => "Belize",
            "dial"   => "501",
        ],
        [
            "alpha2" => "CA",
            "name"   => "Canada",
            "dial"   => "1",
        ],
        [
            "alpha2" => "CC",
            "name"   => "Cocos (Keeling) Islands",
            "dial"   => "61",
        ],
        [
            "alpha2" => "CD",
            "name"   => "Congo - Kinshasa",
            "dial"   => "243",
        ],
        [
            "alpha2" => "CF",
            "name"   => "Central African Republic",
            "dial"   => "236",
        ],
        [
            "alpha2" => "CG",
            "name"   => "Congo - Brazzaville",
            "dial"   => "242",
        ],
        [
            "alpha2" => "CH",
            "name"   => "Switzerland",
            "dial"   => "41",
        ],
        [
            "alpha2" => "CI",
            "name"   => "Côte d’Ivoire",
            "dial"   => "225",
        ],
        [
            "alpha2" => "CK",
            "name"   => "Cook Islands",
            "dial"   => "682",
        ],
        [
            "alpha2" => "CL",
            "name"   => "Chile",
            "dial"   => "56",
        ],
        [
            "alpha2" => "CM",
            "name"   => "Cameroon",
            "dial"   => "237",
        ],
        [
            "alpha2" => "CN",
            "name"   => "China",
            "dial"   => "86",
        ],
        [
            "alpha2" => "CO",
            "name"   => "Colombia",
            "dial"   => "57",
        ],
        [
            "alpha2" => "CR",
            "name"   => "Costa Rica",
            "dial"   => "506",
        ],
        [
            "alpha2" => "CU",
            "name"   => "Cuba",
            "dial"   => "53",
        ],
        [
            "alpha2" => "CV",
            "name"   => "Cape Verde",
            "dial"   => "238",
        ],
        [
            "alpha2" => "CW",
            "name"   => "Curaçao",
            "dial"   => "599",
        ],
        [
            "alpha2" => "CX",
            "name"   => "Christmas Island",
            "dial"   => "61",
        ],
        [
            "alpha2" => "CY",
            "name"   => "Cyprus",
            "dial"   => "357",
        ],
        [
            "alpha2" => "CZ",
            "name"   => "Czechia",
            "dial"   => "420",
        ],
        [
            "alpha2" => "DE",
            "name"   => "Germany",
            "dial"   => "49",
        ],
        [
            "alpha2" => "DJ",
            "name"   => "Djibouti",
            "dial"   => "253",
        ],
        [
            "alpha2" => "DK",
            "name"   => "Denmark",
            "dial"   => "45",
        ],
        [
            "alpha2" => "DM",
            "name"   => "Dominica",
            "dial"   => "1-767",
        ],
        [
            "alpha2" => "DO",
            "name"   => "Dominican Republic",
            "dial"   => "1-809,1-829,1-849",
        ],
        [
            "alpha2" => "DZ",
            "name"   => "Algeria",
            "dial"   => "213",
        ],
        [
            "alpha2" => "EC",
            "name"   => "Ecuador",
            "dial"   => "593",
        ],
        [
            "alpha2" => "EE",
            "name"   => "Estonia",
            "dial"   => "372",
        ],
        [
            "alpha2" => "EG",
            "name"   => "Egypt",
            "dial"   => "20",
        ],
        [
            "alpha2" => "EH",
            "name"   => "Western Sahara",
            "dial"   => "212",
        ],
        [
            "alpha2" => "ER",
            "name"   => "Eritrea",
            "dial"   => "291",
        ],
        [
            "alpha2" => "ES",
            "name"   => "Spain",
            "dial"   => "34",
        ],
        [
            "alpha2" => "ET",
            "name"   => "Ethiopia",
            "dial"   => "251",
        ],
        [
            "alpha2" => "FI",
            "name"   => "Finland",
            "dial"   => "358",
        ],
        [
            "alpha2" => "FJ",
            "name"   => "Fiji",
            "dial"   => "679",
        ],
        [
            "alpha2" => "FK",
            "name"   => "Falkland Islands",
            "dial"   => "500",
        ],
        [
            "alpha2" => "FM",
            "name"   => "Micronesia",
            "dial"   => "691",
        ],
        [
            "alpha2" => "FO",
            "name"   => "Faroe Islands",
            "dial"   => "298",
        ],
        [
            "alpha2" => "FR",
            "name"   => "France",
            "dial"   => "33",
        ],
        [
            "alpha2" => "GA",
            "name"   => "Gabon",
            "dial"   => "241",
        ],
        [
            "alpha2" => "GB",
            "name"   => "UK",
            "dial"   => "44",
        ],
        [
            "alpha2" => "GD",
            "name"   => "Grenada",
            "dial"   => "1-473",
        ],
        [
            "alpha2" => "GE",
            "name"   => "Georgia",
            "dial"   => "995",
        ],
        [
            "alpha2" => "GF",
            "name"   => "French Guiana",
            "dial"   => "594",
        ],
        [
            "alpha2" => "GG",
            "name"   => "Guernsey",
            "dial"   => "44",
        ],
        [
            "alpha2" => "GH",
            "name"   => "Ghana",
            "dial"   => "233",
        ],
        [
            "alpha2" => "GI",
            "name"   => "Gibraltar",
            "dial"   => "350",
        ],
        [
            "alpha2" => "GL",
            "name"   => "Greenland",
            "dial"   => "299",
        ],
        [
            "alpha2" => "GM",
            "name"   => "Gambia",
            "dial"   => "220",
        ],
        [
            "alpha2" => "GN",
            "name"   => "Guinea",
            "dial"   => "224",
        ],
        [
            "alpha2" => "GP",
            "name"   => "Guadeloupe",
            "dial"   => "590",
        ],
        [
            "alpha2" => "GQ",
            "name"   => "Equatorial Guinea",
            "dial"   => "240",
        ],
        [
            "alpha2" => "GR",
            "name"   => "Greece",
            "dial"   => "30",
        ],
        [
            "alpha2" => "GS",
            "name"   => "South Georgia & South Sandwich Islands",
            "dial"   => "500",
        ],
        [
            "alpha2" => "GT",
            "name"   => "Guatemala",
            "dial"   => "502",
        ],
        [
            "alpha2" => "GU",
            "name"   => "Guam",
            "dial"   => "1-671",
        ],
        [
            "alpha2" => "GW",
            "name"   => "Guinea-Bissau",
            "dial"   => "245",
        ],
        [
            "alpha2" => "GY",
            "name"   => "Guyana",
            "dial"   => "592",
        ],
        [
            "alpha2" => "HK",
            "name"   => "Hong Kong",
            "dial"   => "852",
        ],
        [
            "alpha2" => "HM",
            "name"   => "Heard & McDonald Islands",
            "dial"   => "672",
        ],
        [
            "alpha2" => "HN",
            "name"   => "Honduras",
            "dial"   => "504",
        ],
        [
            "alpha2" => "HR",
            "name"   => "Croatia",
            "dial"   => "385",
        ],
        [
            "alpha2" => "HT",
            "name"   => "Haiti",
            "dial"   => "509",
        ],
        [
            "alpha2" => "HU",
            "name"   => "Hungary",
            "dial"   => "36",
        ],
        [
            "alpha2" => "ID",
            "name"   => "Indonesia",
            "dial"   => "62",
        ],
        [
            "alpha2" => "IE",
            "name"   => "Ireland",
            "dial"   => "353",
        ],
        [
            "alpha2" => "IL",
            "name"   => "Israel",
            "dial"   => "972",
        ],
        [
            "alpha2" => "IM",
            "name"   => "Isle of Man",
            "dial"   => "44",
        ],
        [
            "alpha2" => "IN",
            "name"   => "India",
            "dial"   => "91",
        ],
        [
            "alpha2" => "IO",
            "name"   => "British Indian Ocean Territory",
            "dial"   => "246",
        ],
        [
            "alpha2" => "IQ",
            "name"   => "Iraq",
            "dial"   => "964",
        ],
        [
            "alpha2" => "IR",
            "name"   => "Iran",
            "dial"   => "98",
        ],
        [
            "alpha2" => "IS",
            "name"   => "Iceland",
            "dial"   => "354",
        ],
        [
            "alpha2" => "IT",
            "name"   => "Italy",
            "dial"   => "39",
        ],
        [
            "alpha2" => "JE",
            "name"   => "Jersey",
            "dial"   => "44",
        ],
        [
            "alpha2" => "JM",
            "name"   => "Jamaica",
            "dial"   => "1-876",
        ],
        [
            "alpha2" => "JO",
            "name"   => "Jordan",
            "dial"   => "962",
        ],
        [
            "alpha2" => "JP",
            "name"   => "Japan",
            "dial"   => "81",
        ],
        [
            "alpha2" => "KE",
            "name"   => "Kenya",
            "dial"   => "254",
        ],
        [
            "alpha2" => "KG",
            "name"   => "Kyrgyzstan",
            "dial"   => "996",
        ],
        [
            "alpha2" => "KH",
            "name"   => "Cambodia",
            "dial"   => "855",
        ],
        [
            "alpha2" => "KI",
            "name"   => "Kiribati",
            "dial"   => "686",
        ],
        [
            "alpha2" => "KM",
            "name"   => "Comoros",
            "dial"   => "269",
        ],
        [
            "alpha2" => "KN",
            "name"   => "St. Kitts & Nevis",
            "dial"   => "1-869",
        ],
        [
            "alpha2" => "KP",
            "name"   => "North Korea",
            "dial"   => "850",
        ],
        [
            "alpha2" => "KR",
            "name"   => "South Korea",
            "dial"   => "82",
        ],
        [
            "alpha2" => "KW",
            "name"   => "Kuwait",
            "dial"   => "965",
        ],
        [
            "alpha2" => "KY",
            "name"   => "Cayman Islands",
            "dial"   => "1-345",
        ],
        [
            "alpha2" => "KZ",
            "name"   => "Kazakhstan",
            "dial"   => "7",
        ],
        [
            "alpha2" => "LA",
            "name"   => "Laos",
            "dial"   => "856",
        ],
        [
            "alpha2" => "LB",
            "name"   => "Lebanon",
            "dial"   => "961",
        ],
        [
            "alpha2" => "LC",
            "name"   => "St. Lucia",
            "dial"   => "1-758",
        ],
        [
            "alpha2" => "LI",
            "name"   => "Liechtenstein",
            "dial"   => "423",
        ],
        [
            "alpha2" => "LK",
            "name"   => "Sri Lanka",
            "dial"   => "94",
        ],
        [
            "alpha2" => "LR",
            "name"   => "Liberia",
            "dial"   => "231",
        ],
        [
            "alpha2" => "LS",
            "name"   => "Lesotho",
            "dial"   => "266",
        ],
        [
            "alpha2" => "LT",
            "name"   => "Lithuania",
            "dial"   => "370",
        ],
        [
            "alpha2" => "LU",
            "name"   => "Luxembourg",
            "dial"   => "352",
        ],
        [
            "alpha2" => "LV",
            "name"   => "Latvia",
            "dial"   => "371",
        ],
        [
            "alpha2" => "LY",
            "name"   => "Libya",
            "dial"   => "218",
        ],
        [
            "alpha2" => "MA",
            "name"   => "Morocco",
            "dial"   => "212",
        ],
        [
            "alpha2" => "MC",
            "name"   => "Monaco",
            "dial"   => "377",
        ],
        [
            "alpha2" => "MD",
            "name"   => "Moldova",
            "dial"   => "373",
        ],
        [
            "alpha2" => "ME",
            "name"   => "Montenegro",
            "dial"   => "382",
        ],
        [
            "alpha2" => "MF",
            "name"   => "St. Martin",
            "dial"   => "590",
        ],
        [
            "alpha2" => "MG",
            "name"   => "Madagascar",
            "dial"   => "261",
        ],
        [
            "alpha2" => "MH",
            "name"   => "Marshall Islands",
            "dial"   => "692",
        ],
        [
            "alpha2" => "MK",
            "name"   => "Macedonia",
            "dial"   => "389",
        ],
        [
            "alpha2" => "ML",
            "name"   => "Mali",
            "dial"   => "223",
        ],
        [
            "alpha2" => "MM",
            "name"   => "Myanmar",
            "dial"   => "95",
        ],
        [
            "alpha2" => "MN",
            "name"   => "Mongolia",
            "dial"   => "976",
        ],
        [
            "alpha2" => "MO",
            "name"   => "Macau",
            "dial"   => "853",
        ],
        [
            "alpha2" => "MP",
            "name"   => "Northern Mariana Islands",
            "dial"   => "1-670",
        ],
        [
            "alpha2" => "MQ",
            "name"   => "Martinique",
            "dial"   => "596",
        ],
        [
            "alpha2" => "MR",
            "name"   => "Mauritania",
            "dial"   => "222",
        ],
        [
            "alpha2" => "MS",
            "name"   => "Montserrat",
            "dial"   => "1-664",
        ],
        [
            "alpha2" => "MT",
            "name"   => "Malta",
            "dial"   => "356",
        ],
        [
            "alpha2" => "MU",
            "name"   => "Mauritius",
            "dial"   => "230",
        ],
        [
            "alpha2" => "MV",
            "name"   => "Maldives",
            "dial"   => "960",
        ],
        [
            "alpha2" => "MW",
            "name"   => "Malawi",
            "dial"   => "265",
        ],
        [
            "alpha2" => "MX",
            "name"   => "Mexico",
            "dial"   => "52",
        ],
        [
            "alpha2" => "MY",
            "name"   => "Malaysia",
            "dial"   => "60",
        ],
        [
            "alpha2" => "MZ",
            "name"   => "Mozambique",
            "dial"   => "258",
        ],
        [
            "alpha2" => "NA",
            "name"   => "Namibia",
            "dial"   => "264",
        ],
        [
            "alpha2" => "NC",
            "name"   => "New Caledonia",
            "dial"   => "687",
        ],
        [
            "alpha2" => "NE",
            "name"   => "Niger",
            "dial"   => "227",
        ],
        [
            "alpha2" => "NF",
            "name"   => "Norfolk Island",
            "dial"   => "672",
        ],
        [
            "alpha2" => "NG",
            "name"   => "Nigeria",
            "dial"   => "234",
        ],
        [
            "alpha2" => "NI",
            "name"   => "Nicaragua",
            "dial"   => "505",
        ],
        [
            "alpha2" => "NL",
            "name"   => "Netherlands",
            "dial"   => "31",
        ],
        [
            "alpha2" => "NO",
            "name"   => "Norway",
            "dial"   => "47",
        ],
        [
            "alpha2" => "NP",
            "name"   => "Nepal",
            "dial"   => "977",
        ],
        [
            "alpha2" => "NR",
            "name"   => "Nauru",
            "dial"   => "674",
        ],
        [
            "alpha2" => "NU",
            "name"   => "Niue",
            "dial"   => "683",
        ],
        [
            "alpha2" => "NZ",
            "name"   => "New Zealand",
            "dial"   => "64",
        ],
        [
            "alpha2" => "OM",
            "name"   => "Oman",
            "dial"   => "968",
        ],
        [
            "alpha2" => "PA",
            "name"   => "Panama",
            "dial"   => "507",
        ],
        [
            "alpha2" => "PE",
            "name"   => "Peru",
            "dial"   => "51",
        ],
        [
            "alpha2" => "PF",
            "name"   => "French Polynesia",
            "dial"   => "689",
        ],
        [
            "alpha2" => "PG",
            "name"   => "Papua New Guinea",
            "dial"   => "675",
        ],
        [
            "alpha2" => "PH",
            "name"   => "Philippines",
            "dial"   => "63",
        ],
        [
            "alpha2" => "PK",
            "name"   => "Pakistan",
            "dial"   => "92",
        ],
        [
            "alpha2" => "PL",
            "name"   => "Poland",
            "dial"   => "48",
        ],
        [
            "alpha2" => "PM",
            "name"   => "St. Pierre & Miquelon",
            "dial"   => "508",
        ],
        [
            "alpha2" => "PN",
            "name"   => "Pitcairn Islands",
            "dial"   => "870",
        ],
        [
            "alpha2" => "PR",
            "name"   => "Puerto Rico",
            "dial"   => "1",
        ],
        [
            "alpha2" => "PS",
            "name"   => "Palestine",
            "dial"   => "970",
        ],
        [
            "alpha2" => "PT",
            "name"   => "Portugal",
            "dial"   => "351",
        ],
        [
            "alpha2" => "PW",
            "name"   => "Palau",
            "dial"   => "680",
        ],
        [
            "alpha2" => "PY",
            "name"   => "Paraguay",
            "dial"   => "595",
        ],
        [
            "alpha2" => "QA",
            "name"   => "Qatar",
            "dial"   => "974",
        ],
        [
            "alpha2" => "RE",
            "name"   => "Réunion",
            "dial"   => "262",
        ],
        [
            "alpha2" => "RO",
            "name"   => "Romania",
            "dial"   => "40",
        ],
        [
            "alpha2" => "RS",
            "name"   => "Serbia",
            "dial"   => "381",
        ],
        [
            "alpha2" => "RU",
            "name"   => "Russia",
            "dial"   => "7",
        ],
        [
            "alpha2" => "RW",
            "name"   => "Rwanda",
            "dial"   => "250",
        ],
        [
            "alpha2" => "SA",
            "name"   => "Saudi Arabia",
            "dial"   => "966",
        ],
        [
            "alpha2" => "SB",
            "name"   => "Solomon Islands",
            "dial"   => "677",
        ],
        [
            "alpha2" => "SC",
            "name"   => "Seychelles",
            "dial"   => "248",
        ],
        [
            "alpha2" => "SD",
            "name"   => "Sudan",
            "dial"   => "249",
        ],
        [
            "alpha2" => "SE",
            "name"   => "Sweden",
            "dial"   => "46",
        ],
        [
            "alpha2" => "SG",
            "name"   => "Singapore",
            "dial"   => "65",
        ],
        [
            "alpha2" => "SH",
            "name"   => "St. Helena",
            "dial"   => "290",
        ],
        [
            "alpha2" => "SI",
            "name"   => "Slovenia",
            "dial"   => "386",
        ],
        [
            "alpha2" => "SJ",
            "name"   => "Svalbard & Jan Mayen",
            "dial"   => "47",
        ],
        [
            "alpha2" => "SK",
            "name"   => "Slovakia",
            "dial"   => "421",
        ],
        [
            "alpha2" => "SL",
            "name"   => "Sierra Leone",
            "dial"   => "232",
        ],
        [
            "alpha2" => "SM",
            "name"   => "San Marino",
            "dial"   => "378",
        ],
        [
            "alpha2" => "SN",
            "name"   => "Senegal",
            "dial"   => "221",
        ],
        [
            "alpha2" => "SO",
            "name"   => "Somalia",
            "dial"   => "252",
        ],
        [
            "alpha2" => "SR",
            "name"   => "Suriname",
            "dial"   => "597",
        ],
        [
            "alpha2" => "SS",
            "name"   => "South Sudan",
            "dial"   => "211",
        ],
        [
            "alpha2" => "ST",
            "name"   => "São Tomé & Príncipe",
            "dial"   => "239",
        ],
        [
            "alpha2" => "SV",
            "name"   => "El Salvador",
            "dial"   => "503",
        ],
        [
            "alpha2" => "SX",
            "name"   => "Sint Maarten",
            "dial"   => "1-721",
        ],
        [
            "alpha2" => "SY",
            "name"   => "Syria",
            "dial"   => "963",
        ],
        [
            "alpha2" => "SZ",
            "name"   => "Swaziland",
            "dial"   => "268",
        ],
        [
            "alpha2" => "TC",
            "name"   => "Turks & Caicos Islands",
            "dial"   => "1-649",
        ],
        [
            "alpha2" => "TD",
            "name"   => "Chad",
            "dial"   => "235",
        ],
        [
            "alpha2" => "TF",
            "name"   => "French Southern Territories",
            "dial"   => "262",
        ],
        [
            "alpha2" => "TG",
            "name"   => "Togo",
            "dial"   => "228",
        ],
        [
            "alpha2" => "TH",
            "name"   => "Thailand",
            "dial"   => "66",
        ],
        [
            "alpha2" => "TJ",
            "name"   => "Tajikistan",
            "dial"   => "992",
        ],
        [
            "alpha2" => "TK",
            "name"   => "Tokelau",
            "dial"   => "690",
        ],
        [
            "alpha2" => "TL",
            "name"   => "Timor-Leste",
            "dial"   => "670",
        ],
        [
            "alpha2" => "TM",
            "name"   => "Turkmenistan",
            "dial"   => "993",
        ],
        [
            "alpha2" => "TN",
            "name"   => "Tunisia",
            "dial"   => "216",
        ],
        [
            "alpha2" => "TO",
            "name"   => "Tonga",
            "dial"   => "676",
        ],
        [
            "alpha2" => "TR",
            "name"   => "Turkey",
            "dial"   => "90",
        ],
        [
            "alpha2" => "TT",
            "name"   => "Trinidad & Tobago",
            "dial"   => "1-868",
        ],
        [
            "alpha2" => "TV",
            "name"   => "Tuvalu",
            "dial"   => "688",
        ],
        [
            "alpha2" => "TW",
            "name"   => "Taiwan",
            "dial"   => "886",
        ],
        [
            "alpha2" => "TZ",
            "name"   => "Tanzania",
            "dial"   => "255",
        ],
        [
            "alpha2" => "UA",
            "name"   => "Ukraine",
            "dial"   => "380",
        ],
        [
            "alpha2" => "UG",
            "name"   => "Uganda",
            "dial"   => "256",
        ],
        [
            "alpha2" => "UM",
            "name"   => "U.S. Outlying Islands",
            "dial"   => "0",
        ],
        [
            "alpha2" => "US",
            "name"   => "US",
            "dial"   => "1",
        ],
        [
            "alpha2" => "UY",
            "name"   => "Uruguay",
            "dial"   => "598",
        ],
        [
            "alpha2" => "UZ",
            "name"   => "Uzbekistan",
            "dial"   => "998",
        ],
        [
            "alpha2" => "VA",
            "name"   => "Vatican City",
            "dial"   => "39-06",
        ],
        [
            "alpha2" => "VC",
            "name"   => "St. Vincent & Grenadines",
            "dial"   => "1-784",
        ],
        [
            "alpha2" => "VE",
            "name"   => "Venezuela",
            "dial"   => "58",
        ],
        [
            "alpha2" => "VG",
            "name"   => "British Virgin Islands",
            "dial"   => "1-284",
        ],
        [
            "alpha2" => "VI",
            "name"   => "U.S. Virgin Islands",
            "dial"   => "1-340",
        ],
        [
            "alpha2" => "VN",
            "name"   => "Vietnam",
            "dial"   => "84",
        ],
        [
            "alpha2" => "VU",
            "name"   => "Vanuatu",
            "dial"   => "678",
        ],
        [
            "alpha2" => "WF",
            "name"   => "Wallis & Futuna",
            "dial"   => "681",
        ],
        [
            "alpha2" => "WS",
            "name"   => "Samoa",
            "dial"   => "685",
        ],
        [
            "alpha2" => "YE",
            "name"   => "Yemen",
            "dial"   => "967",
        ],
        [
            "alpha2" => "YT",
            "name"   => "Mayotte",
            "dial"   => "262",
        ],
        [
            "alpha2" => "ZA",
            "name"   => "South Africa",
            "dial"   => "27",
        ],
        [
            "alpha2" => "ZM",
            "name"   => "Zambia",
            "dial"   => "260",
        ],
        [
            "alpha2" => "ZW",
            "name"   => "Zimbabwe",
            "dial"   => "263",
        ],
    ];
}
