<?php
/**
 * Main plugin bootstrap class.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads and initializes SAC Database Inspector.
 */
final class WPDI_Database_Inspector {

	/**
	 * Single instance.
	 *
	 * @var WPDI_Database_Inspector|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return WPDI_Database_Inspector
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Load dependencies and initialize admin hooks. */
	private function __construct() {
		$this->includes();
		if ( is_admin() ) {
			WPDI_Admin::instance();
		}
	}

	/** Include focused plugin services. */
	private function includes() {
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-redactor.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-ownership.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-report.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-snapshots.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-cleanup.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-exporter.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-ai.php';
		require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-admin.php';
	}

	/** Activation hook. */
	public static function activate() {
		// Reserved for idempotent migrations.
	}

	/** Deactivation hook. */
	public static function deactivate() {
		// Snapshots are retained until uninstall or expiry.
	}
}
