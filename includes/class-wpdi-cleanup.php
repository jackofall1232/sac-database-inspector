<?php
/**
 * Bounded, snapshot-backed database mutations.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Performs only explicitly requested and allowlisted maintenance operations.
 */
class WPDI_Cleanup {

	const BATCH_SIZE = 100;

	/**
	 * Snapshot service.
	 *
	 * @var WPDI_Snapshots
	 */
	private $snapshots;

	/**
	 * Ownership service.
	 *
	 * @var WPDI_Ownership
	 */
	private $ownership;

	/**
	 * Constructor.
	 *
	 * @param WPDI_Snapshots $snapshots Snapshot service.
	 * @param WPDI_Ownership $ownership Ownership service.
	 */
	public function __construct( WPDI_Snapshots $snapshots, WPDI_Ownership $ownership ) {
		$this->snapshots = $snapshots;
		$this->ownership = $ownership;
	}

	/**
	 * Perform one bounded cleanup batch.
	 *
	 * @param string $action Cleanup identifier.
	 * @return array
	 */
	public function perform( $action ) {
		global $wpdb;

		$defaults = array(
			'expired_transients',
			'all_transients',
			'revisions',
			'auto_drafts',
			'trashed_posts',
			'orphaned_postmeta',
			'orphaned_commentmeta',
			'orphaned_usermeta',
			'spam_comments',
			'trashed_comments',
			'object_cache',
		);
		/** This compatibility filter can remove actions; adding a name still requires an implementation. */
		$allowed = apply_filters( 'wpdi_cleanup_actions', $defaults );
		if ( ! in_array( $action, $allowed, true ) || ! in_array( $action, $defaults, true ) ) {
			return $this->error( __( 'Invalid action.', 'sac-database-inspector' ) );
		}

		if ( 'object_cache' === $action ) {
			if ( ! wp_using_ext_object_cache() ) {
				return $this->success( 0, '', false );
			}
			$result = wp_cache_flush();
			return false === $result ? $this->error( __( 'The object cache could not be flushed.', 'sac-database-inspector' ) ) : $this->success( 1, '', false );
		}

		$records      = array();
		$targets      = array();
		$site_targets = array();
		$restorable   = false;

		switch ( $action ) {
			case 'expired_transients':
				$like = $wpdb->esc_like( '_transient_timeout_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded mutation target selection.
				$targets = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d", $like, time(), self::BATCH_SIZE ) );
				foreach ( $targets as $timeout_name ) {
					$key     = substr( $timeout_name, strlen( '_transient_timeout_' ) );
					$records = array_merge( $records, $this->get_option_records( array( $timeout_name, '_transient_' . $key ) ) );
				}
				if ( is_multisite() && count( $targets ) < self::BATCH_SIZE ) {
					$site_like = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';
					$remaining = self::BATCH_SIZE - count( $targets );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded multisite target selection.
					$site_targets = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND CAST(meta_value AS UNSIGNED) < %d ORDER BY meta_id ASC LIMIT %d", $site_like, time(), $remaining ) );
					foreach ( $site_targets as $timeout_name ) {
						$key     = substr( $timeout_name, strlen( '_site_transient_timeout_' ) );
						$records = array_merge( $records, $this->get_site_option_records( array( $timeout_name, '_site_transient_' . $key ) ) );
					}
				}
				$restorable = true;
				break;

			case 'all_transients':
				$like = $wpdb->esc_like( '_transient_' ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded mutation target selection.
				$targets    = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $like, self::BATCH_SIZE ) );
				$records    = $this->get_option_records( $targets );
				$restorable = true;
				break;

			case 'revisions':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded IDs only.
				$targets = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY ID ASC LIMIT 100" );
				$records = $this->audit_id_records( 'revision', $targets );
				break;

			case 'auto_drafts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded IDs only.
				$targets = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' ORDER BY ID ASC LIMIT 100" );
				$records = $this->audit_id_records( 'auto_draft', $targets );
				break;

			case 'trashed_posts':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded IDs only.
				$targets = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' ORDER BY ID ASC LIMIT 100" );
				$records = $this->audit_id_records( 'trashed_post', $targets );
				break;

			case 'orphaned_postmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded relationship cleanup.
				$rows       = $wpdb->get_results( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value FROM (SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id ASC LIMIT 100000) pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL ORDER BY pm.meta_id ASC LIMIT 100", ARRAY_A );
				$records    = $this->typed_records( 'postmeta', $rows );
				$targets    = wp_list_pluck( $rows, 'meta_id' );
				$restorable = true;
				break;

			case 'orphaned_commentmeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded relationship cleanup.
				$rows       = $wpdb->get_results( "SELECT cm.meta_id, cm.comment_id, cm.meta_key, cm.meta_value FROM (SELECT meta_id, comment_id, meta_key, meta_value FROM {$wpdb->commentmeta} ORDER BY meta_id ASC LIMIT 100000) cm LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID WHERE c.comment_ID IS NULL ORDER BY cm.meta_id ASC LIMIT 100", ARRAY_A );
				$records    = $this->typed_records( 'commentmeta', $rows );
				$targets    = wp_list_pluck( $rows, 'meta_id' );
				$restorable = true;
				break;

			case 'orphaned_usermeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded relationship cleanup.
				$rows       = $wpdb->get_results( "SELECT um.umeta_id, um.user_id, um.meta_key, um.meta_value FROM (SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id ASC LIMIT 100000) um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL ORDER BY um.umeta_id ASC LIMIT 100", ARRAY_A );
				$records    = $this->typed_records( 'usermeta', $rows );
				$targets    = wp_list_pluck( $rows, 'umeta_id' );
				$restorable = true;
				break;

			case 'spam_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded IDs only.
				$targets = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' ORDER BY comment_ID ASC LIMIT 100" );
				$records = $this->audit_id_records( 'spam_comment', $targets );
				break;

			case 'trashed_comments':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded IDs only.
				$targets = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'trash' ORDER BY comment_ID ASC LIMIT 100" );
				$records = $this->audit_id_records( 'trashed_comment', $targets );
				break;
		}

		if ( empty( $targets ) && empty( $site_targets ) ) {
			return $this->success( 0, '', $restorable );
		}

		$snapshot_id = $this->snapshots->create(
			$action,
			$records,
			$restorable,
			array( 'batch_size' => count( $targets ) + count( $site_targets ) )
		);
		if ( is_wp_error( $snapshot_id ) ) {
			return $this->error( $snapshot_id->get_error_message() );
		}

		$deleted = 0;
		switch ( $action ) {
			case 'expired_transients':
				foreach ( $targets as $timeout_name ) {
					$key     = substr( $timeout_name, strlen( '_transient_timeout_' ) );
					$changed = delete_transient( $key );
					// Clean old database-backed rows even when an external object cache is now active.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact snapshotted transient rows.
					$changed = (bool) $wpdb->delete( $wpdb->options, array( 'option_name' => $timeout_name ), array( '%s' ) ) || $changed;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact snapshotted transient rows.
					$changed = (bool) $wpdb->delete( $wpdb->options, array( 'option_name' => '_transient_' . $key ), array( '%s' ) ) || $changed;
					wp_cache_delete( $key, 'transient' );
					if ( $changed ) {
						++$deleted;
					}
				}
				foreach ( $site_targets as $timeout_name ) {
					$key        = substr( $timeout_name, strlen( '_site_transient_timeout_' ) );
					$changed    = delete_site_transient( $key );
					$network_id = get_current_network_id();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact snapshotted network transient row.
					$changed = (bool) $wpdb->delete(
						$wpdb->sitemeta,
						array(
							'site_id'  => $network_id,
							'meta_key' => $timeout_name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact indexed key in a bounded delete.
						),
						array( '%d', '%s' )
					) || $changed;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact snapshotted network transient row.
					$changed = (bool) $wpdb->delete(
						$wpdb->sitemeta,
						array(
							'site_id'  => $network_id,
							'meta_key' => '_site_transient_' . $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Exact indexed key in a bounded delete.
						),
						array( '%d', '%s' )
					) || $changed;
					wp_cache_delete( $key, 'site-transient' );
					if ( $changed ) {
						++$deleted;
					}
				}
				break;
			case 'all_transients':
				foreach ( $targets as $name ) {
					$key = 0 === strpos( $name, '_transient_timeout_' ) ? substr( $name, strlen( '_transient_timeout_' ) ) : substr( $name, strlen( '_transient_' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact snapshotted record.
					$deleted += (int) $wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
					wp_cache_delete( $key, 'transient' );
				}
				wp_cache_delete( 'alloptions', 'options' );
				break;
			case 'revisions':
				foreach ( $targets as $id ) {
					$deleted += false !== wp_delete_post_revision( (int) $id ) ? 1 : 0;
				}
				break;
			case 'auto_drafts':
			case 'trashed_posts':
				foreach ( $targets as $id ) {
					$deleted += false !== wp_delete_post( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'orphaned_postmeta':
				$deleted = $this->delete_ids( $wpdb->postmeta, 'meta_id', $targets );
				break;
			case 'orphaned_commentmeta':
				$deleted = $this->delete_ids( $wpdb->commentmeta, 'meta_id', $targets );
				break;
			case 'orphaned_usermeta':
				$deleted = $this->delete_ids( $wpdb->usermeta, 'umeta_id', $targets );
				break;
			case 'spam_comments':
			case 'trashed_comments':
				foreach ( $targets as $id ) {
					$deleted += false !== wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;
		}

		return $this->success( $deleted, $snapshot_id, $restorable );
	}

	/**
	 * Change one unprotected option's autoload state after a snapshot.
	 *
	 * @param string $option_name Option name.
	 * @param bool   $enabled     Desired state.
	 * @return array
	 */
	public function change_autoload( $option_name, $enabled ) {
		global $wpdb;

		if ( '' === $option_name || $this->ownership->is_protected_option( $option_name ) ) {
			return $this->error( __( 'This option is protected and cannot be modified.', 'sac-database-inspector' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact option autoload-state snapshot; the potentially sensitive value is not loaded.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name ), ARRAY_A );
		if ( ! $row ) {
			return $this->error( __( 'Option not found.', 'sac-database-inspector' ) );
		}

		$record      = array_merge( array( 'type' => 'autoload_option' ), $row );
		$snapshot_id = $this->snapshots->create( 'autoload_change', array( $record ), true, array( 'option_name' => $option_name ) );
		if ( is_wp_error( $snapshot_id ) ) {
			return $this->error( $snapshot_id->get_error_message() );
		}

		$new_value = $enabled ? 'on' : 'off';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact guarded option update.
		$result = $wpdb->update( $wpdb->options, array( 'autoload' => $new_value ), array( 'option_name' => $option_name ), array( '%s' ), array( '%s' ) );
		if ( false === $result ) {
			return $this->error( __( 'The autoload state could not be changed.', 'sac-database-inspector' ) );
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return array(
			'success'     => true,
			'changed'     => (int) $result,
			'option_name' => $option_name,
			'autoload'    => $new_value,
			'snapshot_id' => $snapshot_id,
			'restorable'  => true,
			'message'     => __( 'Autoload state updated. A restorable safety snapshot was created.', 'sac-database-inspector' ),
		);
	}

	/**
	 * Fetch exact option rows for snapshots.
	 *
	 * @param string[] $names Option names.
	 * @return array
	 */
	private function get_option_records( $names ) {
		global $wpdb;
		$names = array_values( array_unique( array_filter( array_map( 'strval', (array) $names ) ) ) );
		if ( empty( $names ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count matches the bounded exact-name list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", $names ), ARRAY_A );
		return $this->typed_records( 'option', $rows );
	}

	/**
	 * Fetch exact multisite option rows for snapshots.
	 *
	 * @param string[] $names Network option names.
	 * @return array
	 */
	private function get_site_option_records( $names ) {
		global $wpdb;
		$names = array_values( array_unique( array_filter( array_map( 'strval', (array) $names ) ) ) );
		if ( empty( $names ) || ! is_multisite() ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholder count matches the bounded exact-key list.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT site_id, meta_key, meta_value FROM {$wpdb->sitemeta} WHERE meta_key IN ({$placeholders})", $names ), ARRAY_A );
		return $this->typed_records( 'site_option', $rows );
	}

	/**
	 * Add a record type discriminator.
	 *
	 * @param string $type Record type.
	 * @param array  $rows Database rows.
	 * @return array
	 */
	private function typed_records( $type, $rows ) {
		return array_map(
			static function ( $row ) use ( $type ) {
				return array_merge( array( 'type' => $type ), $row );
			},
			(array) $rows
		);
	}

	/**
	 * Create non-restorable ID-only audit records.
	 *
	 * @param string $type Record type.
	 * @param int[]  $ids  Record IDs.
	 * @return array
	 */
	private function audit_id_records( $type, $ids ) {
		return array_map(
			static function ( $id ) use ( $type ) {
				return array(
					'type' => $type,
					'id'   => (int) $id,
				);
			},
			(array) $ids
		);
	}

	/**
	 * Delete exact numeric IDs one at a time to keep SQL bounded.
	 *
	 * @param string $table  Validated WordPress table name.
	 * @param string $column Hard-coded identifier column.
	 * @param int[]  $ids    Snapshotted IDs.
	 * @return int
	 */
	private function delete_ids( $table, $column, $ids ) {
		global $wpdb;
		$deleted = 0;
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact snapshotted ID.
			$deleted += (int) $wpdb->delete( $table, array( $column => (int) $id ), array( '%d' ) );
		}
		return $deleted;
	}

	/**
	 * Build a standard success response.
	 *
	 * @param int    $deleted     Number changed.
	 * @param string $snapshot_id Snapshot UUID.
	 * @param bool   $restorable  Whether the snapshot is restorable.
	 * @return array
	 */
	private function success( $deleted, $snapshot_id, $restorable ) {
		return array(
			'success'     => true,
			'deleted'     => (int) $deleted,
			'snapshot_id' => $snapshot_id,
			'restorable'  => (bool) $restorable,
			'batched'     => (int) $deleted >= self::BATCH_SIZE,
			'message'     => sprintf(
				/* translators: %d: number of changed items. */
				__( 'Changed %d items in this bounded batch.', 'sac-database-inspector' ),
				(int) $deleted
			),
		);
	}

	/**
	 * Build a standard error response.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function error( $message ) {
		return array(
			'success' => false,
			'message' => $message,
		);
	}
}
