<?php
/** @package WPDI */

use PHPUnit\Framework\TestCase;

final class ExporterTest extends TestCase {

	public function test_json_export_uses_central_redaction() {
		$exporter = new WPDI_Exporter( new WPDI_Redactor() );
		$result   = $exporter->export( array( 'health' => array( 'score' => 5 ), 'password' => 'do-not-export' ), 'json' );
		$decoded  = json_decode( $result['content'], true );

		$this->assertSame( 'application/json', $result['mime_type'] );
		$this->assertSame( 5, $decoded['health']['score'] );
		$this->assertSame( '[redacted]', $decoded['password'] );
	}

	public function test_invalid_format_fails_closed() {
		$exporter = new WPDI_Exporter( new WPDI_Redactor() );
		$result   = $exporter->export( array(), 'xml' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_csv_neutralizes_spreadsheet_formulas() {
		$report = array(
			'health'     => array( 'rating' => 'good', 'score' => 0, 'explanation' => array() ),
			'plugins'    => array(),
			'tables'     => array(),
			'autoload'   => array(
				'items' => array(
					array(
						'name'         => '=DANGEROUS()',
						'autoload'     => 'on',
						'size'         => 1,
						'owner'        => 'Unknown',
						'confidence'   => 'unknown',
						'reason'       => 'No evidence.',
					),
				),
			),
			'ghost_data' => array(),
		);
		$exporter = new WPDI_Exporter( new WPDI_Redactor() );
		$result   = $exporter->export( $report, 'csv' );

		$this->assertStringContainsString( "'=DANGEROUS()", $result['content'] );
	}
}
