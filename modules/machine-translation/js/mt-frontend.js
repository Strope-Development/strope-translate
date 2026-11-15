/**
 * Machine translation in the front-end translation editor. Loaded with the editor when the machine
 * translation can be used (models installed, user allowed), it gives the editor `window.stropeMtFrontend`:
 *
 * - `canTranslate( from, to )`: whether texts can be translated between two languages (slugs).
 * - `translate( from, to, texts, onStatus )`: translates texts, resolves with one text (or null) per text.
 *   Nothing is saved: the editor fills its fields for review.
 * - `translatePost( post, to )`: adds a post to the translation queue (a draft needing a review), and works
 *   on it in this tab until the queue is empty.
 * - `watchRun( run, onUpdate )`: follows the translation of a post; returns a function stopping it.
 *
 * @package Strope-Translate
 */

import { api, createHost, createTranslator, describeStatus, isSupported } from './lib/host.js';
import { isInstalled, isTranslatableText, routeOf, translateTexts } from './lib/texts.js';

( () => {
	const config = window.stropeMtFrontendData;

	if ( ! config || ! config.kit || ! isSupported() ) {
		return;
	}

	const i18n = config.i18n;
	let translator = null;
	let host = null;
	let listener = null; // Status listener of the translation in progress.
	let hostListener = null;

	const code = ( slug ) => ( config.languages[ slug ] ? config.languages[ slug ].code : '' );

	const canTranslate = ( from, to ) => {
		const route = routeOf( code( from ), code( to ) );
		return '' !== route && config.allowed.includes( to ) && isInstalled( route, config.directions );
	};

	const getTranslator = () => {
		if ( ! translator ) {
			translator = createTranslator( config, ( status ) => {
				if ( listener && [ 'starting', 'loading', 'downloading' ].includes( status.state ) ) {
					listener( describeStatus( status, i18n ) );
				}
			} );
		}
		return translator;
	};

	window.stropeMtFrontend = {
		i18n,
		defaultSource: config.defaultSource,
		englishSource: config.englishSource,
		canTranslate,
		isText: isTranslatableText,

		/**
		 * Translates texts.
		 *
		 * @param {string}    from     Source language slug.
		 * @param {string}    to       Target language slug.
		 * @param {string[]}  texts    Texts (inline HTML or plain text).
		 * @param {Function=} onStatus Called with status lines while the engine starts (first use: downloads).
		 * @return {Promise<Array<string|false|null>>} The translation; false when it is the text itself
		 *                                            (already in the target language?); null when it failed.
		 */
		async translate( from, to, texts, onStatus ) {
			const out = texts.map( () => null );
			listener = onStatus || null;
			try {
				await translateTexts(
					config,
					getTranslator(),
					from,
					texts.map( ( text, i ) => ( { key: `t${ i }`, target: to, text } ) ),
					( key, text ) => {
						out[ Number( key.slice( 1 ) ) ] = text;
					},
					( key ) => {
						out[ Number( key.slice( 1 ) ) ] = false;
					}
				);
			} finally {
				listener = null;
			}
			return out;
		},

		/**
		 * Adds a post to the translation queue, and starts working on it in this tab (another tab of the
		 * browser may do it instead). The worker stops when the queue is empty.
		 *
		 * @param {number}    post     Source post id.
		 * @param {string}    to       Target language slug.
		 * @param {Function=} onStatus Called with the status lines of the worker.
		 * @return {Promise<{run: number, skipped: string[]}>}
		 */
		async translatePost( post, to, onStatus ) {
			const result = await api( config, 'mt/runs', { posts: [ Number( post ) ], languages: [ to ] } );

			hostListener = onStatus || null;
			if ( ! host ) {
				host = createHost( config, ( status ) => {
					if ( hostListener ) {
						hostListener( describeStatus( status, i18n ), status.state );
					}
				}, { stopWhenIdle: 3 } );
			}
			host.start( false );

			return { run: Number( result.run_id ), skipped: result.skipped || [] };
		},

		/**
		 * Follows a run of one post: polls its translation until it is done, failed or cancelled.
		 *
		 * @param {number}   run      Run id.
		 * @param {Function} onUpdate Called with the job: `status`, `progress`, `message`, `edit`.
		 * @return {Function} Stops following.
		 */
		watchRun( run, onUpdate ) {
			let timer = null;
			let stopped = false;

			const poll = async () => {
				try {
					const data = await api( config, `mt/runs/${ Number( run ) }` );
					const job = ( data.jobs || [] )[ 0 ];
					if ( stopped || ! job ) {
						return;
					}
					onUpdate( job );
					if ( [ 'done', 'failed', 'cancelled' ].includes( job.status ) ) {
						return;
					}
				} catch ( error ) {
					if ( stopped ) {
						return;
					}
					onUpdate( { status: 'error', message: error.message } );
				}
				timer = setTimeout( poll, 3000 );
			};

			poll();

			return () => {
				stopped = true;
				clearTimeout( timer );
			};
		},
	};
} )();
