<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marks the editable elements of the page in front-end edit mode.
 *
 * The blocks of a post content get a `data-strope-edit="<post_id>:<path>"` attribute where `<path>`
 * is the position of the block in the tree returned by `parse_blocks( $post->post_content )`.
 * The paths are computed while the post content is rendered: a frame is opened when `the_content`
 * starts (just before `do_blocks()`), top level blocks are counted in this frame and inner blocks
 * are counted in their parent. Each computed path is checked against the stored blocks tree.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_Markers {
	/**
	 * Key added to the parsed blocks to identify them between `render_block_data` and `render_block`.
	 *
	 * @var string
	 */
	const KEY = 'strope_fe_id';

	/**
	 * Strope Translate's model.
	 *
	 * @var Strope_Model
	 */
	private $model;

	/**
	 * Blocks being rendered, by internal id.
	 *
	 * @var array[]
	 */
	private $entries = array();

	/**
	 * Internal id counter.
	 *
	 * @var int
	 */
	private $next_id = 0;

	/**
	 * Number of blocks currently being rendered.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Stack of the `the_content` filters being applied for block content.
	 *
	 * @var array[]
	 */
	private $frames = array();

	/**
	 * Stack of the `the_content` filters being applied for classic content (post ID or 0).
	 *
	 * @var int[]
	 */
	private $classic = array();

	/**
	 * Cache of the parsed blocks of the posts.
	 *
	 * @var array[]
	 */
	private $trees = array();

	/**
	 * Cache of the edit capability by post.
	 *
	 * @var bool[]
	 */
	private $can_edit = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model $model Strope Translate's model.
	 */
	public function __construct( $model ) {
		$this->model = $model;

		add_filter( 'the_content', array( $this, 'start_content' ), 8 ); // Just before do_blocks().
		add_filter( 'the_content', array( $this, 'end_content' ), 9 );   // Just after do_blocks().
		add_filter( 'the_content', array( $this, 'wrap_classic_content' ), 9999 );
		add_filter( 'pre_render_block', array( $this, 'pre_render_block' ), PHP_INT_MAX, 3 );
		add_filter( 'render_block_data', array( $this, 'render_block_data' ), PHP_INT_MAX, 3 );
		add_filter( 'render_block', array( $this, 'render_block' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Tells whether the current user can edit a post from the front end.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_edit( int $post_id ): bool {
		if ( ! isset( $this->can_edit[ $post_id ] ) ) {
			$post = get_post( $post_id );

			$this->can_edit[ $post_id ] = $post instanceof WP_Post
				&& $this->model->is_translated_post_type( $post->post_type )
				&& current_user_can( 'edit_post', $post_id );
		}
		return $this->can_edit[ $post_id ];
	}

	/**
	 * Returns the stored blocks tree of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function get_tree( WP_Post $post ): array {
		if ( ! isset( $this->trees[ $post->ID ] ) ) {
			$this->trees[ $post->ID ] = parse_blocks( $post->post_content );
		}
		return $this->trees[ $post->ID ];
	}

	/**
	 * Opens a frame when the content of a post starts being filtered.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Post content.
	 * @return string Unmodified content.
	 */
	public function start_content( $content ) {
		$post    = get_post();
		$frame   = array(
			'post'  => 0,
			'depth' => $this->depth,
			'top'   => 0,
		);
		$classic = 0;

		if ( $post instanceof WP_Post && is_string( $content ) && $this->can_edit( $post->ID ) ) {
			if ( has_blocks( $post->post_content ) ) {
				if ( $this->content_matches( $content, $post ) ) {
					$frame['post'] = $post->ID;
				}
			} elseif ( $this->normalize( $content ) === $this->normalize( $post->post_content ) && '' !== trim( $content ) ) {
				$classic = $post->ID;
			}
		}

		$this->frames[]  = $frame;
		$this->classic[] = $classic;
		return $content;
	}

	/**
	 * Closes the frame once the blocks have been rendered.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Post content.
	 * @return string Unmodified content.
	 */
	public function end_content( $content ) {
		array_pop( $this->frames );
		return $content;
	}

	/**
	 * Wraps a classic (non-block) content in an element opening the editor.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function wrap_classic_content( $content ) {
		$post_id = (int) array_pop( $this->classic );
		if ( $post_id && is_string( $content ) ) {
			$content = sprintf(
				'<div class="strope-fe-classic" data-strope-edit="%s" data-strope-block="classic" tabindex="0">%s</div>',
				esc_attr( $post_id . ':content' ),
				$content
			);
		}
		return $content;
	}

	/**
	 * Normalizes a content for comparison.
	 *
	 * @since 4.1.0
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private function normalize( string $content ): string {
		return trim( str_replace( array( "\r\n", "\r", ']]&gt;' ), array( "\n", "\n", ']]>' ), $content ) );
	}

	/**
	 * Tells whether a content being rendered corresponds to the beginning of the stored post content,
	 * so that the computed paths match the stored blocks.
	 *
	 * @since 4.1.0
	 *
	 * @param string  $content Content being filtered.
	 * @param WP_Post $post    Post.
	 * @return bool
	 */
	private function content_matches( string $content, WP_Post $post ): bool {
		if ( $this->normalize( $content ) === $this->normalize( $post->post_content ) ) {
			return true;
		}

		$rendered = parse_blocks( $content );
		$stored   = $this->get_tree( $post );

		if ( empty( $rendered ) || count( $rendered ) > count( $stored ) ) {
			return false;
		}

		return $this->blocks_match( $rendered, array_slice( $stored, 0, count( $rendered ) ) );
	}

	/**
	 * Compares two lists of parsed blocks: same structure and same text in the supported blocks.
	 *
	 * @since 4.1.0
	 *
	 * @param array $a Parsed blocks.
	 * @param array $b Parsed blocks.
	 * @return bool
	 */
	private function blocks_match( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		foreach ( $a as $i => $block ) {
			if ( $block['blockName'] !== $b[ $i ]['blockName'] ) {
				return false;
			}
			if ( Strope_Frontend_Editor_Blocks::is_supported( $block['blockName'] ) && $block['innerHTML'] !== $b[ $i ]['innerHTML'] ) {
				return false;
			}
			if ( ! $this->blocks_match( (array) $block['innerBlocks'], (array) $b[ $i ]['innerBlocks'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Counts a block whose rendering was short-circuited by a `pre_render_block` filter
	 * (e.g. a block hidden in the current language), so that the next blocks get the right path.
	 *
	 * Hooked at the highest priority so that it sees the final value of the filter.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null   $pre_render   The pre-rendered content, null if the block is rendered normally.
	 * @param array         $parsed_block The block being rendered.
	 * @param WP_Block|null $parent_block The parent block, if any.
	 * @return string|null Unmodified pre-rendered content.
	 */
	public function pre_render_block( $pre_render, $parsed_block, $parent_block = null ) {
		if ( null !== $pre_render ) {
			$this->next_position( $parent_block );
		}
		return $pre_render;
	}

	/**
	 * Advances the position counters and returns the position of the block about to be rendered.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Block|null $parent_block The parent block, if any.
	 * @return array Post ID (0 if the block is not part of an editable post content) and block path.
	 */
	private function next_position( $parent_block ): array {
		$position = array(
			'post' => 0,
			'path' => '',
		);

		if ( null === $parent_block ) {
			$frame = empty( $this->frames ) ? null : array_key_last( $this->frames );
			if ( null !== $frame && $this->frames[ $frame ]['post'] && $this->depth === $this->frames[ $frame ]['depth'] ) {
				$position['post'] = $this->frames[ $frame ]['post'];
				$position['path'] = (string) $this->frames[ $frame ]['top']++;
			}
		} elseif ( $parent_block instanceof WP_Block && isset( $parent_block->parsed_block[ self::KEY ] ) ) {
			$parent = $parent_block->parsed_block[ self::KEY ];
			if ( isset( $this->entries[ $parent ] ) && $this->entries[ $parent ]['post'] ) {
				$position['post'] = $this->entries[ $parent ]['post'];
				$position['path'] = $this->entries[ $parent ]['path'] . '.' . $this->entries[ $parent ]['children']++;
			}
		}

		return $position;
	}

	/**
	 * Computes the path of a block before it is rendered.
	 *
	 * @since 4.1.0
	 *
	 * @param array         $parsed_block The block being rendered.
	 * @param array         $source_block An un-modified copy of `$parsed_block`.
	 * @param WP_Block|null $parent_block The parent block, if any.
	 * @return array
	 */
	public function render_block_data( $parsed_block, $source_block, $parent_block = null ) {
		if ( ! is_array( $parsed_block ) ) {
			return $parsed_block;
		}

		$entry = array_merge( $this->next_position( $parent_block ), array( 'children' => 0 ) );

		if ( $entry['post'] ) {
			$post   = get_post( $entry['post'] );
			$stored = $post instanceof WP_Post ? Strope_Frontend_Editor_Blocks::get_block( $this->get_tree( $post ), $entry['path'] ) : null;
			$source = is_array( $source_block ) ? $source_block : $parsed_block;

			// The block must be the stored one: same name and, for editable blocks, same markup.
			if (
				null === $stored
				|| $stored['blockName'] !== $parsed_block['blockName']
				|| ( Strope_Frontend_Editor_Blocks::is_supported( $stored['blockName'] ) && ( $source['innerHTML'] ?? null ) !== $stored['innerHTML'] )
			) {
				$entry['post'] = 0;
			}
		}

		$id                        = ++$this->next_id;
		$this->entries[ $id ]      = $entry;
		$parsed_block[ self::KEY ] = $id;
		++$this->depth;

		return $parsed_block;
	}

	/**
	 * Adds the markers to a rendered block.
	 *
	 * @since 4.1.0
	 *
	 * @param string        $block_content Rendered block.
	 * @param array         $block         Parsed block.
	 * @param WP_Block|null $instance      Block instance.
	 * @return string
	 */
	public function render_block( $block_content, $block, $instance = null ) {
		if ( ! is_array( $block ) || ! is_string( $block_content ) ) {
			return $block_content;
		}

		if ( isset( $block[ self::KEY ], $this->entries[ $block[ self::KEY ] ] ) ) {
			$entry = $this->entries[ $block[ self::KEY ] ];
			unset( $this->entries[ $block[ self::KEY ] ] );
			--$this->depth;

			if ( $entry['post'] && null !== $block['blockName'] ) {
				return $this->mark_block( $block_content, $entry['post'] . ':' . $entry['path'], $block );
			}
		}

		if ( $instance instanceof WP_Block && in_array( $block['blockName'], array( 'core/post-title', 'core/post-excerpt' ), true ) && ! empty( $instance->context['postId'] ) ) {
			$post_id = (int) $instance->context['postId'];
			if ( $this->can_edit( $post_id ) ) {
				if ( 'core/post-title' === $block['blockName'] ) {
					return $this->mark_element( $block_content, $post_id . ':title', $block['blockName'] );
				}
				if ( has_excerpt( $post_id ) ) {
					return $this->mark_element( $block_content, $post_id . ':excerpt', $block['blockName'] );
				}
			}
		}

		return $block_content;
	}

	/**
	 * Marks the outer element of a dynamic block (title, excerpt).
	 *
	 * @since 4.1.0
	 *
	 * @param string $html Rendered block.
	 * @param string $key  Marker value.
	 * @param string $name Block name.
	 * @return string
	 */
	private function mark_element( string $html, string $key, string $name ): string {
		$processor = new WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag() ) {
			return $html;
		}
		$processor->set_attribute( 'data-strope-edit', $key );
		$processor->set_attribute( 'data-strope-block', $name );
		$processor->set_attribute( 'data-strope-field', 'text' );
		if ( null === $processor->get_attribute( 'tabindex' ) ) {
			$processor->set_attribute( 'tabindex', '0' );
		}
		return $processor->get_updated_html();
	}

	/**
	 * Marks a rendered block and its editable elements.
	 *
	 * @since 4.1.0
	 *
	 * @param string $html  Rendered block.
	 * @param string $key   Marker value (`<post_id>:<path>`).
	 * @param array  $block Parsed block.
	 * @return string
	 */
	private function mark_block( string $html, string $key, array $block ): string {
		$name    = (string) $block['blockName'];
		$targets = $this->get_field_targets( $html, $block );

		$processor = new WP_HTML_Tag_Processor( $html );
		$index     = 0;

		while ( $processor->next_tag() ) {
			if ( 0 === $index ) {
				$processor->set_attribute( 'data-strope-edit', $key );
				$processor->set_attribute( 'data-strope-block', $name );
				if ( Strope_Frontend_Editor_Blocks::is_supported( $name ) && null === $processor->get_attribute( 'tabindex' ) ) {
					$processor->set_attribute( 'tabindex', '0' );
				}
			}
			if ( isset( $targets[ $index ] ) ) {
				$processor->set_attribute( 'data-strope-field', $targets[ $index ] );
			}
			++$index;
			if ( empty( $targets ) || $index > max( array_keys( $targets ) ) ) {
				break;
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Finds the rendered elements holding the editable fields of a block.
	 *
	 * @since 4.1.0
	 *
	 * @param string $html  Rendered block.
	 * @param array  $block Parsed block.
	 * @return string[] Field names by tag index.
	 */
	private function get_field_targets( string $html, array $block ): array {
		$fields = Strope_Frontend_Editor_Blocks::get_block_fields( $block );
		if ( empty( $fields ) ) {
			return array();
		}

		$tags      = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tags[] = strtolower( (string) $processor->get_tag() );
		}

		$targets = array();
		foreach ( $fields as $field ) {
			$spec    = Strope_Frontend_Editor_Blocks::get_field_spec( $block['blockName'], $field );
			$matches = array_keys( array_intersect( $tags, $spec['tags'] ) );
			if ( $spec['multiple'] ) {
				$nth = Strope_Frontend_Editor_Blocks::parse_field( $field )[1];
			} else {
				$nth = $spec['nth'] < 0 ? count( $matches ) - 1 : $spec['nth'];
			}
			if ( isset( $matches[ $nth ] ) && ! isset( $targets[ $matches[ $nth ] ] ) ) {
				$targets[ $matches[ $nth ] ] = $field;
			}
		}
		return $targets;
	}
}
