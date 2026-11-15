<?php
/**
 * Displays the theme and plugin strings in Languages > Translations.
 *
 * @package Strope-Translate
 *
 * @var array                           $filters    Current filters.
 * @var Strope_Language[]               $languages  Displayed languages.
 * @var array[]                         $components Scanned components.
 * @var int[]                           $counts     Number of strings per component.
 * @var array|null                      $job        Current scan job.
 * @var Strope_Table_Discovered_Strings $table      List table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

$strope_sd_last_scan = empty( $components ) ? 0 : max( wp_list_pluck( $components, 'last_scan' ) );
$strope_sd_page_url  = admin_url( 'admin.php?page=mlang_strings&view=discovered' );
?>
<div class="strope-sd-scan postbox">
	<div class="inside">
		<h2><?php esc_html_e( 'Theme and plugin strings', 'strope-translate' ); ?></h2>
		<p>
			<?php esc_html_e( 'Translate or change any text of the active theme and plugins, including in the default language, without editing translation files.', 'strope-translate' ); ?>
		</p>
		<p class="strope-sd-actions">
			<?php if ( current_user_can( 'manage_languages' ) ) : ?>
				<button type="button" class="button button-primary" id="strope-sd-scan" data-action="start"><?php esc_html_e( 'Scan now', 'strope-translate' ); ?></button>
			<?php endif; ?>
			<?php if ( $job ) : ?>
				<button type="button" class="button" id="strope-sd-resume" data-action="step"><?php esc_html_e( 'Resume the interrupted scan', 'strope-translate' ); ?></button>
			<?php endif; ?>
			<span class="strope-sd-last-scan">
				<?php
				if ( $strope_sd_last_scan ) {
					printf(
						/* translators: %s is a date */
						esc_html__( 'Last scan: %s', 'strope-translate' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $strope_sd_last_scan ) )
					);
				} else {
					esc_html_e( 'The active theme and plugins have not been scanned yet.', 'strope-translate' );
				}
				?>
			</span>
		</p>
		<div class="strope-sd-progress" hidden>
			<progress id="strope-sd-progress-bar" max="100" value="0"></progress>
			<span id="strope-sd-progress-text" aria-live="polite"></span>
		</div>
		<?php if ( ! empty( $components ) ) : ?>
			<details class="strope-sd-components">
				<summary><?php esc_html_e( 'Scanned themes and plugins', 'strope-translate' ); ?></summary>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'strope-translate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'strope-translate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Text domain', 'strope-translate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Strings', 'strope-translate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last scan', 'strope-translate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $components as $strope_sd_id => $strope_sd_component ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( add_query_arg( 'component', rawurlencode( $strope_sd_id ), $strope_sd_page_url ) ); ?>"><?php echo esc_html( $strope_sd_component['name'] ); ?></a></td>
								<td><?php echo 'theme' === $strope_sd_component['type'] ? esc_html__( 'Theme', 'strope-translate' ) : esc_html__( 'Plugin', 'strope-translate' ); ?></td>
								<td><code><?php echo esc_html( $strope_sd_component['domain'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( isset( $counts[ $strope_sd_id ] ) ? $counts[ $strope_sd_id ] : 0 ) ); ?></td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $strope_sd_component['last_scan'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endif; ?>
	</div>
</div>

<form id="strope-sd-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
	<input type="hidden" name="page" value="mlang_strings" />
	<input type="hidden" name="view" value="discovered" />
	<?php $table->search_box( __( 'Search strings', 'strope-translate' ), 'strope-sd' ); ?>
</form>

<form id="strope-sd-translations" method="post" action="<?php echo esc_url( add_query_arg( 'noheader', 'true' ) ); ?>">
	<input type="hidden" name="strope_action" value="<?php echo esc_attr( Strope_String_Discovery_Admin::SAVE_ACTION ); ?>" />
	<?php
	wp_nonce_field( Strope_String_Discovery_Admin::SAVE_ACTION, '_wpnonce_strope_sd' );
	$table->display();

	if ( $table->has_items() ) {
		submit_button( __( 'Save translations', 'strope-translate' ), 'primary', 'strope_sd_submit' );
	}
	?>
</form>
