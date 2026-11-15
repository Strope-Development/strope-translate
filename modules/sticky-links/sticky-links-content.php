<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds the internal links in a post content and corrects them, so that they point to the current url
 * of the linked post or term, in the language of the post containing the link.
 *
 * The links are identified by an index (stored in the post) associating each url to the linked object,
 * so that a link is still found after the url of the linked object changed.
 *
 * @since 4.1.0
 *
 * @phpstan-type IndexEntry array{type: 'post'|'term', id: positive-int, source: positive-int}
 */
class Strope_Sticky_Links_Content {
	/**
	 * Blocks storing a link in their attributes.
	 *
	 * @var string[]
	 */
	const NAVIGATION_BLOCKS = array( 'navigation-link', 'navigation-submenu' );

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * @var Strope_Links_Model
	 */
	protected $links_model;

	/**
	 * @var array
	 */
	protected $options;

	/**
	 * Cache of resolved urls, for the content being fixed.
	 *
	 * @var array
	 */
	protected $resolved = array();

	/**
	 * Hosts of the website (all languages).
	 *
	 * @var string[]|null
	 */
	protected $hosts;

	/**
	 * Language of the content being fixed.
	 *
	 * @var Strope_Language|null
	 */
	protected $language;

	/**
	 * Index of the links found in the content being fixed.
	 *
	 * @var array
	 *
	 * @phpstan-var array<string, IndexEntry>
	 */
	protected $index = array();

	/**
	 * Index of the links stored before the fix.
	 *
	 * @var array
	 *
	 * @phpstan-var array<string, IndexEntry>
	 */
	protected $previous_index = array();

	/**
	 * Urls of the broken links found in the content being fixed.
	 *
	 * @var string[]
	 */
	protected $broken = array();

	/**
	 * Number of links corrected in the content being fixed.
	 *
	 * @var int
	 */
	protected $changed = 0;

	/**
	 * Language whose links are switched to the language of the content being fixed (the language
	 * the content was translated from), null to never switch the language of the links.
	 *
	 * @var Strope_Language|null
	 */
	protected $source_language;

	/**
	 * Urls stored in the attributes of the blocks of the content being fixed (except the navigation links).
	 * Such urls aren't modified in the html, as the block would become invalid.
	 *
	 * @var true[]
	 */
	protected $block_urls = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model       $model       Strope Translate's model.
	 * @param Strope_Links_Model $links_model Strope Translate's links model.
	 */
	public function __construct( Strope_Model $model, Strope_Links_Model $links_model ) {
		$this->model       = $model;
		$this->links_model = $links_model;
		$this->options     = $model->options;
	}

	/**
	 * Corrects the internal links of a content.
	 *
	 * @since 4.1.0
	 *
	 * Links are switched to the language of the content only when they point to content in the source language
	 * (the language the content was translated from), and never when they have a `hreflang` or `lang` attribute,
	 * so that intentional links to another language (e.g. "Read in French") are kept.
	 *
	 * @param string               $content         The content (unslashed).
	 * @param Strope_Language      $language        The language of the content.
	 * @param array                $previous_index  The index of the links stored before.
	 * @param Strope_Language|null $source_language The language the content was translated from, null if none.
	 * @return array {
	 *   @type string   $content The corrected content.
	 *   @type array    $index   The index of the links, normalized urls as keys.
	 *   @type string[] $broken  The urls of the links pointing to a deleted post or term.
	 *   @type int      $changed The number of corrected links.
	 * }
	 */
	public function fix( string $content, Strope_Language $language, array $previous_index = array(), ?Strope_Language $source_language = null ): array {
		$this->language        = $language;
		$this->source_language = $source_language && $source_language->slug !== $language->slug ? $source_language : null;
		$this->previous_index  = $previous_index;
		$this->index           = array();
		$this->broken          = array();
		$this->changed         = 0;
		$this->resolved        = array(); // The linked objects may have changed since the previous fix.
		$this->block_urls      = $this->get_block_urls( $content );

		// The urls are built as seen by a visitor browsing the content language.
		$strope          = Strope();
		$current         = $strope->curlang;
		$strope->curlang = $language;

		try {
			$content = $this->fix_links( $content );
		} finally {
			$strope->curlang = $current;
		}

		$result = array(
			'content' => $content,
			'index'   => $this->index,
			'broken'  => array_values( array_unique( $this->broken ) ),
			'changed' => $this->changed,
		);

		$this->language        = null;
		$this->source_language = null;
		$this->previous_index  = array();
		$this->block_urls      = array();

		return $result;
	}

