<?php
/**
 * The FacGatewayTest class file.
 *
 * @package    Mazepress\Gateway\Fac
 * @subpackage Tests
 */

declare(strict_types=1);

namespace Mazepress\Gateway\Fac\Tests;

use Mazepress\Gateway\Payment;
use Mazepress\Gateway\Address;
use Mazepress\Gateway\CreditCard;
use Mazepress\Gateway\Transaction;
use Mazepress\Gateway\Fac\FacGateway;
use Mockery;
use WP_Mock;
use SoapClient;

/**
 * The FacGatewayTest class.
 */
class FacGatewayTest extends WP_Mock\Tools\TestCase {

	/**
	 * Test class properites.
	 *
	 * @return void
	 */
	public function test_properties(): void {

		$object = new FacGateway();

		$this->assertInstanceOf( FacGateway::class, $object->set_public_key( 'public1' ) );
		$this->assertEquals( 'public1', $object->get_public_key() );

		$this->assertInstanceOf( FacGateway::class, $object->set_private_key( 'private1' ) );
		$this->assertEquals( 'private1', $object->get_private_key() );

		$this->assertEquals( '464748', $object->get_acquirer_id() );
		$this->assertInstanceOf( FacGateway::class, $object->set_acquirer_id( '464749' ) );
		$this->assertEquals( '464749', $object->get_acquirer_id() );

		$this->assertFalse( $object->get_is_live() );
		$this->assertInstanceOf( FacGateway::class, $object->set_is_live( true ) );
		$this->assertTrue( $object->get_is_live() );

		$this->assertEquals( $object::PRODUCTION, $object->get_endpoint() );

		$wsdl = 'https://www.dataaccess.com/webservicesserver/NumberConversion.wso?WSDL';
		$this->assertInstanceOf( FacGateway::class, $object->set_client( new SoapClient( $wsdl ) ) );
		$this->assertInstanceOf( SoapClient::class, $object->get_client() );
	}

	/**
	 * Test validate credentials.
	 *
	 * @return void
	 */
	public function test_validate_credentials(): void {

		$object = new FacGateway();
		$method = $this->getInaccessibleMethod( $object, 'validate_credentials' );

		// Check for valid public_key.
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_public_key', $output->get_error_code() );
		$object->set_public_key( 'public1' );

		// Check for valid private_key.
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_private_key', $output->get_error_code() );
		$object->set_private_key( 'private1' );

		// Check for valid aquirer_id.
		$object->set_acquirer_id( '' );
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_acquirer_id', $output->get_error_code() );
		$object->set_acquirer_id( '464748' );

		// Check for valid address.
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_amount', $output->get_error_code() );
		$object->set_amount( 100 );

		// Check for valid card.
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_card', $output->get_error_code() );
		$object->set_card( new CreditCard() );

		// Check for valid address.
		$output = $method->invoke( $object );
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_address', $output->get_error_code() );
		$object->set_address( new Address() );

		// Check for valid return.
		$output = $method->invoke( $object );
		$this->assertTrue( $output );
	}

	/**
	 * Test process payment.
	 *
	 * @return void
	 */
	public function test_process_success(): void {

		$object = new FacGateway( false );
		$object->set_public_key( 'public1' );
		$object->set_private_key( 'private1' );
		$object->set_acquirer_id( '464748' );
		$object->set_amount( 100 );
		$object->set_card( new CreditCard() );
		$object->set_address( new Address() );

		$client = Mockery::mock( SoapClient::class );

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'Authorize' )
			->once()
			->andReturn(
				(object) array(
					'AuthorizeResult' => (object) array(
						'CreditCardTransactionResults' => (object) array(
							'ResponseCode'          => 1,
							'ReferenceNumber'       => 'REF12345',
							'ReasonCodeDescription' => 'Card charged successfully',
						),
						'OrderNumber'                  => 'ORD12345',
					),
				)
			);

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->process();
		$this->assertInstanceOf( Transaction::class, $output );
		$this->assertEquals( 'Paid', $output->get_status() );
		$this->assertEquals( 'REF12345', $output->get_reference_id() );
		$this->assertEquals( 'ORD12345', $output->get_transaction_id() );
		$this->assertEquals( 'Card charged successfully', $output->get_message() );
	}

	/**
	 * Test process payment.
	 *
	 * @return void
	 */
	public function test_process_failure(): void {

		$object = new FacGateway( false );
		$output = $object->process();
		$this->assertInstanceOf( \WP_Error::class, $output );
	}

	/**
	 * Test process payment.
	 *
	 * @return void
	 */
	public function test_process_exception(): void {

		$object = new FacGateway( false );
		$object->set_public_key( 'public1' );
		$object->set_private_key( 'private1' );
		$object->set_acquirer_id( '464748' );
		$object->set_amount( 100 );
		$object->set_card( new CreditCard() );
		$object->set_address( new Address() );

		$client = Mockery::mock( SoapClient::class );

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'Authorize' )
			->once()
			->andThrow( new \SoapFault( 'soap_broke', 'An error occurred' ) );

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->process();
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'soap_broke', $output->get_error_code() );
		$this->assertEquals( 'An error occurred', $output->get_error_message() );
	}

	/**
	 * Test process payment.
	 *
	 * @return void
	 */
	public function test_process_invalid_response(): void {

		$object = new FacGateway( false );
		$object->set_public_key( 'public1' );
		$object->set_private_key( 'private1' );
		$object->set_acquirer_id( '464748' );
		$object->set_amount( 100 );
		$object->set_card( new CreditCard() );
		$object->set_address( new Address() );

		$client = Mockery::mock( SoapClient::class );

		// @phpstan-ignore-next-line
		$client->shouldReceive( 'Authorize' )
			->once()
			->andReturn( null );

		// @phpstan-ignore-next-line
		$object->set_client( $client );

		$output = $object->process();
		$this->assertInstanceOf( \WP_Error::class, $output );
		$this->assertEquals( 'invalid_response', $output->get_error_code() );
	}
}
