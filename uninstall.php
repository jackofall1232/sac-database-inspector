<?php
/**
 * Uninstall script for WordPress Database Inspector.
 *
 * The plugin creates no custom tables or settings. It only removes its own
 * locally stored safety snapshots on uninstall; inspected site data remains.
 *
 * @package WP_Database_Inspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	$wpdi_offset = 0;
	do {
		$wpdi_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $wpdi_offset,
			)
		);
		foreach ( $wpdi_site_ids as $wpdi_site_id ) {
			switch_to_blog( $wpdi_site_id );
			delete_option( 'wpdi_safety_snapshots' );
			restore_current_blog();
		}
		$wpdi_site_count = count( $wpdi_site_ids );
		$wpdi_offset    += $wpdi_site_count;
	} while ( 100 === $wpdi_site_count );
} else {
	delete_option( 'wpdi_safety_snapshots' );
}
