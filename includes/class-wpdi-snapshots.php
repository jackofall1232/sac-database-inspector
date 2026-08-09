<?php
/**
 * Lightweight local safety snapshots.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores bounded rollback data in a protected, non-autoloaded WordPress option.
 */
class WPDI_Snapshots {

	const OPTION_NAME = 'wpdi_safety_snapshots';
	const MAX_ITEMS   = 20;
	const MAX_BYTES   = 1048576;
	const RETENTION   = 1209600;

	/**
	 * Create a snapshot.
	 *
	 * @param string $operation  Operation name.
	 * @param array  $records    Records needed for audit/restore.
	 * @param bool   $restorable Whether restoration is supported.
	 * @param array  $context    Non-sensitive operation context.
	 * @return string|WP_Error Snapshot ID or error.
	 */
	public function create( $operation, $records, $restorable, $context = array() ) {
		$operation = sanitize_key( $operation );
		if ( empty( $operation ) || empty( $records ) ) {
			return new WP_Error( 'wpdi_empty_snapshot', __( 'There is no operation data to snapshot.', 'sac-database-inspector' ) );
		}

		$snapshot = array(
			'id'          => wp_generate_uuid4(),
			'operation'   => $operation,
			'created_at'  => time(),
			'created_by'  => get_current_user_id(),
			'restorable'  => (bool) $restorable,
			'restored_at' => 0,
			'context'     => array_map( 'sanitize_text_field', $context ),
			'records'     => $records,
		);

		$encoded = wp_json_encode( $snapshot );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_BYTES ) {
			return new WP_Error( 'wpdi_snapshot_too_large', __( 'The safety snapshot would exceed the 1 MB operation limit. No changes were made.', 'sac-database-inspector' ) );
		}

		$items   = $this->get_all();
		$items[] = $snapshot;
		$items   = $this->prune( $items );

		if ( false === update_option( self::OPTION_NAME, $items, false ) ) {
			$stored = get_option( self::OPTION_NAME, array() );
			if ( $stored !== $items ) {
				return new WP_Error( 'wpdi_snapshot_failed', __( 'The safety snapshot could not be stored. No changes were made.', 'sac-database-inspector' ) );
			}
		}

