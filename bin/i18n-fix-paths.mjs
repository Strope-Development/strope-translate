/**
 * Makes the file references of the translation catalogues relative to the plugin folder.
 *
 * On Windows, `wp i18n make-pot` writes the references of the plugin header strings as absolute paths
 * (`#: X:/path/to/strope-translate/strope-translate.php`), which would publish the local folder.
 */
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const dir  = path.join( root, 'languages' );
const base = root.replaceAll( '\\', '/' ).replace( /\/?$/, '/' );

for ( const name of readdirSync( dir ) ) {
	if ( ! /\.(pot|po)$/.test( name ) ) {
		continue;
	}

	const file = path.join( dir, name );
	const text = readFileSync( file, 'utf8' );
	// Only reference lines: `#: <path>[:line] ...`.
	const fixed = text.replace( /^#: .*$/gm, ( line ) => line.split( base ).join( '' ).split( base.replace( /\//g, '\\' ) ).join( '' ) );

	if ( fixed !== text ) {
		writeFileSync( file, fixed );
		console.log( `Relative references: ${ name }` );
	}
}
