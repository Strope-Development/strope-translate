<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Container for 3rd party plugins ( and themes ) integrations.
 * This class is available as soon as the plugin is loaded.
 *
 * @since 1.0
 * @since 2.8 Renamed from Strope_Plugins_Compat to Strope_Integrations.
 */
#[AllowDynamicProperties]
class Strope_Integrations {
	/**
	 * Singleton instance.
	 *
	 * @var Strope_Integrations|null
	 */
	protected static $instance;

	/**
	 * Constructor.
	 *
	 * @since 1.0
	 */
	protected function __construct() {}

	/**
	 * Access to the single instance of the class.
	 *
	 * @since 1.7
	 *
	 * @return Strope_Integrations
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init(); // After the instance is stored, so integrations can safely access it while loading.
		}

		return self::$instance;
	}

	/**
	 * Requires integrations.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	protected function init() {
		// Loads external integrations.
		foreach ( glob( __DIR__ . '/*/load.php', GLOB_NOSORT ) as $load_script ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
			require_once $load_script; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		}
	}
}

class_alias( 'Strope_Integrations', 'Strope_Plugins_Compat' ); // For Backward compatibility.
