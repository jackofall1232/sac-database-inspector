<?php
/**
 * Conservative artifact ownership identification.
 *
 * @package WPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifies core, theme, and plugin database artifacts.
 */
class WPDI_Ownership {

	/**
	 * Installed plugin inventory.
	 *
	 * @var array|null
	 */
	private $plugins = null;

	/**
	 * Known signatures. These are attribution hints, never deletion authority.
	 *
	 * @var array
	 */
	private $known_signatures = array(
		'woocommerce'            => array( 'woocommerce_', 'wc_' ),
		'wordpress-seo'          => array( 'wpseo_', '_yoast_' ),
		'all-in-one-seo-pack'    => array( 'aioseop_' ),
		'seo-by-rank-math'       => array( 'rank_math_' ),
		'elementor'              => array( 'elementor_', '_elementor_' ),
		'easy-digital-downloads' => array( 'edd_' ),
		'w3-total-cache'         => array( 'w3tc_' ),
		'wp-rocket'              => array( 'wp_rocket_' ),
		'autoptimize'            => array( 'autoptimize_' ),
		'litespeed-cache'        => array( 'litespeed_' ),
		'wordfence'              => array( 'wordfence_' ),
		'better-wp-security'     => array( 'itsec_' ),
		'updraftplus'            => array( 'updraftplus_' ),
		'wpforms-lite'           => array( 'wpforms_' ),
		'gravityforms'           => array( 'gf_' ),
		'ninja-forms'            => array( 'ninja_forms_' ),
		'contact-form-7'         => array( 'wpcf7_', 'cf7_' ),
		'jetpack'                => array( 'jetpack_' ),
		'akismet'                => array( 'akismet_' ),
	);

