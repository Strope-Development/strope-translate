<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI command displaying a summary of the translation status.
 *
 * @since 4.1.0
 */
class Strope_Translation_Status_CLI {
	/**
	 * Displays the translation coverage, outdated and needs review counts per post type and language.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Limit the summary to one post type.
	 *
	 * [--words]
	 * : Also show the words to translate: in the missing translations, and in the outdated translations.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp strope translation-status
	 *     wp strope translation-status --post_type=page --format=json
	 *     wp strope translation-status --words
	 *
	 * @since 4.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$post_types = Strope_Translation_Status::get_post_types();

		if ( ! empty( $assoc_args['post_type'] ) ) {
			if ( ! in_array( $assoc_args['post_type'], $post_types, true ) ) {
				WP_CLI::error( sprintf( 'The post type "%s" is not translated or not tracked.', $assoc_args['post_type'] ) );
			}
			$post_types = array( $assoc_args['post_type'] );
		}

		$rows = array();

		foreach ( $post_types as $post_type ) {
			$summary = Strope_Translation_Status::get_summary( $post_type );
			$words   = empty( $assoc_args['words'] ) ? null : Strope_Translation_Status::get_words( $post_type, null );

			foreach ( $summary['languages'] as $slug => $row ) {
				$rows[] = array(
					'post_type'    => $post_type,
					'language'     => $slug,
					'items'        => $summary['items'],
					'translated'   => $row['translated'],
					'missing'      => $row['missing'],
					'coverage'     => $row['coverage'] . '%',
					'outdated'     => $row['outdated'],
					'needs_review' => $row['needs_review'],
				);

				if ( $words ) {
					$rows[ count( $rows ) - 1 ] += array(
						'words_missing'  => $words['languages'][ $slug ]['missing'] ?? 0,
						'words_outdated' => $words['languages'][ $slug ]['outdated'] ?? 0,
					);
				}
			}
		}

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array_merge(
				array( 'post_type', 'language', 'items', 'translated', 'missing', 'coverage', 'outdated', 'needs_review' ),
				empty( $assoc_args['words'] ) ? array() : array( 'words_missing', 'words_outdated' )
			)
		);
	}
}