	/**
	 * Returns the urls stored in the attributes of the blocks (except the navigation links).
	 *
	 * @since 4.1.0
	 *
	 * @param string $content The content.
	 * @return true[] Urls as keys.
	 */
	protected function get_block_urls( string $content ): array {
		$urls = array();

		if ( ! preg_match_all( '/<!--\s+wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{.*?\})\s+\/?-->/s', $content, $matches, PREG_SET_ORDER ) ) {
			return $urls;
		}

		foreach ( $matches as $match ) {
			if ( in_array( $match[1], self::NAVIGATION_BLOCKS, true ) ) {
				continue;
			}

			$attributes = json_decode( $match[2], true );

			if ( is_array( $attributes ) ) {
				array_walk_recursive(
					$attributes,
					function ( $value ) use ( &$urls ) {
						if ( is_string( $value ) && '' !== $value ) {
							$urls[ trim( $value ) ] = true;
						}
					}
				);
			}
		}

		return $urls;
	}

	/**
	 * Corrects the links in html attributes and in navigation blocks.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content The content.
	 * @return string
	 */
	protected function fix_links( string $content ): string {
		if ( false !== stripos( $content, 'href' ) ) {
			$content = (string) preg_replace_callback(
				'/<a\s[^>]*>/is',
				array( $this, 'fix_link_tag' ),
				$content
			);
		}

		if ( false !== strpos( $content, '<!-- wp:navigation-' ) ) {
			$content = (string) preg_replace_callback(
				'/<!--\s+wp:(' . implode( '|', self::NAVIGATION_BLOCKS ) . ')\s+(\{.*?\})\s+(\/?)-->/s',
				array( $this, 'fix_navigation_block' ),
				$content
			);
		}

		return $content;
	}

	/**
	 * Corrects the url of a link.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $matches Matches of the `<a>` opening tag regex.
	 * @return string
	 */
	protected function fix_link_tag( array $matches ): string {
		$tag = $matches[0];

		if ( ! preg_match( '/(\shref\s*=\s*)(["\'])(.*?)\2/is', $tag, $href, PREG_OFFSET_CAPTURE ) ) {
			return $tag;
		}

		// An url also stored in the block attributes can't be modified in the html alone.
		if ( isset( $this->block_urls[ trim( wp_specialchars_decode( $href[3][0], ENT_QUOTES ) ) ] ) ) {
			return $tag;
		}

		// A link explicitly in a language (e.g. "Read in French") keeps its language.
		$keep_language = (bool) preg_match( '/\s(?:hreflang|lang)\s*=/i', $tag );

		$url = $this->fix_url( $href[3][0], null, $target, $keep_language );

		return null === $url ? $tag : substr_replace( $tag, $url, $href[3][1], strlen( $href[3][0] ) );
	}

	/**
	 * Corrects the url (and the linked object ID) of a navigation link block.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $matches Matches of the block comment regex.
	 * @return string
	 */
	protected function fix_navigation_block( array $matches ): string {
		$attributes = json_decode( $matches[2], true );

		if ( ! is_array( $attributes ) || empty( $attributes['url'] ) || ! is_string( $attributes['url'] ) ) {
			return $matches[0];
		}

		$kind   = isset( $attributes['kind'] ) ? $attributes['kind'] : '';
		$object = null;

		if ( ! empty( $attributes['id'] ) && is_numeric( $attributes['id'] ) && in_array( $kind, array( 'post-type', 'taxonomy' ), true ) ) {
			$object = array(
				'type' => 'post-type' === $kind ? 'post' : 'term',
				'id'   => (int) $attributes['id'],
			);
		}

		$target = null;
		$url    = $this->fix_url( esc_attr( $attributes['url'] ), $object, $target );

		if ( null === $url ) {
			return $matches[0];
		}

		$attributes['url'] = wp_specialchars_decode( $url, ENT_QUOTES );

		if ( $object && $target && $target['type'] === $object['type'] ) {
			$attributes['id'] = $target['id'];
		}

		return sprintf( '<!-- wp:%s %s %s-->', $matches[1], serialize_block_attributes( $attributes ), $matches[3] );
	}

