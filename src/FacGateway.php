<?php
/**
 * The FacGateway class file.
 *
 * @package    Mazepress\Gateway
 * @subpackage Fac
 */

declare(strict_types=1);

namespace Mazepress\Gateway\Fac;

use Mazepress\Gateway\Payment;
use Mazepress\Gateway\Transaction;
use SoapClient;

/**
 * The FacGateway abstract class.
 */
class FacGateway extends Payment {

	/**
	 * The PRODUCTION endpoint.
	 *
	 * @var string PRODUCTION
	 */
	const PRODUCTION = 'https://marlin.firstatlanticcommerce.com/PGService/Services.svc?wsdl';

	/**
	 * The SANDBOX endpoint.
	 *
	 * @var string SANDBOX
	 */
	const SANDBOX = 'https://ecm.firstatlanticcommerce.com/PGService/Services.svc?wsdl';

	/**
	 * The public_key.
	 *
	 * @var string $public_key
	 */
	private $public_key;

	/**
	 * The private_key.
	 *
	 * @var string $private_key
	 */
	private $private_key;

	/**
	 * The acquirer id
	 *
	 * Acquirer is always 464748.
	 *
	 * @var string $acquirer_id
	 */
	private $acquirer_id = '464748';

	/**
	 * The live mode flag.
	 *
	 * @var bool $is_live
	 */
	private $is_live = false;

	/**
	 * The soap client object.
	 *
	 * @var SoapClient $client
	 */
	private $client;

	/**
	 * Initiate class.
	 *
	 * @param string $public_key  The public key.
	 * @param string $private_key The private key.
	 * @param bool   $live        Live mode.
	 */
	public function __construct( string $public_key, string $private_key, bool $live = false ) {
		$this->set_public_key( $public_key );
		$this->set_private_key( $private_key );
		$this->set_is_live( $live );
	}

	/**
	 * Process the payment. If the payment fails,
	 * it should return a WP_Error object.
	 *
	 * @return Transaction|\WP_Error
	 */
	public function process() {

		// Validate the credentials.
		$validate = $this->validate_credentials();
		if ( is_wp_error( $validate ) ) {
			return $validate;
		}

		$billing    = $this->get_address();
		$card       = $this->get_card();
		$amount     = $this->get_amount();
		$amount_pad = str_pad( (string) sprintf( '%s00', $amount ), 12, '0', STR_PAD_LEFT );

		$hashval = sprintf(
			'%s%s%s%s%s%s',
			$this->get_private_key(),
			$this->get_public_key(),
			$this->get_acquirer_id(),
			(string) $this->get_invoice_id(),
			$amount_pad,
			$this->get_currency_code()
		);

		//phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$signature = base64_encode( sha1( $hashval, true ) );

		// Set the Billing address.
		$billing_details = array(
			'BillToFirstName'   => (string) $billing->get_first_name(),
			'BillToLastName'    => (string) $billing->get_last_name(),
			'BillToEmail'       => (string) $billing->get_email(),
			'BillToMobile'      => (string) $billing->get_phone(),
			'BillToAddress'     => (string) $billing->get_address1(),
			'BillToCity'        => (string) $billing->get_city(),
			'BillToCounty'      => (string) $billing->get_state(),
			'BillToZipPostCode' => (string) $billing->get_zip(),
			'BillToCountry'     => (string) $billing->get_country_code(),
		);

		// Credit Card Details.
		$card_details = array(
			'CardNumber'     => (string) $card->get_number(),
			'CardExpiryDate' => (string) $card->get_expiry(),
			'CardCVV2'       => (string) $card->get_cvv(),
			'IssueNumber'    => '',
			'StartDate'      => '',
		);

		// Transaction Details.
		$transaction_details = array(
			'AcquirerId'       => (string) $this->get_acquirer_id(),
			'Amount'           => $amount_pad,
			'Currency'         => $this->get_currency_code(),
			'CurrencyExponent' => $this->get_currency_exponent(),
			'IPAddress'        => '',
			'MerchantId'       => (string) $this->get_public_key(),
			'OrderNumber'      => (string) $this->get_invoice_id(),
			'Signature'        => $signature,
			'SignatureMethod'  => 'SHA1',
			'TransactionCode'  => '8',
		);

		// The request data is named 'Request' for reasons that are not clear!.
		$request = array(
			'Request' => array(
				'CardDetails'        => $card_details,
				'TransactionDetails' => $transaction_details,
				'BillingDetails'     => $billing_details,
			),
		);

		try {
			// Create a new SoapClient.
			$client = ( ! is_null( $this->get_client() ) ) ? $this->get_client() : new SoapClient(
				$this->get_endpoint(),
				array(
					'soap_version' => SOAP_1_1,
					'exceptions'   => true,
					'trace'        => true,
					'cache_wsdl'   => WSDL_CACHE_NONE,
				)
			);

			// Call the Authorize through the Client.
			$response = $client->Authorize( $request );

		} catch ( \SoapFault $fault ) {
			return new \WP_Error( 'soap_broke', $fault->getMessage() );
		}

		//phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( ! isset( $response->AuthorizeResult->CreditCardTransactionResults->ResponseCode ) ) {
			return new \WP_Error( 'invalid_response', __( 'Invalid response from the gateway.', 'gatewayfac' ) );
		}

