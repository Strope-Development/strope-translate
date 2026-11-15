<?php
/**
 * Bootstrap of the unit tests: the classes under test only need a few WordPress functions.
 *
 * @package Strope-Translate
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for the WordPress error class.
	 */
	class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		protected $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		protected $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		/**
		 * Returns the error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

/**
 * Stand-in for the WordPress function.
 *
 * @param mixed $thing Value to check.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Stand-in for the WordPress function.
 *
 * @param mixed $data  Value to encode.
 * @param int   $flags Flags.
 * @return string|false
 */
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}

/**
 * Stand-in for the WordPress function, for lists of arrays.
 *
 * @param array  $list  List of arrays.
 * @param string $field Field to pluck.
 * @return array
 */
function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( $list as $key => $item ) {
		$out[ $key ] = is_object( $item ) ? $item->$field : $item[ $field ];
	}
	return $out;
}

/**
 * Stand-in for the WordPress function, for lists of arrays and the AND operator.
 *
 * @param array $list List of arrays.
 * @param array $args Key and value pairs to match.
 * @return array
 */
function wp_list_filter( $list, $args = array() ) {
	return array_filter(
		$list,
		function ( $item ) use ( $args ) {
			$item = (array) $item;
			foreach ( $args as $key => $value ) {
				if ( ! array_key_exists( $key, $item ) || $value != $item[ $key ] ) { // phpcs:ignore Universal.Operators.StrictComparisons -- As WordPress does.
					return false;
				}
			}
			return true;
		}
	);
}

/**
 * Stand-in for the WordPress function.
 *
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $text );
	return trim( strip_tags( (string) $text ) );
}

$GLOBALS['shortcode_tags'] = array();

require dirname( __DIR__ ) . '/services/word-count/word-count.php';
require dirname( __DIR__ ) . '/modules/machine-translation/mt-segmenter.php';
