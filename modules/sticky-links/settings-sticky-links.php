<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for the sticky links module.
 *
 * @since 4.1.0
 */
class Strope_Settings_Sticky_Links extends Strope_Settings_Module {
	/**
	 * Stores the display order priority.
	 *
	 * @var int
	 */
	public $priority = 47;

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
				'module'        => 'sticky_links',
				'title'         => __( 'Sticky links', 'strope-translate' ),
				'description'   => __( 'Keeps the links between your posts, pages and categories working and in the right language. The links are corrected in the saved content: when you save, and in the background when a linked page changes its address or gets a published translation. Each background correction is saved as a revision.', 'strope-translate' ),
				'active_option' => 'sticky_links',
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
		$pending = Strope_Sticky_Links::count_pending();
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Existing content', 'strope-translate' ); ?></legend>
			<?php if ( Strope_Sticky_Links::is_scanning() ) : ?>
				<p><?php esc_html_e( 'The links of the existing content are being updated in the background.', 'strope-translate' ); ?></p>
			<?php else : ?>
				<label>
					<input name="sticky_links_scan" type="checkbox" value="1" />
					<?php esc_html_e( 'Update the links in all existing content (in the background, with a revision for each modified post)', 'strope-translate' ); ?>
				</label>
			<?php endif; ?>
			<?php if ( $pending > 0 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d is a number of posts */
						esc_html( _n( '%d post is waiting for its links to be updated.', '%d posts are waiting for their links to be updated.', $pending, 'strope-translate' ) ),
						(int) $pending
					);
					?>
				</p>
			<?php endif; ?>
		</fieldset>
		<?php
		$broken = Strope_Sticky_Links::get_broken_links( 50 );

		if ( empty( $broken ) ) {
			return;
		}
		?>
		<fieldset>
			<legend><?php esc_html_e( 'Broken links', 'strope-translate' ); ?></legend>
			<p class="description"><?php esc_html_e( 'These links point to content which was deleted. Edit the posts to correct or remove them.', 'strope-translate' ); ?></p>
			<ul>
				<?php
				foreach ( $broken as $row ) {
					$title = get_the_title( $row['post_id'] );
					$title = '' === $title ? __( '(no title)', 'strope-translate' ) : $title;
					$link  = get_edit_post_link( $row['post_id'] );
					$urls  = implode( ', ', array_map( 'esc_html', $row['urls'] ) );

					if ( $link && current_user_can( 'edit_post', $row['post_id'] ) ) {
						printf( '<li><a href="%s">%s</a>: <code>%s</code></li>', esc_url( $link ), esc_html( $title ), $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					} else {
						printf( '<li>%s: <code>%s</code></li>', esc_html( $title ), $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}
				?>
			</ul>
		</fieldset>
		<?php
	}

	/**
	 * Starts the update of the existing content if requested. No option is saved.
	 *
	 * @since 4.1.0
	 *
	 * @param array $options Unsanitized options to save.
	 * @return array
	 */
	protected function update( $options ) {
		if ( ! empty( $options['sticky_links_scan'] ) && ! empty( $this->options['sticky_links'] ) ) {
			$sticky_links = Strope()->sticky_links ?? null;
			if ( $sticky_links instanceof Strope_Sticky_Links ) {
				$sticky_links->start_scan();
			}
		}

		return array();
	}
}
