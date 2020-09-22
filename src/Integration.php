<?php

/*
 * (c) Daan Rijpkema <info@daanrijpkema.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Bluem\BluemPHP;

require __DIR__ . '/../vendor/autoload.php';

require_once 'Emandates.php';
require_once 'Payments.php';
require_once 'Identity.php';
require_once 'Iban.php';
require_once 'BluemResponse.php';
require_once 'BluemRequest.php';

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

/**
 * BlueM Integration main class
 */
class Integration
{
	private $configuration;

	public $environment;

	/**
	 * Constructs a new instance.
	 */
	function __construct($configuration = null)
	{
		if (is_null($configuration)) {
			throw new Exception("No valid configuration given to instantiate Bluem Integration");
			exit;
		}

		// validating configuration
		if (!in_array($configuration->environment, [BLUEM_ENVIRONMENT_TESTING, BLUEM_ENVIRONMENT_ACCEPTANCE, BLUEM_ENVIRONMENT_PRODUCTION])) {
			throw new Exception("Invalid environment setting, should be test,acc or prod");
		}


		$this->configuration = $configuration;



		if ($this->configuration->environment === BLUEM_ENVIRONMENT_PRODUCTION) {
			$this->configuration->accessToken = $configuration->production_accessToken;
		} elseif ($this->configuration->environment === BLUEM_ENVIRONMENT_TESTING) {
			$this->configuration->accessToken = $configuration->test_accessToken;
		}
		$this->environment = $this->configuration->environment;

		// this is given by the bank (default 0)
		$this->configuration->merchantSubID = "0";
	}

	/**-------------- MANDATE SPECIFIC FUNCTIONS --------------*/

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

