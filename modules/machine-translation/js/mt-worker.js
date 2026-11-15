/**
 * Machine translation worker: runs Bergamot (js/lib/engine.js) in a Web Worker for the job queue of the
 * site (WASM worker kit, lib/wasm-worker). The queue hands out batches of segments of one language pair;
 * they are translated in one engine call and sent back. The Translations page uses the same worker for
 * direct translations, outside of the queue.
 *
 * Unit input (from Strope_MT_Task::payload()): `text`, `html`, `route`. Result: the engine output.
 *
 * @package Strope-Translate
 */

import { serve } from '../lib/wasm-worker/js/worker.js';
import { countWords, createEngine } from './lib/engine.js';

let words = 0;

serve( {
	runners: {
		'translate-post': {
			supports: () => 'object' === typeof WebAssembly && 'function' === typeof self.importScripts,

			prepare: ( env ) => createEngine( env ),

			async runBatch( { units, engine, report, progress } ) {
				const outcomes = new Array( units.length );
				const byRoute = new Map();

				// A queue batch has one route (its affinity); a direct run may have several.
				units.forEach( ( unit, index ) => {
					const route = String( unit.input.route || '' );
					if ( ! byRoute.has( route ) ) {
						byRoute.set( route, [] );
					}
					byRoute.get( route ).push( index );
				} );

				let done = 0;

				for ( const [ route, indexes ] of byRoute ) {
					const items = indexes.map( ( index ) => ( { text: String( units[ index ].input.text || '' ), html: !! units[ index ].input.html } ) );
					const started = performance.now();
					const results = await engine.translate( route, items );
					const seconds = Math.max( performance.now() - started, 1 ) / 1000;
					const count = items.reduce( ( total, item ) => total + countWords( item.text ), 0 );

					indexes.forEach( ( index, i ) => {
						const result = results[ i ] || { error: 'engine error' };
						outcomes[ index ] = 'string' === typeof result.text ? { result: result.text } : { error: result.error || 'engine error' };
					} );

					words += count;
					done += indexes.length;
					report( 'translating', { route, wps: Math.round( count / seconds ), words } );
					progress( done / units.length );
				}

				return outcomes;
			},
		},
	},
} );
