<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translation status tracking: public API.
 *
 * Data model (post metas):
 * - `_strope_content_hash`: md5 of the normalized translatable content of a post (see `get_fields()`).
 * - `_strope_source`: array( 'id' => source post id, 'hash' => content hash of the source when the translation
 *   was last updated, 'time' => timestamp, 'user' => user id ). Absent for original posts and legacy content.
 * - `_strope_needs_review`: array( 'method' => 'duplicate'|..., 'time' => timestamp, 'user' => user id,
 *   'pending' => bool ). Set when the content was not written by a human (duplication...). When `pending` is true,
 *   the next user save (the one that completes the creation of the translation in the editor) only resets
 *   `pending` instead of clearing the flag.
 * - `_strope_outdated`: denormalized flag (value = source post id) maintained on writes so that list filters and
 *   counts can use simple meta queries.
 *
 * Statuses per (post, language): `missing`, `up_to_date`, `outdated`, plus the `needs_review` flag.
 * A post without source (the original of the translation group) is always `up_to_date`.
 * Posts synchronized by the sync-post module are always `up_to_date` and never need a review.
 *
 * All methods are static so that other modules can use them without needing a reference to an instance.
 *
 * @since 4.1.0
 */
class Strope_Translation_Status {
	const META_HASH     = '_strope_content_hash';
	const META_SOURCE   = '_strope_source';
	const META_REVIEW   = '_strope_needs_review';
	const META_OUTDATED = '_strope_outdated';

	const STATUS_MISSING    = 'missing';
	const STATUS_UP_TO_DATE = 'up_to_date';
	const STATUS_OUTDATED   = 'outdated';

