<?php
/** Run only with WP-CLI inside the isolated Docker compose project. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { exit( 1 ); }
wp_set_current_user( 1 );
if ( ! post_type_exists( 'property' ) ) { register_post_type( 'property', array( 'public' => false ) ); }
$property = wp_insert_post( array( 'post_type' => 'property', 'post_status' => 'publish', 'post_title' => 'Benchmark property' ), true );
if ( is_wp_error( $property ) ) { WP_CLI::error( $property->get_error_message() ); }
global $wpdb;
$bench = new HM_Benchmark( $wpdb, hm_cte_leads() );
$seed = $bench->seed( (int) $property );
if ( is_wp_error( $seed ) ) { WP_CLI::error( $seed->get_error_message() ); }
$results = $bench->run_suite();
if ( is_wp_error( $results ) ) { WP_CLI::error( $results->get_error_message() ); }
WP_CLI::log( wp_json_encode( array( 'versions' => array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'mysql' => $wpdb->db_version() ), 'results' => $results ), JSON_PRETTY_PRINT ) );
$cleaned = $bench->cleanup();
if ( is_wp_error( $cleaned ) ) { WP_CLI::error( $cleaned->get_error_message() ); }
wp_delete_post( $property, true );