	/**
	 * Corrects an url.
	 *
	 * @since 4.1.0
	 *
	 * @param string     $raw           The url, as found in an html attribute.
	 * @param array|null $object        Optional. The linked object, when known.
	 * @param array|null $target        Set to the object finally linked.
	 * @param bool       $keep_language Optional. Whether to keep the language of the linked object. Default false.
	 * @return string|null The corrected url, html escaped, null if not modified.
	 */
	protected function fix_url( string $raw, ?array $object = null, ?array &$target = null, bool $keep_language = false ): ?string {
		$url = trim( wp_specialchars_decode( $raw, ENT_QUOTES ) );

		$parts = $this->split_url( $url );
		if ( null === $parts ) {
			return null;
		}

		list( $base, $suffix, $absolute ) = $parts;

		$key   = $this->normalize( $absolute );
		$entry = isset( $this->previous_index[ $key ] ) ? $this->previous_index[ $key ] : null;

		// Find the linked object: the one stored in the index first (the url may be outdated), then from the url.
		$source = null;
		if ( $object && $this->exists( $object['type'], $object['id'] ) ) {
			$source = $object;
		} elseif ( $entry && $this->exists( $entry['type'], $entry['source'] ) ) {
			$source = array(
				'type' => $entry['type'],
				'id'   => $entry['source'],
			);
		} elseif ( $entry && $this->exists( $entry['type'], $entry['id'] ) ) {
			$source = array(
				'type' => $entry['type'],
				'id'   => $entry['id'],
			);
		} else {
			$source = $this->resolve_url( $absolute );
			$source = $source && $this->exists( $source['type'], $source['id'] ) ? $source : null;
		}

		if ( null === $source ) {
			if ( $entry || $object ) {
				// Keep the entry to report the link as broken until it's modified.
				$this->broken[]      = $url;
				$this->index[ $key ] = $entry ? $entry : array(
					'type'   => $object['type'],
					'id'     => $object['id'],
					'source' => $object['id'],
				);
			}
			return null;
		}

		$target = $this->get_target( $source, $keep_language );
		$new    = $target ? $this->get_link( $target ) : '';

		if ( '' === $new ) {
			// The linked object isn't published (yet): keep the link and its index entry, it'll be corrected later.
			$this->index[ $key ] = array(
				'type'   => $source['type'],
				'id'     => $source['id'],
				'source' => $source['id'],
			);
			$target = null;
			return null;
		}

		$this->index[ $this->normalize( $new ) ] = array(
			'type'   => $target['type'],
			'id'     => $target['id'],
			'source' => $source['id'],
		);

		if ( $this->normalize( $new ) === $key ) {
			return null;
		}

		if ( '/' === $base[0] && wp_parse_url( $new, PHP_URL_HOST ) === wp_parse_url( $absolute, PHP_URL_HOST ) ) {
			$new = wp_make_link_relative( $new );
		}

		++$this->changed;

		return esc_attr( $new ) . ( '' === $suffix ? '' : esc_attr( $suffix ) );
	}

	/**
	 * Splits an internal url into its base (identifying the linked object) and its suffix (query string and fragment).
	 *
	 * @since 4.1.0
	 *
	 * @param string $url The url.
	 * @return array|null Base, suffix and absolute base url, null if the url isn't an internal link to a content.
	 */
	protected function split_url( string $url ): ?array {
		if ( '' === $url || '#' === $url[0] || '?' === $url[0] || ( '/' !== $url[0] && ! preg_match( '#^https?://#i', $url ) ) || 0 === strpos( $url, '//' ) ) {
			return null;
		}

		// With pretty permalinks, the query string doesn't identify the linked object.
		$separators = get_option( 'permalink_structure' ) ? '?#' : '#';
		$position   = strcspn( $url, $separators );
		$base       = substr( $url, 0, $position );
		$suffix     = (string) substr( $url, $position );

		if ( '/' === $base[0] ) {
			$home     = $this->language ? $this->language->get_home_url() : home_url( '/' );
			$scheme   = wp_parse_url( $home, PHP_URL_SCHEME );
			$host     = wp_parse_url( $home, PHP_URL_HOST );
			$port     = wp_parse_url( $home, PHP_URL_PORT );
			$absolute = $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $base;
		} else {
			$absolute = $base;
		}

		$host = wp_parse_url( $absolute, PHP_URL_HOST );
		$path = (string) wp_parse_url( $absolute, PHP_URL_PATH );

		if ( ! is_string( $host ) || ! in_array( strtolower( $host ), $this->get_hosts(), true ) ) {
			return null;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && 0 !== strpos( trailingslashit( $path ), $home_path ) ) {
			return null;
		}

		if ( preg_match( '#/(wp-admin|wp-content|wp-includes|wp-json)(/|$)|/(wp-login|xmlrpc|wp-cron)\.php#i', $path ) ) {
			return null;
		}

		return array( $base, $suffix, $absolute );
	}

