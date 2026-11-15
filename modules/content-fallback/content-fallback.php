<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by the content fallback module (settings and frontend).
 *
 * The module lets untranslated content in the default language be displayed
 * in place of the missing translations, while the visitor stays in his language.
 *
 * @since 4.1.0
 */
class Strope_Content_Fallback {
	/**
	 * Key of the module settings in the plugin options.
	 *
	 * @var string
	 */
	const OPTION = 'content_fallback';

	/**
	 * Returns the module settings, merged with the defaults.
	 *
	 * @since 4.1.0
	 *
	 * @param array $options The plugin options.
	 * @return array {
	 *   @type string[] $post_types Post types displayed in the default language when not translated.
	 *   @type string[] $taxonomies Taxonomies displayed in the default language when not translated.
	 *   @type bool     $notice     Whether to display a notice above fallback content.
	 * }
	 */
	public static function get_settings( $options ) {
		$settings = isset( $options[ self::OPTION ] ) && is_array( $options[ self::OPTION ] ) ? $options[ self::OPTION ] : array();

		$settings = array_merge( self::get_defaults(), $settings );

		$settings['post_types'] = array_values( array_filter( (array) $settings['post_types'], 'is_string' ) );
		$settings['taxonomies'] = array_values( array_filter( (array) $settings['taxonomies'], 'is_string' ) );
		$settings['notice']     = ! empty( $settings['notice'] );

		return $settings;
	}

	/**
	 * Returns the default settings: fallback disabled for everything.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'post_types' => array(),
			'taxonomies' => array(),
			'notice'     => false,
		);
	}

	/**
	 * Tells whether the fallback is enabled for at least one post type or taxonomy.
	 *
	 * @since 4.1.0
	 *
	 * @param array $options The plugin options.
	 * @return bool
	 */
	public static function is_enabled( $options ) {
		$settings = self::get_settings( $options );
		return ! empty( $settings['post_types'] ) || ! empty( $settings['taxonomies'] );
	}

