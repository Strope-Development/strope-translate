<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for synchronization settings management
 *
 * @since 1.8
 */
class Strope_Settings_Sync extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 50;

	/**
	 * Constructor
	 *
	 * @since 1.8
	 *
	 * @param object $strope_translate The strope_translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct(
			$strope_translate,
			array(
				'module'      => 'sync',
				'title'       => __( 'Synchronization', 'strope-translate' ),
				'description' => __( 'The synchronization options allow to maintain exact same values (or translations in the case of taxonomies and page parent) of meta content between the translations of a post or page.', 'strope-translate' ),
			)
		);
	}

	/**
	 * Deactivates the module
	 *
	 * @since 1.8
	 */
	public function deactivate() {
		$this->options['sync'] = array();
		update_option( 'strope_translate', $this->options );
	}

	/**
	 * Displays the settings form
	 *
	 * @since 1.8
	 */
	protected function form() {
		?>
		<ul class="strope-inline-block-list">
			<?php
			foreach ( self::list_metas_to_sync() as $key => $str ) {
				printf(
					'<li><label><input name="sync[%s]" type="checkbox" value="1" %s /> %s</label></li>',
					esc_attr( $key ),
					checked( in_array( $key, $this->options['sync'] ), true, false ),
					esc_html( $str )
				);
			}
			?>
		</ul>
		<?php
	}

	/**
	 * Sanitizes the settings before saving.
	 *
	 * @since 1.8
	 *
	 * @param array $options Unsanitized options to save.
	 * @return array
	 */
	protected function update( $options ) {
		$newoptions = array( 'sync' => empty( $options['sync'] ) ? array() : array_keys( $options['sync'], 1 ) );
		return $newoptions; // Take care to return only validated options.
	}

	/**
	 * Get the row actions.
	 *
	 * @since 1.8
	 *
	 * @return string[] Row actions.
	 */
	protected function get_actions() {
		return empty( $this->options['sync'] ) ? array( 'configure' ) : array( 'configure', 'deactivate' );
	}

	/**
	 * Get the list of synchronization settings.
	 *
	 * @since 1.0
	 *
	 * @return string[] Array synchronization options.
	 */
	public static function list_metas_to_sync() {
		return array(
			'taxonomies'        => __( 'Taxonomies', 'strope-translate' ),
			'post_meta'         => __( 'Custom fields', 'strope-translate' ),
			'comment_status'    => __( 'Comment status', 'strope-translate' ),
			'ping_status'       => __( 'Ping status', 'strope-translate' ),
			'sticky_posts'      => __( 'Sticky posts', 'strope-translate' ),
			'post_date'         => __( 'Published date', 'strope-translate' ),
			'post_format'       => __( 'Post format', 'strope-translate' ),
			'post_parent'       => __( 'Page parent', 'strope-translate' ),
			'_wp_page_template' => __( 'Page template', 'strope-translate' ),
			'menu_order'        => __( 'Page order', 'strope-translate' ),
			'_thumbnail_id'     => __( 'Featured image', 'strope-translate' ),
		);
	}
}
