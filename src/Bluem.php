<?php

/*
 * (c) 2021 - Daan Rijpkema <d.rijpkema@bluem.nl>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Bluem\BluemPHP;

use Bluem\BluemPHP\Contexts\IdentityContext;
use Bluem\BluemPHP\Contexts\MandatesContext;
use Bluem\BluemPHP\Contexts\PaymentsContext;
use Bluem\BluemPHP\Requests\BluemRequest;
use Bluem\BluemPHP\Requests\EmandateBluemRequest;
use Bluem\BluemPHP\Requests\EmandateStatusBluemRequest;
use Bluem\BluemPHP\Requests\IbanBluemRequest;
use Bluem\BluemPHP\Requests\IdentityBluemRequest;
use Bluem\BluemPHP\Requests\IdentityStatusBluemRequest;
use Bluem\BluemPHP\Requests\PaymentBluemRequest;
use Bluem\BluemPHP\Requests\PaymentStatusBluemRequest;
use Bluem\BluemPHP\Responses\ErrorBluemResponse;
use Bluem\BluemPHP\Responses\IBANNameCheckBluemResponse;
use Bluem\BluemPHP\Responses\IdentityStatusBluemResponse;
use Bluem\BluemPHP\Responses\IdentityTransactionBluemResponse;
use Bluem\BluemPHP\Responses\MandateStatusBluemResponse;
use Bluem\BluemPHP\Responses\MandateTransactionBluemResponse;
use Bluem\BluemPHP\Responses\PaymentStatusBluemResponse;
use Bluem\BluemPHP\Responses\PaymentTransactionBluemResponse;
use Bluem\BluemPHP\Validators\Validator;
use Bluem\BluemPHP\Helpers\IPAPI;
use Carbon\Carbon;
use Exception;
use HTTP_Request2 as BluemHttpRequest;
use Selective\XmlDSig\XmlSignatureValidator;
use Throwable;

// libxml_use_internal_errors(false);

if (!defined("BLUEM_ENVIRONMENT_PRODUCTION")) {
    define("BLUEM_ENVIRONMENT_PRODUCTION", "prod");
}
if (!defined("BLUEM_ENVIRONMENT_TESTING")) {
    define("BLUEM_ENVIRONMENT_TESTING", "test");
}
if (!defined("BLUEM_ENVIRONMENT_ACCEPTANCE")) {
    define("BLUEM_ENVIRONMENT_ACCEPTANCE", "acc");
}
if (!defined("BLUEM_STATIC_MERCHANT_ID")) {
    define("BLUEM_STATIC_MERCHANT_ID", "0020000387");
}
if (!defined("BLUEM_LOCAL_DATE_FORMAT")) {
    define("BLUEM_LOCAL_DATE_FORMAT", "Y-m-d\TH:i:s");
}

/**
 * Bluem Integration main class
 */
class Bluem
{
    private $_config;

    public $environment;

    /**
     * Bluem constructor.
     *
     * @param null $_config
     *
     * @throws Exception
     */
    public function __construct(\stdClass $_config = null)
    {
        if (is_null($_config)) {
            throw new Exception(
                "No configuration given to instantiate the Integration"
            );
            exit;
        }

        
        // validating configuration
        // essential validation
        $_config = $this->_validateEnvironment($_config);
        $_config = $this->_validateSenderID($_config);
        $_config = $this->_validateTest_accessToken($_config);
        $_config = $this->_validateProduction_accessToken($_config);
        $_config = $this->_validateBrandID($_config);
        
        // secondary values, possibly automatically inferred/defaulting
        $_config = $this->_validateMerchantIDAndSelectAccessToken($_config);
        $_config = $this->_validateThanksPage($_config);
        $_config = $this->_validateExpectedReturnStatus($_config);
        $_config = $this->_validateEMandateReason($_config);
        $_config = $this->_validateLocalInstrumentCode($_config);
        $_config = $this->_validateMerchantReturnURLBase($_config);
        // this is given by the bank (default 0)
        $_config->merchantSubID = "0";

        $this->_config = $_config;
        $this->merchantID = $this->_config->merchantID;
        $this->environment = $this->_config->environment;
    }

    /*
    *  MANDATE SPECIFIC FUNCTIONS
    *
    */

