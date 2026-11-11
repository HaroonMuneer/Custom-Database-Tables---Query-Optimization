<?php
/** Run with: wp eval-file wp-content/plugins/hm-custom-table-engine/tests/integration.php */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() || ! defined( 'HM_CTE_PATH' ) ) {
	WP_CLI::error( 'Requires a local WordPress environment and active HM Custom Table Engine.' );
}

global $wpdb;
$failures = array();
$check = static function ( $condition, string $label ) use ( &$failures ): void {
	if ( ! $condition ) { $failures[] = $label; }
	WP_CLI::log( ( $condition ? 'PASS ' : 'FAIL ' ) . $label );
};
$database = new HM_Database( $wpdb );
$check( true === $database->install(), 'schema installation' );
$check( $database->ready(), 'schema verification' );
$repository = hm_cte_leads();
$missing_type = ! post_type_exists( 'property' );
if ( $missing_type ) {
	$check( 'hm_invalid_property' === $repository->insert_lead( array( 'client_name' => 'Test', 'property_id' => 1, 'budget' => '1.00' ) )->get_error_code(), 'missing property type' );
	register_post_type( 'property', array( 'public' => false ) );
}
$property = wp_insert_post( array( 'post_type' => 'property', 'post_status' => 'publish', 'post_title' => 'HM integration fixture' ), true );
if ( is_wp_error( $property ) ) { WP_CLI::error( 'Could not create property fixture.' ); }
$wrong = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'HM wrong type fixture' ), true );
$created = array();
try {
	$check( is_wp_error( $repository->insert_lead( array( 'client_name' => 'Bad', 'property_id' => $wrong, 'budget' => '1.00' ) ) ), 'wrong post type rejected' );
	$check( is_wp_error( $repository->insert_lead( array( 'client_name' => '', 'property_id' => $property, 'budget' => '1.00' ) ) ), 'empty name rejected' );
	$check( is_wp_error( $repository->insert_lead( array( 'client_name' => 'Bad', 'property_id' => $property, 'budget' => '-1' ) ) ), 'negative budget rejected' );
	$check( is_wp_error( $repository->insert_lead( array( 'client_name' => 'Bad', 'property_id' => $property, 'budget' => '1.00', 'status' => 'invalid' ) ) ), 'invalid status rejected' );
	foreach ( array( '11.00', '22.00', '33.00' ) as $budget ) {
		$id = $repository->insert_lead( array( 'client_name' => 'Fixture ' . $budget, 'property_id' => $property, 'budget' => $budget ) );
		if ( is_wp_error( $id ) ) { $failures[] = 'insert lead'; break; }
		$created[] = $id;
	}
	if ( 3 === count( $created ) ) {
		$check( '11.00' === $repository->get_lead( $created[0] )['budget'], 'insert and read' );
		$check( 1 === $repository->update_status( $created[0], 'qualified' ), 'status update' );
		$check( 1 === $repository->update_budget( $created[0], '44.00' ), 'budget update' );
		$check( '44.00' === $repository->get_lead( $created[0] )['budget'], 'updated budget read' );
		$filtered = $repository->get_filtered_leads( array( 'status' => 'qualified', 'min_budget' => '40.00' ) );
		$check( ! is_wp_error( $filtered ) && in_array( $created[0], array_column( $filtered['items'], 'id' ), true ), 'combined filtering' );
		$page = $repository->get_all_leads( array( 'page' => 1, 'per_page' => 1 ) );
		$check( ! is_wp_error( $page ) && 1 === count( $page['items'] ) && $page['total'] >= 3, 'pagination and count' );
		$check( is_wp_error( $repository->get_all_leads( array( 'per_page' => 101 ) ) ), 'page size limit' );
		$check( is_wp_error( $repository->get_all_leads( array( 'order' => 'DESC; DROP TABLE x' ) ) ), 'sort injection rejected' );
	}
} finally {
	foreach ( $created as $id ) { $repository->delete_lead( $id ); }
	wp_delete_post( $property, true );
	wp_delete_post( $wrong, true );
}
if ( $failures ) { WP_CLI::error( implode( ', ', $failures ) ); }
WP_CLI::success( 'Integration checks passed.' );
