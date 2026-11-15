<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints of the machine translation, namespace `strope/v1`. The browser workers use the routes of
 * the job queue, under `strope-mt-worker/v1` (see Strope_Machine_Translation::kit()).
 *
 * - GET  /mt/status          Language pairs, recent runs, waiting work.
 * - POST /mt/directions      Installs (one file per request) or removes the models of a language pair.
 * - GET  /mt/manifest        Engine and model files for the browser workers.
 * - POST /mt/runs            Starts a run.
 * - GET  /mt/runs/{id}       Jobs of a run.
 * - POST /mt/runs/{id}       Pauses, resumes, cancels a run or retries its failed jobs.
 * - POST /mt/strings/prepare Prepares strings of the Translations page for the engine.
 * - POST /mt/strings/restore Checks and restores their translations (the page fills the fields).
 * - POST /mt/preferences     Saves the background preference of the current user.
 *
 * All endpoints require the `manage_translations` capability, the models need `manage_languages`.
 *
 * @since 4.2.0
 */
class Strope_MT_REST {
	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'strope/v1';

	/**
	 * Registers the routes.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function register_routes() {
		$translate = array( self::class, 'can_translate' );
		$manage    = array( self::class, 'can_manage_languages' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_status' ),
				'permission_callback' => $translate,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/directions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'update_direction' ),
				'permission_callback' => $manage,
				'args'                => array(
					'direction' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^(?:zh-Han[st]|[a-z]{2,3})-(?:zh-Han[st]|[a-z]{2,3})$',
					),
					'action'    => array(
						'type'     => 'string',
						'enum'     => array( 'install', 'remove' ),
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_manifest' ),
				'permission_callback' => $translate,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/runs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_run' ),
				'permission_callback' => $translate,
				'args'                => array(
					'posts'       => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
						'maxItems' => 5000,
					),
					'languages'   => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
					'post_status' => array(
						'type' => 'string',
						'enum' => array( 'draft', 'publish' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/runs/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_run' ),
					'permission_callback' => $translate,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'control_run' ),
					'permission_callback' => $translate,
					'args'                => array(
						'action' => array(
							'type'     => 'string',
							'enum'     => array( 'pause', 'resume', 'cancel', 'retry' ),
							'required' => true,
						),
					),
				),
			)
		);

		$string_items = array(
			'type'     => 'array',
			'required' => true,
			'maxItems' => Strope_MT_Strings::MAX_ITEMS,
			'items'    => array( 'type' => 'object' ),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/strings/prepare',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'prepare_strings' ),
				'permission_callback' => $translate,
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'items'  => $string_items,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/strings/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'restore_strings' ),
				'permission_callback' => $translate,
				'args'                => array(
					'source' => array(
						'type'     => 'string',
						'required' => true,
					),
					'items'  => $string_items,
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mt/preferences',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_preferences' ),
				'permission_callback' => $translate,
				'args'                => array(
					'background' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission to translate.
	 *
	 * @since 4.2.0
	 *
	 * @return bool
	 */
	public static function can_translate(): bool {
		return current_user_can( Strope_Capabilities::MANAGE_TRANSLATIONS );
	}

	/**
	 * Permission to manage the models.
	 *
	 * @since 4.2.0
	 *
	 * @return bool
	 */
	public static function can_manage_languages(): bool {
		return current_user_can( Strope_Capabilities::MANAGE_LANGUAGES );
	}

	/**
	 * Returns the language pairs, the recent runs and the waiting work.
	 *
	 * @since 4.2.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_status() {
		return rest_ensure_response(
			array(
				'directions' => Strope_MT_Engine_Files::get_site_directions(),
				'runs'       => Strope_MT_Queue::get_runs(),
				'waiting'    => Strope_MT_Queue::count_waiting(),
			)
		);
	}

	/**
	 * Installs or removes the models of a language pair.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_direction( WP_REST_Request $request ) {
		$direction = (string) $request['direction'];

		if ( ! isset( Strope_MT_Engine_Files::get_site_directions()[ $direction ] ) ) {
			return new WP_Error( 'strope_mt_unknown_direction', __( 'This language pair is not used by the site.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( 'remove' === $request['action'] ) {
			Strope_MT_Engine_Files::uninstall( $direction );
			return rest_ensure_response( array( 'complete' => true ) );
		}

		$result = Strope_MT_Engine_Files::install_step( $direction );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Returns the engine and model files.
	 *
	 * @since 4.2.0
	 *
	 * @return WP_REST_Response
	 */
	public static function get_manifest() {
		return rest_ensure_response( Strope_MT_Engine_Files::get_worker_manifest() );
	}

	/**
	 * Starts a run.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_run( WP_REST_Request $request ) {
		$status = $request['post_status'] ? (string) $request['post_status'] : Strope_Machine_Translation::get_options()['post_status'];
		$result = Strope_MT_Queue::create_run( (array) $request['posts'], (array) $request['languages'], array( 'post_status' => $status ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Returns the jobs of a run.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_run( WP_REST_Request $request ) {
		$run = Strope_MT_Store::get_run( (int) $request['id'] );

		if ( ! $run || ! Strope_MT_Queue::user_can_manage_run( $run ) ) {
			return new WP_Error( 'strope_mt_not_found', __( 'Not found.', 'strope-translate' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'jobs' => Strope_MT_Queue::get_run_jobs( $run ) ) );
	}

	/**
	 * Changes the state of a run.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function control_run( WP_REST_Request $request ) {
		$result = Strope_MT_Queue::control_run( (int) $request['id'], (string) $request['action'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Prepares strings of the Translations page for the engine.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function prepare_strings( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => Strope_MT_Strings::prepare( (string) $request['source'], (array) $request['items'] ) ) );
	}

	/**
	 * Checks and restores the engine output of strings.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function restore_strings( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'items' => Strope_MT_Strings::restore( (string) $request['source'], (array) $request['items'] ) ) );
	}

	/**
	 * Saves the background preference.
	 *
	 * @since 4.2.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function save_preferences( WP_REST_Request $request ) {
		update_user_meta( get_current_user_id(), Strope_Machine_Translation::USER_BACKGROUND, $request['background'] ? '1' : '0' );

		return rest_ensure_response( array( 'background' => (bool) $request['background'] ) );
	}
}
