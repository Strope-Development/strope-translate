<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sets the taxonomies languages and translations model up.
 *
 * @since 1.8
 *
 * @phpstan-import-type DBInfoWithType from Strope_Translatable_Object_With_Types_Interface
 */
class Strope_Translated_Term extends Strope_Translated_Object implements Strope_Translatable_Object_With_Types_Interface {
	use Strope_Translatable_Object_With_Types_Trait;

	/**
	 * Taxonomy name for the languages.
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	protected $tax_language = 'term_language';

	/**
	 * Object type to use when registering the taxonomy.
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	protected $object_type = 'term';

	/**
	 * Identifier that must be unique for each type of content.
	 * Also used when checking capabilities.
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	protected $type = 'term';

	/**
	 * Identifier for each type of content to used for cache type.
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	protected $cache_type = 'terms';


	/**
	 * Taxonomy name for the translation groups.
	 *
	 * @var string
	 *
	 * @phpstan-var non-empty-string
	 */
	protected $tax_translations = 'term_translations';

	/**
	 * Constructor.
	 *
	 * @since 1.8
	 *
	 * @param Strope_Model $model Instance of `Strope_Model`.
	 */
	public function __construct( Strope_Model &$model ) {
		parent::__construct( $model );

		// Keep hooks in constructor for backward compatibility.
		$this->init();
	}

	/**
	 * Adds hooks.
	 *
	 * @since 3.4
	 *
	 * @return static
	 */
	public function init() {
		add_filter( 'get_terms', array( $this, '_prime_terms_cache' ), 10, 2 );
		add_action( 'clean_term_cache', array( $this, 'clean_term_cache' ) );
		return parent::init();
	}

	/**
	 * Stores the term's language into the database.
	 *
	 * @since 0.6
	 * @since 3.4 Renamed the parameter $term_id into $id.
	 *
	 * @param int                     $id   Term ID.
	 * @param Strope_Language|string|int $lang Language (object, slug, or term ID).
	 * @return bool True when successfully assigned. False otherwise (or if the given language is already assigned to
	 *              the object).
	 */
	public function set_language( $id, $lang ) {
		if ( ! parent::set_language( $id, $lang ) ) {
			return false;
		}

		$id = $this->sanitize_int_id( $id );

		// Add translation group for correct WXR export.
		$translations = $this->get_translations( $id );

		if ( ! empty( $translations ) ) {
			$translations = array_diff( $translations, array( $id ) );
		}

		$this->save_translations( $id, $translations );

		return true;
	}

	/**
	 * Returns the language of a term.
	 *
	 * @since 0.1
	 * @since 3.4 Renamed the parameter $value into $id.
	 * @since 3.4 Deprecated to retrieve the language by term slug + taxonomy anymore.
	 *
	 * @param int $id Term ID.
	 * @return Strope_Language|false A `Strope_Language` object. `false` if no language is associated to that term or if the
	 *                            ID is invalid.
	 */
	public function get_language( $id ) {
		if ( func_num_args() > 1 ) {
			// Backward compatibility.
			_deprecated_argument( __METHOD__ . '()', '3.4' );

			$term = get_term_by( 'slug', $id, func_get_arg( 1 ) ); // @phpstan-ignore-line
			$id   = $term instanceof WP_Term ? $term->term_id : 0;
		}

		return parent::get_language( $id );
	}

	/**
	 * Deletes a translation of a term.
	 *
	 * @since 0.5
	 *
	 * @param int $id Term ID.
	 * @return void
	 */
	public function delete_translation( $id ) {
		global $wpdb;

		$id = $this->sanitize_int_id( $id );

		if ( empty( $id ) ) {
			return;
		}

		$slug = array_search( $id, $this->get_translations( $id ) ); // In case some plugin stores the same value with different key.

		parent::delete_translation( $id );
		wp_delete_object_term_relationships( $id, $this->tax_translations );

		if ( doing_action( 'pre_delete_term' ) ) {
			return;
		}

		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( * ) FROM $wpdb->terms WHERE term_id = %d;", $id ) ) ) {
			return;
		}