	/**
	 * Returns the hosts of the website, lowercased.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_hosts(): array {
		if ( null === $this->hosts ) {
			$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
			foreach ( $this->model->get_languages_list() as $language ) {
				$hosts[] = wp_parse_url( $language->get_home_url(), PHP_URL_HOST );
			}
			$this->hosts = array_values( array_unique( array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) ) );
		}

		return $this->hosts;
	}

	/**
	 * Normalizes an absolute url, to be used as index key.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url An absolute url.
	 * @return string
	 */
	public function normalize( string $url ): string {
		$url = strtok( $url, '#' );
		$url = preg_replace_callback(
			'#^https?://[^/?]+#i',
			function ( $matches ) {
				return strtolower( preg_replace( '#^https?://#i', '', $matches[0] ) );
			},
			(string) $url
		);

		$position = strcspn( (string) $url, '?' );
		return untrailingslashit( substr( (string) $url, 0, $position ) ) . substr( (string) $url, $position );
	}

	/**
	 * Finds the post or term linked by an url.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url An absolute internal url, without fragment.
	 * @return array|null Object type ('post' or 'term') and ID.
	 */
	public function resolve_url( string $url ): ?array {
		$key = $this->normalize( $url );

		if ( ! array_key_exists( $key, $this->resolved ) ) {
			$this->resolved[ $key ] = $this->do_resolve_url( $url );
		}

		return $this->resolved[ $key ];
	}

