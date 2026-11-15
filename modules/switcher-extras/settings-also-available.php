<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for the "also available in" notice.
 *
 * @since 4.1.0
 */
class Strope_Settings_Also_Available extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 61;

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
				'module'        => 'also_available',
				'title'         => __( '"Also available in" notice', 'strope-translate' ),
				'description'   => __( 'Displays a notice before or after the content of posts, listing the other languages in which they are published.', 'strope-translate' ),
				'active_option' => 'also_available',
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
		$settings = Strope_Also_Available_Notice::get_settings( $this->options );
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Post types', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				foreach ( Strope_Also_Available_Notice::get_available_post_types() as $post_type => $label ) {
					printf(
						'<li><label><input name="also_available_settings[post_types][%1$s]" type="checkbox" value="1" %2$s /> %3$s</label></li>',
						esc_attr( $post_type ),
						checked( in_array( $post_type, $settings['post_types'], true ), true, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Position', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				$positions = array(
					'before' => __( 'Before the content', 'strope-translate' ),
					'after'  => __( 'After the content', 'strope-translate' ),
				);
				foreach ( $positions as $value => $label ) {
					printf(
						'<li><label><input name="also_available_settings[position]" type="radio" value="%1$s" %2$s /> %3$s</label></li>',
						esc_attr( $value ),
						checked( $settings['position'], $value, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'Format', 'strope-translate' ); ?></legend>
			<ul class="strope-inline-block-list">
				<?php
				$formats = array(
					'names' => __( 'Language names', 'strope-translate' ),
					'flags' => __( 'Flags and language names', 'strope-translate' ),
				);
				foreach ( $formats as $value => $label ) {
					printf(
						'<li><label><input name="also_available_settings[format]" type="radio" value="%1$s" %2$s /> %3$s</label></li>',
						esc_attr( $value ),
						checked( $settings['format'], $value, false ),
						esc_html( $label )
					);
				}
				?>
			</ul>
		</fieldset>
		<fieldset>
			<legend><label for="strope-also-available-text"><?php esc_html_e( 'Text', 'strope-translate' ); ?></label></legend>
			<input type="text" class="regular-text" id="strope-also-available-text" name="also_available_settings[text]" value="<?php echo esc_attr( $settings['text'] ); ?>" placeholder="<?php echo esc_attr( Strope_Also_Available_Notice::get_default_text() ); ?>" />
			<p class="description">
				<?php
				/* translators: Do not translate %s, it is a placeholder in the text of the notice. */
				esc_html_e( '%s is replaced by the list of languages. Leave empty to use the default text. A custom text can be translated in the strings translations.', 'strope-translate' );
				?>
			</p>
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
		$raw      = isset( $options['also_available_settings'] ) && is_array( $options['also_available_settings'] ) ? wp_unslash( $options['also_available_settings'] ) : array();
		$settings = array(
			'post_types' => array(),
			'position'   => isset( $raw['position'] ) ? $raw['position'] : '',
			'format'     => isset( $raw['format'] ) ? $raw['format'] : '',
			'text'       => isset( $raw['text'] ) ? $raw['text'] : '',
		);

		if ( ! empty( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			$settings['post_types'] = array_intersect(
				array_keys( Strope_Also_Available_Notice::get_available_post_types() ),
				array_keys( $raw['post_types'] )
			);
		}

		return array( 'also_available_settings' => Strope_Also_Available_Notice::sanitize_settings( $settings ) );
	}
}
