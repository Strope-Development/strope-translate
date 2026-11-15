<?php
/**
 * Displays the content of the About metabox
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly
}
?>
<p>
	<?php
	printf(
		/* translators: %s is the plugin version. */
		esc_html__( 'Strope Translate %s. Free software released under the GNU General Public License v3 or later.', 'strope-translate' ),
		esc_html( STROPE_TRANSLATE_VERSION )
	);
	?>
</p>
