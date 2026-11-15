<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTML tag scanner exposing the byte offsets of the current tag.
 *
 * Used by the front-end editor to locate the inner HTML of an element
 * in the stored markup of a block.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_Tag_Scanner extends WP_HTML_Tag_Processor {

	/**
	 * Returns the byte offset and length of the tag the scanner is currently on.
	 *
	 * @since 4.1.0
	 *
	 * @return int[]|null Array with the start offset and the length of the tag, null on failure.
	 */
	public function get_current_span() {
		if ( ! $this->set_bookmark( 'strope_fe_span' ) || ! isset( $this->bookmarks['strope_fe_span'] ) ) {
			return null;
		}

		$span = $this->bookmarks['strope_fe_span'];
		$this->release_bookmark( 'strope_fe_span' );

		return array( (int) $span->start, (int) $span->length );
	}
}
