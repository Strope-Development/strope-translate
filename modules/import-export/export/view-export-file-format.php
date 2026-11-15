<?php
/**
 * Outputs the export file format dropdown in the bulk translate form.
 *
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

$selected = array_key_first( $supported_formats );
?>
<label for="strope-select-format">
	<span><?php esc_html_e( 'File format', 'strope-translate' ); ?></span>
	<select name="filetype" id="strope-select-format">
		<?php
		foreach ( $supported_formats as $key => $format ) {
			?>
			<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $key, $selected ); ?>>
				<?php echo esc_html( Strope_File_Format_Factory::get_format_label( $key ) ); ?>
			</option>
			<?php
		}
		?>
	</select>
</label>
