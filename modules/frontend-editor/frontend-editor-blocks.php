<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers to locate and modify the text of blocks stored in a post content.
 *
 * Blocks are identified by their path in the tree returned by `parse_blocks()`,
 * e.g. `2.0.1` is the second inner block of the first inner block of the third top level block.
 * Top level indexes include the freeform blocks (whitespace between blocks), exactly as `do_blocks()` renders them.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_Blocks {

	/**
	 * Returns the inline HTML elements and attributes allowed in an edited text.
	 *
	 * @since 4.1.0
	 *
	 * @return array[] Allowed HTML in the `wp_kses()` format.
	 */
	public static function get_allowed_html(): array {
		$allowed = array(
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
			'br'     => array(),
			'a'      => array(
				'href'      => true,
				'target'    => true,
				'rel'       => true,
				'hreflang'  => true,
				'title'     => true,
				'data-type' => true,
				'data-id'   => true,
			),
			'span'   => array(
				'lang' => true,
				'dir'  => true,
			),
		);

		/**
		 * Filters the inline HTML allowed in the texts edited with the front-end translation editor.
		 *
		 * @since 4.1.0
		 *
		 * @param array[] $allowed Allowed HTML in the `wp_kses()` format.
		 */
		return (array) apply_filters( 'strope_frontend_editor_allowed_html', $allowed );
	}

	/**
	 * Returns the editable fields of the supported blocks.
	 *
	 * Each field is described by:
	 * - `tags`:     The tag names of the element holding the text.
	 * - `part`:     `first` or `last` string part of the block `innerContent` where the element is.
	 * - `nth`:      Which matching element to use: 0 for the first one, -1 for the last one.
	 * - `open`:     Whether the closing tag may be in another part (e.g. list item with a nested list).
	 * - `pre`:      Whether the text is preformatted (new lines are kept as is).
	 * - `multiple`: Whether the field is repeated (e.g. table cells), the field name is then `name:index`.
	 *
	 * @since 4.1.0
	 *
	 * @return array[]
	 */
	public static function get_specs(): array {
		static $specs = null;

		if ( null !== $specs ) {
			return $specs;
		}

		$specs = array(
			'core/paragraph'    => array( 'text' => array( 'tags' => array( 'p' ) ) ),
			'core/heading'      => array( 'text' => array( 'tags' => array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ) ),
			'core/list-item'    => array(
				'text' => array(
					'tags' => array( 'li' ),
					'open' => true,
				),
			),
			'core/quote'        => array(
				'citation' => array(
					'tags' => array( 'cite' ),
					'part' => 'last',
					'nth'  => -1,
				),
			),
			'core/pullquote'    => array(
				'text'     => array( 'tags' => array( 'p' ) ),
				'citation' => array( 'tags' => array( 'cite' ) ),
			),
			'core/verse'        => array(
				'text' => array(
					'tags' => array( 'pre' ),
					'pre'  => true,
				),
			),
			'core/preformatted' => array(
				'text' => array(
					'tags' => array( 'pre' ),
					'pre'  => true,
				),
			),
			'core/button'       => array( 'text' => array( 'tags' => array( 'a', 'button' ) ) ),
			'core/details'      => array( 'summary' => array( 'tags' => array( 'summary' ) ) ),
			'core/table'        => array(
				'cell'    => array(
					'tags'     => array( 'td', 'th' ),
					'multiple' => true,
				),
				'caption' => array( 'tags' => array( 'figcaption' ) ),
			),
		);

		foreach ( $specs as $name => $fields ) {
			foreach ( $fields as $field => $spec ) {
				$specs[ $name ][ $field ] = array_merge(
					array(
						'tags'     => array(),
						'part'     => 'first',
						'nth'      => 0,
						'open'     => false,
						'pre'      => false,
						'multiple' => false,
					),
					$spec
				);
			}
		}

		return $specs;
	}

	/**
	 * Tells whether a block type has editable fields.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $name Block name.
	 * @return bool
	 */
	public static function is_supported( $name ): bool {
		return is_string( $name ) && isset( self::get_specs()[ $name ] );
	}

	/**
	 * Splits a field name into its key and its index (for repeated fields).
	 *
	 * @since 4.1.0
	 *
	 * @param string $field Field name, e.g. `text` or `cell:3`.
	 * @return array Field key and index (-1 when not a repeated field).
	 */
	public static function parse_field( string $field ): array {
		if ( preg_match( '/^([a-z]+):(\d+)$/', $field, $matches ) ) {
			return array( $matches[1], (int) $matches[2] );
		}
		return array( $field, -1 );
	}

	/**
	 * Returns the spec of a field of a block.
	 *
	 * @since 4.1.0
	 *
	 * @param string|null $name  Block name.
	 * @param string      $field Field name.
	 * @return array|null
	 */
	public static function get_field_spec( $name, string $field ) {
		if ( ! self::is_supported( $name ) ) {
			return null;
		}
		list( $key, $index ) = self::parse_field( $field );
		$specs = self::get_specs()[ $name ];
		if ( ! isset( $specs[ $key ] ) || ( $specs[ $key ]['multiple'] xor $index >= 0 ) ) {
			return null;
		}
		return $specs[ $key ];
	}

	/**
	 * Returns a translated label for a field.
	 *
	 * @since 4.1.0
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	public static function get_field_label( string $field ): string {
		list( $key, $index ) = self::parse_field( $field );
		switch ( $key ) {
			case 'citation':
				return __( 'Citation', 'strope-translate' );
			case 'summary':
				return __( 'Summary', 'strope-translate' );
			case 'caption':
				return __( 'Caption', 'strope-translate' );
			case 'cell':
				/* translators: %d is a table cell number. */
				return sprintf( __( 'Cell %d', 'strope-translate' ), $index + 1 );
			default:
				return __( 'Text', 'strope-translate' );
		}
	}

	/**
	 * Finds the elements matching some tag names in an HTML fragment.
	 *
	 * @since 4.1.0
	 *
	 * @param string   $html HTML fragment.
	 * @param string[] $tags Lowercase tag names.
	 * @return array[] List of elements with the offsets of their inner HTML (`end` is null when unclosed).
	 */
	public static function find_elements( string $html, array $tags ): array {
		$scanner  = new Strope_Frontend_Editor_Tag_Scanner( $html );
		$elements = array();
		$open     = array();

		while ( $scanner->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = strtolower( (string) $scanner->get_tag() );
			if ( ! in_array( $tag, $tags, true ) ) {
				continue;
			}

			$span = $scanner->get_current_span();
			if ( null === $span ) {
				return array();
			}

			if ( $scanner->is_tag_closer() ) {
				if ( ! empty( $open[ $tag ] ) ) {
					$i                     = array_pop( $open[ $tag ] );
					$elements[ $i ]['end'] = $span[0];
				}
				continue;
			}

			$elements[]     = array(
				'tag'   => $tag,
				'start' => $span[0] + $span[1],
				'end'   => null,
			);
			$open[ $tag ][] = count( $elements ) - 1;
		}

		return $elements;
	}

	/**
	 * Locates the inner HTML of a field in the `innerContent` of a parsed block.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $block Parsed block.
	 * @param string $field Field name.
	 * @return int[]|null Index of the `innerContent` part, start and end offsets. Null if not found.
	 */
	public static function locate_field( array $block, string $field ) {
		$spec = self::get_field_spec( $block['blockName'] ?? null, $field );
		if ( null === $spec || empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return null;
		}

		$parts = array_keys( array_filter( $block['innerContent'], 'is_string' ) );
		if ( empty( $parts ) ) {
			return null;
		}

		$part     = 'last' === $spec['part'] ? end( $parts ) : reset( $parts );
		$html     = $block['innerContent'][ $part ];
		$elements = self::find_elements( $html, $spec['tags'] );

		if ( $spec['multiple'] ) {
			$nth = self::parse_field( $field )[1];
		} else {
			$nth = $spec['nth'] < 0 ? count( $elements ) - 1 : $spec['nth'];
		}

		if ( $nth < 0 || ! isset( $elements[ $nth ] ) ) {
			return null;
		}

		$element = $elements[ $nth ];
		if ( null === $element['end'] ) {
			if ( ! $spec['open'] ) {
				return null;
			}
			$element['end'] = strlen( $html );
		}

		return array( $part, $element['start'], $element['end'] );
	}

	/**
	 * Returns the list of fields available in a parsed block.
	 *
	 * @since 4.1.0
	 *
	 * @param array $block Parsed block.
	 * @return string[]
	 */
	public static function get_block_fields( array $block ): array {
		$name = $block['blockName'] ?? null;
		if ( ! self::is_supported( $name ) ) {
			return array();
		}

		$fields = array();
		foreach ( self::get_specs()[ $name ] as $key => $spec ) {
			if ( $spec['multiple'] ) {
				for ( $i = 0; $i < 1000; $i++ ) {
					if ( null === self::locate_field( $block, "{$key}:{$i}" ) ) {
						break;
					}
					$fields[] = "{$key}:{$i}";
				}
			} elseif ( null !== self::locate_field( $block, $key ) ) {
				$fields[] = $key;
			}
		}
		return $fields;
	}

	/**
	 * Returns the inner HTML of a field.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $block Parsed block.
	 * @param string $field Field name.
	 * @return string|null
	 */
	public static function get_field_html( array $block, string $field ) {
		$location = self::locate_field( $block, $field );
		if ( null === $location ) {
			return null;
		}
		list( $part, $start, $end ) = $location;
		return substr( $block['innerContent'][ $part ], $start, $end - $start );
	}

	/**
	 * Replaces the inner HTML of a field, keeping the block wrapper and attributes.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $block Parsed block.
	 * @param string $field Field name.
	 * @param string $html  New (sanitized) inner HTML.
	 * @return array|null The modified block, null if the field was not found.
	 */
	public static function set_field_html( array $block, string $field, string $html ) {
		$location = self::locate_field( $block, $field );
		if ( null === $location ) {
			return null;
		}
		list( $part, $start, $end ) = $location;

		$block['innerContent'][ $part ] = substr_replace( $block['innerContent'][ $part ], $html, $start, $end - $start );
		$block['innerHTML']             = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

		return $block;
	}

	/**
	 * Returns the optimistic concurrency hash of a block.
	 *
	 * @since 4.1.0
	 *
	 * @param array $block Parsed block.
	 * @return string
	 */
	public static function get_hash( array $block ): string {
		return md5( (string) ( $block['blockName'] ?? '' ) . '|' . (string) ( $block['innerHTML'] ?? '' ) );
	}

	/**
	 * Converts a path string to a list of indexes.
	 *
	 * @since 4.1.0
	 *
	 * @param string $path Block path, e.g. `0.2.1`.
	 * @return int[]|null
	 */
	public static function parse_path( string $path ) {
		if ( ! preg_match( '/^\d{1,5}(?:\.\d{1,5}){0,30}$/', $path ) ) {
			return null;
		}
		return array_map( 'intval', explode( '.', $path ) );
	}

	/**
	 * Returns a block from a parsed blocks tree.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $path   Block path.
	 * @return array|null
	 */
	public static function get_block( array $blocks, string $path ) {
		$indexes = self::parse_path( $path );
		if ( null === $indexes ) {
			return null;
		}

		$block = null;
		foreach ( $indexes as $index ) {
			if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
				return null;
			}
			$block  = $blocks[ $index ];
			$blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		}
		return $block;
	}

	/**
	 * Replaces a block in a parsed blocks tree.
	 *
	 * @since 4.1.0
	 *
	 * @param array $blocks  Parsed blocks.
	 * @param int[] $indexes Block path as a list of indexes.
	 * @param array $block   The new block.
	 * @return array The modified blocks tree.
	 */
	public static function replace_block( array $blocks, array $indexes, array $block ): array {
		$index = array_shift( $indexes );
		if ( empty( $indexes ) ) {
			$blocks[ $index ] = $block;
		} else {
			$blocks[ $index ]['innerBlocks'] = self::replace_block( $blocks[ $index ]['innerBlocks'], $indexes, $block );
		}
		return $blocks;
	}

	/**
	 * Converts a path to the path of the same block in the block editor,
	 * which ignores the top level whitespace only freeform blocks.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $path   Block path.
	 * @return string Empty string on failure.
	 */
	public static function get_editor_path( array $blocks, string $path ): string {
		$indexes = self::parse_path( $path );
		if ( null === $indexes || ! isset( $blocks[ $indexes[0] ] ) ) {
			return '';
		}

		$top = 0;
		for ( $i = 0; $i < $indexes[0]; $i++ ) {
			if ( null !== $blocks[ $i ]['blockName'] || '' !== trim( (string) $blocks[ $i ]['innerHTML'] ) ) {
				++$top;
			}
		}
		$indexes[0] = $top;
		return implode( '.', $indexes );
	}

	/**
	 * Tells whether an HTML fragment contains only the allowed inline formatting.
	 *
	 * @since 4.1.0
	 *
	 * @param string $html HTML fragment.
	 * @return bool
	 */
	public static function is_inline_html( string $html ): bool {
		if ( false !== strpos( $html, '<!--' ) ) {
			return false;
		}

		$allowed   = self::get_allowed_html();
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = strtolower( (string) $processor->get_tag() );
			if ( ! isset( $allowed[ $tag ] ) ) {
				return false;
			}
			if ( $processor->is_tag_closer() ) {
				continue;
			}
			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $attribute ) {
				if ( empty( $allowed[ $tag ][ strtolower( $attribute ) ] ) ) {
					return false;
				}
				$value = $processor->get_attribute( $attribute );
				if ( is_string( $value ) && in_array( strtolower( $attribute ), array( 'href', 'src', 'cite' ), true ) && wp_kses_bad_protocol( $value, wp_allowed_protocols() ) !== $value ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Sanitizes an edited text.
	 *
	 * @since 4.1.0
	 *
	 * @param string $html Edited HTML.
	 * @param bool   $pre  Whether the text is preformatted.
	 * @return string
	 */
	public static function sanitize_inline_html( string $html, bool $pre = false ): string {
		$html = str_replace( array( "\r\n", "\r" ), "\n", $html );

		// HTML comments are kept by kses but could inject block delimiters into the post content.
		$html = (string) preg_replace( '/<!--.*?(?:-->|$)/s', '', $html );
		$html = wp_kses( $html, self::get_allowed_html() );
		$html = str_replace( '<!--', '&lt;!--', $html );

		return $pre ? $html : trim( $html );
	}
}