	/**
	 * Returns the translated post types which can use the fallback.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model $model The model.
	 * @return string[]
	 */
	public static function get_available_post_types( $model ) {
		$post_types = array();

		foreach ( $model->get_translated_post_types() as $post_type ) {
			if ( 'attachment' !== $post_type && post_type_exists( $post_type ) && is_post_type_viewable( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}

		return $post_types;
	}

	/**
	 * Returns a subquery selecting the posts in the default language which have a visible translation in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param int      $lang_tt_id    Term taxonomy id of the current language (`language` taxonomy).
	 * @param int      $default_tt_id Term taxonomy id of the default language (`language` taxonomy).
	 * @param string[] $statuses      Post statuses of a visible translation.
	 * @return string
	 */
	public static function get_translated_posts_subquery( $lang_tt_id, $default_tt_id, $statuses ) {
		global $wpdb;

		$lang_tt_id    = absint( $lang_tt_id );
		$default_tt_id = absint( $default_tt_id );
		$statuses      = "'" . implode( "','", esc_sql( array_map( 'sanitize_key', (array) $statuses ) ) ) . "'";

		return "SELECT strope_fb_d.object_id FROM {$wpdb->term_relationships} AS strope_fb_d
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_g ON strope_fb_g.object_id = strope_fb_d.object_id
			INNER JOIN {$wpdb->term_taxonomy} AS strope_fb_gt ON strope_fb_gt.term_taxonomy_id = strope_fb_g.term_taxonomy_id AND strope_fb_gt.taxonomy = 'post_translations'
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_t ON strope_fb_t.term_taxonomy_id = strope_fb_g.term_taxonomy_id
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_tl ON strope_fb_tl.object_id = strope_fb_t.object_id AND strope_fb_tl.term_taxonomy_id = {$lang_tt_id}
			INNER JOIN {$wpdb->posts} AS strope_fb_p ON strope_fb_p.ID = strope_fb_t.object_id AND strope_fb_p.post_status IN ( {$statuses} )
			WHERE strope_fb_d.term_taxonomy_id = {$default_tt_id}";
	}

	/**
	 * Returns a subquery selecting the terms in the default language which have a translation in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $lang_tt_id    Term taxonomy id of the current language (`term_language` taxonomy).
	 * @param int $default_tt_id Term taxonomy id of the default language (`term_language` taxonomy).
	 * @return string
	 */
	public static function get_translated_terms_subquery( $lang_tt_id, $default_tt_id ) {
		global $wpdb;

		$lang_tt_id    = absint( $lang_tt_id );
		$default_tt_id = absint( $default_tt_id );

		return "SELECT strope_fb_d.object_id FROM {$wpdb->term_relationships} AS strope_fb_d
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_g ON strope_fb_g.object_id = strope_fb_d.object_id
			INNER JOIN {$wpdb->term_taxonomy} AS strope_fb_gt ON strope_fb_gt.term_taxonomy_id = strope_fb_g.term_taxonomy_id AND strope_fb_gt.taxonomy = 'term_translations'
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_t ON strope_fb_t.term_taxonomy_id = strope_fb_g.term_taxonomy_id
			INNER JOIN {$wpdb->term_relationships} AS strope_fb_tl ON strope_fb_tl.object_id = strope_fb_t.object_id AND strope_fb_tl.term_taxonomy_id = {$lang_tt_id}
			WHERE strope_fb_d.term_taxonomy_id = {$default_tt_id}";
	}

	/**
	 * Returns the WHERE clause to append to a posts query already filtered by the current language
	 * *and* the default language, to remove the posts in the default language which must not be displayed.
	 *
	 * @since 4.1.0
	 *
	 * @param string $id_column   Column of the post ID, including the table alias.
	 * @param string $type_column Column of the post type, including the table alias.
	 * @param array  $args {
	 *   @type int      $lang_tt_id    Term taxonomy id of the current language.
	 *   @type int      $default_tt_id Term taxonomy id of the default language.
	 *   @type string[] $statuses      Post statuses of a visible translation.
	 *   @type string[] $post_types    Post types allowing the fallback, empty if all the queried post types allow it.
	 * }
	 * @return string
	 */
	public static function get_posts_exclusion_where( $id_column, $type_column, $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'lang_tt_id'    => 0,
				'default_tt_id' => 0,
				'statuses'      => array( 'publish' ),
				'post_types'    => array(),
			)
		);

		$subquery = self::get_translated_posts_subquery( $args['lang_tt_id'], $args['default_tt_id'], $args['statuses'] );
		$where    = " AND {$id_column} NOT IN ( {$subquery} )";

		if ( ! empty( $args['post_types'] ) ) {
			$post_types = "'" . implode( "','", esc_sql( array_map( 'sanitize_key', (array) $args['post_types'] ) ) ) . "'";
			$lang_tt_id = absint( $args['lang_tt_id'] );

			// Posts in the default language are allowed only for the post types using the fallback.
			$where .= " AND ( {$type_column} IN ( {$post_types} ) OR {$id_column} IN ( SELECT strope_fb_l.object_id FROM {$wpdb->term_relationships} AS strope_fb_l WHERE strope_fb_l.term_taxonomy_id = {$lang_tt_id} ) )";
		}

		return $where;
	}

	/**
	 * Returns a WHERE clause filtering a posts query joined with the language (`strope_tr` alias)
	 * by the current language with the fallback to the default language.
	 *
	 * @since 4.1.0
	 *
	 * @param string $id_column Column of the post ID, including the table alias.
	 * @param array  $args      See `get_posts_exclusion_where()`. The `post_types` argument is not used.
	 * @return string
	 */
	public static function get_posts_language_where( $id_column, $args ) {
		$lang_tt_id    = absint( $args['lang_tt_id'] );
		$default_tt_id = absint( $args['default_tt_id'] );
		$subquery      = self::get_translated_posts_subquery( $lang_tt_id, $default_tt_id, $args['statuses'] );

		return " AND ( strope_tr.term_taxonomy_id = {$lang_tt_id} OR ( strope_tr.term_taxonomy_id = {$default_tt_id} AND {$id_column} NOT IN ( {$subquery} ) ) )";
	}

	/**
	 * Returns the translated taxonomies which can use the fallback.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model $model The model.
	 * @return string[]
	 */
	public static function get_available_taxonomies( $model ) {
		$taxonomies = array();

		foreach ( $model->get_translated_taxonomies() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && is_taxonomy_viewable( $taxonomy ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}
}
