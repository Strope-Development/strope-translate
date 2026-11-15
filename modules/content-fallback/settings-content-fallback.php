<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for the content fallback module.
 *
 * @since 4.1.0
 */
class Strope_Settings_Content_Fallback extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 45;

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
				'module'        => 'content_fallback',
				'title'         => __( 'Content fallback', 'strope-translate' ),
				'description'   => __( 'Choose, per content type, whether content which is not translated in the visitor language is hidden or displayed in the default language. Visitors stay in their language (URLs, menus and interface), search engines are pointed to the original content.', 'strope-translate' ),
				'active_option' => 'none',
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
		$settings   = Strope_Content_Fallback::get_settings( $this->options );
		$post_types = Strope_Content_Fallback::get_available_post_types( $this->model );
		$taxonomies = Strope_Content_Fallback::get_available_taxonomies( $this->model );

		$modes = array(
			'translate' => __( 'Translate', 'strope-translate' ),
			'fallback'  => __( 'Show default language content when not translated', 'strope-translate' ),
		);

		if ( ! empty( $post_types ) ) {
			?>
			<h4><?php esc_html_e( 'Post types', 'strope-translate' ); ?></h4>
			<table class="strope-content-fallback-table">
				<?php
				foreach ( $post_types as $post_type ) {
					$object = get_post_type_object( $post_type );
					if ( $object ) {
						$this->print_row( 'post_types', $post_type, $object->labels->name, in_array( $post_type, $settings['post_types'], true ), $modes );
					}
				}
				?>
			</table>
			<?php
		}

		if ( ! empty( $taxonomies ) ) {
			?>
			<h4><?php esc_html_e( 'Taxonomies', 'strope-translate' ); ?></h4>
			<table class="strope-content-fallback-table">
				<?php
				foreach ( $taxonomies as $taxonomy ) {
					$object = get_taxonomy( $taxonomy );
					if ( $object ) {
						$this->print_row( 'taxonomies', $taxonomy, $object->labels->name, in_array( $taxonomy, $settings['taxonomies'], true ), $modes );
					}
				}
				?>
			</table>
			<?php
		}
		?>
		<p>
			<label>
				<input name="content_fallback_notice" type="checkbox" value="1" <?php checked( $settings['notice'] ); ?> />
				<?php esc_html_e( 'Display a notice above the content when it is not available in the visitor language', 'strope-translate' ); ?>
			</label>
		</p>
		<p class="description">
			<?php esc_html_e( 'The fallback requires the language to be set from the URL. Content displayed in the default language keeps its canonical URL in the default language, is marked with its real language and is not listed in the sitemaps of the other languages.', 'strope-translate' ); ?>
		</p>
		<?php
	}

	/**
	 * Prints a row of the settings form.
	 *
	 * @since 4.1.0
	 *
	 * @param string   $group   'post_types' or 'taxonomies'.
	 * @param string   $name    Post type or taxonomy name.
	 * @param string   $label   Post type or taxonomy label.
	 * @param bool     $enabled Whether the fallback is enabled.
	 * @param string[] $modes   Available modes.
	 * @return void
	 */
	protected function print_row( $group, $name, $label, $enabled, $modes ) {
		$id = sprintf( 'strope-content-fallback-%s-%s', $group, $name );
		?>
		<tr>
			<td>
				<label for="<?php echo esc_attr( $id ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1 is a post type or taxonomy label, 2 is a post type or taxonomy key. */
							_x( '%1$s (%2$s)', 'content type setting choice', 'strope-translate' ),
							$label,
							$name
						)
					);
					?>
				</label>
			</td>
			<td>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( sprintf( 'content_fallback_%s[%s]', $group, $name ) ); ?>">
					<?php
					foreach ( $modes as $value => $text ) {
						printf(
							'<option value="%s" %s>%s</option>',
							esc_attr( $value ),
							selected( 'fallback' === $value, $enabled, false ),
							esc_html( $text )
						);
					}
					?>
				</select>
			</td>
		</tr>
		<?php
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
		$settings = Strope_Content_Fallback::get_defaults();

		$available = array(
			'post_types' => Strope_Content_Fallback::get_available_post_types( $this->model ),
			'taxonomies' => Strope_Content_Fallback::get_available_taxonomies( $this->model ),
		);

		foreach ( $available as $group => $names ) {
			$key = 'content_fallback_' . $group;

			if ( empty( $options[ $key ] ) || ! is_array( $options[ $key ] ) ) {
				continue;
			}

			foreach ( $options[ $key ] as $name => $mode ) {
				$name = sanitize_key( $name );
				if ( 'fallback' === $mode && in_array( $name, $names, true ) ) {
					$settings[ $group ][] = $name;
				}
			}
		}

		$settings['notice'] = ! empty( $options['content_fallback_notice'] );

		return array( Strope_Content_Fallback::OPTION => $settings ); // Take care to return only validated options.
	}
}
