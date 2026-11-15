/**
 * Bergamot, the translation engine of Firefox Translations, inside a Web Worker: loads the engine and the
 * language models (served by the site, cached by the browser, checked against their hash) and translates
 * batches of segments. Nothing is sent elsewhere.
 *
 * Used by the queue runner (mt-worker.js) and the direct translations of the Translations page.
 *
 * @package Strope-Translate
 */

import { decompress } from 'fzstd';

/* global loadBergamot */

const CACHE_NAME = 'strope-mt-v1';
const ALIGNMENTS = { model: 256, lex: 64, vocab: 64, srcvocab: 64, trgvocab: 64 };
const MAX_MODELS = 3;

/**
 * Returns the model directions of a route: `nl-en-de` gives `nl-en` and `en-de`.
 *
 * @param {string} route Route.
 * @return {string[]}
 */
export function routeDirections( route ) {
	const codes = route.match( /zh-Han[st]|[a-z]{2,3}/g ) || [];
	const directions = [];

	for ( let i = 0; i < codes.length - 1; i++ ) {
		directions.push( `${ codes[ i ] }-${ codes[ i + 1 ] }` );
	}

	return directions;
}

/**
 * Counts the words of a segment.
 *
 * @param {string} text Segment.
 * @return {number}
 */
export function countWords( text ) {
	return String( text ).replace( /<[^>]*>/g, ' ' ).split( /\s+/ ).filter( Boolean ).length;
}

/**
 * Creates the engine. Nothing is loaded until the first translation.
 *
 * @param {Object}   env                     Runner environment of the worker kit.
 * @param {Function} env.fetch               Fetch with the worker's credentials.
 * @param {Function} env.report              Posts a status: `starting`, `loading`, `downloading`.
 * @param {Object}   env.options             `manifestUrl` (REST route of the engine files), `glueUrl`.
 * @return {{translate: Function}}
 */
