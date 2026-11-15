<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings of the machine translation module.
 *
 * @since 4.2.0
 */
class Strope_Settings_Machine_Translation extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 66;

	/**
	 * Constructor.
	 *
	 * @since 4.2.0
	 *
	 * @param Strope_Settings $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct(
			$strope_translate,
			array(
				'module'        => 'machine_translation',
				'title'         => __( 'Machine translation', 'strope-translate' ),
				'description'   => __( 'Translates posts and pages automatically, in the browsers of your editors, with the engine of Firefox Translations. Your server does no translation work and the text is never sent to a translation service. The translations are saved as drafts marked as needing a review, and translations written by a person are never replaced.', 'strope-translate' ),
				'active_option' => 'machine_translation',
			)
		);
	}

	/**
	 * Displays the settings form.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	protected function form() {
		$options = Strope_Machine_Translation::get_options();
		?>
		<fieldset>
			<legend><?php esc_html_e( 'New translations are saved as', 'strope-translate' ); ?></legend>
			<label style="display:block">
				<input name="machine_translation[post_status]" type="radio" value="draft" <?php checked( 'draft', $options['post_status'] ); ?> />
				<?php esc_html_e( 'Drafts, published after a review (recommended)', 'strope-translate' ); ?>
			</label>
			<label style="display:block">
				<input name="machine_translation[post_status]" type="radio" value="publish" <?php checked( 'publish', $options['post_status'] ); ?> />
				<?php esc_html_e( 'Published posts, still marked as needing a review', 'strope-translate' ); ?>
			</label>
		</fieldset>
		<p>
			<label>
				<input name="machine_translation[background]" type="checkbox" value="1" <?php checked( $options['background'] ); ?> />
				<?php esc_html_e( 'Editors can let their browser translate in the background while they use the admin (each editor can turn it off)', 'strope-translate' ); ?>
			</label>
		</p>
		<p>
			<label for="strope-mt-keep"><?php esc_html_e( 'Never translate these words and names (one per line):', 'strope-translate' ); ?></label><br />
			<textarea id="strope-mt-keep" name="machine_translation[keep]" rows="4" cols="50" class="large-text code"><?php echo esc_textarea( $options['keep'] ); ?></textarea>
		</p>
		<?php
		if ( current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			printf(
				'<p class="description">%s <a href="%s">%s</a></p>',
				esc_html__( 'Terms which must always be translated the same way go in the glossary.', 'strope-translate' ),
				esc_url( Strope_MT_Admin::get_page_url() . '#strope-mt-glossary' ),
				esc_html__( 'Edit the glossary', 'strope-translate' )
			);
		}
		?>
		<?php
		if ( current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( Strope_MT_Admin::get_page_url() ),
				esc_html__( 'Install the language models and translate your content', 'strope-translate' )
			);
		}
	}

	/**
	 * Sanitizes the settings before saving.
	 *
	 * @since 4.2.0
	 *
	 * @param array $options Unsanitized options to save.
	 * @return array
	 */
	protected function update( $options ) {
		$input = isset( $options['machine_translation'] ) && is_array( $options['machine_translation'] ) ? wp_unslash( $options['machine_translation'] ) : array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) ( $input['keep'] ?? '' ) );
		$lines = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $lines ), 'strlen' ) ) ), 0, 500 );

		return array(
			Strope_Machine_Translation::OPTIONS_KEY => array(
				'post_status' => isset( $input['post_status'] ) && 'publish' === $input['post_status'] ? 'publish' : 'draft',
				'background'  => empty( $input['background'] ) ? 0 : 1,
				'keep'        => implode( "\n", $lines ),
			),
		);
	}
}
