<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST API of the translation status:
 * - field `strope_translation_status` on tracked post types (edit context only);
 * - `GET strope/v1/translation-status/<id>`: status of a post and its translations;
 * - `POST strope/v1/translation-status/<id>` with `action` = mark_up_to_date | mark_reviewed | mark_outdated;
 * - `GET strope/v1/translation-status/summary?post_type=<type>`: counts per language (requires `edit_others_posts`,
 *   as the counts include other authors' drafts and private posts).
 *
 * @since 4.1.0
 */
class Strope_Translation_Status_REST {
	const REST_NAMESPACE = 'strope/v1';

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register' ), 20 );
	}

	/**
	 * Registers the field and routes.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register() {
		foreach ( Strope_Translation_Status::get_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				'strope_translation_status',
				array(
					'get_callback' => array( $this, 'get_field' ),
					'schema'       => array(
						'description' => __( 'Translation status of the post in each language.', 'strope-translate' ),
						'type'        => array( 'object', 'null' ),
						'context'     => array( 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/translation-status/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'id'     => array(
							'type'     => 'integer',
							'required' => true,
						),
						'action' => array(
							'description' => __( 'Action to perform on the post.', 'strope-translate' ),
							'type'        => 'string',
							'enum'        => array( 'mark_up_to_date', 'mark_reviewed', 'mark_outdated' ),
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/translation-status/summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'summary_permissions_check' ),
				'args'                => array(
					'post_type' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => Strope_Translation_Status::get_post_types(),
					),
				),
			)
		);
	}

	/**
	 * Returns the status of a post, completed with links and capabilities for the current user.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function prepare_status( $post_id ) {
		$status = Strope_Translation_Status::get_status( $post_id );

		if ( null === $status ) {
			return null;
		}

		$model     = Strope()->model;
		$post_type = get_post_type_object( (string) get_post_type( $post_id ) );
		$can_add   = $post_type && current_user_can( $post_type->cap->create_posts ) && 'auto-draft' !== get_post_status( $post_id );

		foreach ( $status as $slug => $entry ) {
			$language = $model->get_language( $slug );

			$entry['language']  = $language ? $language->name : $slug;
			$entry['flag_url']  = $language ? $language->get_display_flag_url() : '';
			$entry['is_current'] = (int) $entry['translation_id'] === (int) $post_id;
			$entry['can_edit']  = $entry['translation_id'] && current_user_can( 'edit_post', $entry['translation_id'] );
			$entry['edit_link'] = $entry['can_edit'] ? (string) get_edit_post_link( $entry['translation_id'], 'raw' ) : '';
			$entry['add_link']  = '';

			if ( ! $entry['translation_id'] && $can_add && $post_type ) {
				$entry['add_link'] = add_query_arg(
					array(
						'post_type' => $post_type->name,
						'from_post' => (int) $post_id,
						'new_lang'  => $slug,
						'_wpnonce'  => wp_create_nonce( 'new-post-translation' ),
					),
					admin_url( 'post-new.php' )
				);
			}

			$status[ $slug ] = $entry;
		}

		return $status;
	}

	/**
	 * Gets the REST field value.
	 *
	 * @since 4.1.0
	 *
	 * @param array $object Post data.
	 * @return array|null
	 */
	public function get_field( $object ) {
		$id = isset( $object['id'] ) ? (int) $object['id'] : 0;

		if ( empty( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}

		return self::prepare_status( $id );
	}

	/**
	 * Checks the permission to read or update the status of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function item_permissions_check( $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( ! Strope_Translation_Status::is_tracked_post( $id, true ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'strope-translate' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Returns the status of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ) {
		$id = (int) $request->get_param( 'id' );

		return rest_ensure_response(
			array(
				'id'     => $id,
				'status' => self::prepare_status( $id ),
			)
		);
	}

	/**
	 * Performs an action on a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id = (int) $request->get_param( 'id' );

		switch ( $request->get_param( 'action' ) ) {
			case 'mark_up_to_date':
				$result = Strope_Translation_Status::mark_up_to_date( $id );
				break;
			case 'mark_reviewed':
				$result = Strope_Translation_Status::mark_reviewed( $id );
				break;
			case 'mark_outdated':
				$result = Strope_Translation_Status::mark_needs_update( $id );
				break;
			default:
				$result = new WP_Error( 'rest_invalid_param', __( 'Invalid action.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( is_wp_error( $result ) ) {
			if ( ! $result->get_error_data() ) {
				$result->add_data( array( 'status' => 400 ) );
			}
			return $result;
		}

		return $this->get_item( $request );
	}

	/**
	 * Checks the permission to read the summary of a post type.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function summary_permissions_check( $request ) {
		$post_type = get_post_type_object( (string) $request->get_param( 'post_type' ) );

		if ( ! $post_type || ! current_user_can( $post_type->cap->edit_posts ) || ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Returns the summary of a post type.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_summary( $request ) {
		$post_type = (string) $request->get_param( 'post_type' );

		return rest_ensure_response( array_merge( array( 'post_type' => $post_type ), Strope_Translation_Status::get_summary( $post_type ) ) );
	}
}
