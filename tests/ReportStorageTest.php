<?php
/** @package WPDI */

use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb double for exercising the table-metadata result contract.
 */
final class WPDI_Fake_Wpdb {

	public $base_prefix = 'wp_';
	public $options     = 'wp_options';
	public $last_error  = '';
	public $results     = array();

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function prepare( $query, ...$args ) {
		return $query;
	}

	public function get_results( $query = null ) {
		return $this->results;
	}
}

/**
 * Verifies every realistic information_schema result shape is handled
 * without PHP warnings, notices, or deprecations.
 */
final class ReportStorageTest extends TestCase {

	/**
	 * Errors captured by the strict handler.
	 *
	 * @var array
	 */
	private $php_errors = array();

	protected function setUp(): void {
		$this->php_errors = array();
		set_error_handler(
			function ( $errno, $errstr, $errfile, $errline ) {
				$this->php_errors[] = "$errstr in $errfile:$errline";
				return true;
			}
		);
	}

	protected function tearDown(): void {
		restore_error_handler();
	}

	private function storage_health( $results, $last_error = '' ) {
		global $wpdb;

		$wpdb             = new WPDI_Fake_Wpdb();
		$wpdb->results    = $results;
		$wpdb->last_error = $last_error;

		$report = new WPDI_Report( new WPDI_Ownership() );
		$method = new ReflectionMethod( WPDI_Report::class, 'get_storage_health' );
		$method->setAccessible( true );

		return $method->invoke( $report );
	}

	private function assertNoPhpErrors() {
		$this->assertSame( array(), $this->php_errors, 'PHP warnings/notices/deprecations were raised.' );
	}

	public function test_expected_aliased_row_shape() {
		$storage = $this->storage_health(
			array(
				(object) array(
					'table_name'   => 'wp_options',
					'data_length'  => 1000,
					'index_length' => 500,
					'data_free'    => 0,
					'table_rows'   => 100,
					'engine'       => 'InnoDB',
				),
				(object) array(
					'table_name'   => 'wp_posts',
					'data_length'  => 2000,
					'index_length' => 1000,
					'data_free'    => 10,
					'table_rows'   => 5,
					'engine'       => 'InnoDB',
				),
			)
		);

		$this->assertNoPhpErrors();
		$this->assertTrue( $storage['information_schema_access'] );
		$this->assertSame( 4500, $storage['total_size'] );
		$this->assertSame( 1500, $storage['options_table_size'] );
		$this->assertCount( 2, $storage['tables'] );
		$this->assertSame( 'wp_options', $storage['tables'][0]['name'] );
		$this->assertSame( 'InnoDB', $storage['tables'][0]['engine'] );
		$this->assertSame( 'WordPress Core', $storage['tables'][0]['owner'] );
	}

	public function test_mysql8_uppercase_unaliased_row_shape_is_still_consumed() {
		$storage = $this->storage_health(
			array(
				(object) array(
					'TABLE_NAME'   => 'wp_options',
					'DATA_LENGTH'  => 1000,
					'INDEX_LENGTH' => 500,
					'DATA_FREE'    => 0,
					'TABLE_ROWS'   => 100,
					'ENGINE'       => 'InnoDB',
				),
			)
		);

		$this->assertNoPhpErrors();
		$this->assertSame( 1500, $storage['total_size'] );
		$this->assertSame( 'wp_options', $storage['tables'][0]['name'] );
	}

	public function test_nullable_metric_values() {
		$storage = $this->storage_health(
			array(
				(object) array(
					'table_name'   => 'wp_example',
					'data_length'  => null,
					'index_length' => null,
					'data_free'    => null,
					'table_rows'   => null,
					'engine'       => null,
				),
			)
		);

		$this->assertNoPhpErrors();
		$this->assertTrue( $storage['information_schema_access'] );
		$this->assertSame( 0, $storage['total_size'] );
		$this->assertSame( 0, $storage['tables'][0]['size'] );
		$this->assertSame( 0, $storage['tables'][0]['row_count'] );
		$this->assertSame( '', $storage['tables'][0]['engine'] );
	}

	public function test_null_table_name_row_is_skipped() {
		$storage = $this->storage_health(
			array(
				(object) array(
					'table_name'   => null,
					'data_length'  => 100,
					'index_length' => 100,
					'data_free'    => null,
					'table_rows'   => null,
					'engine'       => null,
				),
			)
		);

		$this->assertNoPhpErrors();
		$this->assertSame( array(), $storage['tables'] );
	}

	public function test_empty_query_result() {
		$storage = $this->storage_health( array() );

		$this->assertNoPhpErrors();
		$this->assertSame( array(), $storage['tables'] );
		$this->assertSame( 0, $storage['total_size'] );
		$this->assertSame( 0, $storage['large_table_count'] );
	}

	public function test_failed_query_null_result() {
		$storage = $this->storage_health( null );

		$this->assertNoPhpErrors();
		$this->assertFalse( $storage['information_schema_access'] );
		$this->assertSame( array(), $storage['tables'] );
		$this->assertSame( 0, $storage['total_size'] );
	}

	public function test_restricted_information_schema_error() {
		$storage = $this->storage_health( array(), 'SELECT command denied to user' );

		$this->assertNoPhpErrors();
		$this->assertFalse( $storage['information_schema_access'] );
		$this->assertSame( array(), $storage['tables'] );
	}

	public function test_storage_health_is_collected_once_per_request() {
		global $wpdb;

		$wpdb          = new WPDI_Fake_Wpdb();
		$wpdb->results = array(
			(object) array(
				'table_name'   => 'wp_options',
				'data_length'  => 1000,
				'index_length' => 500,
				'data_free'    => 0,
				'table_rows'   => 100,
				'engine'       => 'InnoDB',
			),
		);

		$report = new WPDI_Report( new WPDI_Ownership() );
		$method = new ReflectionMethod( WPDI_Report::class, 'get_storage_health' );
		$method->setAccessible( true );

		$first = $method->invoke( $report );
		// A second call must reuse the collected data, not re-query.
		$wpdb->results = null;
		$second        = $method->invoke( $report );

		$this->assertNoPhpErrors();
		$this->assertSame( $first, $second );
	}
}
