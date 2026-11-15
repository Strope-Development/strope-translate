/**
 * Front-end translation editor.
 *
 * Opens a side panel when a marked element is clicked and saves the edited
 * translation into the real post content (or the strings translations) through the REST API.
 *
 * @package Strope-Translate
 * @since 4.1.0
 */
( function () {
	'use strict';

	var config = window.stropeFrontendEditor;
	if ( ! config ) {
		return;
	}

	var i18n = config.i18n || {};
	var MARKER = '[data-strope-edit]';

	var panel = null;
	var els = {};
	var state = {
		target: null, // Clicked element.
		item: null, // Data returned by the REST API.
		key: '',
		field: 'auto',
		lang: '', // Edited language, empty for the language of the page; 'all' for the overview.
		dirty: false,
		busy: false,
		returnFocus: null,
	};
	var stringsData = null;
	var pageTranslations = {}; // Machine translations of pages started here, by "post:language".

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( name ) {
			var value = attrs[ name ];
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( 'text' === name ) {
				node.textContent = value;
			} else if ( 'className' === name ) {
				node.className = value;
			} else if ( 0 === name.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( name.slice( 2 ), value );
			} else {
				node.setAttribute( name, true === value ? '' : value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function escapeHtml( text ) {
		return String( text )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function request( method, endpoint, data ) {
		var url = config.root + endpoint;
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' },
		};

		if ( 'GET' === method && data ) {
			url += ( -1 === url.indexOf( '?' ) ? '?' : '&' ) + new URLSearchParams( data ).toString();
		} else if ( data ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( data );
		}

		return window.fetch( url, options ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( body ) {
					if ( ! response.ok ) {
						var error = new Error( ( body && body.message ) || i18n.error );
						error.status = response.status;
						throw error;
					}
					return body;
				} );
		} );
	}

	function setStatus( message, type ) {
		if ( ! els.status ) {
			return;
		}
		els.status.textContent = message || '';
		els.status.className = 'strope-fe-status' + ( type ? ' is-' + type : '' );
	}

	/**
	 * Converts the HTML of the editable field to the allowed inline HTML.
	 *
	 * @param {Node}    root Editable element.
	 * @param {boolean} pre  Whether new lines are kept as is.
	 * @return {string} Clean HTML.
	 */
	function cleanHtml( root, pre ) {
		var newline = pre ? '\n' : '<br>';

		function walk( node ) {
			var out = '';
			Array.prototype.forEach.call( node.childNodes, function ( child, index ) {
				if ( 3 === child.nodeType ) {
					out += escapeHtml( child.nodeValue.split( String.fromCharCode( 160 ) ).join( ' ' ) );
					return;
				}
				if ( 1 !== child.nodeType ) {
					return;
				}
				var tag = child.nodeName.toLowerCase();
				var inner = walk( child );
				switch ( tag ) {
					case 'strong':
					case 'b':
						out += inner ? '<strong>' + inner + '</strong>' : '';
						break;
					case 'em':
					case 'i':
						out += inner ? '<em>' + inner + '</em>' : '';
						break;
					case 'code':
						out += inner ? '<code>' + inner + '</code>' : '';
						break;
					case 'br':
						out += newline;
						break;
					case 'a':
						if ( child.getAttribute( 'href' ) ) {
							var attrs = ' href="' + escapeHtml( child.getAttribute( 'href' ) ) + '"';
							[ 'target', 'rel', 'hreflang', 'title', 'data-type', 'data-id' ].forEach( function ( name ) {
								if ( child.hasAttribute( name ) ) {
									attrs += ' ' + name + '="' + escapeHtml( child.getAttribute( name ) ) + '"';
								}
							} );
							out += '<a' + attrs + '>' + inner + '</a>';
						} else {
							out += inner;
						}
						break;
					case 'span':
						if ( child.getAttribute( 'lang' ) ) {
							var spanAttrs = ' lang="' + escapeHtml( child.getAttribute( 'lang' ) ) + '"';
							if ( child.getAttribute( 'dir' ) ) {
								spanAttrs += ' dir="' + escapeHtml( child.getAttribute( 'dir' ) ) + '"';
							}
							out += '<span' + spanAttrs + '>' + inner + '</span>';
						} else {
							out += inner;
						}
						break;
					case 'div':
					case 'p':
						// Line breaks inserted by the browser when pressing Enter.
						out += ( index > 0 && out && ! /(<br>|\n)$/.test( out ) ? newline : '' ) + inner;
						break;
					default:
						out += inner;
				}
			} );
			return out;
		}

		return walk( root ).replace( /(<br>)+$/, '' );
	}

	/**
	 * Cleans HTML which does not come from the page (machine translation): parsed in an inert template,
	 * then reduced to the allowed inline HTML like an edited text.
	 *
	 * @param {string}  html HTML.
	 * @param {boolean} pre  Whether new lines are kept as is.
	 * @return {string} Clean HTML.
	 */
	function cleanForeignHtml( html, pre ) {
		var template = document.createElement( 'template' );
		template.innerHTML = '<div>' + html + '</div>';
		return cleanHtml( template.content.firstChild, pre );
	}

	/**
	 * Returns the machine translation of the editor (machine translation module), or null when it is not
	 * available.
	 *
	 * @return {Object|null}
	 */
	function machineTranslation() {
		return window.stropeMtFrontend || null;
	}

	/* ------------------------------------------------------------------ */
	/* Panel                                                                */
	/* ------------------------------------------------------------------ */

	function buildPanel() {
		if ( panel ) {
			return panel;
		}

		els.close = el( 'button', {
			type: 'button',
			className: 'strope-fe-close',
			'aria-label': i18n.close,
			onclick: function () {
				closePanel();
			},
		}, [ el( 'span', { 'aria-hidden': 'true', text: '×' } ) ] );

		els.tabContent = el( 'button', {
			type: 'button',
			role: 'tab',
			id: 'strope-fe-tab-content',
			'aria-controls': 'strope-fe-tabpanel-content',
			'aria-selected': 'true',
			text: i18n.content,
			onclick: function () {
				selectTab( 'content' );
			},
		} );

		var tabs = [ els.tabContent ];

		if ( config.canStrings ) {
			els.tabStrings = el( 'button', {
				type: 'button',
				role: 'tab',
				id: 'strope-fe-tab-strings',
				'aria-controls': 'strope-fe-tabpanel-strings',
				'aria-selected': 'false',
				tabindex: '-1',
				text: i18n.strings,
				onclick: function () {
					selectTab( 'strings' );
				},
			} );
			tabs.push( els.tabStrings );
		}

		els.tablist = el( 'div', { role: 'tablist', className: 'strope-fe-tabs', onkeydown: onTabKeydown }, tabs );

		els.contentPanel = el( 'div', {
			role: 'tabpanel',
			id: 'strope-fe-tabpanel-content',
			'aria-labelledby': 'strope-fe-tab-content',
			className: 'strope-fe-tabpanel',
		}, [ el( 'p', { className: 'strope-fe-intro', text: i18n.intro } ) ] );

		els.stringsPanel = el( 'div', {
			role: 'tabpanel',
			id: 'strope-fe-tabpanel-strings',
			'aria-labelledby': 'strope-fe-tab-strings',
			className: 'strope-fe-tabpanel',
			hidden: true,
		} );

		els.status = el( 'div', { className: 'strope-fe-status', role: 'status', 'aria-live': 'polite' } );

		els.badge = config.language ? el( 'span', { className: 'strope-fe-badge', text: config.language.name } ) : null;
		els.langSelect = el( 'select', {
			className: 'strope-fe-lang-select',
			'aria-label': i18n.editedLanguage,
			hidden: true,
			onchange: function () {
				var value = els.langSelect.value;
				var previous = state.lang;
				state.lang = config.language && value === config.language.slug ? '' : value;
				if ( ! edit( state.key, state.field, state.target ) ) {
					state.lang = previous;
					updateLangSelect( state.item && state.item.languages );
				}
			},
		} );

		panel = el( 'aside', {
			id: 'strope-fe-panel',
			className: 'strope-fe-panel',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-labelledby': 'strope-fe-panel-title',
			dir: document.documentElement.dir || null,
			hidden: true,
			onkeydown: onPanelKeydown,
		}, [
			el( 'div', { className: 'strope-fe-header' }, [
				el( 'h2', { id: 'strope-fe-panel-title', className: 'strope-fe-title', text: i18n.panelTitle } ),
				els.badge,
				els.langSelect,
				els.close,
			] ),
			els.tablist,
			els.contentPanel,
			els.stringsPanel,
			els.status,
		] );

		document.body.appendChild( panel );
		return panel;
	}

	function openPanel( tab ) {
		buildPanel();
		if ( panel.hidden ) {
			state.returnFocus = document.activeElement;
			panel.hidden = false;
			document.body.classList.add( 'strope-fe-panel-open' );
		}
		selectTab( tab || 'content' );
	}

	function closePanel() {
		if ( ! panel || panel.hidden ) {
			return;
		}
		if ( state.dirty && ! window.confirm( i18n.unsavedConfirm ) ) {
			return;
		}
		resetContent();
		state.lang = '';
		panel.hidden = true;
		document.body.classList.remove( 'strope-fe-panel-open' );
		if ( state.returnFocus && state.returnFocus.focus && document.contains( state.returnFocus ) ) {
			state.returnFocus.focus();
		}
	}

	function selectTab( name ) {
		var strings = 'strings' === name && els.tabStrings;
		els.tabContent.setAttribute( 'aria-selected', strings ? 'false' : 'true' );
		els.tabContent.tabIndex = strings ? -1 : 0;
		els.contentPanel.hidden = !! strings;
		if ( els.tabStrings ) {
			els.tabStrings.setAttribute( 'aria-selected', strings ? 'true' : 'false' );
			els.tabStrings.tabIndex = strings ? 0 : -1;
			els.stringsPanel.hidden = ! strings;
		}
		if ( strings ) {
			renderStrings();
		}
		updateLangSelect( ! strings && state.item ? state.item.languages : null );
	}

	/**
	 * Shows the language switch of the edited text (or the language badge of the page when no text is edited).
	 *
	 * @param {Array|null} languages Languages and status of the text in each of them.
	 */
	function updateLangSelect( languages ) {
		if ( ! els.langSelect ) {
			return;
		}
		var show = !! ( languages && languages.length > 1 );
		els.langSelect.hidden = ! show;
		if ( els.badge ) {
			els.badge.hidden = show;
		}
		if ( ! show ) {
			return;
		}

		var labels = { missing: i18n.statusMissing, mismatch: i18n.statusMismatch, forbidden: i18n.statusForbidden };
		els.langSelect.textContent = '';
		languages.forEach( function ( language ) {
			var label = 'current' === language.status ? i18n.thisPage.replace( '%s', language.name ) : language.name;
			if ( labels[ language.status ] ) {
				label += ' (' + labels[ language.status ] + ')';
			}
			els.langSelect.appendChild( el( 'option', { value: language.slug, text: label } ) );
		} );
		els.langSelect.appendChild( el( 'option', { value: 'all', text: i18n.allLanguages } ) );
		els.langSelect.value = state.lang || ( config.language ? config.language.slug : '' );
	}

	function onTabKeydown( event ) {
		if ( ! els.tabStrings || ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) ) {
			return;
		}
		var toStrings = 'false' === els.tabStrings.getAttribute( 'aria-selected' );
		selectTab( toStrings ? 'strings' : 'content' );
		( toStrings ? els.tabStrings : els.tabContent ).focus();
		event.preventDefault();
	}

	function onPanelKeydown( event ) {
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			event.stopPropagation();
			onEscape();
		} else if ( 'Enter' === event.key && ( event.ctrlKey || event.metaKey ) && state.item && els.save && ! els.save.disabled ) {
			event.preventDefault();
			save();
		}
	}

	function onEscape() {
		if ( state.item && ! els.contentPanel.hidden ) {
			cancelEdit();
		} else {
			closePanel();
		}
	}

	function resetContent() {
		if ( state.target ) {
			state.target.classList.remove( 'strope-fe-active' );
		}
		state.target = null;
		state.item = null;
		state.key = '';
		state.dirty = false;
		els.save = null;
		els.editor = null;
		setStatus( '' );
		updateLangSelect( null );
		els.contentPanel.textContent = '';
		els.contentPanel.appendChild( el( 'p', { className: 'strope-fe-intro', text: i18n.intro } ) );
	}

	function cancelEdit() {
		if ( state.dirty && ! window.confirm( i18n.unsavedConfirm ) ) {
			return;
		}
		var target = state.target;
		state.dirty = false;
		resetContent();
		if ( target && target.focus ) {
			target.focus();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Content editing                                                      */
	/* ------------------------------------------------------------------ */

	function parseKey( key ) {
		var index = key.indexOf( ':' );
		return { post: key.slice( 0, index ), path: key.slice( index + 1 ) };
	}

	function edit( key, field, target ) {
		if ( state.dirty && ! window.confirm( i18n.unsavedConfirm ) ) {
			return false;
		}

		openPanel( 'content' );
		resetContent();

		state.key = key;
		state.field = field || 'auto';
		state.target = target || null;
		if ( state.target ) {
			state.target.classList.add( 'strope-fe-active' );
		}

		var params = parseKey( key );
		params.field = state.field;
		params.lang = state.lang;

		els.contentPanel.textContent = '';
		setStatus( i18n.loading );

		request( 'GET', 'block', params )
			.then( function ( item ) {
				if ( state.key !== key ) {
					return;
				}
				setStatus( '' );
				state.item = item;
				state.field = item.field || 'auto';
				updateLangSelect( item.languages );
				if ( item.overview ) {
					renderOverview( item );
				} else {
					renderItem( item );
				}
			} )
			.catch( function ( error ) {
				setStatus( error.message, 'error' );
			} );

		return true;
	}

	function linkButtons( links ) {
		var buttons = [];
		if ( links && links.open ) {
			buttons.push( el( 'a', { className: 'strope-fe-button', href: links.open, text: i18n.openPage } ) );
		}
		if ( links && links.add ) {
			buttons.push( el( 'a', { className: 'strope-fe-button', href: links.add, target: '_blank', rel: 'noopener', text: i18n.addTranslation } ) );
		}
		return buttons;
	}

	/**
	 * Displays the text in all the languages, each with a button to edit it.
	 */
	function renderOverview( item ) {
		var box = els.contentPanel;
		box.textContent = '';

		box.appendChild( el( 'dl', { className: 'strope-fe-meta' }, [
			el( 'dt', { text: i18n.block } ),
			el( 'dd', { text: item.block_title || '' } ),
		] ) );

		( item.languages || [] ).forEach( function ( language ) {
			var isPage = 'current' === language.status;
			var children = [
				el( 'h3', { className: 'strope-fe-label', text: isPage ? i18n.thisPage.replace( '%s', language.name ) : language.name } ),
			];

			if ( null !== language.html && undefined !== language.html ) {
				var value = el( 'div', {
					className: 'strope-fe-source' + ( item.pre ? ' is-pre' : '' ),
					lang: language.code || null,
					dir: language.code ? ( language.rtl ? 'rtl' : 'ltr' ) : null,
				} );
				value.innerHTML = language.html; // Sanitized server side.
				children.push( value );
			} else if ( language.message ) {
				children.push( el( 'p', { className: 'strope-fe-notice', text: language.message } ) );
			}

			var actions = [];
			if ( 'ok' === language.status || isPage ) {
				actions.push( el( 'button', {
					type: 'button',
					className: 'strope-fe-button',
					text: i18n.editLanguage,
					onclick: function () {
						state.lang = isPage ? '' : language.slug;
						edit( state.key, state.field, state.target );
					},
				} ) );
			}
			actions = actions.concat( linkButtons( language.links ) );

			var pageButton = translatePageButton( item, language );
			if ( pageButton ) {
				actions.push( pageButton );
			}
			if ( actions.length ) {
				children.push( el( 'div', { className: 'strope-fe-actions' }, actions ) );
			}

			var progress = pageTranslations[ item.viewed + ':' + language.slug ];
			if ( progress ) {
				children.push( el( 'p', { className: 'strope-fe-notice strope-fe-mt-note' + ( progress.error ? ' is-error' : '' ), role: 'status', text: progress.text } ) );
				if ( progress.edit ) {
					children.push( el( 'div', { className: 'strope-fe-actions' }, [
						el( 'a', { className: 'strope-fe-button', href: progress.edit, target: '_blank', rel: 'noopener', text: machineTranslation().i18n.editDraft } ),
					] ) );
				}
			}

			box.appendChild( el( 'div', { className: 'strope-fe-section' }, children ) );
		} );

		box.appendChild( el( 'div', { className: 'strope-fe-actions' }, [
			el( 'button', { type: 'button', className: 'strope-fe-button is-link', text: i18n.cancel, onclick: cancelEdit } ),
		] ) );
		els.close.focus();
	}

	/**
	 * Returns the button adding the page to the machine translation queue, for a language without a
	 * translation of the page, or null.
	 *
	 * @param {Object} item     Overview.
	 * @param {Object} language Language of the overview.
	 * @return {HTMLElement|null}
	 */
	function translatePageButton( item, language ) {
		var mt = machineTranslation();
		var page = ( item.languages || [] ).filter( function ( other ) {
			return 'current' === other.status;
		} )[ 0 ];
		var key = item.viewed + ':' + language.slug;

		if ( ! mt || ! page || 'missing' !== language.status || ! mt.canTranslate( page.slug, language.slug ) || ( pageTranslations[ key ] && ! pageTranslations[ key ].error ) ) {
			return null;
		}

		var button = el( 'button', {
			type: 'button',
			className: 'strope-fe-button',
			text: mt.i18n.translatePage,
			onclick: function () {
				button.disabled = true;
				pageTranslations[ key ] = { text: mt.i18n.queued };
				renderOverview( item );

				var update = function ( progress ) {
					pageTranslations[ key ] = progress;
					if ( state.item === item ) {
						renderOverview( item );
					}
				};

				mt.translatePost( item.viewed, language.slug, function ( line, workerState ) {
					var current = pageTranslations[ key ];
					if ( current && ! current.edit && ! current.error && -1 !== [ 'starting', 'loading', 'downloading' ].indexOf( workerState ) ) {
						update( { text: line } );
					}
				} )
					.then( function ( result ) {
						mt.watchRun( result.run, function ( job ) {
							if ( 'done' === job.status ) {
								update( { text: mt.i18n.pageDone, edit: job.edit } );
								// The page now has a translation: show it (the note stays).
								if ( state.item === item ) {
									edit( state.key, state.field, state.target );
								}
							} else if ( 'failed' === job.status || 'cancelled' === job.status || 'error' === job.status ) {
								update( { text: mt.i18n.pageFailed.replace( '%s', job.message || job.status ), error: true } );
							} else {
								update( { text: mt.i18n.progress.replace( '%d', Math.round( ( job.progress || 0 ) * 100 ) ).replace( '%%', '%' ) } );
							}
						} );
					} )
					.catch( function ( error ) {
						update( { text: mt.i18n.pageFailed.replace( '%s', error.message ), error: true } );
					} );
			},
		} );

		return button;
	}

	/**
	 * Returns the button filling the editor with a machine translation of the source text, or null.
	 *
	 * @param {Object} item Edited text.
	 * @param {Object} lang Language of the edited text.
	 * @return {HTMLElement|null}
	 */
	function machineTranslateButton( item, lang ) {
		var mt = machineTranslation();
		var source = item.source || {};
		var from = source.language || {};

		if ( ! mt || ! item.editable || 'string' !== typeof source.text || ! mt.isText( source.text ) || ! from.slug || ! lang.slug || ! mt.canTranslate( from.slug, lang.slug ) ) {
			return null;
		}

		var button = el( 'button', {
			type: 'button',
			className: 'strope-fe-button is-small strope-fe-mt-button',
			text: mt.i18n.translateFrom.replace( '%s', from.name || from.slug ),
			onclick: function () {
				if ( state.busy || ! els.editor || ( state.dirty && ! window.confirm( i18n.unsavedConfirm ) ) ) {
					return;
				}

				var editor = els.editor;
				state.busy = true;
				button.disabled = true;
				setStatus( mt.i18n.working );

				mt.translate( from.slug, lang.slug, [ source.text ], function ( line ) {
					setStatus( line );
				} )
					.then( function ( texts ) {
						if ( editor !== els.editor ) {
							return; // Another text was opened meanwhile.
						}
						if ( false === texts[ 0 ] ) {
							setStatus( mt.i18n.unchanged );
							return;
						}
						if ( null === texts[ 0 ] ) {
							setStatus( mt.i18n.noResult, 'error' );
							return;
						}
						if ( item.plain ) {
							editor.value = texts[ 0 ];
						} else {
							editor.innerHTML = cleanForeignHtml( texts[ 0 ], item.pre );
						}
						editor.classList.add( 'is-machine' );
						state.dirty = true;
						setStatus( mt.i18n.filled, 'success' );
						editor.focus();
					} )
					.catch( function ( error ) {
						setStatus( mt.i18n.error.replace( '%s', error.message ), 'error' );
					} )
					.then( function () {
						state.busy = false;
						button.disabled = false;
					} );
			},
		} );

		return button;
	}

	function renderItem( item ) {
		var lang = item.language || config.language || {};
		var box = els.contentPanel;
		box.textContent = '';

		var meta = el( 'dl', { className: 'strope-fe-meta' }, [
			el( 'dt', { text: i18n.block } ),
			el( 'dd', { text: item.block_title + ( item.block && item.block !== item.block_title && -1 !== item.block.indexOf( '/' ) ? ' (' + item.block + ')' : '' ) } ),
			el( 'dt', { text: i18n.language } ),
			el( 'dd', { text: lang.name || '' } ),
		] );
		box.appendChild( meta );

		if ( item.fields && item.fields.length > 1 ) {
			var select = el( 'select', {
				id: 'strope-fe-field',
				onchange: function () {
					edit( state.key, select.value, state.target );
				},
			}, item.fields.map( function ( field ) {
				return el( 'option', { value: field.name, selected: field.name === item.field, text: field.label } );
			} ) );
			box.appendChild( el( 'p', { className: 'strope-fe-field-select' }, [
				el( 'label', { for: 'strope-fe-field', text: i18n.field } ),
				select,
			] ) );
		}

		if ( item.supported ) {
			var source = item.source || {};
			var sourceLang = source.language || {};
			var sourceBox = el( 'div', {
				className: 'strope-fe-source' + ( item.pre ? ' is-pre' : '' ),
				id: 'strope-fe-source',
				lang: sourceLang.code || null,
				dir: sourceLang.code ? ( sourceLang.rtl ? 'rtl' : 'ltr' ) : null,
			} );
			if ( null !== source.html && undefined !== source.html ) {
				sourceBox.innerHTML = source.html; // Sanitized server side.
			} else {
				sourceBox.classList.add( 'is-empty' );
				sourceBox.textContent = source.message || '';
			}
			box.appendChild( el( 'div', { className: 'strope-fe-section' }, [
				el( 'h3', { className: 'strope-fe-label', text: i18n.source + ( sourceLang.name ? ' (' + sourceLang.name + ')' : '' ) } ),
				sourceBox,
			] ) );
		}

		if ( item.viewed && Number( item.post ) !== Number( item.viewed ) && config.language ) {
			// The text of a translation, not the one displayed on the page.
			box.appendChild( el( 'p', { className: 'strope-fe-notice', text: i18n.otherLanguage.replace( '%1$s', lang.name || '' ).replace( '%2$s', config.language.name ) } ) );
		}

		if ( item.message ) {
			box.appendChild( el( 'p', { className: 'strope-fe-notice', text: item.message } ) );
		}

		if ( item.supported && item.plain ) {
			// Title and excerpt: raw value (markup included) edited as text, sanitized server side.
			var textarea = el( 'textarea', {
				className: 'strope-fe-editor is-plain',
				id: 'strope-fe-editor',
				rows: 'title' === item.block || ( 'acf' === item.block && ! item.multiline ) ? 2 : ( 'acf' === item.block ? 8 : 5 ),
				'aria-labelledby': 'strope-fe-editor-label',
				'aria-describedby': 'strope-fe-shortcuts',
				lang: lang.code || null,
				dir: lang.code ? ( lang.rtl ? 'rtl' : 'ltr' ) : null,
				oninput: function () {
					state.dirty = true;
				},
			} );
			textarea.value = item.text;
			els.editor = textarea;
			box.appendChild( el( 'div', { className: 'strope-fe-section' }, [
				el( 'h3', { className: 'strope-fe-label', id: 'strope-fe-editor-label', text: i18n.translation } ),
				machineTranslateButton( item, lang ),
				textarea,
				el( 'p', { className: 'strope-fe-help', id: 'strope-fe-shortcuts', text: i18n.shortcuts } ),
			] ) );
		} else if ( item.supported ) {
			var editor = el( 'div', {
				className: 'strope-fe-editor' + ( item.pre ? ' is-pre' : '' ),
				id: 'strope-fe-editor',
				contenteditable: item.editable ? 'true' : 'false',
				role: 'textbox',
				'aria-multiline': 'true',
				'aria-labelledby': 'strope-fe-editor-label',
				'aria-describedby': 'strope-fe-shortcuts',
				'aria-readonly': item.editable ? null : 'true',
				lang: lang.code || null,
				dir: lang.code ? ( lang.rtl ? 'rtl' : 'ltr' ) : null,
				oninput: function () {
					state.dirty = true;
				},
				onpaste: onPaste,
			} );
			editor.innerHTML = item.html; // Current content of the post, sanitized server side (inline HTML only).
			els.editor = editor;

			var children = [ el( 'h3', { className: 'strope-fe-label', id: 'strope-fe-editor-label', text: i18n.translation } ), machineTranslateButton( item, lang ) ];
			if ( item.editable ) {
				children.push( buildToolbar() );
			}
			children.push( editor );
			children.push( el( 'p', { className: 'strope-fe-help', id: 'strope-fe-shortcuts', text: i18n.shortcuts } ) );
			box.appendChild( el( 'div', { className: 'strope-fe-section' }, children ) );
		}

		var actions = el( 'div', { className: 'strope-fe-actions' } );
		if ( item.editable ) {
			els.save = el( 'button', { type: 'button', className: 'strope-fe-button is-primary', text: i18n.save, onclick: save } );
			actions.appendChild( els.save );
		}
		linkButtons( item.links ).forEach( function ( button ) {
			actions.appendChild( button );
		} );
		if ( item.edit_link ) {
			actions.appendChild( el( 'a', { className: 'strope-fe-button', href: item.edit_link, target: '_blank', rel: 'noopener', text: i18n.openEditor } ) );
		}
		actions.appendChild( el( 'button', { type: 'button', className: 'strope-fe-button is-link', text: i18n.cancel, onclick: cancelEdit } ) );
		box.appendChild( actions );

		( els.editor && item.editable ? els.editor : els.close ).focus();
	}

	function onPaste( event ) {
		// Paste as plain text to avoid foreign markup.
		if ( ! event.clipboardData ) {
			return;
		}
		event.preventDefault();
		var text = event.clipboardData.getData( 'text/plain' );
		document.execCommand( 'insertText', false, text );
	}

	function buildToolbar() {
		function button( label, text, command ) {
			return el( 'button', {
				type: 'button',
				className: 'strope-fe-tool',
				'aria-label': label,
				title: label,
				text: text,
				onmousedown: function ( event ) {
					event.preventDefault(); // Keep the selection in the editor.
				},
				onclick: function () {
					els.editor.focus();
					command();
					state.dirty = true;
				},
			} );
		}

		return el( 'div', { className: 'strope-fe-toolbar', role: 'toolbar', 'aria-label': i18n.translation, 'aria-controls': 'strope-fe-editor' }, [
			button( i18n.bold, 'B', function () {
				document.execCommand( 'bold' );
			} ),
			button( i18n.italic, 'I', function () {
				document.execCommand( 'italic' );
			} ),
			button( i18n.code, '</>', wrapCode ),
			button( i18n.link, '\u2197', function () {
				var url = window.prompt( i18n.linkPrompt, 'https://' );
				if ( url ) {
					document.execCommand( 'createLink', false, url );
				}
			} ),
			button( i18n.unlink, '✕', function () {
				document.execCommand( 'unlink' );
			} ),
		] );
	}

	function wrapCode() {
		var selection = window.getSelection();
		if ( ! selection.rangeCount || selection.isCollapsed || ! els.editor.contains( selection.anchorNode ) ) {
			return;
		}
		var range = selection.getRangeAt( 0 );
		var code = document.createElement( 'code' );
		code.appendChild( range.extractContents() );
		range.insertNode( code );
	}

	function save() {
		if ( ! state.item || state.busy || ! els.editor ) {
			return;
		}

		var item = state.item;
		var params = parseKey( state.key );
		var content = item.plain ? els.editor.value : cleanHtml( els.editor, item.pre );

		state.busy = true;
		els.save.disabled = true;
		setStatus( i18n.saving );

		request( 'POST', 'block', {
			post: Number( item.post || params.post ), // The translation when editing another language.
			path: params.path,
			field: item.field,
			block: -1 !== String( item.block ).indexOf( '/' ) ? item.block : '',
			hash: item.hash,
			content: content,
		} )
			.then( function ( result ) {
				state.dirty = false;
				item.hash = result.hash;
				els.editor.classList.remove( 'is-machine' );
				if ( item.plain ) {
					item.text = result.text;
					els.editor.value = result.text;
				} else {
					item.html = result.html;
					els.editor.innerHTML = result.html; // Sanitized server side.
				}
				// The page displays the text of its own language only.
				if ( ! item.viewed || Number( item.post ) === Number( item.viewed ) ) {
					updatePage( state.key, item, result );
				}
				setStatus( i18n.saved, 'success' );
			} )
			.catch( function ( error ) {
				setStatus( error.message, 'error' );
			} )
			.then( function () {
				state.busy = false;
				if ( els.save ) {
					els.save.disabled = false;
				}
			} );
	}

	/**
	 * Updates the edited element(s) in place after a save.
	 */
	function updatePage( key, item, result ) {
		var blocks = document.querySelectorAll( '[data-strope-edit="' + ( window.CSS && CSS.escape ? CSS.escape( key ) : key ) + '"]' );

		Array.prototype.forEach.call( blocks, function ( block ) {
			if ( 'acf' === item.block ) {
				if ( item.multiline ) {
					block.innerHTML = result.rendered; // Sanitized server side.
				} else {
					block.textContent = result.text;
				}
				return;
			}
			if ( 'title' === item.block ) {
				( block.querySelector( 'a' ) || block ).innerHTML = result.rendered; // Sanitized server side.
				return;
			}
			if ( 'excerpt' === item.block ) {
				( block.querySelector( '.wp-block-post-excerpt__excerpt' ) || block ).innerHTML = result.rendered;
				return;
			}

			var target = block.getAttribute( 'data-strope-field' ) === item.field ? block : null;
			if ( ! target ) {
				Array.prototype.some.call( block.querySelectorAll( '[data-strope-field="' + item.field + '"]' ), function ( candidate ) {
					if ( candidate.closest( MARKER ) === block ) {
						target = candidate;
						return true;
					}
					return false;
				} );
			}
			if ( ! target ) {
				return;
			}

			// Keep nested blocks (e.g. nested lists in a list item).
			var nested = Array.prototype.filter.call( target.children, function ( child ) {
				return child.matches( MARKER ) || child.querySelector( MARKER );
			} );
			if ( nested.length ) {
				var first = nested[ 0 ];
				while ( target.firstChild && target.firstChild !== first ) {
					target.removeChild( target.firstChild );
				}
				var fragment = document.createRange().createContextualFragment( result.rendered );
				target.insertBefore( fragment, first );
			} else {
				target.innerHTML = result.rendered;
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Strings                                                              */
	/* ------------------------------------------------------------------ */

	function getStrings() {
		if ( null === stringsData ) {
			stringsData = { language: '', strings: [] };
			var node = document.getElementById( 'strope-fe-strings-data' );
			if ( node ) {
				try {
					stringsData = JSON.parse( node.textContent );
				} catch ( e ) {}
			}
		}
		return stringsData;
	}

	function renderStrings() {
		var box = els.stringsPanel;
		if ( box.getAttribute( 'data-rendered' ) ) {
			return;
		}
		box.setAttribute( 'data-rendered', '1' );

		var data = getStrings();
		var lang = config.language || {};

		if ( ! data.strings.length ) {
			box.appendChild( el( 'p', { className: 'strope-fe-intro', text: i18n.noStrings } ) );
			return;
		}

		var list = el( 'ul', { className: 'strope-fe-strings' } );
		var filter = el( 'input', {
			type: 'search',
			className: 'strope-fe-filter',
			'aria-label': i18n.filter,
			placeholder: i18n.filter,
			oninput: function () {
				var term = filter.value.toLowerCase();
				Array.prototype.forEach.call( list.children, function ( li ) {
					li.hidden = term && -1 === li.getAttribute( 'data-search' ).indexOf( term );
				} );
			},
		} );

		var mt = machineTranslation();
		var translatable = []; // Empty strings which can be machine translated: { string, textarea, status }.
		var mtStatus = el( 'p', { className: 'strope-fe-string-status strope-fe-mt-note', role: 'status' } );

		var sourceOf = function ( string ) {
			return 'registered' === string.type ? mt.defaultSource : mt.englishSource;
		};

		var canMachineTranslate = function ( string, textarea ) {
			return !! mt && ! string.plural && '' === textarea.value.trim() && mt.isText( string.singular ) && mt.canTranslate( sourceOf( string ), data.language );
		};

		var machineTranslate = function ( entries, done ) {
			// One request per source language (registered strings and theme or plugin strings may differ).
			var bySource = {};
			entries.forEach( function ( entry ) {
				var from = sourceOf( entry.string );
				( bySource[ from ] = bySource[ from ] || [] ).push( entry );
			} );

			var filled = 0;
			var chain = Promise.resolve();
			Object.keys( bySource ).forEach( function ( from ) {
				chain = chain.then( function () {
					return mt.translate( from, data.language, bySource[ from ].map( function ( entry ) {
						return entry.string.singular;
					} ), function ( line ) {
						mtStatus.textContent = line;
					} ).then( function ( texts ) {
						bySource[ from ].forEach( function ( entry, i ) {
							if ( false === texts[ i ] ) {
								entry.status.textContent = mt.i18n.unchanged;
							} else if ( null !== texts[ i ] && '' === entry.textarea.value.trim() ) {
								entry.textarea.value = texts[ i ];
								entry.textarea.classList.add( 'is-machine' );
								entry.status.textContent = mt.i18n.filled;
								filled++;
							}
						} );
					} );
				} );
			} );

			return chain.then( function () {
				done( filled );
			} );
		};

		data.strings.forEach( function ( string, index ) {
			var id = 'strope-fe-string-' + index;
			var editable = 'registered' === string.type || ( config.canOverride && false !== string.editable );
			var textarea = el( 'textarea', {
				id: id,
				rows: string.singular.length > 60 ? 3 : 1,
				lang: lang.code || null,
				dir: lang.code ? ( lang.rtl ? 'rtl' : 'ltr' ) : null,
				readonly: ! editable,
			} );
			textarea.value = string.translation;

			var status = el( 'span', { className: 'strope-fe-string-status', role: 'status' } );
			var button = editable ? el( 'button', {
				type: 'button',
				className: 'strope-fe-button is-small',
				text: i18n.save,
				onclick: function () {
					button.disabled = true;
					status.textContent = i18n.saving;
					request( 'POST', 'string', {
						type: string.type,
						lang: data.language,
						domain: string.domain,
						context: string.context,
						singular: string.singular,
						id: string.id,
						translation: textarea.value,
					} )
						.then( function ( result ) {
							textarea.value = result.translation;
							string.translation = result.translation;
							status.textContent = i18n.reloadToSee;
						} )
						.catch( function ( error ) {
							status.textContent = error.message;
						} )
						.then( function () {
							button.disabled = false;
						} );
				},
			} ) : null;

			var mtButton = null;
			if ( editable && canMachineTranslate( string, textarea ) ) {
				var entry = { string: string, textarea: textarea, status: status };
				translatable.push( entry );
				mtButton = el( 'button', {
					type: 'button',
					className: 'strope-fe-button is-small is-link',
					text: mt.i18n.translateString,
					onclick: function () {
						mtButton.disabled = true;
						status.textContent = mt.i18n.working;
						machineTranslate( [ entry ], function ( filled ) {
							if ( ! filled && mt.i18n.unchanged !== status.textContent ) {
								status.textContent = mt.i18n.noResult;
							}
						} )
							.catch( function ( error ) {
								status.textContent = mt.i18n.error.replace( '%s', error.message );
							} )
							.then( function () {
								mtButton.disabled = false;
							} );
					},
				} );
			}

			var details = 'registered' === string.type ? i18n.registered : string.domain + ( string.context ? ' — ' + string.context : '' );

			list.appendChild( el( 'li', { 'data-search': ( string.singular + ' ' + string.translation ).toLowerCase() }, [
				el( 'label', { for: id, className: 'strope-fe-string-source', text: string.singular } ),
				el( 'span', { className: 'strope-fe-string-details', text: details } ),
				textarea,
				el( 'div', { className: 'strope-fe-string-actions' }, [
					button,
					mtButton,
					editable ? null : el( 'span', { text: i18n.notOverridable } ),
					status,
				] ),
			] ) );
		} );

		box.appendChild( filter );

		if ( translatable.length ) {
			var all = el( 'button', {
				type: 'button',
				className: 'strope-fe-button is-small',
				text: mt.i18n.translateEmpty,
				onclick: function () {
					all.disabled = true;
					mtStatus.textContent = mt.i18n.working;
					machineTranslate( translatable.filter( function ( entry ) {
						return '' === entry.textarea.value.trim();
					} ), function ( filled ) {
						mtStatus.textContent = mt.i18n.stringsFilled.replace( '%d', filled );
					} )
						.catch( function ( error ) {
							mtStatus.textContent = mt.i18n.error.replace( '%s', error.message );
						} )
						.then( function () {
							all.disabled = false;
						} );
				},
			} );
			box.appendChild( el( 'div', { className: 'strope-fe-actions strope-fe-mt-strings' }, [ all, mtStatus ] ) );
		}

		box.appendChild( list );
	}

	/* ------------------------------------------------------------------ */
	/* Page interactions                                                    */
	/* ------------------------------------------------------------------ */

	function isEditorUi( node ) {
		return !! ( node && node.closest && node.closest( '#strope-fe-panel, #wpadminbar' ) );
	}

	function targetOf( node ) {
		var block = node.closest( MARKER );
		if ( ! block ) {
			return null;
		}
		var field = node.closest( '[data-strope-field]' );
		var fieldName = field && field.closest( MARKER ) === block ? field.getAttribute( 'data-strope-field' ) : 'auto';
		return { block: block, key: block.getAttribute( 'data-strope-edit' ), field: fieldName };
	}

	document.addEventListener( 'click', function ( event ) {
		var node = event.target && 1 === event.target.nodeType ? event.target : event.target && event.target.parentElement;
		if ( ! node || isEditorUi( node ) || event.altKey ) {
			return;
		}

		var target = targetOf( node );
		if ( target ) {
			event.preventDefault();
			event.stopPropagation();
			edit( target.key, target.field, target.block );
		} else if ( node.closest( 'a[href]' ) ) {
			// Links are disabled while editing, Alt+click follows them.
			event.preventDefault();
			setStatus( i18n.intro );
		}
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && panel && ! panel.hidden && ! isEditorUi( event.target ) ) {
			onEscape();
			return;
		}
		if ( ( 'Enter' !== event.key && ' ' !== event.key ) || event.altKey || ! event.target.matches || ! event.target.matches( MARKER ) || isEditorUi( event.target ) ) {
			return;
		}
		event.preventDefault();
		var target = targetOf( event.target );
		edit( target.key, target.field, target.block );
	} );

	/* ------------------------------------------------------------------ */
	/* Custom fields                                                        */
	/* ------------------------------------------------------------------ */

	function normalizeText( text ) {
		return String( text || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/**
	 * Marks the texts of the custom fields rendered on the page (e.g. ACF flexible content blocks).
	 * The server lists the fields formatted while rendering the page, with their text; the elements
	 * displaying this text are marked here, in the editor's browser only.
	 */
	function markCustomFields() {
		var node = document.getElementById( 'strope-fe-acf-data' );
		var fields = [];
		var byText = {};
		var used = {};

		if ( node ) {
			try {
				fields = JSON.parse( node.textContent ) || [];
			} catch ( e ) {
				fields = [];
			}
		}

		fields.forEach( function ( field ) {
			if ( field && field.key && field.text && field.text.length > 1 ) {
				( byText[ field.text ] = byText[ field.text ] || [] ).push( field );
			}
		} );

		if ( ! Object.keys( byText ).length ) {
			return;
		}

		var root = document.querySelector( 'main, [role="main"]' ) || document.body;
		var excluded = MARKER + ', #wpadminbar, #strope-fe-panel, script, style, noscript, template, textarea, select, option';

		function mark( element, text ) {
			var list = byText[ text ];
			var count = used[ text ] || 0;
			// Identical texts are assigned to the fields in their rendering order.
			var field = list[ Math.min( count, list.length - 1 ) ];
			used[ text ] = count + 1;

			if ( field.html ) {
				// Text areas and editors: the element containing all the formatted value (e.g. its paragraphs).
				while ( element.parentElement && element.parentElement !== root && 1 === element.parentElement.children.length && normalizeText( element.parentElement.textContent ) === text ) {
					element = element.parentElement;
				}
			}

			element.setAttribute( 'data-strope-edit', field.key );
			element.setAttribute( 'data-strope-field', 'value' );
			if ( null === element.getAttribute( 'tabindex' ) ) {
				element.setAttribute( 'tabindex', '0' );
			}
		}

		// The innermost elements whose whole text is the text of a field.
		Array.prototype.forEach.call( root.querySelectorAll( '*' ), function ( element ) {
			if ( element.closest( excluded ) ) {
				return;
			}
			var text = normalizeText( element.textContent );
			if ( ! byText[ text ] ) {
				return;
			}
			var deeper = Array.prototype.some.call( element.children, function ( child ) {
				return normalizeText( child.textContent ) === text;
			} );
			if ( ! deeper ) {
				mark( element, text );
			}
		} );

		// Single line texts sharing their element with other content (e.g. a title followed by a span).
		var walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		var texts = [];
		while ( walker.nextNode() ) {
			texts.push( walker.currentNode );
		}
		texts.forEach( function ( textNode ) {
			var text = normalizeText( textNode.nodeValue );
			var parent = textNode.parentElement;
			if ( ! byText[ text ] || byText[ text ][ 0 ].html || ! parent || parent.closest( excluded ) ) {
				return;
			}
			// Only the text is wrapped, the white spaces around it stay outside (e.g. before a following span).
			var value = textNode.nodeValue;
			var start = value.length - value.replace( /^\s+/, '' ).length;
			var length = value.replace( /\s+$/, '' ).length - start;
			if ( start > 0 ) {
				textNode = textNode.splitText( start );
			}
			if ( length < textNode.nodeValue.length ) {
				textNode.splitText( length );
			}
			var span = document.createElement( 'span' );
			span.className = 'strope-fe-field';
			parent.insertBefore( span, textNode );
			span.appendChild( textNode );
			mark( span, text );
		} );
	}

	function onReady() {
		markCustomFields();

		// Text to select, when opened from the panel of another page (e.g. another language).
		var focus = new URLSearchParams( window.location.search ).get( 'strope_fe_focus' );
		if ( focus && config.post && /^[A-Za-z0-9_:.-]{1,200}$/.test( focus ) ) {
			var key = config.post + ':' + focus;
			var element = document.querySelector( '[data-strope-edit="' + ( window.CSS && CSS.escape ? CSS.escape( key ) : key ) + '"]' );
			if ( element ) {
				element.scrollIntoView( { block: 'center' } );
				edit( key, 'auto', element );
				return;
			}
			if ( 'title' === focus || 'excerpt' === focus ) {
				edit( key, 'text', null );
				return;
			}
		}

		if ( ! document.querySelector( MARKER ) ) {
			openPanel( 'content' );
			els.contentPanel.textContent = '';
			els.contentPanel.appendChild( el( 'p', { className: 'strope-fe-notice', text: i18n.nothingEditable } ) );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', onReady );
	} else {
		onReady();
	}

	// Admin bar entries.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '#wpadminbar a[href^="#strope-fe-"]' );
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		var action = link.getAttribute( 'href' ).slice( '#strope-fe-'.length );
		if ( 'strings' === action ) {
			openPanel( 'strings' );
		} else if ( ( 'title' === action || 'excerpt' === action ) && config.post ) {
			var marked = document.querySelector( '[data-strope-edit="' + config.post + ':' + action + '"]' );
			edit( config.post + ':' + action, 'text', marked );
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( state.dirty ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
} )();
