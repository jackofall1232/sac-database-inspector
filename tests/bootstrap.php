<?php
/**
 * Minimal WordPress function stubs for side-effect-free unit tests.
 *
 * @package WPDI
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'WPDI_VERSION', '1.1.2' );

class WP_Error {
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $text ) {
	return $text;
}

function esc_html__( $text ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function is_multisite() {
	return false;
}

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wpdi_test_database' );
}

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $value ) );
}

function get_option( $name, $default_value = false ) {
	return $default_value;
}

function get_plugins() {
	return array();
}

require_once dirname( __DIR__ ) . '/includes/class-wpdi-redactor.php';
require_once dirname( __DIR__ ) . '/includes/class-wpdi-ownership.php';
require_once dirname( __DIR__ ) . '/includes/class-wpdi-exporter.php';
require_once dirname( __DIR__ ) . '/includes/class-wpdi-ai.php';
require_once dirname( __DIR__ ) . '/includes/class-wpdi-report.php';
