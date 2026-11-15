<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for the floating language switcher.
 *
 * @since 4.1.0
 */
class Strope_Settings_Floating_Switcher extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 60;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Settings $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct(
			$strope_translate,
			array(
				'module'        => 'floating_switcher',
				'title'         => __( 'Floating language switcher', 'strope-translate' ),
				'description'   => __( 'Displays a language switcher floating in a corner of every page of the site, without having to edit the theme, the menus or the widgets.', 'strope-translate' ),
				'active_option' => 'floating_switcher',
			)
		);
	}

	/**
	 * Displays the settings form.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function form() {
		$settings = Strope_Floating_Switcher::get_settings( $this->options );
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Position', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				foreach ( Strope_Floating_Switcher::get_positions() as $value => $label ) {
					printf(
						'<li><label><input name="floating_switcher_settings[position]" type="radio" value="%s" %s /> %s</label></li>',
						esc_attr( $value ),
						checked( $settings['position'], $value, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Display', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				foreach ( Strope_Floating_Switcher::get_display_modes() as $value => $label ) {
					printf(
						'<li><label><input name="floating_switcher_settings[display]" type="radio" value="%s" %s /> %s</label></li>',
						esc_attr( $value ),
						checked( $settings['display'], $value, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Options', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				$checkboxes = array(
					'dropdown'               => __( 'Displays as a dropdown', 'strope-translate' ),
					'hide_current'           => __( 'Hides the current language', 'strope-translate' ),
					'hide_if_no_translation' => __( 'Hides languages with no translation', 'strope-translate' ),
				);
				foreach ( $checkboxes as $key => $label ) {
					printf(
						'<li><label><input name="floating_switcher_settings[%s]" type="checkbox" value="1" %s /> %s</label></li>',
						esc_attr( $key ),
						checked( ! empty( $settings[ $key ] ), true, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
			<p class="description"><?php esc_html_e( 'The dropdown opens from a button showing the current language. The list displays all the languages at once.', 'strope-translate' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Sanitizes the settings before saving.
	 *
	 * @since 4.1.0
	 *
	 * @param array $options Unsanitized options to save.
	 * @return array
	 */
	protected function update( $options ) {
		$settings = isset( $options['floating_switcher_settings'] ) && is_array( $options['floating_switcher_settings'] ) ? wp_unslash( $options['floating_switcher_settings'] ) : array();

		// Unchecked checkboxes are not sent.
		foreach ( array( 'dropdown', 'hide_current', 'hide_if_no_translation' ) as $key ) {
			$settings[ $key ] = empty( $settings[ $key ] ) ? 0 : 1;
		}

		return array( 'floating_switcher_settings' => Strope_Floating_Switcher::sanitize_settings( $settings ) );
	}
}
