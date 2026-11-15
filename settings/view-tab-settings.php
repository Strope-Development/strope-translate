<?php
/**
 * Displays the settings tab in Strope Translate settings
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}
?>
<div class="form-wrap">
	<?php
	wp_nonce_field( 'strope_options', '_strope_nonce' );
	$list_table = new Strope_Table_Settings();
	$list_table->prepare_items( $this->modules );
	$list_table->display();
	?>
</div>
