<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Service to collect linked post ids.
 *
 * @since 3.3
 */
class Strope_Collect_Linked_Posts {

	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Post types expected for the collected post ids, keyed by post id.
	 * A block referencing a post (for example a reusable block) can only reference a post of a given type.
	 *
	 * @since 4.1.0
	 *
	 * @var string[][]
	 * @phpstan-var array<int, array<string>>
	 */
	protected $expected_post_types = array();

	/**
	 * Ids of the reusable blocks already parsed, to avoid processing them several times or looping infinitely.
	 *
	 * @since 4.1.0
	 *
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	protected $processed_posts = array();

	/**
	 * Strope_Collect_Linked_Posts constructor.
	 *
	 * @since 3.3
	 *
	 * @param array $options The plugin options.
	 */
	public function __construct( $options ) {
		$this->options = $options;
	}

	/**
	 * Gets all the post ids linked to a set of posts.
	 *
	 * @since 3.3
	 * @since 3.4 Parameter changed from int[] to WP_Post[].
	 * @since 3.5 Renamed from get_linked_post_ids and now returns a WP_Post array.
	 *            Also added a second parameter for the post types to retrieve.
	 *
	 * @since 4.1.0 Only returns the posts that the current user can read, and whose type matches the block referencing them.
	 *
	 * @param WP_Post[] $posts      The posts for which searching linked posts.
	 * @param string[]  $post_types Limits the linked posts search to these post types.
	 * @return WP_Post[] An array of linked post objects.
	 */
	public function get_linked_posts( array $posts, array $post_types ) {
		$this->expected_post_types = array();
		$this->processed_posts     = array();

		$linked_ids = array();

		foreach ( $posts as $post ) {
			$linked_ids = array_merge( $linked_ids, $this->get_post_ids_from_post( $post ) );
		}

		$linked_ids          = array_unique( array_filter( array_map( 'intval', $linked_ids ) ) );
		$expected_post_types = $this->expected_post_types;

		$this->expected_post_types = array();
		$this->processed_posts     = array();

		if ( empty( $linked_ids ) ) {
			return array();
		}

		// Query all the linked posts outside the Strope_Export_Bulk_Option::translate() loop to avoid multiple SQL queries with get_post() call.
		$linked_posts = get_posts(
			array(
				'include'     => $linked_ids,
				'post_type'   => $post_types,
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		// The ids come from the content: never collect a post of an unexpected type, or that the current user can't read.
		return array_values(
			array_filter(
				$linked_posts,
				function ( $linked_post ) use ( $expected_post_types ) {
					if ( isset( $expected_post_types[ $linked_post->ID ] ) && ! in_array( $linked_post->post_type, $expected_post_types[ $linked_post->ID ], true ) ) {
						return false;
					}
					return current_user_can( 'read_post', $linked_post->ID );
				}
			)
		);
	}

	/**
	 * Stores the post type expected for post ids found in the content.
	 *
	 * @since 4.1.0
	 *
	 * @param int[]  $ids       Post ids.
	 * @param string $post_type The post type these ids must have.
	 * @return int[] The post ids, unchanged.
	 */
	protected function expect_post_type( array $ids, string $post_type ): array {
		foreach ( $ids as $id ) {
			$this->expected_post_types[ (int) $id ][] = $post_type;
		}
		return $ids;
	}

	/**
	 * Gets the post ids from a post, wether it's classic or block edited.
	 *
	 * @since 3.3
	 *
	 * @param WP_Post $post A given WP_Post object.
	 * @return int[] An array of post ids.
	 */
	protected function get_post_ids_from_post( $post ) {
		$linked_ids = array();

		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			$linked_ids = $this->get_post_ids_from_block_content( $post->post_content );
		} elseif ( $this->options['media_support'] ) {
			$linked_ids = $this->get_medias_from_html_content( $post->post_content );
		}

		if ( $this->options['media_support'] && has_post_thumbnail( $post->ID ) ) {
			$linked_ids = array_merge( $linked_ids, $this->expect_post_type( array( (int) get_post_thumbnail_id( $post->ID ) ), 'attachment' ) );
		}

		$linked_ids = array_filter( $linked_ids ); // Clean up the array.

		/**
		 * Filters the medias linked to a post.
		 *
		 * @since 3.3
		 *
		 * @param int[] $linked_ids Post ids attached to a post (could be in content or in post metas).
		 * @param int   $post_id    The post id the post we get other post from.
		 */
		$linked_ids = apply_filters( 'strope_collect_post_ids', $linked_ids, $post->ID );

		return array_unique( $linked_ids );
	}

	/**
	 * Gets the post ids from block type content.
	 *
	 * @since 3.3
	 *
	 * @param string $post_content The content of the post.
	 * @return int[] An array of post ids.
	 */
	protected function get_post_ids_from_block_content( $post_content ) {
		$blocks = parse_blocks( $post_content );

		return $this->get_post_ids_from_blocks_deep( $blocks );
	}

	/**
	 * Gets the post ids from blocks.
	 *
	 * @since 3.3
	 *
	 * @param array $blocks An array of blocks.
	 * @return int[] An array of post ids.
	 */
	protected function get_post_ids_from_blocks_deep( $blocks ) {
		$post_ids = array();

		foreach ( $blocks as $block ) {
			if ( $this->options['media_support'] ) {
				$post_ids = array_merge( $post_ids, $this->get_media_ids_from_block( $block ) );
			}
			$post_ids = array_merge( $post_ids, $this->get_navigation_block_ids( $block ) );
			$post_ids = array_merge( $post_ids, $this->get_reusable_block_ids( $block ) );
		}
		return array_unique( $post_ids );
	}

	/**
	 * Gets the media ids from a block.
	 *
	 * @since 3.3
	 *
	 * @param array $block A representative array of a block.
	 * @return int[] An array of media ids, empty if none found.
	 */
	protected function get_media_ids_from_block( $block ) {
		$post_ids = array();

		switch ( $block['blockName'] ) {
			case 'core/audio':
			case 'core/cover':
			case 'core/file':
			case 'core/image':
			case 'core/video':
				if ( ! empty( $block['attrs']['id'] ) ) {
					$post_ids[] = (int) $block['attrs']['id'];
				}
				break;
			case 'core/gallery':
				// Backward compatibility with WP < 5.9.
				if ( isset( $block['attrs']['ids'] ) && is_array( $block['attrs']['ids'] ) ) {
					$post_ids = array_merge( $post_ids, array_map( 'intval', $block['attrs']['ids'] ) );
				}
				break;
			case 'core/media-text':
				if ( ! empty( $block['attrs']['mediaId'] ) ) {
					$post_ids[] = (int) $block['attrs']['mediaId'];
				}
				break;
			default:
				if ( ! empty( $block['innerHTML'] ) ) {
					$post_ids = array_merge( $post_ids, $this->get_medias_from_html_content( $block['innerHTML'] ) );
				}
				break;
		}
		$post_ids = $this->expect_post_type( $post_ids, 'attachment' );

		if ( ! empty( $block['innerBlocks'] ) ) {
			$post_ids = array_merge( $post_ids, $this->get_post_ids_from_blocks_deep( $block['innerBlocks'] ) );
		}

		return $post_ids;
	}

	/**
	 * Gets the media ids from classic type content.
	 *
	 * @since 3.3
	 *
	 * @param string $post_content The content of the post.
	 * @return int[]
	 */
	protected function get_medias_from_html_content( $post_content ) {

		return $this->expect_post_type(
			array_merge( $this->get_medias_from_img_tags( $post_content ), $this->get_medias_from_shortcodes( $post_content ) ),
			'attachment'
		);
	}

	/**
	 * Gets media ids from shortcodes.
	 *
	 * @since 3.3
	 *
	 * @param string $post_content The content of the required post.
	 * @return int[] The media ids.
	 */
	protected function get_medias_from_shortcodes( $post_content ) {
		$media_ids = array();

		if ( preg_match_all( '/' . get_shortcode_regex() . '/s', $post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $shortcode ) {
				$attributes = shortcode_parse_atts( $shortcode[3] ); // $shortcode[3] returns the shortcode attributes as string.

				switch ( $shortcode[2] ) { // $shortcode[2] returns the shortcode name.
					case 'caption':
						if ( isset( $attributes['id'] ) ) {
							preg_match( '/attachment_([0-9]+)/', $attributes['id'], $attr );
							$media_ids[] = (int) $attr[1];
						}
						break;
					case 'gallery':
					case 'playlist':
						if ( isset( $attributes['ids'] ) ) {
							$media_ids = array_merge( $media_ids, array_map( 'intval', explode( ',', $attributes['ids'] ) ) );
						}
						break;
				}
			}
		}

		return $media_ids;
	}

	/**
	 * Gets media ids from img tags
	 *
	 * @since 3.3
	 *
	 * @param string $post_content The content of the post to search from.
	 * @return int[] An array of media ids (empty if no media is found).
	 */
	protected function get_medias_from_img_tags( $post_content ) {
		$media_ids = array();
		$textarr   = wp_html_split( $post_content );
		if ( ! is_array( $textarr ) ) {
			return $media_ids;
		}
		foreach ( $textarr as $text ) {
			if ( 0 !== strpos( $text, '<img' ) ) {
				continue;
			}
			$attributes = wp_kses_attr_parse( $text );
			if ( ! is_array( $attributes ) ) {
				continue;
			}
			foreach ( $attributes as $attr ) {
				if ( 0 === strpos( $attr, 'class' ) && preg_match( '#wp\-image\-([0-9]+)#', $attr, $matches ) ) {
					$media_ids[] = (int) $matches[1];
				}
			}
		}

		return $media_ids;
	}

	/**
	 * Gets id from a reusable block.
	 * Also recursively get contained reusable block ids.
	 *
	 * @since 3.3
	 * @since 4.1.0 Parses only the reusable blocks that the current user can read, and each of them only once.
	 *
	 * @param array $block An array containing block data.
	 * @return int[] An array of reusable block ids, empty if none found.
	 */
	protected function get_reusable_block_ids( $block ) {
		$ids = array();

		if ( 'core/block' !== $block['blockName'] || empty( $block['attrs']['ref'] ) ) {
			return $ids;
		}

		$ref   = (int) $block['attrs']['ref'];
		$ids[] = $ref;
		$this->expect_post_type( $ids, 'wp_block' );

		if ( isset( $this->processed_posts[ $ref ] ) ) {
			// Already parsed, e.g. a reusable block containing itself.
			return $ids;
		}

		$this->processed_posts[ $ref ] = $ref;

		$linked_block_post = get_post( $ref );

		if ( $linked_block_post instanceof WP_Post && 'wp_block' === $linked_block_post->post_type && current_user_can( 'read_post', $ref ) ) {
			$ids = array_merge( $ids, $this->get_post_ids_from_block_content( $linked_block_post->post_content ) );
		}

		return $ids;
	}

	/**
	 * Returns the ID from a navigation block.
	 *
	 * @since 3.3
	 *
	 * @param array $block An array containing block data.
	 * @return int[] An array of navigation post IDs, empty if none found.
	 */
	protected function get_navigation_block_ids( $block ) {
		if ( 'core/navigation' !== $block['blockName'] || empty( $block['attrs']['ref'] ) ) {
			return array();
		}
		return $this->expect_post_type( array( (int) $block['attrs']['ref'] ), 'wp_navigation' );
	}
}
