<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * ACF (and Secure Custom Fields) fields in the front-end editor.
 *
 * Pages built with custom fields (e.g. flexible content "blocks" rendered by the theme) don't go through
 * `the_content`, and their values are escaped by the theme, so they can't be marked in the html.
 * In edit mode, the text fields formatted while rendering the page are recorded and sent to the browser,
 * which finds their text in the page and marks it. The fields are edited through the REST API and saved
 * into the translated post's own field values.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_ACF {
	/**
	 * Path prefix of the custom fields in the front-end editor keys (`{post}:acf:{meta name}`).
	 *
	 * @var string
	 */
	const PATH_PREFIX = 'acf:';

	/**
	 * Pattern of a field meta name (e.g. `blocks_0_title` for a sub field of a flexible content).
	 *
	 * @var string
	 */
	const NAME_PATTERN = '[A-Za-z0-9][A-Za-z0-9_-]{0,190}';

	/**
	 * Editable field types.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'text', 'textarea', 'wysiwyg' );

	/**
	 * Maximum number of recorded fields per page.
	 *
	 * @var int
	 */
	const MAX_FIELDS = 500;

	/**
	 * Strope Translate's model.
	 *
	 * @var Strope_Model
	 */
	private $model;

	/**
	 * Recorded fields, keys as keys.
	 *
	 * @var array[]
	 */
	private $fields = array();

	/**
	 * Whether the current user can edit a post, by post ID.
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
	public function __construct( Strope_Model $model ) {
		$this->model = $model;
	}

	/**
	 * Tells whether ACF (or a compatible plugin) is active.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'acf_get_field' ) && function_exists( 'acf_update_value' ) && function_exists( 'acf_format_value' );
	}

	/**
	 * Starts recording the fields formatted while rendering the page.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function start() {
		add_filter( 'acf/format_value', array( $this, 'record' ), PHP_INT_MAX, 3 );
		add_action( 'wp_footer', array( $this, 'print_data' ), 1000 );
	}

	/**
	 * Records an editable field formatted for the page. The value is returned unchanged.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $value   Formatted value.
	 * @param mixed $post_id Post ID as passed to ACF (may be 'option', 'term_12'...).
	 * @param array $field   Field.
	 * @return mixed
	 */
	public function record( $value, $post_id, $field ) {
		if ( count( $this->fields ) >= self::MAX_FIELDS || ! is_string( $value ) || '' === trim( $value ) || ! is_numeric( $post_id ) || ! is_array( $field ) ) {
			return $value;
		}

		$post_id = (int) $post_id;
		$name    = isset( $field['name'] ) ? (string) $field['name'] : '';
		$type    = isset( $field['type'] ) ? (string) $field['type'] : '';

		if ( ! in_array( $type, self::TYPES, true ) || ! preg_match( '/^' . self::NAME_PATTERN . '$/', $name ) || ! $this->can_edit( $post_id ) ) {
			return $value;
		}

		$key = $post_id . ':' . self::PATH_PREFIX . $name;

		if ( ! isset( $this->fields[ $key ] ) ) {
			$this->fields[ $key ] = array(
				'key'  => $key,
				'type' => $type,
				'text' => self::normalize_text( $value ),
				'html' => 'text' !== $type,
			);
		}

		return $value;
	}

	/**
	 * Tells whether the current user can edit a post from the front end.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_edit( int $post_id ): bool {
		if ( ! isset( $this->can_edit[ $post_id ] ) ) {
			$post = get_post( $post_id );

			$this->can_edit[ $post_id ] = $post instanceof WP_Post
				&& $this->model->is_translated_post_type( $post->post_type )
				&& current_user_can( 'edit_post', $post_id );
		}

		return $this->can_edit[ $post_id ];
	}

	/**
	 * Returns the text of a formatted value, as the browser sees it (tags removed, entities decoded, white spaces collapsed).
	 *
	 * @since 4.1.0
	 *
	 * @param string $value Formatted value.
	 * @return string
	 */
	public static function normalize_text( string $value ): string {
		$text = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Prints the recorded fields for the front-end editor script.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function print_data() {
		printf(
			'<script type="application/json" id="strope-fe-acf-data">%s</script>' . "\n",
			wp_json_encode( array_values( $this->fields ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	}

	/**
	 * Tells whether a front-end editor path is a custom field path.
	 *
	 * @since 4.1.0
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_path( string $path ): bool {
		return 0 === strpos( $path, self::PATH_PREFIX );
	}

	/**
	 * Returns an editable field of a post, from its meta name.
	 * The field is found from the field key reference stored by ACF next to the value (`_{$name}`),
	 * which ensures that the meta is an ACF field value.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param string $path    Front-end editor path (`acf:{meta name}`).
	 * @return array|null Field, with its full meta name, null if not found or not editable.
	 */
	public static function get_field( int $post_id, string $path ): ?array {
		if ( ! self::is_active() || ! self::is_path( $path ) ) {
			return null;
		}

		$name = substr( $path, strlen( self::PATH_PREFIX ) );

		if ( ! preg_match( '/^' . self::NAME_PATTERN . '$/', $name ) ) {
			return null;
		}

		$key = get_post_meta( $post_id, '_' . $name, true );

		if ( ! is_string( $key ) || ! preg_match( '/^field_[A-Za-z0-9_]+$/', $key ) ) {
			return null;
		}

		$field = acf_get_field( $key );

		if ( ! is_array( $field ) || empty( $field['type'] ) || ! in_array( $field['type'], self::TYPES, true ) ) {
			return null;
		}

		$field['name'] = $name; // Full meta name of a sub field (e.g. `blocks_0_title`).

		return $field;
	}

	/**
	 * Returns the raw value of a field.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $post_id Post ID.
	 * @param array $field   Field, with its full meta name.
	 * @return string
	 */
	public static function get_value( int $post_id, array $field ): string {
		$value = get_post_meta( $post_id, $field['name'], true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Returns the html of a value as displayed on the page (sanitized).
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $field   Field, with its full meta name.
	 * @param string $value   Raw value.
	 * @return string
	 */
	public static function render( int $post_id, array $field, string $value ): string {
		if ( 'text' === $field['type'] ) {
			return esc_html( $value );
		}

		$formatted = acf_format_value( $value, $post_id, $field );
		return wp_kses_post( is_string( $formatted ) ? $formatted : $value );
	}

	/**
	 * Returns the label of a field, prefixed with the label of its flexible content layout or repeater row if any.
	 *
	 * @since 4.1.0
	 *
	 * @param array $field Field.
	 * @return string
	 */
	public static function get_label( array $field ): string {
		$label = ! empty( $field['label'] ) ? (string) $field['label'] : (string) $field['name'];

		if ( empty( $field['parent'] ) || ! is_string( $field['parent'] ) || 0 !== strpos( $field['parent'], 'field_' ) ) {
			return $label;
		}

		$parent = acf_get_field( $field['parent'] );

		if ( ! is_array( $parent ) ) {
			return $label;
		}

		// Flexible content: the label of the layout.
		if ( ! empty( $field['parent_layout'] ) && ! empty( $parent['layouts'] ) && is_array( $parent['layouts'] ) ) {
			foreach ( $parent['layouts'] as $key => $layout ) {
				if ( is_array( $layout ) && ( $key === $field['parent_layout'] || ( isset( $layout['key'] ) && $layout['key'] === $field['parent_layout'] ) ) && ! empty( $layout['label'] ) ) {
					return $layout['label'] . ' › ' . $label;
				}
			}
		}

		return ! empty( $parent['label'] ) ? $parent['label'] . ' › ' . $label : $label;
	}

	/**
	 * Sanitizes an edited value before saving it, as the post editor would for the current user.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $field Field.
	 * @param string $value Edited value.
	 * @return string
	 */
	public static function sanitize( array $field, string $value ): string {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		if ( 'text' === $field['type'] ) {
			$value = trim( str_replace( "\n", ' ', $value ) );
		}

		/*
		 * Markup is filtered as in the post editor. A value without markup is kept as is, as kses would
		 * encode its ampersands, which themes escaping the value would then display.
		 */
		if ( false !== strpos( $value, '<' ) && ! current_user_can( 'unfiltered_html' ) ) {
			$value = wp_kses_post( $value );
		}

		return $value;
	}

	/**
	 * Saves the value of a field, through ACF so that its update filters run.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $field   Field, with its full meta name.
	 * @param string $value   Sanitized value.
	 * @return string The stored value.
	 */
	public static function save( int $post_id, array $field, string $value ): string {
		acf_update_value( $value, $post_id, $field );

		if ( function_exists( 'acf_flush_value_cache' ) ) {
			acf_flush_value_cache( $post_id, $field['name'] );
		}
		clean_post_cache( $post_id );

		return self::get_value( $post_id, $field );
	}
}
