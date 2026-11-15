<?php
/**
 * Outputs the bulk translate form
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

?>
<form method="get"><table style="display: none"><tbody id="strope-bulk-translate">
	<tr id="strope-translate" class="inline-edit-row">
		<td>
			<div class="inline-edit-wrapper">
				<span class="inline-edit-legend"><?php esc_html_e( 'Bulk translate', 'strope-translate' ); ?></span>
				<div class="strope-bulk-translate-fields-wrapper">
					<fieldset>
						<div class="inline-edit-col">
						<span class="title"><?php esc_html_e( 'Target languages', 'strope-translate' ); ?></span>
						<?php
						foreach ( $this->model->get_languages_list() as $language ) {
							printf(
								'<label><span class="option"><input name="strope-translate-lang[]" type="checkbox" value="%1$s" /></span><span class="strope-translation-flag">%3$s</span>%2$s</label>',
								esc_attr( $language->slug ),
								esc_html( $language->name ),
								$language->flag // phpcs:ignore WordPress.Security.EscapeOutput
							);
						}
						?>
						</div>
					</fieldset>
					<fieldset>
						<div class="inline-edit-col">
							<span class="title"><?php esc_html_e( 'Action', 'strope-translate' ); ?></span>
							<?php
							if ( isset( $bulk_translate_options ) ) {
								$selected = reset( $bulk_translate_options );
								foreach ( $bulk_translate_options as $bulk_translate_option ) {
									$bulk_translate_option->display( $selected->get_name() );
								}
							}
							?>
						</div>
					</fieldset>
				</div>
				<p class="submit bulk-translate-save">
					<?php wp_nonce_field( 'strope_translate', '_strope_translate_nonce' ); ?>
					<button type="button" class="button button-secondary cancel"><?php esc_html_e( 'Cancel', 'strope-translate' ); ?></button>
					<?php submit_button( __( 'Submit', 'strope-translate' ), 'primary', '', false ); ?>
				</p>
			</div>
		</td>
	</tr>
</tbody></table></form>
