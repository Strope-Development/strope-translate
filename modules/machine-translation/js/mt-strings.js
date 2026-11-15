/**
 * Machine translation buttons of the Languages > Translations page.
 *
 * Fills empty translation fields with a machine translation, one field at a time or all the empty fields of
 * the page. Nothing is saved: the user reviews the filled fields and saves the form as usual.
 *
 * @package Strope-Translate
 */

import { createTranslator, describeStatus, isSupported, sprintf } from './lib/host.js';
import { isInstalled, isTranslatableText, routeOf, translateTexts } from './lib/texts.js';

( () => {
	const config = window.stropeMtStrings;

	if ( ! config || ! isSupported() ) {
		return;
	}

	const i18n = config.i18n;
	const discovered = 'discovered' === config.view;
	const form = document.getElementById( discovered ? 'strope-sd-translations' : 'string-translation' );
	const table = form && form.querySelector( 'table.wp-list-table' );

	if ( ! table ) {
		return;
	}

	const pattern = discovered ? /^strope_sd\[(\d+)\]\[([^\]]+)\]\[(\d+)\]$/ : /^translation\[([^\]]+)\]\[([^\]]+)\]$/;
	const storageKey = `strope-mt-source-${ config.view }`;
	const filled = new Set();
	let busy = false;

	const translator = createTranslator( config, ( status ) => {
		if ( busy && [ 'starting', 'loading', 'downloading' ].includes( status.state ) ) {
			setStatus( describeStatus( status, i18n ) );
		}
	} );

	/* Toolbar. */

	const select = document.createElement( 'select' );
	select.id = `strope-mt-source-${ config.view }`;
	for ( const [ slug, language ] of Object.entries( config.languages ) ) {
		select.append( new Option( language.name, slug ) );
	}

	let source = config.defaultSource;
	try {
		const stored = window.localStorage.getItem( storageKey );
		if ( stored && config.languages[ stored ] ) {
			source = stored;
		}
	} catch ( e ) {
		// Storage not available.
	}
	select.value = source;

	const label = document.createElement( 'label' );
	label.htmlFor = select.id;
	label.textContent = i18n.from;

	const all = document.createElement( 'button' );
	all.type = 'button';
	all.className = 'button';
	all.textContent = i18n.translateAll;

	const statusLine = document.createElement( 'span' );
	statusLine.className = 'strope-mt-strings-status';
	statusLine.setAttribute( 'aria-live', 'polite' );

	const bar = document.createElement( 'div' );
	bar.className = 'strope-mt-strings-bar';
	bar.append( label, select, all, statusLine );

	const tablenav = form.querySelector( '.tablenav.top' );
	( tablenav || table ).before( bar );

	const setStatus = ( text, isError = false ) => {
		statusLine.textContent = text;
		statusLine.classList.toggle( 'is-error', isError );
	};

	/* Fields. */

	const getFields = () => Array.from( form.querySelectorAll( '.translation input[type="text"], .translation textarea' ) )
		.map( ( el ) => {
			const match = el.name && el.name.match( pattern );
			if ( ! match ) {
				return null;
			}
			return discovered
				? { el, lang: match[ 2 ], sdId: Number( match[ 1 ] ), form: Number( match[ 3 ] ) }
				: { el, lang: match[ 1 ] };
		} )
		.filter( Boolean );

	const isTranslatable = ( field ) => {
		const from = config.languages[ source ] && config.languages[ source ].code;
		const to = config.languages[ field.lang ] && config.languages[ field.lang ].code;
		const route = routeOf( from, to );
		return '' !== route && field.lang !== source && isInstalled( route, config.directions ) && '' === field.el.value.trim() && isTranslatableText( field.el.placeholder );
	};

	const buttons = new Map();

	const refreshButtons = () => {
		let count = 0;
		for ( const field of getFields() ) {
			let button = buttons.get( field.el );

			if ( ! button ) {
				button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'button-link strope-mt-field-button';
				button.innerHTML = '<span class="dashicons dashicons-translation" aria-hidden="true"></span>';
				const name = config.languages[ field.lang ] ? config.languages[ field.lang ].name : field.lang;
				button.title = sprintf( i18n.translateOne, name );
				button.setAttribute( 'aria-label', button.title );
				button.addEventListener( 'click', () => run( [ field ] ) );
				field.el.after( button );
				field.el.addEventListener( 'input', () => {
					if ( ! field.el.dataset.stropeMtFilling ) {
						field.el.classList.remove( 'strope-mt-filled' );
					}
					refreshButtons();
				} );
				buttons.set( field.el, button );
			}

			const visible = isTranslatable( field );
			button.hidden = ! visible;
			button.disabled = busy;
			count += visible ? 1 : 0;
		}

		all.disabled = busy || 0 === count;
	};

	select.addEventListener( 'change', () => {
		source = select.value;
		try {
			window.localStorage.setItem( storageKey, source );
		} catch ( e ) {
			// Storage not available.
		}
		refreshButtons();
	} );

	all.addEventListener( 'click', () => run( getFields().filter( isTranslatable ) ) );

	const fill = ( field, text ) => {
		field.el.dataset.stropeMtFilling = '1';
		field.el.value = text;
		field.el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		field.el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		delete field.el.dataset.stropeMtFilling;
		field.el.classList.add( 'strope-mt-filled' );
		field.el.title = i18n.filledTitle;
		filled.add( field.el );
	};

	/* Translation. */

	const run = async ( fields ) => {
		if ( busy || ! fields.length ) {
			return;
		}

		busy = true;
		refreshButtons();
		setStatus( i18n.working );

		const byKey = new Map();
		const items = fields.map( ( field, index ) => {
			const key = `f${ index }`;
			byKey.set( key, field );
			return discovered
				? { key, target: field.lang, sd_id: field.sdId, form: field.form }
				: { key, target: field.lang, text: field.el.placeholder };
		} );

		try {
			const { done, existing, failed, unchanged } = await translateTexts( config, translator, source, items, ( key, text ) => fill( byKey.get( key ), text ) );

			const notes = [ sprintf( i18n.filled, done ) ];
			if ( existing ) {
				notes.push( sprintf( i18n.existing, existing ) );
			}
			if ( failed ) {
				notes.push( sprintf( i18n.failed, failed ) );
			}
			if ( unchanged ) {
				notes.push( sprintf( i18n.unchanged, unchanged ) );
			}
			setStatus( notes.join( ' ' ) );
		} catch ( error ) {
			setStatus( sprintf( i18n.error, error.message ), true );
		} finally {
			busy = false;
			refreshButtons();
		}
	};

	/* Unsaved machine translations. */

	form.addEventListener( 'submit', () => filled.clear() );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( [ ...filled ].some( ( el ) => el.isConnected && '' !== el.value ) ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	refreshButtons();
} )();
