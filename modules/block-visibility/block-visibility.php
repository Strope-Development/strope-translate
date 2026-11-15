<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-block language visibility in content shared by all languages:
 * templates, template parts, synced patterns and navigation menus (when they are not translated),
 * and posts of post types which are not translated.
 *
 * The language is stored in the `strope_lang` block attribute, also used by the widgets block editor.
 * An empty value or 'every' means that the block is displayed in all languages.
 *
 * @since 4.1.0
 */
class Strope_Block_Visibility {
	/**
	 * Name of the block attribute.
	 *
	 * @var string
	 */
	const ATTRIBUTE = 'strope_lang';

	/**
	 * Value used in the editor for "all languages".
	 *
	 * @var string
	 */
	const ALL_LANGUAGES = 'every';

	/**
	 * Script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'strope-block-visibility';

	/**
	 * Blocks which can't be filtered by language.
	 *
	 * @var string[]
	 */
	const EXCLUDED_BLOCKS = array( 'core/legacy-widget', 'core/widget-area' );

	/**
	 * @var Strope_Base
	 */
	protected $strope_translate;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->strope_translate = &$strope_translate;
	}

	/**
	 * Adds hooks.
	 *
	 * @since 4.1.0
	 *
	 * @return self
	 */
	public function init() {
		// After `Strope_Widget_Editor_Language_Attribute`, which registers the attribute for dynamic blocks.
		add_filter( 'register_block_type_args', array( $this, 'add_block_attribute' ), 20, 2 );

		if ( $this->strope_translate instanceof Strope_Frontend ) {
			add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), 5, 2 );
			add_filter( 'render_block', array( $this, 'render_block' ), 5, 2 );
		}

		if ( $this->strope_translate instanceof Strope_Admin_Base ) {
			add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		}

		return $this;
	}

	/**
	 * Registers the language attribute for all blocks, so that it is accepted by the REST API
	 * (e.g. server side rendering in the editor).
	 *
	 * @since 4.1.0
	 *
	 * @param array  $args       Block type arguments.
	 * @param string $block_name Block name.
	 * @return array
	 */
	public function add_block_attribute( $args, $block_name = '' ) {
		if ( ! is_array( $args ) || in_array( $block_name, self::EXCLUDED_BLOCKS, true ) ) {
			return $args;
		}

		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}

		if ( ! isset( $args['attributes'][ self::ATTRIBUTE ] ) ) {
			$args['attributes'][ self::ATTRIBUTE ] = array(
				'type'    => 'string',
				'default' => '',
			);
		}

		return $args;
	}

	/**
	 * Tells if a parsed block must be hidden in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param array $parsed_block Parsed block.
	 * @return bool
	 */
	public function is_hidden( $parsed_block ) {
		if ( empty( $parsed_block['attrs'][ self::ATTRIBUTE ] ) || ! is_string( $parsed_block['attrs'][ self::ATTRIBUTE ] ) ) {
			return false;
		}

		$lang = $parsed_block['attrs'][ self::ATTRIBUTE ];

		if ( self::ALL_LANGUAGES === $lang || empty( $this->strope_translate->curlang ) ) {
			return false;
		}

		$hidden = $this->strope_translate->curlang->slug !== $lang;

		/**
		 * Filters whether a block restricted to a language is hidden in the current language.
		 *
		 * @since 4.1.0
		 *
		 * @param bool   $hidden       Whether the block is hidden.
		 * @param string $lang         Language code of the block.
		 * @param array  $parsed_block Parsed block.
		 */
		return (bool) apply_filters( 'strope_block_hidden_for_language', $hidden, $lang, $parsed_block );
	}

	/**
	 * Short-circuits the rendering of blocks not displayed in the current language.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $pre_render   The pre-rendered content, null by default.
	 * @param array       $parsed_block Parsed block.
	 * @return string|null
	 */
	public function pre_render_block( $pre_render, $parsed_block ) {
		if ( null !== $pre_render || ! is_array( $parsed_block ) ) {
			return $pre_render;
		}

		return $this->is_hidden( $parsed_block ) ? '' : null;
	}

	/**
	 * Removes blocks not displayed in the current language.
	 * Needed for blocks rendered without the 'pre_render_block' filter (e.g. navigation items).
	 *
	 * @since 4.1.0
	 *
	 * @param string $block_content Block content.
	 * @param array  $parsed_block  Parsed block.
	 * @return string
	 */
	public function render_block( $block_content, $parsed_block ) {
		return is_array( $parsed_block ) && $this->is_hidden( $parsed_block ) ? '' : $block_content;
	}

	/**
	 * Enqueues the editor script in the post and site editors.
	 * The widgets editors are handled by the language switcher blocks script.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_block_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( empty( $screen ) || in_array( $screen->base, array( 'widgets', 'customize' ), true ) ) {
			return;
		}

		$translated_post_types = array_values( $this->strope_translate->model->get_translated_post_types() );

		// In the post editor, the control is offered only for post types which aren't translated.
		$allow_control = 'post' !== $screen->base || ! in_array( $screen->post_type, $translated_post_types, true );

		$languages = array();
		foreach ( $this->strope_translate->model->get_languages_list() as $language ) {
			$languages[] = array(
				'slug'     => $language->slug,
				'name'     => $language->name,
				'w3c'      => $language->w3c,
				'flag_url' => $language->get_display_flag_url(),
			);
		}

		$settings = array(
			'allowControl'        => $allow_control,
			'allLanguages'        => self::ALL_LANGUAGES,
			'languages'           => $languages,
			'translatedPostTypes' => $translated_post_types,
			'templatePostTypes'   => array( 'wp_template', 'wp_template_part' ),
			'excludedBlocks'      => self::EXCLUDED_BLOCKS,
		);

		/**
		 * Filters the settings of the per-block language visibility in the block editor.
		 *
		 * @since 4.1.0
		 *
		 * @param array     $settings Settings.
		 * @param WP_Screen $screen   Current screen.
		 */
		$settings = (array) apply_filters( 'strope_block_visibility_editor_settings', $settings, $screen );

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( '/js/build/block-visibility' . $suffix . '.js', STROPE_TRANSLATE_FILE ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks', 'wp-i18n' ),
			STROPE_TRANSLATE_VERSION,
			false // In the header, to register the attribute before blocks registered by other scripts.
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'var strope_block_visibility_settings = ' . wp_json_encode( $settings ) . ';',
			'before'
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'strope-translate', STROPE_TRANSLATE_DIR . '/languages' );
	}
}
