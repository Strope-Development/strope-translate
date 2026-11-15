<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all settings
 *
 * @since 1.8
 */
class Strope_Settings_Module {
	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	public $options;

	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * Instance of a child class of Strope_Links_Model.
	 *
	 * @var Strope_Links_Model
	 */
	public $links_model;

	/**
	 * Key to use to manage the module activation state.
	 * Possible values:
	 * - An option key for a module that can be activated/deactivated.
	 * - 'none' for a module that doesn't have a activation/deactivation setting.
	 * - 'preview' for a preview module kept for backward compatibility; all features are available.
	 *
	 * @var string
	 *
	 * @phpstan-var non-falsy-string
	 */
	public $active_option;

	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 100;

	/**
	 * Stores the module name.
	 * It must be unique.
	 *
	 * @var string
	 *
	 * @phpstan-var non-falsy-string
	 */
	public $module;

	/**
	 * Stores the module title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Stores the module description.
	 *
	 * @var string
	 */
	public $description;

	/**
	 * Stores the settings actions.
	 *
	 * @var array
	 */
	protected $action_links;

	/**
	 * Stores html fragment for the buttons.
	 *
	 * @var array
	 */
	protected $buttons;

	/**
	 * Stores html form when provided by a child class.
	 *
	 * @var string|false
	 */
	protected $form = false;

	/**
	 * Constructor
	 *
	 * @since 1.8
	 *
	 * @param object $strope_translate The Strope Translate object.
	 * @param array  $args {
	 *   @type string $module        Unique module name.
	 *   @type string $title         The title of the settings module.
	 *   @type string $description   The description of the settings module.
	 *   @type string $active_option Optional. Key to use to manage the module activation state.
	 *                               Possible values:
	 *                               - An option key for a module that can be activated/deactivated.
	 *                               - 'none' for a module that doesn't have a activation/deactivation setting.
	 *                               - 'preview' for a preview module kept for backward compatibility; all features are available.
	 *                               Default is 'none'.
	 * }
	 *
	 * @phpstan-param array{
	 *   module: non-falsy-string,
	 *   title: string,
	 *   description: string,
	 *   active_option?: non-falsy-string
	 * } $args
	 */
	public function __construct( &$strope_translate, $args ) {
		$this->options     = &$strope_translate->options;
		$this->model       = &$strope_translate->model;
		$this->links_model = &$strope_translate->links_model;

		$args = wp_parse_args(
			$args,
			array(
				'title'         => '',
				'description'   => '',
				'active_option' => 'none',
			)
		);

		if ( empty( $args['active_option'] ) ) {
			// Backward compatibility.
			$args['active_option'] = 'none';
		}

		foreach ( $args as $prop => $value ) {
			$this->$prop = $value;
		}

		// All possible action links, even if not always a link ;-)
		$this->action_links = array(
			'configure'   => sprintf(
				'<a title="%s" href="%s">%s</a>',
				esc_attr__( 'Configure this module', 'strope-translate' ),
				'#',
				esc_html__( 'Settings', 'strope-translate' )
			),
			'deactivate'  => sprintf(
				'<a title="%s" href="%s">%s</a>',
				esc_attr__( 'Deactivate this module', 'strope-translate' ),
				esc_url( wp_nonce_url( '?page=mlang&tab=modules&strope_action=deactivate&noheader=true&module=' . $this->module, 'strope_deactivate' ) ),
				esc_html__( 'Deactivate', 'strope-translate' )
			),
			'activate'    => sprintf(
				'<a title="%s" href="%s">%s</a>',
				esc_attr__( 'Activate this module', 'strope-translate' ),
				esc_url( wp_nonce_url( '?page=mlang&tab=modules&strope_action=activate&noheader=true&module=' . $this->module, 'strope_activate' ) ),
				esc_html__( 'Activate', 'strope-translate' )
			),
			'activated'   => esc_html__( 'Activated', 'strope-translate' ),
			'deactivated' => esc_html__( 'Deactivated', 'strope-translate' ),
		);

		$this->buttons = array(
			'cancel' => sprintf( '<button type="button" class="button button-secondary cancel">%s</button>', esc_html__( 'Cancel', 'strope-translate' ) ),
			'save'   => sprintf( '<button type="button" class="button button-primary save">%s</button>', esc_html__( 'Save Changes', 'strope-translate' ) ),
		);

		// Ajax action to save options.
		add_action( 'wp_ajax_strope_save_options', array( $this, 'save_options' ) );
	}

