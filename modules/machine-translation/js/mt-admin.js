/**
 * Languages > Machine translation page: worker controls, runs, language models.
 *
 * @package Strope-Translate
 */

import { api, createHost, describeStatus, sprintf, updateAdminBar } from './lib/host.js';

( () => {
	const config = window.stropeMt;

	if ( ! config ) {
		return;
	}

	const i18n = config.i18n;
	const $ = ( selector, root = document ) => root.querySelector( selector );

	/**
	 * Creates an element.
	 *
	 * @param {string} tag        Tag name.
	 * @param {Object} attributes Attributes and properties (`text`, `className`, `onclick`...).
	 * @param {Array}  children   Child nodes or strings.
	 * @return {HTMLElement}
	 */
	const el = ( tag, attributes = {}, children = [] ) => {
		const node = document.createElement( tag );
		for ( const [ key, value ] of Object.entries( attributes ) ) {
			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( 'className' === key ) {
				node.className = value;
			} else if ( null !== value && undefined !== value && false !== value ) {
				node.setAttribute( key, true === value ? '' : value );
			}
		}
		for ( const child of children ) {
			node.append( child );
		}
		return node;
	};

	/* Worker controls. */

	const statusLine = $( '#strope-mt-worker-status' );
	const toggle = $( '#strope-mt-worker-toggle' );
	const background = $( '#strope-mt-background' );
	let lastStatus = { state: 'stopped' };

	const host = createHost( config, ( status ) => {
		lastStatus = status;
		if ( statusLine ) {
			statusLine.textContent = describeStatus( status, i18n );
			statusLine.dataset.state = status.state;
		}
		updateAdminBar( status, i18n );
		renderToggle();

		if ( 'translating' === status.state ) {
			scheduleRefresh( 1500 );
		}
	} );

	const renderToggle = () => {
		if ( ! toggle ) {
			return;
		}
		const running = host.isRunning() || 'other-tab' === lastStatus.state;
		toggle.textContent = running ? i18n.stop : i18n.start;
		toggle.className = running ? 'button' : 'button button-primary';
	};

	if ( toggle ) {
		toggle.addEventListener( 'click', () => {
			if ( host.isRunning() || 'other-tab' === lastStatus.state ) {
				host.stop();
			} else {
				host.start( true );
			}
			renderToggle();
		} );
	}

	if ( background ) {
		background.addEventListener( 'change', async () => {
			config.background = background.checked;
			try {
				await api( config, 'mt/preferences', { background: background.checked } );
			} catch ( e ) {
				background.checked = ! background.checked;
			}
		} );
	}

	statusLine.textContent = describeStatus( lastStatus, i18n );
	renderToggle();

	/* Runs. */

	const runsBox = $( '#strope-mt-runs' );
	const openDetails = new Set();
	let refreshTimer = null;
	let refreshing = false;

	const scheduleRefresh = ( delay ) => {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( refresh, delay );
	};

	const refresh = async () => {
		if ( refreshing ) {
			return;
		}
		refreshing = true;

		try {
			const data = await api( config, 'mt/status' );
			renderRuns( data.runs || [] );
			renderModels( data.directions || {} );

			// Start automatically when there is work and the user lets the browser translate.
			if ( config.background && data.waiting > 0 && ! host.isRunning() && 'other-tab' !== lastStatus.state ) {
				host.start( false );
			}

			const active = ( data.runs || [] ).some( ( run ) => 'active' === run.status );
			scheduleRefresh( active ? ( document.hidden ? 20000 : 4000 ) : 30000 );
		} catch ( error ) {
			scheduleRefresh( 30000 );
		} finally {
			refreshing = false;
		}
	};

	const runAction = async ( run, action ) => {
		try {
			await api( config, `mt/runs/${ run.id }`, { action } );
		} catch ( error ) {
			window.alert( error.message ); // eslint-disable-line no-alert
		}
		refresh();
		if ( ( 'resume' === action || 'retry' === action ) && ! host.isRunning() ) {
			host.start( true );
		}
	};

	const renderRuns = ( runs ) => {
		if ( ! runsBox ) {
			return;
		}

		if ( ! runs.length ) {
			runsBox.replaceChildren( el( 'p', { text: i18n.noRuns } ) );
			return;
		}

		const rows = runs.map( ( run ) => {
			const percent = Math.round( ( run.progress || 0 ) * 100 );
			const finished = run.jobs.done + run.jobs.skipped + run.jobs.failed;
			const notes = [];

			if ( run.jobs.skipped ) {
				notes.push( sprintf( i18n.skippedCount, run.jobs.skipped ) );
			}
			if ( run.jobs.failed ) {
				notes.push( sprintf( i18n.failedCount, run.jobs.failed ) );
			}

			const actions = [];
			if ( run.can_manage ) {
				if ( 'active' === run.status ) {
					actions.push( [ 'pause', i18n.pause ] );
				}
				if ( 'paused' === run.status ) {
					actions.push( [ 'resume', i18n.resume ] );
				}
				if ( 'active' === run.status || 'paused' === run.status ) {
					actions.push( [ 'cancel', i18n.cancel ] );
				}
				if ( run.jobs.failed ) {
					actions.push( [ 'retry', i18n.retry ] );
				}
			}

			const details = el( 'div', { className: 'strope-mt-details' } );
			const detailsButton = el( 'button', {
				type: 'button',
				className: 'button-link',
				'aria-expanded': openDetails.has( run.id ) ? 'true' : 'false',
				text: i18n.details,
				onclick: () => {
					if ( openDetails.has( run.id ) ) {
						openDetails.delete( run.id );
						details.replaceChildren();
						detailsButton.setAttribute( 'aria-expanded', 'false' );
					} else {
						openDetails.add( run.id );
						detailsButton.setAttribute( 'aria-expanded', 'true' );
						loadDetails( run, details );
					}
				},
			} );

			if ( openDetails.has( run.id ) ) {
				loadDetails( run, details );
			}

			return el( 'tr', {}, [
				el( 'td', {}, [
					el( 'strong', { text: run.languages.join( ', ' ) } ),
					el( 'br' ),
					el( 'span', { className: 'description', text: `${ new Date( run.created ).toLocaleString() } · ${ run.user }` } ),
				] ),
				el( 'td', { className: 'strope-mt-progress-cell' }, [
					el( 'div', { className: 'strope-mt-progress', role: 'progressbar', 'aria-valuenow': String( percent ), 'aria-valuemin': '0', 'aria-valuemax': '100' }, [
						el( 'span', { style: `width:${ percent }%` } ),
					] ),
					el( 'span', { className: 'description', text: `${ sprintf( i18n.posts, finished, run.jobs.total ) }${ notes.length ? ' · ' + notes.join( ', ' ) : '' }` } ),
				] ),
				el( 'td', { text: i18n.statuses[ run.status ] || run.status } ),
				el( 'td', { className: 'strope-mt-actions' }, [
					...actions.map( ( [ action, label ] ) => el( 'button', { type: 'button', className: 'button button-small', text: label, onclick: () => runAction( run, action ) } ) ),
					detailsButton,
					details,
				] ),
			] );
		} );

		runsBox.replaceChildren( el( 'table', { className: 'widefat striped strope-mt-runs' }, [ el( 'tbody', {}, rows ) ] ) );
	};

	const loadDetails = async ( run, box ) => {
		try {
			const data = await api( config, `mt/runs/${ run.id }` );
			box.replaceChildren(
				el( 'ul', {}, ( data.jobs || [] ).map( ( job ) => el( 'li', {}, [
					`${ job.title } → ${ job.language }: ${ i18n.statuses[ job.status ] || job.status }`,
					job.message ? el( 'span', { className: 'description', text: ` (${ job.message })` } ) : '',
					job.edit ? ' ' : '',
					job.edit ? el( 'a', { href: job.edit, text: i18n.edit } ) : '',
				] ) ) )
			);
		} catch ( error ) {
			box.replaceChildren( el( 'p', { text: error.message } ) );
		}
	};

	/* Language models. */

	const busyModels = new Set();

	const renderModels = ( directions ) => {
		document.querySelectorAll( '.strope-mt-models tr[data-direction]' ).forEach( ( row ) => {
			const direction = row.dataset.direction;
			const info = directions[ direction ];
			if ( info ) {
				row.dataset.installed = info.installed ? '1' : '0';
				row.dataset.outdated = info.outdated ? '1' : '0';
				row.dataset.available = info.available ? '1' : '0';
			}
			if ( ! busyModels.has( direction ) ) {
				renderModelActions( row );
			}
		} );
	};

	const renderModelActions = ( row ) => {
		const cell = $( '.strope-mt-model-actions', row );
		const direction = row.dataset.direction;
		const buttons = [];

		if ( '1' === row.dataset.installed ) {
			if ( '1' === row.dataset.outdated ) {
				buttons.push( el( 'button', { type: 'button', className: 'button button-small', text: i18n.update, onclick: () => installModel( row ) } ) );
			} else {
				buttons.push( el( 'span', { className: 'strope-mt-installed', text: i18n.installed } ) );
			}
			buttons.push( el( 'button', {
				type: 'button',
				className: 'button-link button-link-delete',
				text: i18n.remove,
				onclick: async () => {
					if ( ! window.confirm( i18n.confirmRemove ) ) { // eslint-disable-line no-alert
						return;
					}
					await api( config, 'mt/directions', { direction, action: 'remove' } );
					row.dataset.installed = '0';
					renderModelActions( row );
				},
			} ) );
		} else if ( '1' === row.dataset.available ) {
			buttons.push( el( 'button', { type: 'button', className: 'button button-small', text: i18n.install, onclick: () => installModel( row ) } ) );
		} else {
			buttons.push( el( 'span', { className: 'description', text: i18n.notAvailable } ) );
		}

		cell.replaceChildren( ...buttons );
	};

	const installModel = async ( row ) => {
		const direction = row.dataset.direction;
		const cell = $( '.strope-mt-model-actions', row );
		const bar = el( 'span' );
		const label = el( 'span', { className: 'description', text: '0%' } );

		busyModels.add( direction );
		cell.replaceChildren( el( 'div', { className: 'strope-mt-progress' }, [ bar ] ), label );

		try {
			for ( ;; ) {
				const result = await api( config, 'mt/directions', { direction, action: 'install' } );
				const percent = result.total ? Math.round( ( result.done_bytes / result.total ) * 100 ) : 100;
				bar.style.width = `${ percent }%`;
				label.textContent = `${ percent }%`;
				if ( result.complete ) {
					break;
				}
			}
			row.dataset.installed = '1';
			row.dataset.outdated = '0';
		} catch ( error ) {
			window.alert( error.message ); // eslint-disable-line no-alert
		} finally {
			busyModels.delete( direction );
			renderModelActions( row );
		}
	};

	document.querySelectorAll( '.strope-mt-models tr[data-direction]' ).forEach( renderModelActions );

	// Glossary: adds an empty row (the page has three without JavaScript).
	const glossaryAdd = $( '.strope-mt-glossary-add' );
	if ( glossaryAdd ) {
		const tbody = $( '.strope-mt-glossary tbody' );
		glossaryAdd.hidden = false;
		glossaryAdd.addEventListener( 'click', () => {
			const row = tbody.lastElementChild.cloneNode( true );
			const index = tbody.children.length;
			row.querySelectorAll( 'input' ).forEach( ( input ) => {
				input.value = '';
				input.name = input.name.replace( /^glossary\[\d+\]/, `glossary[${ index }]` );
			} );
			tbody.append( row );
			row.querySelector( 'input' ).focus();
		} );
	}

	refresh();
} )();
