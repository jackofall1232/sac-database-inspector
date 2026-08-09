<?php
/**
 * Deterministic central diagnostic report.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the normalized report consumed by every presentation layer.
 */
class WPDI_Report {

	/**
	 * Ownership service.
	 *
	 * @var WPDI_Ownership
	 */
	private $ownership;

	/**
	 * Per-request generated report cache keyed by normalized arguments.
	 *
	 * @var array
	 */
	private $report_cache = array();

	/**
	 * Per-request storage health cache.
	 *
	 * @var array|null
	 */
	private $storage_cache = null;

	/**
	 * Per-request runtime health cache.
	 *
	 * @var array|null
	 */
	private $runtime_cache = null;

	/**
	 * Per-request maintenance health cache.
	 *
	 * @var array|null
	 */
	private $maintenance_cache = null;

	/**
	 * Per-request artifact index cache.
	 *
	 * @var array|null
	 */
	private $artifact_cache = null;

	/**
	 * Constructor.
	 *
	 * @param WPDI_Ownership $ownership Ownership service.
	 */
	public function __construct( WPDI_Ownership $ownership ) {
		$this->ownership = $ownership;
	}

	/**
	 * Generate a normalized metadata-only report.
	 *
	 * @param array $args Optional scan controls.
	 * @return array
	 */
	public function generate( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'sections'          => array( 'health', 'autoload', 'plugins', 'tables', 'ghost_data' ),
				'autoload_page'     => 1,
				'autoload_per_page' => 50,
				'autoload_search'   => '',
				'autoload_order'    => 'desc',
			)
		);

		$args['sections'] = array_values( array_unique( array_map( 'sanitize_key', (array) $args['sections'] ) ) );
		sort( $args['sections'] );
		ksort( $args );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Deterministic hash of internal scalar arguments; never unserialized.
		$cache_key = md5( serialize( $args ) );
		if ( isset( $this->report_cache[ $cache_key ] ) ) {
			return $this->report_cache[ $cache_key ];
		}

		$sections = $args['sections'];
		$warnings = array();
		$storage  = $this->get_storage_health();
		if ( ! $storage['information_schema_access'] ) {
			$warnings[] = array(
				'code'    => 'information_schema_restricted',
				'message' => 'Database and table sizes are unavailable because the host restricts information_schema. Other diagnostics remain valid.',
			);
		}
		$runtime         = $this->get_runtime_health();
		$maintenance     = $this->get_maintenance_health();
		$autoload        = in_array( 'autoload', $sections, true )
			? $this->get_autoload_options( $args['autoload_page'], $args['autoload_per_page'], $args['autoload_search'], $args['autoload_order'] )
			: array(
				'items'       => array(),
				'page'        => 1,
				'per_page'    => 0,
				'total'       => $runtime['autoload_count'],
				'total_pages' => 0,
				'search'      => '',
				'order'       => 'desc',
			);
		$needs_artifacts = in_array( 'plugins', $sections, true ) || in_array( 'ghost_data', $sections, true );
		$artifacts       = $needs_artifacts ? $this->get_artifact_index() : array(
			'options'  => array(),
			'postmeta' => array(),
			'usermeta' => array(),
		);
		$plugins         = in_array( 'plugins', $sections, true ) ? $this->build_plugin_footprints( $artifacts, $storage['tables'] ) : array();
		$ghost_data      = in_array( 'ghost_data', $sections, true ) ? $this->get_ghost_data( $artifacts, $storage['tables'], $maintenance ) : array();
		$score           = $this->calculate_health_score( $runtime, $storage, $maintenance );

		$this->report_cache[ $cache_key ] = array(
			'schema_version' => 1,
			'plugin_version' => WPDI_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'database'       => array(
				'name_redacted'             => true,
				'total_size'                => $storage['total_size'],
				'information_schema_access' => $storage['information_schema_access'],
				'multisite'                 => is_multisite(),
			),
			'health'         => array(
				'score'       => $score['score'],
				'rating'      => $score['rating'],
				'explanation' => $score['penalties'],
				'runtime'     => $runtime,
				'storage'     => array_diff_key( $storage, array( 'tables' => true ) ),
				'maintenance' => $maintenance,
			),
			'autoload'       => $autoload,
			'plugins'        => $plugins,
			'tables'         => $storage['tables'],
			'ghost_data'     => $ghost_data,
			'filesystem'     => array(
				'scanned' => false,
				'reason'  => 'Filesystem scanning is not required for database diagnostics and was not performed.',
			),
			'warnings'       => $warnings,
		);

		return $this->report_cache[ $cache_key ];
	}

	/**
	 * Runtime health metrics.
	 *
	 * @return array
	 */
	private function get_runtime_health() {
		global $wpdb;

		if ( null !== $this->runtime_cache ) {
			return $this->runtime_cache;
		}

		$values       = $this->get_autoload_values();
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT COUNT(*) AS option_count, COALESCE(SUM(LENGTH(option_value)), 0) AS total_size, SUM(CASE WHEN LENGTH(option_value) >= 100000 THEN 1 ELSE 0 END) AS large_count FROM {$wpdb->options} WHERE autoload IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count is generated from core autoload values.
			$values
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The query was prepared immediately above.
		$row = $wpdb->get_row( $sql );

		$this->runtime_cache = array(
			'autoload_size'          => is_object( $row ) && isset( $row->total_size ) ? (int) $row->total_size : 0,
			'autoload_count'         => is_object( $row ) && isset( $row->option_count ) ? (int) $row->option_count : 0,
			'large_autoload_count'   => is_object( $row ) && isset( $row->large_count ) ? (int) $row->large_count : 0,
			'large_option_threshold' => 100000,
			'object_cache_enabled'   => (bool) wp_using_ext_object_cache(),
		);

		return $this->runtime_cache;
	}

	/**
	 * Storage health and tables.
	 *
	 * @return array
	 */
	private function get_storage_health() {
		global $wpdb;

		if ( null !== $this->storage_cache ) {
			return $this->storage_cache;
		}

		$previous = $wpdb->suppress_errors( true );
		// Columns are aliased explicitly because MySQL 8 returns information_schema
		// column names in their canonical uppercase form regardless of query casing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema is the authoritative size source.
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS table_name, ENGINE AS engine, TABLE_ROWS AS table_rows, DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length, DATA_FREE AS data_free FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
				DB_NAME
			)
		);
		$error = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );

		$has_access   = is_array( $rows ) && '' === $error;
		$tables       = array();
		$total_size   = 0;
		$options_size = 0;
		if ( $has_access ) {
			$prefix = is_string( $wpdb->base_prefix ) ? $wpdb->base_prefix : '';
			foreach ( $rows as $raw_row ) {
				$row = $this->normalize_table_row( $raw_row );
				if ( '' === $row['table_name'] ) {
					continue;
				}
				$size       = $row['data_length'] + $row['index_length'];
				$short_name = '' !== $prefix && 0 === strpos( $row['table_name'], $prefix ) ? substr( $row['table_name'], strlen( $prefix ) ) : $row['table_name'];
				$short_name = (string) preg_replace( '/^\d+_/', '', $short_name );
				$owner      = $this->is_core_table( $short_name )
					? array(
						'owner'        => 'WordPress Core',
						'owner_type'   => 'core',
						'owner_slug'   => '',
						'owner_status' => '',
						'confidence'   => 'confirmed',
						'reason'       => 'Exact WordPress core table name.',
					)
					: $this->ownership->identify( $short_name, 'table' );

				$tables[]    = array_merge(
					array(
						'name'       => $row['table_name'],
						'size'       => $size,
						'data_size'  => $row['data_length'],
						'index_size' => $row['index_length'],
						'free_size'  => $row['data_free'],
						'row_count'  => $row['table_rows'],
						'engine'     => sanitize_text_field( $row['engine'] ),
					),
					$owner
				);
				$total_size += $size;
				if ( $wpdb->options === $row['table_name'] ) {
					$options_size = $size;
				}
			}
		}

		$this->storage_cache = array(
			'total_size'                => $total_size,
			'options_table_size'        => $options_size,
			'information_schema_access' => $has_access,
			'large_table_count'         => count(
				array_filter(
					$tables,
					static function ( $table ) {
						return $table['size'] >= 104857600; }
				)
			),
			'tables'                    => $tables,
		);

		return $this->storage_cache;
	}

	/**
	 * Normalize one information_schema.TABLES row into a stable typed contract.
	 *
	 * Handles driver case differences and legitimately NULL metric columns
	 * (views, unsupported engines, restricted hosts) without PHP warnings.
	 *
	 * @param object|array $raw_row Raw database row.
	 * @return array
	 */
	private function normalize_table_row( $raw_row ) {
		$row = is_object( $raw_row ) ? get_object_vars( $raw_row ) : (array) $raw_row;
		$row = array_change_key_case( $row, CASE_LOWER );

		return array(
			'table_name'   => isset( $row['table_name'] ) && is_scalar( $row['table_name'] ) ? (string) $row['table_name'] : '',
			'engine'       => isset( $row['engine'] ) && is_scalar( $row['engine'] ) ? (string) $row['engine'] : '',
			'table_rows'   => isset( $row['table_rows'] ) && is_numeric( $row['table_rows'] ) ? (int) $row['table_rows'] : 0,
			'data_length'  => isset( $row['data_length'] ) && is_numeric( $row['data_length'] ) ? (int) $row['data_length'] : 0,
			'index_length' => isset( $row['index_length'] ) && is_numeric( $row['index_length'] ) ? (int) $row['index_length'] : 0,
			'data_free'    => isset( $row['data_free'] ) && is_numeric( $row['data_free'] ) ? (int) $row['data_free'] : 0,
		);
	}

	/**
	 * Maintenance counts.
	 *
	 * @return array
	 */
	private function get_maintenance_health() {
		global $wpdb;

		if ( null !== $this->maintenance_cache ) {
			return $this->maintenance_cache;
		}

		$timeout_like   = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$transient_like = $wpdb->esc_like( '_transient_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live diagnostic aggregates.
		$expired = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $timeout_like, time() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live diagnostic aggregate.
		$transient_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) );

		$queries = array(
			'revisions'            => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'",
			'auto_drafts'          => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'",
			'trashed_posts'        => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'",
			'spam_comments'        => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'",
			'trashed_comments'     => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'",
			'orphaned_postmeta'    => "SELECT COUNT(*) FROM (SELECT meta_id, post_id FROM {$wpdb->postmeta} ORDER BY meta_id ASC LIMIT 100000) pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL",
			'orphaned_commentmeta' => "SELECT COUNT(*) FROM (SELECT meta_id, comment_id FROM {$wpdb->commentmeta} ORDER BY meta_id ASC LIMIT 100000) cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL",
			'orphaned_usermeta'    => "SELECT COUNT(*) FROM (SELECT umeta_id, user_id FROM {$wpdb->usermeta} ORDER BY umeta_id ASC LIMIT 100000) um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL",
		);

		$metrics = array(
			'expired_transients' => (int) $expired,
			'transient_count'    => (int) $transient_count,
		);
		foreach ( $queries as $key => $query ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Static table identifiers and predicates.
			$metrics[ $key ] = (int) $wpdb->get_var( $query );
		}
		$metrics['orphan_scan_row_limit'] = 100000;

		$this->maintenance_cache = $metrics;

		return $this->maintenance_cache;
	}

	/**
	 * Paginated autoload inventory without returning option values.
	 *
	 * @param int    $page     Page number.
	 * @param int    $per_page Page size.
	 * @param string $search   Name search.
	 * @param string $order    Size order.
	 * @return array
	 */
	public function get_autoload_options( $page = 1, $per_page = 50, $search = '', $order = 'desc' ) {
		global $wpdb;

		$page         = max( 1, absint( $page ) );
		$per_page     = min( 100, max( 1, absint( $per_page ) ) );
		$offset       = ( $page - 1 ) * $per_page;
		$order        = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$values       = $this->get_autoload_values();
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		if ( '' !== $search ) {
			$params = array_merge( $values, array( '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count is generated from core autoload values.
			$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ({$placeholders}) AND option_name LIKE %s", $params ) );
			$params[] = $per_page;
			$params[] = $offset;
			if ( 'ASC' === $order ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is generated from core values; order is a static literal.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, LENGTH(option_value) AS size, LEFT(option_value, 12) AS value_prefix FROM {$wpdb->options} WHERE autoload IN ({$placeholders}) AND option_name LIKE %s ORDER BY LENGTH(option_value) ASC, option_name ASC LIMIT %d OFFSET %d", $params ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is generated from core values; order is a static literal.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, LENGTH(option_value) AS size, LEFT(option_value, 12) AS value_prefix FROM {$wpdb->options} WHERE autoload IN ({$placeholders}) AND option_name LIKE %s ORDER BY LENGTH(option_value) DESC, option_name ASC LIMIT %d OFFSET %d", $params ) );
			}
		} else {
			$params = $values;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count is generated from core autoload values.
			$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ({$placeholders})", $params ) );
			$params[] = $per_page;
			$params[] = $offset;
			if ( 'ASC' === $order ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is generated from core values; order is a static literal.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, LENGTH(option_value) AS size, LEFT(option_value, 12) AS value_prefix FROM {$wpdb->options} WHERE autoload IN ({$placeholders}) ORDER BY LENGTH(option_value) ASC, option_name ASC LIMIT %d OFFSET %d", $params ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count is generated from core values; order is a static literal.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload, LENGTH(option_value) AS size, LEFT(option_value, 12) AS value_prefix FROM {$wpdb->options} WHERE autoload IN ({$placeholders}) ORDER BY LENGTH(option_value) DESC, option_name ASC LIMIT %d OFFSET %d", $params ) );
			}
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$name = is_object( $row ) && isset( $row->option_name ) && is_scalar( $row->option_name ) ? (string) $row->option_name : '';
			if ( '' === $name ) {
				continue;
			}
			$owner   = $this->ownership->identify( $name, 'option' );
			$items[] = array_merge(
				array(
					'name'       => $name,
					'size'       => isset( $row->size ) ? (int) $row->size : 0,
					'autoload'   => isset( $row->autoload ) && is_scalar( $row->autoload ) ? (string) $row->autoload : '',
					'serialized' => (bool) preg_match( '/^(?:a|O|C|s|i|b|d):/', isset( $row->value_prefix ) && is_scalar( $row->value_prefix ) ? (string) $row->value_prefix : '' ),
					'protected'  => $this->ownership->is_protected_option( $name ),
				),
				$owner
			);
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'search'      => (string) $search,
			'order'       => strtolower( $order ),
		);
	}

	/**
	 * Build a bounded artifact-name/size index.
	 *
	 * @return array
	 */
	private function get_artifact_index() {
		global $wpdb;

		if ( null !== $this->artifact_cache ) {
			return $this->artifact_cache;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded metadata-only inventory.
		$options = $wpdb->get_results( "SELECT option_name AS artifact_name, LENGTH(option_value) AS artifact_size, autoload FROM {$wpdb->options} ORDER BY option_id DESC LIMIT 10000" );
		$index   = array(
			'options'  => array(),
			'postmeta' => array(),
			'usermeta' => array(),
		);
		foreach ( (array) $options as $row ) {
			$name = is_object( $row ) && isset( $row->artifact_name ) && is_scalar( $row->artifact_name ) ? (string) $row->artifact_name : '';
			if ( '' === $name ) {
				continue;
			}
			$index['options'][] = array(
				'name'     => $name,
				'size'     => isset( $row->artifact_size ) ? (int) $row->artifact_size : 0,
				'autoload' => isset( $row->autoload ) && in_array( $row->autoload, $this->get_autoload_values(), true ),
				'owner'    => $this->ownership->identify( $name, 'option' ),
			);
		}

		$meta_queries = array(
			'postmeta' => "SELECT meta_key AS artifact_name, COUNT(*) AS artifact_count, COALESCE(SUM(LENGTH(meta_value)), 0) AS artifact_size FROM (SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key <> '' ORDER BY meta_id DESC LIMIT 100000) sampled GROUP BY meta_key ORDER BY artifact_size DESC LIMIT 1000",
			'usermeta' => "SELECT meta_key AS artifact_name, COUNT(*) AS artifact_count, COALESCE(SUM(LENGTH(meta_value)), 0) AS artifact_size FROM (SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key <> '' ORDER BY umeta_id DESC LIMIT 100000) sampled GROUP BY meta_key ORDER BY artifact_size DESC LIMIT 1000",
		);
		foreach ( $meta_queries as $type => $query ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Static bounded aggregate query.
			foreach ( (array) $wpdb->get_results( $query ) as $row ) {
				$name = is_object( $row ) && isset( $row->artifact_name ) && is_scalar( $row->artifact_name ) ? (string) $row->artifact_name : '';
				if ( '' === $name ) {
					continue;
				}
				$index[ $type ][] = array(
					'name'  => $name,
					'count' => isset( $row->artifact_count ) ? (int) $row->artifact_count : 0,
					'size'  => isset( $row->artifact_size ) ? (int) $row->artifact_size : 0,
					'owner' => $this->ownership->identify( $name, $type ),
				);
			}
		}

		$this->artifact_cache = $index;

		return $this->artifact_cache;
	}

	/**
	 * Aggregate artifacts by installed plugin.
	 *
	 * @param array $artifacts Artifact index.
	 * @param array $tables    Table report.
	 * @return array
	 */
	private function build_plugin_footprints( $artifacts, $tables ) {
		$footprints = array();
		foreach ( $this->ownership->get_plugins() as $plugin ) {
			$footprints[ $plugin['slug'] ] = array(
				'plugin'          => $plugin['name'],
				'slug'            => $plugin['slug'],
				'status'          => $plugin['status'],
				'options_count'   => 0,
				'options_size'    => 0,
				'autoload_size'   => 0,
				'transient_size'  => 0,
				'postmeta_size'   => 0,
				'usermeta_size'   => 0,
				'custom_tables'   => array(),
				'estimated_total' => 0,
				'confidence'      => 'unknown',
			);
		}

		foreach ( $artifacts as $type => $rows ) {
			foreach ( $rows as $row ) {
				$slug = $row['owner']['owner_slug'];
				if ( ! $slug || ! isset( $footprints[ $slug ] ) || 'unknown' === $row['owner']['confidence'] ) {
					continue;
				}
				$footprints[ $slug ]['confidence'] = 'high';
				if ( 'options' === $type ) {
					++$footprints[ $slug ]['options_count'];
					$footprints[ $slug ]['options_size'] += $row['size'];
					if ( $row['autoload'] ) {
						$footprints[ $slug ]['autoload_size'] += $row['size'];
					}
					if ( 0 === strpos( $row['name'], '_transient_' ) || 0 === strpos( $row['name'], '_site_transient_' ) ) {
						$footprints[ $slug ]['transient_size'] += $row['size'];
					}
				} elseif ( 'postmeta' === $type ) {
					$footprints[ $slug ]['postmeta_size'] += $row['size'];
				} elseif ( 'usermeta' === $type ) {
					$footprints[ $slug ]['usermeta_size'] += $row['size'];
				}
			}
		}

		foreach ( $tables as $table ) {
			$slug = $table['owner_slug'];
			if ( $slug && isset( $footprints[ $slug ] ) && 'unknown' !== $table['confidence'] ) {
				$footprints[ $slug ]['custom_tables'][] = array(
					'name' => $table['name'],
					'size' => $table['size'],
				);
				$footprints[ $slug ]['confidence']      = 'high';
			}
		}

		foreach ( $footprints as &$footprint ) {
			$table_size                   = array_sum( wp_list_pluck( $footprint['custom_tables'], 'size' ) );
			$footprint['estimated_total'] = $footprint['options_size'] + $footprint['postmeta_size'] + $footprint['usermeta_size'] + $table_size;
		}
		unset( $footprint );

		return array_values( $footprints );
	}

	/**
	 * Create explainable, read-only ghost-data findings.
	 *
	 * @param array $artifacts   Artifact index.
	 * @param array $tables      Tables.
	 * @param array $maintenance Maintenance counts.
	 * @return array
	 */
	private function get_ghost_data( $artifacts, $tables, $maintenance ) {
		$findings = array();
		$orphans  = array(
			'orphaned_postmeta'    => 'Post metadata whose post no longer exists.',
			'orphaned_commentmeta' => 'Comment metadata whose comment no longer exists.',
			'orphaned_usermeta'    => 'User metadata whose user no longer exists.',
			'expired_transients'   => 'Transient timeout is in the past.',
		);
		foreach ( $orphans as $key => $reason ) {
			if ( ! empty( $maintenance[ $key ] ) ) {
				$findings[] = array(
					'artifact'     => $key,
					'type'         => 'aggregate',
					'count'        => $maintenance[ $key ],
					'owner'        => 'WordPress database relationship',
					'owner_status' => '',
					'confidence'   => 'confirmed',
					'reason'       => $reason,
					'deletable'    => false,
				);
			}
		}

		foreach ( $artifacts['options'] as $option ) {
			$owner = $option['owner'];
			if ( 'not_installed' === $owner['owner_status'] && 'high' === $owner['confidence'] && count( $findings ) < 200 ) {
				$findings[] = array(
					'artifact'     => $option['name'],
					'type'         => 'option',
					'size'         => $option['size'],
					'owner'        => $owner['owner'],
					'owner_status' => 'not_installed',
					'confidence'   => $owner['confidence'],
					'reason'       => $owner['reason'],
					'deletable'    => false,
				);
			}
		}

		foreach ( $tables as $table ) {
			if ( 'not_installed' === $table['owner_status'] && 'high' === $table['confidence'] && count( $findings ) < 200 ) {
				$findings[] = array(
					'artifact'     => $table['name'],
					'type'         => 'table',
					'size'         => $table['size'],
					'owner'        => $table['owner'],
					'owner_status' => 'not_installed',
					'confidence'   => $table['confidence'],
					'reason'       => $table['reason'],
					'deletable'    => false,
				);
			}
		}

		$cron = get_option( 'cron', array() );
		if ( is_array( $cron ) ) {
			$seen      = array();
			$inspected = 0;
			foreach ( $cron as $timestamp => $hooks ) {
				if ( $inspected >= 1000 ) {
					break;
				}
				if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
					continue;
				}
				foreach ( array_keys( $hooks ) as $hook ) {
					if ( $inspected >= 1000 ) {
						break;
					}
					if ( isset( $seen[ $hook ] ) ) {
						continue;
					}
					$seen[ $hook ] = true;
					++$inspected;
					$owner = $this->ownership->identify( $hook, 'cron' );
					if ( 'not_installed' === $owner['owner_status'] && 'high' === $owner['confidence'] && count( $findings ) < 200 ) {
						$findings[] = array(
							'artifact'     => $hook,
							'type'         => 'cron_hook',
							'owner'        => $owner['owner'],
							'owner_status' => 'not_installed',
							'confidence'   => $owner['confidence'],
							'reason'       => 'Scheduled hook has an exact missing-plugin prefix match.',
							'deletable'    => false,
						);
					}
				}
			}
		}

		return $findings;
	}

	/**
	 * Deterministic, explainable lower-is-better score.
	 *
	 * @param array $runtime     Runtime metrics.
	 * @param array $storage     Storage metrics.
	 * @param array $maintenance Maintenance metrics.
	 * @return array
	 */
	private function calculate_health_score( $runtime, $storage, $maintenance ) {
		$penalties = array();
		$this->add_penalty( $penalties, 'runtime', 'autoload_size', max( 0, min( 30, ( $runtime['autoload_size'] - 1048576 ) / 104857.6 ) ), 'Autoload data above 1 MB increases request-time memory and work.' );
		$this->add_penalty( $penalties, 'runtime', 'large_autoload_options', min( 10, $runtime['large_autoload_count'] * 2 ), 'Autoloaded options of at least 100 KB deserve review.' );
		$this->add_penalty( $penalties, 'maintenance', 'expired_transients', min( 10, $maintenance['expired_transients'] / 20 ), 'Expired transients are safe maintenance candidates.' );
		$this->add_penalty( $penalties, 'maintenance', 'revisions', max( 0, min( 15, ( $maintenance['revisions'] - 100 ) / 50 ) ), 'Large revision counts can increase storage.' );
		$orphaned = $maintenance['orphaned_postmeta'] + $maintenance['orphaned_commentmeta'] + $maintenance['orphaned_usermeta'];
		$this->add_penalty( $penalties, 'maintenance', 'orphaned_metadata', min( 15, $orphaned / 100 ), 'Orphaned metadata has no related content or user.' );
		$trash = $maintenance['spam_comments'] + $maintenance['trashed_comments'] + $maintenance['trashed_posts'];
		$this->add_penalty( $penalties, 'maintenance', 'trash_and_spam', min( 10, $trash / 50 ), 'Trash and spam consume storage until removed.' );
		$this->add_penalty( $penalties, 'storage', 'large_tables', min( 10, $storage['large_table_count'] * 2 ), 'Tables over 100 MB may warrant workload-specific review.' );

		$score = (int) min( 100, round( array_sum( wp_list_pluck( $penalties, 'points' ) ) ) );
		/**
		 * Filter the legacy lower-is-worse health score.
		 *
		 * @param int   $score Health penalty score from 0 to 100.
		 * @param array $stats Legacy-compatible metric keys.
		 */
		$score = (int) apply_filters( 'wpdi_health_score', $score, $this->legacy_score_stats( $runtime, $storage, $maintenance ) );
		$score = min( 100, max( 0, $score ) );

		return array(
			'score'     => $score,
			'rating'    => $score <= 40 ? 'good' : ( $score <= 70 ? 'warning' : 'critical' ),
			'penalties' => $penalties,
		);
	}

	/**
	 * Add a non-zero score penalty.
	 *
	 * @param array  $penalties Penalty collection by reference.
	 * @param string $category  Health category.
	 * @param string $code      Stable penalty code.
	 * @param float  $points    Penalty points.
	 * @param string $reason    Human-readable explanation.
	 */
	private function add_penalty( &$penalties, $category, $code, $points, $reason ) {
		$points = round( max( 0, $points ), 1 );
		if ( $points > 0 ) {
			$penalties[] = array(
				'category' => $category,
				'code'     => $code,
				'points'   => $points,
				'reason'   => $reason,
			);
		}
	}

	/**
	 * Build legacy fields supplied to the existing score filter.
	 *
	 * @param array $runtime     Runtime health.
	 * @param array $storage     Storage health.
	 * @param array $maintenance Maintenance health.
	 * @return array
	 */
	private function legacy_score_stats( $runtime, $storage, $maintenance ) {
		return array(
			'autoload_size'         => $runtime['autoload_size'],
			'autoload_count'        => $runtime['autoload_count'],
			'object_cache_enabled'  => $runtime['object_cache_enabled'],
			'expired_transients'    => $maintenance['expired_transients'],
			'transient_count'       => $maintenance['transient_count'],
			'revisions_count'       => $maintenance['revisions'],
			'orphaned_postmeta'     => $maintenance['orphaned_postmeta'],
			'orphaned_commentmeta'  => $maintenance['orphaned_commentmeta'],
			'spam_comments'         => $maintenance['spam_comments'],
			'trashed_comments'      => $maintenance['trashed_comments'],
			'trashed_posts_count'   => $maintenance['trashed_posts'],
			'auto_drafts_count'     => $maintenance['auto_drafts'],
			'total_db_size'         => $storage['total_size'],
			'options_table_size'    => $storage['options_table_size'],
			'info_schema_available' => $storage['information_schema_access'],
			'top_autoload'          => array(),
		);
	}

	/** Get core-supported autoload values. */
	private function get_autoload_values() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}

		return array( 'yes' );
	}

	/**
	 * Determine core tables from exact short names.
	 *
	 * @param string $name Prefix-free table name.
	 * @return bool
	 */
	private function is_core_table( $name ) {
		return in_array(
			$name,
			array( 'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'termmeta', 'terms', 'term_relationships', 'term_taxonomy', 'usermeta', 'users', 'blogs', 'blogmeta', 'registration_log', 'signups', 'site', 'sitemeta' ),
			true
		);
	}
}
