<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database tables of the machine translation (the jobs themselves are in the tables of the job queue, see
 * Strope_Machine_Translation::kit()).
 *
 * - runs:   one per request ("translate these posts into these languages").
 * - memory: engine output keyed by source text, language route and model version, reused across runs.
 *
 * @since 4.2.0
 */
class Strope_MT_Store {
	/**
	 * Database schema version.
	 *
	 * @var int
	 */
	const DB_VERSION = 2;

	/**
	 * Option storing the installed schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'strope_mt_db_version';

	/**
	 * Run statuses.
	 */
	const RUN_ACTIVE    = 'active';
	const RUN_PAUSED    = 'paused';
	const RUN_CANCELLED = 'cancelled';
	const RUN_DONE      = 'done';



	/**
	 * Returns a table name.
	 *
	 * @since 4.2.0
	 *
	 * @param string $name `runs` or `memory`.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'strope_mt_' . $name;
	}

	/**
	 * Returns the current time in the MySQL format (GMT).
	 *
	 * @since 4.2.0
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( int $offset = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	/**
	 * Creates or upgrades the tables when needed.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates the tables.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs            = self::table( 'runs' );
		$memory          = self::table( 'memory' );

		dbDelta(
			array(
				"CREATE TABLE $runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  args longtext NOT NULL,
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset_collate;",
				"CREATE TABLE $memory (
  hash char(32) NOT NULL,
  translation longtext NOT NULL,
  created datetime NOT NULL,
  used datetime NOT NULL,
  PRIMARY KEY  (hash)
) $charset_collate;",
			)
		);

		// Version 1 had its own queue: its runs cannot continue in the job queue.
		if ( 1 === (int) get_option( self::DB_VERSION_OPTION ) ) {
			self::drop_legacy();
			$wpdb->query( "TRUNCATE TABLE $runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		Strope_Machine_Translation::kit()->install();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drops the queue tables of version 1.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	protected static function drop_legacy() {
		global $wpdb;

		foreach ( array( 'jobs', 'segments' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Drops the tables (uninstall).
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		foreach ( array( 'runs', 'memory' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		self::drop_legacy();
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Inserts a row and returns its id.
	 *
	 * @since 4.2.0
	 *
	 * @param string $name Table short name.
	 * @param array  $data Row data.
	 * @return int 0 on failure.
	 */
	public static function insert( string $name, array $data ): int {
		global $wpdb;

		return $wpdb->insert( self::table( $name ), $data ) ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates rows.
	 *
	 * @since 4.2.0
	 *
	 * @param string $name  Table short name.
	 * @param array  $data  Data to update.
	 * @param array  $where Where clause.
	 * @return int|false Number of updated rows.
	 */
	public static function update( string $name, array $data, array $where ) {
		global $wpdb;

		return $wpdb->update( self::table( $name ), $data, $where );
	}

	/**
	 * Returns a run.
	 *
	 * @since 4.2.0
	 *
	 * @param int $id Run id.
	 * @return object|null
	 */
	public static function get_run( int $id ) {
		global $wpdb;

		$table = self::table( 'runs' );
		$run   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $run ) {
			$run->args = self::decode( $run->args );
		}

		return $run;
	}

	/**
	 * Returns a translation from the memory.
	 *
	 * @since 4.2.0
	 *
	 * @param string[] $hashes Memory keys.
	 * @return string[] Translations keyed by hash.
	 */
	public static function get_memory( array $hashes ): array {
		global $wpdb;

		$hashes = array_values( array_unique( array_filter( $hashes, 'is_string' ) ) );
		if ( empty( $hashes ) ) {
			return array();
		}

		$table = self::table( 'memory' );
		$found = array();

		foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT hash, translation FROM $table WHERE hash IN ($in)", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$found[ $row->hash ] = $row->translation;
			}
		}

		if ( $found ) {
			$keys = array_keys( $found );
			$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET used = %s WHERE hash IN ($in)", array_merge( array( self::now() ), $keys ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $found;
	}

	/**
	 * Saves a translation in the memory.
	 *
	 * @since 4.2.0
	 *
	 * @param string $hash        Memory key.
	 * @param string $translation Raw engine output.
	 * @return void
	 */
	public static function set_memory( string $hash, string $translation ) {
		global $wpdb;

		$table = self::table( 'memory' );
		$now   = self::now();

		$wpdb->query( $wpdb->prepare( "REPLACE INTO $table (hash, translation, created, used) VALUES (%s, %s, %s, %s)", $hash, $translation, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Empties the translation memory.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function clear_memory() {
		global $wpdb;

		$table = self::table( 'memory' );
		$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns the number of entries in the translation memory.
	 *
	 * @since 4.2.0
	 *
	 * @return int
	 */
	public static function count_memory(): int {
		global $wpdb;

		$table = self::table( 'memory' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Decodes a JSON column.
	 *
	 * @since 4.2.0
	 *
	 * @param mixed $json JSON string.
	 * @return array
	 */
	public static function decode( $json ): array {
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Encodes a JSON column.
	 *
	 * @since 4.2.0
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function encode( $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $json ? '' : $json;
	}
}
