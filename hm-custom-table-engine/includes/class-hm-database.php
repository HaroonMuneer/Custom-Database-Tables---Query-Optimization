<?php
/** Schema installation and verification. @package HM_Custom_Table_Engine */
defined( 'ABSPATH' ) || exit;

class HM_Database {
	public const VERSION = '1.0.0';
	public const VERSION_OPTION = 'hm_cte_schema_version';
	public const LOCK_OPTION = 'hm_cte_migration_lock';
	private wpdb $wpdb;

	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function table_name(): string {
		return $this->wpdb->prefix . 'custom_lead_logs';
	}

	public function version_matches(): bool {
		return self::VERSION === get_option( self::VERSION_OPTION, '' );
	}

	public function ready(): bool {
		return $this->version_matches() && $this->verify_schema();
	}

	/**
	 * Install or upgrade the additive schema. Never advance the version on failure.
	 *
	 * @return true|WP_Error
	 */
	public function install() {
		if ( is_multisite() ) {
			return new WP_Error( 'hm_multisite_unsupported', 'Multisite is not supported.' );
		}
		$prior = get_option( self::VERSION_OPTION, '' );
		if ( $prior && version_compare( $prior, self::VERSION, '>' ) ) {
			return new WP_Error( 'hm_schema_unavailable', 'Database schema is newer than this plugin.' );
		}
		$lock = get_option( self::LOCK_OPTION, false );
		if ( false !== $lock ) {
			if ( ! ctype_digit( (string) $lock ) || time() - (int) $lock < 300 ) {
				return new WP_Error( 'hm_schema_locked', 'A database migration is already running.' );
			}
			delete_option( self::LOCK_OPTION );
		}
		if ( ! add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
			return new WP_Error( 'hm_schema_locked', 'A database migration is already running.' );
		}
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$table = $this->table_name();
			$collate = $this->wpdb->get_charset_collate();
			$sql = "CREATE TABLE {$table} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
client_name varchar(100) NOT NULL,
property_id bigint unsigned NOT NULL,
status varchar(50) NOT NULL DEFAULT 'new',
budget decimal(10,2) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY property_id (property_id),
KEY created_at_id (created_at,id),
KEY status_created_at_id (status,created_at,id)
) ENGINE=InnoDB {$collate};";
			$this->wpdb->last_error = '';
			dbDelta( $sql );
			if ( $this->wpdb->last_error || ! $this->verify_schema() ) {
				return new WP_Error( 'hm_schema_unavailable', 'Lead table installation or verification failed.' );
			}
			// Version 1.0.0 is the baseline; future data migrations run here before advancement.
			update_option( self::VERSION_OPTION, self::VERSION, false );
			return true;
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/** Verify table, required column definitions, and indexes. */
	public function verify_schema(): bool {
		$table = $this->table_name();
		$this->wpdb->last_error = '';
		$columns = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		if ( $this->wpdb->last_error || ! is_array( $columns ) ) {
			return false;
		}
		$expected = array( 'id' => 'bigint unsigned', 'client_name' => 'varchar(100)', 'property_id' => 'bigint unsigned', 'status' => 'varchar(50)', 'budget' => 'decimal(10,2)', 'created_at' => 'datetime' );
		$found = array();
		foreach ( $columns as $column ) {
			$found[ $column['Field'] ] = strtolower( $column['Type'] );
		}
		foreach ( $expected as $name => $type ) {
			if ( ! isset( $found[ $name ] ) || $type !== $found[ $name ] ) {
				return false;
			}
		}
		$this->wpdb->last_error = '';
		$indexes = $this->wpdb->get_results( $this->wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( $this->wpdb->last_error || ! is_array( $indexes ) ) {
			return false;
		}
		$actual = array();
		foreach ( $indexes as $index ) {
			$actual[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
		}
		$required = array( 'PRIMARY' => array( 'id' ), 'property_id' => array( 'property_id' ), 'created_at_id' => array( 'created_at', 'id' ), 'status_created_at_id' => array( 'status', 'created_at', 'id' ) );
		foreach ( $required as $name => $fields ) {
			if ( ! isset( $actual[ $name ] ) ) {
				return false;
			}
			ksort( $actual[ $name ] );
			if ( array_values( $actual[ $name ] ) !== $fields ) {
				return false;
			}
		}
		return true;
	}
}
