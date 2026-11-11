<?php
/** Lead persistence. @package HM_Custom_Table_Engine */
defined( 'ABSPATH' ) || exit;

class HM_Lead_Repository {
	private const STATUSES = array( 'new', 'contacted', 'qualified', 'won', 'lost' );
	private wpdb $wpdb;
	private HM_Database $database;

	public function __construct( wpdb $wpdb, HM_Database $database ) {
		$this->wpdb = $wpdb;
		$this->database = $database;
	}

	private function available() {
		return $this->database->ready() ? true : new WP_Error( 'hm_schema_unavailable', 'Lead table is not ready.' );
	}

	private function positive_int( $value ) {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
			return new WP_Error( 'hm_invalid_input', 'A positive integer is required.' );
		}
		$number = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false === $number ? new WP_Error( 'hm_invalid_input', 'Integer is out of range.' ) : $number;
	}

	private function budget( $value ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return new WP_Error( 'hm_invalid_input', 'Budget must be a decimal string.' );
		}
		$value = (string) $value;
		if ( ! preg_match( '/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D', $value ) ) {
			return new WP_Error( 'hm_invalid_input', 'Budget must be between 0.00 and 99999999.99.' );
		}
		$parts = explode( '.', $value );
		return $parts[0] . '.' . str_pad( $parts[1] ?? '', 2, '0' );
	}

	private function validate_fields( array $data, bool $creating = false ) {
		$allowed = array( 'client_name', 'property_id', 'status', 'budget' );
		if ( ! $data || array_diff( array_keys( $data ), $allowed ) ) {
			return new WP_Error( 'hm_invalid_input', 'Unknown or empty lead fields.' );
		}
		if ( $creating && ( ! isset( $data['client_name'], $data['property_id'], $data['budget'] ) ) ) {
			return new WP_Error( 'hm_invalid_input', 'Name, property, and budget are required.' );
		}
		if ( isset( $data['client_name'] ) ) {
			if ( ! is_string( $data['client_name'] ) || ! wp_check_invalid_utf8( $data['client_name'], true ) ) {
				return new WP_Error( 'hm_invalid_input', 'Invalid client name.' );
			}
			$data['client_name'] = sanitize_text_field( $data['client_name'] );
			if ( '' === trim( $data['client_name'] ) || mb_strlen( $data['client_name'], 'UTF-8' ) > 100 ) {
				return new WP_Error( 'hm_invalid_input', 'Client name must contain 1–100 characters.' );
			}
		}
		if ( isset( $data['property_id'] ) ) {
			$data['property_id'] = $this->positive_int( $data['property_id'] );
			if ( is_wp_error( $data['property_id'] ) ) {
				return $data['property_id'];
			}
			if ( ! post_type_exists( 'property' ) ) {
				return new WP_Error( 'hm_invalid_property', 'The property post type is not registered.' );
			}
			$post = get_post( $data['property_id'] );
			if ( ! $post || 'property' !== $post->post_type ) {
				return new WP_Error( 'hm_invalid_property', 'Property must reference an existing property post.' );
			}
		}
		if ( isset( $data['status'] ) && ( ! is_string( $data['status'] ) || ! in_array( $data['status'], self::STATUSES, true ) ) ) {
			return new WP_Error( 'hm_invalid_input', 'Unsupported lead status.' );
		}
		if ( isset( $data['budget'] ) ) {
			$data['budget'] = $this->budget( $data['budget'] );
			if ( is_wp_error( $data['budget'] ) ) {
				return $data['budget'];
			}
		}
		return $data;
	}

	private function formats( array $data ): array {
		$formats = array( 'client_name' => '%s', 'property_id' => '%d', 'status' => '%s', 'budget' => '%s', 'created_at' => '%s' );
		return array_map( static fn( $field ) => $formats[ $field ], array_keys( $data ) );
	}

	public function insert_lead( array $data ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$data = $this->validate_fields( $data, true );
		if ( is_wp_error( $data ) ) { return $data; }
		$data['status'] = $data['status'] ?? 'new';
		$data['created_at'] = current_time( 'mysql', true );
		$result = $this->wpdb->insert( $this->database->table_name(), $data, $this->formats( $data ) );
		return false === $result ? new WP_Error( 'hm_database_error', 'Could not insert lead.' ) : (int) $this->wpdb->insert_id;
	}

	public function get_lead( $id ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$id = $this->positive_int( $id );
		if ( is_wp_error( $id ) ) { return $id; }
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT id, client_name, property_id, status, budget, created_at FROM %i WHERE id = %d', $this->database->table_name(), $id ), ARRAY_A );
		if ( $this->wpdb->last_error ) { return new WP_Error( 'hm_database_error', 'Could not read lead.' ); }
		return $row ? $this->normalize( $row ) : null;
	}

	private function normalize( array $row ): array {
		$row['id'] = (int) $row['id'];
		$row['property_id'] = (int) $row['property_id'];
		$row['budget'] = (string) $row['budget'];
		return $row;
	}

	public function update_lead( $id, array $data ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$id = $this->positive_int( $id );
		if ( is_wp_error( $id ) ) { return $id; }
		$data = $this->validate_fields( $data );
		if ( is_wp_error( $data ) ) { return $data; }
		$result = $this->wpdb->update( $this->database->table_name(), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) );
		return false === $result ? new WP_Error( 'hm_database_error', 'Could not update lead.' ) : (int) $result;
	}

	public function update_status( $id, $status ) { return $this->update_lead( $id, array( 'status' => $status ) ); }
	public function update_budget( $id, $budget ) { return $this->update_lead( $id, array( 'budget' => $budget ) ); }

	public function delete_lead( $id ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$id = $this->positive_int( $id );
		if ( is_wp_error( $id ) ) { return $id; }
		$result = $this->wpdb->delete( $this->database->table_name(), array( 'id' => $id ), array( '%d' ) );
		return false === $result ? new WP_Error( 'hm_database_error', 'Could not delete lead.' ) : (int) $result;
	}

	public function get_all_leads( array $args = array() ) { return $this->get_filtered_leads( $args ); }

	private function query_parts( array $args ) {
		if ( array_diff( array_keys( $args ), array( 'status', 'min_budget', 'page', 'per_page', 'order' ) ) ) {
			return new WP_Error( 'hm_invalid_input', 'Unknown query option.' );
		}
		$page = $this->positive_int( $args['page'] ?? 1 );
		$per_page = $this->positive_int( $args['per_page'] ?? 20 );
		if ( is_wp_error( $page ) || is_wp_error( $per_page ) || $per_page > 100 || $page - 1 > intdiv( PHP_INT_MAX, $per_page ) ) {
			return new WP_Error( 'hm_invalid_input', 'Invalid pagination.' );
		}
		$order = $args['order'] ?? 'DESC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			return new WP_Error( 'hm_invalid_input', 'Invalid sort direction.' );
		}
		$where = array( '1=1' );
		$values = array();
		if ( array_key_exists( 'status', $args ) ) {
			if ( ! is_string( $args['status'] ) || ! in_array( $args['status'], self::STATUSES, true ) ) { return new WP_Error( 'hm_invalid_input', 'Invalid status filter.' ); }
			$where[] = 'status = %s';
			$values[] = $args['status'];
		}
		if ( array_key_exists( 'min_budget', $args ) ) {
			$budget = $this->budget( $args['min_budget'] );
			if ( is_wp_error( $budget ) ) { return $budget; }
			$where[] = 'budget >= %s';
			$values[] = $budget;
		}
		return array( 'where' => implode( ' AND ', $where ), 'values' => $values, 'page' => $page, 'per_page' => $per_page, 'order' => $order );
	}

	public function count_leads( array $args = array() ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$parts = $this->query_parts( $args );
		if ( is_wp_error( $parts ) ) { return $parts; }
		return $this->count_parts( $parts );
	}

	private function count_parts( array $parts ) {
		$sql = 'SELECT COUNT(*) FROM %i WHERE ' . $parts['where'];
		$this->wpdb->last_error = '';
		$count = $this->wpdb->get_var( $this->wpdb->prepare( $sql, array_merge( array( $this->database->table_name() ), $parts['values'] ) ) );
		return $this->wpdb->last_error || null === $count ? new WP_Error( 'hm_database_error', 'Could not count leads.' ) : (int) $count;
	}

	public function get_filtered_leads( array $args = array() ) {
		$ready = $this->available();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$parts = $this->query_parts( $args );
		if ( is_wp_error( $parts ) ) { return $parts; }
		$total = $this->count_parts( $parts );
		if ( is_wp_error( $total ) ) { return $total; }
		$sql = 'SELECT id, client_name, property_id, status, budget, created_at FROM %i WHERE ' . $parts['where'] . ' ORDER BY created_at ' . $parts['order'] . ', id ' . $parts['order'] . ' LIMIT %d OFFSET %d';
		$params = array_merge( array( $this->database->table_name() ), $parts['values'], array( $parts['per_page'], ( $parts['page'] - 1 ) * $parts['per_page'] ) );
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( $this->wpdb->last_error || ! is_array( $rows ) ) { return new WP_Error( 'hm_database_error', 'Could not list leads.' ); }
		return array( 'items' => array_map( array( $this, 'normalize' ), $rows ), 'page' => $parts['page'], 'per_page' => $parts['per_page'], 'total' => $total, 'total_pages' => (int) ceil( $total / $parts['per_page'] ) );
	}
}
