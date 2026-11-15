<?php
/**
 * Displays the machine translation page.
 *
 * @package Strope-Translate
 *
 * @var array             $notice     Notice to display, array( type, message ), or false.
 * @var int[]             $post_ids   Posts selected with the bulk action.
 * @var Strope_Language[] $languages  Languages the user can translate into.
 * @var array             $options    Module settings.
 * @var array             $directions Language pairs of the site (languages managers only).
 * @var array|WP_Error    $index      Index of the available models (languages managers only).
 * @var string[]          $post_types Translated post types, labels keyed by name.
 */

defined( 'ABSPATH' ) || exit;

$strope_default = $this->model->get_default_language();
?>
<div class="strope-mt">
	<?php if ( is_array( $notice ) ) : ?>
		<div class="notice notice-<?php echo 'error' === $notice[0] ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
	<?php endif; ?>

	<?php if ( is_wp_error( $index ) ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $index->get_error_message() ); ?></p></div>
	<?php endif; ?>

	<div class="strope-mt-columns">
		<div class="strope-mt-main">
			<div class="strope-mt-card">
				<h2><?php echo $post_ids ? esc_html__( 'Translate the selected content', 'strope-translate' ) : esc_html__( 'Translate your content', 'strope-translate' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="strope_mt_create" />
					<?php wp_nonce_field( 'strope_mt_create' ); ?>

					<?php if ( $post_ids ) : ?>
						<input type="hidden" name="posts" value="<?php echo esc_attr( implode( ',', $post_ids ) ); ?>" />
						<p>
							<?php
							$strope_titles = array_map( 'get_the_title', array_slice( $post_ids, 0, 10 ) );
							echo esc_html( implode( ', ', $strope_titles ) );
							if ( count( $post_ids ) > 10 ) {
								/* translators: %d: number of other posts */
								echo ' ' . esc_html( sprintf( __( 'and %d more', 'strope-translate' ), count( $post_ids ) - 10 ) );
							}
							?>
						</p>
					<?php else : ?>
						<p><?php esc_html_e( 'Translates the published content which has no translation yet in the chosen languages.', 'strope-translate' ); ?></p>
						<fieldset class="strope-mt-field">
							<legend><?php esc_html_e( 'Content', 'strope-translate' ); ?></legend>
							<?php foreach ( $post_types as $strope_type => $strope_label ) : ?>
								<label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $strope_type ); ?>" <?php checked( in_array( $strope_type, array( 'post', 'page' ), true ) ); ?> /> <?php echo esc_html( $strope_label ); ?></label>
							<?php endforeach; ?>
						</fieldset>
						<p class="strope-mt-field">
							<label for="strope-mt-source"><?php esc_html_e( 'Translate from', 'strope-translate' ); ?></label>
							<select id="strope-mt-source" name="source">
								<?php foreach ( $this->model->get_languages_list() as $strope_language ) : ?>
									<option value="<?php echo esc_attr( $strope_language->slug ); ?>" <?php selected( $strope_default && $strope_default->slug === $strope_language->slug ); ?>><?php echo esc_html( $strope_language->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>

					<fieldset class="strope-mt-field">
						<legend><?php esc_html_e( 'Into', 'strope-translate' ); ?></legend>
						<?php foreach ( $languages as $strope_language ) : ?>
							<label><input type="checkbox" name="languages[]" value="<?php echo esc_attr( $strope_language->slug ); ?>" <?php checked( ! $strope_default || $strope_default->slug !== $strope_language->slug ); ?> /> <?php echo esc_html( $strope_language->name ); ?></label>
						<?php endforeach; ?>
					</fieldset>

					<fieldset class="strope-mt-field">
						<legend><?php esc_html_e( 'Save new translations as', 'strope-translate' ); ?></legend>
						<label><input type="radio" name="post_status" value="draft" <?php checked( 'draft', $options['post_status'] ); ?> /> <?php esc_html_e( 'Drafts', 'strope-translate' ); ?></label>
						<label><input type="radio" name="post_status" value="publish" <?php checked( 'publish', $options['post_status'] ); ?> /> <?php esc_html_e( 'Published', 'strope-translate' ); ?></label>
					</fieldset>

					<p class="description"><?php esc_html_e( 'Translations written or edited by a person are never replaced. Every machine translation is marked as needing a review until someone saves it in the editor.', 'strope-translate' ); ?></p>

					<?php submit_button( $post_ids ? __( 'Translate', 'strope-translate' ) : __( 'Translate missing content', 'strope-translate' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="strope-mt-card">
				<h2><?php esc_html_e( 'Translations', 'strope-translate' ); ?></h2>
				<div id="strope-mt-runs" aria-live="polite"></div>
			</div>

			<?php if ( is_array( $glossary ) ) : ?>
				<?php $strope_all_languages = $this->model->get_languages_list(); ?>
				<div class="strope-mt-card" id="strope-mt-glossary">
					<h2><?php esc_html_e( 'Glossary', 'strope-translate' ); ?></h2>
					<p><?php esc_html_e( 'Terms always translated the same way, e.g. the names of your products or the words of your shop. Write each term in the languages you want, as in the middle of a sentence: a term is used when it has a translation in both languages of a translation. Terms are found whatever their case, and get a capital at the start of a sentence.', 'strope-translate' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="strope_mt_glossary" />
						<?php wp_nonce_field( 'strope_mt_glossary' ); ?>
						<table class="widefat striped strope-mt-glossary">
							<thead>
								<tr>
									<?php foreach ( $strope_all_languages as $strope_language ) : ?>
										<th scope="col"><?php echo esc_html( $strope_language->name ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_merge( $glossary, array_fill( 0, 3, array() ) ) as $strope_row => $strope_entry ) : ?>
									<tr>
										<?php foreach ( $strope_all_languages as $strope_language ) : ?>
											<td>
												<input
													type="text"
													class="widefat"
													lang="<?php echo esc_attr( $strope_language->get_locale( 'display' ) ); ?>"
													dir="<?php echo $strope_language->is_rtl ? 'rtl' : 'ltr'; ?>"
													name="<?php echo esc_attr( sprintf( 'glossary[%d][%s]', $strope_row, $strope_language->slug ) ); ?>"
													value="<?php echo esc_attr( $strope_entry[ $strope_language->slug ] ?? '' ); ?>"
													aria-label="<?php echo esc_attr( $strope_language->name ); ?>"
												/>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p>
							<?php submit_button( __( 'Save the glossary', 'strope-translate' ), 'primary', 'submit', false ); ?>
							<button type="button" class="button strope-mt-glossary-add" hidden><?php esc_html_e( 'Add a term', 'strope-translate' ); ?></button>
						</p>
						<p class="description"><?php esc_html_e( 'Applies to the next machine translations, including the texts already in the translation memory. Translations already saved are not changed.', 'strope-translate' ); ?></p>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<div class="strope-mt-side">
			<div class="strope-mt-card">
				<h2><?php esc_html_e( 'This browser', 'strope-translate' ); ?></h2>
				<p><?php esc_html_e( 'The translations are made by your browser. Keep a tab of the admin open until the queue is empty.', 'strope-translate' ); ?></p>
				<p id="strope-mt-worker-status" class="strope-mt-status" aria-live="polite"></p>
				<p><button type="button" class="button button-primary" id="strope-mt-worker-toggle"></button></p>
				<?php if ( $options['background'] ) : ?>
					<p><label><input type="checkbox" id="strope-mt-background" <?php checked( Strope_Machine_Translation::user_wants_background() ); ?> /> <?php esc_html_e( 'Translate in the background while I use the admin', 'strope-translate' ); ?></label></p>
				<?php endif; ?>
			</div>

			<?php if ( current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) ) : ?>
				<div class="strope-mt-card">
					<h2><?php esc_html_e( 'Language models', 'strope-translate' ); ?></h2>
					<?php if ( empty( $directions ) ) : ?>
						<p><?php esc_html_e( 'Add a second language to the site first.', 'strope-translate' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Each translation direction needs a model of about 25 MB, downloaded once to your site from Mozilla (Firefox Translations). Languages other than English are translated through English.', 'strope-translate' ); ?></p>
						<table class="widefat striped strope-mt-models">
							<tbody>
								<?php foreach ( $directions as $strope_direction => $strope_info ) : ?>
									<tr data-direction="<?php echo esc_attr( $strope_direction ); ?>" data-installed="<?php echo $strope_info['installed'] ? '1' : '0'; ?>" data-outdated="<?php echo $strope_info['outdated'] ? '1' : '0'; ?>" data-available="<?php echo $strope_info['available'] ? '1' : '0'; ?>">
										<td>
											<?php echo esc_html( $strope_info['from'] . ' → ' . $strope_info['to'] ); ?>
											<?php if ( $strope_info['size'] ) : ?>
												<br /><span class="description"><?php echo esc_html( size_format( $strope_info['size'], 0 ) ); ?></span>
											<?php endif; ?>
										</td>
										<td class="strope-mt-model-actions"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: link to Firefox Translations */
					esc_html__( 'Translation engine: Bergamot, from %s (Mozilla Public License 2.0). Your text is never sent to a translation service.', 'strope-translate' ),
					'<a href="https://github.com/mozilla/translations" target="_blank" rel="noopener noreferrer">Firefox Translations</a>'
				);
				?>
			</p>
		</div>
	</div>
</div>
