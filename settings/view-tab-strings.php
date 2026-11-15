<?php
/**
 * Displays the strings translations tab in Strope Translate settings
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}

$strope_strings_view = isset( $_GET['view'] ) && 'discovered' === $_GET['view'] ? 'discovered' : 'registered'; // phpcs:ignore WordPress.Security.NonceVerification
?>
<ul class="subsubsub strope-strings-views">
	<li>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=mlang_strings' ) ); ?>"<?php echo 'registered' === $strope_strings_view ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Registered strings', 'strope-translate' ); ?></a> |
	</li>
	<li>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=mlang_strings&view=discovered' ) ); ?>"<?php echo 'discovered' === $strope_strings_view ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Theme and plugin strings', 'strope-translate' ); ?></a>
	</li>
</ul>
<br class="clear" />
<?php
if ( 'discovered' === $strope_strings_view ) {
	/**
	 * Fires to display the theme and plugin strings in the Translations tab.
	 *
	 * @since 4.1.0
	 */
	do_action( 'strope_strings_tab_discovered' );
	return;
}
?>
<div class="form-wrap">
	<form id="string-translation" method="post" action="<?php echo esc_url( add_query_arg( 'noheader', 'true' ) ); ?>">
		<input type="hidden" name="strope_action" value="string-translation" />
		<?php
		$string_table->search_box( __( 'Search translations', 'strope-translate' ), 'translations' );
		wp_nonce_field( 'string-translation', '_wpnonce_string-translation' );
		$string_table->display();
		printf( '<br /><label><input name="clean" type="checkbox" value="1" /> %s</label>', esc_html__( 'Clean strings translation database', 'strope-translate' ) );
		?>
		<p><?php esc_html_e( 'Use this to remove unused strings from database, for example after a plugin has been uninstalled.', 'strope-translate' ); ?></p>
		<?php
		submit_button(); // Since WP 3.1
		?>
	</form>
	<div class="metabox-holder">
		<?php
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		do_meta_boxes( 'languages_page_mlang_strings', 'normal', array() );
		?>
	</div>

</div>
