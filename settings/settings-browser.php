<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for browser language preference detection
 *
 * @since 1.8
 */
class Strope_Settings_Browser extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 20;

	/**
	 * Constructor
	 *
	 * @since 1.8
	 *
	 * @param object $strope_translate strope_translate object
	 */
	public function __construct( &$strope_translate ) {
		// Needed for `$this->is_available()`, which is used before calling the parent's constructor.
		$this->options = &$strope_translate->options;

		parent::__construct(
			$strope_translate,
			array(
				'module'        => 'browser',
				'title'         => __( 'Detect browser language', 'strope-translate' ),
				'description'   => __( 'When the front page is visited, redirects to itself in the browser preferred language. As this doesn\'t work if it is cached, Strope Translate will attempt to disable the front page cache for known cache plugins.', 'strope-translate' ),
				'active_option' => $this->is_available() ? 'browser' : 'none',
			)
		);

		if ( ! class_exists( 'Strope_Xdata_Domain', true ) ) {
			add_action( 'admin_print_footer_scripts', array( $this, 'print_js' ) );
		}
	}

	/**
	 * Tells if the option is available
	 *
	 * @since 2.0
	 *
	 * @return bool
	 */
	protected function is_available() {
		return ( 3 > $this->options['force_lang'] ) || class_exists( 'Strope_Xdata_Domain', true );
	}

	/**
	 * Tells if the module is active
	 *
	 * @since 1.8
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->is_available() ? parent::is_active() : false;
	}

	/**
	 * Displays the javascript to handle dynamically the change in url modifications
	 * as the preferred browser language is not used when the language is set from different domains
	 *
	 * @since 1.8
	 *
	 * @return void
	 */
	public function print_js() {
		wp_enqueue_script( 'jquery' );

		if ( parent::is_active() && 3 > $this->options['force_lang'] ) {
			$func = 'removeClass( "inactive" ).addClass( "active" )';
			$link = sprintf( '<span class="deactivate">%s</span>', $this->action_links['deactivate'] );
		}
		else {
			$func = 'removeClass( "active" ).addClass( "inactive" )';
			$link = sprintf( '<span class="activate">%s</span>', $this->action_links['activate'] );
		}

		$deactivated = sprintf( '<span class="deactivated">%s</span>', $this->action_links['deactivated'] );

		?>
		<script>
			jQuery(
				function( $ ){
					$( "input[name='force_lang']" ).on( 'change', function() {
						var value = $( this ).val();
						if ( 3 > value ) {
							$( "#strope-module-browser" ).<?php echo $func; // phpcs:ignore WordPress.Security.EscapeOutput ?>.children( "td" ).children( ".row-actions" ).html( '<?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
						}
						else {
							$( "#strope-module-browser" ).removeClass( "active" ).addClass( "inactive" ).children( "td" ).children( ".row-actions" ).html( '<?php echo $deactivated; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
						}
					} );
				}
			);
		</script>
		<?php
	}
}