	/**
	 * Tells if the module is active.
	 *
	 * @since 1.8
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'none' === $this->active_option || ( 'preview' !== $this->active_option && ! empty( $this->options[ $this->active_option ] ) );
	}

	/**
	 * Activates the module.
	 *
	 * @since 1.8
	 *
	 * @return void
	 */
	public function activate() {
		if ( 'none' !== $this->active_option && 'preview' !== $this->active_option ) {
			$this->options[ $this->active_option ] = true;
			update_option( 'strope_translate', $this->options );
		}
	}

	/**
	 * Deactivates the module.
	 *
	 * @since 1.8
	 *
	 * @return void
	 */
	public function deactivate() {
		if ( 'none' !== $this->active_option && 'preview' !== $this->active_option ) {
			$this->options[ $this->active_option ] = false;
			update_option( 'strope_translate', $this->options );
		}
	}

	/**
	 * Protected method to display a configuration form.
	 *
	 * @since 1.8
	 *
	 * @return void
	 */
	protected function form() {
		// Child classes can provide a form.
	}

	/**
	 * Public method returning the form if any.
	 *
	 * @since 1.8
	 *
	 * @return string
	 */
	public function get_form() {
		if ( ! $this->is_active() ) {
			return '';
		}

		// Read the form only once
		if ( false === $this->form ) {
			ob_start();
			$this->form();
			$this->form = ob_get_clean();
		}

		return $this->form;
	}

	/**
	 * Allows child classes to validate their options before saving.
	 *
	 * @since 1.8
	 *
	 * @param array $options Unsanitized options to save.
	 * @return array Options
	 */
	protected function update( $options ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return array(); // It's responsibility of the child class to decide what is saved.
	}

	/**
	 * Ajax method to save the options.
	 *
	 * @since 1.8
	 *
	 * @return void
	 */
	public function save_options() {
		check_ajax_referer( 'strope_options', '_strope_nonce' );
		if ( ! current_user_can( 'manage_languages' ) ) {
			wp_die( -1 );
		}

		if ( isset( $_POST['module'] ) && $this->module === $_POST['module'] ) {
			// It's up to the child class to decide which options are saved, whether there are errors or not
			$post = array_diff_key( $_POST, array_flip( array( 'action', 'module', 'strope_ajax_backend', '_strope_nonce' ) ) );
			$options = $this->update( $post );
			$this->options = array_merge( $this->options, $options );
			update_option( 'strope_translate', $this->options );

			// Refresh language cache in case home urls have been modified
			$this->model->clean_languages_cache();

			// Refresh rewrite rules in case rewrite,  hide_default, post types or taxonomies options have been modified
			// Don't use flush_rewrite_rules as we don't have the right links model and permastruct
			delete_option( 'rewrite_rules' );


			ob_start();

			if ( empty( get_settings_errors( 'strope_translate' ) ) ) {
				// Send update message
				strope_add_notice( new WP_Error( 'settings_updated', __( 'Settings saved.', 'strope-translate' ), 'success' ) );
				settings_errors( 'strope_translate' );
				$x = new WP_Ajax_Response( array( 'what' => 'success', 'data' => ob_get_clean() ) );
				$x->send();
			} else {
				// Send error messages
				settings_errors( 'strope_translate' );
				$x = new WP_Ajax_Response( array( 'what' => 'error', 'data' => ob_get_clean() ) );
				$x->send();
			}
		}
	}

	/**
	 * Get the row actions.
	 *
	 * @since 1.8
	 *
	 * @return string[]
	 */
	protected function get_actions() {
		$actions = array();

		if ( $this->is_active() && $this->get_form() ) {
			$actions[] = 'configure';
		}

		if ( 'none' !== $this->active_option && 'preview' !== $this->active_option ) {
			$actions[] = $this->is_active() ? 'deactivate' : 'activate';
		}

		if ( empty( $actions ) ) {
			$actions[] = $this->is_active() ? 'activated' : 'deactivated';
		}

		return $actions;
	}

	/**
	 * Get the actions links.
	 *
	 * @since 1.8
	 *
	 * @return string[] Action links.
	 */
	public function get_action_links() {
		return array_intersect_key( $this->action_links, array_flip( $this->get_actions() ) );
	}


	/**
	 * Allows child classes to display an upgrade message.
	 *
	 * @since 1.9
	 *
	 * @return string
	 */
	public function get_upgrade_message() {
		return '';
	}

	/**
	 * Get the buttons.
	 *
	 * @since 1.9
	 *
	 * @return string[] An array of html fragment for the buttons.
	 */
	public function get_buttons() {
		return $this->buttons;
	}
}
