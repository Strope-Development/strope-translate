<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the correct class depending on the context.
 *
 * @since 2.8
 */
class Strope_Admin_Loader {
	/**
	 * Reference to the Strope Translate object.
	 *
	 * @var object
	 */
	protected $strope_translate;

	/**
	 * Name of the property to create.
	 *
	 * @var string
	 */
	protected $property;

	/**
	 * Fully qualified name of the class to instantiate.
	 *
	 * @var array
	 */
	protected $args;

	/**
	 * Constructor.
	 *
	 * @since 2.8
	 * @since 3.6 New parameter `$args`.
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 * @param string   $property Name of the property to add to $strope_translate.
	 * @param array    $args     Optional. List of arguments to use when instantiating the class.
	 *
	 * @phpstan-param non-falsy-string $property
	 */
	public function __construct( &$strope_translate, $property, array $args = array() ) {
		$this->strope_translate = &$strope_translate;
		$this->property = $property;
		$this->args     = $args;

		add_action( 'admin_init', array( $this, 'load' ), 20 ); // After fusion Builder.
	}

	/**
	 * Finds out if the block editor is in use and loads the correct class accordingly.
	 *
	 * @since 2.8
	 *
	 * @return void
	 */
	public function load() {
		if ( 'post-new.php' === $GLOBALS['pagenow'] ) {
			// We need to wait until we know which editor is in use
			add_filter( 'use_block_editor_for_post', array( $this, '_load' ), 999 ); // After the plugin Classic Editor
		} elseif ( 'post.php' === $GLOBALS['pagenow'] && isset( $_GET['action'], $_GET['post'] ) && 'edit' === $_GET['action'] && empty( $_GET['meta-box-loader'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->_load( use_block_editor_for_post( (int) $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$this->_load( false );
		}
	}

	/**
	 * Loads the correct class, depending on the editor in use.
	 *
	 * We must make sure to instantiate the class only once, as the function may be called from a filter,
	 *
	 * @since 2.8
	 *
	 * @param bool $is_block_editor Whether to use the block editor or not.
	 * @return bool
	 */
	public function _load( $is_block_editor ) {
		$prop = $this->property;

		if ( isset( $this->strope_translate->$prop ) ) {
			return $is_block_editor;
		}

		$classname = 'Strope_' . ucwords( $prop, '_' );

		if ( $is_block_editor && strope_use_block_editor_plugin() ) {
			$classname = "{$classname}_REST";
		}

		$args = array( $this->strope_translate );

		if ( ! empty( $this->args ) ) {
			$args = array_merge( $args, array_values( $this->args ) );
		}

		$this->strope_translate->$prop = new $classname( ...$args );

		return $is_block_editor;
	}
}
