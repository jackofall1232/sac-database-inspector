<?php
/**
 * Optional WordPress AI Client advisory integration.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends an explicitly requested, redacted metadata report through WordPress core.
 */
class WPDI_AI {

	/**
	 * Redaction boundary.
	 *
	 * @var WPDI_Redactor
	 */
	private $redactor;

	/**
	 * Constructor.
	 *
	 * @param WPDI_Redactor $redactor Redaction service.
	 */
	public function __construct( WPDI_Redactor $redactor ) {
		$this->redactor = $redactor;
	}

	/**
	 * Determine whether configured text generation is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$builder = wp_ai_client_prompt( 'SAC Database Inspector capability check.' );
		return is_object( $builder ) && $builder->is_supported_for_text_generation();
	}

	/**
	 * Explain deterministic findings. This method cannot expose mutation tools.
	 *
	 * @param array  $report Normalized report.
	 * @param string $focus  Requested explanation focus.
	 * @return array|WP_Error
	 */
	public function explain( $report, $focus ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'wpdi_ai_unavailable', __( 'WordPress AI text generation is unavailable or no compatible provider is configured.', 'sac-database-inspector' ) );
		}

		$allowed_focus = array( 'health', 'footprints', 'ghost_data', 'autoload', 'priorities' );
		$focus         = in_array( $focus, $allowed_focus, true ) ? $focus : 'health';
		$context       = $this->build_context( $report );
		$prompt        = "You are explaining a deterministic SAC Database Inspector report to a WordPress administrator.\n"
			. "The report facts and health score are authoritative; do not recalculate, override, or invent them.\n"
			. "Give concise, cautious investigation advice. Do not provide executable SQL or claim that heuristic ownership is certain.\n"
			. "Clearly label your response as interpretation, not a measured fact. Focus: {$focus}.\n\n"
			. wp_json_encode( $context, JSON_UNESCAPED_SLASHES );

		// No sampling parameters are forced: several providers and models
		// (notably reasoning models) reject an explicit temperature with a
		// 400 error, and provider defaults are appropriate for advisory text.
		try {
			$result = wp_ai_client_prompt( $prompt )->generate_text_result();
		} catch ( Throwable $exception ) {
			$detail = sanitize_text_field( (string) $exception->getMessage() );
			if ( strlen( $detail ) > 200 ) {
				$detail = substr( $detail, 0, 200 ) . '…';
			}
			return new WP_Error(
				'wpdi_ai_request_failed',
				sprintf(
					/* translators: %s: short provider error detail. */
					__( 'The AI request could not be completed: %s', 'sac-database-inspector' ),
					$detail
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_object( $result ) || ! method_exists( $result, 'toText' ) ) {
			return new WP_Error( 'wpdi_ai_invalid_result', __( 'The AI provider returned an unsupported response.', 'sac-database-inspector' ) );
		}

		try {
			$text = $result->toText();
		} catch ( Throwable $exception ) {
			return new WP_Error( 'wpdi_ai_text_conversion_failed', __( 'The AI provider response could not be converted to text.', 'sac-database-inspector' ) );
		}
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error( 'wpdi_ai_empty_result', __( 'The AI provider returned an empty explanation.', 'sac-database-inspector' ) );
		}

		return array(
			'explanation' => sanitize_textarea_field( $text ),
			'advisory'    => true,
			'label'       => __( 'AI interpretation', 'sac-database-inspector' ),
		);
	}

	/**
	 * Build a capped, metadata-only AI context.
	 *
	 * @param array $report Normalized report.
	 * @return array
	 */
	private function build_context( $report ) {
		$context = array(
			'schema_version' => $report['schema_version'],
			'health'         => $report['health'],
			'database'       => $report['database'],
			'plugins'        => array_slice( $report['plugins'], 0, 50 ),
			'autoload'       => array_slice( $report['autoload']['items'], 0, 50 ),
			'ghost_data'     => array_slice( $report['ghost_data'], 0, 50 ),
			'tables'         => array_slice( $report['tables'], 0, 50 ),
			'warnings'       => $report['warnings'],
		);

		return $this->redactor->redact( $context );
	}
}
