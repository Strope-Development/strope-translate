<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * REST endpoints of the theme and plugin strings discovery, namespace `strope/v1`.
 *
 * - GET  /string-discovery/scan         Status of the scan and scanned components.
 * - POST /string-discovery/scan         Starts (`action=start`) or continues (`action=step`) a scan.
 * - GET  /string-discovery/strings      Lists the strings with their translations.
 * - POST /string-discovery/translations Saves translations.
 *
 * All endpoints require the `manage_translations` capability.
 *
 * @since 4.1.0
 */
class Strope_String_Discovery_REST {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'strope/v1';

	/**
	 * Registers the routes.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/string-discovery/scan',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_scan' ),
					'permission_callback' => array( self::class, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'scan' ),
					'permission_callback' => array( self::class, 'scan_permission' ),
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'start', 'step' ),
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/string-discovery/strings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_strings' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'component' => array(
						'type'    => 'string',
						'default' => '',
					),
					'domain'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'search'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'    => array(
						'type'    => 'string',
						'enum'    => array( '', 'translated', 'untranslated' ),
						'default' => '',
					),
					'page'      => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/string-discovery/translations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_translations' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'translations' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 500,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'string_id'   => array(
									'type'     => 'integer',
									'required' => true,
								),
								'language'    => array(
									'type'     => 'string',
									'required' => true,
								),
								'translation' => array(
									'type'     => array( 'string', 'array' ),
									'items'    => array( 'type' => 'string' ),
									'required' => true,
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @since 4.1.0
	 *
	 * @return bool|WP_Error
	 */
	public static function permission() {
		if ( current_user_can( 'manage_translations' ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to translate strings.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Permission callback of the scan: starting (or restarting) a scan requires `manage_languages`,
	 * continuing it requires `manage_translations`.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function scan_permission( WP_REST_Request $request ) {
		$cap = 'start' === $request['action'] ? 'manage_languages' : 'manage_translations';

		if ( current_user_can( $cap ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to scan the themes and plugins.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Returns the scan status.
	 *
	 * @since 4.1.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_scan() {
		return rest_ensure_response( self::scan_response( Strope_String_Scanner::get_progress( Strope_String_Scanner::get_job() ) ) );
	}

	/**
	 * Starts or continues a scan.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function scan( WP_REST_Request $request ) {
		$progress = 'start' === $request['action'] ? Strope_String_Scanner::start() : Strope_String_Scanner::step();

		if ( is_wp_error( $progress ) ) {
			return $progress;
		}

		return rest_ensure_response( self::scan_response( $progress ) );
	}

	/**
	 * Builds the scan response.
	 *
	 * @since 4.1.0
	 *
	 * @param array $progress Progress.
	 * @return array
	 */
	protected static function scan_response( array $progress ): array {
		$counts     = Strope_String_Discovery_Store::count_by_component();
		$components = array();

		foreach ( Strope_String_Scanner::get_components() as $id => $component ) {
			$components[] = array(
				'id'        => $id,
				'type'      => $component['type'],
				'name'      => $component['name'],
				'domain'    => $component['domain'],
				'last_scan' => (int) $component['last_scan'],
				'count'     => isset( $counts[ $id ] ) ? $counts[ $id ] : 0,
			);
		}

		$progress['components'] = $components;
		return $progress;
	}

	/**
	 * Lists the strings.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_strings( WP_REST_Request $request ) {
		$languages = Strope_String_Discovery::get_user_languages();
		$slugs     = wp_list_pluck( $languages, 'slug' );

		$result = Strope_String_Discovery_Store::query_strings(
			array(
				'component'        => (string) $request['component'],
				'domain'           => (string) $request['domain'],
				'search'           => (string) $request['search'],
				'status'           => (string) $request['status'],
				'status_languages' => $slugs,
				'page'             => (int) $request['page'],
				'per_page'         => (int) $request['per_page'],
			)
		);

		$translations = Strope_String_Discovery_Store::get_translations( wp_list_pluck( $result['items'], 'id' ), $slugs );
		$items        = array();

		foreach ( $result['items'] as $item ) {
			$items[] = array(
				'id'           => (int) $item->id,
				'domain'       => $item->domain,
				'context'      => $item->context,
				'singular'     => $item->singular,
				'plural'       => $item->plural,
				'component'    => $item->component,
				'sources'      => '' === $item->sources ? array() : explode( "\n", $item->sources ),
				'translations' => isset( $translations[ (int) $item->id ] ) ? $translations[ (int) $item->id ] : new stdClass(),
			);
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) );

		return $response;
	}

	/**
	 * Saves translations.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_translations( WP_REST_Request $request ) {
		$languages = array();
		foreach ( Strope_String_Discovery::get_user_languages() as $language ) {
			$languages[ $language->slug ] = $language;
		}

		// Validates everything first: nothing is saved if one item is invalid.
		$items = array();
		foreach ( array_values( (array) $request['translations'] ) as $i => $item ) {
			$slug = (string) $item['language'];

			if ( ! isset( $languages[ $slug ] ) ) {
				return new WP_Error( 'strope_forbidden_language', __( 'Sorry, you are not allowed to translate strings in this language.', 'strope-translate' ), array( 'status' => 403, 'index' => $i ) );
			}

			$string = Strope_String_Discovery_Store::get_string( (int) $item['string_id'] );
			if ( ! $string ) {
				return new WP_Error( 'strope_invalid_string', __( 'Invalid string.', 'strope-translate' ), array( 'status' => 404, 'index' => $i ) );
			}

			$forms = Strope_String_Discovery::prepare_translation( $item['translation'], $string );
			if ( is_wp_error( $forms ) ) {
				$forms->add_data( array_merge( (array) $forms->get_error_data(), array( 'index' => $i ) ) );
				return $forms;
			}

			$items[] = array( (int) $string->id, $slug, $forms );
		}

		$saved = 0;
		$dirty = array();

		try {
			foreach ( $items as $item ) {
				list( $string_id, $slug, $forms ) = $item;

				if ( ! Strope_String_Discovery_Store::set_translation( $string_id, $slug, $forms ) ) {
					return new WP_Error( 'strope_db_error', __( 'The string could not be saved.', 'strope-translate' ), array( 'status' => 500, 'saved' => $saved ) );
				}

				++$saved;
				$dirty[ $slug ] = $languages[ $slug ];
			}
		} finally {
			// Always rebuild the cache of the languages touched, even after an error.
			foreach ( $dirty as $language ) {
				Strope_String_Discovery_Store::rebuild_cache( $language );
			}
		}

		return rest_ensure_response( array( 'saved' => $saved ) );
	}
}