		$code   = (int) $response->AuthorizeResult->CreditCardTransactionResults->ResponseCode;
		$status = ( 1 === $code ) ? 'Paid' : 'Pending';

		$transaction = ( new Transaction() )
			->set_status( $status )
			->set_code( (string) $code );

		if ( isset( $response->AuthorizeResult->OrderNumber ) ) {
			$transaction->set_transaction_id( $response->AuthorizeResult->OrderNumber );
		}

		if ( isset( $response->AuthorizeResult->CreditCardTransactionResults->ReferenceNumber ) ) {
			$transaction->set_reference_id(
				$response->AuthorizeResult->CreditCardTransactionResults->ReferenceNumber
			);
		}

		if ( isset( $response->AuthorizeResult->CreditCardTransactionResults->ReasonCodeDescription ) ) {
			$transaction->set_message(
				$response->AuthorizeResult->CreditCardTransactionResults->ReasonCodeDescription
			);
		}

		//phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return $transaction;
	}

	/**
	 * Get the public key.
	 *
	 * @return string|null
	 */
	public function get_public_key(): ?string {
		return $this->public_key;
	}

	/**
	 * Set the public key.
	 *
	 * @param string $public_key The public key.
	 *
	 * @return self
	 */
	public function set_public_key( string $public_key ): self {
		$this->public_key = $public_key;
		return $this;
	}

	/**
	 * Get the private key.
	 *
	 * @return string|null
	 */
	public function get_private_key(): ?string {
		return $this->private_key;
	}

	/**
	 * Set the private key.
	 *
	 * @param string $private_key The private key.
	 *
	 * @return self
	 */
	public function set_private_key( string $private_key ): self {
		$this->private_key = $private_key;
		return $this;
	}

	/**
	 * Get the live mode.
	 *
	 * @return bool
	 */
	public function get_is_live(): bool {
		return $this->is_live;
	}

	/**
	 * Set the live mode.
	 *
	 * @param bool $is_live The live mode.
	 *
	 * @return self
	 */
	public function set_is_live( bool $is_live ): self {
		$this->is_live = $is_live;
		return $this;
	}

	/**
	 * Get the acquirer id.
	 *
	 * @return string
	 */
	public function get_acquirer_id(): string {
		return $this->acquirer_id;
	}

	/**
	 * Set the acquirer id.
	 *
	 * @param string $acquirer_id The acquirer id.
	 *
	 * @return self
	 */
	public function set_acquirer_id( string $acquirer_id ): self {
		$this->acquirer_id = $acquirer_id;
		return $this;
	}

	/**
	 * Get the endpoint.
	 *
	 * @return string
	 */
	public function get_endpoint(): string {
		return $this->is_live ? self::PRODUCTION : self::SANDBOX;
	}

	/**
	 * Get the soap client.
	 *
	 * @return SoapClient|null
	 */
	public function get_client(): ?SoapClient {
		return $this->client;
	}

	/**
	 * Set the soap client.
	 *
	 * @param SoapClient $client The soap client.
	 *
	 * @return self
	 */
	public function set_client( SoapClient $client ): self {
		$this->client = $client;
		return $this;
	}

	/**
	 * Valdiate the minimum requirements.
	 *
	 * @return bool|\WP_Error
	 */
	private function validate_credentials() {

		// Check the public key.
		if ( empty( $this->get_public_key() ) ) {
			return new \WP_Error( 'invalid_public_key', __( 'Invalid public key.', 'gatewayfac' ) );
		}

		// Check the private key.
		if ( empty( $this->get_private_key() ) ) {
			return new \WP_Error( 'invalid_private_key', __( 'Invalid private key.', 'gatewayfac' ) );
		}

		// Check the acquirer id.
		if ( empty( $this->get_acquirer_id() ) ) {
			return new \WP_Error( 'invalid_acquirer_id', __( 'Invalid acquirer id.', 'gatewayfac' ) );
		}

		// Check the amount.
		$amount = $this->get_amount();
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_amount', __( 'Invalid amount.', 'gatewayfac' ) );
		}

		// Check the card.
		$card = $this->get_card();
		if ( is_null( $card ) ) {
			return new \WP_Error( 'invalid_card', __( 'Invalid credit card.', 'gatewayfac' ) );
		}

		// Check the address.
		$address = $this->get_address();
		if ( is_null( $address ) ) {
			return new \WP_Error( 'invalid_address', __( 'Invalid billing address.', 'gatewayfac' ) );
		}

		return true;
	}
}
