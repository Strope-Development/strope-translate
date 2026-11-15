<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allows to decode and encode data in a given format.
 *
 * A format can be a single encoding (`serialize`, `json`, `base64`, `urlencode`) or a comma separated chain
 * of encodings, listed in the order they were applied when encoding the data. For example `json,urlencode`
 * means that the data was JSON encoded, then URL encoded: decoding URL decodes, then JSON decodes.
 * Some configuration files list the encodings in the decoding order instead: when decoding fails with the
 * reverse order, the listed order is tried, and the order that worked is used to encode the data again.
 *
 * @since 3.6
 * @since 4.1.0 Supports `base64`, `urlencode` and chains of encodings.
 *
 * @phpstan-type Formats ''|'serialize'|'json'|'base64'|'urlencode'|non-empty-string
 */
class Strope_Data_Encoding {
	/**
	 * Supported encodings.
	 *
	 * @var string[]
	 */
	const ENCODINGS = array( 'serialize', 'json', 'base64', 'urlencode' );

	/**
	 * Encoding format, as given.
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Encodings to apply, in the encoding order. Empty if the format contains an unsupported encoding.
	 *
	 * @var string[]
	 */
	private $chain;

	/**
	 * Encodings in the order used to decode the last decoded data, used to encode the data again.
	 *
	 * @var string[]
	 */
	private $decoding_order;

	/**
	 * Constructor.
	 *
	 * @since 3.6
	 * @since 4.1.0 Accepts `base64`, `urlencode` and comma separated chains.
	 *
	 * @param string $format Encoding format. Possible values are:
	 *    - An empty string: to (un)serialize.
	 *    - `serialize`, `json`, `base64`, `urlencode`.
	 *    - A comma separated list of these encodings, in the encoding order (e.g. `json,urlencode`).
	 *    Default is an empty string.
	 */
	public function __construct( string $format = '' ) {
		$format       = strtolower( trim( $format ) );
		$this->format = '' !== $format ? $format : 'serialize';

		$chain       = array_map( 'trim', explode( ',', $this->format ) );
		$this->chain = array_diff( $chain, self::ENCODINGS ) ? array() : array_values( array_filter( $chain ) );

		$this->decoding_order = array_reverse( $this->chain );
	}

	/**
	 * Tells if the current format is `serialize`, which is what WP uses by default for the metas.
	 *
	 * @since 3.6
	 *
	 * @return bool
	 */
	public function use_serialize(): bool {
		return array( 'serialize' ) === $this->chain;
	}

	/**
	 * Decodes the given data.
	 * Returns a `WP_Error` object upon decoding failure.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data.
	 * @return mixed|WP_Error Decoded data. A `WP_Error` object upon decoding failure.
	 */
	public function decode( $data ) {
		if ( empty( $this->chain ) ) {
			return new WP_Error( 'strope-decode-unknown-format', 'Unknown format.' );
		}

		$orders = array( array_reverse( $this->chain ) );
		if ( count( $this->chain ) > 1 ) {
			$orders[] = $this->chain;
		}

		$error = null;
		foreach ( $orders as $order ) {
			$value = $this->apply( $data, $order, 'decode' );

			if ( ! is_wp_error( $value ) ) {
				$this->decoding_order = $order;
				return $value;
			}

			$error = $error ?? $value;
		}

		return $error;
	}

	/**
	 * Decodes the given data.
	 * The data is passed by reference and the method returns a `WP_Error` object upon decoding failure.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data, passed by reference.
	 * @return WP_Error
	 */
	public function decode_reference( &$data ): WP_Error {
		$value = $this->decode( $data );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$data = $value;
		return new WP_Error();
	}

	/**
	 * Encodes the given data.
	 * Returns a `WP_Error` object upon encoding failure.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data.
	 * @return mixed Decoded data. A `WP_Error` object upon encoding failure.
	 */
	public function encode( $data ) {
		if ( empty( $this->chain ) ) {
			return new WP_Error( 'strope-encode-unknown-format', 'Unknown format.' );
		}

		// Mirror the order used to decode.
		return $this->apply( $data, array_reverse( $this->decoding_order ), 'encode' );
	}