	/**
	 * Whether the feature is enabled.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Filters whether the translation status tracking is enabled.
		 * Must be filtered before the plugin modules are loaded (e.g. in a mu-plugin or another plugin).
		 *
		 * @since 4.1.0
		 *
		 * @param bool $enabled True to enable the translation status tracking (default).
		 */
		return (bool) apply_filters( 'strope_translation_status_enabled', true );
	}

	/**
	 * Returns the model.
	 *
	 * @since 4.1.0
	 *
	 * @return Strope_Model|null
	 */
	protected static function model() {
		$strope = function_exists( 'Strope' ) ? Strope() : null;
		return $strope && isset( $strope->model ) ? $strope->model : null;
	}

	/**
	 * Returns the post types for which the translation status is tracked.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public static function get_post_types() {
		static $cache = null;

		$model = self::model();

		if ( empty( $model ) ) {
			return array();
		}

		// Post types may be registered until `init`, use a cache only after `wp_loaded`.
		$can_cache = did_action( 'wp_loaded' ) > 0;

		if ( $can_cache && null !== $cache ) {
			return $cache;
		}

		$post_types = array_diff(
			$model->get_translated_post_types(),
			array( 'attachment', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face' )
		);

		/**
		 * Filters the post types for which the translation status is tracked.
		 * Only translated post types are taken into account.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $post_types Post type names.
		 */
		$post_types = (array) apply_filters( 'strope_translation_status_post_types', array_values( $post_types ) );

		$post_types = array_values( array_filter( $post_types, array( $model, 'is_translated_post_type' ) ) );

		if ( $can_cache ) {
			$cache = $post_types;
		}

		return $post_types;
	}

	/**
	 * Tells whether a post type is tracked.
	 *
	 * @since 4.1.0
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public static function is_tracked_post_type( $post_type ) {
		return is_string( $post_type ) && in_array( $post_type, self::get_post_types(), true );
	}

	/**
	 * Tells whether a post is tracked (existing post, tracked post type, not a revision nor an auto-draft).
	 *
	 * @since 4.1.0
	 *
	 * @param int|WP_Post $post          Post ID or object.
	 * @param bool        $allow_drafts  Whether to accept auto-drafts.
	 * @return bool
	 */
	public static function is_tracked_post( $post, $allow_drafts = false ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post || 'revision' === $post->post_type ) {
			return false;
		}

		if ( ! $allow_drafts && 'auto-draft' === $post->post_status ) {
			return false;
		}

		return self::is_tracked_post_type( $post->post_type );
	}

	/**
	 * Returns the list of fields included in the content hash.
	 * WP_Post properties are read from the post, other names are considered as post meta keys.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	public static function get_fields( WP_Post $post ) {
		/**
		 * Filters the translatable fields included in the content hash used to detect outdated translations.
		 * Values which are not WP_Post properties are considered as post meta keys.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $fields List of fields, default `array( 'post_title', 'post_content', 'post_excerpt' )`.
		 * @param WP_Post  $post   The post.
		 */
		$fields = apply_filters( 'strope_translation_status_fields', array( 'post_title', 'post_content', 'post_excerpt' ), $post );

		return array_values( array_unique( array_filter( (array) $fields, 'is_string' ) ) );
	}

	/**
	 * Removes the urls of the internal links from a content, as they don't need to be translated
	 * (and may be corrected automatically by the sticky links).
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	protected static function remove_internal_urls( string $content ): string {
		$hosts = array( wp_parse_url( home_url(), PHP_URL_HOST ) );
		$model = self::model();

		if ( $model ) {
			foreach ( $model->get_languages_list() as $language ) {
				$hosts[] = wp_parse_url( $language->get_home_url(), PHP_URL_HOST );
			}
		}

		$hosts = array_map( 'strtolower', array_filter( $hosts, 'is_string' ) );

		$is_internal = function ( $url ) use ( $hosts ) {
			$url = trim( wp_specialchars_decode( $url, ENT_QUOTES ) );
			if ( preg_match( '#^/(?!/)#', $url ) ) {
				return true;
			}
			$host = preg_match( '#^https?://#i', $url ) ? wp_parse_url( $url, PHP_URL_HOST ) : null;
			return is_string( $host ) && in_array( strtolower( $host ), $hosts, true );
		};

		if ( false !== stripos( $content, 'href' ) ) {
			$content = (string) preg_replace_callback(
				'/(<a\s(?:[^>]*?\s)?href\s*=\s*)(["\'])(.*?)\2/is',
				function ( $matches ) use ( $is_internal ) {
					return $is_internal( $matches[3] ) ? $matches[1] . $matches[2] . $matches[2] : $matches[0];
				},
				$content
			);
		}

		if ( false !== strpos( $content, '<!-- wp:navigation-' ) ) {
			$content = (string) preg_replace_callback(
				'/<!--\s+wp:(navigation-link|navigation-submenu)\s+(\{.*?\})\s+(\/?)-->/s',
				function ( $matches ) use ( $is_internal ) {
					$attributes = json_decode( $matches[2], true );
					if ( ! is_array( $attributes ) || ! isset( $attributes['url'] ) || ! is_string( $attributes['url'] ) || ! $is_internal( $attributes['url'] ) ) {
						return $matches[0];
					}
					unset( $attributes['url'], $attributes['id'] );
					return sprintf( '<!-- wp:%s %s %s-->', $matches[1], serialize_block_attributes( $attributes ), $matches[3] );
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Normalizes a value before hashing it.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function normalize( $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			$value = wp_json_encode( $value );
		}

		$value = str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	/**
	 * Computes the content hash of a post from its current content.
	 *
	 * @since 4.1.0
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string Empty string if the post doesn't exist.
	 */
	public static function compute_hash( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$data = array();

		foreach ( self::get_fields( $post ) as $field ) {
			if ( 'post_content' === $field ) {
				$data[ $field ] = self::normalize( self::remove_internal_urls( (string) $post->post_content ) );
			} elseif ( in_array( $field, array( 'post_title', 'post_excerpt', 'post_name' ), true ) || property_exists( $post, $field ) ) {
				$data[ $field ] = self::normalize( $post->$field );
			} else {
				$values = get_post_meta( $post->ID, $field );
				$data[ 'meta:' . $field ] = self::normalize( is_array( $values ) && 1 === count( $values ) ? reset( $values ) : $values );
			}
		}

		return md5( (string) wp_json_encode( $data ) );
	}

	/**
	 * Returns the stored content hash of a post, computing and storing it if absent (lazy backfill).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_hash( $post_id ) {
		$post_id = (int) $post_id;
		$hash    = get_post_meta( $post_id, self::META_HASH, true );

		if ( is_string( $hash ) && '' !== $hash ) {
			return $hash;
		}

		$hash = self::compute_hash( $post_id );

		if ( '' !== $hash && self::is_tracked_post( $post_id ) ) {
			update_post_meta( $post_id, self::META_HASH, $hash );
		}

		return $hash;
	}

	/**
	 * Recomputes and stores the content hash of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return string The new hash.
	 */
	public static function update_hash( $post_id ) {
		$hash = self::compute_hash( $post_id );

		if ( '' !== $hash && get_post_meta( $post_id, self::META_HASH, true ) !== $hash ) {
			update_post_meta( $post_id, self::META_HASH, $hash );
		}

		return $hash;
	}

	/**
	 * Returns the translations of a post (including itself), language slugs as keys.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function get_translations( $post_id ) {
		$model = self::model();
		return $model ? $model->post->get_translations( (int) $post_id ) : array();
	}

	/**
	 * Returns the id of the source of a translation: the post it was created from if it's still in the translation group,
	 * otherwise the post in the default language. 0 if the post is an original (has no source).
	 *
	 * @since 4.1.0
	 *
	 * @param int        $post_id      Post ID.
	 * @param int[]|null $translations Optional translations group, to avoid querying it again.
	 * @return int
	 */
	public static function get_source_id( $post_id, $translations = null ) {
		$post_id      = (int) $post_id;
		$translations = is_array( $translations ) ? $translations : self::get_translations( $post_id );
		$translations = array_map( 'intval', $translations );

		$meta = get_post_meta( $post_id, self::META_SOURCE, true );

		if ( is_array( $meta ) && ! empty( $meta['id'] ) && (int) $meta['id'] !== $post_id && in_array( (int) $meta['id'], $translations, true ) ) {
			return (int) $meta['id'];
		}

		$model   = self::model();
		$default = $model ? $model->get_default_language() : false;

		if ( empty( $default ) || empty( $translations[ $default->slug ] ) || $translations[ $default->slug ] === $post_id ) {
			return 0;
		}

		$default_id = $translations[ $default->slug ];

		// Avoid cycles: if the default language post was created from this post, this post is the original.
		$default_meta = get_post_meta( $default_id, self::META_SOURCE, true );

		if ( is_array( $default_meta ) && ! empty( $default_meta['id'] ) && (int) $default_meta['id'] === $post_id ) {
			return 0;
		}

		return $default_id;
	}

	/**
	 * Tells whether a post is synchronized with at least another post by the sync-post module.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_synchronized( $post_id ) {
		$model = self::model();

		if ( empty( $model ) ) {
			return false;
		}

		$term = $model->post->get_object_term( (int) $post_id, 'post_translations' );
		$lang = $model->post->get_language( (int) $post_id );

		if ( empty( $term->description ) || empty( $lang ) ) {
			return false;
		}

		$d = strope_maybe_unserialize( $term->description );

		if ( ! is_array( $d ) || empty( $d['sync'][ $lang->slug ] ) || ! is_array( $d['sync'] ) ) {
			return false;
		}

		return count( array_keys( $d['sync'], $d['sync'][ $lang->slug ], true ) ) > 1;
	}

	/**
	 * Returns an empty status entry.
	 *
	 * @since 4.1.0
	 *
	 * @param string $lang Language slug.
	 * @return array
	 */
	protected static function empty_entry( $lang ) {
		return array(
			'lang'           => $lang,
			'status'         => self::STATUS_MISSING,
			'translation_id' => 0,
			'source_id'      => 0,
			'is_original'    => false,
			'synchronized'   => false,
			'needs_review'   => false,
			'review_method'  => '',
			'last_updated'   => 0,
		);
	}

	/**
	 * Builds the status entry of a translation.
	 *
	 * @since 4.1.0
	 *
	 * @param string $lang         Language slug.
	 * @param int    $id           Translation post ID, 0 if missing.
	 * @param int[]  $translations Translations group.
	 * @return array
	 */
	protected static function build_entry( $lang, $id, array $translations ) {
		$entry = self::empty_entry( $lang );
		$id    = (int) $id;

		if ( empty( $id ) ) {
			return $entry;
		}

		$entry['translation_id'] = $id;
		$entry['status']         = self::STATUS_UP_TO_DATE;
		$entry['source_id']      = self::get_source_id( $id, $translations );

		self::get_hash( $id ); // Lazy backfill.

		$review = get_post_meta( $id, self::META_REVIEW, true );
		if ( is_array( $review ) && ! empty( $review['method'] ) ) {
			$entry['needs_review']  = true;
			$entry['review_method'] = (string) $review['method'];
		}

		$meta = get_post_meta( $id, self::META_SOURCE, true );
		if ( is_array( $meta ) && ! empty( $meta['time'] ) ) {
			$entry['last_updated'] = (int) $meta['time'];
		}

		if ( self::is_synchronized( $id ) ) {
			$entry['synchronized'] = true;
			$entry['needs_review'] = false;
			$entry['review_method'] = '';
			return $entry;
		}

		if ( empty( $entry['source_id'] ) ) {
			$entry['is_original'] = true;
			return $entry;
		}

		// No baseline for this source: legacy content, considered up to date.
		if ( is_array( $meta ) && isset( $meta['id'], $meta['hash'] ) && (int) $meta['id'] === $entry['source_id'] ) {
			if ( (string) $meta['hash'] !== self::get_hash( $entry['source_id'] ) ) {
				$entry['status'] = self::STATUS_OUTDATED;
			}
		}

		return $entry;
	}

	/**
	 * Returns the translation status of a post for all languages, or for one language.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Optional language slug.
	 * @return array|null Array of entries with language slugs as keys, or one entry if `$lang` is provided.
	 *                    Null if the post isn't tracked or the language doesn't exist.
	 *                    Entry keys: lang, status (missing|up_to_date|outdated), translation_id, source_id,
	 *                    is_original, synchronized, needs_review, review_method, last_updated (timestamp).
	 */
	public static function get_status( $post_id, $lang = '' ) {
		$model = self::model();

		if ( empty( $model ) || ! self::is_tracked_post( $post_id, true ) ) {
			return null;
		}

		$translations = self::get_translations( $post_id );
		$post_lang    = $model->post->get_language( (int) $post_id );

		if ( $post_lang && empty( $translations[ $post_lang->slug ] ) ) {
			$translations[ $post_lang->slug ] = (int) $post_id;
		}

		$return = array();

		foreach ( $model->get_languages_list() as $language ) {
			if ( '' !== $lang && $language->slug !== $lang ) {
				continue;
			}

			$return[ $language->slug ] = self::build_entry( $language->slug, isset( $translations[ $language->slug ] ) ? $translations[ $language->slug ] : 0, $translations );
		}

		if ( '' !== $lang ) {
			return isset( $return[ $lang ] ) ? $return[ $lang ] : null;
		}

		return $return;
	}

	/**
	 * Returns the status entry of a post in its own language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_post_entry( $post_id ) {
		$model = self::model();
		$lang  = $model ? $model->post->get_language( (int) $post_id ) : false;

		return $lang ? self::get_status( $post_id, $lang->slug ) : null;
	}

	/**
	 * Returns the persisted state (as used by list filters and counts) of posts.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $ids Post IDs.
	 * @return array[]
	 */
	protected static function persisted_state( array $ids ) {
		$state = array();

		foreach ( $ids as $id ) {
			$state[ $id ] = array(
				'status'       => metadata_exists( 'post', $id, self::META_OUTDATED ) ? self::STATUS_OUTDATED : self::STATUS_UP_TO_DATE,
				'needs_review' => metadata_exists( 'post', $id, self::META_REVIEW ),
			);
		}

		return $state;
	}

	/**
	 * Runs a modification and keeps the denormalized flags of the translation group up to date.
	 * Fires `strope_translation_status_changed` for each post whose status or review flag changed.
	 *
	 * @since 4.1.0
	 *
	 * @param int           $post_id  Post ID.
	 * @param callable|null $mutation Modification to run.
	 * @return void
	 */
	public static function transaction( $post_id, $mutation = null ) {
		$post_id = (int) $post_id;
		$before  = self::persisted_state( array_unique( array_merge( array( $post_id ), array_values( self::get_translations( $post_id ) ) ) ) );

		if ( is_callable( $mutation ) ) {
			call_user_func( $mutation );
		}

		$ids = array_unique( array_merge( array_keys( $before ), array_values( self::get_translations( $post_id ) ) ) );
		self::refresh_flags( $ids );
		$after = self::persisted_state( $ids );

		foreach ( $after as $id => $state ) {
			$old = isset( $before[ $id ] ) ? $before[ $id ] : array( 'status' => self::STATUS_UP_TO_DATE, 'needs_review' => false );

			if ( $old === $state || ! get_post( $id ) ) {
				continue;
			}

			$entry = self::get_post_entry( $id );

			if ( empty( $entry ) ) {
				continue;
			}

			$old_entry                 = $entry;
			$old_entry['status']       = $old['status'];
			$old_entry['needs_review'] = $old['needs_review'];

			/**
			 * Fires when the translation status (up_to_date / outdated) or the review flag of a post changed.
			 *
			 * @since 4.1.0
			 *
			 * @param int   $id        The post ID.
			 * @param array $entry     The new status entry, see `Strope_Translation_Status::get_status()`.
			 * @param array $old_entry The previous status entry.
			 */
			do_action( 'strope_translation_status_changed', $id, $entry, $old_entry );
		}
	}

	/**
	 * Recomputes the denormalized flags of posts.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $ids Post IDs.
	 * @return void
	 */
	protected static function refresh_flags( array $ids ) {
		foreach ( $ids as $id ) {
			$id = (int) $id;

			if ( ! self::is_tracked_post( $id ) ) {
				continue;
			}

			$entry = self::get_post_entry( $id );

			if ( empty( $entry ) ) {
				continue;
			}

			if ( self::STATUS_OUTDATED === $entry['status'] ) {
				if ( (int) get_post_meta( $id, self::META_OUTDATED, true ) !== $entry['source_id'] ) {
					update_post_meta( $id, self::META_OUTDATED, $entry['source_id'] );
				}
			} elseif ( metadata_exists( 'post', $id, self::META_OUTDATED ) ) {
				delete_post_meta( $id, self::META_OUTDATED );
			}

			// Synchronized posts never need a review.
			if ( $entry['synchronized'] && metadata_exists( 'post', $id, self::META_REVIEW ) ) {
				delete_post_meta( $id, self::META_REVIEW );
			}
		}
	}

	/**
	 * Refreshes the denormalized flags of the translation group of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function refresh( $post_id ) {
		self::transaction( $post_id );
	}

	/**
	 * Stores the source of a translation (without refreshing flags).
	 *
	 * @since 4.1.0
	 *
	 * @param int         $post_id   Translation post ID.
	 * @param int         $source_id Source post ID.
	 * @param string|null $hash      Source hash to store, null to use the current hash of the source.
	 * @return void
	 */
	public static function set_source( $post_id, $source_id, $hash = null ) {
		$source_id = (int) $source_id;

		if ( empty( $source_id ) || (int) $post_id === $source_id ) {
			return;
		}

		update_post_meta(
			(int) $post_id,
			self::META_SOURCE,
			array(
				'id'   => $source_id,
				'hash' => null === $hash ? self::get_hash( $source_id ) : (string) $hash,
				'time' => time(),
				'user' => get_current_user_id(),
			)
		);
	}

	/**
	 * Marks a translation as up to date with its source.
	 * Does nothing (but returns true) for an original post.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function mark_up_to_date( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! self::is_tracked_post( $post_id ) ) {
			return new WP_Error( 'strope_translation_status_invalid_post', __( 'Invalid post.', 'strope-translate' ) );
		}

		self::transaction(
			$post_id,
			function () use ( $post_id ) {
				$source_id = self::get_source_id( $post_id );

				if ( $source_id ) {
					self::set_source( $post_id, $source_id );
				}
			}
		);

		return true;
	}

	/**
	 * Marks a translation as needing an update (outdated) until it's updated again.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function mark_needs_update( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! self::is_tracked_post( $post_id ) ) {
			return new WP_Error( 'strope_translation_status_invalid_post', __( 'Invalid post.', 'strope-translate' ) );
		}

		$source_id = self::get_source_id( $post_id );

		if ( empty( $source_id ) || self::is_synchronized( $post_id ) ) {
			return new WP_Error( 'strope_translation_status_no_source', __( 'This post is not a translation of another post, it cannot be outdated.', 'strope-translate' ) );
		}

		self::transaction(
			$post_id,
			function () use ( $post_id, $source_id ) {
				self::set_source( $post_id, $source_id, '' );
			}
		);

		return true;
	}

	/**
	 * Alias of `mark_needs_update()`.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function mark_outdated( $post_id ) {
		return self::mark_needs_update( $post_id );
	}

	/**
	 * Flags a post as needing a review (e.g. content created by duplication or by an automated tool).
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $method  How the content was created, e.g. 'duplicate'.
	 * @param bool   $pending True if the flag must survive the next user save (the one creating the post in the editor).
	 * @return true|WP_Error
	 */
	public static function mark_needs_review( $post_id, $method = 'manual', $pending = false ) {
		$post_id = (int) $post_id;

		if ( ! self::is_tracked_post( $post_id, true ) ) {
			return new WP_Error( 'strope_translation_status_invalid_post', __( 'Invalid post.', 'strope-translate' ) );
		}

		self::transaction(
			$post_id,
			function () use ( $post_id, $method, $pending ) {
				update_post_meta(
					$post_id,
					self::META_REVIEW,
					array(
						'method'  => sanitize_key( $method ),
						'time'    => time(),
						'user'    => get_current_user_id(),
						'pending' => (bool) $pending,
					)
				);
			}
		);

		return true;
	}

	/**
	 * Marks a post as reviewed (clears the needs review flag).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	public static function mark_reviewed( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! self::is_tracked_post( $post_id, true ) ) {
			return new WP_Error( 'strope_translation_status_invalid_post', __( 'Invalid post.', 'strope-translate' ) );
		}

		self::transaction(
			$post_id,
			function () use ( $post_id ) {
				delete_post_meta( $post_id, self::META_REVIEW );
			}
		);

		return true;
	}

	/**
	 * Handles a save made by a user in an editor: the translation becomes up to date and
	 * the review flag is cleared (or its `pending` state consumed).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function user_saved( $post_id ) {
		$post_id = (int) $post_id;

		if ( ! self::is_tracked_post( $post_id ) ) {
			return;
		}

		self::transaction(
			$post_id,
			function () use ( $post_id ) {
				$source_id = self::get_source_id( $post_id );

				if ( $source_id ) {
					self::set_source( $post_id, $source_id );
				}

				$review = get_post_meta( $post_id, self::META_REVIEW, true );

				if ( is_array( $review ) && ! empty( $review['pending'] ) ) {
					$review['pending'] = false;
					update_post_meta( $post_id, self::META_REVIEW, $review );
				} elseif ( metadata_exists( 'post', $post_id, self::META_REVIEW ) ) {
					delete_post_meta( $post_id, self::META_REVIEW );
				}
			}
		);
	}

	/**
	 * Primes the caches (posts, metas, language and translation terms) of a list of posts and all their translations,
	 * to avoid N+1 queries when displaying the status of many posts.
	 *
	 * @since 4.1.0
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	public static function prime_caches( array $post_ids ) {
		$post_ids = array_filter( array_map( 'intval', $post_ids ) );

		if ( empty( $post_ids ) ) {
			return;
		}

		$post_types = self::get_post_types();

		if ( empty( $post_types ) ) {
			return;
		}

		update_object_term_cache( $post_ids, $post_types );

		$all = $post_ids;
		foreach ( $post_ids as $id ) {
			$all = array_merge( $all, array_values( self::get_translations( $id ) ) );
		}
		$all = array_values( array_unique( array_map( 'intval', $all ) ) );

		_prime_post_caches( $all, false, false );
		update_object_term_cache( $all, $post_types );
		update_meta_cache( 'post', $all );
	}

	/**
	 * Returns the post statuses taken into account in counts.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	public static function get_counted_post_statuses() {
		/**
		 * Filters the post statuses taken into account in the translation status summary.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $statuses Post statuses.
		 */
		return (array) apply_filters( 'strope_translation_status_counted_post_statuses', array( 'publish', 'future', 'draft', 'pending', 'private' ) );
	}

	/**
	 * Returns a SQL condition (to use in a WHERE clause) selecting posts which have no translation in a language.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language $language Language.
	 * @param string          $column   Post ID column, e.g. "wp_posts.ID".
	 * @return string
	 */
	public static function get_missing_sql( Strope_Language $language, $column ) {
		global $wpdb;

		$tt_id = (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' );

		return "{$column} NOT IN ( SELECT strope_ts_l.object_id FROM {$wpdb->term_relationships} AS strope_ts_l WHERE strope_ts_l.term_taxonomy_id = {$tt_id} )"
			. " AND {$column} NOT IN ("
			. " SELECT strope_ts_g.object_id FROM {$wpdb->term_relationships} AS strope_ts_g"
			. " INNER JOIN {$wpdb->term_taxonomy} AS strope_ts_gt ON strope_ts_gt.term_taxonomy_id = strope_ts_g.term_taxonomy_id AND strope_ts_gt.taxonomy = 'post_translations'"
			. " INNER JOIN {$wpdb->term_relationships} AS strope_ts_m ON strope_ts_m.term_taxonomy_id = strope_ts_g.term_taxonomy_id"
			. " INNER JOIN {$wpdb->term_relationships} AS strope_ts_ml ON strope_ts_ml.object_id = strope_ts_m.object_id AND strope_ts_ml.term_taxonomy_id = {$tt_id}"
			. ' )';
	}

	/**
	 * Returns a summary of the translation status of a post type.
	 *
	 * @since 4.1.0
	 *
	 * @param string $post_type Post type name.
	 * @return array {
	 *     @type int   $items     Number of distinct contents (translation groups, or posts without translations).
	 *     @type array $languages Per language slug: translated, missing, coverage (percent, float), outdated, needs_review.
	 * }
	 */
	public static function get_summary( $post_type ) {
		global $wpdb;

		$model  = self::model();
		$return = array(
			'items'     => 0,
			'languages' => array(),
		);

		if ( empty( $model ) || ! self::is_tracked_post_type( $post_type ) ) {
			return $return;
		}

		$languages = $model->get_languages_list();
		$tt_ids    = array();

		foreach ( $languages as $language ) {
			$tt_ids[ (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' ) ] = $language->slug;
		}

		if ( empty( $tt_ids ) ) {
			return $return;
		}

		$statuses     = self::get_counted_post_statuses();
		$status_in    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$tt_ids_in    = implode( ', ', array_map( 'absint', array_keys( $tt_ids ) ) );
		$cache_key    = md5( $post_type . '|' . implode( ',', $statuses ) . '|' . $tt_ids_in );
		$last_changed = wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'terms' ); // Post metas changes also update the 'posts' last changed.
		$cached       = wp_cache_get( $cache_key, 'strope_translation_status' );

		if ( is_array( $cached ) && isset( $cached['last_changed'], $cached['data'] ) && $cached['last_changed'] === $last_changed ) {
			return $cached['data'];
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$items = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT COALESCE( CONCAT( 'g', strope_ts_gt.term_taxonomy_id ), CONCAT( 'p', p.ID ) ) )
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->term_relationships} AS strope_ts_l ON strope_ts_l.object_id = p.ID AND strope_ts_l.term_taxonomy_id IN ( {$tt_ids_in} )
				LEFT JOIN ( {$wpdb->term_relationships} AS strope_ts_g
					INNER JOIN {$wpdb->term_taxonomy} AS strope_ts_gt ON strope_ts_gt.term_taxonomy_id = strope_ts_g.term_taxonomy_id AND strope_ts_gt.taxonomy = 'post_translations'
				) ON strope_ts_g.object_id = p.ID
				WHERE p.post_type = %s AND p.post_status IN ( {$status_in} )",
				array_merge( array( $post_type ), $statuses )
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT strope_ts_l.term_taxonomy_id AS tt_id, COUNT( DISTINCT p.ID ) AS translated,
					COUNT( DISTINCT strope_ts_o.post_id ) AS outdated, COUNT( DISTINCT strope_ts_r.post_id ) AS needs_review
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->term_relationships} AS strope_ts_l ON strope_ts_l.object_id = p.ID AND strope_ts_l.term_taxonomy_id IN ( {$tt_ids_in} )
				LEFT JOIN {$wpdb->postmeta} AS strope_ts_o ON strope_ts_o.post_id = p.ID AND strope_ts_o.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} AS strope_ts_r ON strope_ts_r.post_id = p.ID AND strope_ts_r.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ( {$status_in} )
				GROUP BY strope_ts_l.term_taxonomy_id",
				array_merge( array( self::META_OUTDATED, self::META_REVIEW, $post_type ), $statuses )
			)
		);
		// phpcs:enable

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->tt_id ] = $row;
		}

		$return['items'] = $items;

		foreach ( $tt_ids as $tt_id => $slug ) {
			$translated = isset( $counts[ $tt_id ] ) ? (int) $counts[ $tt_id ]->translated : 0;

			$return['languages'][ $slug ] = array(
				'translated'   => $translated,
				'missing'      => max( 0, $items - $translated ),
				'coverage'     => $items > 0 ? round( min( 100, 100 * $translated / $items ), 1 ) : 100.0,
				'outdated'     => isset( $counts[ $tt_id ] ) ? (int) $counts[ $tt_id ]->outdated : 0,
				'needs_review' => isset( $counts[ $tt_id ] ) ? (int) $counts[ $tt_id ]->needs_review : 0,
			);
		}

		wp_cache_set(
			$cache_key,
			array(
				'last_changed' => $last_changed,
				'data'         => $return,
			),
			'strope_translation_status',
			HOUR_IN_SECONDS
		);

		return $return;
	}

	/**
	 * Returns the words to translate of a post type, per language: the words of the contents without a
	 * translation in the language, and of the contents whose translation is outdated.
	 *
	 * The words of a content are the words of its post in the default language, or of its oldest post when it
	 * has none. Posts not counted within the time limit are counted in the background, so the totals can
	 * be too low until then.
	 *
	 * @since 4.3.0
	 *
	 * @param string     $post_type Post type name.
	 * @param float|null $budget    Time limit to count posts, in seconds, null for no limit.
	 * @return array {
	 *     @type array $languages Per language slug: `missing` and `outdated` words.
	 *     @type bool  $complete  Whether all the posts are counted.
	 * }
	 */
	public static function get_words( $post_type, $budget = 1.5 ) {
		global $wpdb;

		$model  = self::model();
		$return = array(
			'languages' => array(),
			'complete'  => true,
		);

		if ( empty( $model ) || ! self::is_tracked_post_type( $post_type ) ) {
			return $return;
		}

		$tt_ids = array();
		foreach ( $model->get_languages_list() as $language ) {
			$tt_ids[ (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' ) ] = $language->slug;
		}

		if ( empty( $tt_ids ) ) {
			return $return;
		}

		$statuses  = self::get_counted_post_statuses();
		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$tt_ids_in = implode( ', ', array_map( 'absint', array_keys( $tt_ids ) ) );

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, strope_ts_l.term_taxonomy_id AS tt_id, strope_ts_g.term_taxonomy_id AS group_id, strope_ts_o.meta_id AS outdated
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->term_relationships} AS strope_ts_l ON strope_ts_l.object_id = p.ID AND strope_ts_l.term_taxonomy_id IN ( {$tt_ids_in} )
				LEFT JOIN ( {$wpdb->term_relationships} AS strope_ts_g
					INNER JOIN {$wpdb->term_taxonomy} AS strope_ts_gt ON strope_ts_gt.term_taxonomy_id = strope_ts_g.term_taxonomy_id AND strope_ts_gt.taxonomy = 'post_translations'
				) ON strope_ts_g.object_id = p.ID
				LEFT JOIN {$wpdb->postmeta} AS strope_ts_o ON strope_ts_o.post_id = p.ID AND strope_ts_o.meta_key = %s
				WHERE p.post_type = %s AND p.post_status IN ( {$status_in} )
				ORDER BY p.ID ASC",
				array_merge( array( self::META_OUTDATED, $post_type ), $statuses )
			)
		);
		// phpcs:enable

		// Contents: their posts by language, and whether each translation is outdated.
		$groups = array();
		foreach ( (array) $rows as $row ) {
			$key  = $row->group_id ? 'g' . $row->group_id : 'p' . $row->ID;
			$slug = $tt_ids[ (int) $row->tt_id ];

			if ( ! isset( $groups[ $key ]['posts'][ $slug ] ) ) {
				$groups[ $key ]['posts'][ $slug ] = (int) $row->ID;
			}
			if ( $row->outdated ) {
				$groups[ $key ]['outdated'][ $slug ] = true;
			}
		}

		$default = $model->get_default_language();
		$sources = array();
		foreach ( $groups as $key => $group ) {
			$sources[ $key ] = $default && isset( $group['posts'][ $default->slug ] ) ? $group['posts'][ $default->slug ] : min( $group['posts'] );
		}

		$counts = Strope_Word_Count::get_posts_words( array_values( $sources ), $budget );

		foreach ( $tt_ids as $slug ) {
			$return['languages'][ $slug ] = array(
				'missing'  => 0,
				'outdated' => 0,
			);
		}

		foreach ( $groups as $key => $group ) {
			$words = $counts['words'][ $sources[ $key ] ] ?? 0;

			foreach ( $tt_ids as $slug ) {
				if ( ! isset( $group['posts'][ $slug ] ) ) {
					$return['languages'][ $slug ]['missing'] += $words;
				} elseif ( ! empty( $group['outdated'][ $slug ] ) && $group['posts'][ $slug ] !== $sources[ $key ] ) {
					$return['languages'][ $slug ]['outdated'] += $words;
				}
			}
		}

		$return['complete'] = empty( $counts['pending'] );

		return $return;
	}

	/**
	 * Returns a human readable label for a status.
	 *
	 * @since 4.1.0
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function get_status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_MISSING:
				return __( 'Missing', 'strope-translate' );
			case self::STATUS_OUTDATED:
				return __( 'Outdated', 'strope-translate' );
			default:
				return __( 'Up to date', 'strope-translate' );
		}
	}
}
