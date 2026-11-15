<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Word counts of the content to translate.
 *
 * The words of a post are the words of the texts sent to translators in an XLIFF export (title, content,
 * excerpt, translatable custom fields), without markup, shortcodes and links. Chinese and Japanese are
 * counted per character, as translation agencies do.
 *
 * The count of a post is cached in a protected post meta (never copied to the translations), until the
 * post is modified.
 *
 * @since 4.3.0
 */
class Strope_Word_Count {
	/**
	 * Post meta of the cached count: `version|post_modified_gmt|words`.
	 */
	const META = '_strope_word_count';

	/**
	 * Version of the counting rules: counts made with other rules are counted again.
	 */
	const VERSION = 1;

	/**
	 * Cron hook counting the posts in the background.
	 */
	const BACKFILL_HOOK = 'strope_word_count_backfill';

	/**
	 * Option: posts waiting to be counted in the background.
	 */
	const BACKFILL_OPTION = 'strope_word_count_queue';

	/**
	 * Counts the words of a text.
	 *
	 * @since 4.3.0
	 *
	 * @param string $text Text, possibly with markup.
	 * @return int
	 */
	public static function count( string $text ): int {
		if ( '' === trim( $text ) ) {
			return 0;
		}

		// A text which is only a url (e.g. the link of an image) is not translated.
		if ( preg_match( '#^\s*(?:https?:)?//\S+\s*$#i', $text ) ) {
			return 0;
		}

		$text = (string) preg_replace( '~<!--.*?-->|<(script|style|svg|math)\b.*?</\1\s*>~si', ' ', $text );
		$text = (string) preg_replace( '~<[^>]*>~', ' ', $text );
		$text = (string) preg_replace( '~\[\[?/?[A-Za-z][\w-]*(?:\s[^\]]*)?\]\]?~', ' ', $text ); // Shortcode tags.
		$text = (string) preg_replace( '~(?:https?:)?//[^\s<>"\']+~i', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Chinese and Japanese: one word per character.
		$ideographs = preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $text );
		$text       = (string) preg_replace( '/[\p{Han}\p{Hiragana}\p{Katakana}]/u', ' ', $text );

		// Words: letters and digits, with inner apostrophes, hyphens, dots and commas (it's, e-mail, 3.5, 1,000).
		$words = preg_match_all( '/[\p{L}\p{N}\p{M}]+(?:[\'’\-.,][\p{L}\p{N}\p{M}]+)*/u', $text );

		return (int) $ideographs + (int) $words;
	}

	/**
	 * Counts the words of the translatable texts of a post (not cached).
	 *
	 * @since 4.3.0
	 *
	 * @param WP_Post $post Post.
	 * @return int
	 */
	public static function count_post( WP_Post $post ): int {
		$model  = Strope()->model;
		$source = $model->post->get_language( $post->ID );

		if ( ! $source ) {
			$source = $model->get_default_language();
		}

		$target = null;
		foreach ( $model->get_languages_list() as $language ) {
			if ( ! $source || $language->slug !== $source->slug ) {
				$target = $language;
				break;
			}
		}

		if ( ! $source || ! $target ) {
			// A single language: the main fields only.
			return self::count( $post->post_title ) + self::count( $post->post_content ) + self::count( $post->post_excerpt );
		}

		$collector = new Strope_Word_Count_Collector( $source, $target );
		$exporter  = new Strope_Export_Posts( $model->post );

		$exporter->add_item( $collector, $post );

		$words = 0;
		foreach ( $collector->get() as $text ) {
			$words += self::count( $text );
		}

		/**
		 * Filters the number of words to translate of a post.
		 *
		 * @since 4.3.0
		 *
		 * @param int     $words Number of words.
		 * @param WP_Post $post  Post.
		 */
		return max( 0, (int) apply_filters( 'strope_post_word_count', $words, $post ) );
	}

	/**
	 * Returns the cache key of a post count.
	 *
	 * @since 4.3.0
	 *
	 * @param string $modified Modification date of the post (GMT).
	 * @return string
	 */
	protected static function cache_key( string $modified ): string {
		return self::VERSION . '|' . $modified;
	}

