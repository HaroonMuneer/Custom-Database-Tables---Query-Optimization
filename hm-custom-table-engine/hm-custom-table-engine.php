<?php
/**
 * Plugin Name: HM Custom Table Engine
 * Description: Typed property lead storage in a dedicated WordPress database table.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Text Domain: hm-custom-table-engine
 *
 * @package HM_Custom_Table_Engine
 */

defined( 'ABSPATH' ) || exit;

define( 'HM_CTE_PATH', plugin_dir_path( __FILE__ ) );
require_once HM_CTE_PATH . 'includes/class-hm-database.php';
require_once HM_CTE_PATH . 'includes/class-hm-lead-repository.php';
require_once HM_CTE_PATH . 'includes/class-hm-benchmark.php';

/** Install the site table on activation. */
function hm_cte_activate( $network_wide = false ) {
	if ( $network_wide ) {
		wp_die( esc_html__( 'Network activation is not supported.', 'hm-custom-table-engine' ) );
	}
	if ( version_compare( PHP_VERSION, '8.2', '<' ) || version_compare( get_bloginfo( 'version' ), '6.2', '<' ) || PHP_INT_SIZE < 8 ) {
		wp_die( esc_html__( 'HM Custom Table Engine requires WordPress 6.2+, PHP 8.2+, and 64-bit PHP.', 'hm-custom-table-engine' ) );
	}
	global $wpdb;
	$result = ( new HM_Database( $wpdb ) )->install();
	if ( is_wp_error( $result ) ) {
		wp_die( esc_html( $result->get_error_message() ) );
	}
}
register_activation_hook( __FILE__, 'hm_cte_activate' );

/** Remove transient migration state without touching business data. */
function hm_cte_deactivate() {
	delete_option( HM_Database::LOCK_OPTION );
}
register_deactivation_hook( __FILE__, 'hm_cte_deactivate' );

/** Check for schema upgrades after plugin updates. */
function hm_cte_maybe_upgrade() {
	if ( is_multisite() || version_compare( PHP_VERSION, '8.2', '<' ) || version_compare( get_bloginfo( 'version' ), '6.2', '<' ) ) {
		return;
	}
	global $wpdb;
	$database = new HM_Database( $wpdb );
	if ( ! $database->version_matches() ) {
		$database->install();
	}
}
add_action( 'plugins_loaded', 'hm_cte_maybe_upgrade' );

/**
 * Construct a repository for the current site.
 *
 * @return HM_Lead_Repository
 */
function hm_cte_leads() {
	global $wpdb;
	return new HM_Lead_Repository( $wpdb, new HM_Database( $wpdb ) );
}
