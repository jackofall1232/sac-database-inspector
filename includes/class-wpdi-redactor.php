<?php
/**
 * Central sensitive-data redaction.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redacts diagnostic data before export or AI use.
 */
class WPDI_Redactor {

	/**
	 * Patterns which indicate a potentially sensitive field or artifact name.
	 *
	 * @var string[]
	 */
	private $sensitive_patterns = array(
		'password',
		'passwd',
		'secret',
		'token',
		'api_key',
		'apikey',
		'auth_key',
		'private_key',
		'client_secret',
		'credential',
		'database_url',
		'db_password',
		'cookiehash',
		'salt',
		'authorization',
		'access_key',
		'consumer_secret',
	);

	/**
	 * Determine whether a name is potentially sensitive.
	 *
	 * @param string $name Field or artifact name.
	 * @return bool
	 */
	public function is_sensitive_name( $name ) {
		$name = strtolower( (string) $name );

		foreach ( $this->sensitive_patterns as $pattern ) {
			if ( false !== strpos( $name, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively sanitize a report for an external boundary.
	 *
	 * Report generation intentionally contains metadata rather than values. This
	 * second boundary check prevents a future report extension from leaking values.
	 *
	 * @param mixed  $data        Data to redact.
	 * @param string $parent_key  Parent key.
	 * @return mixed
	 */
	public function redact( $data, $parent_key = '' ) {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}

		if ( ! is_array( $data ) ) {
			if ( $this->is_sensitive_name( $parent_key ) ) {
				return '[redacted]';
			}

			return is_string( $data ) ? $this->redact_string( $data ) : $data;
		}

		$redacted = array();
		foreach ( $data as $key => $value ) {
			$key_string = is_string( $key ) ? $key : $parent_key;
			if ( $this->is_sensitive_name( $key_string ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}

			$redacted[ $key ] = $this->redact( $value, $key_string );
		}

		return $redacted;
	}

	/**
	 * Redact common secret formats from free text.
	 *
	 * @param string $value Input string.
	 * @return string
	 */
	private function redact_string( $value ) {
		if ( strlen( $value ) > 10000 ) {
			$value = substr( $value, 0, 10000 ) . '[truncated]';
		}

		$patterns = array(
			'/(-----BEGIN [A-Z ]*PRIVATE KEY-----).*?(-----END [A-Z ]*PRIVATE KEY-----)/s' => '$1[redacted]$2',
			'/\b(?:sk|pk|rk)-[A-Za-z0-9_-]{16,}\b/' => '[redacted-token]',
			'/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i' => 'Bearer [redacted]',
		);

		return preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );
	}
}
