/**
 * Page side of the machine translation, shared by the admin page, the background script and the
 * Translations page: REST helpers, status lines, and the workers of the job queue (WASM worker kit).
 *
 * Only one tab per browser runs the queue workers: the tabs take turns with the Web Locks API.
 *
 * @package Strope-Translate
 */

import { createDirectWorker, isSupported as kitSupported, startWorkers, watchQueue } from '../../lib/wasm-worker/js/host.js';

/**
 * Tells whether the browser can run the engine.
 *
 * @return {boolean}
 */
export function isSupported() {
	return kitSupported() && 'function' === typeof WebAssembly.instantiate;
}

/**
 * Replaces the `%s`, `%d` and `%1$s` placeholders of a string.
 *
 * @param {string} format String.
 * @param {...*}   args   Values.
 * @return {string}
 */
export function sprintf( format, ...args ) {
	let i = 0;
	return String( format ).replace( /%(?:(\d+)\$)?[sd]/g, ( match, position ) => String( position ? args[ position - 1 ] : args[ i++ ] ) );
}

/**
 * Calls the REST API of the plugin (`strope/v1`).
 *
 * @param {Object}  config Script data.
 * @param {string}  path   Route relative to `strope/v1/`.
 * @param {Object=} body   JSON body for a POST.
 * @return {Promise<Object>}
 */
export async function api( config, path, body ) {
	const response = await fetch( config.api + path, {
		method: body ? 'POST' : 'GET',
		credentials: 'same-origin',
		headers: body ? { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' } : { 'X-WP-Nonce': config.nonce },
		body: body ? JSON.stringify( body ) : undefined,
	} );
	const data = await response.json().catch( () => null );

	if ( 403 === response.status && data && 'rest_cookie_invalid_nonce' === data.code && await renewNonce( config ) ) {
		return api( config, path, body );
	}

	if ( ! response.ok ) {
		throw new Error( ( data && data.message ) || `HTTP ${ response.status }` );
	}

	return data;
}

/**
 * Gets a new REST nonce (the admin pages may stay open for days).
 *
 * @param {Object} config Script data.
 * @return {Promise<boolean>} Whether the nonce changed.
 */
async function renewNonce( config ) {
	try {
		const response = await fetch( config.nonceUrl, { credentials: 'same-origin' } );
		const nonce = ( await response.text() ).trim();

		if ( response.ok && /^[a-f0-9]{10}$/.test( nonce ) && nonce !== config.nonce ) {
			config.nonce = nonce;
			if ( config.kit ) {
				config.kit.nonce = nonce;
			}
			return true;
		}
	} catch ( e ) {
		// Logged out, or offline.
	}

	return false;
}

/**
 * Returns a human readable status line.
 *
 * @param {Object} status Status message (see `normalizer()`).
 * @param {Object} i18n   Strings.
 * @return {string}
 */
export function describeStatus( status, i18n ) {
	switch ( status.state ) {
		case 'starting':
			return i18n.starting;
		case 'loading':
			return sprintf( i18n.loading, status.direction || '' );
		case 'downloading': {
			const percent = status.total ? Math.min( 100, Math.round( ( status.loaded / status.total ) * 100 ) ) : 0;
			return sprintf( i18n.downloading, `${ percent }%` );
		}
		case 'translating':
			return sprintf( i18n.translating, status.wps || 0 );
		case 'idle':
			return i18n.idle;
		case 'error':
			return sprintf( i18n.error, status.message || '' );
		case 'other-tab':
			return i18n.otherTab;
		case 'unsupported':
			return i18n.unsupported;
		default:
			return i18n.stopped;
	}
}

/**
 * Turns the statuses of the queue workers into the states of the page: `starting`, `loading`,
 * `downloading`, `translating` (with the last speed), `idle`, `error`, `other-tab`, `unsupported`, `stopped`.
 *
 * @param {Function} onStatus Called with the page statuses.
 * @return {Function} Status handler for the workers.
 */
function normalizer( onStatus ) {
	let speed = null;

	return ( status ) => {
		switch ( status.state ) {
			case 'translating':
				speed = status;
				onStatus( status );
				return;
			case 'preparing':
				if ( ! speed ) {
					onStatus( { ...status, state: 'starting' } );
				}
				return;
			case 'working':
			case 'progress':
			case 'completed':
			case 'unit-failed':
				onStatus( { ...( speed || { wps: 0 } ), state: 'translating' } );
				return;
			case 'lease-lost':
				onStatus( { state: 'idle', idle: 0 } );
				return;
			default:
				onStatus( status );
		}
	};
}

/**
 * Creates the controller of this tab's queue worker.
 *
 * @param {Object}   config   Script data, with `kit` (options of the queue workers).
 * @param {Function} onStatus Called with each status.
 * @param {Object}   options  More options of `startWorkers()`, e.g. `stopWhenIdle`.
 * @return {{start: Function, stop: Function, isRunning: Function}}
 */
export function createHost( config, onStatus, options = {} ) {
	return startWorkers( { ...config.kit, ...options, onStatus: normalizer( onStatus ) } );
}

/**
 * Helps in the background: starts this tab's worker when the queue has work and no other tab of the
 * browser translates, and frees the browser after about half a minute without work.
 *
 * @param {Object}   config   Script data, with `kit`.
 * @param {Function} onStatus Called with each status.
 * @return {{stop: Function}}
 */
export function watch( config, onStatus ) {
	return watchQueue( { ...config.kit, onStatus: normalizer( onStatus ) } );
}

/**
 * Creates a translator for the page itself (Translations page): a worker which translates the batches it
 * is given, outside of the queue, with the same engine.
 *
 * @param {Object}    config   Script data, with `kit`.
 * @param {Function=} onStatus Called with the statuses (engine start, downloads).
 * @return {{translate: Function}}
 */
export function createTranslator( config, onStatus ) {
	const worker = createDirectWorker( { ...config.kit, onStatus: onStatus ? normalizer( onStatus ) : () => {} } );

	return {
		/**
		 * Translates a batch.
		 *
		 * @param {string}   route Route, e.g. `nl-en`.
		 * @param {Object[]} items Items with `id`, `text`, `html`.
		 * @return {Promise<Object[]>} Results with `id` and `text` or `error`.
		 */
		async translate( route, items ) {
			const outcomes = await worker.run( 'translate-post', items.map( ( item ) => ( { route, text: item.text, html: !! item.html } ) ) );

			return items.map( ( item, i ) => {
				const outcome = outcomes[ i ] || {};
				return 'result' in outcome ? { id: item.id, text: String( outcome.result ) } : { id: item.id, error: outcome.error || 'engine error' };
			} );
		},
	};
}

/**
 * Shows the progress in the admin bar.
 *
 * @param {Object} status Status.
 * @param {Object} i18n   Strings.
 * @return {void}
 */
export function updateAdminBar( status, i18n ) {
	const node = document.getElementById( 'wp-admin-bar-strope-mt' );

	if ( ! node ) {
		return;
	}

	const label = node.querySelector( '.ab-label' );
	const busy = [ 'starting', 'loading', 'downloading', 'translating' ].includes( status.state );

	node.classList.toggle( 'hidden', ! busy );

	if ( label && busy ) {
		label.textContent = 'translating' === status.state ? sprintf( i18n.adminBar, `${ status.wps || 0 } w/s` ) : describeStatus( status, i18n );
	}

	node.title = describeStatus( status, i18n );
}