export function createEngine( { fetch: authFetch, report, options } ) {
	const state = {
		manifest: null,
		bergamot: null,
		service: null,
		models: new Map(), // Direction => TranslationModel, least recently used first.
		routeModels: null,
		route: '',
		lock: Promise.resolve(), // One translation at a time: the engine is synchronous, the models shared.
	};

	const getManifest = async ( refresh = false ) => {
		if ( ! state.manifest || refresh ) {
			const response = await authFetch( options.manifestUrl );
			if ( ! response.ok ) {
				throw new Error( `The list of language models could not be loaded (HTTP ${ response.status }).` );
			}
			state.manifest = await response.json();
			cleanCache( state.manifest );
		}
		return state.manifest;
	};

	const loadEngine = async () => {
		if ( state.bergamot ) {
			return;
		}

		report( 'starting' );

		const manifest = await getManifest();
		if ( ! manifest.wasm ) {
			throw new Error( 'The translation engine is not installed.' );
		}

		if ( 'function' !== typeof self.loadBergamot ) {
			self.importScripts( options.glueUrl );
		}

		const wasmBinary = await getFile( manifest.wasm, 'engine', report );

		state.bergamot = await new Promise( ( resolve, reject ) => {
			const bergamot = loadBergamot( {
				INITIAL_MEMORY: 41943040,
				print: () => {},
				printErr: () => {},
				onAbort: () => reject( new Error( 'The translation engine could not start.' ) ),
				onRuntimeInitialized: async () => {
					await Promise.resolve(); // Let `bergamot` be assigned.
					resolve( bergamot );
				},
				wasmBinary,
			} );
		} );

		state.service = new state.bergamot.BlockingService( { cacheSize: 0 } );
	};

	const loadRoute = async ( route ) => {
		await loadEngine();

		if ( state.route === route && state.routeModels ) {
			return;
		}

		const directions = routeDirections( route );

		for ( const direction of directions ) {
			if ( state.models.has( direction ) ) {
				const model = state.models.get( direction );
				state.models.delete( direction );
				state.models.set( direction, model ); // Most recently used.
				continue;
			}

			let entry = ( await getManifest() ).directions[ direction ];
			if ( ! entry ) {
				entry = ( await getManifest( true ) ).directions[ direction ];
			}
			if ( ! entry ) {
				const error = new Error( `The ${ direction } language model is not installed.` );
				error.retry = true; // It may be installed later.
				throw error;
			}

			report( 'loading', { direction } );

			const files = {};
			for ( const [ type, file ] of Object.entries( entry.files ) ) {
				files[ type ] = { data: await getFile( file, direction, report ), name: file.name };
			}

			state.models.set( direction, buildModel( state.bergamot, direction, files ) );

			while ( state.models.size > MAX_MODELS ) {
				const oldest = state.models.keys().next().value;
				if ( directions.includes( oldest ) ) {
					break;
				}
				state.models.get( oldest ).delete();
				state.models.delete( oldest );
			}
		}

		state.routeModels = directions.map( ( direction ) => state.models.get( direction ) );
		state.route = route;
	};

	const reset = async ( route ) => {
		state.bergamot = null;
		state.service = null;
		state.models.clear();
		state.routeModels = null;
		state.route = '';
		await loadRoute( route );
	};

	const runEngine = ( items ) => {
		const b = state.bergamot;
		const messages = new b.VectorString();
		const responseOptions = new b.VectorResponseOptions();
		let responses = null;

		try {
			for ( const item of items ) {
				messages.push_back( item.text );
				responseOptions.push_back( { qualityScores: false, alignment: !! item.html, html: !! item.html } );
			}

			const models = state.routeModels;
			responses = 1 === models.length
				? state.service.translate( models[ 0 ], messages, responseOptions )
				: state.service.translateViaPivoting( models[ 0 ], models[ 1 ], messages, responseOptions );

			const out = [];
			for ( let i = 0; i < items.length; i++ ) {
				out.push( { text: responses.get( i ).getTranslatedText() } );
			}
			return out;
		} finally {
			try {
				messages.delete();
				responseOptions.delete();
				if ( responses ) {
					responses.delete();
				}
			} catch ( e ) {
				// The engine aborted, its memory is gone anyway.
			}
		}
	};

	/**
	 * Translates a batch of one route. When the engine aborts (it does on input it cannot parse), it is
	 * started again and the items are translated one by one, so that only the faulty item fails.
	 *
	 * @param {string}   route Route, e.g. `nl-en`.
	 * @param {Object[]} items Items with `text` and `html`.
	 * @return {Promise<Object[]>} One per item: `{ text }` or `{ error }`.
	 */
	const translate = ( route, items ) => {
		const run = state.lock.then( async () => {
			await loadRoute( route );

			try {
				return runEngine( items );
			} catch ( error ) {
				const results = [];

				await reset( route );

				for ( const item of items ) {
					try {
						results.push( ...runEngine( [ item ] ) );
					} catch ( itemError ) {
						results.push( { error: 'engine error' } );
						await reset( route );
					}
				}

				return results;
			}
		} );

		state.lock = run.catch( () => {} );
		return run;
	};

	return { translate };
}

/**
 * Builds a translation model from its files, like Firefox does.
 *
 * @param {Object} b         Bergamot module.
 * @param {string} direction Direction.
 * @param {Object} files     Files by type, each with `data` (Uint8Array) and `name`.
 * @return {Object} Bergamot TranslationModel.
 */
function buildModel( b, direction, files ) {
	const memory = {};

	for ( const [ type, file ] of Object.entries( files ) ) {
		const aligned = new b.AlignedMemory( file.data.byteLength, ALIGNMENTS[ type ] || 64 );
		aligned.getByteArrayView().set( file.data );
		memory[ type ] = aligned;
	}

	const vocabs = new b.AlignedMemoryList();

	if ( memory.vocab ) {
		vocabs.push_back( memory.vocab );
	} else {
		vocabs.push_back( memory.srcvocab );
		vocabs.push_back( memory.trgvocab );
	}

	const config = {
		'beam-size': '1',
		normalize: '1.0',
		'word-penalty': '0',
		'max-length-break': '128',
		'mini-batch-words': '1024',
		workspace: '128',
		'max-length-factor': '2.0',
		'skip-cost': 'true',
		'cpu-threads': '0',
		quiet: 'true',
		'quiet-translation': 'true',
		'gemm-precision': /intgemm8\.bin/.test( files.model.name || '' ) ? 'int8shiftAll' : 'int8shiftAlphaAll',
		alignment: 'soft',
	};

	// Marian expects a YAML block with this exact indentation.
	const indent = '            ';
	let yaml = '\n';
	for ( const [ key, value ] of Object.entries( config ) ) {
		yaml += `${ indent }${ key }: ${ value }\n`;
	}
	yaml += indent;

	const [ from, to ] = direction.match( /zh-Han[st]|[a-z]{2,3}/g ) || [ '', '' ];

	return new b.TranslationModel( from, to, yaml, memory.model, memory.lex || null, vocabs, null );
}

