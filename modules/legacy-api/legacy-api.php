<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets themes and plugins written for Polylang keep working with Strope Translate:
 * - Provides the `pll_*()` template tags and API functions, and `PLL()`.
 * - Makes the `PLL_*` class names and the `Polylang` class name aliases of the Strope Translate classes.
 * - Runs the callbacks hooked to `pll_*` actions and filters when the corresponding `strope_*` hooks are fired.
 * - Defines the constants commonly used to detect Polylang.
 *
 * @since 4.0.0
 */
class Strope_Legacy_API {
	/**
	 * API level reported to third party code checking the version.
	 *
	 * @var string
	 */
	const API_VERSION = '3.6.5';

	/**
	 * Classes checked with `instanceof` by third party code, aliased early because `instanceof` doesn't trigger the autoloader.
	 *
	 * @var string[]
	 */
	const EAGER_ALIASES = array(
		'Base',
		'Frontend',
		'Admin_Base',
		'Admin',
		'Settings',
		'REST_Request',
		'Model',
		'Admin_Model',
		'Language',
	);

	/**
	 * Legacy hooks already bridged.
	 *
	 * @var true[] Hook names as keys.
	 */
	private static $bridged = array();

	/**
	 * Loads the parts of the compatibility layer needed before the plugin is initialized:
	 * hooks, class aliases and constants.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function early_init() {
		$constants = array(
			'POLYLANG_VERSION'  => self::API_VERSION,
			'POLYLANG_BASENAME' => STROPE_TRANSLATE_BASENAME,
			'POLYLANG_FILE'     => STROPE_TRANSLATE_FILE,
			'POLYLANG_DIR'      => STROPE_TRANSLATE_DIR,
			'PLL_COOKIE'        => STROPE_COOKIE,
		);

		foreach ( $constants as $name => $value ) {
			strope_set_constant( $name, $value );
		}

		spl_autoload_register( array( self::class, 'autoload' ) );

		foreach ( self::EAGER_ALIASES as $name ) {
			self::autoload( 'PLL_' . $name );
		}
		self::autoload( 'Polylang' );

		// Callbacks may be added to legacy hooks by plugins, themes, or during `init`.
		self::bridge_hooks();
		add_action( 'after_setup_theme', array( self::class, 'bridge_hooks' ), PHP_INT_MAX );
		add_action( 'init', array( self::class, 'bridge_hooks' ), PHP_INT_MAX );
		add_action( 'wp_loaded', array( self::class, 'bridge_hooks' ), PHP_INT_MAX );
	}

	/**
	 * Loads the parts of the compatibility layer needing the Strope Translate object: API functions and global.
	 *
	 * @since 4.0.0
	 *
	 * @param Strope_Base $strope_translate The Strope Translate object.
	 * @return void
	 */
	public static function init( Strope_Base $strope_translate ) {
		require_once __DIR__ . '/functions.php';

		$GLOBALS['polylang'] = $strope_translate; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Autoloader making legacy class names aliases of the Strope Translate classes.
	 *
	 * @since 4.0.0
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		if ( 'Polylang' === $class ) {
			$target = 'Strope_Translate';
		} elseif ( 0 === strpos( $class, 'PLL_' ) ) {
			$target = 'Strope_' . substr( $class, 4 );
		} else {
			return;
		}

		if ( ! class_exists( $class, false ) && ! interface_exists( $class, false ) && ( class_exists( $target ) || interface_exists( $target ) || trait_exists( $target ) ) ) {
			class_alias( $target, $class );
		}
	}

	/**
	 * Makes the callbacks hooked to `pll_*` hooks run when the corresponding `strope_*` hooks are fired.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function bridge_hooks() {
		global $wp_filter;

		foreach ( array_keys( $wp_filter ) as $hook ) {
			if ( ! is_string( $hook ) || 0 !== strpos( $hook, 'pll_' ) || isset( self::$bridged[ $hook ] ) ) {
				continue;
			}

			self::$bridged[ $hook ] = true;

			add_filter(
				'strope_' . substr( $hook, 4 ),
				function ( ...$args ) use ( $hook ) {
					if ( empty( $args ) ) {
						do_action( $hook ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
						return null;
					}

					return apply_filters_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				},
				10,
				99
			);
		}
	}
}
