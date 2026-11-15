<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main class that handles the translation of the templates in full site editing.
 *
 * @since 3.2
 */
class Strope_FSE_Tools {

	/**
	 * Returns the name of the template post types that are translated by Strope Translate.
	 *
	 * @since 3.2
	 *
	 * @return string[] Array keys and array values are identical.
	 */
	public static function get_template_post_types() {
		return array(
			'wp_template_part' => 'wp_template_part',
		);
	}

	/**
	 * Tells if the given post type is a template post type that is translated by Strope Translate.
	 *
	 * @since 3.2
	 *
	 * @param string $post_type A post type name.
	 * @return bool
	 */
	public static function is_template_post_type( $post_type ) {
		return is_string( $post_type ) && in_array( $post_type, self::get_template_post_types(), true );
	}

	/**
	 * Tells if the query is a template request that is translated by Strope Translate.
	 *
	 * @since 3.2
	 *
	 * @param WP_Query $query Instance of `WP_Query`.
	 * @return bool
	 */
	public static function is_template_query( WP_Query $query ) {
		if ( empty( $query->query_vars['post_type'] ) ) {
			// No post types specified.
			return false;
		}

		if ( is_string( $query->query_vars['post_type'] ) ) {
			$post_type = $query->query_vars['post_type'];
		} elseif ( is_array( $query->query_vars['post_type'] ) && 1 === count( $query->query_vars['post_type'] ) ) {
			$post_type = reset( $query->query_vars['post_type'] );
		} else {
			// Multiple post types.
			return false;
		}

		if ( ! self::is_template_post_type( $post_type ) ) {
			// Not a translated template request.
			return false;
		}

		return true;
	}

	/**
	 * Returns the template post object currently being edited.
	 *
	 * @since 3.2
	 *
	 * @global WP_Post $post
	 *
	 * @return WP_Post|null
	 */
	public static function get_template_post() {
		global $post;

		if ( ! empty( $post ) && $post instanceof WP_Post && self::is_template_post_type( $post->post_type ) ) {
			return $post;
		}

		$request = self::get_site_editor_request();

		if ( empty( $request['post_id'] ) || empty( $request['post_type'] ) ) {
			return null;
		}

		$template_id = $request['post_id'];

		if ( ! preg_match( '@^.+//[a-zA-Z0-9_-]+$@', $template_id ) ) {
			return null;
		}

		$template_type = $request['post_type'];

		if ( ! self::is_template_post_type( $template_type ) ) {
			return null;
		}

		return self::get_post_from_template_id( $template_id, $template_type );
	}

	/**
	 * Returns a post associated with the given template ID from the database.
	 *
	 * @since 3.2
	 *
	 * @param string $template_id   Template ID, in the form of `{themeSlug}//{templateSlug}`.
	 * @param string $template_type Template type, either 'wp_template' or 'wp_template_part'.
	 * @return WP_Post|null
	 */
	public static function get_post_from_template_id( $template_id, $template_type ) {
		$parts = self::get_template_id_components( $template_id );

		if ( empty( $parts ) ) {
			return null;
		}

		return self::query_template_post( $parts['name'], $parts['theme'], $template_type );
	}

	/**
	 * Converts a template ID into an array containing the theme name and the template name.
	 *
	 * @since 3.2
	 *
	 * @param string $template_id Template ID, in the form of `{themeSlug}//{templateSlug}`.
	 * @return string[] {
	 *     An array containing the theme name and the template name. An empty array if the template ID is invalid.
	 *
	 *     @type string $theme The theme name (slug).
	 *     @type string $name  The template name (slug).
	 * }
	 */
	public static function get_template_id_components( $template_id ) {
		$parts = explode( '//', $template_id, 2 );

		if ( count( $parts ) < 2 ) {
			// Missing template name.
			return array();
		}

		if ( empty( $parts[0] ) || empty( $parts[1] ) ) {
			// Invalid template name.
			return array();
		}

		return array(
			'theme' => $parts[0],
			'name'  => $parts[1],
		);
	}

	/**
	 * Returns a template post from the database.
	 *
	 * @since 3.2
	 *
	 * @param string $post_name  Post name (slug).
	 * @param string $theme_name Theme name (slug).
	 * @param string $post_type  Post type, either 'wp_template' or 'wp_template_part'.
	 * @return WP_Post|null
	 */
	public static function query_template_post( $post_name, $theme_name, $post_type ) {
		$template_query = new WP_Query(
			array(
				'post_name__in'  => (array) $post_name,
				'post_type'      => $post_type,
				'post_status'    => array( 'auto-draft', 'draft', 'publish', 'trash' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => $theme_name,
					),
				),
			)
		);

		if ( empty( $template_query->posts ) ) {
			return null;
		}

		return reset( $template_query->posts );
	}

	/**
	 * Returns the post type and the post ID requested in the site editor URL.
	 * Handles both the URL format introduced in WP 6.8 (`?p=/{post_type}/{post_id}`)
	 * and the legacy one (`?postType={post_type}&postId={post_id}`).
	 *
	 * @since 4.0.0
	 *
	 * @return string[] {
	 *     @type string $post_type The requested post type, empty string if none.
	 *     @type string $post_id   The requested post ID (or template ID), empty string if none.
	 * }
	 *
	 * @phpstan-return array{post_type: string, post_id: string}
	 */
	public static function get_site_editor_request() {
		$request = array(
			'post_type' => '',
			'post_id'   => '',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $_GET['p'] ) && is_string( $_GET['p'] ) ) {
			// WP 6.8+.
			if ( preg_match( '@^/([a-z0-9_-]+)(?:/(.+))?$@', wp_unslash( $_GET['p'] ), $matches ) ) {
				$request['post_type'] = sanitize_key( $matches[1] );
				$request['post_id']   = isset( $matches[2] ) ? $matches[2] : '';
			}
		} else {
			if ( ! empty( $_GET['postType'] ) && is_string( $_GET['postType'] ) ) {
				$request['post_type'] = sanitize_key( $_GET['postType'] );
			}
			if ( ! empty( $_GET['postId'] ) && is_string( $_GET['postId'] ) ) {
				$request['post_id'] = wp_unslash( $_GET['postId'] );
			}
		}
		// phpcs:enable

		return $request;
	}

	/**
	 * Tells if we're in the site editor.
	 *
	 * @since 3.2
	 *
	 * @global string $pagenow
	 *
	 * @return bool
	 */
	public static function is_site_editor() {
		return isset( $GLOBALS['pagenow'] ) && 'site-editor.php' === $GLOBALS['pagenow'];
	}

	/**
	 * Returns translatable post types supporting automatic translations deletion.
	 *
	 * @since 3.4.5
	 *
	 * @return string[] Array of post types.
	 */
	public static function get_translatable_post_types() {
		return array_merge(
			self::get_template_post_types(),
			array(
				'wp_block'      => 'wp_block',
				'wp_navigation' => 'wp_navigation',
			)
		);
	}
}