    /**
     * Create a Mandate Request given a customer ID, order ID
     * and Mandate ID and return the request object
     * WITHOUT sending it
     *
     * @param $customer_id
     * @param $order_id
     * @param boolean $mandate_id
     *
     * @return EmandateBluemRequest
     * @throws Exception
     */
    public function CreateMandateRequest(
        $customer_id,
        $order_id,
        $mandate_id = false
    ) {
        if (is_null($customer_id)) {
            throw new Exception("Customer ID Not set", 1);
        }
        if (is_null($order_id)) {
            throw new Exception("Order ID Not set", 1);
        }

        if ($mandate_id === false) {
            $mandate_id = $this->CreateMandateID($order_id, $customer_id);
        }

        return new EmandateBluemRequest(
            $this->_config,
            $customer_id,
            $order_id,
            $mandate_id,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
                isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : "")
        );
    }

    /**
     * Create a Mandate Request given a customer ID, order ID
     * and Mandate ID and return the request object,
     * sending it and returning the response
     *
     * @param $customer_id
     * @param $order_id
     * @param $mandate_id
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse|Exception
     * @throws Exception
     */
    public function Mandate(
        $customer_id,
        $order_id,
        $mandate_id = false
    ) {
        $_request = $this->CreateMandateRequest(
            $customer_id,
            $order_id,
            $mandate_id
        );

        return $this->PerformRequest($_request);
    }

    /**
     * Retrieving a mandate request's status based on a mandate ID and an entrance Code, and returning the response
     *
     * @param $mandateID
     * @param $entranceCode
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse|Exception
     */
    public function MandateStatus($mandateID, $entranceCode)
    {
        $r = new EMandateStatusBluemRequest(
            $this->_config,
            $mandateID,
            $entranceCode,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
                isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : "")
        );

        return $this->PerformRequest($r);
    }

    /**
     * Create a mandate ID in the required structure, based on the order ID, customer ID and the current timestamp.
     *
     * @param String $order_id    The order ID
     * @param String $customer_id The customer ID
     *
     * @return String
     */
    public function CreateMandateID(String $order_id, String $customer_id): String
    {
        // veteranen search team, specific
        if ($this->_config->senderID === "S1300") {
            return "M" . Carbon::now()->timezone('Europe/Amsterdam')->format('YmdHis');
        }
        // nextdeli et al
        return substr($customer_id . Carbon::now()->timezone('Europe/Amsterdam')->format('Ymd') . $order_id, 0, 35);
    }


    /**
     * For mandates only: retreive the maximum amount from
     * the AcceptanceReport to use in parsing and validating
     * mandates in webshop context
     *
     * @param $response
     *
     * @return object
     */
    public function GetMaximumAmountFromTransactionResponse($response)
    {
        if (isset($response->EMandateStatusUpdate->EMandateStatus->AcceptanceReport->MaxAmount)) {
            return (object) [
                'amount' => (float) ($response->EMandateStatusUpdate->EMandateStatus->AcceptanceReport->MaxAmount . ""),
                'currency' => 'EUR'
            ];
        }

        return (object) ['amount' => (float) 0.0, 'currency' => 'EUR'];
    }

    /**-------------- PAYMENT SPECIFIC FUNCTIONS --------------*/
    /**
     * Create a payment request object
     *
     * @param String $description
     * @param        $debtorReference
     * @param Float  $amount
     * @param null   $dueDateTime
     * @param string $currency
     * @param null   $entranceCode
     * @param string $debtorReturnURL
     *
     * @return PaymentBluemRequest
     */
    public function CreatePaymentRequest(
        String $description,
        $debtorReference,
        Float $amount,
        $dueDateTime = null,
        String $currency = "EUR",
        $entranceCode = null,
        $debtorReturnURL = ""
    ): PaymentBluemRequest {

        if (is_null($entranceCode)) {
            $entranceCode = $this->CreateEntranceCode();
        }

        // create try catch for these validation steps
            // @todo: validate Description
            // @todo: validate Amount
            // @todo: validate Currency
            // @todo: Create constants for Currencies
            // @todo: sanitize debtorReturnURL

        return new PaymentBluemRequest(
            $this->_config,
            $description,
            $debtorReference,
            $amount,
            $dueDateTime,
            $currency,
            $this->CreatePaymentTransactionID($debtorReference),
            $entranceCode,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
                isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : ""),
            $debtorReturnURL
        );
    }

    /**
     * Create a payment request and perform it, returning the response
     *
     * @param string $description
     * @param        $debtorReference
     * @param        $amount
     * @param null   $dueDateTime
     * @param string $currency
     * @param null   $entranceCode
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse|Exception
     */
    public function Payment(
        string $description,
        $debtorReference,
        $amount,
        $dueDateTime = null,
        string $currency = "EUR",
        $entranceCode = null
    ) {
        if (is_null($entranceCode)) {
            $entranceCode = $this->CreateEntranceCode();
        }
        return $this->PerformRequest(
            $this->CreatePaymentRequest(
                $description,
                $debtorReference,
                $amount,
                $dueDateTime,
                $currency,
                $entranceCode
            )
        );
    }

    /**
     * Retrieve the status of a payment request, based on transactionID and Entrance Code
     *
     * @param $transactionID
     * @param $entranceCode
     * @return ErrorBluemResponse|PaymentStatusBluemResponse|Exception
     */
    public function PaymentStatus($transactionID, $entranceCode)
    {
        $r = new PaymentStatusBluemRequest(
            $this->_config,
            $transactionID,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
                isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : ""),
            $entranceCode
        );

        return $this->PerformRequest($r);
    }

    /**
     * Create a payment Transaction ID in the required structure, based on the order ID, customer ID and the current timestamp.
     *
     * @param String $debtorReference
     *
     * @return String
     */
    public function CreatePaymentTransactionID(String $debtorReference): String
    {
        return substr($debtorReference, 0, 28) . Carbon::now()->format('Ymd');
    }



    /**-------------- IDENTITY SPECIFIC FUNCTIONS --------------*/
    /**
     * Create Identity request based on a category, description, reference and given a return URL
     *
     * @param        $requestCategory
     * @param string $description
     * @param        $debtorReference
     * @param        $debtorReturnURL
     * @param string $entranceCode
     *
     * @return IdentityBluemRequest
     */
    public function CreateIdentityRequest(
        $requestCategory,
        string $description,
        $debtorReference,
        $debtorReturnURL,
        $entranceCode = ""
    ): IdentityBluemRequest {
        // todo: Check if this is needed?
        //$this->CreateIdentityTransactionID($debtorReference),

        return new IdentityBluemRequest(
            $this->_config,
            $entranceCode,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
            isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : ""),
            $requestCategory,
            $description,
            $debtorReference,
            $debtorReturnURL
        );
    }

    /**
     * Retrieve Identity request status
     *
     * @param $transactionID
     * @param $entranceCode
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse|Exception
     * @throws \HTTP_Request2_LogicException
     */
    public function IdentityStatus($transactionID, $entranceCode)
    {
        $r = new IdentityStatusBluemRequest(
            $this->_config,
            $entranceCode,
            ($this->_config->environment == BLUEM_ENVIRONMENT_TESTING &&
                isset($this->_config->expectedReturnStatus) ?
                $this->_config->expectedReturnStatus : ""),
            $transactionID
        );

        return $this->PerformRequest($r);
    }


    // @todo: Create Identity shorthand function


    /**
     * Create a Identity Transaction ID in the required structure, based on the order ID, customer ID and the current timestamp.
     * @param String $debtorReference
     * @return String Identity Transaction ID
     */
    public function CreateIdentityTransactionID(String $debtorReference): String
    {
        return substr($debtorReference, 0, 28) . Carbon::now()->format('Ymd');
    }


    /** Universal Functions */
    /**
     * Generate an entrance code based on the current date and time.
     */
    public function CreateEntranceCode(): String
    {
        return Carbon::now()->format("YmdHisv"); // . "000";
    }

    /**
     * Perform a request to the Bluem API given a request
     * object and return its response
     *
     * @param BluemRequest $transaction_request
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse
     * @throws \DOMException
     * @throws \HTTP_Request2_LogicException
     */
    public function PerformRequest(BluemRequest $transaction_request)
    {
        $validator = new Validator();
        if (!$validator->validate($transaction_request->RequestContext(), $transaction_request->XmlString())) {
            return new ErrorBluemResponse(
                "Error: Request is not formed correctly. More details: ".
                implode(
                    '; '.PHP_EOL,
                    $validator->errorDetails
                )
            );
        };

        // set this to true if you want more internal information when debugging or extending
        $verbose = false;

        $now = Carbon::now('UTC');
        // set timezone to UTC to let the transaction xttrs timestamp work; 8-9-2021
        
        $xttrs_filename = $transaction_request->transaction_code . "-{$this->_config->senderID}-BSP1-" . $now->format('YmdHis') . "000.xml";

        // conform Rfc1123 standard in GMT time
        $rfc7231format = "D, d M Y H:i:s \G\M\T";
        // Since v2.0.5 : use preset format instead of
        // function to allow for Carbon 1.21 legacy compatibility
        $xttrs_date = $now->format($rfc7231format);

        $request_url = $transaction_request->HttpRequestUrl();

        $req = new BluemHttpRequest();

        $req->setUrl($request_url);
        $req->setMethod(BluemHttpRequest::METHOD_POST);

        $req->setHeader('Access-Control-Allow-Origin', '*');
        $req->setHeader("Content-Type", "application/xml; type=" . $transaction_request->transaction_code . "; charset=UTF-8");
        $req->setHeader("x-ttrs-date", $xttrs_date);
        $req->setHeader("x-ttrs-files-count", "1");
        $req->setHeader("x-ttrs-filename", $xttrs_filename);

        if ($verbose) {
            echo PHP_EOL . "<BR>URL// " . $request_url;

            echo PHP_EOL . "<BR>HEADER// " . "Content-Type: " . "application/xml; type=" . $transaction_request->transaction_code . "; charset=UTF-8";
            echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-date: ' . $xttrs_date;
            echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-files-count: ' . '1';
            echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-filename: ' . $xttrs_filename;
            echo "<HR>";
            echo PHP_EOL . "BODY: " . $transaction_request->XmlString();
        }

        $req->setBody($transaction_request->XmlString());
        try {
            $http_response = $req->send();
            if ($verbose) {
                echo PHP_EOL . "<BR>RESPONSE// ";
                echo($http_response->getBody());
            }

            switch ($http_response->getStatus()) {
                case 200: {
                    if ($http_response->getBody() == "") {
                        return new ErrorBluemResponse("Error: Empty response returned");
                    }

                    try {
                        $response = $this->fabricateResponseObject($transaction_request->transaction_code, $http_response->getBody());
                    } catch (\Throwable $th) {
                        return new ErrorBluemResponse("Error: Could not create Bluem Response object. More details: " . $th->getMessage());
                    }

                    if ($response->attributes()['type'].''  === "ErrorResponse") {
                        switch ((string)$transaction_request->transaction_code) {
                            case 'SRX':
                            case 'SUD':
                            case 'TRX':
                            case 'TRS':
                                $errmsg = (string)$response->EMandateErrorResponse->Error->ErrorMessage;
                                break;
                            case 'PSU':
                            case 'PSX':
                            case 'PTS':
                            case 'PTX':
                                $errmsg = (string)$response->PaymentErrorResponse->Error->ErrorMessage;
                                break;
                            case 'ITX':
                            case 'ITX':
                            case 'ISU':
                            case 'ISX':
                                $errmsg = (string)$response->IDentityErrorResponse->Error->ErrorMessage;
                                break;
                            case 'INS':
                            case 'INX':
                                $errmsg = (string)$response->IBANCheckErrorResponse->Error->ErrorMessage;
                                break;
                            default:
                                throw new Exception("Invalid transaction type requested");
                            }

                        return new ErrorBluemResponse("Error: " . ($errmsg));
                    }

                    if (!$response->Status()) {
                        return new ErrorBluemResponse("Error: " . ($response->Error->ErrorMessage));
                    }
                    return $response;

                    break;
                }
                case 400:
                    return new ErrorBluemResponse('Your request was not formed correctly.');
                case 401:
                    return new ErrorBluemResponse('Unauthorized: check your access credentials.');
                case 500:
                    return new ErrorBluemResponse('An unrecoverable error at the server side occurred while processing the request');
                default:
                    return new ErrorBluemResponse('Unexpected / erroneous response (code ' . $http_response->getStatus() . ')');
            }
        } catch (Throwable $e) {
            return new ErrorBluemResponse('HTTP Request Error');
        }
    }


    /** Webhook Code
     *
     * Senders provide Bluem with a webhook URL.
     * The URL will be checked for consistency and
     * validity and will not be stored if any of the
     * checks fails. */

    /**
     * Webhook for Bluem Mandate signature verification procedure
     */
    public function Webhook()
    {
        // set this to true if you want more internal information when debugging or extending
        $verbose = false;

        // The following checks will be performed:
        // @todo URL must start with https://


        // Check: ONLY Accept post requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($verbose) {
                exit("Not post");
            }
            http_response_code(400);
            exit();
        }

        // Check: An empty POST to the URL (normal HTTP request) always has to respond with HTTP 200 OK
        $postData = file_get_contents('php://input');

        if ($postData === "") {
            if ($verbose) {
                echo "NO POST";
            }
            http_response_code(200);
            exit();
        }

        // Check: content type has to be: "Content-type", "text/xml; charset=UTF-8"

        // Parsing XML data from POST body
        try {
            $xmlObject = new \SimpleXMLElement($postData);
        } catch (Exception $e) {
            if ($verbose) {
                echo($e->getMessage());
                exit();
            }
            http_response_code(400); // could not parse XML
            exit();
        }

        // Check: if signature is valid in postdata
        if (!$this->_validateWebhookSignature($postData)) {
            if ($verbose) {
                exit('no valid webhook sig');
            }

            http_response_code(400);
            // echo 'The XML signature is not valid.';
            // echo PHP_EOL;
            exit;
        }


        // @todo: finish this code
        throw new Exception("Not implemented fully yet, please contact the developer or work around this error");
        // @todo webhook response dependent on the interface, check the status update

        // @todo webhook response mandates

        // @todo webhook response payments
        if (!isset($xmlObject->EPaymentInterface->PaymentStatusUpdate)) {
            http_response_code(400);
            exit;
        }
        $status_update = $xmlObject->EPaymentInterface->PaymentStatusUpdate;
        return $status_update;

        // @todo webhook response identity

        // @todo webhook response and more

        // @todo catch exceptions
    }

    /**
     * Validate webhook signature based on a key file
     * available in the `keys` folder
     *
     * @param  $xmlInput
     * @return bool
     */
    private function _validateWebhookSignature($xmlInput): bool
    {
        $temp_file = tmpfile();
        fwrite($temp_file, $xmlInput);
        $temp_file_path = stream_get_meta_data($temp_file)['uri'];

        $signatureValidator = new XmlSignatureValidator();

        // @todo Check if keyfile has to be chosen according to env
        // if ($this->_config->environment === BLUEM_ENVIRONMENT_TESTING) {
        // $public_key_file = "webhook.bluem.nl_pub_cert_test.crt";
        // } else {
        // $public_key_file = "webhook.bluem.nl_pub_key_production.crt";
        // }
        $key_folder = $public_key_file = "bluem_nl.crt";
        $public_key_file_path = __DIR__ . "/../keys/" . $public_key_file;
        // TODO: put the key in a different folder, relative to this PHP library
        // echo $public_key_file_path;
        // die();

        try {
            $signatureValidator->loadPublicKeyFile($public_key_file_path);
        } catch (\Throwable $th) {
            return false;
            // echo "Error: " . $th->getMessage();
        }

        $isValid = $signatureValidator->verifyXmlFile($temp_file_path);
        fclose($temp_file);

        if ($isValid) {
            return true;
        }
        return false;
    }

    /**
     * Create the proper response object class
     *
     * @param $type
     * @param $response_xml
     *
     * @return IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse
     * @throws Exception
     */
    private function fabricateResponseObject($type, $response_xml)
    {
        switch ($type) {
        case 'SRX':
        case 'SUD':
            return new MandateStatusBluemResponse($response_xml);
        case 'TRX':
        case 'TRS':
            return new MandateTransactionBluemResponse($response_xml);
        case 'PSU':
        case 'PSX':
            return new PaymentStatusBluemResponse($response_xml);
        case 'PTS':
        case 'PTX':
            return new PaymentTransactionBluemResponse($response_xml);
        case 'ITX':
        case 'ITX':
            return new IdentityTransactionBluemResponse($response_xml);
        case 'ISU':
        case 'ISX':
            return new IdentityStatusBluemResponse($response_xml);
        case 'INS':
        case 'INX':
            return new IBANNameCheckBluemResponse($response_xml);
        default:
            throw new Exception("Invalid transaction type requested");
        }
    }

    /**
     * Retrieve a list of all possible identity request types, which can be useful for reference
     *
     * @return string[]
     */
    public function GetIdentityRequestTypes()
    {
        return [
            "CustomerIDRequest",
            "CustomerIDLoginRequest",
            "NameRequest",
            "AddressRequest",
            "BirthDateRequest",
            "AgeCheckRequest",
            "GenderRequest",
            "TelephoneRequest",
            "EmailRequest",
        ];
    }


    /* IBAN SPECIFIC */

    /**
     * Create IBAN Name Check request
     * 
     * @param string $iban            Given IBAN to check
     * @param string $name            Given name to check
     * @param string $debtorReference An optional given debtor reference 
     *                                to append to the check request
     *
     * @return IbanBluemRequest
     */
    public function CreateIBANNameCheckRequest(String $iban, String $name, String $debtorReference = "")
    {
        $entranceCode = $this->CreateEntranceCode();
        return new IbanBluemRequest(
            $this->_config, $entranceCode,
            $iban, $name, $debtorReference
        );
    }


    /**
     * Create and perform IBAN Name Check request 
     * 
     * @param string $iban            Given IBAN to check
     * @param string $name            Given name to check
     * @param string $debtorReference An optional given debtor reference 
     *                                to append to the check request
     *
     * @return ErrorBluemResponse|IBANNameCheckBluemResponse|IdentityStatusBluemResponse|IdentityTransactionBluemResponse|MandateStatusBluemResponse|MandateTransactionBluemResponse|PaymentStatusBluemResponse|PaymentTransactionBluemResponse|Exception
     * @throws \HTTP_Request2_LogicException
     */
    public function IBANNameCheck(String $iban, String $name, String $debtorReference="")
    {
        $r = $this->CreateIBANNameCheckRequest($iban, $name, $debtorReference);
        $response = $this->PerformRequest($r);
        return $response;
    }

    /**
     * Retrieve array of BIC codes (IssuerIDs) of banks from context
     *
     * @param $contextName
     *
     * @return array
     * @throws Exception
     */
    public function retrieveBICCodesForContext($contextName)
    {
        $context = $this->_retrieveContext($contextName);
        return $context->getBICCodes();
    }

    /**
     * Retrieve array of BIC codes (IssuerIDs) of banks from context
     *
     * @param $contextName
     *
     * @return array|mixed
     * @throws Exception
     */
    public function retrieveBICsForContext($contextName)
    {
        $context = $this->_retrieveContext($contextName);
        return $context->getBICs();
    }


    /**
     * @param $context
     *
     * @return IdentityContext|MandatesContext|PaymentsContext
     * @throws Exception
     */
    public function _retrieveContext($context)
    {
        $localInstrumentCode = $this->_config->localInstrumentCode;
        switch ($context) {
        case 'Mandates':
            $context = new MandatesContext($localInstrumentCode);
            break;
        case 'Payments':
            $context = new PaymentsContext();
            break;
        case 'Identity':
            $context = new IdentityContext();
            break;
        default:
            $contexts = ["Mandates","Payments","Identity"];
            throw new Exception(
                "Invalid Context requested, should be
                one of the following: ".
                implode(",", $contexts)
            );
        }

        return $context;
    }

    /**
     * Verify if the current IP is based in the Netherlands 
     * utilizing a geolocation integration
     *
     * @return bool
     */
    public function VerifyIPIsNetherlands() {
        $this->IPAPI = new IPAPI();
        return $this->IPAPI->CheckIsNetherlands();
    }

    private function _validateEnvironment($_config)
    {
        if (!in_array(
            $_config->environment,
            [
                BLUEM_ENVIRONMENT_TESTING,
                BLUEM_ENVIRONMENT_ACCEPTANCE,
                BLUEM_ENVIRONMENT_PRODUCTION
            ]
        )
        ) {
            throw new Exception(
                "Invalid environment setting, should be either
                'test', 'acc' or 'prod'"
            );
        }
        return $_config;
    }
    private function _validateSenderID($_config)
    {
        if (!isset($_config->senderID)) {
            throw new Exception(
                "senderID not set; 
                please add this to your configuration when instantiating the Bluem integration"
            );
        }
        if ($_config->senderID =="") {
            throw new Exception(
                "senderID cannot be empty; 
                please add this to your configuration when instantiating the Bluem integration"
            );
        }
        if (substr($_config->senderID, 0, 1) !== "S") {
            throw new Exception(
                "senderID always starts with an S followed by digits. 
                Please correct this in your configuration when instantiating the Bluem integration"
            );
        }

        return $_config;
    }
    private function _validateTest_accessToken($_config)
    {
        if ($_config->environment === BLUEM_ENVIRONMENT_TESTING
        && (!isset($_config->test_accessToken)
            || $_config->test_accessToken ==="")
        ) {
            throw new Exception(
                "test_accessToken not set correctly; please add this 
                to your configuration when instantiating the Bluem integration"
            );
        }
        return $_config;
    }
    private function _validateProduction_accessToken($_config)
    {
        // only required if mode is set to PROD
        // production_accessToken
        if ($_config->environment === BLUEM_ENVIRONMENT_PRODUCTION
            && (!isset($_config->production_accessToken)
            || $_config->production_accessToken ==="")
        ) {
            throw new Exception(
                "production_accessToken not set correctly; 
                please add this to your configuration when 
                instantiating the Bluem integration"
            );
        }
        return $_config;
    }

    private function _validateMerchantIDAndSelectAccessToken($_config)
    {
        if (!isset($_config->merchantId) || is_null($_config->merchantId)) {
            $_config->merchantId = "";
        }

        if ($_config->environment === BLUEM_ENVIRONMENT_PRODUCTION) {
            $_config->accessToken = $_config->production_accessToken;
        // @todo consider throwing an exception if these tokens are missing.
        } elseif ($_config->environment === BLUEM_ENVIRONMENT_TESTING) {
            $_config->accessToken = $_config->test_accessToken;
            // @todo consider throwing an exception if these tokens are missing.

            // hardcoded merchantID in case of test.
            // It is always the bluem merchant ID then.
            $_config->merchantID = BLUEM_STATIC_MERCHANT_ID;
        }
        return $_config;
    }
    private function _validateThanksPage($_config)
    {
        return $_config;
    }

    private function _validateExpectedReturnStatus($_config)
    {
        if (isset($_config->expectedReturnStatus)) {
            if ($_config->environment === BLUEM_ENVIRONMENT_TESTING) {
                // if an invalid possible return status is given, set it to a default value (for testing purposes only)
                $possibleReturnStatuses = [
                    "none",
                    "success",
                    "cancelled",
                    "expired",
                    "failure",
                    "open",
                    "pending"
                ];
                if ($_config->expectedReturnStatus !== ""
                    && !in_array(
                        $_config->expectedReturnStatus,
                        $possibleReturnStatuses
                    )
                ) {
                    $_config->expectedReturnStatus = "success";
                }
            } else {
                unset($_config->expectedReturnStatus);
            }
        }
        return $_config;
    }

    private function _validateBrandID($_config)
    {
        if (!isset($_config->brandID)) {
            throw new Exception("brandID not set; please add this to your configuration when instantiating the Bluem integration");
        }
        return $_config;
    }
    private function _validateEMandateReason($_config)
    {
        return $_config;
    }
    private function _validateLocalInstrumentCode($_config)
    {
        if (!isset($_config->localInstrumentCode)
            || !in_array(
                $_config->localInstrumentCode,
                ['B2B', 'CORE']
            )
        ) {
            // defaulting localInstrumentCode
            $_config->localInstrumentCode = "CORE";
        }
        return $_config;
    }
    private function _validateMerchantReturnURLBase($_config)
    {
        return $_config;
    }
}
