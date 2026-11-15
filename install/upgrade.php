<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages Strope Translate upgrades
 *
 * @since 1.2
 */
class Strope_Upgrade {
	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	public $options;

	/**
	 * Constructor
	 *
	 * @since 1.2
	 *
	 * @param array $options Strope Translate options
	 */
	public function __construct( &$options ) {
		$this->options = &$options;
	}

	/**
	 * Check if upgrade is possible otherwise die to avoid activation
	 *
	 * @since 1.2
	 *
	 * @return void
	 */
	public function can_activate() {
		if ( ! $this->can_upgrade() ) {
			ob_start();
			$this->admin_notices(); // FIXME the error message is displayed two times
			die( ob_get_contents() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Upgrades if possible otherwise returns false to stop Strope Translate loading
	 *
	 * @since 1.2
	 *
	 * @return bool true if upgrade is possible, false otherwise
	 */
	public function upgrade() {
		if ( ! $this->can_upgrade() ) {
			add_action( 'all_admin_notices', array( $this, 'admin_notices' ) );
			return false;
		}

		add_action( 'admin_init', array( $this, '_upgrade' ) );
		return true;
	}


	/**
	 * Check if we the previous version is not too old
	 * Upgrades if OK
	 * /!\ never start any upgrade before admin_init as it is likely to conflict with some other plugins
	 *
	 * @since 1.2
	 *
	 * @return bool true if upgrade is possible, false otherwise
	 */
	public function can_upgrade() {
		// Don't manage upgrade from version < 1.8
		return version_compare( $this->options['version'], '1.8', '>=' );
	}

	/**
	 * Displays a notice when ugrading from a too old version
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function admin_notices() {
		printf(
			'<div class="error"><p>%s</p><p>%s</p></div>',
			esc_html__( 'Strope Translate has been deactivated because you upgraded from a too old version.', 'strope-translate' ),
			sprintf(
				/* translators: %1$s and %2$s are Strope Translate version numbers */
				esc_html__( 'Before upgrading to %2$s, please upgrade to %1$s.', 'strope-translate' ),
				'<strong>2.9</strong>',
				STROPE_TRANSLATE_VERSION // phpcs:ignore WordPress.Security.EscapeOutput
			)
		);
	}

	/**
	 * Upgrades the plugin depending on the previous version
	 *
	 * @since 1.2
	 *
	 * @return void
	 */
	public function _upgrade() {
		// Once, not on every request until an administrator visits the dashboard.
		delete_transient( 'strope_languages_list' );

		foreach ( array( '2.0.8', '2.1', '2.7', '3.4' ) as $version ) {
			if ( version_compare( $this->options['version'], $version, '<' ) ) {
				$method_to_call = array( $this, 'upgrade_' . str_replace( '.', '_', $version ) );
				if ( is_callable( $method_to_call ) ) {
					call_user_func( $method_to_call );
				}
			}
		}

		$this->options['previous_version'] = $this->options['version']; // Remember the previous version of Strope Translate since v1.7.7
		$this->options['version'] = STROPE_TRANSLATE_DATA_VERSION;
		update_option( 'strope_translate', $this->options );
	}

	/**
	 * Upgrades if the previous version is < 2.0.8
	 * Changes the user meta 'user_lang' to 'locale' to match WP 4.7 choice
	 *
	 * @since 2.0.8
	 *
	 * @return void
	 */
	protected function upgrade_2_0_8() {
		global $wpdb;
		$wpdb->update( $wpdb->usermeta, array( 'meta_key' => 'locale' ), array( 'meta_key' => 'user_lang' ) );
	}

	/**
	 * Upgrades if the previous version is < 2.1.
	 * Moves strings translations from strope_translate_mo post_content to post meta _strope_strings_translations.
	 *
	 * @since 2.1
	 *
	 * @return void
	 */
	protected function upgrade_2_1() {
		$posts = get_posts(
			array(
				'post_type'   => 'strope_translate_mo',
				'post_status' => 'any',
				'numberposts' => -1,
				'nopaging'    => true,
			)
		);

		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				$meta = get_post_meta( $post->ID, '_strope_strings_translations', true );

				if ( empty( $meta ) ) {
					$strings = strope_maybe_unserialize( $post->post_content );
					if ( is_array( $strings ) ) {
						update_post_meta( $post->ID, '_strope_strings_translations', $strings );
					}
				}
			}
		}
	}

	/**
	 * Upgrades if the previous version is < 2.7
	 * Replace numeric keys by hashes in WPML registered strings
	 * Dismiss the wizard notice for existing sites
	 *
	 * @since 2.7
	 *
	 * @return void
	 */
	protected function upgrade_2_7() {
		$strings = get_option( 'strope_translate_wpml_strings' );
		if ( is_array( $strings ) ) {
			$new_strings = array();

			foreach ( $strings as $string ) {
				$context = $string['context'];
				$name    = $string['name'];

				$key = md5( "$context | $name" );
				$new_strings[ $key ] = $string;
			}
			update_option( 'strope_translate_wpml_strings', $new_strings );
		}

		Strope_Admin_Notices::dismiss( 'wizard' );
	}

	/**
	 * Upgrades if the previous version is < 3.4.0.
	 *
	 * @since 3.4
	 *
	 * @return void
	 */
	protected function upgrade_3_4() {
		$this->migrate_locale_fallback_to_language_description();

		$this->migrate_strings_translations();
	}

	/**
	 * Moves strings translations from post meta to term meta _strope_strings_translations.
	 *
	 * @since 3.4
	 *
	 * @return void
	 */
	protected function migrate_strings_translations() {
		$posts = get_posts(
			array(
				'post_type' => 'strope_translate_mo',
				'post_status' => 'any',
				'numberposts' => -1,
				'nopaging' => true,
			)
		);

		if ( ! is_array( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {
			$meta = get_post_meta( $post->ID, '_strope_strings_translations', true );

			$term_id = (int) substr( $post->post_title, strlen( 'strope_translate_mo_' ) ); // The post title is 'strope_translate_mo_' followed by the language term id.

			// Do not delete post metas in case a user needs to rollback to Strope Translate < 3.4.

			if ( empty( $meta ) || ! is_array( $meta ) ) {
				continue;
			}

			update_term_meta( $term_id, '_strope_strings_translations', wp_slash( $meta ) );
		}
	}

	/**
	 * Migrate locale fallback to language term description.
	 *
	 * @since 3.4
	 *
	 * @return void
	 */
	protected function migrate_locale_fallback_to_language_description() {
		// Migrate locale fallbacks from term metas to language term description.
		$terms = get_terms(
			array(
				'taxonomy'   => 'language',
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'         => 'fallback',
						'compare_key' => 'EXISTS',
					),
				),
			)
		);

		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			$fallbacks = get_term_meta( $term->term_id, 'fallback', true );

			delete_term_meta( $term->term_id, 'fallback' );

			if ( empty( $fallbacks ) || ! is_array( $fallbacks ) ) {
				// Empty or invalid value, should not happen.
				continue;
			}

			$description = strope_maybe_unserialize( $term->description );
			$description = is_array( $description ) ? $description : array();

			$description['fallbacks'] = $fallbacks;
			/** @var string */
			$description = maybe_serialize( $description );

			wp_update_term( $term->term_id, 'language', array( 'description' => $description ) );
		}
	}
}
