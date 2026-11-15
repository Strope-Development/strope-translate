<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * It is best practice that plugins do nothing before `plugins_loaded` is fired.
 * So it is what Strope Translate intends to do.
 * But some plugins load their textdomain as soon as loaded, thus before `plugins_loaded` is fired.
 * This class defers textdomain loading until the language is defined either in a `plugins_loaded` action
 * or in a `wp` action (when the language is set from content on frontend).
 *
 * @since 1.2
 */
class Strope_OLT_Manager {
	/**
	 * Singleton instance
	 *
	 * @var Strope_OLT_Manager|null
	 */
	protected static $instance;

	/**
	 * Constructor: setups relevant filters.
	 *
	 * @since 1.2
	 */
	public function __construct() {
		// Allows Strope Translate to be the first plugin loaded ;-)
		add_filter( 'pre_update_option_active_plugins', array( $this, 'make_strope_translate_first' ) );
		add_filter( 'pre_update_option_active_sitewide_plugins', array( $this, 'make_strope_translate_first' ) );

		// Overriding load text domain only on front since WP 4.7.
		if ( ( is_admin() && ! Strope_Translate::is_ajax_on_front() ) || Strope_Translate::is_rest_request() ) {
			return;
		}

		// Filters for text domain management.
		add_filter( 'load_textdomain_mofile', '__return_empty_string' );

		// Loads text domains.
		add_action( 'strope_language_defined', array( $this, 'load_textdomains' ), 2 ); // After Strope_Frontend::strope_language_defined.
		add_action( 'strope_no_language_defined', array( $this, 'load_textdomains' ) );
	}

	/**
	 * Access to the single instance of the class
	 *
	 * @since 1.7
	 *
	 * @return Strope_OLT_Manager
	 */
	public static function instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Loads textdomains.
	 *
	 * @since 0.1
	 * @since 4.0.0 Relies on the WordPress just in time translations loading instead of overriding each textdomain.
	 *
	 * @return void
	 */
	public function load_textdomains() {
		// Our load_textdomain_mofile filter has done its job. let's remove it to enable translation.
		remove_filter( 'load_textdomain_mofile', '__return_empty_string' );

		$GLOBALS['l10n'] = array();
		$new_locale      = get_locale();

		load_default_textdomain( $new_locale );

		// Act only if the language has not been set early (before default textdomain loading and $wp_locale creation).
		if ( ! empty( $GLOBALS['wp_locale'] ) ) {
			// Reinitializes wp_locale for weekdays and months.
			unset( $GLOBALS['wp_locale'] );
			$GLOBALS['wp_locale'] = new WP_Locale();
		}

		if ( ! empty( $GLOBALS['wp_locale_switcher'] ) ) {
			/** This action is documented in wp-includes/class-wp-locale-switcher.php */
			do_action( 'change_locale', $new_locale );
		}

		do_action_deprecated( 'strope_translate_labels', array(), '4.0.0', 'change_locale' );
	}

	/**
	 * Allows Strope Translate to be the first plugin loaded ;-).
	 *
	 * @since 1.2
	 *
	 * @param string[] $plugins List of active plugins.
	 * @return string[] List of active plugins.
	 */
	public function make_strope_translate_first( $plugins ) {
		if ( $key = array_search( STROPE_TRANSLATE_BASENAME, $plugins ) ) {
			unset( $plugins[ $key ] );
			array_unshift( $plugins, STROPE_TRANSLATE_BASENAME );
		}
		return $plugins;
	}
}
