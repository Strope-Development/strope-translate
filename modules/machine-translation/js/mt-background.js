/**
 * Background machine translation on the admin pages: when the queue has work and no other tab of this
 * browser translates, a worker starts in this tab, and stops when the queue is empty.
 *
 * @package Strope-Translate
 */

import { updateAdminBar, watch } from './lib/host.js';

( () => {
	const config = window.stropeMt;

	if ( ! config || ! config.kit ) {
		return;
	}

	watch( config, ( status ) => updateAdminBar( status, config.i18n ) );
} )();
