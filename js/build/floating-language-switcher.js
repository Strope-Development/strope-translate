/**
 * Floating language switcher: dropdown behavior.
 * Without this script, the list of languages stays visible.
 *
 * @package Strope-Translate
 */
( function () {
	'use strict';

	/**
	 * Enhances a floating language switcher displayed as a dropdown.
	 *
	 * @param {HTMLElement} container The `<nav>` element.
	 */
	function enhance( container ) {
		const button = container.querySelector( '.strope-floating-switcher__toggle' );
		const list = container.querySelector( '.strope-floating-switcher__list' );

		if ( ! button || ! list ) {
			return;
		}

		const getLinks = function () {
			return Array.prototype.slice.call( list.querySelectorAll( 'a[href]' ) );
		};

		const isOpen = function () {
			return 'true' === button.getAttribute( 'aria-expanded' );
		};

		const focusLink = function ( index ) {
			const links = getLinks();
			if ( links.length ) {
				links[ ( index + links.length ) % links.length ].focus();
			}
		};

		const open = function () {
			list.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
		};

		const close = function ( restoreFocus ) {
			list.hidden = true;
			button.setAttribute( 'aria-expanded', 'false' );

			if ( restoreFocus ) {
				button.focus();
			}
		};

		container.classList.add( 'is-enhanced' );
		list.hidden = true;
		button.hidden = false;

		button.addEventListener( 'click', function () {
			if ( isOpen() ) {
				close( false );
			} else {
				open();
			}
		} );

		button.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				open();
				focusLink( 'ArrowDown' === event.key ? 0 : -1 );
			} else if ( 'Escape' === event.key && isOpen() ) {
				event.preventDefault();
				close( true );
			}
		} );

		list.addEventListener( 'keydown', function ( event ) {
			const index = getLinks().indexOf( document.activeElement );

			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					focusLink( index + 1 );
					break;
				case 'ArrowUp':
					event.preventDefault();
					focusLink( index - 1 );
					break;
				case 'Home':
					event.preventDefault();
					focusLink( 0 );
					break;
				case 'End':
					event.preventDefault();
					focusLink( -1 );
					break;
				case 'Escape':
					event.preventDefault();
					close( true );
					break;
			}
		} );

		// Close when the focus moves or a click happens outside of the switcher.
		container.addEventListener( 'focusout', function ( event ) {
			if ( isOpen() && event.relatedTarget && ! container.contains( event.relatedTarget ) ) {
				close( false );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! container.contains( event.target ) ) {
				close( false );
			}
		} );
	}

	function init() {
		document.querySelectorAll( '[data-strope-floating-switcher].strope-floating-switcher--dropdown' ).forEach( enhance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
