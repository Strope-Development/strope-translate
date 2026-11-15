<?php
/**
 * Displays the wizard languages step
 *
 * @package Strope-Translate
 *
 * @since 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

$existing_languages = $this->model->get_languages_list();
$default_language   = count( $existing_languages ) > 0 ? $this->options['default_lang'] : null;
$languages_list = array_diff_key(
	Strope_Settings::get_predefined_languages(),
	wp_list_pluck( $existing_languages, 'locale', 'locale' )
);
?>
<div id="language-fields"></div>
<p class="languages-setup">
	<?php esc_html_e( 'This wizard will help you configure your Strope Translate settings, and get you started quickly with your multilingual website.', 'strope-translate' ); ?>
</p>
<p class="languages-setup">
	<?php esc_html_e( 'First we are going to define the languages that you will use on your website.', 'strope-translate' ); ?>
</p>
<h2><?php esc_html_e( 'Languages', 'strope-translate' ); ?></h2>
<div id="messages">
</div>
<div class="form-field">
	<label for="lang_list"><?php esc_html_e( 'Select a language to be added', 'strope-translate' ); ?></label>
	<div class="select-language-field">
		<select name="lang_list" id="lang_list">
			<option value=""></option>
			<?php
			foreach ( $languages_list as $language ) {
				printf(
					'<option value="%1$s" data-flag-html="%3$s" data-language-name="%2$s" >%2$s - %1$s</option>' . "\n",
					esc_attr( $language['locale'] ),
					esc_attr( $language['name'] ),
					esc_attr( Strope_Language::get_predefined_flag( $language['flag'] ) )
				);
			}
			?>
		</select>
		<div class="action-buttons">
			<button type="button"
				class="button-primary button"
				value="<?php esc_attr_e( 'Add new language', 'strope-translate' ); ?>"
				id="add-language"
				name="add-language"
			>
				<span class="dashicons dashicons-plus"></span><?php esc_html_e( 'Add new language', 'strope-translate' ); ?>
			</button>
		</div>
	</div>
</div>
<table id="languages" class="striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Language', 'strope-translate' ); ?></th>
			<th><?php esc_html_e( 'Remove', 'strope-translate' ); ?></th>
		</tr>
	</thead>
	<tbody>
	</tbody>
</table>
<table id="defined-languages" class="striped<?php echo empty( $existing_languages ) ? ' hide' : ''; ?>">
	<?php if ( ! is_null( $default_language ) ) : ?>
		<caption><span class="icon-default-lang"></span> <?php esc_html_e( 'Default language', 'strope-translate' ); ?></caption>
	<?php endif; ?>
	<thead>
		<tr>
			<th><?php esc_html_e( 'Languages already defined', 'strope-translate' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php
	foreach ( $existing_languages as $lg ) {
		printf(
			'<tr><td>%3$s<span>%2$s - %1$s</span> %4$s</td></tr>' . "\n",
			esc_attr( $lg->locale ),
			esc_html( $lg->name ),
			$lg->flag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$lg->is_default ? ' <span class="icon-default-lang"><span class="screen-reader-text">' . esc_html__( 'Default language', 'strope-translate' ) . '</span></span>' : ''
		);
	}
	?>
	</tbody>
</table>

<div id="dialog">
	<p>
	<?php
	printf(
		/* translators: %1$s: is a language flag image, %2$s: is a language native name */
		esc_html__( 'You selected %1$s %2$s but you didn\'t add it to the list before continuing to the next step.', 'strope-translate' ),
		'<span id="dialog-language-flag"></span>',
		'<strong id="dialog-language"></strong>'
	);
	?>
	</p>
	<p>
	<?php esc_html_e( 'Do you want to add this language before continuing to the next step?', 'strope-translate' ); ?>
	</p>
	<ul>
		<li>
			<?php
			printf(
				/* translators: %s: is the translated label of the 'Yes' button  */
				esc_html__( '%s: add this language and continue to the next step', 'strope-translate' ),
				'<strong>' . esc_html__( 'Yes', 'strope-translate' ) . '</strong >'
			);
			?>
		</li>
		<li>
		<?php
			printf(
				/* translators: %s: is the translated label of the 'No' button  */
				esc_html__( "%s: don't add this language and continue to the next step", 'strope-translate' ),
				'<strong>' . esc_html__( 'No', 'strope-translate' ) . '</strong >'
			);
			?>
		</li>
		<li>
		<?php
			printf(
				/* translators: %s: is the translated label of the 'Ignore' button  */
				esc_html__( '%s: stay at this step', 'strope-translate' ),
				'<strong>' . esc_html__( 'Ignore', 'strope-translate' ) . '</strong >'
			);
			?>
		</li>
	</ul>
</div>
