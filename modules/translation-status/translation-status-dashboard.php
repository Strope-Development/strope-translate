<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard widget summarizing the translation status per post type and language.
 * Only displayed for post types the user can edit for all authors (`edit_others_posts`).
 *
 * @since 4.1.0
 */
class Strope_Translation_Status_Dashboard {
	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Admin_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;

		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Returns the post types the current user can edit.
	 *
	 * @since 4.1.0
	 *
	 * @return WP_Post_Type[]
	 */
	protected function get_post_types() {
		$post_types = array();

		foreach ( Strope_Translation_Status::get_post_types() as $name ) {
			$post_type = get_post_type_object( $name );

			if ( $post_type && $post_type->show_ui && current_user_can( $post_type->cap->edit_posts ) && current_user_can( $post_type->cap->edit_others_posts ) ) {
				$post_types[] = $post_type;
			}
		}

		return $post_types;
	}

	/**
	 * Registers the widget.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( 'edit_posts' ) || ! $this->model->has_languages() || empty( $this->get_post_types() ) ) {
			return;
		}

		wp_add_dashboard_widget( 'strope_translation_status', __( 'Translation status', 'strope-translate' ), array( $this, 'display' ) );
	}

	/**
	 * Returns the URL of a filtered posts list.
	 *
	 * @since 4.1.0
	 *
	 * @param string $post_type Post type.
	 * @param string $lang      Language slug, 'all' for all languages.
	 * @param string $filter    Filter value.
	 * @return string
	 */
	protected function get_list_url( $post_type, $lang, $filter ) {
		return add_query_arg(
			array(
				'post_type'                                  => $post_type,
				'lang'                                       => $lang,
				Strope_Translation_Status_Admin::QUERY_VAR => $filter,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Displays the widget.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function display() {
		$start    = microtime( true );
		$complete = true;

		foreach ( $this->get_post_types() as $post_type ) {
			$summary = Strope_Translation_Status::get_summary( $post_type->name );

			printf( '<h3 class="strope-ts-dashboard-title">%s</h3>', esc_html( $post_type->labels->name ) );

			if ( empty( $summary['items'] ) ) {
				echo '<p>' . esc_html( $post_type->labels->not_found ) . '</p>';
				continue;
			}

			// Counting the words of the posts not counted yet: 2 seconds for all the post types, the rest in the background.
			$words    = Strope_Translation_Status::get_words( $post_type->name, max( 0.0, 2.0 - ( microtime( true ) - $start ) ) );
			$complete = $complete && $words['complete'];

			echo '<table class="widefat striped strope-ts-dashboard"><thead><tr>';
			printf(
				'<th scope="col">%s</th><th scope="col">%s</th><th scope="col" class="num">%s</th><th scope="col" class="num">%s</th><th scope="col" class="num">%s</th>',
				esc_html__( 'Language', 'strope-translate' ),
				esc_html__( 'Coverage', 'strope-translate' ),
				esc_html__( 'Outdated', 'strope-translate' ),
				esc_html__( 'Needs review', 'strope-translate' ),
				esc_html__( 'Words to translate', 'strope-translate' )
			);
			echo '</tr></thead><tbody>';

			foreach ( $this->model->get_languages_list() as $language ) {
				if ( ! isset( $summary['languages'][ $language->slug ] ) ) {
					continue;
				}

				$row = $summary['languages'][ $language->slug ];

				$coverage = sprintf(
					'<span class="strope-ts-meter" aria-hidden="true"><span style="width:%1$s%%"></span></span> %2$s',
					esc_attr( (string) (int) round( $row['coverage'] ) ),
					esc_html(
						sprintf(
							/* translators: 1: coverage percentage, 2: number of translated items, 3: total number of items */
							__( '%1$s%% (%2$d/%3$d)', 'strope-translate' ),
							number_format_i18n( $row['coverage'], 0 ),
							$row['translated'],
							$summary['items']
						)
					)
				);

				if ( $row['missing'] > 0 ) {
					$coverage .= sprintf(
						' &ndash; <a href="%s">%s</a>',
						esc_url( $this->get_list_url( $post_type->name, 'all', 'missing-' . $language->slug ) ),
						/* translators: %s is a number of missing translations */
						esc_html( sprintf( _n( '%s missing', '%s missing', $row['missing'], 'strope-translate' ), number_format_i18n( $row['missing'] ) ) )
					);
				}

				printf(
					'<tr><th scope="row">%1$s %2$s</th><td>%3$s</td><td class="num">%4$s</td><td class="num">%5$s</td><td class="num">%6$s</td></tr>',
					$language->flag ? $language->flag : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $language->name ),
					$coverage, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$this->count_link( $row['outdated'], $this->get_list_url( $post_type->name, $language->slug, 'outdated' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$this->count_link( $row['needs_review'], $this->get_list_url( $post_type->name, $language->slug, 'needs_review' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$this->words_cell( $words['languages'][ $language->slug ] ?? array() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}

			echo '</tbody></table>';
		}

		if ( ! $complete ) {
			echo '<p class="description">' . esc_html__( 'Some word counts are still being calculated: the numbers of words will be complete in a few minutes.', 'strope-translate' ) . '</p>';
		}
	}

	/**
	 * Returns the words to translate cell: the total, with the details in a tooltip.
	 *
	 * @since 4.3.0
	 *
	 * @param array $words `missing` and `outdated` words.
	 * @return string
	 */
	protected function words_cell( array $words ) {
		$missing  = (int) ( $words['missing'] ?? 0 );
		$outdated = (int) ( $words['outdated'] ?? 0 );

		if ( 0 === $missing + $outdated ) {
			return '0';
		}

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr(
				sprintf(
					/* translators: 1: number of words in the missing translations, 2: number of words in the outdated translations */
					__( '%1$s words in missing translations, %2$s words in outdated translations', 'strope-translate' ),
					number_format_i18n( $missing ),
					number_format_i18n( $outdated )
				)
			),
			esc_html( number_format_i18n( $missing + $outdated ) )
		);
	}

	/**
	 * Returns a count, linked to a list if not zero.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $count Count.
	 * @param string $url   URL.
	 * @return string
	 */
	protected function count_link( $count, $url ) {
		if ( empty( $count ) ) {
			return '0';
		}

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( number_format_i18n( $count ) ) );
	}
}