	/**
	 * Reads a cached count.
	 *
	 * @since 4.3.0
	 *
	 * @param mixed  $value    Meta value.
	 * @param string $modified Modification date of the post (GMT).
	 * @return int|null Null if absent or outdated.
	 */
	protected static function read_cache( $value, string $modified ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$pos = strrpos( $value, '|' );

		if ( false === $pos || substr( $value, 0, $pos ) !== self::cache_key( $modified ) ) {
			return null;
		}

		return (int) substr( $value, $pos + 1 );
	}

	/**
	 * Returns the number of words of a post, from the cache or counted (and cached).
	 *
	 * @since 4.3.0
	 *
	 * @param int|WP_Post $post Post.
	 * @return int
	 */
	public static function get_post_words( $post ): int {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		$words = self::read_cache( get_post_meta( $post->ID, self::META, true ), $post->post_modified_gmt );

		if ( null !== $words ) {
			return $words;
		}

		$words = self::count_post( $post );
		update_post_meta( $post->ID, self::META, self::cache_key( $post->post_modified_gmt ) . '|' . $words );

		return $words;
	}

	/**
	 * Returns the number of words of several posts, counting the posts without an up to date cache within a
	 * time limit. The posts left are counted in the background.
	 *
	 * @since 4.3.0
	 *
	 * @param int[]      $post_ids Post IDs.
	 * @param float|null $budget   Time limit to count posts, in seconds, null for no limit.
	 * @return array {
	 *     @type int[] $words   Words, keyed by post ID (only the counted posts).
	 *     @type int[] $pending Posts not counted yet.
	 * }
	 */
	public static function get_posts_words( array $post_ids, $budget = 1.5 ): array {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$words    = array();
		$pending  = array();

		foreach ( array_chunk( $post_ids, 500 ) as $chunk ) {
			$in = implode( ', ', $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_modified_gmt, m.meta_value FROM {$wpdb->posts} AS p LEFT JOIN {$wpdb->postmeta} AS m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.ID IN ( {$in} )", self::META ) );

			foreach ( (array) $rows as $row ) {
				$count = self::read_cache( $row->meta_value, (string) $row->post_modified_gmt );
				if ( null === $count ) {
					$pending[] = (int) $row->ID;
				} else {
					$words[ (int) $row->ID ] = $count;
				}
			}
		}

		$start = microtime( true );

		while ( $pending && ( null === $budget || microtime( true ) - $start < $budget ) ) {
			$post_id           = array_shift( $pending );
			$words[ $post_id ] = self::get_post_words( $post_id );
		}

		if ( $pending ) {
			self::schedule_backfill( $pending );
		}

		return array(
			'words'   => $words,
			'pending' => $pending,
		);
	}

	/**
	 * Adds posts to the background count.
	 *
	 * @since 4.3.0
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	public static function schedule_backfill( array $post_ids ) {
		$queue = get_option( self::BACKFILL_OPTION, array() );
		$queue = array_values( array_unique( array_merge( is_array( $queue ) ? array_map( 'intval', $queue ) : array(), array_map( 'intval', $post_ids ) ) ) );

		update_option( self::BACKFILL_OPTION, array_slice( $queue, 0, 50000 ), false );

		if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK );
		}
	}

	/**
	 * Counts posts in the background, about 20 seconds per run, then schedules the next run.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public static function backfill() {
		$queue = get_option( self::BACKFILL_OPTION, array() );
		$queue = is_array( $queue ) ? array_map( 'intval', $queue ) : array();
		$start = microtime( true );

		while ( $queue && microtime( true ) - $start < 20 ) {
			self::get_post_words( array_shift( $queue ) );
		}

		if ( $queue ) {
			update_option( self::BACKFILL_OPTION, $queue, false );
			wp_schedule_single_event( time() + 5, self::BACKFILL_HOOK );
		} else {
			delete_option( self::BACKFILL_OPTION );
		}
	}

	/**
	 * Registers the background count.
	 *
	 * @since 4.3.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::BACKFILL_HOOK, array( self::class, 'backfill' ) );
	}
}
