<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Settings class for the translation workflow (translators and translation jobs).
 *
 * @since 4.1.0
 */
class Strope_Settings_Translation_Workflow extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 65;

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
				'module'      => 'translation_workflow',
				'title'       => __( 'Translation workflow', 'strope-translate' ),
				'description' => __( 'Translator role, translation languages per user (in the user profiles) and translation jobs with email notifications.', 'strope-translate' ),
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
		$options = Strope_Translation_Workflow::get_options();
		$fields  = array(
			'translators_publish' => __( 'Translators can publish translations and edit published content. Otherwise, they submit their translations for review and cannot modify published content.', 'strope-translate' ),
			'notifications'       => __( 'Send email notifications when a translation job is assigned, ready for review or done.', 'strope-translate' ),
			'digest'              => __( 'Send a daily digest of their open translation jobs to translators.', 'strope-translate' ),
		);

		echo '<ul class="strope-inline-block-list" style="display:block">';
		foreach ( $fields as $key => $label ) {
			printf(
				'<li style="display:block"><label><input name="translation_workflow[%1$s]" type="checkbox" value="1" %2$s /> %3$s</label></li>',
				esc_attr( $key ),
				checked( ! empty( $options[ $key ] ), true, false ),
				esc_html( $label )
			);
		}
		echo '</ul>';
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
		$new = array();

		foreach ( array( 'translators_publish', 'notifications', 'digest' ) as $key ) {
			$new[ $key ] = empty( $options['translation_workflow'][ $key ] ) ? 0 : 1;
		}

		return array( 'translation_workflow' => $new );
	}

	/**
	 * Returns the row actions.
	 *
	 * @since 4.1.0
	 *
	 * @return string[]
	 */
	protected function get_actions() {
		return array( 'configure' );
	}
}
