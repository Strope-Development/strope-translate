/**
 * Hides the languages not assigned to the current user in the language selectors
 * (classic editor metabox, block editor sidebar, quick edit, bulk edit, terms forms).
 * The restriction itself is enforced server side.
 *
 * @package Strope-Translate
 */
( function () {
	'use strict';

	var data = window.strope_translator_languages;

	if ( ! data || ! Array.isArray( data.all ) || ! Array.isArray( data.allowed ) ) {
		return;
	}

	var selectors = [
		'select[name="post_lang_choice"]',
		'select[name="inline_lang_choice"]',
		'select[name="term_lang_choice"]',
		'select[name="strope_post_lang_choice"]',
		'select#strope_post_lang_choice',
	].join( ',' );

	/**
	 * Hides and disables the options of the languages not allowed.
	 * The options are not removed, as some selectors are rendered by React.
	 */
	function filterSelectors() {
		document.querySelectorAll( selectors ).forEach( function ( select ) {
			Array.prototype.forEach.call( select.options, function ( option ) {
				if ( -1 !== data.all.indexOf( option.value ) && -1 === data.allowed.indexOf( option.value ) && ! option.disabled ) {
					option.hidden = true;
					option.disabled = true;
				}
			} );
		} );
	}

	var scheduled = false;

	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			filterSelectors();
		} );
	}

	function init() {
		filterSelectors();
		new window.MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