	/**
	 * Encodes the given data.
	 * The data is passed by reference and the method returns a `WP_Error` object upon encoding failure.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data, passed by reference.
	 * @return WP_Error
	 */
	public function encode_reference( &$data ): WP_Error {
		$value = $this->encode( $data );

		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$data = $value;
		return new WP_Error();
	}

	/**
	 * Applies a list of encodings to the data.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed    $data      Data.
	 * @param string[] $encodings Encodings, in the order to apply them.
	 * @param string   $direction 'decode' or 'encode'.
	 * @return mixed|WP_Error Data, a `WP_Error` object upon failure.
	 */
	private function apply( $data, array $encodings, string $direction ) {
		foreach ( $encodings as $encoding ) {
			$method = "{$direction}_{$encoding}";
			$data   = $this->$method( $data );

			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}

		return $data;
	}

	/**
	 * Unserializes data, without allowing objects.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private function decode_serialize( $data ) {
		return strope_maybe_unserialize( $data );
	}

	/**
	 * Serializes data if needed.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	private function encode_serialize( $data ) {
		return maybe_serialize( $data );
	}

	/**
	 * Decodes the given JSON data.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data.
	 * @return mixed|WP_Error Decoded data. A `WP_Error` object upon decoding failure.
	 */
	private function decode_json( $data ) {
		if ( ! is_string( $data ) ) {
			return new WP_Error( 'strope-json-not-a-string', 'Value is not a string.' );
		}

		$decoded = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'strope-json-decoding-error', json_last_error_msg() );
		}

		return $decoded;
	}

	/**
	 * Encodes the given data to JSON.
	 *
	 * @since 3.6
	 *
	 * @param mixed $data Data.
	 * @return string|WP_Error Encoded data. A `WP_Error` object upon decoding failure.
	 */
	private function encode_json( $data ) {
		$encoded = wp_json_encode( $data, JSON_PRESERVE_ZERO_FRACTION ); // Cannot trigger an Exception since we use the default value for `$depth`.

		if ( ! is_string( $encoded ) ) {
			return new WP_Error( 'strope-json-encoding-error', json_last_error_msg() );
		}

		return $encoded;
	}

	/**
	 * Decodes base64 data.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return string|WP_Error Decoded data. A `WP_Error` object upon decoding failure.
	 */
	private function decode_base64( $data ) {
		if ( ! is_string( $data ) ) {
			return new WP_Error( 'strope-base64-not-a-string', 'Value is not a string.' );
		}

		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $decoded ) {
			return new WP_Error( 'strope-base64-decoding-error', 'Invalid base64 data.' );
		}

		return $decoded;
	}

	/**
	 * Encodes data to base64.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return string|WP_Error Encoded data. A `WP_Error` object upon encoding failure.
	 */
	private function encode_base64( $data ) {
		if ( ! is_scalar( $data ) ) {
			return new WP_Error( 'strope-base64-not-a-string', 'Value is not a string.' );
		}

		return base64_encode( (string) $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * URL decodes data.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return string|WP_Error Decoded data. A `WP_Error` object upon decoding failure.
	 */
	private function decode_urlencode( $data ) {
		if ( ! is_string( $data ) ) {
			return new WP_Error( 'strope-urlencode-not-a-string', 'Value is not a string.' );
		}

		return rawurldecode( $data );
	}

	/**
	 * URL encodes data.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $data Data.
	 * @return string|WP_Error Encoded data. A `WP_Error` object upon encoding failure.
	 */
	private function encode_urlencode( $data ) {
		if ( ! is_scalar( $data ) ) {
			return new WP_Error( 'strope-urlencode-not-a-string', 'Value is not a string.' );
		}

		return rawurlencode( (string) $data );
	}
}
