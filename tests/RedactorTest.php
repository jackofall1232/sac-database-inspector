<?php
/** @package WPDI */

use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	public function test_sensitive_keys_are_redacted_recursively() {
		$redactor = new WPDI_Redactor();
		$result   = $redactor->redact(
			array(
				'health' => array( 'score' => 12 ),
				'api_key' => 'secret-value',
				'nested' => array( 'client_secret' => 'another-secret' ),
			)
		);

		$this->assertSame( 12, $result['health']['score'] );
		$this->assertSame( '[redacted]', $result['api_key'] );
		$this->assertSame( '[redacted]', $result['nested']['client_secret'] );
	}

	public function test_token_and_private_key_shapes_are_redacted_from_text() {
		$redactor = new WPDI_Redactor();
		$result   = $redactor->redact( "Bearer abcdefghijklmnop.qrstuv\nsk-1234567890abcdefghijkl\n-----BEGIN PRIVATE KEY-----value-----END PRIVATE KEY-----" );

		$this->assertStringNotContainsString( 'abcdefghijklmnop.qrstuv', $result );
		$this->assertStringNotContainsString( '1234567890abcdefghijkl', $result );
		$this->assertStringNotContainsString( 'value', $result );
	}
}