	/**
	 * Finds the post or term linked by an url.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url An absolute internal url, without fragment.
	 * @return array|null Object type ('post' or 'term') and ID.
	 */
	protected function do_resolve_url( string $url ): ?array {
		$slug     = $this->links_model->get_language_from_url( $url );
		$language = $slug ? $this->model->get_language( $slug ) : false;

		if ( ! $language && $this->options['force_lang'] ) {
			$language = $this->model->get_default_language();
		}

		$resolved = $language ? $language : null;

		// Queries are filtered by the current language (or the language of a REST request): resolve the url in its own language.
		$strope          = Strope();
		$current         = $strope->curlang;
		$strope->curlang = $resolved;
		$model           = $this->model;
		$lang            = $language ? $language->slug : implode( ',', $model->get_languages_list( array( 'fields' => 'slug' ) ) );
		$set_language    = function ( $query ) use ( $model, $language, $lang ) {
			if ( empty( $query->get( 'lang' ) ) ) {
				$strope_query = new Strope_Query( $query, $model );
				$strope_query->query->set( 'lang', $lang );
				if ( $language ) {
					$strope_query->filter_query( $language );
				}
			}
		};
		add_action( 'parse_query', $set_language, 0 );

		try {
			$lookup = $this->links_model instanceof Strope_Links_Abstract_Domain ? $this->links_model->remove_language_from_link( $url ) : $url;
			$id     = url_to_postid( $lookup );

			if ( ! $id && 'page' === get_option( 'show_on_front' ) && $this->is_home_url( $url ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
		} finally {
			remove_action( 'parse_query', $set_language, 0 );
			$strope->curlang = $current;
		}

		if ( $id > 0 && get_post( $id ) ) {
			if ( $language && $this->model->is_translated_post_type( get_post_type( $id ) ) ) {
				$post_language = $this->model->post->get_language( $id );
				if ( $post_language && $post_language->slug !== $language->slug ) {
					$tr_id = $this->model->post->get( $id, $language );
					$id    = $tr_id ? $tr_id : $id;
				}
			}

			return array(
				'type' => 'post',
				'id'   => (int) $id,
			);
		}

		return $this->resolve_term_url( $url );
	}

	/**
	 * Tells whether an url is the home url of one of the languages.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url An absolute url.
	 * @return bool
	 */
	protected function is_home_url( string $url ): bool {
		$key   = $this->normalize( $url );
		$homes = array( home_url( '/' ) );

		foreach ( $this->model->get_languages_list() as $language ) {
			$homes[] = $language->get_home_url();
		}

		foreach ( $homes as $home ) {
			if ( $this->normalize( $home ) === $key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Finds the term linked by an url.
	 *
	 * @since 4.1.0
	 *
	 * @param string $url An absolute internal url, without fragment.
	 * @return array|null Object type ('term') and ID.
	 */
	protected function resolve_term_url( string $url ): ?array {
		$path     = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$position = strrpos( $path, '/' );
		$slug     = strtolower( false === $position ? $path : substr( $path, $position + 1 ) );

		if ( '' === $slug ) {
			return null;
		}

		$taxonomies = get_taxonomies( array( 'public' => true ) );
		unset( $taxonomies['post_format'] );

		$terms = get_terms(
			array(
				'taxonomy'               => array_values( $taxonomies ),
				'slug'                   => array( $slug, rawurldecode( $slug ) ),
				'hide_empty'             => false,
				'lang'                   => implode( ',', $this->model->get_languages_list( array( 'fields' => 'slug' ) ) ),
				'update_term_meta_cache' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return null;
		}

		$key = $this->normalize( $url );

		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) && $this->normalize( $link ) === $key ) {
				return array(
					'type' => 'term',
					'id'   => (int) $term->term_id,
				);
			}
		}

		return null;
	}

	/**
	 * Tells whether a post or term exists (and is not trashed).
	 *
	 * @since 4.1.0
	 *
	 * @param string $type Object type: 'post' or 'term'.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	protected function exists( string $type, int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		if ( 'term' === $type ) {
			$term = get_term( $id );
			return $term instanceof WP_Term;
		}

		$post = get_post( $id );
		return $post instanceof WP_Post && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) && 'revision' !== $post->post_type;
	}

	/**
	 * Tells whether a post or term can be linked (it's publicly visible).
	 *
	 * @since 4.1.0
	 *
	 * @param string $type Object type: 'post' or 'term'.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	protected function is_linkable( string $type, int $id ): bool {
		if ( 'term' === $type ) {
			return $this->exists( 'term', $id );
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'attachment' === $post->post_type ) {
			return 'inherit' === $post->post_status || 'publish' === $post->post_status;
		}

		return 'publish' === $post->post_status;
	}

	/**
	 * Returns the object to link in the language of the content: the translation of the linked object
	 * if the linked object is in the source language and its translation is published, the linked object itself otherwise.
	 *
	 * @since 4.1.0
	 *
	 * @param array $source        Object type and ID.
	 * @param bool  $keep_language Optional. Whether to keep the language of the linked object. Default false.
	 * @return array|null Object type and ID, null if neither the translation nor the object can be linked.
	 */
	protected function get_target( array $source, bool $keep_language = false ): ?array {
		if ( 'term' === $source['type'] ) {
			$term       = get_term( $source['id'] );
			$translated = $term instanceof WP_Term && $this->model->is_translated_taxonomy( $term->taxonomy );
		} else {
			$translated = $this->model->is_translated_post_type( (string) get_post_type( $source['id'] ) );
		}

		if ( $translated && $this->language && $this->source_language && ! $keep_language ) {
			$object          = 'term' === $source['type'] ? $this->model->term : $this->model->post;
			$source_language = $object->get_language( $source['id'] );
			$tr_id           = $source_language && $source_language->slug === $this->source_language->slug ? $object->get( $source['id'], $this->language ) : 0;

			if ( $tr_id && $this->is_linkable( $source['type'], $tr_id ) ) {
				return array(
					'type' => $source['type'],
					'id'   => (int) $tr_id,
				);
			}
		}

		return $this->is_linkable( $source['type'], $source['id'] ) ? $source : null;
	}

	/**
	 * Returns the url of a post or term.
	 *
	 * @since 4.1.0
	 *
	 * @param array $target Object type and ID.
	 * @return string Empty string on failure.
	 */
	protected function get_link( array $target ): string {
		$link = 'term' === $target['type'] ? get_term_link( (int) $target['id'] ) : get_permalink( (int) $target['id'] );
		return is_string( $link ) ? $link : '';
	}
}
