<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class to display information for the Share slugs module.
 *
 * @since 3.1
 */
class Strope_Settings_Share_Slug extends Strope_Settings_Preview_Share_Slug {
	/**
	 * Constructor.
	 *
	 * @since 3.1
	 *
	 * @param Strope_Settings $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct( $strope_translate, array( 'active_option' => 'none' ) );

		if ( get_option( 'permalink_structure' ) ) {
			add_action( 'admin_print_footer_scripts', array( $this, 'print_js' ) );
		}
	}

	/**
	 * Returns the module description.
	 *
	 * @since 3.1
	 *
	 * @return string
	 */
	protected function get_description() {
		return parent::get_description() . ' ' . __( 'The module is automatically deactivated when using plain permalinks or when the language is set from the content in the URL modifications.', 'strope-translate' );
	}

	/**
	 * Tells if the module is active.
	 *
	 * @since 1.9
	 *
	 * @return bool
	 */
	public function is_active() {
		return $this->options['force_lang'] && get_option( 'permalink_structure' );
	}

	/**
	 * Displays the javascript to handle dynamically the change in url modifications
	 * as sharing slugs is not possible when the language is set from the content
	 *
	 * @since 1.9
	 *
	 * @return void
	 */
	public function print_js() {
		wp_enqueue_script( 'jquery' );

		$activated = sprintf( '<span class="activated">%s</span>', $this->action_links['activated'] );
		$deactivated = sprintf( '<span class="deactivated">%s</span>', $this->action_links['deactivated'] );

		?>
		<script>
			jQuery(
				function ( $ ) {
					$( "input[name='force_lang']" ).on( 'change', function () {
						var value = $( this ).val();
						if ( value > 0 ) {
							$( "#strope-module-share-slugs" ).removeClass( "inactive" ).addClass( "active" ).children( "td" ).children( ".row-actions" ).html( '<?php echo $activated; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
						}
						else {
							$( "#strope-module-share-slugs" ).removeClass( "active" ).addClass( "inactive" ).children( "td" ).children( ".row-actions" ).html( '<?php echo $deactivated; // phpcs:ignore WordPress.Security.EscapeOutput ?>' );
						}
					} );
				}
			);
		</script>
		<?php
	}
}
