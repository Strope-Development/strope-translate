<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Storage of the discovered theme and plugin strings and of their translations (overrides).
 *
 * Two custom tables (per site on multisite):
 * - `{$wpdb->prefix}strope_strings`: the strings found by the scanner (or saved from the front end).
 * - `{$wpdb->prefix}strope_string_translations`: the translations, one row per string and language.
 *
 * The runtime never queries these tables: the translations are compiled into one non-autoloaded
 * option per language (see `self::rebuild_cache()`), and an autoloaded index tells which text domains
 * have overrides in which language.
 *
 * @since 4.1.0
 */
class Strope_String_Discovery_Store {
	/**
	 * Database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Option storing the database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'strope_string_discovery_db_version';

	/**
	 * Autoloaded option storing the text domains having overrides, per language slug.
	 *
	 * @var string
	 */
	const INDEX_OPTION = 'strope_string_overrides';

	/**
	 * Prefix of the non-autoloaded options storing the compiled overrides, one per language slug.
	 *
	 * @var string
	 */
	const CACHE_OPTION_PREFIX = 'strope_string_overrides_';

	/**
	 * Maximum length of the sources column.
	 *
	 * @var int
	 */
	const SOURCES_MAX_LENGTH = 1000;

	/**
	 * Whether the tables have been checked during this request.
	 *
	 * @var bool
	 */
	protected static $checked = false;

	/**
	 * Returns the name of the strings table.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	public static function strings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'strope_strings';
	}

	/**
	 * Returns the name of the translations table.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	public static function translations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'strope_string_translations';
	}

	/**
	 * Creates or upgrades the tables if needed.
	 *
	 * @since 4.1.0
	 *
	 * @return bool True if the tables are available.
	 */
	public static function maybe_install(): bool {
		if ( self::$checked ) {
			return true;
		}

		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}

