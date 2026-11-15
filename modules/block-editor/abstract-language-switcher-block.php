<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Abstract class for language switcher block.
 *
 * @since 3.2
 */
abstract class Strope_Abstract_Language_Switcher_Block {
	/**
	 * @var Strope_Links
	 */
	protected $links;

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Current lang to render the language switcher block in an admin context.
	 *
	 * @since 2.8
	 *
	 * @var string|null
	 */
	protected $admin_current_lang;

	/**
	 * Is it the edit context?
	 *
	 * @var bool
	 */
	protected $is_edit_context = false;

	/**
	 * Constructor
	 *
	 * @since 2.8
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;
		$this->links = &$strope_translate->links;
	}

	/**
	 * Adds the required hooks.
	 *
	 * @since 3.2
	 *
	 * @return self
	 */
	public function init() {
		// Use rest_pre_dispatch_filter to get additionnal parameters for language switcher block.
		add_filter( 'rest_pre_dispatch', array( $this, 'get_rest_query_params' ), 10, 3 );

		// Register language switcher block.
		add_action( 'init', array( $this, 'register' ) );

		return $this;
	}

	/**
	 * Returns the block name with the Strope Translate's namespace.
	 *
	 * @since 3.2
	 *
	 * @return string The block name.
	 */
	abstract protected function get_block_name();

	/**
	 * Renders the Strope Translate's block on server.
	 *
	 * @since 3.2
	 * @since 3.3 Accepts two new parameters, $content and $block.
	 *
	 * @param array    $attributes The block attributes.
	 * @param string   $content    The saved content.
	 * @param WP_Block $block      The parsed block.
	 * @return string The HTML string output to serve.
	 */
	abstract public function render( $attributes, $content, $block );

	/**
	 * Returns the supported pieces of inherited context for the block, by default none are supported..
	 *
	 * @since 3.3
	 *
	 * @return array An array with context subject, default to empty.
	 */
	protected function get_context() {
		return array();
	}

	/**
	 * Returns the block supports, by default none.
	 *
	 * @since 4.1.0
	 *
	 * @return array
	 */
	protected function get_supports() {
		return array();
	}

	/**
	 * Returns the handle of a stylesheet to load in the block editor, by default none.
	 *
	 * @since 4.1.0
	 *
	 * @return string Style handle, empty string if none.
	 */
	protected function get_editor_style() {
		return '';
	}

	/**
	 * Registers the Strope Translate's block.
	 *
	 * @since 2.8
	 * @since 3.2 Renamed and now handle any type of block registration based on a dynamic name.
	 *
	 * @return void
	 */
	public function register() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( $this->get_block_name() ) ) {
			// Don't register a block more than once or WordPress send an error. See https://github.com/WordPress/wordpress-develop/blob/5.9/src/wp-includes/class-wp-block-type-registry.php#L82-L90
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$script_filename = 'js/build/blocks' . $suffix . '.js';
		$script_handle = 'strope_blocks';
		$dependencies  = wp_script_is( 'react-jsx-runtime', 'registered' ) ? array( 'react', 'react-jsx-runtime' ) : array( 'react' ); // The runtime exists since WordPress 6.6.
		wp_register_script(
			$script_handle,
			plugins_url( $script_filename, STROPE_TRANSLATE_FILE ),
			array_merge(
				$dependencies,
				array(
					'wp-block-editor',
					'wp-blocks',
					'wp-components',
					'wp-element',
					'wp-i18n',
					'wp-server-side-render',
					'lodash',
				)
			),
			STROPE_TRANSLATE_VERSION,
			true
		);

		wp_localize_script( $script_handle, 'strope_block_editor_blocks_settings', Strope_Switcher::get_switcher_options( 'block', 'string' ) );

		$attributes = array(
			'className' => array(
				'type'    => 'string',
				'default' => '',
			),
		);
		foreach ( Strope_Switcher::get_switcher_options( 'block', 'default' ) as $option => $default ) {
			$attributes[ $option ] = array(
				'type'    => 'boolean',
				'default' => $default,
			);
		}

		$block_args = array(
			'editor_script'   => $script_handle,
			'attributes'      => $attributes,
			'render_callback' => array( $this, 'render' ),
			'uses_context'    => $this->get_context(),
		);

		$supports = $this->get_supports();
		if ( ! empty( $supports ) ) {
			$block_args['supports'] = $supports;
		}

		$editor_style = $this->get_editor_style();
		if ( ! empty( $editor_style ) ) {
			$block_args['editor_style'] = $editor_style;
		}

		register_block_type( $this->get_block_name(), $block_args );

		// Translated strings used in JS code
		wp_set_script_translations( $script_handle, 'strope-translate', STROPE_TRANSLATE_DIR . '/languages' );
	}

	/**
	 * Returns the REST parameters for language switcher block.
	 * Used to store the request's language and context locally.
	 * Previously was in the `Strope_Block_Editor_Switcher_Block` class.
	 *
	 * @see WP_REST_Server::dispatch()
	 *
	 * @since 2.8
	 *
	 * @param mixed           $result  Response to replace the requested version with. Can be anything
	 *                                 a normal endpoint can return, or null to not hijack the request.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function get_rest_query_params( $result, $server, $request ) {
		if ( strope_is_edit_rest_request( $request ) ) {
			$this->is_edit_context = true;
			$this->admin_current_lang = $request->get_param( 'lang' );
		}
		return $result;
	}

	/**
	 * Adds the attributes to render the block correctly.
	 * Also specifies not to echo the switcher in any case.
	 *
	 * @since 3.2
	 *
	 * @param array $attributes The attributes of the currently rendered block.
	 * @return array The modified attributes.
	 */
	protected function set_attributes_for_block( $attributes ) {
		$attributes['echo'] = 0;
		if ( $this->is_edit_context ) {
			$attributes['admin_render']           = 1;
			$attributes['admin_current_lang']     = $this->admin_current_lang;
			$attributes['hide_if_empty']          = 0;
			$attributes['hide_if_no_translation'] = 0; // Force not to hide the language for the block preview even if the option is checked.
		}
		return $attributes;
	}
}
