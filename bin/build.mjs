/**
 * Builds the plugin assets into js/build and css/build.
 *
 * For each source, two files are written: `name.js` (readable, loaded when SCRIPT_DEBUG is true)
 * and `name.min.js` (minified). Same for CSS.
 *
 * - Sources using `import`/`export` are bundled with their dependencies (e.g. js/src/lib/*).
 * - Other sources are classic scripts: they are copied and minified as is, without any wrapper,
 *   so that their top-level declarations and `this` keep referring to the global scope.
 *   This is the case of the pre-compiled editor bundles in js/src/editor.
 *
 * Usage: `npm run build`, or `npm run watch` to rebuild on changes.
 *
 * @package Strope-Translate
 */

import * as esbuild from 'esbuild';
import { existsSync, watch } from 'node:fs';
import { mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/**
 * Source directories. Each `*.js` / `*.css` file directly inside one of these is an entry point.
 */
const SOURCES = {
	js: [ 'js/src', 'js/src/editor', 'modules/*/js', 'integrations/*/js', 'services/*/js' ],
	css: [ 'css/src', 'modules/*/css', 'modules/*/assets/css', 'services/*/css' ],
};

const OUTPUT = {
	js: 'js/build',
	css: 'css/build',
};

/**
 * Expands a directory pattern containing at most one `*` segment.
 *
 * @param {string} pattern Directory pattern relative to the plugin root.
 * @return {Promise<string[]>} Matching directories, relative to the plugin root.
 */
async function expandDir( pattern ) {
	const [ before, after ] = pattern.split( '/*/' );

	if ( undefined === after ) {
		return existsSync( path.join( root, pattern ) ) ? [ pattern ] : [];
	}

	const entries = await readdir( path.join( root, before ), { withFileTypes: true } );

	return entries
		.filter( ( entry ) => entry.isDirectory() && existsSync( path.join( root, before, entry.name, after ) ) )
		.map( ( entry ) => `${ before }/${ entry.name }/${ after }` );
}

/**
 * Lists the entry points of a given type.
 *
 * @param {'js'|'css'} type Asset type.
 * @return {Promise<string[]>} Source files relative to the plugin root.
 */
async function listEntries( type ) {
	const files = [];

	for ( const pattern of SOURCES[ type ] ) {
		for ( const dir of await expandDir( pattern ) ) {
			const entries = await readdir( path.join( root, dir ), { withFileTypes: true } );
			for ( const entry of entries ) {
				if ( entry.isFile() && entry.name.endsWith( `.${ type }` ) && ! entry.name.endsWith( `.min.${ type }` ) ) {
					files.push( `${ dir }/${ entry.name }` );
				}
			}
		}
	}

	// Output files are named after the source file, so names must be unique.
	const seen = new Map();
	for ( const file of files ) {
		const name = path.basename( file );
		if ( seen.has( name ) ) {
			throw new Error( `Two sources would build to the same file "${ name }": ${ seen.get( name ) } and ${ file }` );
		}
		seen.set( name, file );
	}

	return files.sort();
}

/**
 * Tells whether a JavaScript source is an ES module that must be bundled.
 *
 * @param {string} code Source code.
 * @return {boolean}
 */
function isModule( code ) {
	return /^\s*(import|export)\s/m.test( code );
}

/**
 * Builds one JavaScript entry point.
 *
 * @param {string} file Source file relative to the plugin root.
 * @return {Promise<void>}
 */
async function buildJs( file ) {
	const source = await readFile( path.join( root, file ), 'utf8' );
	let code = source;

	if ( isModule( source ) ) {
		const result = await esbuild.build( {
			entryPoints: [ path.join( root, file ) ],
			bundle: true,
			format: 'esm', // No wrapper: the output must not keep any import/export, it is loaded as a classic script.
			write: false,
			charset: 'utf8',
			logLevel: 'silent',
		} );
		code = result.outputFiles[ 0 ].text;

		if ( isModule( code ) ) {
			throw new Error( `${ file }: the bundle still contains import or export statements.` );
		}
	}

	// Minify as a classic script: top-level names are kept as they may be used globally.
	const minified = await esbuild.transform( code, {
		loader: 'js',
		minify: true,
		legalComments: 'inline',
		charset: 'utf8',
	} );

	const name = path.basename( file, '.js' );
	await writeFile( path.join( root, OUTPUT.js, `${ name }.js` ), code );
	await writeFile( path.join( root, OUTPUT.js, `${ name }.min.js` ), minified.code );
}

/**
 * Builds one CSS entry point.
 *
 * @param {string} file Source file relative to the plugin root.
 * @return {Promise<void>}
 */
async function buildCss( file ) {
	const code = await readFile( path.join( root, file ), 'utf8' );
	const minified = await esbuild.transform( code, {
		loader: 'css',
		minify: true,
		legalComments: 'none',
		charset: 'utf8',
	} );

	const name = path.basename( file, '.css' );
	await writeFile( path.join( root, OUTPUT.css, `${ name }.css` ), code );
	await writeFile( path.join( root, OUTPUT.css, `${ name }.min.css` ), minified.code );
}

/**
 * Cleans the output directories and builds all the assets.
 *
 * @return {Promise<boolean>} Whether the build succeeded.
 */
async function buildAll() {
	const start = Date.now();

	try {
		const js = await listEntries( 'js' );
		const css = await listEntries( 'css' );

		for ( const dir of Object.values( OUTPUT ) ) {
			await mkdir( path.join( root, dir ), { recursive: true } );
		}

		await Promise.all( [ ...js.map( buildJs ), ...css.map( buildCss ) ] );

		// Remove the built files whose source no longer exists. Done last, so that a concurrent build never sees empty folders.
		const expected = new Set(
			[ ...js, ...css ].flatMap( ( file ) => {
				const ext  = path.extname( file );
				const name = path.basename( file, ext );
				return [ `${ name }${ ext }`, `${ name }.min${ ext }` ];
			} )
		);
		for ( const dir of Object.values( OUTPUT ) ) {
			for ( const file of await readdir( path.join( root, dir ) ) ) {
				if ( ! expected.has( file ) ) {
					await rm( path.join( root, dir, file ), { force: true } );
				}
			}
		}

		console.log( `Built ${ js.length } scripts and ${ css.length } stylesheets in ${ Date.now() - start } ms.` );
		return true;
	} catch ( error ) {
		console.error( error.errors ? esbuild.formatMessagesSync( error.errors, { kind: 'error' } ).join( '\n' ) : error );
		return false;
	}
}

const ok = await buildAll();

if ( process.argv.includes( '--watch' ) ) {
	let timer;
	const outputs = Object.values( OUTPUT ).map( ( dir ) => path.normalize( dir ) );

	watch( root, { recursive: true }, ( event, filename ) => {
		if ( ! filename || ! /\.(js|css)$/.test( filename ) || filename.includes( 'node_modules' ) || outputs.some( ( dir ) => path.normalize( filename ).startsWith( dir ) ) ) {
			return;
		}
		clearTimeout( timer );
		timer = setTimeout( buildAll, 100 );
	} );

	console.log( 'Watching for changes...' );
} else if ( ! ok ) {
	process.exitCode = 1;
}
