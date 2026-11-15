<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the sticky links: corrects the internal links of the content and lists the broken links.
 *
 * @since 4.1.0
 */
class Strope_Sticky_Links_CLI {
	/**
	 * @var Strope_Sticky_Links
	 */
	protected $sticky_links;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Sticky_Links $sticky_links The sticky links module.
	 */
	public function __construct( Strope_Sticky_Links $sticky_links ) {
		$this->sticky_links = $sticky_links;
	}

	/**
	 * Corrects the internal links in the content, directly (a revision is saved for each modified post).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : IDs of the posts to update. Defaults to all the posts waiting to be updated.
	 *
	 * [--all]
	 * : Update all the posts of the translated post types.
	 *
	 * [--post_type=<post_type>]
	 * : With --all, limit the update to one post type.
	 *
	 * [--dry-run]
	 * : Only list the posts whose links would be corrected.
	 *
	 * ## EXAMPLES
	 *
	 *     wp strope links update --all --dry-run
	 *     wp strope links update --all
	 *     wp strope links update 12 34
	 *
	 * @since 4.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$posts   = 0;
		$links   = 0;

		foreach ( $this->get_post_ids( $args, $assoc_args ) as $post_id ) {
			if ( $dry_run ) {
				$result  = $this->sticky_links->preview_post( $post_id );
				$changed = null === $result ? 0 : $result['changed'];
			} else {
				$changed = (int) $this->sticky_links->process_post( $post_id, true );
				delete_post_meta( $post_id, Strope_Sticky_Links::META_PENDING );
			}

			if ( $changed > 0 ) {
				++$posts;
				$links += $changed;
				WP_CLI::log( sprintf( '%s %d link(s) in post %d: %s', $dry_run ? 'Would correct' : 'Corrected', $changed, $post_id, get_the_title( $post_id ) ) );
			}
		}

		$this->sticky_links->flush();

		WP_CLI::success( sprintf( '%s %d link(s) in %d post(s).', $dry_run ? 'Would correct' : 'Corrected', $links, $posts ) );
	}

	/**
	 * Lists the links pointing to deleted content.
	 *
	 * ## OPTIONS
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
	 * @since 4.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function broken( $args, $assoc_args ) {
		$rows = array();

		foreach ( Strope_Sticky_Links::get_broken_links( PHP_INT_MAX ) as $row ) {
			foreach ( $row['urls'] as $url ) {
				$rows[] = array(
					'post_id' => $row['post_id'],
					'title'   => get_the_title( $row['post_id'] ),
					'url'     => $url,
				);
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No broken links.' );
			return;
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'post_id', 'title', 'url' ) );
	}

	/**
	 * Returns the IDs of the posts to update.
	 *
	 * @since 4.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return Generator
	 */
	protected function get_post_ids( $args, $assoc_args ) {
		global $wpdb;

		if ( ! empty( $args ) ) {
			foreach ( $args as $post_id ) {
				yield absint( $post_id );
			}
			return;
		}

		if ( empty( $assoc_args['all'] ) ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id", Strope_Sticky_Links::META_PENDING ) );
			foreach ( $ids as $post_id ) {
				yield (int) $post_id;
			}
			return;
		}

		$post_types = array();
		if ( ! empty( $assoc_args['post_type'] ) ) {
			if ( ! Strope()->model->is_translated_post_type( $assoc_args['post_type'] ) ) {
				WP_CLI::error( sprintf( 'The post type "%s" is not translated.', $assoc_args['post_type'] ) );
			}
			$post_types = array( $assoc_args['post_type'] );
		}

		$cursor = 0;
		do {
			$ids = $this->sticky_links->get_posts_after( $cursor, 200, $post_types );
			foreach ( $ids as $post_id ) {
				yield $post_id;
				$cursor = $post_id;
			}
			wp_cache_flush_runtime();
		} while ( count( $ids ) === 200 );
	}
}
