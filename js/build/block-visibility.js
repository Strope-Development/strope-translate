/**
 * Per-block language visibility in content shared by all languages
 * (templates, template parts, untranslated patterns and navigation menus, posts of untranslated post types).
 *
 * @package Strope-Translate
 */
( function ( wp, settings ) {
	'use strict';

	if ( ! wp || ! settings || ! wp.hooks || ! wp.blocks ) {
		return;
	}

	const { addFilter } = wp.hooks;
	const { createElement: el, Fragment } = wp.element;
	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl } = wp.components;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;

	const ATTRIBUTE = 'strope_lang';
	const ALL = settings.allLanguages || 'every';
	const languages = Array.isArray( settings.languages ) ? settings.languages : [];
	const translatedPostTypes = settings.translatedPostTypes || [];
	const templatePostTypes = settings.templatePostTypes || [];
	const excludedBlocks = settings.excludedBlocks || [];

	/**
	 * Registers the attribute for all blocks, in all editors, so that it's never lost when saving.
	 *
	 * @param {Object} blockSettings Block settings.
	 * @param {string} name          Block name.
	 * @return {Object} Block settings.
	 */
	const addAttribute = function ( blockSettings, name ) {
		if ( excludedBlocks.includes( name ) || ( blockSettings.attributes && blockSettings.attributes[ ATTRIBUTE ] ) ) {
			return blockSettings;
		}

		return {
			...blockSettings,
			attributes: {
				...blockSettings.attributes,
				[ ATTRIBUTE ]: {
					type: 'string',
					default: ALL,
				},
			},
		};
	};

	addFilter( 'blocks.registerBlockType', 'strope/block-visibility/attribute', addAttribute );

	if ( ! settings.allowControl || ! languages.length ) {
		return;
	}

	/**
	 * Returns the post type of the entity edited in the post or site editor.
	 *
	 * @param {Function} select Data registry select function.
	 * @return {string|undefined} Post type.
	 */
	const getEditedPostType = function ( select ) {
		const editor = select( 'core/editor' );
		let postType = editor && editor.getCurrentPostType ? editor.getCurrentPostType() : undefined;

		if ( ! postType ) {
			const siteEditor = select( 'core/edit-site' );
			postType = siteEditor && siteEditor.getEditedPostType ? siteEditor.getEditedPostType() : undefined;
		}

		return postType;
	};

	/**
	 * Tells if the control can be offered for a block: only when the block belongs to content shared by all languages.
	 *
	 * @param {Function} select   Data registry select function.
	 * @param {string}   clientId Block client id.
	 * @return {boolean} Whether the control is allowed.
	 */
	const isControlAllowed = function ( select, clientId ) {
		const postType = getEditedPostType( select );

		// A translated post: each language already has its own post.
		if ( postType && translatedPostTypes.includes( postType ) && ! templatePostTypes.includes( postType ) ) {
			return false;
		}

		const blockEditor = select( 'core/block-editor' );

		// Items of translated navigation menus and blocks of translated synced patterns, stored in their own posts (`ref`).
		const translatedContainers = [
			[ 'wp_navigation', 'core/navigation' ],
			[ 'wp_block', 'core/block' ],
		];

		for ( const [ containerPostType, containerBlock ] of translatedContainers ) {
			if ( ! translatedPostTypes.includes( containerPostType ) ) {
				continue;
			}

			const parents = blockEditor.getBlockParentsByBlockName( clientId, containerBlock );

			if ( parents.some( ( parentId ) => !! ( blockEditor.getBlockAttributes( parentId ) || {} ).ref ) ) {
				return false;
			}
		}

		return true;
	};

	/**
	 * The language visibility panel, rendered only for the selected block.
	 *
	 * @param {Object} props Block edit props.
	 * @return {Object|null} Element.
	 */
	const LanguageVisibilityControl = function ( props ) {
		const allowed = useSelect(
			( select ) => isControlAllowed( select, props.clientId ),
			[ props.clientId ]
		);

		if ( ! allowed ) {
			return null;
		}

		const value = props.attributes[ ATTRIBUTE ] || ALL;
		const options = [ { value: ALL, label: __( 'All languages', 'strope-translate' ) } ].concat(
			languages.map( ( language ) => ( { value: language.slug, label: language.name } ) )
		);

		// Keep an unknown language (e.g. deleted) selectable to not change it silently.
		if ( ! options.some( ( option ) => option.value === value ) ) {
			options.push( { value, label: value } );
		}

		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{
					title: __( 'Languages', 'strope-translate' ),
					initialOpen: ALL !== value,
					className: 'strope-block-visibility-panel',
				},
				el( SelectControl, {
					label: __( 'The block is displayed for:', 'strope-translate' ),
					value,
					options,
					help: ALL === value
						? __( 'This content is shared by all languages.', 'strope-translate' )
						: __( 'The block is hidden in the other languages.', 'strope-translate' ),
					// "All languages" resets the attribute to its default value, so that it isn't serialized.
					onChange: ( newValue ) => props.setAttributes( { [ ATTRIBUTE ]: ALL === newValue ? undefined : newValue } ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} )
			)
		);
	};

	const withLanguageVisibilityControl = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			const blockType = wp.blocks.getBlockType( props.name );
			const hasAttribute = !! ( blockType && blockType.attributes && blockType.attributes[ ATTRIBUTE ] );

			if ( ! props.isSelected || ! hasAttribute || excludedBlocks.includes( props.name ) ) {
				return el( BlockEdit, props );
			}

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el( LanguageVisibilityControl, props )
			);
		};
	}, 'withStropeLanguageVisibilityControl' );

	addFilter( 'editor.BlockEdit', 'strope/block-visibility/control', withLanguageVisibilityControl );
}( window.wp, window.strope_block_visibility_settings ) );
