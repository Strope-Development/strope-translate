<?php
/**
 * Template tags and API functions for themes and plugins written for Polylang.
 * Each function is an alias of the Strope Translate function with the same name and the `strope_` prefix.
 * Only loaded when the legacy API module is active.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid

if ( ! function_exists( 'PLL' ) ) {
	function PLL() {
		return Strope();
	}
}

if ( ! function_exists( 'pll_the_languages' ) ) {
	function pll_the_languages( ...$args ) {
		return strope_the_languages( ...$args );
	}
}

if ( ! function_exists( 'pll_current_language' ) ) {
	function pll_current_language( ...$args ) {
		return strope_current_language( ...$args );
	}
}

if ( ! function_exists( 'pll_default_language' ) ) {
	function pll_default_language( ...$args ) {
		return strope_default_language( ...$args );
	}
}

if ( ! function_exists( 'pll_get_post' ) ) {
	function pll_get_post( ...$args ) {
		return strope_get_post( ...$args );
	}
}

if ( ! function_exists( 'pll_get_term' ) ) {
	function pll_get_term( ...$args ) {
		return strope_get_term( ...$args );
	}
}

if ( ! function_exists( 'pll_home_url' ) ) {
	function pll_home_url( ...$args ) {
		return strope_home_url( ...$args );
	}
}

if ( ! function_exists( 'pll_register_string' ) ) {
	function pll_register_string( ...$args ) {
		return strope_register_string( ...$args );
	}
}

if ( ! function_exists( 'pll__' ) ) {
	function pll__( ...$args ) {
		return strope__( ...$args );
	}
}

if ( ! function_exists( 'pll_esc_html__' ) ) {
	function pll_esc_html__( ...$args ) {
		return strope_esc_html__( ...$args );
	}
}

if ( ! function_exists( 'pll_esc_attr__' ) ) {
	function pll_esc_attr__( ...$args ) {
		return strope_esc_attr__( ...$args );
	}
}

if ( ! function_exists( 'pll_e' ) ) {
	function pll_e( ...$args ) {
		strope_e( ...$args );
	}
}

if ( ! function_exists( 'pll_esc_html_e' ) ) {
	function pll_esc_html_e( ...$args ) {
		strope_esc_html_e( ...$args );
	}
}

if ( ! function_exists( 'pll_esc_attr_e' ) ) {
	function pll_esc_attr_e( ...$args ) {
		strope_esc_attr_e( ...$args );
	}
}

if ( ! function_exists( 'pll_translate_string' ) ) {
	function pll_translate_string( ...$args ) {
		return strope_translate_string( ...$args );
	}
}

if ( ! function_exists( 'pll_is_translated_post_type' ) ) {
	function pll_is_translated_post_type( ...$args ) {
		return strope_is_translated_post_type( ...$args );
	}
}

if ( ! function_exists( 'pll_is_translated_taxonomy' ) ) {
	function pll_is_translated_taxonomy( ...$args ) {
		return strope_is_translated_taxonomy( ...$args );
	}
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( ...$args ) {
		return strope_languages_list( ...$args );
	}
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	function pll_set_post_language( ...$args ) {
		return strope_set_post_language( ...$args );
	}
}

if ( ! function_exists( 'pll_set_term_language' ) ) {
	function pll_set_term_language( ...$args ) {
		return strope_set_term_language( ...$args );
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	function pll_save_post_translations( ...$args ) {
		return strope_save_post_translations( ...$args );
	}
}

if ( ! function_exists( 'pll_save_term_translations' ) ) {
	function pll_save_term_translations( ...$args ) {
		return strope_save_term_translations( ...$args );
	}
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	function pll_get_post_language( ...$args ) {
		return strope_get_post_language( ...$args );
	}
}

if ( ! function_exists( 'pll_get_term_language' ) ) {
	function pll_get_term_language( ...$args ) {
		return strope_get_term_language( ...$args );
	}
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	function pll_get_post_translations( ...$args ) {
		return strope_get_post_translations( ...$args );
	}
}

if ( ! function_exists( 'pll_get_term_translations' ) ) {
	function pll_get_term_translations( ...$args ) {
		return strope_get_term_translations( ...$args );
	}
}

if ( ! function_exists( 'pll_count_posts' ) ) {
	function pll_count_posts( ...$args ) {
		return strope_count_posts( ...$args );
	}
}

if ( ! function_exists( 'pll_insert_post' ) ) {
	function pll_insert_post( ...$args ) {
		return strope_insert_post( ...$args );
	}
}

if ( ! function_exists( 'pll_insert_term' ) ) {
	function pll_insert_term( ...$args ) {
		return strope_insert_term( ...$args );
	}
}

if ( ! function_exists( 'pll_update_post' ) ) {
	function pll_update_post( ...$args ) {
		return strope_update_post( ...$args );
	}
}

if ( ! function_exists( 'pll_update_term' ) ) {
	function pll_update_term( ...$args ) {
		return strope_update_term( ...$args );
	}
}

// phpcs:enable
