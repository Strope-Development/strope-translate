<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

// Default directory to store user data such as custom flags
if ( ! defined( 'STROPE_LOCAL_DIR' ) ) {
	define( 'STROPE_LOCAL_DIR', WP_CONTENT_DIR . '/strope-translate' );
}

// Includes local config file if exists
if ( is_readable( STROPE_LOCAL_DIR . '/strope-config.php' ) ) {
	include_once STROPE_LOCAL_DIR . '/strope-config.php';
}

/**
 * Controls the plugin, as well as activation, and deactivation
 *
 * @since 0.1
 *
 * @template TStropeClass of Strope_Base
 */
class Strope_Translate {

	/**
	 * Constructor
	 *
	 * @since 0.1
	 */
	public function __construct() {
		require_once __DIR__ . '/functions.php'; // VIP functions

		// register an action when plugin is activating.
		register_activation_hook( STROPE_TRANSLATE_BASENAME, array( 'Strope_Wizard', 'start_wizard' ) );

		$install = new Strope_Install( STROPE_TRANSLATE_BASENAME );

		// Stopping here if we are going to deactivate the plugin ( avoids breaking rewrite rules )
		if ( $install->is_deactivation() || ! $install->can_activate() ) {
			return;
		}

		// Data from a previous multilingual plugin must be imported before anything else is loaded.
		if ( Strope_Import_Polylang::is_pending() ) {
			Strope_Import_Polylang::load();
			return;
		}

		Strope_Capabilities::init();

		if ( is_admin() && isset( $_GET['strope_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			add_action( 'admin_notices', array( new Strope_Import_Polylang(), 'display_notice' ) ); // Import result.
		}

		// Plugin initialization
		// Take no action before all plugins are loaded
		add_action( 'plugins_loaded', array( $this, 'init' ), 1 );

		// Override load text domain waiting for the language to be defined
		// Here for plugins which load text domain as soon as loaded :(
		if ( ! defined( 'STROPE_OLT' ) || STROPE_OLT ) {
			Strope_OLT_Manager::instance();
		}

		/*
		 * Loads the compatibility with some plugins and themes.
		 * Loaded as soon as possible as we may need to act before other plugins are loaded.
		 */
		if ( ! defined( 'STROPE_PLUGINS_COMPAT' ) || STROPE_PLUGINS_COMPAT ) {
			Strope_Integrations::instance();
		}
	}

	/**
	 * Tells whether the current request is an ajax request on frontend or not
	 *
	 * @since 2.2
	 *
	 * @return bool
	 */
	public static function is_ajax_on_front() {
		// Special test for plupload which does not use jquery ajax and thus does not pass our ajax prefilter
		// Special test for customize_save done in frontend but for which we want to load the admin
		$in = isset( $_REQUEST['action'] ) && in_array( sanitize_key( $_REQUEST['action'] ), array( 'upload-attachment', 'customize_save' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$is_ajax_on_front = wp_doing_ajax() && empty( $_REQUEST['strope_ajax_backend'] ) && ! $in; // phpcs:ignore WordPress.Security.NonceVerification

		/**
		 * Filters whether the current request is an ajax request on front.
		 *
		 * @since 2.3
		 *
		 * @param bool $is_ajax_on_front Whether the current request is an ajax request on front.
		 */
		return apply_filters( 'strope_is_ajax_on_front', $is_ajax_on_front );
	}

	/**
	 * Is the current request a REST API request?
	 * Inspired by WP::parse_request()
	 * Needed because at this point, the constant REST_REQUEST is not defined yet
	 *
	 * @since 2.4.1
	 *
	 * @return bool
	 */
	public static function is_rest_request() {
		// Handle pretty permalinks.
		$home_path       = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );

		$req_uri = trim( (string) wp_parse_url( strope_get_requested_url(), PHP_URL_PATH ), '/' );
		$req_uri = (string) preg_replace( $home_path_regex, '', $req_uri );
		$req_uri = trim( $req_uri, '/' );
		$req_uri = str_replace( 'index.php', '', $req_uri );
		$req_uri = trim( $req_uri, '/' );

		// And also test rest_route query string parameter is not empty for plain permalinks.
		$query_string = array();
		wp_parse_str( (string) wp_parse_url( strope_get_requested_url(), PHP_URL_QUERY ), $query_string );
		$rest_route = isset( $query_string['rest_route'] ) && is_string( $query_string['rest_route'] ) ? trim( $query_string['rest_route'], '/' ) : false;

		return 0 === strpos( $req_uri, rest_get_url_prefix() . '/' ) || ! empty( $rest_route );
	}

	/**
	 * Tells if we are in the wizard process.
	 *
	 * @since 2.7
	 *
	 * @return bool
	 */
	public static function is_wizard() {
		return isset( $_GET['page'] ) && ! empty( $_GET['page'] ) && 'mlang_wizard' === sanitize_key( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Defines constants
	 * May be overridden by a plugin if set before plugins_loaded, 1
	 *
	 * @since 1.6
	 *
	 * @return void
	 */
	public static function define_constants() {
		// Cookie name. no cookie will be used if set to false
		if ( ! defined( 'STROPE_COOKIE' ) ) {
			define( 'STROPE_COOKIE', 'strope_language' );
		}

		// Backward compatibility with Strope Translate < 2.3
		if ( ! defined( 'STROPE_AJAX_ON_FRONT' ) ) {
			define( 'STROPE_AJAX_ON_FRONT', self::is_ajax_on_front() );
		}

		// Admin
		if ( ! defined( 'STROPE_ADMIN' ) ) {
			define( 'STROPE_ADMIN', wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( is_admin() && ! STROPE_AJAX_ON_FRONT ) );
		}

		// Settings page whatever the tab except for the wizard which needs to be an admin process.
		if ( ! defined( 'STROPE_SETTINGS' ) ) {
			define( 'STROPE_SETTINGS', is_admin() && ( ( isset( $_GET['page'] ) && 0 === strpos( sanitize_key( $_GET['page'] ), 'mlang' ) && ! self::is_wizard() ) || ! empty( $_REQUEST['strope_ajax_settings'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}

	/**
	 * Strope Translate initialization
	 * setups models and separate admin and frontend
	 *
	 * @since 1.2
	 *
	 * @return void
	 */
	public function init() {
		self::define_constants();
		$options = get_option( 'strope_translate' );

		// Plugin upgrade
		if ( $options && version_compare( $options['version'], STROPE_TRANSLATE_DATA_VERSION, '<' ) ) {
			$upgrade = new Strope_Upgrade( $options );
			if ( ! $upgrade->upgrade() ) { // If the version is too old
				return;
			}
		}

		// In some edge cases, it's possible that no options were found in the database. Load default options as we need some.
		if ( ! $options ) {
			$options = Strope_Install::get_default_options();
		}

		// Compatibility with code written for Polylang: must be ready before any hook is fired.
		if ( ! empty( $options['legacy_api'] ) ) {
			Strope_Legacy_API::early_init();
		}

		/**
		 * Filter the model class to use
		 * /!\ this filter is fired *before* the $strope_translate object is available
		 *
		 * @since 1.5
		 *
		 * @param string $class either Strope_Model or Strope_Admin_Model
		 */
		$class = apply_filters( 'strope_model', STROPE_SETTINGS || self::is_wizard() ? 'Strope_Admin_Model' : 'Strope_Model' );
		/** @var Strope_Model $model */
		$model = new $class( $options );

		if ( ! $model->has_languages() ) {
			/**
			 * Fires when no language has been defined yet
			 * Used to load overridden textdomains
			 *
			 * @since 1.2
			 */
			do_action( 'strope_no_language_defined' );
		}

		$class = '';

		if ( STROPE_SETTINGS ) {
			$class = 'Strope_Settings';
		} elseif ( STROPE_ADMIN ) {
			$class = 'Strope_Admin';
		} elseif ( self::is_rest_request() ) {
			$class = 'Strope_REST_Request';
		} elseif ( $model->has_languages() ) {
			$class = 'Strope_Frontend';
		}

		/**
		 * Filters the class to use to instantiate the $strope_translate object
		 *
		 * @since 2.6
		 *
		 * @param string $class A class name.
		 */
		$class = apply_filters( 'strope_context', $class );

		if ( ! empty( $class ) ) {
			/** @phpstan-var class-string<TStropeClass> $class */
			$this->init_context( $class, $model );
		}
	}

	/**
	 * Strope Translate initialization.
	 * Setups the Strope Translate Context, loads the modules and init Strope Translate.
	 *
	 * @since 3.6
	 *
	 * @param string    $class The class name.
	 * @param Strope_Model $model Instance of Strope_Model.
	 * @return Strope_Base
	 *
	 * @phpstan-param class-string<TStropeClass> $class
	 * @phpstan-return TStropeClass
	 */
	public function init_context( string $class, Strope_Model $model ): Strope_Base {
		global $strope_translate;

		$links_model = $model->get_links_model();
		$strope_translate    = new $class( $links_model );

		/**
		 * Fires after Strope Translate's model init.
		 * This is the best place to register a custom table (see `Strope_Model`'s constructor).
		 * /!\ This hook is fired *before* the $strope_translate object is available.
		 * /!\ The languages are also not available yet.
		 *
		 * @since 3.4
		 *
		 * @param Strope_Model $model Strope Translate model.
		 */
		do_action( 'strope_model_init', $model );

		$model->maybe_create_language_terms();

		/**
		 * Fires after the $strope_translate object is created and before the API is loaded
		 *
		 * @since 2.0
		 *
		 * @param object $strope_translate
		 */
		do_action_ref_array( 'strope_pre_init', array( &$strope_translate ) );

		// Loads the API
		require_once STROPE_TRANSLATE_DIR . '/include/api.php';

		// Loads the modules.
		$load_scripts = glob( STROPE_TRANSLATE_DIR . '/modules/*/load.php', GLOB_NOSORT );
		if ( is_array( $load_scripts ) ) {
			foreach ( $load_scripts as $load_script ) {
				require_once $load_script; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			}
		}

		$strope_translate->init();

		/**
		 * Fires after the $strope_translate object and the API is loaded
		 *
		 * @since 1.7
		 *
		 * @param object $strope_translate
		 */
		do_action_ref_array( 'strope_init', array( &$strope_translate ) );

		return $strope_translate;
	}
}
