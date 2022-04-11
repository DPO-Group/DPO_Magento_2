<?php
/*
 * Copyright (c) 2022 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the MIT License
 */

namespace Dpo\Dpo\Model;

class Dpopay
{
    const DPO_URL_LIVE = 'https://secure.3gdirectpay.com';
    const DPO_URL_TEST = 'https://secure.3gdirectpay.com';

    private $dpoUrl;
    private $dpoGateway;
    private $testMode;
    private $testText;
    private $logger;

    public function __construct($logger, $testMode = false)
    {
        if ( (int) $testMode == 1 ) {
            $this->dpoUrl   = self::DPO_URL_TEST;
            $this->testMode = true;
            $this->testText = 'teston';
        } else {
            $this->dpoUrl   = self::DPO_URL_LIVE;
            $this->testMode = false;
            $this->testText = 'liveon';
        }

        $this->logger = $logger;
        $this->dpoGateway = $this->dpoUrl . '/payv2.php';

    }

    public function getDpoGateway()
    {
        return $this->dpoGateway;
    }

    /**
     * Create a DPO token for payment processing
     * @param $data
     * @return array
     */
    public function createToken( $data )
    {
        $pre = __METHOD__ . ' : ';

        $companyToken      = $data['companyToken'];
        $accountType       = $data['accountType'];
        $paymentAmount     = $data['paymentAmount'];
        $paymentCurrency   = $data['paymentCurrency'];
        $customerFirstName = $data['customerFirstName'];
        $customerLastName  = $data['customerLastName'];
        $customerAddress   = $data['customerAddress'];
        $customerCity      = $data['customerCity'];
        $customerPhone     = preg_replace( '/[^0-9]/', '', $data['customerPhone'] );
        $redirectURL       = $data['redirectURL'];
        $backURL           = $data['backUrl'];
        $customerEmail     = $data['customerEmail'];
        $reference         = $data['companyRef'] . '_' . $this->testText;
        $country           = $data['payment_country'];
        $country_id        = $data['payment_country'];
        $zip               = $data['payment_postcode'];

        $odate   = date( 'Y/m/d H:i' );
        $postXml = <<<POSTXML
        <?xml version="1.0" encoding="utf-8"?> <API3G> <CompanyToken>$companyToken</CompanyToken> <Request>createToken</Request> <Transaction> <PaymentAmount>$paymentAmount</PaymentAmount> <PaymentCurrency>$paymentCurrency</PaymentCurrency> <CompanyRef>$reference</CompanyRef> <customerFirstName>$customerFirstName</customerFirstName> <customerLastName>$customerLastName</customerLastName> <customerAddress>$customerAddress</customerAddress> <customerCity>$customerCity</customerCity> <customerZip>$zip</customerZip> <customerCountry>$country</customerCountry> <customerDialCode>$country_id</customerDialCode> <customerPhone>$customerPhone</customerPhone> <RedirectURL>$redirectURL</RedirectURL> <BackURL>$backURL</BackURL> <customerEmail>$customerEmail</customerEmail> <TransactionSource>magento</TransactionSource> </Transaction> <Services> <Service> <ServiceType>$accountType</ServiceType> <ServiceDescription>$reference</ServiceDescription> <ServiceDate>$odate</ServiceDate> </Service> </Services> </API3G>
POSTXML;

        $curl = curl_init();
        curl_setopt_array( $curl, array(
            CURLOPT_URL            => $this->dpoUrl . "/API/v6/",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => "",
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => $postXml,
            CURLOPT_HTTPHEADER     => array(
                "cache-control: no-cache",
            ),
        ) );

        $response = curl_exec( $curl );

        $error    = curl_error( $curl );
        if ( $error ) {
            $this->logger->debug( $pre . 'error : ' . json_encode($error) );
        }

        curl_close( $curl );

        if ( $response != '' ) {
            $xml = new \SimpleXMLElement( $response );

            // Check if token was created successfully
            if ( $xml->xpath( 'Result' )[0] != '000' ) {
                $this->logger->debug( $pre . 'response : ' . json_encode($response) );
                throw new \Magento\Framework\Webapi\Exception(__( 'Token could not be created. Please check debug log.' ));
                exit();
            } else {
                $transToken        = $xml->xpath( 'TransToken' )[0]->__toString();
                $result            = $xml->xpath( 'Result' )[0]->__toString();
                $resultExplanation = $xml->xpath( 'ResultExplanation' )[0]->__toString();
                $transRef          = $xml->xpath( 'TransRef' )[0]->__toString();

                return [
                    'success'           => true,
                    'result'            => $result,
                    'transToken'        => $transToken,
                    'resultExplanation' => $resultExplanation,
                    'transRef'          => $transRef,
                ];
            }
        } else {

            $this->logger->debug( $pre . 'response : ' . json_encode($response) );
            $this->logger->debug('Token could not be created. Please go back and try again');
            throw new \Magento\Framework\Webapi\Exception(__( 'Token could not be created. Please go back and try again' ));
        }
    }

    /**
     * Verify the DPO token created in first step of transaction
     * @param $data
     * @return bool|string
     */
    public function verifyToken( $data )
    {
        $companyToken = $data['companyToken'];
        $transToken   = $data['transToken'];

        try {
            $curl = curl_init();
            curl_setopt_array( $curl, array(
                CURLOPT_URL            => $this->dpoUrl . "/API/v6/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => "",
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => "POST",
                CURLOPT_POSTFIELDS     => "<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<API3G>\r\n  <CompanyToken>" . $companyToken . "</CompanyToken>\r\n  <Request>verifyToken</Request>\r\n  <TransactionToken>" . $transToken . "</TransactionToken>\r\n</API3G>",
                CURLOPT_HTTPHEADER     => array(
                    "cache-control: no-cache",
                ),
            ) );

            $response = curl_exec( $curl );
            $err      = curl_error( $curl );

            curl_close( $curl );

            if ( strlen( $err ) > 0 ) {
                echo "cURL Error #:" . $err;
            } else {
                return $response;
            }
        } catch ( \Exception $e ) {

            $this->logger->debug($e->getMessage());

            throw new Magento\Framework\Webapi\Exception(__( $e->getMessage() ));
        }
    }
}
