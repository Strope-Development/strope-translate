/**
 * Block editor document settings panel displaying the translation status of the edited post.
 *
 * Reads the `strope_translation_status` REST field of the edited post and uses the
 * `strope/v1/translation-status/<id>` route to mark translations as up to date or reviewed.
 *
 * @package Strope-Translate
 * @since 4.1.0
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.apiFetch ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var PluginDocumentSettingPanel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	/**
	 * Returns the label of a status.
	 *
	 * @param {Object} entry Status entry.
	 * @return {string} Label.
	 */
	function getStatusLabel( entry ) {
		if ( 'missing' === entry.status ) {
			return __( 'Missing', 'strope-translate' );
		}
		if ( entry.is_current && entry.synchronized ) {
			return __( 'This post (synchronized)', 'strope-translate' );
		}
		if ( entry.is_current && entry.is_original ) {
			return __( 'This post (original)', 'strope-translate' );
		}
		var label = 'outdated' === entry.status ? __( 'Outdated', 'strope-translate' ) : __( 'Up to date', 'strope-translate' );
		if ( entry.is_current ) {
			/* translators: %s is a status, e.g. Outdated */
			return sprintf( __( 'This post: %s', 'strope-translate' ), label );
		}
		return label;
	}

	/**
	 * One row of the panel.
	 *
	 * @param {Object} props Properties: entry, busy, onAction.
	 * @return {Object} Element.
	 */
	function StatusRow( props ) {
		var entry = props.entry;
		var actions = [];

		if ( entry.can_edit && ! entry.is_current && entry.edit_link ) {
			actions.push(
				el( Button, { key: 'edit', variant: 'link', href: entry.edit_link }, __( 'Edit', 'strope-translate' ) )
			);
		}
		if ( ! entry.translation_id && entry.add_link ) {
			actions.push(
				el( Button, { key: 'add', variant: 'link', href: entry.add_link }, __( 'Add', 'strope-translate' ) )
			);
		}
		if ( entry.can_edit && 'outdated' === entry.status ) {
			actions.push(
				el(
					Button,
					{
						key: 'up-to-date',
						variant: 'secondary',
						size: 'small',
						disabled: props.busy,
						onClick: function () {
							props.onAction( entry.translation_id, 'mark_up_to_date' );
						},
					},
					__( 'Mark as up to date', 'strope-translate' )
				)
			);
		}
		if ( entry.can_edit && entry.needs_review ) {
			actions.push(
				el(
					Button,
					{
						key: 'reviewed',
						variant: 'secondary',
						size: 'small',
						disabled: props.busy,
						onClick: function () {
							props.onAction( entry.translation_id, 'mark_reviewed' );
						},
					},
					__( 'Mark as reviewed', 'strope-translate' )
				)
			);
		}

		return el(
			'li',
			{ className: 'strope-ts-item strope-ts-' + entry.status.replace( '_', '-' ) },
			el(
				'div',
				{ className: 'strope-ts-row' },
				entry.flag_url ? el( 'img', { src: entry.flag_url, alt: '', className: 'strope-ts-flag-img' } ) : null,
				el( 'strong', { className: 'strope-ts-language' }, entry.language ),
				el( 'span', { className: 'strope-ts-label' }, getStatusLabel( entry ) ),
				entry.needs_review
					? el( 'span', { className: 'strope-ts-review' }, __( 'Needs review', 'strope-translate' ) )
					: null
			),
			actions.length ? el( 'div', { className: 'strope-ts-actions' }, actions ) : null
		);
	}

	/**
	 * The panel.
	 *
	 * @return {Object|null} Element.
	 */
	function TranslationStatusPanel() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				postId: editor.getCurrentPostId(),
				status: editor.getCurrentPostAttribute( 'strope_translation_status' ),
				isNew: editor.isEditedPostNew(),
			};
		}, [] );

		var overrideState = useState( null );
		var override = overrideState[ 0 ];
		var setOverride = overrideState[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		// The post attribute is refreshed after each save.
		useEffect(
			function () {
				setOverride( null );
			},
			[ data.status ]
		);

		var status = override || data.status;

		function onAction( id, action ) {
			setBusy( true );
			setError( '' );
			wp.apiFetch( {
				path: '/strope/v1/translation-status/' + id,
				method: 'POST',
				data: { action: action },
			} )
				.then( function () {
					return wp.apiFetch( { path: '/strope/v1/translation-status/' + data.postId } );
				} )
				.then( function ( response ) {
					setOverride( response.status );
				} )
				.catch( function ( err ) {
					setError( err && err.message ? err.message : __( 'The translation status could not be updated.', 'strope-translate' ) );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		var content;

		if ( data.isNew ) {
			content = el( 'p', null, __( 'The translation status will be available once the post is saved.', 'strope-translate' ) );
		} else if ( ! status || 'object' !== typeof status ) {
			return null;
		} else {
			content = el(
				Fragment,
				null,
				error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
				el(
					'ul',
					{ className: 'strope-ts-list', 'aria-busy': busy ? 'true' : 'false' },
					Object.keys( status ).map( function ( slug ) {
						return el( StatusRow, { key: slug, entry: status[ slug ], busy: busy, onAction: onAction } );
					} )
				),
				busy ? el( Spinner ) : null
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'strope-translation-status',
				title: __( 'Translation status', 'strope-translate' ),
				className: 'strope-translation-status-panel',
			},
			content
		);
	}

	wp.plugins.registerPlugin( 'strope-translation-status', {
		render: TranslationStatusPanel,
		icon: 'translation',
	} );
} )( window.wp );
