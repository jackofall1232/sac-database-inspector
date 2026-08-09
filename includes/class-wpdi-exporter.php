<?php
/**
 * Report exporters backed only by the normalized report.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts a redacted central report into supported formats.
 */
class WPDI_Exporter {

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
	 * Export a report.
	 *
	 * @param array  $report Normalized report.
	 * @param string $format json, csv, or html.
	 * @return array|WP_Error Content, MIME type, and extension.
	 */
	public function export( $report, $format ) {
		$report = $this->redactor->redact( $report );
		$format = sanitize_key( $format );

		if ( 'json' === $format ) {
			return array(
				'content'   => wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'mime_type' => 'application/json',
				'extension' => 'json',
			);
		}

		if ( 'csv' === $format ) {
			return array(
				'content'   => $this->to_csv( $report ),
				'mime_type' => 'text/csv',
				'extension' => 'csv',
			);
		}

		if ( 'html' === $format ) {
			return array(
				'content'   => $this->to_html( $report ),
				'mime_type' => 'text/html',
				'extension' => 'html',
			);
		}

		return new WP_Error( 'wpdi_invalid_export', __( 'Invalid export format.', 'sac-database-inspector' ) );
	}

	/**
	 * Create a deliberately simple, interoperable CSV.
	 *
	 * @param array $report Redacted report.
	 * @return string
	 */
	private function to_csv( $report ) {
		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream; no filesystem path is accessed.
		$this->write_csv_row( $stream, array( 'section', 'artifact', 'type_or_status', 'size_or_count', 'owner', 'confidence', 'reason' ) );

		$this->write_csv_row( $stream, array( 'health', 'score', $report['health']['rating'], $report['health']['score'], '', '', '' ) );
		foreach ( $report['health']['explanation'] as $penalty ) {
			$this->write_csv_row( $stream, array( 'health', $penalty['code'], $penalty['category'], $penalty['points'], '', '', $penalty['reason'] ) );
		}
		foreach ( $report['plugins'] as $plugin ) {
			$this->write_csv_row( $stream, array( 'plugin', $plugin['plugin'], $plugin['status'], $plugin['estimated_total'], $plugin['slug'], $plugin['confidence'], '' ) );
		}
		foreach ( $report['tables'] as $table ) {
			$this->write_csv_row( $stream, array( 'table', $table['name'], $table['owner_status'], $table['size'], $table['owner'], $table['confidence'], $table['reason'] ) );
		}
		foreach ( $report['autoload']['items'] as $option ) {
			$this->write_csv_row( $stream, array( 'autoload', $option['name'], $option['autoload'], $option['size'], $option['owner'], $option['confidence'], $option['reason'] ) );
		}
		foreach ( $report['ghost_data'] as $finding ) {
			$this->write_csv_row( $stream, array( 'ghost_data', $finding['artifact'], $finding['type'], isset( $finding['size'] ) ? $finding['size'] : $finding['count'], $finding['owner'], $finding['confidence'], $finding['reason'] ) );
		}

		rewind( $stream );
		$content = stream_get_contents( $stream );
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory stream.
		return $content;
	}

	/**
	 * Write one CSV row while neutralizing spreadsheet formulas.
	 *
	 * @param resource $stream In-memory stream.
	 * @param array    $row    Cell values.
	 */
	private function write_csv_row( $stream, $row ) {
		$row = array_map(
			static function ( $value ) {
				$value = (string) $value;
				return preg_match( '/^[=+\-@]/', $value ) ? "'" . $value : $value;
			},
			$row
		);
		// PHP 8.4 deprecates relying on the default $escape; keep the historical value explicitly.
		fputcsv( $stream, $row, ',', '"', '\\' );
	}

	/**
	 * Create a standalone escaped HTML report.
	 *
	 * @param array $report Redacted report.
	 * @return string
	 */
	private function to_html( $report ) {
		$title = esc_html__( 'SAC Database Inspector Diagnostic Report', 'sac-database-inspector' );
		$json  = esc_html( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>' . $title . '</title><style>body{font:14px/1.5 sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#1d2327}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f6f7f7;border:1px solid #c3c4c7;padding:1rem}</style></head><body><h1>' . $title . '</h1><p>' . esc_html__( 'This metadata-only report was redacted before export.', 'sac-database-inspector' ) . '</p><pre>' . $json . '</pre></body></html>';
	}
}