		return $snapshot['id'];
	}

	/**
	 * Return metadata-only snapshot list for the UI.
	 *
	 * @return array
	 */
	public function list_metadata() {
		$list = array();
		foreach ( array_reverse( $this->get_all() ) as $snapshot ) {
			$list[] = array(
				'id'           => $snapshot['id'],
				'operation'    => $snapshot['operation'],
				'created_at'   => gmdate( 'c', (int) $snapshot['created_at'] ),
				'restorable'   => ! empty( $snapshot['restorable'] ),
				'restored_at'  => empty( $snapshot['restored_at'] ) ? '' : gmdate( 'c', (int) $snapshot['restored_at'] ),
				'record_count' => count( $snapshot['records'] ),
				'context'      => isset( $snapshot['context'] ) ? $snapshot['context'] : array(),
			);
		}

		return $list;
	}

	/**
	 * Restore supported option or metadata records.
	 *
	 * @param string $snapshot_id Snapshot UUID.
	 * @return array|WP_Error
	 */
	public function restore( $snapshot_id ) {
		global $wpdb;

		$items = $this->get_all();
		foreach ( $items as $index => $snapshot ) {
			if ( ! hash_equals( (string) $snapshot['id'], (string) $snapshot_id ) ) {
				continue;
			}
			if ( empty( $snapshot['restorable'] ) ) {
				return new WP_Error( 'wpdi_not_restorable', __( 'This snapshot is audit-only and cannot be restored automatically.', 'sac-database-inspector' ) );
			}
			if ( ! empty( $snapshot['restored_at'] ) ) {
				return new WP_Error( 'wpdi_already_restored', __( 'This snapshot has already been restored.', 'sac-database-inspector' ) );
			}

			$restored = 0;
			foreach ( $snapshot['records'] as $record ) {
				if ( 'option' === $record['type'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact access-controlled snapshot restoration requires preserving the raw serialized row.
					$result = $wpdb->replace(
						$wpdb->options,
						array(
							'option_name'  => $record['option_name'],
							'option_value' => $record['option_value'],
							'autoload'     => $record['autoload'],
						),
						array( '%s', '%s', '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( $record['option_name'], 'options' );
					}
				} elseif ( 'autoload_option' === $record['type'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact access-controlled rollback of one snapshotted state.
					$result = $wpdb->update(
						$wpdb->options,
						array( 'autoload' => $record['autoload'] ),
						array( 'option_name' => $record['option_name'] ),
						array( '%s' ),
						array( '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( $record['option_name'], 'options' );
					}
				} elseif ( 'site_option' === $record['type'] && is_multisite() ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Restores one exact bounded network snapshot row.
					$result = $wpdb->replace(
						$wpdb->sitemeta,
						array(
							'site_id'    => (int) $record['site_id'],
							'meta_key'   => $record['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REPLACE data column, not an unindexed query predicate.
							'meta_value' => $record['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- REPLACE data column, not a query predicate.
						),
						array( '%d', '%s', '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( $record['meta_key'], 'site-options' );
					}
				} elseif ( 'postmeta' === $record['type'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Restores one exact bounded orphan snapshot row.
					$result = $wpdb->replace(
						$wpdb->postmeta,
						array(
							'meta_id'    => (int) $record['meta_id'],
							'post_id'    => (int) $record['post_id'],
							'meta_key'   => $record['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REPLACE data column, not an unindexed query predicate.
							'meta_value' => $record['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- REPLACE data column, not a query predicate.
						),
						array( '%d', '%d', '%s', '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( (int) $record['post_id'], 'post_meta' );
					}
				} elseif ( 'commentmeta' === $record['type'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Restores one exact bounded orphan snapshot row.
					$result = $wpdb->replace(
						$wpdb->commentmeta,
						array(
							'meta_id'    => (int) $record['meta_id'],
							'comment_id' => (int) $record['comment_id'],
							'meta_key'   => $record['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REPLACE data column, not an unindexed query predicate.
							'meta_value' => $record['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- REPLACE data column, not a query predicate.
						),
						array( '%d', '%d', '%s', '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( (int) $record['comment_id'], 'comment_meta' );
					}
				} elseif ( 'usermeta' === $record['type'] ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Restores one exact bounded orphan snapshot row.
					$result = $wpdb->replace(
						$wpdb->usermeta,
						array(
							'umeta_id'   => (int) $record['umeta_id'],
							'user_id'    => (int) $record['user_id'],
							'meta_key'   => $record['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REPLACE data column, not an unindexed query predicate.
							'meta_value' => $record['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- REPLACE data column, not a query predicate.
						),
						array( '%d', '%d', '%s', '%s' )
					);
					if ( false !== $result ) {
						++$restored;
						wp_cache_delete( (int) $record['user_id'], 'user_meta' );
					}
				}
			}

			if ( count( $snapshot['records'] ) !== $restored ) {
				return new WP_Error( 'wpdi_snapshot_partial_restore', __( 'The snapshot was only partially restored. It remains available so the operation can be retried.', 'sac-database-inspector' ) );
			}

			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$items[ $index ]['restored_at'] = time();
			update_option( self::OPTION_NAME, $items, false );

			return array(
				'restored'    => $restored,
				'snapshot_id' => $snapshot_id,
			);
		}

		return new WP_Error( 'wpdi_snapshot_not_found', __( 'Safety snapshot not found or expired.', 'sac-database-inspector' ) );
	}

	/** Get raw snapshots. */
	private function get_all() {
		$items = get_option( self::OPTION_NAME, array() );
		return is_array( $items ) ? $this->prune( $items ) : array();
	}

	/**
	 * Apply age and count limits.
	 *
	 * @param array $items Snapshot records.
	 * @return array
	 */
	private function prune( $items ) {
		$minimum = time() - self::RETENTION;
		$items   = array_values(
			array_filter(
				(array) $items,
				static function ( $item ) use ( $minimum ) {
					return is_array( $item ) && ! empty( $item['id'] ) && (int) $item['created_at'] >= $minimum;
				}
			)
		);

		return array_slice( $items, -self::MAX_ITEMS );
	}
}
