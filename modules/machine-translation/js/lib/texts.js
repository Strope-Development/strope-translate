/**
 * Machine translation of single texts in the page (Translations page, front-end editor): the server prepares
 * the texts (splits them, protects their placeholders and markup, uses the translation memory), the engine
 * translates the segments in a worker of this tab, the server checks and restores the output. Nothing is
 * saved: the caller fills its fields for review.
 *
 * @package Strope-Translate
 */

import { api } from './host.js';

/**
 * Language routes, as on the server: through English when neither language is English.
 *
 * @param {string} from Engine code of the source language.
 * @param {string} to   Engine code of the target language.
 * @return {string} Route, empty when the languages are the same.
 */
export function routeOf( from, to ) {
	if ( ! from || ! to || from === to ) {
		return '';
	}
	return 'en' === from || 'en' === to ? `${ from }-${ to }` : `${ from }-en-${ to }`;
}

/**
 * Tells whether the models of a route are installed.
 *
 * @param {string}   route      Route.
 * @param {string[]} directions Installed model directions, e.g. `nl-en`.
 * @return {boolean}
 */
export function isInstalled( route, directions ) {
	const codes = route.match( /zh-Han[st]|[a-z]{2,3}/g ) || [];
	for ( let i = 0; i < codes.length - 1; i++ ) {
		if ( ! directions.includes( `${ codes[ i ] }-${ codes[ i + 1 ] }` ) ) {
			return false;
		}
	}
	return codes.length > 1;
}

const FORMAT_CHAR = '(?:\\\\.|[dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcrU])';
const FORMAT = new RegExp( `^\\s*${ FORMAT_CHAR }(?:[\\s:,./-]*${ FORMAT_CHAR })*\\s*$` );

/**
 * Tells whether a text has something to translate: words, not only placeholders or markup, and not a date
 * or time format (as `Strope_MT_Strings::is_format()`).
 *
 * @param {string} text Text.
 * @return {boolean}
 */
export function isTranslatableText( text ) {
	text = String( text || '' );

	if ( FORMAT.test( text ) && ! /[A-Za-z]{2}/.test( text.replace( /\\./g, '' ) ) ) {
		return false;
	}

	const words = text
		.replace( /%%[A-Za-z][A-Za-z0-9_]*%%/g, ' ' )
		.replace( /%(?:\d+\$)?[-+0#']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/g, ' ' )
		.replace( /<[^>]*>|\[[^\]]*\]|\{[^}]*\}/g, ' ' );

	return /\p{L}{2,}/u.test( words );
}

/**
 * Translates texts. A translation identical to its text is not given: the text was most likely already in
 * the target language (counted as `unchanged`).
 *
 * @param {Object}   config     Script data (REST `api`, `nonce`; `maxItems`).
 * @param {Object}   translator Translator of host.js (`createTranslator()`).
 * @param {string}   source     Source language slug.
 * @param {Object[]} items      Items with `key`, `target` (language slug) and `text`, or `sd_id` and `form`.
 * @param {Function} onText     Called with ( key, text ) for each translated item.
 * @param {Function} onUnchanged Optional: called with the key of each item whose translation is its text.
 * @return {Promise<{done: number, existing: number, failed: number, unchanged: number}>}
 */
export async function translateTexts( config, translator, source, items, onText, onUnchanged = () => {} ) {
	const counts = { done: 0, existing: 0, failed: 0, unchanged: 0 };
	const maxItems = config.maxItems || 100;
	const targets = new Map( items.map( ( item ) => [ item.key, item.target ] ) );
	const sources = new Map( items.map( ( item ) => [ item.key, item.text ] ) );
	const same = ( a, b ) => 'string' === typeof b && String( a ).replace( /\s+/g, ' ' ).trim() === b.replace( /\s+/g, ' ' ).trim();
	const give = ( key, text ) => {
		if ( same( text, sources.get( key ) ) ) {
			counts.unchanged++;
			onUnchanged( key );
		} else {
			onText( key, text );
			counts.done++;
		}
	};

	for ( let start = 0; start < items.length; start += maxItems ) {
		const prepared = await api( config, 'mt/strings/prepare', { source, items: items.slice( start, start + maxItems ) } );
		const toTranslate = [];

		for ( const item of prepared.items || [] ) {
			if ( 'done' === item.status ) {
				give( item.key, item.text );
			} else if ( 'translate' === item.status ) {
				toTranslate.push( item );
			} else if ( 'existing' === item.reason ) {
				counts.existing++;
			} else {
				counts.failed++;
			}
		}

		// The segments, grouped by route, 40 per engine call.
		const routes = new Map();
		for ( const item of toTranslate ) {
			if ( ! routes.has( item.route ) ) {
				routes.set( item.route, [] );
			}
			item.segments.forEach( ( segment, i ) => routes.get( item.route ).push( { id: `${ item.key }:${ i }`, text: segment.text, html: 1 === segment.mode, item, i } ) );
		}

		for ( const [ route, segments ] of routes ) {
			for ( let s = 0; s < segments.length; s += 40 ) {
				const batch = segments.slice( s, s + 40 );
				const results = await translator.translate( route, batch.map( ( { id, text, html } ) => ( { id, text, html } ) ) );
				const byId = new Map( results.map( ( result ) => [ result.id, result ] ) );
				for ( const segment of batch ) {
					const result = byId.get( segment.id );
					segment.item.segments[ segment.i ].output = result && 'string' === typeof result.text ? result.text : '';
				}
			}
		}

		if ( toTranslate.length ) {
			const restored = await api( config, 'mt/strings/restore', {
				source,
				items: toTranslate.map( ( item ) => ( { key: item.key, target: targets.get( item.key ), parts: item.parts, segments: item.segments } ) ),
			} );

			for ( const item of restored.items || [] ) {
				if ( 'string' === typeof item.text ) {
					give( item.key, item.text );
				} else {
					counts.failed++;
				}
			}
		}
	}

	return counts;
}
