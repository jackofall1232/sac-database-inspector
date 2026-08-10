<?php
/**
 * Admin controller and presentation.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates focused services while preserving the original admin surface.
 */
class WPDI_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var WPDI_Admin|null
	 */
	private static $instance = null;

	/**
	 * Ownership service.
	 *
	 * @var WPDI_Ownership
	 */
	private $ownership;

	/**
	 * Redaction service.
	 *
	 * @var WPDI_Redactor
	 */
	private $redactor;

	/**
	 * Report service.
	 *
	 * @var WPDI_Report
	 */
	private $report;

	/**
	 * Snapshot service.
	 *
	 * @var WPDI_Snapshots
	 */
	private $snapshots;

	/**
	 * Cleanup service.
	 *
	 * @var WPDI_Cleanup
	 */
	private $cleanup;

	/**
	 * Export service.
	 *
	 * @var WPDI_Exporter
	 */
	private $exporter;

	/**
	 * Optional AI service.
	 *
	 * @var WPDI_AI
	 */
	private $ai;

	/** Get singleton instance. */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Set up services and hooks. */
	private function __construct() {
		$this->ownership = new WPDI_Ownership();
		$this->redactor  = new WPDI_Redactor();
		$this->report    = new WPDI_Report( $this->ownership );
		$this->snapshots = new WPDI_Snapshots();
		$this->cleanup   = new WPDI_Cleanup( $this->snapshots, $this->ownership );
		$this->exporter  = new WPDI_Exporter( $this->redactor );
		$this->ai        = new WPDI_AI( $this->redactor );

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpdi_get_stats', array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_wpdi_cleanup', array( $this, 'ajax_cleanup' ) );
		add_action( 'wp_ajax_wpdi_option_preview', array( $this, 'ajax_option_preview' ) );
		add_action( 'wp_ajax_wpdi_change_autoload', array( $this, 'ajax_change_autoload' ) );
		add_action( 'wp_ajax_wpdi_restore_snapshot', array( $this, 'ajax_restore_snapshot' ) );
		add_action( 'wp_ajax_wpdi_ai_explain', array( $this, 'ajax_ai_explain' ) );
		add_action( 'wp_ajax_wpdi_dismiss_review', array( $this, 'ajax_dismiss_review' ) );
		add_action( 'admin_post_wpdi_export_report', array( $this, 'export_report' ) );
		add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
	}

	/** Register Tools page. */
	public function add_menu_page() {
		add_management_page(
			__( 'SAC Database Inspector', 'sac-database-inspector' ),
			__( 'DB Inspector', 'sac-database-inspector' ),
			'manage_options',
			'database-inspector',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue local assets only on the plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_database-inspector' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wpdi-admin', WPDI_PLUGIN_URL . 'assets/css/wpdi-admin.css', array(), WPDI_VERSION );
		wp_enqueue_script( 'wpdi-admin', WPDI_PLUGIN_URL . 'assets/js/wpdi-admin.js', array( 'jquery' ), WPDI_VERSION, true );
		wp_localize_script(
			'wpdi-admin',
			'wpdiData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpdi_nonce' ),
				'readOnly' => $this->is_read_only(),
				'i18n'     => array(
					'confirmProceed' => __( 'This changes database data. SAC will first create a bounded safety snapshot where practical. Continue?', 'sac-database-inspector' ),
					'confirmRestore' => __( 'Restore this snapshot? Current matching records may be replaced.', 'sac-database-inspector' ),
					'cleaning'       => __( 'Working…', 'sac-database-inspector' ),
					'error'          => __( 'The request could not be completed.', 'sac-database-inspector' ),
					'readOnlyMode'   => __( 'Read-only mode is enabled.', 'sac-database-inspector' ),
					'aiWorking'      => __( 'Requesting an optional AI interpretation…', 'sac-database-inspector' ),
				),
			)
		);
	}

	/**
	 * Preserve the original flat statistics API.
	 *
	 * @return array
	 */
	public function get_database_stats() {
		$report      = $this->report->generate(
			array(
				'sections'          => array( 'health', 'autoload' ),
				'autoload_per_page' => 20,
			)
		);
		$runtime     = $report['health']['runtime'];
		$storage     = $report['health']['storage'];
		$maintenance = $report['health']['maintenance'];
		$top         = array();
		foreach ( $report['autoload']['items'] as $option ) {
			$top[] = (object) array(
				'option_name' => $option['name'],
				'size'        => $option['size'],
				'source'      => $option['owner'],
			);
		}

		return array(
			'total_db_size'         => $report['database']['total_size'],
			'options_table_size'    => $storage['options_table_size'],
			'info_schema_available' => $storage['information_schema_access'],
			'autoload_count'        => $runtime['autoload_count'],
			'autoload_size'         => $runtime['autoload_size'],
			'transient_count'       => $maintenance['transient_count'],
			'expired_transients'    => $maintenance['expired_transients'],
			'revisions_count'       => $maintenance['revisions'],
			'auto_drafts_count'     => $maintenance['auto_drafts'],
			'trashed_posts_count'   => $maintenance['trashed_posts'],
			'orphaned_postmeta'     => $maintenance['orphaned_postmeta'],
			'orphaned_commentmeta'  => $maintenance['orphaned_commentmeta'],
			'orphaned_usermeta'     => $maintenance['orphaned_usermeta'],
			'spam_comments'         => $maintenance['spam_comments'],
			'trashed_comments'      => $maintenance['trashed_comments'],
			'object_cache_enabled'  => $runtime['object_cache_enabled'],
			'top_autoload'          => $top,
			'health_score'          => $report['health']['score'],
		);
	}

	/**
	 * Register a deterministic Site Health summary.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_site_health_test( $tests ) {
		$tests['direct']['wpdi_database_health'] = array(
			'label' => __( 'SAC database health', 'sac-database-inspector' ),
			'test'  => array( $this, 'run_site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Return the central report's health result to Site Health.
	 *
	 * @return array
	 */
	public function run_site_health_test() {
		$report = $this->report->generate( array( 'sections' => array( 'health' ) ) );
		$score  = $report['health']['score'];
		$status = $score <= 40 ? 'good' : ( $score <= 70 ? 'recommended' : 'critical' );
		$url    = add_query_arg(
			array(
				'page'     => 'database-inspector',
				'wpdi_tab' => 'health',
			),
			admin_url( 'tools.php' )
		);

		return array(
			'label'       => sprintf(
				/* translators: %d: deterministic database health penalty score. */
				__( 'Database health penalty score is %d out of 100', 'sac-database-inspector' ),
				$score
			),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'sac-database-inspector' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'SAC calculated this deterministic score from runtime, storage, and maintenance metadata. Lower is healthier.', 'sac-database-inspector' ) . '</p>',
			'actions'     => '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Review database health details', 'sac-database-inspector' ) . '</a></p>',
			'test'        => 'wpdi_database_health',
		);
	}

	/** Existing read-only compatibility filter. */
	public function is_read_only() {
		return (bool) apply_filters( 'wpdi_read_only', false );
	}

	/** Read-only stats AJAX. */
	public function ajax_get_stats() {
		$this->authorize_ajax();
		wp_send_json_success( $this->get_database_stats() );
	}

	/** Snapshot-backed cleanup AJAX. */
	public function ajax_cleanup() {
		$this->authorize_ajax( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$action = isset( $_POST['cleanup_action'] ) ? sanitize_key( wp_unslash( $_POST['cleanup_action'] ) ) : '';
		/** Fires before a cleanup action, preserving the original hook. */
		do_action( 'wpdi_before_cleanup', $action );
		$result = $this->cleanup->perform( $action );
		/** Fires after a cleanup action, preserving the original hook. */
		do_action( 'wpdi_after_cleanup', $action, $result );
		$this->mark_first_success( $result );
		$this->send_result( $result );
	}

	/** Safe, redacted option preview AJAX. */
	public function ajax_option_preview() {
		global $wpdb;
		$this->authorize_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$name = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Invalid option name.', 'sac-database-inspector' ) ), 400 );
		}

		if ( $this->redactor->is_sensitive_name( $name ) ) {
			wp_send_json_success(
				array(
					'name'     => $name,
					'preview'  => '[redacted]',
					'redacted' => true,
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit administrator preview, capped in SQL.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT LEFT(option_value, 2000) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( null === $value ) {
			wp_send_json_error( array( 'message' => __( 'Option not found.', 'sac-database-inspector' ) ), 404 );
		}

		wp_send_json_success(
			array(
				'name'       => $name,
				'preview'    => $this->redactor->redact( (string) $value, $name ),
				'redacted'   => false,
				'truncated'  => strlen( (string) $value ) >= 2000,
				'serialized' => (bool) preg_match( '/^(?:a|O|C|s|i|b|d):/', (string) $value ),
			)
		);
	}

	/** Guarded autoload-state AJAX. */
	public function ajax_change_autoload() {
		$this->authorize_ajax( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$name = isset( $_POST['option_name'] ) ? sanitize_text_field( wp_unslash( $_POST['option_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
		$result  = $this->cleanup->change_autoload( $name, $enabled );
		$this->mark_first_success( $result );
		$this->send_result( $result );
	}

	/**
	 * Record the first successful maintenance action for the one-time review invitation.
	 *
	 * @param array $result Service result.
	 */
	private function mark_first_success( $result ) {
		$changed = 0;
		if ( isset( $result['deleted'] ) ) {
			$changed = (int) $result['deleted'];
		} elseif ( isset( $result['changed'] ) ) {
			$changed = (int) $result['changed'];
		}
		if ( ! empty( $result['success'] ) && $changed > 0 && ! get_option( 'wpdi_first_success_at' ) ) {
			update_option( 'wpdi_first_success_at', time(), false );
		}
	}

	/** Permanently dismiss the one-time review invitation. */
	public function ajax_dismiss_review() {
		$this->authorize_ajax();
		update_option( 'wpdi_review_dismissed', time(), false );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/** Restore a supported safety snapshot. */
	public function ajax_restore_snapshot() {
		$this->authorize_ajax( true );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$id     = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : '';
		$result = $this->snapshots->restore( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$result['message'] = sprintf(
			/* translators: %d: number of restored records. */
			__( 'Restored %d records.', 'sac-database-inspector' ),
			$result['restored']
		);
		wp_send_json_success( $result );
	}

	/** Optional advisory AI request. */
	public function ajax_ai_explain() {
		$this->authorize_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize_ajax() verified the AJAX nonce above.
		$focus  = isset( $_POST['focus'] ) ? sanitize_key( wp_unslash( $_POST['focus'] ) ) : 'health';
		$report = $this->report->generate();
		$result = $this->ai->explain( $report, $focus );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/** Stream a redacted report export. */
	public function export_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this report.', 'sac-database-inspector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpdi_export_report' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- check_admin_referer() verified the export nonce above.
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'json';
		$export = $this->exporter->export( $this->report->generate(), $format );
		if ( is_wp_error( $export ) ) {
			wp_die( esc_html( $export->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$filename = 'sac-database-inspector-' . gmdate( 'Y-m-d-His' ) . '.' . $export['extension'];
		nocache_headers();
		header( 'Content-Type: ' . $export['mime_type'] . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $export['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format-specific exporter escapes or encodes all content.
		exit;
	}

	/** Render the tabbed admin application. */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'sac-database-inspector' ), '', array( 'response' => 403 ) );
		}

		$tabs = array(
			'overview' => __( 'Overview', 'sac-database-inspector' ),
			'health'   => __( 'Health', 'sac-database-inspector' ),
			'plugins'  => __( 'Plugin Footprints', 'sac-database-inspector' ),
			'autoload' => __( 'Autoload', 'sac-database-inspector' ),
			'ghost'    => __( 'Ghost Data', 'sac-database-inspector' ),
			'tables'   => __( 'Tables', 'sac-database-inspector' ),
			'reports'  => __( 'Reports & AI', 'sac-database-inspector' ),
			'advanced' => __( 'Safety Snapshots', 'sac-database-inspector' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation filter; no state is changed.
		$tab  = isset( $_GET['wpdi_tab'] ) ? sanitize_key( wp_unslash( $_GET['wpdi_tab'] ) ) : 'overview';
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$args = array( 'sections' => array( 'health' ) );
		if ( 'plugins' === $tab ) {
			$args['sections'][] = 'plugins';
		} elseif ( 'autoload' === $tab || 'overview' === $tab ) {
			$args['sections'][] = 'autoload';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination filter; no state is changed.
			$args['autoload_page']     = isset( $_GET['wpdi_paged'] ) ? absint( $_GET['wpdi_paged'] ) : 1;
			$args['autoload_per_page'] = 'overview' === $tab ? 20 : 50;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search filter; no state is changed.
			$args['autoload_search'] = isset( $_GET['wpdi_search'] ) ? sanitize_text_field( wp_unslash( $_GET['wpdi_search'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort filter; no state is changed.
			$args['autoload_order'] = isset( $_GET['wpdi_order'] ) ? sanitize_key( wp_unslash( $_GET['wpdi_order'] ) ) : 'desc';
		} elseif ( 'ghost' === $tab ) {
			$args['sections'][] = 'ghost_data';
		}
		$report = in_array( $tab, array( 'advanced', 'reports' ), true ) ? null : $this->report->generate( $args );
		?>
		<div class="wrap wpdi-wrap">
			<h1><?php esc_html_e( 'SAC Database Inspector', 'sac-database-inspector' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Measured database facts are shown separately from heuristic ownership and optional AI interpretation.', 'sac-database-inspector' ); ?></p>
			<?php $this->render_review_banner(); ?>
			<nav class="nav-tab-wrapper wpdi-tabs" aria-label="<?php esc_attr_e( 'Database Inspector sections', 'sac-database-inspector' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="
					<?php
					echo esc_url(
						add_query_arg(
							array(
								'page'     => 'database-inspector',
								'wpdi_tab' => $slug,
							),
							admin_url( 'tools.php' )
						)
					);
					?>
										"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="wpdi-tab-panel">
				<?php
				switch ( $tab ) {
					case 'health':
						$this->render_health( $report );
						break;
					case 'plugins':
						$this->render_plugins( $report );
						break;
					case 'autoload':
						$this->render_autoload( $report );
						break;
					case 'ghost':
						$this->render_ghost( $report );
						break;
					case 'tables':
						$this->render_tables( $report );
						break;
					case 'reports':
						$this->render_reports();
						break;
					case 'advanced':
						$this->render_snapshots();
						break;
					default:
						$this->render_overview( $report );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render overview and legacy cleanup controls.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_overview( $report ) {
		$r = $report['health']['runtime'];
		$s = $report['health']['storage'];
		$m = $report['health']['maintenance'];

		$score           = (int) $report['health']['score'];
		$score_severity  = $score <= 40 ? 'good' : ( $score <= 70 ? 'warning' : 'critical' );
		$severity_labels = array(
			'good'     => __( 'Healthy', 'sac-database-inspector' ),
			'warning'  => __( 'Needs attention', 'sac-database-inspector' ),
			'critical' => __( 'Critical', 'sac-database-inspector' ),
		);
		?>
		<div class="wpdi-dashboard">
			<div class="wpdi-card wpdi-health-card wpdi-score-<?php echo esc_attr( $score_severity ); ?>"><h2><?php esc_html_e( 'Database Health', 'sac-database-inspector' ); ?></h2><div class="wpdi-score-value"><?php echo esc_html( $report['health']['score'] ); ?></div><span class="wpdi-score-badge"><?php echo esc_html( $severity_labels[ $score_severity ] ); ?></span><p><?php esc_html_e( 'Penalty score / 100 — lower is healthier.', 'sac-database-inspector' ); ?></p></div>
			<div class="wpdi-card"><h2><?php esc_html_e( 'Overview', 'sac-database-inspector' ); ?></h2><div class="wpdi-stats-grid">
				<?php $this->metric( self::format_bytes( $report['database']['total_size'] ), __( 'Total DB Size', 'sac-database-inspector' ) ); ?>
				<?php $this->metric( self::format_bytes( $r['autoload_size'] ), __( 'Autoload Size', 'sac-database-inspector' ) ); ?>
				<?php $this->metric( number_format_i18n( $r['autoload_count'] ), __( 'Autoloaded Options', 'sac-database-inspector' ) ); ?>
				<?php $this->metric( $r['object_cache_enabled'] ? __( 'Yes', 'sac-database-inspector' ) : __( 'No', 'sac-database-inspector' ), __( 'Object Cache', 'sac-database-inspector' ) ); ?>
			</div></div>
		</div>
		<?php
		if ( ! $s['information_schema_access'] ) :
			?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Table sizes are unavailable on this host; this is not treated as a database failure.', 'sac-database-inspector' ); ?></p></div><?php endif; ?>
		<div class="wpdi-card wpdi-cleanup-card"><h2><?php esc_html_e( 'Controlled Maintenance', 'sac-database-inspector' ); ?></h2>
			<p><?php esc_html_e( 'Each request handles at most 100 records. Re-run only after reviewing the updated count. Heuristic ghost findings are never included.', 'sac-database-inspector' ); ?></p>
			<?php
			if ( $this->is_read_only() ) :
				?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Read-only mode is enabled. All mutations are disabled.', 'sac-database-inspector' ); ?></p></div><?php endif; ?>
			<div class="wpdi-cleanup-grid">
				<?php
				$actions = array(
					'expired_transients'   => array( __( 'Expired Transients', 'sac-database-inspector' ), $m['expired_transients'] ),
					'all_transients'       => array( __( 'All Transient Rows', 'sac-database-inspector' ), $m['transient_count'] ),
					'revisions'            => array( __( 'Post Revisions', 'sac-database-inspector' ), $m['revisions'] ),
					'auto_drafts'          => array( __( 'Auto-Drafts', 'sac-database-inspector' ), $m['auto_drafts'] ),
					'trashed_posts'        => array( __( 'Trashed Posts', 'sac-database-inspector' ), $m['trashed_posts'] ),
					'orphaned_postmeta'    => array( __( 'Orphaned Post Meta', 'sac-database-inspector' ), $m['orphaned_postmeta'] ),
					'orphaned_commentmeta' => array( __( 'Orphaned Comment Meta', 'sac-database-inspector' ), $m['orphaned_commentmeta'] ),
					'orphaned_usermeta'    => array( __( 'Orphaned User Meta', 'sac-database-inspector' ), $m['orphaned_usermeta'] ),
					'spam_comments'        => array( __( 'Spam Comments', 'sac-database-inspector' ), $m['spam_comments'] ),
					'trashed_comments'     => array( __( 'Trashed Comments', 'sac-database-inspector' ), $m['trashed_comments'] ),
				);
				if ( $r['object_cache_enabled'] ) {
					$actions['object_cache'] = array( __( 'Object Cache', 'sac-database-inspector' ), 1 ); }
				foreach ( $actions as $action => $data ) :
					?>
				<div class="wpdi-cleanup-item<?php echo esc_attr( 0 === $data[1] ? ' wpdi-cleanup-zero' : '' ); ?>"><div class="wpdi-cleanup-info"><strong><?php echo esc_html( $data[0] ); ?></strong><span class="wpdi-count"><?php echo esc_html( number_format_i18n( $data[1] ) ); ?></span></div><button class="button wpdi-cleanup-btn" data-action="<?php echo esc_attr( $action ); ?>" <?php disabled( $this->is_read_only() || 0 === $data[1] ); ?>><?php esc_html_e( 'Run batch', 'sac-database-inspector' ); ?></button></div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php $this->render_autoload_table( $report['autoload'], false ); ?>
		<p><button id="wpdi-refresh" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Refresh Stats', 'sac-database-inspector' ); ?></button></p>
		<?php
	}

	/**
	 * Render health category detail.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_health( $report ) {
		foreach ( array(
			'runtime'     => __( 'Runtime Health', 'sac-database-inspector' ),
			'storage'     => __( 'Storage Health', 'sac-database-inspector' ),
			'maintenance' => __( 'Maintenance Health', 'sac-database-inspector' ),
		) as $key => $label ) {
			echo '<div class="wpdi-card"><h2>' . esc_html( $label ) . '</h2><dl class="wpdi-detail-list">';
			foreach ( $report['health'][ $key ] as $metric => $value ) {
				if ( null === $value ) {
					$display = __( 'Unavailable', 'sac-database-inspector' );
				} elseif ( is_bool( $value ) ) {
					$display = $value ? __( 'Yes', 'sac-database-inspector' ) : __( 'No', 'sac-database-inspector' );
				} else {
					$display = is_numeric( $value ) ? number_format_i18n( $value ) : (string) $value;
				}
				echo '<div><dt>' . esc_html( ucwords( str_replace( '_', ' ', $metric ) ) ) . '</dt><dd>' . esc_html( $display ) . '</dd></div>';
			}
			echo '</dl></div>';
		}
		echo '<div class="wpdi-card"><h2>' . esc_html__( 'Explainable Score Penalties', 'sac-database-inspector' ) . '</h2>';
		if ( empty( $report['health']['explanation'] ) ) {
			echo '<p>' . esc_html__( 'No deterministic penalties were applied.', 'sac-database-inspector' ) . '</p>'; }
		foreach ( $report['health']['explanation'] as $item ) {
			echo '<p><strong>+' . esc_html( $item['points'] ) . ' — ' . esc_html( ucwords( str_replace( '_', ' ', $item['code'] ) ) ) . '</strong><br>' . esc_html( $item['reason'] ) . '</p>'; }
		echo '</div>';
	}

	/**
	 * Render plugin footprint table.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_plugins( $report ) {
		?>
		<div class="wpdi-card wpdi-plugins-card"><h2><?php esc_html_e( 'Plugin Database Footprints', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'Only exact installed-plugin prefixes and curated exact prefixes are attributed. Estimates omit unknown artifacts and may undercount.', 'sac-database-inspector' ); ?></p>
		<div class="wpdi-table-scroll"><table class="widefat striped wpdi-responsive-table"><thead><tr><th><?php esc_html_e( 'Plugin', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Status', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Options', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Autoload', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Transients', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Metadata', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Tables', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Estimated total', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Confidence', 'sac-database-inspector' ); ?></th></tr></thead><tbody>
		<?php
		foreach ( $report['plugins'] as $plugin ) :
			?>
			<tr><td><?php echo esc_html( $plugin['plugin'] ); ?></td><td><span class="wpdi-status wpdi-status-<?php echo esc_attr( $plugin['status'] ); ?>"><?php echo esc_html( ucfirst( $plugin['status'] ) ); ?></span></td><td><?php echo esc_html( number_format_i18n( $plugin['options_count'] ) . ' / ' . self::format_bytes( $plugin['options_size'] ) ); ?></td><td><?php echo esc_html( self::format_bytes( $plugin['autoload_size'] ) ); ?></td><td><?php echo esc_html( self::format_bytes( $plugin['transient_size'] ) ); ?></td><td><?php echo esc_html( self::format_bytes( $plugin['postmeta_size'] + $plugin['usermeta_size'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( count( $plugin['custom_tables'] ) ) ); ?></td><td><?php echo esc_html( self::format_bytes( $plugin['estimated_total'] ) ); ?></td><td><?php echo esc_html( ucfirst( $plugin['confidence'] ) ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></div>
		<?php
	}

	/**
	 * Render the autoload page.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_autoload( $report ) {
		?>
		<div class="wpdi-card"><h2><?php esc_html_e( 'Autoload Inspector', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'Values are never shown by default. Preview is capped and sensitive names are redacted. Protected core, theme, widget, and transient options cannot be changed.', 'sac-database-inspector' ); ?></p>
		<form method="get" class="wpdi-filter-form"><input type="hidden" name="page" value="database-inspector"><input type="hidden" name="wpdi_tab" value="autoload"><label><span class="screen-reader-text"><?php esc_html_e( 'Search options', 'sac-database-inspector' ); ?></span><input type="search" name="wpdi_search" value="<?php echo esc_attr( $report['autoload']['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search option names', 'sac-database-inspector' ); ?>"></label><select name="wpdi_order"><option value="desc" <?php selected( $report['autoload']['order'], 'desc' ); ?>><?php esc_html_e( 'Largest first', 'sac-database-inspector' ); ?></option><option value="asc" <?php selected( $report['autoload']['order'], 'asc' ); ?>><?php esc_html_e( 'Smallest first', 'sac-database-inspector' ); ?></option></select><button class="button"><?php esc_html_e( 'Filter', 'sac-database-inspector' ); ?></button></form></div>
		<?php $this->render_autoload_table( $report['autoload'], true ); ?>
		<?php
	}

	/**
	 * Render the shared autoload table.
	 *
	 * @param array $autoload Paginated autoload data.
	 * @param bool  $actions  Whether to show inspection and mutation controls.
	 */
	private function render_autoload_table( $autoload, $actions ) {
		?>
		<div class="wpdi-card wpdi-autoload-card"><h2><?php esc_html_e( 'Autoloaded Options', 'sac-database-inspector' ); ?></h2><div class="wpdi-table-scroll"><table class="widefat striped wpdi-responsive-table"><thead><tr><th><?php esc_html_e( 'Option', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Owner / status', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Confidence', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Size', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'State', 'sac-database-inspector' ); ?></th>
		<?php
		if ( $actions ) :
			?>
			<th><?php esc_html_e( 'Inspect / manage', 'sac-database-inspector' ); ?></th><?php endif; ?></tr></thead><tbody>
		<?php
		if ( empty( $autoload['items'] ) ) :
			?>
			<tr><td colspan="6"><?php esc_html_e( 'No matching autoloaded options.', 'sac-database-inspector' ); ?></td></tr><?php endif; ?>
		<?php
		foreach ( $autoload['items'] as $option ) :
			?>
			<tr><td><code><?php echo esc_html( $option['name'] ); ?></code>
			<?php
			if ( $option['serialized'] ) :
				?>
			<span class="wpdi-badge"><?php esc_html_e( 'serialized', 'sac-database-inspector' ); ?></span><?php endif; ?></td><td><?php echo esc_html( $option['owner'] ); ?>
			<?php
			if ( $option['owner_status'] ) :
				?>
	<br><small><?php echo esc_html( str_replace( '_', ' ', ucfirst( $option['owner_status'] ) ) ); ?></small><?php endif; ?></td><td title="<?php echo esc_attr( $option['reason'] ); ?>"><?php echo esc_html( ucfirst( $option['confidence'] ) ); ?></td><td><?php echo esc_html( self::format_bytes( $option['size'] ) ); ?></td><td><code><?php echo esc_html( $option['autoload'] ); ?></code>
			<?php
			if ( $option['protected'] ) :
				?>
	<span class="wpdi-badge wpdi-protected"><?php esc_html_e( 'protected', 'sac-database-inspector' ); ?></span><?php endif; ?></td>
			<?php
			if ( $actions ) :
				?>
	<td><button class="button button-small wpdi-preview-option" data-option="<?php echo esc_attr( $option['name'] ); ?>"><?php esc_html_e( 'Preview', 'sac-database-inspector' ); ?></button> <button class="button button-small wpdi-autoload-toggle" data-option="<?php echo esc_attr( $option['name'] ); ?>" data-enabled="0" <?php disabled( $this->is_read_only() || $option['protected'] ); ?>><?php esc_html_e( 'Disable autoload', 'sac-database-inspector' ); ?></button></td><?php endif; ?></tr><?php endforeach; ?>
		</tbody></table></div>
		<?php
		if ( $actions && $autoload['total_pages'] > 1 ) :
			?>
			<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'wpdi_paged', '%#%' ),
						'format'  => '',
						'current' => $autoload['page'],
						'total'   => $autoload['total_pages'],
					)
				)
			);
			?>
			</div></div><?php endif; ?>
		</div><div id="wpdi-option-preview" class="wpdi-preview" hidden><button type="button" class="wpdi-preview-close" aria-label="<?php esc_attr_e( 'Close preview', 'sac-database-inspector' ); ?>">×</button><h2><?php esc_html_e( 'Safe option preview', 'sac-database-inspector' ); ?></h2><pre></pre></div>
		<?php
	}

	/**
	 * Render ghost findings without delete buttons.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_ghost( $report ) {
		?>
		<div class="wpdi-card wpdi-ghost-card"><h2><?php esc_html_e( 'Ghost / Orphan Data', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'Scanning and deletion are separate. Missing-plugin findings are heuristic and cannot be deleted from this screen. Inactive installed plugins are not classified as missing.', 'sac-database-inspector' ); ?></p><div class="wpdi-table-scroll"><table class="widefat striped wpdi-responsive-table"><thead><tr><th><?php esc_html_e( 'Artifact', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Type', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Owner / status', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Confidence', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Why shown', 'sac-database-inspector' ); ?></th></tr></thead><tbody>
		<?php
		if ( empty( $report['ghost_data'] ) ) :
			?>
			<tr><td colspan="5"><?php esc_html_e( 'No bounded high-confidence findings were detected.', 'sac-database-inspector' ); ?></td></tr><?php endif; ?>
		<?php
		foreach ( $report['ghost_data'] as $item ) :
			?>
			<tr><td><code><?php echo esc_html( $item['artifact'] ); ?></code></td><td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $item['type'] ) ) ); ?></td><td><?php echo esc_html( $item['owner'] ); ?><br><small><?php echo esc_html( str_replace( '_', ' ', ucfirst( $item['owner_status'] ) ) ); ?></small></td><td><?php echo esc_html( ucfirst( $item['confidence'] ) ); ?></td><td><?php echo esc_html( $item['reason'] ); ?></td></tr><?php endforeach; ?>
		</tbody></table></div></div>
		<?php
	}

	/**
	 * Render table storage details.
	 *
	 * @param array $report Normalized report.
	 */
	private function render_tables( $report ) {
		?>
		<div class="wpdi-card wpdi-tables-card"><h2><?php esc_html_e( 'Database Tables', 'sac-database-inspector' ); ?></h2>
		<?php
		if ( empty( $report['tables'] ) ) :
			?>
			<p><?php esc_html_e( 'Table metadata is unavailable because information_schema access is restricted. Other reports remain available.', 'sac-database-inspector' ); ?></p>
			<?php
else :
	?>
			<div class="wpdi-table-scroll"><table class="widefat striped wpdi-responsive-table"><thead><tr><th><?php esc_html_e( 'Table', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Size', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Rows (estimate)', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Owner / status', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Confidence', 'sac-database-inspector' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( $report['tables'] as $table ) :
				?>
	<tr><td><code><?php echo esc_html( $table['name'] ); ?></code></td><td><?php echo esc_html( self::format_bytes( $table['size'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $table['row_count'] ) ); ?></td><td><?php echo esc_html( $table['owner'] ); ?>
				<?php
				if ( $table['owner_status'] ) :
					?>
	<br><small><?php echo esc_html( str_replace( '_', ' ', ucfirst( $table['owner_status'] ) ) ); ?></small><?php endif; ?></td><td title="<?php echo esc_attr( $table['reason'] ); ?>"><?php echo esc_html( ucfirst( $table['confidence'] ) ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
		<?php
	}

	/** Export and optional AI controls. */
	private function render_reports() {
		?>
		<div class="wpdi-card"><h2><?php esc_html_e( 'Redacted Diagnostic Exports', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'All formats are generated from the same normalized report. They contain metadata, not raw option values, and pass through centralized redaction.', 'sac-database-inspector' ); ?></p>
		<?php
		foreach ( array(
			'json' => 'JSON',
			'csv'  => 'CSV',
			'html' => 'HTML',
		) as $format => $label ) :
										$url = wp_nonce_url(
											add_query_arg(
												array(
													'action' => 'wpdi_export_report',
													'format' => $format,
												),
												admin_url( 'admin-post.php' )
											),
											'wpdi_export_report'
										);
			?>
			<a class="button" href="<?php echo esc_url( $url ); ?>"><?php /* translators: %s: export file format, such as JSON. */ echo esc_html( sprintf( __( 'Export %s', 'sac-database-inspector' ), $label ) ); ?></a> <?php endforeach; ?>
		</div><div class="wpdi-card wpdi-ai-card"><h2><?php esc_html_e( 'Optional WordPress AI Interpretation', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'SAC measures the report first. When you explicitly request an explanation, only redacted metadata is sent through the provider-neutral WordPress AI Client. AI cannot run SQL or invoke cleanup.', 'sac-database-inspector' ); ?></p>
		<?php
		if ( $this->ai->is_available() ) :
			?>
			<select id="wpdi-ai-focus"><option value="health"><?php esc_html_e( 'Explain database health', 'sac-database-inspector' ); ?></option><option value="priorities"><?php esc_html_e( 'What should I investigate first?', 'sac-database-inspector' ); ?></option><option value="footprints"><?php esc_html_e( 'Explain plugin footprints', 'sac-database-inspector' ); ?></option><option value="autoload"><?php esc_html_e( 'Explain autoload impact', 'sac-database-inspector' ); ?></option><option value="ghost_data"><?php esc_html_e( 'Explain ghost findings', 'sac-database-inspector' ); ?></option></select> <button id="wpdi-ai-explain" class="button button-secondary"><?php esc_html_e( 'Request AI interpretation', 'sac-database-inspector' ); ?></button><div id="wpdi-ai-result" class="wpdi-ai-result" hidden><strong><?php esc_html_e( 'AI interpretation — not a measured SAC fact', 'sac-database-inspector' ); ?></strong><pre></pre></div>
			<p class="description"><?php esc_html_e( 'Responses come from the text-generation provider selected under Settings → Connectors.', 'sac-database-inspector' ); ?> <a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Manage connectors', 'sac-database-inspector' ); ?></a></p>
			<?php
else :
	?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'AI explanation is unavailable. WordPress 7.0 AI Client and a configured text-generation connector are required; all deterministic features remain available.', 'sac-database-inspector' ); ?></p><p><a class="button" href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Settings → Connectors', 'sac-database-inspector' ); ?></a></p></div><?php endif; ?>
		</div>
		<?php
	}

	/** Snapshot metadata and restore controls. */
	private function render_snapshots() {
		$list = $this->snapshots->list_metadata();
		?>
		<div class="wpdi-card wpdi-snapshots-card"><h2><?php esc_html_e( 'Safety Snapshots', 'sac-database-inspector' ); ?></h2><p><?php esc_html_e( 'SAC keeps at most 20 local snapshots for 14 days. Option and orphan-metadata batches are restorable; post/comment deletion snapshots are audit-only. Snapshot values are never included in exports or AI context.', 'sac-database-inspector' ); ?></p><div class="wpdi-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Created (UTC)', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Operation', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Records', 'sac-database-inspector' ); ?></th><th><?php esc_html_e( 'Restore', 'sac-database-inspector' ); ?></th></tr></thead><tbody>
		<?php
		if ( empty( $list ) ) :
			?>
			<tr><td colspan="4"><?php esc_html_e( 'No retained snapshots.', 'sac-database-inspector' ); ?></td></tr><?php endif; ?>
		<?php
		foreach ( $list as $item ) :
			?>
			<tr><td><?php echo esc_html( $item['created_at'] ); ?></td><td><code><?php echo esc_html( $item['operation'] ); ?></code></td><td><?php echo esc_html( number_format_i18n( $item['record_count'] ) ); ?></td><td>
			<?php
			if ( $item['restorable'] && ! $item['restored_at'] ) :
				?>
			<button class="button button-small wpdi-restore-snapshot" data-snapshot="<?php echo esc_attr( $item['id'] ); ?>" <?php disabled( $this->is_read_only() ); ?>><?php esc_html_e( 'Restore', 'sac-database-inspector' ); ?></button>
				<?php
elseif ( $item['restored_at'] ) :
	esc_html_e( 'Restored', 'sac-database-inspector' );
else :
	esc_html_e( 'Audit only', 'sac-database-inspector' );
endif;
?>
</td></tr><?php endforeach; ?>
		</tbody></table></div></div>
		<?php
	}

	/**
	 * Render the one-time review invitation on the plugin page only.
	 *
	 * Shown after the first successful maintenance action, dismissible in one
	 * click, and never repeated once dismissed.
	 */
	private function render_review_banner() {
		if ( ! get_option( 'wpdi_first_success_at' ) || get_option( 'wpdi_review_dismissed' ) ) {
			return;
		}
		?>
		<div class="wpdi-card wpdi-review-banner" role="status">
			<div class="wpdi-review-copy">
				<strong><?php esc_html_e( 'Is SAC Database Inspector helping you?', 'sac-database-inspector' ); ?></strong>
				<p><?php esc_html_e( 'You have already tidied part of your database with it. A quick 5-star review helps other administrators find the plugin — and this note will never appear again.', 'sac-database-inspector' ); ?></p>
			</div>
			<div class="wpdi-review-actions">
				<a class="button button-primary wpdi-review-link" href="https://wordpress.org/support/plugin/sac-database-inspector/reviews/#new-post" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Rate it ★★★★★', 'sac-database-inspector' ); ?></a>
				<button type="button" class="button wpdi-review-dismiss"><?php esc_html_e( 'No thanks', 'sac-database-inspector' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one headline metric.
	 *
	 * @param string $value Formatted metric value.
	 * @param string $label Metric label.
	 */
	private function metric( $value, $label ) {
		echo '<div class="wpdi-stat"><span class="wpdi-stat-value">' . esc_html( $value ) . '</span><span class="wpdi-stat-label">' . esc_html( $label ) . '</span></div>';
	}

	/**
	 * Enforce capability, nonce, read-only, and confirmation controls.
	 *
	 * @param bool $mutation Whether the request changes state.
	 */
	private function authorize_ajax( $mutation = false ) {
		check_ajax_referer( 'wpdi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'sac-database-inspector' ) ), 403 );
		}
		if ( $mutation ) {
			if ( $this->is_read_only() ) {
				wp_send_json_error( array( 'message' => __( 'Read-only mode is enabled. Changes are disabled.', 'sac-database-inspector' ) ), 403 );
			}
			$confirmed = isset( $_POST['confirmed'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmed'] ) ) : '';
			if ( '1' !== $confirmed ) {
				wp_send_json_error( array( 'message' => __( 'Explicit confirmation is required.', 'sac-database-inspector' ) ), 400 );
			}
		}
	}

	/**
	 * Normalize service result responses.
	 *
	 * @param array $result Service result.
	 */
	private function send_result( $result ) {
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( array( 'message' => isset( $result['message'] ) ? $result['message'] : __( 'The operation failed.', 'sac-database-inspector' ) ) );
	}

	/**
	 * Format bytes for display.
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = (int) $bytes;
		if ( function_exists( 'size_format' ) ) {
			return size_format( $bytes, 2 );
		}
		return $bytes . ' B';
	}
}