/**
 * Returns the decompressed content of an engine or model file, from the browser cache or the site.
 *
 * @param {Object}   file   File descriptor: `url`, `size`, `decompressed_hash`, and `compressed: false` for a file
 *                          that is not zstd compressed.
 * @param {string}   label  What is downloaded, for the progress.
 * @param {Function} report Posts a status.
 * @return {Promise<Uint8Array>}
 */
async function getFile( file, label, report ) {
	const cache = self.caches ? await self.caches.open( CACHE_NAME ).catch( () => null ) : null;
	let bytes = null;

	if ( cache ) {
		const hit = await cache.match( file.url );
		if ( hit ) {
			bytes = new Uint8Array( await hit.arrayBuffer() );
		}
	}

	if ( ! bytes ) {
		bytes = await download( file, label, report );
		if ( cache ) {
			await cache.put( file.url, new Response( bytes, { headers: { 'Content-Type': 'application/octet-stream' } } ) ).catch( () => {} );
		}
	}

	// The model files are zstd compressed; the engine is shipped decompressed (`compressed: false`).
	const data = false === file.compressed ? bytes : decompress( bytes );

	if ( file.decompressed_hash ) {
		const digest = new Uint8Array( await self.crypto.subtle.digest( 'SHA-256', data ) );
		const hash = Array.from( digest, ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' );

		if ( hash !== file.decompressed_hash ) {
			if ( cache ) {
				await cache.delete( file.url );
			}
			throw new Error( 'A language model file is damaged, it will be downloaded again.' );
		}
	}

	return data;
}

/**
 * Downloads a file, reporting the progress.
 *
 * @param {Object}   file   File descriptor.
 * @param {string}   label  What is downloaded.
 * @param {Function} report Posts a status.
 * @return {Promise<Uint8Array>}
 */
async function download( file, label, report ) {
	const response = await fetch( file.url, { credentials: 'same-origin' } );

	if ( ! response.ok || ! response.body ) {
		throw new Error( `The ${ label } file could not be downloaded (HTTP ${ response.status }).` );
	}

	const total = file.size || Number( response.headers.get( 'Content-Length' ) ) || 0;
	const reader = response.body.getReader();
	const chunks = [];
	let loaded = 0;
	let last = 0;

	for ( ;; ) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		chunks.push( value );
		loaded += value.length;

		if ( Date.now() - last > 250 ) {
			last = Date.now();
			report( 'downloading', { label, loaded, total } );
		}
	}

	const out = new Uint8Array( loaded );
	let offset = 0;
	for ( const chunk of chunks ) {
		out.set( chunk, offset );
		offset += chunk.length;
	}

	return out;
}

/**
 * Deletes the cached files which are no longer in the manifest (previous versions).
 *
 * @param {Object} manifest Manifest.
 * @return {Promise<void>}
 */
async function cleanCache( manifest ) {
	if ( ! self.caches ) {
		return;
	}

	try {
		const cache = await self.caches.open( CACHE_NAME );
		const urls = new Set();

		if ( manifest.wasm ) {
			urls.add( manifest.wasm.url );
		}
		for ( const entry of Object.values( manifest.directions || {} ) ) {
			for ( const file of Object.values( entry.files ) ) {
				urls.add( file.url );
			}
		}

		for ( const key of await cache.keys() ) {
			if ( ! urls.has( key.url ) ) {
				await cache.delete( key );
			}
		}
	} catch ( e ) {
		// Not critical.
	}
}