		self::$checked = self::DB_VERSION === get_option( self::DB_VERSION_OPTION );
		return self::$checked;
	}

	/**
	 * Resets the internal flag, for example after switching blog.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function reset() {
		self::$checked = false;
	}

	/**
	 * Creates the tables with dbDelta.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$strings         = self::strings_table();
		$translations    = self::translations_table();

		dbDelta(
			array(
				"CREATE TABLE $strings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  domain varchar(191) NOT NULL DEFAULT '',
  context text NOT NULL,
  singular text NOT NULL,
  plural text NOT NULL,
  hash char(32) NOT NULL,
  component varchar(191) NOT NULL DEFAULT '',
  sources text NOT NULL,
  created datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash),
  KEY domain (domain),
  KEY component (component)
) $charset_collate;",
				"CREATE TABLE $translations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  string_id bigint(20) unsigned NOT NULL,
  language varchar(64) NOT NULL,
  translations longtext NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  updated datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY string_language (string_id,language),
  KEY language (language)
) $charset_collate;",
			)
		);

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $translations ) ) ) === $translations ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		}

		if ( false === get_option( self::INDEX_OPTION ) ) {
			// Always stored (autoloaded) to avoid a query on each request.
			add_option( self::INDEX_OPTION, array(), '', true );
		}
	}

	/**
	 * Computes the unique hash of a string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $domain   Text domain.
	 * @param string $context  Context, empty string if none.
	 * @param string $singular Singular (or only) form.
	 * @param string $plural   Plural form, empty string if none.
	 * @return string
	 */
	public static function hash( string $domain, string $context, string $singular, string $plural = '' ): string {
		return md5( $domain . '|' . $context . "\4" . $singular . "\0" . $plural );
	}

	/**
	 * Returns the current GMT date in MySQL format.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Inserts or refreshes strings found by the scanner.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $strings   Strings keyed by hash, each an array with keys domain, context, singular, plural and sources (string[]).
	 * @param string $component Component id.
	 * @param string $job_start Date (GMT, MySQL format) at which the current scan started.
	 * @return void
	 */
	public static function save_scanned_strings( array $strings, string $component, string $job_start ) {
		global $wpdb;

		if ( empty( $strings ) || ! self::maybe_install() ) {
			return;
		}

		$table = self::strings_table();
		$now   = self::now();

		foreach ( array_chunk( $strings, 200, true ) as $chunk ) {
			$hashes   = array_keys( $chunk );
			$existing = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hash, sources, last_seen FROM $table WHERE hash IN (" . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$hashes
				),
				OBJECT_K
			);

			$by_hash = array();
			foreach ( $existing as $row ) {
				$by_hash[ $row->hash ] = $row;
			}

			$inserts = array();
			$seen    = array();

			foreach ( $chunk as $hash => $string ) {
				$sources = implode( "\n", array_unique( $string['sources'] ) );

				if ( isset( $by_hash[ $hash ] ) ) {
					$row = $by_hash[ $hash ];

					// The sources are reset when the string is seen for the first time during this scan.
					$new_sources = $row->last_seen < $job_start ? $sources : $row->sources . "\n" . $sources;
					$new_sources = self::truncate_sources( $new_sources );

					if ( $new_sources !== $row->sources ) {
						$wpdb->update( $table, array( 'sources' => $new_sources, 'last_seen' => $now ), array( 'id' => (int) $row->id ) );
					} else {
						$seen[] = (int) $row->id;
					}
					continue;
				}

				$inserts[] = $wpdb->prepare(
					'(%s, %s, %s, %s, %s, %s, %s, %s, %s)',
					$string['domain'],
					$string['context'],
					$string['singular'],
					$string['plural'],
					$hash,
					$component,
					self::truncate_sources( $sources ),
					$now,
					$now
				);
			}

			if ( $seen ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE $table SET last_seen = %s WHERE id IN (" . implode( ',', array_fill( 0, count( $seen ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One %d placeholder per id.
						array_merge( array( $now ), $seen )
					)
				);
			}

			if ( $inserts ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "INSERT IGNORE INTO $table (domain, context, singular, plural, hash, component, sources, created, last_seen) VALUES " . implode( ',', $inserts ) );
			}
		}
	}

	/**
	 * Truncates a newline separated list of sources.
	 *
	 * @since 4.1.0
	 *
	 * @param string $sources Sources.
	 * @return string
	 */
	protected static function truncate_sources( string $sources ): string {
		$sources = array_unique( array_filter( explode( "\n", $sources ) ) );
		$out     = '';

		foreach ( $sources as $source ) {
			if ( strlen( $out ) + strlen( $source ) + 1 > self::SOURCES_MAX_LENGTH ) {
				break;
			}
			$out .= ( '' === $out ? '' : "\n" ) . $source;
		}

		return $out;
	}

	/**
	 * Deletes the strings of a component which were not seen since a given date, and which have no translation.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $component Component id, null for all components but the ones in `$exclude`.
	 * @param string      $before    Date (GMT, MySQL format).
	 * @param string[]    $exclude   Components to keep when `$component` is null.
	 * @return void
	 */
	public static function delete_stale_strings( $component, string $before, array $exclude = array() ) {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return;
		}

		$strings      = self::strings_table();
		$translations = self::translations_table();

		if ( null === $component ) {
			$where = $wpdb->prepare( 's.last_seen < %s AND s.component <> %s', $before, '' );
			if ( $exclude ) {
				$where .= $wpdb->prepare( ' AND s.component NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')', $exclude ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		} else {
			$where = $wpdb->prepare( 's.last_seen < %s AND s.component = %s', $before, $component );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE s FROM $strings s LEFT JOIN $translations t ON t.string_id = s.id WHERE t.id IS NULL AND $where" );
	}

	/**
	 * Returns the number of strings per component.
	 *
	 * @since 4.1.0
	 *
	 * @return int[] Number of strings keyed by component id.
	 */
	public static function count_by_component(): array {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return array();
		}

		$table = self::strings_table();
		$rows  = $wpdb->get_results( "SELECT component, COUNT(*) AS n FROM $table GROUP BY component" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row->component ] = (int) $row->n;
		}

		return $out;
	}

	/**
	 * Returns the list of known text domains.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public static function get_domains(): array {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return array();
		}

		$table = self::strings_table();
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT domain FROM $table ORDER BY domain" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets a string by id.
	 *
	 * @since 4.1.0
	 *
	 * @param int $id String id.
	 * @return object|null
	 */
	public static function get_string( int $id ) {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return null;
		}

		$table = self::strings_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets the ids of strings from their hashes.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $hashes Hashes.
	 * @return int[] String ids keyed by hash.
	 */
	public static function get_ids_by_hash( array $hashes ): array {
		global $wpdb;

		$out = array();
		if ( empty( $hashes ) || ! self::maybe_install() ) {
			return $out;
		}

		$table = self::strings_table();
		foreach ( array_chunk( array_values( array_unique( $hashes ) ), 500 ) as $chunk ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, hash FROM $table WHERE hash IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$chunk
				)
			);
			foreach ( (array) $rows as $row ) {
				$out[ $row->hash ] = (int) $row->id;
			}
		}

		return $out;
	}

	/**
	 * Finds a string by its domain, context and singular form, inserting it if it doesn't exist yet.
	 * When several strings match (same singular with different plurals), the one without plural is preferred.
	 *
	 * @since 4.1.0
	 *
	 * @param string $domain   Text domain.
	 * @param string $context  Context.
	 * @param string $singular Singular form.
	 * @param string $plural   Plural form, used only when inserting a new string.
	 * @param bool   $insert   Whether to insert the string if it doesn't exist.
	 * @return object|null
	 */
	public static function find_string( string $domain, string $context, string $singular, string $plural = '', bool $insert = true ) {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return null;
		}

		$table = self::strings_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE hash = %s", self::hash( $domain, $context, $singular, $plural ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			return $row;
		}

		$table = self::strings_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE domain = %s AND context = %s AND singular = %s ORDER BY ( plural = %s ) DESC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$domain,
				$context,
				$singular,
				$plural
			)
		);

		foreach ( (array) $rows as $row ) {
			// MySQL comparisons are case and accent insensitive with most collations.
			if ( $row->domain === $domain && $row->context === $context && $row->singular === $singular ) {
				return $row;
			}
		}

		if ( ! $insert ) {
			return null;
		}

		$now = self::now();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (domain, context, singular, plural, hash, component, sources, created, last_seen) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$domain,
				$context,
				$singular,
				$plural,
				self::hash( $domain, $context, $singular, $plural ),
				'',
				'',
				$now,
				$now
			)
		);

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE hash = %s", self::hash( $domain, $context, $singular, $plural ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Queries the strings.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args {
	 *   @type string   $component        Component id, empty for all.
	 *   @type string   $domain           Text domain, empty for all.
	 *   @type string   $search           Searched text.
	 *   @type string   $status           'translated', 'untranslated' or empty for all.
	 *   @type string[] $status_languages Languages slugs used for the status and translations search.
	 *   @type string   $orderby          'string', 'component' or 'domain'.
	 *   @type string   $order            'asc' or 'desc'.
	 *   @type int      $per_page         Number of strings per page.
	 *   @type int      $page             Page number.
	 * }
	 * @return array {
	 *   @type object[] $items Strings.
	 *   @type int      $total Total number of strings matching the query.
	 * }
	 */
	public static function query_strings( array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'component'        => '',
				'domain'           => '',
				'search'           => '',
				'status'           => '',
				'status_languages' => array(),
				'orderby'          => 'string',
				'order'            => 'asc',
				'per_page'         => 20,
				'page'             => 1,
			)
		);

		if ( ! self::maybe_install() ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$strings      = self::strings_table();
		$translations = self::translations_table();
		$where        = array( '1=1' );
		$languages    = array_values( array_filter( array_map( 'strval', (array) $args['status_languages'] ) ) );

		if ( '' !== $args['component'] ) {
			$where[] = $wpdb->prepare( 's.component = %s', $args['component'] === '-' ? '' : $args['component'] );
		}

		if ( '' !== $args['domain'] ) {
			$where[] = $wpdb->prepare( 's.domain = %s', $args['domain'] );
		}

		$lang_in = '';
		if ( $languages ) {
			$lang_in = $wpdb->prepare( implode( ',', array_fill( 0, count( $languages ), '%s' ) ), $languages ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( '' !== $args['search'] ) {
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$or    = $wpdb->prepare( 's.singular LIKE %s OR s.plural LIKE %s OR s.context LIKE %s OR s.domain LIKE %s', $like, $like, $like, $like );
			if ( $lang_in ) {
				$or .= $wpdb->prepare( " OR EXISTS ( SELECT 1 FROM $translations ts WHERE ts.string_id = s.id AND ts.language IN ($lang_in) AND ts.translations LIKE %s )", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$where[] = "( $or )";
		}

		if ( $lang_in && in_array( $args['status'], array( 'translated', 'untranslated' ), true ) ) {
			$count   = "( SELECT COUNT(*) FROM $translations tc WHERE tc.string_id = s.id AND tc.language IN ($lang_in) )";
			$where[] = 'translated' === $args['status'] ? "$count >= " . count( $languages ) : "$count < " . count( $languages );
		}

		$order   = 'desc' === strtolower( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$orderby = array(
			'string'    => "s.singular $order, s.id ASC",
			'component' => "s.component $order, s.singular ASC",
			'domain'    => "s.domain $order, s.singular ASC",
		);
		$orderby = isset( $orderby[ $args['orderby'] ] ) ? $orderby[ $args['orderby'] ] : $orderby['string'];

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$where    = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $strings s WHERE $where" );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM $strings s WHERE $where ORDER BY $orderby LIMIT %d, %d", $offset, $per_page ) );
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	/**
	 * Gets the translations of strings.
	 *
	 * @since 4.1.0
	 *
	 * @param int[]    $string_ids String ids.
	 * @param string[] $languages  Language slugs, empty for all.
	 * @return array Translations (array of plural forms) keyed by string id and language slug.
	 */
	public static function get_translations( array $string_ids, array $languages = array() ): array {
		global $wpdb;

		$string_ids = array_filter( array_map( 'intval', $string_ids ) );
		$out        = array();

		if ( empty( $string_ids ) || ! self::maybe_install() ) {
			return $out;
		}

		$table = self::translations_table();
		$sql   = "SELECT string_id, language, translations FROM $table WHERE string_id IN (" . implode( ',', $string_ids ) . ')';
		if ( $languages ) {
			$sql .= $wpdb->prepare( ' AND language IN (' . implode( ',', array_fill( 0, count( $languages ), '%s' ) ) . ')', array_values( $languages ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		foreach ( (array) $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ (int) $row->string_id ][ $row->language ] = self::decode_translations( $row->translations );
		}

		return $out;
	}

	/**
	 * Decodes the JSON stored translations.
	 *
	 * @since 4.1.0
	 *
	 * @param string $json JSON encoded array of strings.
	 * @return string[]
	 */
	public static function decode_translations( $json ): array {
		$forms = json_decode( (string) $json, true );
		return is_array( $forms ) ? array_values( array_map( 'strval', array_filter( $forms, 'is_scalar' ) ) ) : array();
	}

	/**
	 * Saves (or deletes) the translation of a string in a language.
	 * Doesn't rebuild the cache, see `self::rebuild_cache()`.
	 *
	 * @since 4.1.0
	 *
	 * @param int      $string_id String id.
	 * @param string   $language  Language slug.
	 * @param string[] $forms     Translations (one per plural form). The translation is deleted if all forms are empty.
	 * @return bool
	 */
	public static function set_translation( int $string_id, string $language, array $forms ): bool {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return false;
		}

		$table = self::translations_table();
		$forms = array_values( array_map( 'strval', $forms ) );

		if ( '' === implode( '', $forms ) ) {
			return false !== $wpdb->delete( $table, array( 'string_id' => $string_id, 'language' => $language ) );
		}

		$json     = wp_json_encode( $forms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE string_id = %d AND language = %s", $string_id, $language ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data     = array(
			'translations' => $json,
			'user_id'      => get_current_user_id(),
			'updated'      => self::now(),
		);
		$result   = $existing ?
			$wpdb->update( $table, $data, array( 'id' => (int) $existing ) ) :
			$wpdb->insert( $table, array_merge( $data, array( 'string_id' => $string_id, 'language' => $language ) ) );

		return false !== $result;
	}

	/**
	 * Compiles the translations of a language into its cache option and updates the index.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @return void
	 */
	public static function rebuild_cache( $language ) {
		global $wpdb;

		if ( ! self::maybe_install() ) {
			return;
		}

		$strings      = self::strings_table();
		$translations = self::translations_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.domain, s.context, s.singular, s.plural, t.translations FROM $translations t INNER JOIN $strings s ON s.id = t.string_id WHERE t.language = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$language->slug
			)
		);

		$domains = array();
		foreach ( (array) $rows as $row ) {
			$forms = self::decode_translations( $row->translations );
			if ( '' === implode( '', $forms ) ) {
				continue;
			}

			$key = Strope_String_Overrides::key( $row->context, $row->singular, $row->plural );
			$domains[ $row->domain ][ $key ] = '' === $row->plural ? $forms[0] : $forms;
		}

		$option = self::CACHE_OPTION_PREFIX . $language->slug;
		$index  = get_option( self::INDEX_OPTION );
		$index  = is_array( $index ) ? $index : array();

		if ( empty( $domains ) ) {
			delete_option( $option );
			unset( $index[ $language->slug ] );
		} else {
			$value = array(
				'plural_forms' => Strope_String_Discovery::get_plural_forms( $language ),
				'domains'      => $domains,
			);
			// Never autoloaded. Updated in place so that concurrent readers never see a missing option.
			update_option( $option, $value, false );
			$index[ $language->slug ] = array_map( 'strval', array_keys( $domains ) );
		}

		update_option( self::INDEX_OPTION, $index, true );

		/**
		 * Fires after the string overrides of a language have been compiled.
		 *
		 * @since 4.1.0
		 *
		 * @param Strope_Language $language The language.
		 */
		do_action( 'strope_string_overrides_updated', $language );
	}

	/**
	 * Removes the translations in a language, for example when the language is deleted.
	 *
	 * @since 4.1.0
	 *
	 * @param string $slug Language slug.
	 * @return void
	 */
	public static function delete_language( string $slug ) {
		global $wpdb;

		if ( self::maybe_install() ) {
			$wpdb->delete( self::translations_table(), array( 'language' => $slug ) );
		}

		delete_option( self::CACHE_OPTION_PREFIX . $slug );

		$index = get_option( self::INDEX_OPTION );
		if ( is_array( $index ) && isset( $index[ $slug ] ) ) {
			unset( $index[ $slug ] );
			update_option( self::INDEX_OPTION, $index, true );
		}
	}
}
