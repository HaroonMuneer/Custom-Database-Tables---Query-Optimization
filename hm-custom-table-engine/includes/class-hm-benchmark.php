<?php
/** Development-only benchmark operations. @package HM_Custom_Table_Engine */
defined( 'ABSPATH' ) || exit;

class HM_Benchmark {
	private wpdb $wpdb;
	private HM_Lead_Repository $repository;
	private string $post_type = 'hm_cte_benchmark';

	public function __construct( wpdb $wpdb, HM_Lead_Repository $repository ) {
		$this->wpdb = $wpdb;
		$this->repository = $repository;
	}

	/** Require explicit local, admin, and development context for every operation. */
	private function permitted() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'hm_benchmark_denied', 'Benchmarking requires a local development site and administrator.' );
		}
		return true;
	}

	private function register_type(): void {
		if ( ! post_type_exists( $this->post_type ) ) {
			register_post_type( $this->post_type, array( 'public' => false, 'show_ui' => false, 'supports' => array( 'title' ) ) );
		}
	}

	/**
	 * Create a deterministic 5,000-pair fixture on a disposable local site.
	 * The manifest allows cleanup without touching unrelated data.
	 */
	public function seed( int $property_id ) {
		$allowed = $this->permitted();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( get_option( 'hm_cte_benchmark_manifest', false ) ) { return new WP_Error( 'hm_benchmark_exists', 'Remove the prior fixture first.' ); }
		if ( ! post_type_exists( 'property' ) || 'property' !== get_post_type( $property_id ) ) { return new WP_Error( 'hm_invalid_property', 'An existing property post is required.' ); }
		$this->register_type();
		$manifest = array( 'custom' => array(), 'posts' => array(), 'pairs' => array(), 'property_id' => $property_id, 'seed' => 20261111, 'complete' => false );
		add_option( 'hm_cte_benchmark_manifest', $manifest, '', false );
		$statuses = array( 'new', 'contacted', 'qualified', 'won', 'lost' );
		for ( $i = 1; $i <= 5000; $i++ ) {
			$name = sprintf( 'Benchmark Lead %04d', $i );
			$status = $statuses[ ( $i * 7 ) % count( $statuses ) ];
			$budget = sprintf( '%d.%02d', 10000 + ( $i * 7919 ) % 90000, $i % 100 );
			$date = gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 00:00:00 UTC' ) + intdiv( $i, 3 ) * 60 );
			$lead = $this->repository->insert_lead( array( 'client_name' => $name, 'property_id' => $property_id, 'status' => $status, 'budget' => $budget ) );
			if ( is_wp_error( $lead ) ) { return $lead; }
			$manifest['custom'][] = $lead;
			// Fixture timestamps are controlled solely by this development helper.
			if ( false === $this->wpdb->update( $this->wpdb->prefix . 'custom_lead_logs', array( 'created_at' => $date ), array( 'id' => $lead ), array( '%s' ), array( '%d' ) ) ) {
				update_option( 'hm_cte_benchmark_manifest', $manifest, false );
				return new WP_Error( 'hm_database_error', 'Could not set fixture timestamp.' );
			}
			$post = wp_insert_post( array( 'post_type' => $this->post_type, 'post_status' => 'publish', 'post_title' => $name, 'post_date_gmt' => $date, 'post_date' => get_date_from_gmt( $date ), 'meta_input' => array( 'client_name' => $name, 'property_id' => $property_id, 'status' => $status, 'budget' => $budget ) ), true );
			if ( is_wp_error( $post ) ) { return $post; }
			$manifest['posts'][] = $post;
			$manifest['pairs'][ $lead ] = $post;
			// Persist each pair so an interrupted seed can be cleaned up safely.
			update_option( 'hm_cte_benchmark_manifest', $manifest, false );
		}
		$manifest['complete'] = true;
		update_option( 'hm_cte_benchmark_manifest', $manifest, false );
		return $manifest;
	}

	/** Execute one equivalent filtered page; no automatic hooks invoke this method. */
	public function run( array $filters = array(), int $runs = 30, int $warmups = 5 ) {
		$allowed = $this->permitted();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$manifest = get_option( 'hm_cte_benchmark_manifest', false );
		if ( ! is_array( $manifest ) || empty( $manifest['complete'] ) || 5000 !== count( $manifest['pairs'] ) ) { return new WP_Error( 'hm_benchmark_missing', 'Complete benchmark fixture is required.' ); }
		if ( $runs < 1 || $runs > 100 || $warmups < 0 || $warmups > 20 ) { return new WP_Error( 'hm_invalid_input', 'Invalid sample count.' ); }
		$this->register_type();
		$filters = array_merge( array( 'page' => 1, 'per_page' => 20, 'order' => 'DESC' ), $filters );
		if ( array_diff( array_keys( $filters ), array( 'status', 'min_budget', 'page', 'per_page', 'order' ) ) ) { return new WP_Error( 'hm_invalid_input', 'Unsupported benchmark filter.' ); }
		$probe = $this->repository->get_filtered_leads( $filters );
		if ( is_wp_error( $probe ) ) { return $probe; }
		$samples = array( 'custom' => array(), 'posts' => array() );
		for ( $i = -$warmups; $i < $runs; $i++ ) {
			foreach ( 0 === $i % 2 ? array( 'custom', 'posts' ) : array( 'posts', 'custom' ) as $kind ) {
				wp_cache_flush();
				if ( function_exists( 'memory_reset_peak_usage' ) ) { memory_reset_peak_usage(); }
				$before_memory = memory_get_usage();
				$before_queries = $this->wpdb->num_queries;
				$before_log = isset( $this->wpdb->queries ) && is_array( $this->wpdb->queries ) ? count( $this->wpdb->queries ) : null;
				timer_start();
				$result = 'custom' === $kind ? $this->repository->get_filtered_leads( $filters ) : $this->query_posts( $filters );
				$seconds = (float) timer_stop( 0, 6 );
				if ( is_wp_error( $result ) ) { return $result; }
				if ( $i >= 0 ) {
					$db_seconds = null;
					if ( null !== $before_log && count( $this->wpdb->queries ) > $before_log ) {
						$db_seconds = array_sum( array_map( static fn( $query ) => (float) $query[1], array_slice( $this->wpdb->queries, $before_log ) ) );
					}
					$sample = array( 'seconds' => $seconds, 'db_call_seconds' => $db_seconds, 'memory_delta_bytes' => memory_get_usage() - $before_memory, 'peak_bytes' => memory_get_peak_usage(), 'queries' => $this->wpdb->num_queries - $before_queries, 'total' => $result['total'], 'ids' => 'custom' === $kind ? array_column( $result['items'], 'id' ) : $result['ids'] );
					if ( 0 === $i && null !== $before_log ) {
						$sample['sql'] = array_column( array_slice( $this->wpdb->queries, $before_log ), 0 );
					}
					$samples[ $kind ][] = $sample;
				}
			}
		}
		foreach ( $samples['custom'] as $i => $sample ) {
			$mapped = array_map( static fn( $id ) => (int) $manifest['pairs'][ $id ], $sample['ids'] );
			if ( $sample['total'] !== $samples['posts'][ $i ]['total'] || $mapped !== $samples['posts'][ $i ]['ids'] ) { return new WP_Error( 'hm_benchmark_mismatch', 'Fixture query results are not equivalent.' ); }
		}
		return array( 'filters' => $filters, 'warmups' => $warmups, 'samples' => $samples, 'summary' => array( 'custom' => $this->summarize( $samples['custom'] ), 'posts' => $this->summarize( $samples['posts'] ) ), 'note' => 'Elapsed time includes database waits. PHP and database timings are not isolated.' );
	}

	private function query_posts( array $filters ) {
		$args = array( 'post_type' => $this->post_type, 'post_status' => 'publish', 'posts_per_page' => $filters['per_page'], 'paged' => $filters['page'], 'orderby' => array( 'date' => $filters['order'], 'ID' => $filters['order'] ), 'no_found_rows' => false, 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
		$meta_query = array( 'relation' => 'AND' );
		if ( array_key_exists( 'status', $filters ) ) {
			$meta_query[] = array( 'key' => 'status', 'value' => $filters['status'], 'compare' => '=' );
		}
		if ( array_key_exists( 'min_budget', $filters ) ) {
			$meta_query[] = array( 'key' => 'budget', 'value' => $filters['min_budget'], 'compare' => '>=', 'type' => 'DECIMAL(10,2)' );
		}
		if ( count( $meta_query ) > 1 ) { $args['meta_query'] = $meta_query; }
		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array( 'id' => (int) $post->ID, 'client_name' => get_post_meta( $post->ID, 'client_name', true ), 'property_id' => (int) get_post_meta( $post->ID, 'property_id', true ), 'status' => get_post_meta( $post->ID, 'status', true ), 'budget' => get_post_meta( $post->ID, 'budget', true ), 'created_at' => $post->post_date_gmt );
		}
		return array( 'total' => (int) $query->found_posts, 'ids' => array_column( $items, 'id' ), 'items' => $items );
	}

	private function summarize( array $samples ): array {
		$values = array_column( $samples, 'seconds' );
		sort( $values, SORT_NUMERIC );
		$count = count( $values );
		return array( 'mean_seconds' => array_sum( $values ) / $count, 'median_seconds' => $count % 2 ? $values[ intdiv( $count, 2 ) ] : ( $values[ $count / 2 - 1 ] + $values[ $count / 2 ] ) / 2, 'min_seconds' => $values[0], 'max_seconds' => $values[ $count - 1 ] );
	}

	/** Run representative workloads without silently reusing filters from another case. */
	public function run_suite( int $runs = 30, int $warmups = 5 ) {
		$workloads = array(
			'unfiltered' => array(),
			'status' => array( 'status' => 'new' ),
			'budget' => array( 'min_budget' => '20000.00' ),
			'combined' => array( 'status' => 'new', 'min_budget' => '20000.00' ),
			'no_matches' => array( 'status' => 'won', 'min_budget' => '99999999.99' ),
			'deep_page' => array( 'status' => 'new', 'page' => 30 ),
		);
		$results = array();
		foreach ( $workloads as $name => $filters ) {
			$result = $this->run( $filters, $runs, $warmups );
			if ( is_wp_error( $result ) ) { return $result; }
			$results[ $name ] = $result;
		}
		return $results;
	}

	/** Delete only IDs recorded in the manifest. */
	public function cleanup() {
		$allowed = $this->permitted();
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$manifest = get_option( 'hm_cte_benchmark_manifest', false );
		if ( ! is_array( $manifest ) ) { return 0; }
		foreach ( $manifest['posts'] as $id ) {
			if ( ! wp_delete_post( (int) $id, true ) ) { return new WP_Error( 'hm_benchmark_cleanup', 'Could not remove every benchmark post; manifest retained.' ); }
		}
		foreach ( $manifest['custom'] as $id ) {
			$result = $this->repository->delete_lead( (int) $id );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		delete_option( 'hm_cte_benchmark_manifest' );
		return count( $manifest['custom'] );
	}
}
