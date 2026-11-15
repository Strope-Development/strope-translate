/**
 * Scan of the theme and plugin strings, in Languages > Translations > Theme and plugin strings.
 *
 * Runs the scan in small steps through the REST API, displaying a progress bar.
 *
 * @package Strope-Translate
 * @since 4.1.0
 */
( function () {
	'use strict';

	const settings = window.stropeStringDiscovery || {};
	const { __, sprintf } = wp.i18n;

	/**
	 * Runs the scan.
	 *
	 * @param {string} action 'start' to start a new scan, 'step' to resume a scan.
	 */
	async function run( action ) {
		const buttons = document.querySelectorAll( '#strope-sd-scan, #strope-sd-resume' );
		const box = document.querySelector( '.strope-sd-progress' );
		const bar = document.getElementById( 'strope-sd-progress-bar' );
		const text = document.getElementById( 'strope-sd-progress-text' );

		buttons.forEach( ( button ) => ( button.disabled = true ) );
		box.hidden = false;
		text.textContent = __( 'Preparing the scan…', 'strope-translate' );

		/**
		 * Sends a scan request, retrying while another scan request holds the lock.
		 *
		 * @param {string} scanAction 'start' or 'step'.
		 * @return {Promise<Object>} Progress.
		 */
		async function request( scanAction ) {
			for ( let attempt = 0; ; attempt++ ) {
				try {
					return await wp.apiFetch( { path: settings.path, method: 'POST', data: { action: scanAction } } );
				} catch ( error ) {
					if ( ! error || 'strope_scan_locked' !== error.code || attempt >= 30 ) {
						throw error;
					}
					await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
				}
			}
		}

		try {
			let progress = await request( action );

			while ( progress.running ) {
				const percent = progress.total ? Math.round( ( progress.done / progress.total ) * 100 ) : 100;
				bar.value = percent;
				text.textContent = sprintf(
					/* translators: 1: percentage, 2: number of scanned files, 3: total number of files, 4: theme or plugin name */
					__( '%1$d%% (%2$d / %3$d files) – %4$s', 'strope-translate' ),
					percent,
					progress.done,
					progress.total,
					progress.current
				);
				progress = await request( 'step' );
			}

			bar.value = 100;
			text.textContent = __( 'Scan complete. Reloading…', 'strope-translate' );
			window.location.reload();
		} catch ( error ) {
			text.textContent = sprintf(
				/* translators: %s is an error message */
				__( 'The scan failed: %s. You can resume it.', 'strope-translate' ),
				error && error.message ? error.message : __( 'unknown error', 'strope-translate' )
			);
			buttons.forEach( ( button ) => ( button.disabled = false ) );
		}
	}

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '#strope-sd-scan, #strope-sd-resume' );
		if ( button ) {
			event.preventDefault();
			run( button.dataset.action === 'step' ? 'step' : 'start' );
		}
	} );
}() );
