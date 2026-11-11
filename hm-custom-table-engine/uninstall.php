<?php
/** Plugin data retention. @package HM_Custom_Table_Engine */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( is_multisite() ) {
	return;
}

delete_option( 'hm_cte_migration_lock' );
if ( true !== get_option( 'hm_cte_delete_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$table = $wpdb->prefix . 'custom_lead_logs';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
if ( ! $wpdb->last_error ) {
	delete_option( 'hm_cte_schema_version' );
	delete_option( 'hm_cte_delete_data_on_uninstall' );
}
