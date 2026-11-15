<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A taxonomy query which appends the content fallback conditions to its SQL.
 *
 * It replaces the taxonomy query of a `WP_Query` when the content fallback applies,
 * so that the conditions are added even when the filters are suppressed (`get_posts()`).
 *
 * @since 4.1.0
 */
class Strope_Content_Fallback_Tax_Query extends WP_Tax_Query {
	/**
	 * Arguments used to build the fallback SQL.
	 * Only scalar values, so that the object stays serializable.
	 *
	 * @see Strope_Content_Fallback::get_posts_fallback_where()
	 *
	 * @var array
	 */
	public $fallback_args = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param array $tax_query     The taxonomy query (see `WP_Tax_Query`).
	 * @param array $fallback_args Arguments used to build the fallback SQL.
	 */
	public function __construct( $tax_query, $fallback_args ) {
		parent::__construct( $tax_query );
		$this->fallback_args = $fallback_args;
	}

	/**
	 * Generates the SQL clauses to be appended to a main query.
	 *
	 * @since 4.1.0
	 *
	 * @param string $primary_table     Database table where the object being filtered is stored (eg wp_users).
	 * @param string $primary_id_column ID column for the filtered object in $primary_table.
	 * @return string[] {
	 *     Array containing JOIN and WHERE SQL clauses to append to the main query.
	 *
	 *     @type string $join  SQL fragment to append to the main JOIN clause.
	 *     @type string $where SQL fragment to append to the main WHERE clause.
	 * }
	 */
	public function get_sql( $primary_table, $primary_id_column ) {
		$sql = parent::get_sql( $primary_table, $primary_id_column );

		if ( ! empty( $this->fallback_args ) ) {
			$sql['where'] .= Strope_Content_Fallback::get_posts_exclusion_where( "{$primary_table}.{$primary_id_column}", "{$primary_table}.post_type", $this->fallback_args );
		}

		return $sql;
	}
}