		$r = new EmandateBluemRequest(
			$this->configuration,
			$customer_id,
			$order_id,
			$mandate_id,
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : "")
		);
		return $r;
	}


	public function Mandate(
		$customer_id,
		$order_id,
		$mandate_id
	) {
		$_request = $this->CreateMandateRequest(
			$customer_id,
			$order_id,
			$mandate_id
		);
		$response = $this->PerformRequest(
			$_request
		);
		return $response;
	}



	public function MandateStatus($mandateID, $entranceCode)
	{

		$r = new EMandateStatusBluemRequest(
			$this->configuration,
			$mandateID,
			$entranceCode,
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : "")
		);

		$response = $this->PerformRequest($r);
		return $response;
	}

	/**
	 * Create a mandate ID in the required structure, based on the order ID, customer ID and the current timestamp.
	 * @param String $order_id    The order ID
	 * @param String $customer_id The customer ID
	 */
	public function CreateMandateID(String $order_id, String $customer_id): String
	{
		// veteranen search team
		if ($this->configuration->senderID === "S1300") {
			return "M" . Carbon::now()->timezone('Europe/Amsterdam')->format('YmdHis');
		}
		// nextdeli etc.
		return substr($customer_id . Carbon::now()->timezone('Europe/Amsterdam')->format('Ymd') . $order_id, 0, 35);
	}



	/**-------------- PAYMENT SPECIFIC FUNCTIONS --------------*/

	public function CreatePaymentRequest(
		$description,
		$debtorReference,
		$amount,
		$dueDateTime = null,
		$currency = "EUR",
		$entranceCode = null
	) {

		if (is_null($entranceCode)) {
			$entranceCode = $this->CreateEntranceCode();
		}

		$r = new PaymentBluemRequest(
			$this->configuration,
			$description,
			$debtorReference,
			$amount,
			$dueDateTime,
			$currency,
			$this->CreatePaymentTransactionID($debtorReference),
			$entranceCode,
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : "")
		);
		return $r;
	}

	public function Payment(
		$description,
		$debtorReference,
		$amount,
		$dueDateTime = null,
		$currency = "EUR",
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


	public function PaymentStatus($transactionID, $entranceCode)
	{

		$r = new PaymentStatusBluemRequest(
			$this->configuration,
			$transactionID,
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : ""),
			$entranceCode
		);

		$response = $this->PerformRequest($r);
		return $response;
	}

	/**
	 * Create a payment Transaction ID in the required structure, based on the order ID, customer ID and the current timestamp.
	 * @param String $order_id    The order ID
	 * @param String $customer_id The customer ID
	 */
	public function CreatePaymentTransactionID(String $debtorReference): String
	{
		return substr($debtorReference, 0, 28) . Carbon::now()->format('Ymd');
	}



	/**-------------- IDENTITY SPECIFIC FUNCTIONS --------------*/

	public function CreateIdentityRequest(
		$requestCategory,
		$description,
		$debtorReference,
		$debtorReturnURL
	) {

		$r = new IdentityBluemRequest(
			$this->configuration,
			"",
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : ""),
			$requestCategory,
			$description,
			$debtorReference,
			$debtorReturnURL
		);
		//$this->CreateIdentityTransactionID($debtorReference),
		return $r;
	}


	public function IdentityStatus($transactionID, $entranceCode)
	{

		$r = new IdentityStatusBluemRequest(
			$this->configuration,
			$entranceCode,
			($this->configuration->environment == BLUEM_ENVIRONMENT_TESTING &&
				isset($this->configuration->expected_return) ?
				$this->configuration->expected_return : ""),
			$transactionID
		);

		$response = $this->PerformRequest($r);
		return $response;
	}

	/**
	 * Create a Identity Transaction ID in the required structure, based on the order ID, customer ID and the current timestamp.
	 * @param String $order_id    The order ID
	 * @param String $customer_id The customer ID
	 */
	public function CreateIdentityTransactionID(String $debtorReference): String
	{
		return substr($debtorReference, 0, 28) . Carbon::now()->format('Ymd');
	}



	/**-------------- LEGACY FUNCTIONS --------------*/
	// To be deprecated by generic / universal functions

	/**
	 * Request a transaction status for any type of transaction
	 * 
	 * @param [type] $mandateID [description]
	 */
	public function RequestTransactionStatus($mandateID, $entranceCode)
	{
		// should be deprecated in this manner as this object is now not used solely for mandates.
		return $this->MandateStatus($mandateID, $entranceCode);
	}

	/**
	 * @deprecated Use specific functions instead!
	 * LEGACY Creates a new test transaction and in case of success, return the link to redirect to to get to the BlueM eMandate environment.
	 * @param int $customer_id The Customer ID
	 * @param int $order_id    The Order ID
	 */
	public function CreateNewTransaction(
		$type = "mandate",
		$properties = []
	) {

		throw new Exception("Deprecated function call");
		// echo "Deprecated function";
		// var_dump($type);
		// var_dump($properties);
		die();
		switch ($type) {
			case 'mandate':

				if (!isset($properties['request_type'])) {
					$properties['request_type'] = 'default';
				}
				if (!isset($properties['simple_redirect_url'])) {
					$properties['simple_redirect_url'] = '';
				}

				return $this->CreateMandateRequest($properties['customer_id'], $properties['order_id'], $properties['request_type'], $properties['simple_redirect_url']);
				break;
			case 'payment':
				return $this->CreatePaymentRequest(
					$properties['description'],
					$properties['debtorReference'],
					$properties['amount'],
					$properties['dueDateTime'],
					$properties['currency']
				);
				break;
			default:
				throw new Exception("Type of transaction to create not given or invalid. Should be one of ['mandate', 'payment']");
				break;
		}
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
	 * Perform a request to the BlueM API given a request object and return its response
	 * @param BluemRequest $transaction_request The Request Object
	 */
	public function PerformRequest(BluemRequest $transaction_request)
	{

		// array holding allowed Origin domains
		// $allowedOrigins = array(
		// 	'(http(s)://)?(www\.)\-nextdeli\.com',
		// 	'http://localhost',
		// );

		// if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] != '') {
		// 	echo $_SERVER['HTTP_ORIGIN'];
		// 	foreach ($allowedOrigins as $allowedOrigin) {
		// 		if (preg_match('#' . $allowedOrigin . '#', $_SERVER['HTTP_ORIGIN'])) {
		// 			echo "MATCH";
		// 			header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
		// 			header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
		// 			header('Access-Control-Max-Age: 1000');
		// 			header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
		// 			break;
		// 		}
		// 	}
		// }
		// die();
		
		$verbose = false;
		
		// make sure the timezone is set correctly..
		$now = Carbon::now()->timezone('Europe/Amsterdam');
		
		$xttrs_filename = $transaction_request->transaction_code . "-{$this->configuration->senderID}-BSP1-" . $now->format('YmdHis') . "000.xml";
		
		// $xttrs_date = $now->format("D, d M Y H:i:s") . " GMT";
		// conform Rfc1123 standard in GMT time
		$xttrs_date = $now->toRfc7231String();
		
		$request_url = $transaction_request->HttpRequestUrl();
		
		$req = new BluemHttpRequest();
		
		// $req->setConfig(['store_body'=>true]);
		
		$req->setUrl($request_url);
		$req->setMethod(BluemHttpRequest::METHOD_POST);
		
		$req->setHeader( 'Access-Control-Allow-Origin','*' );
		$req->setHeader("Content-Type", "application/xml; type=" . $transaction_request->transaction_code . "; charset=UTF-8");
		$req->setHeader("x-ttrs-date", $xttrs_date);
		$req->setHeader("x-ttrs-files-count", "1");
		$req->setHeader("x-ttrs-filename", $xttrs_filename);
		if ($verbose) {
			echo PHP_EOL . "<BR>URL// " . $request_url;
			// echo PHP_EOL."<BR>// ". "Content-Type", "application/xml; type=" . $transaction_request->transaction_code . "; charset=UTF-8";

			echo PHP_EOL . "<BR>HEADER// " . "Content-Type: " . "application/xml; type=" . $transaction_request->transaction_code . "; charset=UTF-8";
			echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-date: ' . $xttrs_date;
			echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-files-count: ' . '1';
			echo PHP_EOL . "<BR>HEADER// " . 'x-ttrs-filename: ' . $xttrs_filename;
			echo "<HR>";
			echo PHP_EOL . "BODY: " . $transaction_request->XmlString();
		}
		// var_dump(libxml_get_errors());

		// die();

		$req->setBody($transaction_request->XmlString());
		try {
			// $statusLine = read_status_line();
			// echo $statusLine;
			// echo $http_response->getStatusLine();
			$http_response = $req->send();
			// var_dump($http_response->getHeader(), $http_response->getCookies(), $http_response->getBody());
			// 		echo $http_response->getStatus();

			if ($verbose) {
				echo PHP_EOL . "<BR>RESPONSE// ";
				// 	var_dump($http_response);
				// 	try {
				// 		//code...
				var_dump($http_response->getBody());
			}
			// 	} catch (\Throwable $th) {
			// 		//throw $th;
			// 		echo "DOUBLE TRY";
			// 		echo $th->getMessage();
			// 		die();
			// 	}
			// }	
			switch ($http_response->getStatus()) {
				case 200: {
						if ($http_response->getBody() == "") {
							return new ErrorBluemResponse("Error: Empty response returned");
						}
						// if($verbose) {

						// 	var_dump($http_response->getBody());

						// }

						try {
							$response = new BluemResponse($http_response->getBody());
							//code...
						} catch (\Throwable $th) {
							echo "error in creating response";
							var_dump($th->getMessage());
							die();
							//throw $th;
						}
						// if($verbose) {

						// 					var_dump($response);
						// die();
						// }
						if (!$response->Status()) {

							return new ErrorBluemResponse("Error: " . ($response->Error()->ErrorMessage));
						}
						return $response;

						break;
					}
				case 400: {
						return new ErrorBluemResponse('Your request was not formed correctly.'); // . $http_response->getBody());
						break;
					}
				case 401: {
						return new ErrorBluemResponse('Unauthorized: check your access credentials.');
						break;
					}
				case 500: {
						return new ErrorBluemResponse('An unrecoverable error at the server side occurred while processing the request');
						break;
					}
				default: {
						return new ErrorBluemResponse('Unexpected / erroneous response (code ' . $http_response->getStatus() . ')');
						break;
					}
			}
		} catch (Throwable $e) {
			// echo "KAAS";_
			// die();
			// var_dump($e);
			// throw new Exception('Error: ' . $e->getMessage(), 400 );
			// echo $e->getMessage();
			// die();
		$error = new ErrorBluemResponse('HTTP Request Error');
			return $error;
		}
	}


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



	/**
	 * Webhook for BlueM Mandate signature verification procedure
	 * @return [type] [description]
	 */
	public function Webhook()
	{
		$verbose = false;

		/* Senders provide Bluem with a webhook URL. The URL will be checked for consistency and validity and will not be stored if any of the checks fails. The following checks will be performed:
	
			*/

		// todo: URL must start with https://


		// ONLY Accept post requests
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			if ($verbose) {
				exit("Not post");
			}
			http_response_code(400);
			exit();
		}

		// An empty POST to the URL (normal HTTP request) always has to respond with HTTP 200 OK
		$postData = file_get_contents('php://input');
		// var_dump($postData);
		if ($postData === "") {
			if ($verbose) {
				echo "NO POST";
			}
			http_response_code(200);
			exit();
		}

		// check content type; it has to be: "Content-type", "text/xml; charset=UTF-8"


		// Parsing XML data from POST body
		try {
			$xml_input = new \SimpleXMLElement($postData);
		} catch (Exception $e) {
			if ($verbose) {
				var_dump($e);
				exit();
			}
			http_response_code(400); 		// could not parse XML
			exit();
		}
		// var_dump($xml_input->EPaymentInterface->PaymentStatusUpdate);
		// check if signature is valid in postdata
		if (!$this->_validateWebhookSignature($postData)) {
			if ($verbose) {
				exit('no valid webhook sig');
			}

			http_response_code(400);
			// echo 'The XML signature is not valid.';
			// echo PHP_EOL;
			exit;
		}

		// valid!
		// echo $postData;
		// echo "<hr>Input";
		// var_dump($xml_input);

		if (!isset($xml_input->EPaymentInterface->PaymentStatusUpdate)) {
			http_response_code(400);
			exit;
		}

		$status_update = $xml_input->EPaymentInterface->PaymentStatusUpdate;
		return $status_update;
	}


	private function _validateWebhookSignature($xml_input)
	{
		$temp_file = tmpfile();
		fwrite($temp_file, $xml_input);
		$temp_file_path = stream_get_meta_data($temp_file)['uri'];

		$signatureValidator = new XmlSignatureValidator();

		// @todo Check if keyfile has to be chosen according to env
		// if ($this->configuration->environment === BLUEM_ENVIRONMENT_TESTING) {
		// $public_key_file = "webhook.bluem.nl_pub_cert_test.crt";
		// } else {
		// $public_key_file = "webhook.bluem.nl_pub_key_production.crt";
		// }
		$key_folder =
			$public_key_file = "bluem_nl.crt";
		$public_key_file_path = __DIR__ . "/../keys/" . $public_key_file;
		// TODO: put the key in a different folder, relative to this PHP library
		// echo $public_key_file_path;
		// die();

		try {
			$signatureValidator->loadPublicKeyFile($public_key_file_path);
		} catch (\Throwable $th) {
			return false;
			// echo "Fout: " . $th->getMessage();
			// exit;
		}

		$isValid = $signatureValidator->verifyXmlFile($temp_file_path);
		fclose($temp_file);

		if ($isValid) {
			return true;
		}
		return false;
	}
}
