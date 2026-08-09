<?php
/**
 * Plugin Name:       SAC Database Inspector
 * Plugin URI:        https://github.com/jackofall1232/sac-database-inspector
 * Description:       Read-only administrative utility for inspecting database tables and cache usage to help identify potential bloat.
 * Version:           1.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            jackofall1232
 * Author URI:        https://wordpress.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sac-database-inspector
 * Domain Path:       /languages
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

define( 'WPDI_VERSION', '1.1.0' );
define( 'WPDI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPDI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WPDI_PLUGIN_DIR . 'includes/class-wpdi-database-inspector.php';

register_activation_hook( __FILE__, array( 'WPDI_Database_Inspector', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPDI_Database_Inspector', 'deactivate' ) );

/**
 * Initialize plugin.
 *
 * @return WPDI_Database_Inspector
 */
function wpdi() {
	return WPDI_Database_Inspector::instance();
}

wpdi();