		// Always keep a group for terms to allow relationships remap when importing from a WXR file.
		$group        = uniqid( 'strope_' );
		$translations = array( $slug => $id );
		wp_insert_term( $group, $this->tax_translations, array( 'description' => maybe_serialize( $translations ) ) );
		wp_set_object_terms( $id, $group, $this->tax_translations );
	}

	/**
	 * Returns object types (taxonomy names) that need to be translated.
	 * The taxonomies list is cached for better performance.
	 * The method waits for 'after_setup_theme' to apply the cache to allow themes adding the filter in functions.php.
	 *
	 * @since 3.4
	 *
	 * @param bool $filter True if we should return only valid registered object types.
	 * @return string[] Object type names for which Strope Translate manages languages.
	 *
	 * @phpstan-return array<non-empty-string, non-empty-string>
	 */
	public function get_translated_object_types( $filter = true ) {
		$taxonomies = $this->model->cache->get( 'taxonomies' );

		if ( false === $taxonomies ) {
			$taxonomies = array( 'category' => 'category', 'post_tag' => 'post_tag' );

			if ( ! empty( $this->model->options['taxonomies'] ) && is_array( $this->model->options['taxonomies'] ) ) {
				$taxonomies = array_merge( $taxonomies, array_combine( $this->model->options['taxonomies'], $this->model->options['taxonomies'] ) );
			}

			/**
			 * Filters the list of taxonomies available for translation.
			 * The default are taxonomies which have the parameter ‘public’ set to true.
			 * The filter must be added soon in the WordPress loading process:
			 * in a function hooked to ‘plugins_loaded’ or directly in functions.php for themes.
			 *
			 * @since 0.8
			 *
			 * @param string[] $taxonomies  List of taxonomy names (as array keys and values).
			 * @param bool     $is_settings True when displaying the list of custom taxonomies in Strope Translate settings.
			 */
			$taxonomies = (array) apply_filters( 'strope_get_taxonomies', $taxonomies, false );

			if ( did_action( 'after_setup_theme' ) && ! doing_action( 'switch_blog' ) ) {
				$this->model->cache->set( 'taxonomies', $taxonomies );
			}
		}

		/** @var array<non-empty-string, non-empty-string> $taxonomies */
		return $filter ? array_intersect( $taxonomies, get_taxonomies() ) : $taxonomies;
	}

	/**
	 * Caches the language and translations when terms are queried by get_terms().
	 *
	 * @since 1.2
	 *
	 * @param WP_Term[]|int[] $terms      Queried terms.
	 * @param string[]        $taxonomies Queried taxonomies.
	 * @return WP_Term[]|int[] Unmodified $terms.
	 *
	 * @phpstan-param array<WP_Term|positive-int> $terms
	 * @phpstan-param array<non-empty-string> $taxonomies
	 * @phpstan-return array<WP_Term|positive-int>
	 */
	public function _prime_terms_cache( $terms, $taxonomies ) {
		$ids = array();

		if ( is_array( $terms ) && $this->model->is_translated_taxonomy( $taxonomies ) ) {
			foreach ( $terms as $term ) {
				$ids[] = is_object( $term ) ? $term->term_id : (int) $term;
			}
		}

		if ( ! empty( $ids ) ) {
			update_object_term_cache( array_unique( $ids ), 'term' ); // Adds language and translation of terms to cache.
		}
		return $terms;
	}

	/**
	 * When the term cache is cleaned, cleans the object term cache too.
	 *
	 * @since 2.0
	 *
	 * @param int[] $ids An array of term IDs.
	 * @return void
	 *
	 * @phpstan-param array<positive-int> $ids
	 */
	public function clean_term_cache( $ids ) {
		clean_object_term_cache( $this->sanitize_int_ids_list( $ids ), 'term' );
	}

	/**
	 * Tells whether a translation term must be updated.
	 *
	 * @since 2.3
	 *
	 * @param int   $id           Term ID.
	 * @param int[] $translations An associative array of translations with language code as key and translation ID as
	 *                            value. Make sure to sanitize this.
	 * @return bool
	 *
	 * @phpstan-param array<non-empty-string, positive-int> $translations
	 */
	protected function should_update_translation_group( $id, $translations ) {
		// Don't do anything if no translations have been added to the group.
		$old_translations = $this->get_translations( $id );
		if ( count( $translations ) > 1 && ! empty( array_diff_assoc( $translations, $old_translations ) ) ) {
			return true;
		}

		// But we need a translation group for terms to allow relationships remap when importing from a WXR file
		$term = $this->get_object_term( $id, $this->tax_translations );
		return empty( $term ) || ! empty( array_diff_assoc( $translations, $old_translations ) );
	}

	/**
	 * Assigns a language to terms in mass.
	 *
	 * @since 1.2
	 * @since 3.4 Moved from Strope_Admin_Model class.
	 *
	 * @param int[]        $ids  Array of post ids or term ids.
	 * @param Strope_Language $lang Language to assign to the posts or terms.
	 * @return void
	 */
	public function set_language_in_mass( $ids, $lang ) {
		parent::set_language_in_mass( $ids, $lang );

		$translations = array();

		foreach ( $ids as $id ) {
			$translations[] = array( $lang->slug => $id );
		}

		if ( ! empty( $translations ) ) {
			$this->set_translation_in_mass( $translations );
		}
	}

	/**
	 * Returns database-related information that can be used in some of this class methods.
	 * These are specific to the table containing the objects.
	 *
	 * @see Strope_Translatable_Object::join_clause()
	 * @see Strope_Translatable_Object::get_objects_with_no_lang_sql()
	 *
	 * @since 3.4.3
	 *
	 * @return string[] {
	 *     @type string $table         Name of the table.
	 *     @type string $id_column     Name of the column containing the object's ID.
	 *     @type string $type_column   Name of the column containing the object's type.
	 *     @type string $default_alias Default alias corresponding to the object's table.
	 * }
	 * @phpstan-return DBInfoWithType
	 */
	protected function get_db_infos() {
		return array(
			'table'         => $GLOBALS['wpdb']->term_taxonomy,
			'id_column'     => 'term_id',
			'type_column'   => 'taxonomy',
			'default_alias' => 't',
		);
	}

	/**
	 * Wraps `wp_insert_term()` with language features.
	 * Allows to use the same name or slug as a term of the same taxonomy in another language.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $term     The term name.
	 * @param string          $taxonomy The taxonomy.
	 * @param Strope_Language $language The term language.
	 * @param array           $args     {
	 *     Optional. See `wp_insert_term()` for accepted arguments.
	 *
	 *     @type int[] $translations The translations to link to the term, with language slugs as keys and term ids as values.
	 * }
	 * @return array|WP_Error An array with the keys `term_id` and `term_taxonomy_id`, a `WP_Error` on failure.
	 */
	public function insert( string $term, string $taxonomy, Strope_Language $language, array $args = array() ) {
		$translations = isset( $args['translations'] ) && is_array( $args['translations'] ) ? $args['translations'] : array();
		unset( $args['translations'] );

		$result = $this->with_inserted_term_context(
			$language,
			isset( $args['parent'] ) ? (int) $args['parent'] : 0,
			function () use ( $term, $taxonomy, $args ) {
				return wp_insert_term( $term, $taxonomy, $args );
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->set_language( (int) $result['term_id'], $language );

		if ( ! empty( $translations ) ) {
			$this->save_translations( (int) $result['term_id'], array_merge( $translations, array( $language->slug => (int) $result['term_id'] ) ) );
		}

		return $result;
	}

	/**
	 * Wraps `wp_update_term()` with language features.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $term_id The term id.
	 * @param array $args    {
	 *     Optional. See `wp_update_term()` for accepted arguments.
	 *
	 *     @type Strope_Language|string $lang         The new term language, object or slug.
	 *     @type int[]                  $translations The translations to link to the term, with language slugs as keys and term ids as values.
	 * }
	 * @return array|WP_Error An array with the keys `term_id` and `term_taxonomy_id`, a `WP_Error` on failure.
	 */
	public function update( int $term_id, array $args = array() ) {
		$term = get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'strope_invalid_term', __( 'Invalid term ID.', 'strope-translate' ) );
		}

		$old_language = $this->get_language( $term_id );
		$language     = $old_language;
		if ( ! empty( $args['lang'] ) ) {
			$language = $this->model->get_language( $args['lang'] );
			if ( ! $language instanceof Strope_Language ) {
				return new WP_Error( 'strope_invalid_language', __( 'Please provide a valid language.', 'strope-translate' ) );
			}
			// Set before the update as the language is needed to make the slug unique per language.
			$this->set_language( $term_id, $language );
		}

		$translations = isset( $args['translations'] ) && is_array( $args['translations'] ) ? $args['translations'] : array();
		unset( $args['lang'], $args['translations'] );

		$update = function () use ( $term, $args ) {
			return wp_update_term( $term->term_id, $term->taxonomy, $args );
		};

		$result = $language
			? $this->with_inserted_term_context( $language, isset( $args['parent'] ) ? (int) $args['parent'] : (int) $term->parent, $update )
			: $update();

		if ( is_wp_error( $result ) ) {
			// Restore the previous language, the term hasn't been updated.
			if ( $language !== $old_language ) {
				if ( $old_language ) {
					$this->set_language( $term_id, $old_language );
				} else {
					wp_delete_object_term_relationships( $term_id, $this->get_tax_language() );
				}
			}
			return $result;
		}

		if ( ! empty( $translations ) && $language ) {
			$this->save_translations( $term_id, array_merge( $translations, array( $language->slug => $term_id ) ) );
		}

		return $result;
	}

	/**
	 * Runs a term insertion or update with the given language and parent used to make the slug unique per language.
	 * The filters are always removed afterwards, whatever happens.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language The term language.
	 * @param int             $parent   The parent term id.
	 * @param callable        $callback The insertion or update.
	 * @return mixed The callback result.
	 */
	private function with_inserted_term_context( Strope_Language $language, int $parent, callable $callback ) {
		$language_callback = function () use ( $language ) {
			return $language;
		};
		$parent_callback   = function () use ( $parent ) {
			return $parent;
		};

		add_filter( 'strope_inserted_term_language', $language_callback, PHP_INT_MAX );
		add_filter( 'strope_inserted_term_parent', $parent_callback, PHP_INT_MAX );

		try {
			return $callback();
		} finally {
			remove_filter( 'strope_inserted_term_language', $language_callback, PHP_INT_MAX );
			remove_filter( 'strope_inserted_term_parent', $parent_callback, PHP_INT_MAX );
		}
	}
}
