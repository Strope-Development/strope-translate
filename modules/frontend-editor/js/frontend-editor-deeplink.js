/**
 * Selects a block in the block editor when the editor is opened from the front-end translation editor.
 *
 * @package Strope-Translate
 * @since 4.1.0
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.data || ! window.URLSearchParams ) {
		return;
	}

	var path = new window.URLSearchParams( window.location.search ).get( 'strope_fe_block' );
	if ( ! path || ! /^\d{1,5}(\.\d{1,5}){0,30}$/.test( path ) ) {
		return;
	}

	var indexes = path.split( '.' ).map( Number );
	var done = false;
	var unsubscribe = null;
	var started = Date.now();

	function find( blocks ) {
		var block = null;
		for ( var i = 0; i < indexes.length; i++ ) {
			block = blocks && blocks[ indexes[ i ] ];
			if ( ! block ) {
				return null;
			}
			blocks = block.innerBlocks;
		}
		return block;
	}

	function check() {
		if ( done ) {
			return;
		}
		if ( Date.now() - started > 20000 ) {
			done = true;
		} else {
			var editor = wp.data.select( 'core/block-editor' );
			var blocks = editor && editor.getBlocks();
			if ( ! blocks || ! blocks.length ) {
				return;
			}
			var block = find( blocks );
			if ( ! block ) {
				return;
			}
			done = true;
			window.setTimeout( function () {
				wp.data.dispatch( 'core/block-editor' ).selectBlock( block.clientId, 0 );
			}, 300 );
		}
		if ( unsubscribe ) {
			window.setTimeout( unsubscribe );
		}
	}

	unsubscribe = wp.data.subscribe( check );
} )( window.wp );
