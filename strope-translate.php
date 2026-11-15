<?php
/**
 * Strope Translate
 *
 * @package           Strope-Translate
 * @author            Strope
 * @license           GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Strope Translate
 * Plugin URI:        https://strope.io/downloads/strope-translate/
 * Description:       Translate your whole site into as many languages as you like, with private machine translation in your browser.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Strope
 * Author URI:        https://strope.io/
 * Text Domain:       strope-translate
 * Domain Path:       /languages
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * Copyright 2011-2019 Frédéric Demarle
 * Copyright 2019-2026 WP SYNTEX
 * Copyright 2026 Strope
 *
 * Strope Translate is a fork of Polylang Pro and of the Polylang core it
 * includes (https://polylang.pro), with fixes from later Polylang releases.
 * Modified by Strope in 2026: rebranded, merged into a single edition,
 * removed licensing and remote update code, applied security hardening and
 * added new features. Third-party notices: see THIRD-PARTY-NOTICES.txt.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

if ( defined( 'STROPE_TRANSLATE_VERSION' ) ) {
	// Another instance of the plugin is already loaded: deactivate this one.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-includes/pluggable.php';
	if ( is_plugin_active( plugin_basename( __FILE__ ) ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) ); // Deactivate this plugin.
		// WP does not allow us to send a custom meaningful message, so just tell the plugin has been deactivated.
		wp_safe_redirect( add_query_arg( 'deactivate', 'true', remove_query_arg( 'activate' ) ) );
		exit;
	}
	return;
}

if ( defined( 'POLYLANG_VERSION' ) ) {
	// An incompatible plugin registering the same language taxonomies is active. Running both would corrupt the data.
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Strope Translate is inactive while Polylang is active, as both plugins use the same language data. Deactivate Polylang: Strope Translate will then offer to import its data.', 'strope-translate' )
				);
			}
		}
	);

	// Translators must not keep their editing rights while their languages restrictions are not enforced. The role is recreated when the plugin runs again.
	add_action(
		'init',
		function () {
			if ( get_role( 'strope_translator' ) ) {
				remove_role( 'strope_translator' );
			}
		}
	);
	return;
}

define( 'STROPE_TRANSLATE_VERSION', '1.0.0' );
/*
 * Version of the settings format stored in the `strope_translate` option. It continues the numbering of
 * Polylang, whose settings Strope Translate keeps and imports: the upgrade routines compare the stored value
 * with it. Change it only with a new upgrade routine, never to the release version.
 */
define( 'STROPE_TRANSLATE_DATA_VERSION', '4.3.0' );
define( 'STROPE_MIN_WP_VERSION', '6.5' );
define( 'STROPE_MIN_PHP_VERSION', '7.4' );

define( 'STROPE_TRANSLATE_FILE', __FILE__ );
define( 'STROPE_TRANSLATE_DIR', __DIR__ );
define( 'STROPE_TRANSLATE_BASENAME', plugin_basename( __FILE__ ) ); // Plugin name as known by WP.
define( 'STROPE_TRANSLATE', 'Strope Translate' );

/*
 * Translations shipped with the plugin. WordPress 6.8+ reads the "Domain Path" header itself, older versions
 * don't. Nothing is loaded here: the files are read when the first string is translated, and the language
 * packs of WordPress.org keep the priority.
 */
if ( isset( $GLOBALS['wp_textdomain_registry'] ) && $GLOBALS['wp_textdomain_registry'] instanceof WP_Textdomain_Registry ) {
	$GLOBALS['wp_textdomain_registry']->set_custom_path( 'strope-translate', __DIR__ . '/languages' );
}

require __DIR__ . '/vendor/autoload.php';

/*
 * The plugin is not loaded only for its own domains check (see Strope_Settings_Url::check_domains()),
 * which passes a short-lived random token stored in a transient right before the request.
 */
$strope_domain_check = isset( $_GET['deactivate-strope-translate'] ) && is_string( $_GET['deactivate-strope-translate'] ) ? $_GET['deactivate-strope-translate'] : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
$strope_domain_token = '' !== $strope_domain_check ? get_transient( 'strope_domain_check_token' ) : false;

if ( ! is_string( $strope_domain_token ) || strlen( $strope_domain_token ) < 32 || ! hash_equals( $strope_domain_token, $strope_domain_check ) ) {
	new Strope_Translate();
}

unset( $strope_domain_check, $strope_domain_token );
