<?php
/**
 * Copyright © 2020 DPO Group. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace DirectPayOnline\Plug\Controller\Index;

class DirectPayCurl
{
    protected $billingDetails;
    protected $configDetails;

    public function __construct( $billingDetails, $configDetails )
    {
        $this->billingDetails = $billingDetails;
        $this->configDetails  = $configDetails;
    }
    /** Get token result */
    public function directPaytTokenResult()
    {
        $response = $this->create_send_xml_request();
        return $response;
    }

    /** Check billing para, and create xml tags accordinatly
     * @param $billingDetails
     * @param $configDetails
     * @return array
     */
    public function checkBillingDetailsForXml( $billingDetails, $configDetails )
    {
        $param = [
            'order_id'   => $billingDetails["order_id"],
            'amount'     => ( isset( $billingDetails["amount"] ) ) ? '<PaymentAmount>' . $billingDetails["amount"] . '</PaymentAmount>' : "",
            'first_name' => ( isset( $billingDetails["first_name"] ) ) ? '<customerFirstName>' . $billingDetails["first_name"] . '</customerFirstName>' : "",
            'last_name'  => ( isset( $billingDetails["last_name"] ) ) ? '<customerLastName>' . $billingDetails["last_name"] . '</customerLastName>' : "",
            'phone'      => ( isset( $billingDetails["phone"] ) ) ? '<customerPhone>' . $billingDetails["phone"] . '</customerPhone>' : "",
            'email'      => ( isset( $billingDetails["email"] ) ) ? '<customerEmail>' . $billingDetails["email"] . '</customerEmail>' : "",
            'address'    => ( isset( $billingDetails["address"] ) ) ? '<customerAddress>' . $billingDetails["address"] . '</customerAddress>' : "",
            'city'       => ( isset( $billingDetails["city"] ) ) ? '<customerCity>' . $billingDetails["city"] . '</customerCity>' : "",
            'zipcode'    => ( isset( $billingDetails["zipcode"] ) ) ? '<customerZip>' . $billingDetails["zipcode"] . '</customerZip>' : "",
            'country'    => ( isset( $billingDetails["country"] ) ) ? '<customerCountry>' . $billingDetails["country"] . '</customerCountry>' : "",
            'dialcode'   => ( isset( $billingDetails['dialcode'] ) ) ? '<customerDialCode>' . $billingDetails['dialcode'] . '</customerDialCode>' : '',
            'ptl_type'   => ( $configDetails['ptl_type'] == 2 ) ? '<PTLtype>minutes</PTLtype>' : "",
            'ptl'        => ( !empty( $configDetails['ptl'] ) ) ? '<PTL>' . $configDetails['ptl'] . '</PTL>' : "",
        ];

        return $param;
    }
    /** Create and send first xml request */
    public function create_send_xml_request()
    {
        $billingDetails = $this->billingDetails;
        $configDetails  = $this->configDetails;

        $param   = $this->checkBillingDetailsForXml( $billingDetails, $configDetails );
        $service = $this->generateProductServiceDetailsToXml( $billingDetails, $configDetails );

        $inputXml = '<?xml version="1.0" encoding="utf-8"?>
					<API3G>
						<CompanyToken>' . $configDetails['company_token'] . '</CompanyToken>
						<Request>createToken</Request>
						<Transaction>' . $param["first_name"] .
            $param["last_name"] .
            $param["phone"] .
            $param["email"] .
            $param["address"] .
            $param["city"] .
            $param["zipcode"] .
            $param["country"] .
            $param['dialcode'] .
            $param["amount"] . '
							<PaymentCurrency>' . $billingDetails["currency"] . '</PaymentCurrency>
							<CompanyRef>' . $billingDetails["order_id"] . '</CompanyRef>
							<RedirectURL>' . $billingDetails["redirectURL"] . '</RedirectURL>
							<BackURL>' . $billingDetails["backURL"] . '</BackURL>
							<CompanyRefUnique>0</CompanyRefUnique>
						</Transaction>
						<Services>' . $service . '</Services>
					</API3G>';
        $writer = new \Zend\Log\Writer\Stream( BP . '/var/log/create_send_xml_request.log' );
        $logger = new \Zend\Log\Logger();
        $logger->addWriter( $writer );
        $logger->info( $inputXml ); // Simple Text Log
        $response = $this->createCURL( $inputXml );

        return $response;
    }
    /**
     * Create service tags to xml of products.
     * @return string $service
     */
    public function generateProductServiceDetailsToXml( $billingDetails, $configDetails )
    {
        $productsArr = $billingDetails['products'];

        $service = '';
        foreach ( $productsArr as $key => $item ) {

            $serviceType = isset( $configDetails["service_type"] ) ? $configDetails["service_type"] : 0;
            $serviceDesc = preg_replace( '/&/', 'and', $item );

            //create each product service xml
            $service .= '<Service>
						    <ServiceType>' . $serviceType . '</ServiceType>
							<ServiceDescription>' . $serviceDesc . '</ServiceDescription>
							<ServiceDate>' . date( 'Y/m/d' ) . '</ServiceDate>
						</Service>';
        }
        return $service;
    }

    // generate Curl and return response
    public function createCURL( $inputXml )
    {
        $configDetails = $this->configDetails;
        $url           = $configDetails['gateway_url'] . "/API/v5/";

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

        return $response;
    }
}