	/**
	 * Protected WordPress options.
	 *
	 * @var string[]
	 */
	private $protected_options = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'users_can_register',
		'default_role',
		'start_of_week',
		'use_balancetags',
		'use_smilies',
		'require_name_email',
		'comments_notify',
		'posts_per_rss',
		'rss_use_excerpt',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'posts_per_page',
		'date_format',
		'time_format',
		'links_updated_date_format',
		'comment_moderation',
		'moderation_notify',
		'permalink_structure',
		'rewrite_rules',
		'hack_file',
		'blog_charset',
		'moderation_keys',
		'active_plugins',
		'category_base',
		'ping_sites',
		'comment_max_links',
		'gmt_offset',
		'default_email_category',
		'recently_edited',
		'template',
		'stylesheet',
		'comment_registration',
		'html_type',
		'use_trackback',
		'default_role',
		'db_version',
		'uploads_use_yearmonth_folders',
		'upload_path',
		'blog_public',
		'default_link_category',
		'show_on_front',
		'tag_base',
		'show_avatars',
		'avatar_rating',
		'upload_url_path',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'image_default_link_type',
		'image_default_size',
		'image_default_align',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'sticky_posts',
		'widget_categories',
		'widget_text',
		'widget_rss',
		'uninstall_plugins',
		'timezone_string',
		'page_for_posts',
		'page_on_front',
		'default_post_format',
		'link_manager_enabled',
		'finished_splitting_shared_terms',
		'site_icon',
		'medium_large_size_w',
		'medium_large_size_h',
		'wp_page_for_privacy_policy',
		'show_comments_cookies_opt_in',
		'admin_email_lifespan',
		'disallowed_keys',
		'auto_plugin_theme_update_emails',
		'wp_force_deactivated_plugins',
		'wp_attachment_pages_enabled',
		'wp_user_roles',
		'cron',
		'theme_switched',
		'recently_activated',
		'sidebars_widgets',
	);

	/**
	 * Get installed plugin inventory.
	 *
	 * @return array
	 */
	public function get_plugins() {
		if ( null !== $this->plugins ) {
			return $this->plugins;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed      = get_plugins();
		$active         = (array) get_option( 'active_plugins', array() );
		$network_active = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$this->plugins  = array();

		foreach ( $installed as $file => $headers ) {
			$directory = dirname( $file );
			$slug      = '.' === $directory ? sanitize_title( basename( $file, '.php' ) ) : sanitize_title( $directory );
			$status    = in_array( $file, $active, true ) || in_array( $file, $network_active, true ) ? 'active' : 'inactive';
			$prefixes  = $this->build_plugin_prefixes( $slug, $headers );

			$this->plugins[ $slug ] = array(
				'slug'     => $slug,
				'file'     => $file,
				'name'     => isset( $headers['Name'] ) ? wp_strip_all_tags( $headers['Name'] ) : $slug,
				'version'  => isset( $headers['Version'] ) ? wp_strip_all_tags( $headers['Version'] ) : '',
				'status'   => $status,
				'prefixes' => $prefixes,
			);
		}

		return $this->plugins;
	}

	/**
	 * Identify an artifact by name.
	 *
	 * @param string $name Artifact name without a WordPress table prefix.
	 * @param string $type Artifact type.
	 * @return array
	 */
	public function identify( $name, $type = 'option' ) {
		$name       = strtolower( (string) $name );
		$core_exact = $this->protected_options;

		if ( 'option' === $type && in_array( $name, $core_exact, true ) ) {
			return $this->result( 'WordPress Core', 'core', 'confirmed', 'Exact protected WordPress option.' );
		}

		if ( 'option' === $type ) {
			$transient_prefixes = array( '_transient_timeout_', '_transient_', '_site_transient_timeout_', '_site_transient_' );
			foreach ( $transient_prefixes as $transient_prefix ) {
				if ( 0 === strpos( $name, $transient_prefix ) ) {
					$payload_owner = $this->identify( substr( $name, strlen( $transient_prefix ) ), 'transient' );
					if ( 'plugin' === $payload_owner['owner_type'] ) {
						$payload_owner['reason'] = 'Exact plugin prefix inside a WordPress transient name.';
						return $payload_owner;
					}
					return $this->result( 'WordPress Core', 'core', 'confirmed', 'WordPress transient storage prefix.' );
				}
			}
		}

		$core_prefixes = array( 'widget_', 'theme_mods_' );
		foreach ( $core_prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$owner_type = 'theme_mods_' === $prefix ? 'theme' : 'core';
				$owner      = 'theme' === $owner_type ? 'Theme' : 'WordPress Core';
				return $this->result( $owner, $owner_type, 'confirmed', 'Exact WordPress naming prefix.' );
			}
		}

		foreach ( $this->get_plugins() as $plugin ) {
			foreach ( $plugin['prefixes'] as $prefix ) {
				if ( $this->matches_prefix( $name, $prefix ) ) {
					return $this->result( $plugin['name'], 'plugin', 'high', 'Exact installed-plugin prefix match.', $plugin['status'], $plugin['slug'] );
				}
			}
		}

		foreach ( $this->known_signatures as $slug => $prefixes ) {
			foreach ( $prefixes as $prefix ) {
				if ( $this->matches_prefix( $name, $prefix ) ) {
					$plugin = isset( $this->get_plugins()[ $slug ] ) ? $this->get_plugins()[ $slug ] : null;
					return $this->result(
						$plugin ? $plugin['name'] : ucwords( str_replace( '-', ' ', $slug ) ),
						'plugin',
						'high',
						'Exact known plugin prefix match.',
						$plugin ? $plugin['status'] : 'not_installed',
						$slug
					);
				}
			}
		}

		return $this->result( 'Unknown', 'unknown', 'unknown', 'No sufficiently strong ownership evidence.' );
	}

	/**
	 * Determine whether an option is protected from modification.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public function is_protected_option( $name ) {
		$name = strtolower( (string) $name );

		return in_array( $name, $this->protected_options, true )
			|| 0 === strpos( $name, 'wp_' )
			|| ( strlen( $name ) > 11 && '_user_roles' === substr( $name, -11 ) )
			|| 0 === strpos( $name, '_transient_' )
			|| 0 === strpos( $name, '_site_transient_' )
			|| 0 === strpos( $name, 'theme_mods_' )
			|| 0 === strpos( $name, 'widget_' );
	}

	/**
	 * Build conservative prefixes from plugin metadata.
	 *
	 * @param string $slug    Plugin slug.
	 * @param array  $headers Plugin headers.
	 * @return string[]
	 */
	private function build_plugin_prefixes( $slug, $headers ) {
		$candidates = array( $slug );
		if ( ! empty( $headers['TextDomain'] ) ) {
			$candidates[] = $headers['TextDomain'];
		}
		if ( isset( $this->known_signatures[ $slug ] ) ) {
			$candidates = array_merge( $candidates, $this->known_signatures[ $slug ] );
		}

		$prefixes = array();
		foreach ( $candidates as $candidate ) {
			$candidate = strtolower( trim( (string) $candidate ) );
			if ( strlen( preg_replace( '/[^a-z0-9]/', '', $candidate ) ) < 3 ) {
				continue;
			}
			$prefixes[] = rtrim( str_replace( '-', '_', $candidate ), '_' ) . '_';
			$prefixes[] = rtrim( str_replace( '_', '-', $candidate ), '-' ) . '-';
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Prefix match with a boundary already included in the prefix.
	 *
	 * @param string $name   Artifact name.
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	private function matches_prefix( $name, $prefix ) {
		return strlen( $prefix ) >= 4 && 0 === strpos( $name, strtolower( $prefix ) );
	}

	/**
	 * Format an ownership result.
	 *
	 * @param string $owner      Owner label.
	 * @param string $type       Owner type.
	 * @param string $confidence Confidence.
	 * @param string $reason     Evidence explanation.
	 * @param string $status     Owner status.
	 * @param string $slug       Plugin slug.
	 * @return array
	 */
	private function result( $owner, $type, $confidence, $reason, $status = '', $slug = '' ) {
		return array(
			'owner'        => $owner,
			'owner_type'   => $type,
			'owner_slug'   => $slug,
			'owner_status' => $status,
			'confidence'   => $confidence,
			'reason'       => $reason,
		);
	}
}
