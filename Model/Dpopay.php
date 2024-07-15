<?php
/*
 * Copyright (c) 2024 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the MIT License
 */

namespace Dpo\Dpo\Model;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Magento\Framework\Escaper;
use Magento\Framework\HTTP\Client\Curl;

class Dpopay
{
    public const DPO_URL_LIVE  = 'https://secure.3gdirectpay.com';
    public const RESPONSE_TEXT = 'response : ';
    /**
     * @var bool
     */
    public bool $testMode;
    /**
     * @var string
     */
    private string $dpoUrl;
    /**
     * @var string
     */
    private string $dpoGateway;
    /**
     * @var string
     */
    private string $testText;
    /**
     * @var object
     */
    private object $logger;
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * Class construct
     *
     * @param object $logger
     * @param Curl $curl
     * @param bool $testMode
     */
    public function __construct($logger, Curl $curl, $testMode = false)
    {
        $this->dpoUrl = self::DPO_URL_LIVE;

        if ((int)$testMode == 1) {
            $this->testMode = true;
            $this->testText = 'teston';
        } else {
            $this->testMode = false;
            $this->testText = 'liveon';
        }

        $this->logger     = $logger;
        $this->dpoGateway = $this->dpoUrl . '/payv2.php';
        $this->curl       = $curl;
    }

    /**
     * Returns the DPO gateway string
     *
     * @return string
     */
    public function getDpoGateway(): string
    {
        return $this->dpoGateway;
    }

    /**
     * Create a DPO token for payment processing
     *
     * @param array $data
     *
     * @return array
     */
    #[ArrayShape([
        'success'           => "bool",
        'result'            => "string",
        'transToken'        => "string",
        'resultExplanation' => "string",
        'transRef'          => "string"
    ])] public function createToken(array $data): array
    {
        $pre = __METHOD__ . ' : ';

        $escaper = new Escaper();

        $companyToken      = $escaper->escapeHtml($data['companyToken']);
        $accountType       = $escaper->escapeHtml($data['accountType']);
        $paymentAmount     = $escaper->escapeHtml($data['paymentAmount']);
        $paymentCurrency   = $escaper->escapeHtml($data['paymentCurrency']);
        $customerFirstName = $escaper->escapeHtml($data['customerFirstName']);
        $customerLastName  = $escaper->escapeHtml($data['customerLastName']);
        $customerAddress   = $escaper->escapeHtml($data['customerAddress']);
        $customerCity      = $escaper->escapeHtml($data['customerCity']);
        $customerPhone     = $escaper->escapeHtml(preg_replace('/\D/', '', $data['customerPhone']));
        $redirectURL       = $escaper->escapeHtml($data['redirectURL']);
        $backURL           = $escaper->escapeHtml($data['backUrl']);
        $customerEmail     = $escaper->escapeHtml($data['customerEmail']);
        $reference         = $escaper->escapeHtml($data['companyRef'] . '_' . $this->testText);
        $country           = $escaper->escapeHtml($data['payment_country']);
        $country_id        = $escaper->escapeHtml($data['payment_country']);
        $zip               = $escaper->escapeHtml($data['payment_postcode']);

        $odate = (new \DateTime())->format('Y/m/d H:i');

        $postXml = <<<POSTXML
        <?xml version="1.0" encoding="utf-8"?> <API3G> <CompanyToken>$companyToken</CompanyToken> <Request>createToken</Request> <Transaction> <PaymentAmount>$paymentAmount</PaymentAmount> <PaymentCurrency>$paymentCurrency</PaymentCurrency> <CompanyRef>$reference</CompanyRef> <customerFirstName>$customerFirstName</customerFirstName> <customerLastName>$customerLastName</customerLastName> <customerAddress>$customerAddress</customerAddress> <customerCity>$customerCity</customerCity> <customerZip>$zip</customerZip> <customerCountry>$country</customerCountry> <customerDialCode>$country_id</customerDialCode> <customerPhone>$customerPhone</customerPhone> <RedirectURL>$redirectURL</RedirectURL> <BackURL>$backURL</BackURL> <customerEmail>$customerEmail</customerEmail> <TransactionSource>magento</TransactionSource> </Transaction> <Services> <Service> <ServiceType>$accountType</ServiceType> <ServiceDescription>$reference</ServiceDescription> <ServiceDate>$odate</ServiceDate> </Service> </Services> </API3G>
POSTXML;

        try {
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->addHeader("Content-Type", "application/xml");
            $this->curl->post($this->dpoUrl . "/API/v6/", $postXml);
            $response = $this->curl->getBody();

            if ($response != '') {
                $xml = simplexml_load_string($response);

                $result            = $xml->xpath('Result')[0]->__toString();
                $resultExplanation = $xml->xpath('ResultExplanation')[0]->__toString();

                // Check if token was created successfully
                if ($result != '000') {
                    $this->logger->debug($pre . self::RESPONSE_TEXT . json_encode($response));

                    return [
                        'success'           => false,
                        'resultExplanation' => $resultExplanation
                    ];
                } else {
                    $transToken = $xml->xpath('TransToken')[0]->__toString();
                    $transRef   = $xml->xpath('TransRef')[0]->__toString();

                    return [
                        'success'           => true,
                        'result'            => $result,
                        'transToken'        => $transToken,
                        'resultExplanation' => $resultExplanation,
                        'transRef'          => $transRef,
                    ];
                }
            } else {
                $this->logger->debug($pre . self::RESPONSE_TEXT . json_encode($response));
                $this->logger->debug('Token could not be created. Please go back and try again');
                throw new \Magento\Framework\Webapi\Exception(
                    __('Token could not be created. Please go back and try again')
                );
            }
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            $this->logger->debug($pre . self::RESPONSE_TEXT . json_encode($response));
            $this->logger->debug('Token XML is invalid. Please go back and try again');
            throw new \Magento\Framework\Webapi\Exception(__('Token XML is invalid. Please go back and try again'));
        }
    }

    /**
     * Verify the DPO token created in first step of transaction
     *
     * @param array $data
     *
     * @return bool|string
     */
    public function verifyToken(array $data): bool|string
    {
        $companyToken = $data['companyToken'];
        $transToken   = $data['transToken'];

        $postXml = <<<POSTXML
<?xml version="1.0" encoding="utf-8"?>
<API3G>
    <CompanyToken>$companyToken</CompanyToken>
    <Request>verifyToken</Request>
    <TransactionToken>$transToken</TransactionToken>
</API3G>
POSTXML;

        try {
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->addHeader("Content-Type", "application/xml");
            $this->curl->post($this->dpoUrl . "/API/v7/", $postXml);
            $response = $this->curl->getBody();

            if (!$response) {
                $this->logger->debug("cURL Error");

                return false;
            }
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage());
            throw new \Magento\Framework\Webapi\Exception(__($e->getMessage()));
        }

        return $response;
    }
}
