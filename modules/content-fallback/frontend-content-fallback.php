<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Displays the content which is not translated in the current language in the default language,
 * while keeping the visitor in his language.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Content_Fallback {
	/**
	 * The main Strope Translate object.
	 *
	 * @var Strope_Frontend
	 */
	protected $strope;

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * @var Strope_Links_Model
	 */
	protected $links_model;

	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Current language.
	 *
	 * @var Strope_Language|null
	 */
	public $curlang;

	/**
	 * Post types using the fallback.
	 *
	 * @var string[]
	 */
	protected $post_types = array();

	/**
	 * Taxonomies using the fallback.
	 *
	 * @var string[]
	 */
	protected $taxonomies = array();

	/**
	 * Whether to display a notice above the fallback content.
	 *
	 * @var bool
	 */
	protected $notice = false;

	/**
	 * Language used to generate links, overrides the current language when not null.
	 *
	 * @var Strope_Language|null
	 */
	protected $link_language;

	/**
	 * Urls of fallback content found when evaluating the translations of the current page, as keys.
	 *
	 * @var true[]
	 */
	protected $fallback_urls = array();

	/**
	 * The queried object when it is displayed as a fallback, `false` if it is not, `null` when not evaluated yet.
	 *
	 * @var WP_Post|WP_Term|false|null
	 */
	protected $queried_fallback;

	/**
	 * Whether the queried archive displays only content in the default language, `null` when not evaluated yet.
	 *
	 * @var bool|null
	 */
	protected $queried_fallback_archive;

	/**
	 * Language codes of the archive translations displaying only fallback content, as keys.
	 * Filled when evaluating the translations urls of the current page.
	 *
	 * @var true[]
	 */
	protected $fallback_archive_languages = array();

	/**
	 * Internal cache.
	 *
	 * @var array
	 */
	protected $cache = array();

	/**
	 * Whether we are evaluating the canonical url of a fallback post.
	 *
	 * @var bool
	 */
	protected $doing_canonical = false;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Frontend $strope_translate The Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->strope      = &$strope_translate;
		$this->model       = &$strope_translate->model;
		$this->links_model = &$strope_translate->links_model;
		$this->options     = &$strope_translate->options;
		$this->curlang     = &$strope_translate->curlang;

		$settings         = Strope_Content_Fallback::get_settings( $this->options );
		$this->post_types = $settings['post_types'];
		$this->taxonomies = $settings['taxonomies'];
		$this->notice     = $settings['notice'];

		// Posts and terms queries.
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ), PHP_INT_MAX );
		add_action( 'parse_tax_query', array( $this, 'parse_tax_query' ), 20 );
		add_filter( 'get_terms_args', array( $this, 'get_terms_args' ), 20, 2 );
		add_filter( 'terms_clauses', array( $this, 'terms_clauses' ), 9, 3 ); // Before Strope_CRUD_Terms.
		add_filter( 'option_sticky_posts', array( $this, 'option_sticky_posts' ), 20 );
		add_filter( 'get_previous_post_where', array( $this, 'adjacent_post_where' ), 20, 5 );
		add_filter( 'get_next_post_where', array( $this, 'adjacent_post_where' ), 20, 5 );
		add_filter( 'getarchives_where', array( $this, 'getarchives_where' ), 20, 2 );
		add_filter( 'strope_hide_archive_translation_url', array( $this, 'hide_archive_translation_url' ), 20, 3 );

		// The sitemaps of a language list only the content in this language.
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'disable_in_query_args' ), 20 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'disable_in_query_args' ), 20 );

		// Fallback urls require the language to be set from the url.
		if ( $this->options['force_lang'] ) {
			add_action( 'parse_query', array( $this, 'parse_main_query' ), 1 ); // After Strope_Share_Post_Slug.
			add_filter( 'posts_where', array( $this, 'posts_where' ), 20, 2 );
			add_action( 'template_redirect', array( $this, 'template_redirect' ), 3 ); // Before Strope_Canonical.

			add_filter( 'post_link', array( $this, 'post_link' ), 25, 2 ); // After Strope_Filters_Links.
			add_filter( 'post_type_link', array( $this, 'post_link' ), 25, 2 );
			add_filter( 'page_link', array( $this, 'page_link' ), 25, 2 );
			add_filter( 'term_link', array( $this, 'term_link' ), 25, 3 );

			add_filter( 'strope_pre_translation_url', array( $this, 'pre_translation_url' ), 20, 3 ); // After Strope_Frontend_Static_Pages.
			add_filter( 'strope_translation_url', array( $this, 'translation_url' ), 999, 2 ); // After the other filters, to get the final url.
			add_filter( 'strope_rel_hreflang_attributes', array( $this, 'rel_hreflang_attributes' ), 20 );
			add_filter( 'get_canonical_url', array( $this, 'get_canonical_url' ), 20, 2 );
			add_action( 'wp_head', array( $this, 'archive_canonical' ), 1 );
		}

		// Content marked with its real language.
		add_filter( 'the_content', array( $this, 'the_content' ), 100 );
		add_filter( 'the_excerpt', array( $this, 'the_excerpt' ), 100 );
		add_filter( 'render_block_core/post-title', array( $this, 'render_block' ), 10, 3 );
		add_filter( 'render_block_core/post-excerpt', array( $this, 'render_block' ), 10, 3 );
	}

	/*
	 * Context and decisions.
	 */

	/**
	 * Tells whether we are in a context where the fallback may apply.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function is_frontend_context() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		// Don't add fallback content to the sitemaps.
		if ( isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query && get_query_var( 'sitemap' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Tells whether the fallback applies to the current request: the current language is not the default language.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function is_active() {
		if ( ! $this->curlang instanceof Strope_Language ) {
			return false;
		}

		$default = $this->model->get_default_language();

		if ( ! $default || $default->slug === $this->curlang->slug ) {
			return false;
		}

		return $this->is_frontend_context();
	}

	/**
	 * Returns the language to use for links.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Language|null
	 */
	protected function get_link_language() {
		return $this->link_language instanceof Strope_Language ? $this->link_language : $this->curlang;
	}

	/**
	 * Calls a function generating links in a given language.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language The language.
	 * @param callable        $callback The function to call.
	 * @return mixed The value returned by the callback.
	 */
	protected function with_link_language( $language, $callback ) {
		$previous            = $this->link_language;
		$this->link_language = $language;

		try {
			$return = call_user_func( $callback );
		} finally {
			$this->link_language = $previous;
		}

		return $return;
	}

	/**
	 * Returns the post statuses of a translation visible to the current user.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_visible_statuses() {
		if ( ! isset( $this->cache['statuses'] ) ) {
			$this->cache['statuses'] = current_user_can( 'read_private_posts' ) ? array( 'publish', 'private' ) : array( 'publish' );
		}
		return $this->cache['statuses'];
	}

	/**
	 * Tells whether a post is visible to the current user.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_visible_post( $post_id ) {
		$post = get_post( $post_id );
		return $post instanceof WP_Post && in_array( $post->post_status, $this->get_visible_statuses(), true );
	}

	/**
	 * Tells whether a post is the static front page or the page for posts in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	protected function is_static_page( $post ) {
		if ( 'page' !== $post->post_type ) {
			return false;
		}

		$default = $this->model->get_default_language();

		return $default && in_array( (int) $post->ID, array( (int) $default->page_on_front, (int) $default->page_for_posts ), true );
	}

	/**
	 * Tells whether a post is displayed as a fallback in a language,
	 * i.e. it is in the default language and not translated in this language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post|null         $post     Post object.
	 * @param Strope_Language|null $language The language in which the post is displayed.
	 * @return bool
	 */
	public function is_fallback_post( $post, $language ) {
		if ( ! $post instanceof WP_Post || ! $language instanceof Strope_Language ) {
			return false;
		}

		$key = "post:{$post->ID}:{$language->slug}";

		if ( ! isset( $this->cache[ $key ] ) ) {
			$this->cache[ $key ] = false;
			$default             = $this->model->get_default_language();

			if (
				$default
				&& $default->slug !== $language->slug
				&& in_array( $post->post_type, $this->post_types, true )
				&& $this->model->is_translated_post_type( $post->post_type )
				&& ! $this->is_static_page( $post )
			) {
				$post_lang = $this->model->post->get_language( $post->ID );

				if ( $post_lang && $post_lang->slug === $default->slug ) {
					$tr_id               = $this->model->post->get_translation( $post->ID, $language );
					$this->cache[ $key ] = ! $tr_id || ! $this->is_visible_post( $tr_id );
				}
			}
		}

		return $this->cache[ $key ];
	}

	/**
	 * Tells whether a term is displayed as a fallback in a language,
	 * i.e. it is in the default language and not translated in this language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Term|null         $term     Term object.
	 * @param Strope_Language|null $language The language in which the term is displayed.
	 * @return bool
	 */
	public function is_fallback_term( $term, $language ) {
		if ( ! $term instanceof WP_Term || ! $language instanceof Strope_Language ) {
			return false;
		}

		$key = "term:{$term->term_id}:{$language->slug}";

		if ( ! isset( $this->cache[ $key ] ) ) {
			$this->cache[ $key ] = false;
			$default             = $this->model->get_default_language();

			if (
				$default
				&& $default->slug !== $language->slug
				&& in_array( $term->taxonomy, $this->taxonomies, true )
				&& $this->model->is_translated_taxonomy( $term->taxonomy )
			) {
				$term_lang = $this->model->term->get_language( $term->term_id );

				if ( $term_lang && $term_lang->slug === $default->slug ) {
					$this->cache[ $key ] = ! $this->model->term->get_translation( $term->term_id, $language );
				}
			}
		}

		return $this->cache[ $key ];
	}

	/**
	 * Returns the queried object if it is displayed as a fallback.
	 *
	 * @since 4.1.0
	 *
	 * @return WP_Post|WP_Term|false
	 */
	public function get_queried_fallback() {
		if ( null !== $this->queried_fallback ) {
			return $this->queried_fallback;
		}

		if ( ! did_action( 'wp' ) ) {
			return false; // Too early.
		}

		$this->queried_fallback = false;

		if ( ! $this->options['force_lang'] || ! $this->is_active() ) {
			return false;
		}

		$object = get_queried_object();

		if ( is_singular() && ! is_preview() && $object instanceof WP_Post && $this->is_fallback_post( $object, $this->curlang ) ) {
			$this->queried_fallback = $object;
		} elseif ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term && $this->is_fallback_term( $object, $this->curlang ) ) {
			$this->queried_fallback = $object;
		}

		return $this->queried_fallback;
	}

	/**
	 * Returns the arguments used to build the SQL for posts.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	protected function get_sql_args() {
		$default = $this->model->get_default_language();

		return array(
			'lang_tt_id'    => (int) $this->curlang->get_tax_prop( 'language', 'term_taxonomy_id' ),
			'default_tt_id' => (int) $default->get_tax_prop( 'language', 'term_taxonomy_id' ),
			'statuses'      => $this->get_visible_statuses(),
		);
	}

	/**
	 * Replaces the language WHERE fragment added by Strope Translate in a SQL clause.
	 *
	 * @since 4.1.0
	 *
	 * @param string $where       The WHERE clause.
	 * @param string $fragment    The fragment to replace.
	 * @param string $replacement The replacement.
	 * @return string
	 */
	protected function replace_where_fragment( $where, $fragment, $replacement ) {
		if ( '' === $fragment || false === strpos( $where, $fragment ) ) {
			return $where;
		}

		return (string) preg_replace_callback(
			'/' . preg_quote( $fragment, '/' ) . '(?![0-9])/',
			function () use ( $replacement ) {
				return $replacement;
			},
			$where,
			1
		);
	}

	/*
	 * Posts queries.
	 */

	/**
	 * Marks the query as ready for the fallback: its taxonomy query will be parsed for the last time.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query WP_Query object.
	 * @return void
	 */
	public function pre_get_posts( $query ) {
		$query->strope_fallback_pending = true;
	}

	/**
	 * Adds the posts in the default language to a query filtered by the current language,
	 * excluding the ones translated in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query WP_Query object.
	 * @return void
	 */
	public function parse_tax_query( $query ) {
		if ( empty( $query->strope_fallback_pending ) ) {
			return;
		}

		$query->strope_fallback_pending = false;

		if ( empty( $this->post_types ) || ! $this->is_active() || $query->is_singular ) {
			return;
		}

		if ( isset( $query->query_vars['strope_fallback'] ) && ! $query->query_vars['strope_fallback'] ) {
			return;
		}

		$tax_query = $query->tax_query;

		if ( ! $tax_query instanceof WP_Tax_Query || $tax_query instanceof Strope_Content_Fallback_Tax_Query || empty( $tax_query->queries ) ) {
			return;
		}

		$queries = $tax_query->queries;

		if ( isset( $queries['relation'] ) && 'OR' === strtoupper( $queries['relation'] ) ) {
			return;
		}

		$key = $this->find_language_clause( $queries );

		if ( null === $key ) {
			return;
		}

		$post_types = $this->get_query_post_types( $query );

		if ( false === $post_types ) {
			return;
		}

		$clause = $this->add_default_language_to_clause( $queries[ $key ] );

		if ( false === $clause ) {
			return;
		}

		$queries[ $key ] = $clause;
		$queries         = $this->expand_term_clauses( $queries );

		$args               = $this->get_sql_args();
		$args['post_types'] = $post_types;

		/**
		 * Filters the arguments used to add the default language content to a posts query.
		 * Returning an empty array disables the fallback for this query.
		 *
		 * @since 4.1.0
		 *
		 * @param array    $args  {
		 *   @type int      $lang_tt_id    Term taxonomy id of the current language.
		 *   @type int      $default_tt_id Term taxonomy id of the default language.
		 *   @type string[] $statuses      Post statuses of a visible translation.
		 *   @type string[] $post_types    Post types allowing the fallback, empty if all the queried post types allow it.
		 * }
		 * @param WP_Query $query The query.
		 */
		$args = apply_filters( 'strope_content_fallback_query_args', $args, $query );

		if ( empty( $args ) || ! is_array( $args ) ) {
			return;
		}

		$new_tax_query                = new Strope_Content_Fallback_Tax_Query( $queries, $args );
		$new_tax_query->queried_terms = $tax_query->queried_terms; // Keep the queried terms in the current language.
		$query->tax_query             = $new_tax_query;
	}

	/**
	 * Returns the key of the top level clause filtering the query by the current language only.
	 *
	 * @since 4.1.0
	 *
	 * @param array $queries Sanitized taxonomy queries.
	 * @return int|string|null
	 */
	protected function find_language_clause( $queries ) {
		$found = null;

		foreach ( $queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			if ( isset( $clause['taxonomy'] ) && 'language' === $clause['taxonomy'] ) {
				if ( null !== $found ) {
					return null; // Several language clauses, don't try to understand.
				}
				$found = $key;
			}
		}

		if ( null === $found ) {
			return null;
		}

		$clause = $queries[ $found ];

		if ( empty( $clause['operator'] ) || 'IN' !== strtoupper( $clause['operator'] ) || empty( $clause['terms'] ) ) {
			return null;
		}

		$terms = (array) $clause['terms'];

		if ( 1 !== count( $terms ) ) {
			return null;
		}

		$language = $this->get_language_from_clause( reset( $terms ), isset( $clause['field'] ) ? $clause['field'] : 'term_id' );

		return $language && $language->slug === $this->curlang->slug ? $found : null;
	}

	/**
	 * Returns the language queried in a taxonomy clause.
	 *
	 * @since 4.1.0
	 *
	 * @param int|string $term  The queried term.
	 * @param string     $field The field used to query the term.
	 * @return Strope_Language|false
	 */
	protected function get_language_from_clause( $term, $field ) {
		switch ( $field ) {
			case 'term_taxonomy_id':
				return $this->model->get_language( 'tt:' . (int) $term );
			case 'term_id':
				return $this->model->get_language( (int) $term );
			case 'slug':
				return is_string( $term ) ? $this->model->get_language( $term ) : false;
		}
		return false;
	}

	/**
	 * Adds the default language to the language clause.
	 *
	 * @since 4.1.0
	 *
	 * @param array $clause Language clause.
	 * @return array|false
	 */
	protected function add_default_language_to_clause( $clause ) {
		$default = $this->model->get_default_language();

		switch ( $clause['field'] ) {
			case 'term_taxonomy_id':
				$clause['terms'][] = (int) $default->get_tax_prop( 'language', 'term_taxonomy_id' );
				break;
			case 'term_id':
				$clause['terms'][] = (int) $default->get_tax_prop( 'language', 'term_id' );
				break;
			case 'slug':
				$clause['terms'][] = $default->slug;
				break;
			default:
				return false;
		}

		$clause['terms'] = array_values( (array) $clause['terms'] );
		return $clause;
	}

	/**
	 * Returns the post types to which the fallback must be restricted in a query.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query The query.
	 * @return string[]|false An empty array if all queried post types use the fallback,
	 *                        the list of post types using the fallback otherwise,
	 *                        false if none of the queried post types use the fallback.
	 */
	protected function get_query_post_types( $query ) {
		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) || 'any' === $post_types || ( is_array( $post_types ) && in_array( 'any', $post_types, true ) ) ) {
			// The post types are decided later by WordPress.
			return $this->post_types;
		}

		$post_types = array_filter( (array) $post_types, 'is_string' );
		$enabled    = array_intersect( $post_types, $this->post_types );

		if ( empty( $enabled ) ) {
			return false;
		}

		return count( $enabled ) === count( $post_types ) ? array() : array_values( $enabled );
	}

	/**
	 * Adds the translations in the default language of the queried terms in the current language,
	 * so that e.g. a category archive also lists the posts in the default language of the translated category.
	 *
	 * @since 4.1.0
	 *
	 * @param array $queries Sanitized taxonomy queries.
	 * @return array
	 */
	protected function expand_term_clauses( $queries ) {
		foreach ( $queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			if ( isset( $clause['taxonomy'] ) ) {
				if (
					'language' !== $clause['taxonomy']
					&& ! empty( $clause['terms'] )
					&& isset( $clause['operator'] ) && in_array( strtoupper( $clause['operator'] ), array( 'IN', 'NOT IN' ), true )
					&& $this->model->is_translated_taxonomy( $clause['taxonomy'] )
				) {
					$queries[ $key ] = $this->expand_term_clause( $clause );
				}
			} else {
				$queries[ $key ] = $this->expand_term_clauses( $clause ); // Nested queries.
			}
		}

		return $queries;
	}

	/**
	 * Adds the translations in the default language of the queried terms in the current language to a taxonomy clause.
	 *
	 * @since 4.1.0
	 *
	 * @param array $clause A taxonomy clause.
	 * @return array
	 */
	protected function expand_term_clause( $clause ) {
		global $wpdb;

		$taxonomy = $clause['taxonomy'];
		$terms    = (array) $clause['terms'];
		$default  = $this->model->get_default_language();

		switch ( $clause['field'] ) {
			case 'term_id':
				$ids = wp_parse_id_list( $terms );
				break;

			case 'term_taxonomy_id':
				$tt_ids = implode( ',', wp_parse_id_list( $terms ) );
				// PHPCS:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ( {$tt_ids} )" );
				break;

			case 'slug':
			case 'name':
				$ids = get_terms(
					array(
						'taxonomy'               => $taxonomy,
						$clause['field']         => $terms,
						'hide_empty'             => false,
						'fields'                 => 'ids',
						'lang'                   => $this->curlang->slug,
						'update_term_meta_cache' => false,
					)
				);

				if ( is_array( $ids ) ) {
					// Keep only the terms which can be queried in the current language.
					$ids = array_filter(
						$ids,
						function ( $id ) use ( $taxonomy ) {
							$lang = $this->model->term->get_language( (int) $id );
							return $lang && ( $lang->slug === $this->curlang->slug || $this->is_fallback_term( get_term( (int) $id, $taxonomy ), $this->curlang ) );
						}
					);
				}
				break;

			default:
				return $clause;
		}

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return $clause;
		}

		$ids              = array_map( 'intval', $ids );
		$include_children = ! empty( $clause['include_children'] ) && is_taxonomy_hierarchical( $taxonomy );

		if ( $include_children ) {
			$ids = $this->add_term_children( $ids, $taxonomy );
		}

		$added = array();
		foreach ( $ids as $id ) {
			$lang = $this->model->term->get_language( $id );
			if ( $lang && $lang->slug === $this->curlang->slug ) {
				$tr_id = $this->model->term->get_translation( $id, $default );
				if ( $tr_id ) {
					$added[] = (int) $tr_id;
				}
			}
		}

		if ( empty( $added ) ) {
			return $clause;
		}

		if ( $include_children ) {
			$added = $this->add_term_children( $added, $taxonomy );
		}

		$tt_ids = array();
		foreach ( array_unique( array_merge( $ids, $added ) ) as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof WP_Term ) {
				$tt_ids[] = (int) $term->term_taxonomy_id;
			}
		}

		if ( empty( $tt_ids ) ) {
			return $clause;
		}

		$clause['terms']            = $tt_ids;
		$clause['field']            = 'term_taxonomy_id';
		$clause['include_children'] = false;

		return $clause;
	}

	/**
	 * Adds the children terms to a list of terms.
	 *
	 * @since 4.1.0
	 *
	 * @param int[]  $ids      Term ids.
	 * @param string $taxonomy Taxonomy name.
	 * @return int[]
	 */
	protected function add_term_children( $ids, $taxonomy ) {
		foreach ( $ids as $id ) {
			$children = get_term_children( $id, $taxonomy );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Adds the sticky posts in the default language which are not translated in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $posts Sticky posts ids filtered by Strope Translate.
	 * @return int[]
	 */
	public function option_sticky_posts( $posts ) {
		if ( ! is_array( $posts ) || ! in_array( 'post', $this->post_types, true ) || ! $this->is_active() ) {
			return $posts;
		}

		$sticky = wp_cache_get( 'sticky_posts', 'options' ); // Set by Strope_Frontend_Filters.
		$tt_id  = $this->model->get_default_language()->get_tax_prop( 'language', 'term_taxonomy_id' );

		if ( ! is_array( $sticky ) || empty( $sticky[ $tt_id ] ) || ! is_array( $sticky[ $tt_id ] ) ) {
			return $posts;
		}

		foreach ( $sticky[ $tt_id ] as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && $this->is_visible_post( $post->ID ) && $this->is_fallback_post( $post, $this->curlang ) ) {
				$posts[] = (int) $post_id;
			}
		}

		return array_values( array_unique( array_map( 'intval', $posts ) ) );
	}

	/**
	 * Adds the fallback content to the previous and next post queries.
	 *
	 * @since 4.1.0
	 *
	 * @param string       $where          The WHERE clause in the SQL.
	 * @param bool         $in_same_term   Whether post should be in a same taxonomy term.
	 * @param int[]|string $excluded_terms Array of excluded term IDs.
	 * @param string       $taxonomy       Taxonomy.
	 * @param WP_Post      $post           WP_Post object.
	 * @return string
	 */
	public function adjacent_post_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->post_types, true ) || ! $this->is_active() ) {
			return $where;
		}

		return $this->replace_where_fragment(
			$where,
			$this->model->post->where_clause( $this->curlang ),
			Strope_Content_Fallback::get_posts_language_where( 'p.ID', $this->get_sql_args() )
		);
	}

	/**
	 * Adds the fallback content to the archives widget.
	 *
	 * @since 4.1.0
	 *
	 * @param string $where The WHERE clause.
	 * @param array  $args  wp_get_archives() arguments.
	 * @return string
	 */
	public function getarchives_where( $where, $args ) {
		global $wpdb;

		if ( empty( $args['post_type'] ) || ! in_array( $args['post_type'], $this->post_types, true ) || ! $this->is_active() ) {
			return $where;
		}

		return $this->replace_where_fragment(
			$where,
			$this->model->post->where_clause( $this->curlang ),
			Strope_Content_Fallback::get_posts_language_where( "{$wpdb->posts}.ID", $this->get_sql_args() )
		);
	}

	/**
	 * Avoids hiding the link to an archive in another language which displays fallback content.
	 *
	 * @since 4.1.0
	 *
	 * @param bool   $hide Whether to hide the translation url.
	 * @param string $slug Language code of the translation.
	 * @param array  $args Arguments used to evaluate the number of posts in the archive.
	 * @return bool
	 */
	public function hide_archive_translation_url( $hide, $slug, $args ) {
		$default = $this->model->get_default_language();

		if ( ! $hide || ! $default || $default->slug === $slug || ! $this->is_frontend_context() ) {
			return $hide;
		}

		$post_type = empty( $args['post_type'] ) ? 'post' : $args['post_type'];

		if ( ! is_string( $post_type ) || ! in_array( $post_type, $this->post_types, true ) ) {
			return $hide;
		}

		$hide = ! $this->model->count_posts( $default, $args );

		if ( ! $hide ) {
			// This archive displays only fallback content: it is not a real translation, see `translation_url()`.
			$this->fallback_archive_languages[ $slug ] = true;
		}

		return $hide;
	}

	/**
	 * Disables the fallback in query arguments.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public function disable_in_query_args( $args ) {
		$args['strope_fallback'] = false;
		return $args;
	}

	/*
	 * Terms queries.
	 */

	/**
	 * Tells whether a terms query must include the terms in the default language not translated in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $taxonomies Queried taxonomies.
	 * @param array    $args       Query arguments.
	 * @return bool
	 */
	protected function is_terms_fallback( $taxonomies, $args ) {
		if ( empty( $this->taxonomies ) || empty( $taxonomies ) || ! empty( $args['object_ids'] ) ) {
			return false;
		}

		if ( isset( $args['strope_fallback'] ) && ! $args['strope_fallback'] ) {
			return false;
		}

		$taxonomies = (array) $taxonomies;

		if ( ! $this->model->is_translated_taxonomy( $taxonomies ) || ! array_intersect( $taxonomies, $this->taxonomies ) ) {
			return false;
		}

		if ( ! $this->is_active() ) {
			return false;
		}

		if ( isset( $args['lang'] ) ) {
			if ( ! is_string( $args['lang'] ) && ! $args['lang'] instanceof Strope_Language ) {
				return false;
			}

			$language = '' === $args['lang'] ? false : $this->model->get_language( $args['lang'] );

			return $language && $language->slug === $this->curlang->slug;
		}

		// The query is filtered by the current language.
		return true;
	}

	/**
	 * Uses a specific cache domain for terms queries including the fallback terms.
	 *
	 * @since 4.1.0
	 *
	 * @param array    $args       Query arguments.
	 * @param string[] $taxonomies Queried taxonomies.
	 * @return array
	 */
	public function get_terms_args( $args, $taxonomies ) {
		if ( $this->is_terms_fallback( $taxonomies, $args ) ) {
			$args['cache_domain'] = ( empty( $args['cache_domain'] ) ? 'core' : $args['cache_domain'] ) . '_strope_fallback';
		}
		return $args;
	}

	/**
	 * Filters the terms queries by the current language including the terms in the default language
	 * not translated in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $clauses    SQL clauses.
	 * @param string[] $taxonomies Queried taxonomies.
	 * @param array    $args       Query arguments.
	 * @return string[]
	 */
	public function terms_clauses( $clauses, $taxonomies, $args ) {
		if ( false !== strpos( $clauses['join'], 'strope_tr' ) || ! $this->is_terms_fallback( $taxonomies, $args ) ) {
			return $clauses;
		}

		$default       = $this->model->get_default_language();
		$lang_tt_id    = (int) $this->curlang->get_tax_prop( 'term_language', 'term_taxonomy_id' );
		$default_tt_id = (int) $default->get_tax_prop( 'term_language', 'term_taxonomy_id' );
		$subquery      = Strope_Content_Fallback::get_translated_terms_subquery( $lang_tt_id, $default_tt_id );

		$taxonomy_where = '';
		if ( array_diff( (array) $taxonomies, $this->taxonomies ) ) {
			$taxonomy_where = " AND tt.taxonomy IN ( '" . implode( "','", esc_sql( $this->taxonomies ) ) . "' )";
		}

		// The join prevents Strope_CRUD_Terms to add its own language filter.
		$clauses['join']  .= $this->model->term->join_clause();
		$clauses['where'] .= " AND ( strope_tr.term_taxonomy_id = {$lang_tt_id} OR ( strope_tr.term_taxonomy_id = {$default_tt_id}{$taxonomy_where} AND t.term_id NOT IN ( {$subquery} ) ) )";

		return $clauses;
	}

	/*
	 * Single fallback requests.
	 */

	/**
	 * Finds the post in the default language requested with an url in the current language
	 * when the shared slugs restrict the query to the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Query $query WP_Query object.
	 * @return void
	 */
	public function parse_main_query( $query ) {
		if ( ! $query->is_main_query() || empty( $this->post_types ) || empty( $this->strope->share_post_slug ) || ! $this->is_active() ) {
			return;
		}

		$qv = $query->query_vars;

		if ( ! empty( $qv['attachment'] ) || ! empty( $qv['attachment_id'] ) || ! empty( $qv['preview'] ) ) {
			return;
		}

		$post_type = empty( $qv['post_type'] ) ? '' : $qv['post_type'];

		if ( ! is_string( $post_type ) ) {
			return;
		}

		if ( ! empty( $qv['pagename'] ) && is_string( $qv['pagename'] ) ) {
			$path       = $qv['pagename'];
			$post_types = array( $post_type ? $post_type : 'page' );
		} elseif ( ! empty( $qv['name'] ) && is_string( $qv['name'] ) ) {
			$path       = $qv['name'];
			$post_types = array( $post_type ? $post_type : 'post' );
		} else {
			return;
		}

		$post_types = array_intersect( $post_types, $this->post_types );

		if ( empty( $post_types ) ) {
			return;
		}

		$post = $this->find_post_by_path( $path, $post_types, $this->model->get_default_language() );

		// Nothing to do if the post exists in the current language (with a shared slug).
		if ( ! $post || ! $this->is_fallback_post( $post, $this->curlang ) || $this->find_post_by_path( $path, $post_types, $this->curlang ) ) {
			return;
		}

		$query->strope_fallback_single = (int) $post->ID;

		if ( ! empty( $qv['pagename'] ) ) {
			$query->queried_object    = $post;
			$query->queried_object_id = (int) $post->ID;
		}
	}

	/**
	 * Finds a post by its path in a given language.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $path       The post path.
	 * @param string[]        $post_types The post types.
	 * @param Strope_Language $language   The language.
	 * @return WP_Post|null
	 */
	protected function find_post_by_path( $path, $post_types, $language ) {
		global $wpdb;

		$path  = str_replace( array( '%2F', '%20' ), array( '/', ' ' ), rawurlencode( urldecode( $path ) ) );
		$parts = array_values( array_filter( array_map( 'sanitize_title_for_query', explode( '/', trim( $path, '/' ) ) ) ) );

		if ( empty( $parts ) ) {
			return null;
		}

		$slug       = end( $parts );
		$post_types = "'" . implode( "','", esc_sql( $post_types ) ) . "'";

		$sql  = "SELECT ID FROM {$wpdb->posts}" . $this->model->post->join_clause();
		$sql .= $wpdb->prepare( " WHERE {$wpdb->posts}.post_name = %s AND {$wpdb->posts}.post_type IN ( {$post_types} )", $slug ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql .= $this->model->post->where_clause( $language );

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( is_post_type_hierarchical( $post->post_type ) && get_page_uri( $post ) !== implode( '/', $parts ) ) {
				continue;
			}

			return $post;
		}

		return null;
	}

	/**
	 * Queries the fallback post in the default language instead of the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param string   $where The WHERE clause.
	 * @param WP_Query $query The query.
	 * @return string
	 */
	public function posts_where( $where, $query ) {
		if ( empty( $query->strope_fallback_single ) || ! $this->curlang instanceof Strope_Language ) {
			return $where;
		}

		return $this->replace_where_fragment(
			$where,
			$this->model->post->where_clause( $this->curlang ),
			$this->model->post->where_clause( $this->model->get_default_language() )
		);
	}

	/**
	 * Prevents the redirection of a fallback page to the url in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function template_redirect() {
		$object = $this->get_queried_fallback();

		if ( ! $object ) {
			return;
		}

		if ( ! empty( $this->strope->canonical ) ) {
			remove_action( 'template_redirect', array( $this->strope->canonical, 'check_canonical_url' ), 4 );
		}

		$this->maybe_redirect( $object );
	}

	/**
	 * Redirects a fallback page to its expected url, e.g. when the slugs are translated.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post|WP_Term $object The queried object.
	 * @return void
	 */
	protected function maybe_redirect( $object ) {
		if ( ! $this->links_model->using_permalinks || is_paged() || get_query_var( 'page' ) || get_query_var( 'cpage' ) || is_feed() || is_preview() || is_customize_preview() || ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		$expected = $object instanceof WP_Post ? get_permalink( $object ) : get_term_link( $object );

		if ( ! is_string( $expected ) || '' === $expected ) {
			return;
		}

		$requested      = strope_get_requested_url();
		$requested_path = untrailingslashit( rawurldecode( (string) wp_parse_url( $requested, PHP_URL_PATH ) ) );
		$expected_path  = untrailingslashit( rawurldecode( (string) wp_parse_url( $expected, PHP_URL_PATH ) ) );

		if ( strtolower( $requested_path ) === strtolower( $expected_path ) ) {
			return; // Trailing slashes are managed by WordPress.
		}

		$query = wp_parse_url( $requested, PHP_URL_QUERY );
		if ( ! empty( $query ) ) {
			$expected .= ( false === strpos( $expected, '?' ) ? '?' : '&' ) . $query;
		}

		if ( wp_validate_redirect( $expected ) ) {
			wp_safe_redirect( $expected, 301, STROPE_TRANSLATE );
			exit;
		}
	}

	/*
	 * Links.
	 */

	/**
	 * Switches the language and translated slugs of a link.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $link     The link.
	 * @param Strope_Language $language The language.
	 * @param string          $type     Post type or taxonomy name.
	 * @return string
	 */
	protected function switch_link( $link, $language, $type ) {
		global $wp_rewrite;

		$link = $this->links_model->switch_language_in_link( $link, $language );

		if ( ! empty( $this->strope->translate_slugs->slugs_model ) ) {
			$slugs_model = $this->strope->translate_slugs->slugs_model;
			$types       = array( $type );

			if ( ! empty( $wp_rewrite->front ) && trim( $wp_rewrite->front, '/' ) ) {
				$types[] = 'front';
			}

			foreach ( $types as $slug_type ) {
				if ( isset( $slugs_model->translated_slugs[ $slug_type ]['translations'][ $language->slug ] ) ) {
					$link = $slugs_model->switch_translated_slug( $link, $language, $slug_type );
				}
			}
		}

		return $link;
	}

	/**
	 * Modifies the links to posts displayed as a fallback, so that the visitor stays in his language.
	 *
	 * @since 4.1.0
	 *
	 * @param string      $link The post link.
	 * @param WP_Post|int $post The post.
	 * @return string
	 */
	public function post_link( $link, $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post || false !== strpos( $link, 'preview=true' ) || ! $this->is_frontend_context() ) {
			return $link;
		}

		$language = $this->get_link_language();

		return $this->is_fallback_post( $post, $language ) ? $this->switch_link( $link, $language, $post->post_type ) : $link;
	}

	/**
	 * Modifies the links to pages displayed as a fallback, so that the visitor stays in his language.
	 *
	 * @since 4.1.0
	 *
	 * @param string $link    The page link.
	 * @param int    $post_id The page id.
	 * @return string
	 */
	public function page_link( $link, $post_id ) {
		return $this->post_link( $link, $post_id );
	}

	/**
	 * Modifies the links to terms displayed as a fallback, so that the visitor stays in his language.
	 *
	 * @since 4.1.0
	 *
	 * @param string  $link     The term link.
	 * @param WP_Term $term     The term.
	 * @param string  $taxonomy The taxonomy.
	 * @return string
	 */
	public function term_link( $link, $term, $taxonomy ) {
		if ( ! $term instanceof WP_Term || ! $this->is_frontend_context() ) {
			return $link;
		}

		$language = $this->get_link_language();

		return $this->is_fallback_term( $term, $language ) ? $this->switch_link( $link, $language, $taxonomy ) : $link;
	}

	/*
	 * Language switcher and SEO.
	 */

	/**
	 * Returns the urls of the translations of a post or term displayed as a fallback.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $url               Empty string or the url of the translation of the current page.
	 * @param Strope_Language $language          Language of the translation.
	 * @param int             $queried_object_id Queried object ID.
	 * @return string
	 */
	public function pre_translation_url( $url, $language, $queried_object_id ) {
		if ( ! empty( $url ) || ! $language instanceof Strope_Language || ! $this->curlang instanceof Strope_Language || ! $this->is_frontend_context() ) {
			return $url;
		}

		if ( is_front_page() || is_home() || is_attachment() || is_preview() ) {
			return $url;
		}

		$default  = $this->model->get_default_language();
		$object   = get_queried_object();
		$fallback = $this->get_queried_fallback();

		if ( ! $default ) {
			return $url;
		}

		if ( is_singular() && $object instanceof WP_Post && in_array( $object->post_type, $this->post_types, true ) ) {
			$tr_id = $this->model->post->get( $object->ID, $language );

			if ( $tr_id && $this->is_visible_post( $tr_id ) && $this->model->post->current_user_can_read( $tr_id ) ) {
				if ( ! $fallback ) {
					return $url; // Let Strope Translate do its job.
				}

				return (string) $this->with_link_language(
					$language,
					function () use ( $tr_id ) {
						return get_permalink( $tr_id );
					}
				);
			}

			if ( $default->slug === $language->slug ) {
				return $url;
			}

			$source = get_post( $this->model->post->get( $object->ID, $default ) );

			if ( ! $source instanceof WP_Post || ! $this->is_visible_post( $source->ID ) || ! $this->is_fallback_post( $source, $language ) ) {
				return $url;
			}

			return $this->add_fallback_url(
				$this->with_link_language(
					$language,
					function () use ( $source ) {
						return get_permalink( $source );
					}
				)
			);
		}

		if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term && in_array( $object->taxonomy, $this->taxonomies, true ) ) {
			$tr_id = $this->model->term->get( $object->term_id, $language );

			if ( $tr_id ) {
				if ( ! $fallback ) {
					return $url; // Let Strope Translate do its job.
				}

				$tr_term = get_term( $tr_id, $object->taxonomy );

				if ( ! $tr_term instanceof WP_Term ) {
					return $url;
				}

				$link = $this->with_link_language(
					$language,
					function () use ( $tr_term ) {
						return get_term_link( $tr_term );
					}
				);

				return is_string( $link ) ? $link : $url;
			}

			if ( $default->slug === $language->slug ) {
				return $url;
			}

			$source = get_term( $this->model->term->get( $object->term_id, $default ), $object->taxonomy );

			if ( ! $source instanceof WP_Term || ! $this->is_fallback_term( $source, $language ) ) {
				return $url;
			}

			$link = $this->with_link_language(
				$language,
				function () use ( $source ) {
					return get_term_link( $source );
				}
			);

			return is_string( $link ) ? $this->add_fallback_url( $link ) : $url;
		}

		return $url;
	}

	/**
	 * Remembers a fallback url.
	 *
	 * @since 4.1.0
	 *
	 * @param string|false $url The url.
	 * @return string
	 */
	protected function add_fallback_url( $url ) {
		$url = (string) $url;

		if ( '' !== $url ) {
			$this->fallback_urls[ $url ] = true;
		}

		return $url;
	}

	/**
	 * Tells whether an url points to content displayed as a fallback.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url The url.
	 * @return bool
	 */
	public function is_fallback_url( $url ) {
		return isset( $this->fallback_urls[ $url ] );
	}

	/**
	 * Remembers the urls of the archive translations displaying only fallback content.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $url  The translation url.
	 * @param string      $slug Language code of the translation.
	 * @return string|null
	 */
	public function translation_url( $url, $slug ) {
		if ( isset( $this->fallback_archive_languages[ $slug ] ) ) {
			unset( $this->fallback_archive_languages[ $slug ] );

			if ( is_string( $url ) ) {
				$this->add_fallback_url( $url );
			}
		}

		return $url;
	}

	/**
	 * Tells whether the queried archive, in the current language, displays only content in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public function is_fallback_archive() {
		global $wp_query;

		if ( null !== $this->queried_fallback_archive ) {
			return $this->queried_fallback_archive;
		}

		if ( ! did_action( 'wp' ) ) {
			return false; // Too early.
		}

		$this->queried_fallback_archive = false;

		if ( ! $this->options['force_lang'] || ! $this->is_active() || ! is_archive() || $this->get_queried_fallback() ) {
			return false;
		}

		// Only archives displaying content thanks to the fallback.
		if ( ! $wp_query instanceof WP_Query || ! $wp_query->tax_query instanceof Strope_Content_Fallback_Tax_Query || empty( $wp_query->post_count ) ) {
			return false;
		}

		// Does the archive contain content in the current language?
		$query_vars = array_merge(
			$wp_query->query,
			array(
				'lang'                   => $this->curlang->slug,
				'strope_fallback'        => false,
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		unset( $query_vars['paged'], $query_vars['page'], $query_vars['offset'] );

		$query = new WP_Query( $query_vars );

		$this->queried_fallback_archive = empty( $query->posts );

		return $this->queried_fallback_archive;
	}

	/**
	 * Removes the fallback urls from the hreflang alternates: they are not real translations.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $hreflangs Array of urls with language codes as keys.
	 * @return string[]
	 */
	public function rel_hreflang_attributes( $hreflangs ) {
		if ( ! is_array( $hreflangs ) ) {
			return $hreflangs;
		}

		// The current archive displays only fallback content: it is not a real translation either.
		if ( $this->is_fallback_archive() ) {
			$this->add_fallback_url( $this->strope->links->get_translation_url( $this->curlang ) );
		}

		if ( empty( $this->fallback_urls ) ) {
			return $hreflangs;
		}

		$hreflangs = array_filter(
			$hreflangs,
			function ( $url ) {
				return ! $this->is_fallback_url( $url );
			}
		);

		// No alternate at all if only one language remains.
		return count( array_diff_key( $hreflangs, array( 'x-default' => '' ) ) ) > 1 ? $hreflangs : array();
	}

	/**
	 * Points the canonical url of a post displayed as a fallback to the url in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @param string|false $canonical_url The canonical url.
	 * @param WP_Post      $post          The post.
	 * @return string|false
	 */
	public function get_canonical_url( $canonical_url, $post ) {
		$fallback = $this->get_queried_fallback();

		if ( $this->doing_canonical || ! $fallback instanceof WP_Post || ! $post instanceof WP_Post || $post->ID !== $fallback->ID ) {
			return $canonical_url;
		}

		$this->doing_canonical = true;

		$url = $this->with_link_language(
			$this->model->get_default_language(),
			function () use ( $post ) {
				return wp_get_canonical_url( $post );
			}
		);

		$this->doing_canonical = false;

		/**
		 * Filters the canonical url of content displayed as a fallback.
		 *
		 * @since 4.1.0
		 *
		 * @param string                      $url    The canonical url, in the default language.
		 * @param WP_Post|WP_Term|object|null $object The post or term displayed as a fallback,
		 *                                            or the queried object of an archive displaying only fallback content.
		 */
		return apply_filters( 'strope_content_fallback_canonical_url', $url ? $url : $canonical_url, $post );
	}

	/**
	 * Returns the canonical url, in the default language, of the current page when it displays fallback content:
	 * a post or term displayed as a fallback, or an archive displaying only content in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @return string Empty string if the current page doesn't display fallback content.
	 */
	public function get_fallback_canonical_url() {
		$object  = $this->get_queried_fallback();
		$default = $this->model->get_default_language();

		if ( $object instanceof WP_Post ) {
			return (string) wp_get_canonical_url( $object ); // Filtered by `get_canonical_url()`.
		}

		if ( $object instanceof WP_Term ) {
			$url = $this->with_link_language(
				$default,
				function () use ( $object ) {
					return get_term_link( $object );
				}
			);
		} elseif ( $default && $this->is_fallback_archive() ) {
			$object = get_queried_object();
			$url    = $this->strope->links->get_translation_url( $default );
		} else {
			return '';
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 ) {
			$url = $this->links_model->add_paged_to_link( $url, $paged );
		}

		/** This filter is documented in modules/content-fallback/frontend-content-fallback.php */
		$url = apply_filters( 'strope_content_fallback_canonical_url', $url, $object );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Outputs the canonical url of a term archive displayed as a fallback
	 * or of an archive displaying only fallback content.
	 * The canonical url of posts is output by WordPress, see `get_canonical_url()`.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function archive_canonical() {
		if ( is_singular() ) {
			return;
		}

		$url = $this->get_fallback_canonical_url();

		if ( $url ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
		}
	}

	/*
	 * Content.
	 */

	/**
	 * Returns the language of a post displayed in another language.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post|int|null $post The post.
	 * @return Strope_Language|false
	 */
	protected function get_foreign_language( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $this->post_types, true ) || ! $this->is_active() ) {
			return false;
		}

		$language = $this->model->post->get_language( $post->ID );
		$default  = $this->model->get_default_language();

		return $language && $language->slug === $default->slug ? $language : false;
	}

	/**
	 * Wraps a content with its language attributes.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $content  The content.
	 * @param Strope_Language $language The language of the content.
	 * @return string
	 */
	protected function wrap( $content, $language ) {
		return sprintf(
			'<div class="strope-content-fallback" lang="%s" dir="%s">%s</div>',
			esc_attr( $language->get_locale( 'display' ) ),
			$language->is_rtl ? 'rtl' : 'ltr',
			$content
		);
	}

	/**
	 * Returns the notice displayed above the content displayed as a fallback.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post         $post     The post.
	 * @param Strope_Language $language The language of the post.
	 * @return string
	 */
	protected function get_notice( $post, $language ) {
		$html = sprintf(
			'<p class="strope-content-fallback-notice">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s is a language name. */
					__( 'This content is not available in %s yet.', 'strope-translate' ),
					$this->curlang->name
				)
			)
		);

		/**
		 * Filters the notice displayed above content displayed as a fallback.
		 *
		 * @since 4.1.0
		 *
		 * @param string          $html     The notice markup.
		 * @param WP_Post         $post     The post displayed as a fallback.
		 * @param Strope_Language $curlang  The current language.
		 * @param Strope_Language $language The language of the post.
		 */
		return (string) apply_filters( 'strope_content_fallback_notice', $html, $post, $this->curlang, $language );
	}

	/**
	 * Marks the content displayed in the default language with its language,
	 * and optionally adds a notice above.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content The post content.
	 * @return string
	 */
	public function the_content( $content ) {
		if ( '' === trim( (string) $content ) || doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
			return $content;
		}

		$post     = get_post();
		$language = $this->get_foreign_language( $post );

		if ( ! $language ) {
			return $content;
		}

		$notice = '';
		if ( $this->notice && ! is_feed() && is_singular() && (int) $post->ID === (int) get_queried_object_id() ) {
			$notice = $this->get_notice( $post, $language );
		}

		return $notice . $this->wrap( $content, $language );
	}

	/**
	 * Marks the excerpt displayed in the default language with its language.
	 *
	 * @since 4.1.0
	 *
	 * @param string $excerpt The post excerpt.
	 * @return string
	 */
	public function the_excerpt( $excerpt ) {
		if ( '' === trim( (string) $excerpt ) ) {
			return $excerpt;
		}

		$language = $this->get_foreign_language( get_post() );

		return $language ? $this->wrap( $excerpt, $language ) : $excerpt;
	}

	/**
	 * Adds the language attributes to the post title and post excerpt blocks.
	 *
	 * @since 4.1.0
	 *
	 * @param string        $block_content The block content.
	 * @param array         $block         The block.
	 * @param WP_Block|null $instance      The block instance.
	 * @return string
	 */
	public function render_block( $block_content, $block, $instance = null ) {
		if ( '' === trim( (string) $block_content ) ) {
			return $block_content;
		}

		$post_id  = $instance instanceof WP_Block && ! empty( $instance->context['postId'] ) ? (int) $instance->context['postId'] : get_the_ID();
		$language = $this->get_foreign_language( $post_id );

		if ( ! $language ) {
			return $block_content;
		}

		$processor = new WP_HTML_Tag_Processor( $block_content );

		if ( ! $processor->next_tag() ) {
			return $block_content;
		}

		$processor->set_attribute( 'lang', $language->get_locale( 'display' ) );
		$processor->set_attribute( 'dir', $language->is_rtl ? 'rtl' : 'ltr' );

		return $processor->get_updated_html();
	}
}
