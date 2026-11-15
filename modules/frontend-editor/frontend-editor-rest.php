<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints of the front-end translation editor.
 *
 * Every edit is saved into the real translated post (a new revision is created)
 * or into the stored strings translations.
 *
 * @since 4.1.0
 */
class Strope_Frontend_Editor_REST {
	const NAMESPACE = 'strope/v1';
	const BASE      = 'frontend-editor';

	/**
	 * Strope Translate's model.
	 *
	 * @var Strope_Model
	 */
	private $model;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Model $model Strope Translate's model.
	 */
	public function __construct( $model ) {
		$this->model = $model;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function register_routes() {
		$block_args = array(
			'post'  => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
			'path'  => array(
				'type'     => 'string',
				'pattern'  => '^(?:\d{1,5}(?:\.\d{1,5}){0,30}|title|excerpt|content|acf:' . Strope_Frontend_Editor_ACF::NAME_PATTERN . ')$',
				'required' => true,
			),
			'field' => array(
				'type'    => 'string',
				'pattern' => '^(?:auto|[a-z]{1,20}(?::\d{1,5})?)$',
				'default' => 'auto',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/block',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'edit_post_permission' ),
					'args'                => array_merge(
						$block_args,
						array(
							'lang' => array(
								'type'    => 'string',
								'pattern' => '^(?:all|[a-z][a-z0-9_-]{0,63})?$',
								'default' => '',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_item' ),
					'permission_callback' => array( $this, 'edit_post_permission' ),
					'args'                => array_merge(
						$block_args,
						array(
							'block'   => array(
								'type'    => 'string',
								'default' => '',
							),
							'hash'    => array(
								'type'     => 'string',
								'pattern'  => '^[a-f0-9]{32}$',
								'required' => true,
							),
							'content' => array(
								'type'     => 'string',
								'required' => true,
							),
						)
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/string',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_string' ),
				'permission_callback' => array( $this, 'manage_translations_permission' ),
				'args'                => array(
					'type'        => array(
						'type'     => 'string',
						'enum'     => array( Strope_Frontend_Editor_Strings::TYPE_REGISTERED, Strope_Frontend_Editor_Strings::TYPE_DISCOVERED ),
						'required' => true,
					),
					'lang'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'domain'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'context'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'singular'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'id'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'translation' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Checks that the current user can edit the requested post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function edit_post_permission( WP_REST_Request $request ) {
		$post = get_post( (int) $request['post'] );

		if ( ! $post instanceof WP_Post || ! $this->model->is_translated_post_type( $post->post_type ) ) {
			return new WP_Error( 'strope_fe_invalid_post', __( 'Invalid post.', 'strope-translate' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'strope_fe_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Checks that the current user can manage the strings translations.
	 *
	 * @since 4.1.0
	 *
	 * @return true|WP_Error
	 */
	public function manage_translations_permission() {
		if ( ! current_user_can( 'manage_translations' ) ) {
			return new WP_Error( 'strope_fe_forbidden', __( 'Sorry, you are not allowed to translate strings.', 'strope-translate' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Returns language data for the client.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language|false $language Language.
	 * @return array|null
	 */
	private function language_data( $language ) {
		if ( ! $language instanceof Strope_Language ) {
			return null;
		}
		return array(
			'slug' => $language->slug,
			'name' => $language->name,
			'code' => $language->w3c,
			'rtl'  => (bool) $language->is_rtl,
		);
	}

	/**
	 * Returns the ID of the source post of a translation.
	 *
	 * Uses the translation status tracker when available, else the translation in the default language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return int Source post ID, 0 if none.
	 */
	public function get_source_post_id( int $post_id ): int {
		$source   = 0;
		$original = false;
		$language = $this->model->post->get_language( $post_id );

		if ( $language && function_exists( 'strope_get_translation_status' ) ) {
			// Translation status tracking: `source_id` is the post the translation is based on.
			$status = strope_get_translation_status( $post_id, $language->slug );
			if ( is_array( $status ) ) {
				$source   = isset( $status['source_id'] ) ? (int) $status['source_id'] : 0;
				$original = ! empty( $status['is_original'] );
			}
		}

		if ( ! $source && ! $original ) {
			$default = $this->model->get_default_language();
			if ( $language && $default && $language->slug !== $default->slug ) {
				$source = (int) $this->model->post->get( $post_id, $default );
			}
		}

		/**
		 * Filters the source post displayed when editing a translation from the front end.
		 *
		 * @since 4.1.0
		 *
		 * @param int $source  Source post ID, 0 if none.
		 * @param int $post_id Translated post ID.
		 */
		$source = (int) apply_filters( 'strope_frontend_editor_source_post', $source, $post_id );

		// The source must be another post of the same translation group.
		if ( $source === $post_id || ! in_array( $source, array_map( 'intval', $this->model->post->get_translations( $post_id ) ), true ) ) {
			return 0;
		}

		return $source;
	}

	/**
	 * Returns the data needed to edit a block, the title, the excerpt or a custom field of a post,
	 * in the language of the post or, with the `lang` parameter, in another language (the same text in a translation),
	 * or an overview of all the languages (`lang=all`).
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$viewed   = get_post( (int) $request['post'] );
		$path     = (string) $request['path'];
		$field    = (string) $request['field'];
		$lang     = (string) $request['lang'];
		$language = $this->model->post->get_language( $viewed->ID );

		if ( 'all' === $lang ) {
			return $this->get_overview( $viewed, $path, $field );
		}

		$post   = $viewed;
		$source = null;

		if ( '' !== $lang && ( ! $language || $lang !== $language->slug ) ) {
			$target = $this->model->get_language( $lang );

			if ( ! $target ) {
				return new WP_Error( 'strope_fe_invalid_language', __( 'Invalid language.', 'strope-translate' ), array( 'status' => 400 ) );
			}

			$translation = $this->get_translation( $viewed, $path, $field, $target );

			if ( 'ok' !== $translation['status'] ) {
				$base = $this->get_item_data( $viewed, $path, $field, null );
				$base = is_wp_error( $base ) ? array() : $base->get_data();

				return rest_ensure_response(
					array(
						'post'        => $viewed->ID,
						'viewed'      => $viewed->ID,
						'path'        => $path,
						'field'       => $field,
						'block'       => isset( $base['block'] ) ? $base['block'] : '',
						'block_title' => isset( $base['block_title'] ) ? $base['block_title'] : '',
						'supported' => false,
						'editable'  => false,
						'language'  => $this->language_data( $target ),
						'message'   => $translation['message'],
						'links'     => $translation['links'],
						'languages' => $this->get_languages_status( $viewed, $path, $field ),
					)
				);
			}

			$post   = $translation['post'];
			$source = $viewed; // The text of the page being edited is the reference.
		}

		$response = $this->get_item_data( $post, $path, $field, $source );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data              = $response->get_data();
		$data['viewed']    = $viewed->ID;
		$data['languages'] = $this->get_languages_status( $viewed, $path, $field );

		if ( $post->ID !== $viewed->ID ) {
			$data['links'] = array( 'open' => $this->get_edit_mode_link( $post, $path ) );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Returns the translation of a post containing the same text as the given path, and tells whether the current user can edit it.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post         $post     Post being viewed.
	 * @param string          $path     Path of the text in the post.
	 * @param string          $field    Field of the block.
	 * @param Strope_Language $language Language of the translation.
	 * @return array {
	 *   @type string       $status  'current' (the post itself), 'ok', 'missing', 'forbidden' or 'mismatch'.
	 *   @type WP_Post|null $post    The translation.
	 *   @type string       $message Message explaining the status.
	 *   @type string[]     $links   Links to open the translation in edit mode ('open') or to create it ('add').
	 * }
	 */
	private function get_translation( WP_Post $post, string $path, string $field, Strope_Language $language ): array {
		$result = array(
			'status'  => 'ok',
			'post'    => null,
			'message' => '',
			'links'   => array(),
		);

		$current = $this->model->post->get_language( $post->ID );
		if ( $current && $current->slug === $language->slug ) {
			$result['status'] = 'current';
			$result['post']   = $post;
			return $result;
		}

		$tr_id = (int) $this->model->post->get( $post->ID, $language );
		$tr    = $tr_id ? get_post( $tr_id ) : null;

		if ( ! $tr instanceof WP_Post ) {
			$result['status']  = 'missing';
			/* translators: %s is a language name. */
			$result['message'] = sprintf( __( 'This page is not translated in %s yet.', 'strope-translate' ), $language->name );
			$add_link          = $this->get_add_translation_link( $post, $language );
			if ( $add_link ) {
				$result['links']['add'] = $add_link;
			}
			return $result;
		}

		$result['post'] = $tr;

		if ( ! current_user_can( 'edit_post', $tr->ID ) ) {
			$result['status']  = 'forbidden';
			/* translators: %s is a language name. */
			$result['message'] = sprintf( __( 'You are not allowed to edit the %s translation of this page.', 'strope-translate' ), $language->name );
			return $result;
		}

		if ( ! $this->same_text( $post, $tr, $path, $field ) ) {
			$result['status']  = 'mismatch';
			/* translators: %s is a language name. */
			$result['message'] = sprintf( __( 'This text was not found at the same place in the %s translation. Open the page to edit it.', 'strope-translate' ), $language->name );
			$result['links']   = array( 'open' => $this->get_edit_mode_link( $tr, '' ) );
		}

		return $result;
	}

	/**
	 * Tells whether a translation contains the same text as the post at the given path:
	 * the same custom field (same field key, so the same layout in a flexible content), or a block of the same type at the same place.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post  Post.
	 * @param WP_Post $tr    Translation.
	 * @param string  $path  Path.
	 * @param string  $field Field of the block.
	 * @return bool
	 */
	private function same_text( WP_Post $post, WP_Post $tr, string $path, string $field ): bool {
		if ( in_array( $path, array( 'title', 'excerpt' ), true ) ) {
			return true;
		}

		if ( Strope_Frontend_Editor_ACF::is_path( $path ) ) {
			$post_field = Strope_Frontend_Editor_ACF::get_field( $post->ID, $path );
			$tr_field   = Strope_Frontend_Editor_ACF::get_field( $tr->ID, $path );
			return null !== $post_field && null !== $tr_field && $post_field['key'] === $tr_field['key'];
		}

		if ( null === Strope_Frontend_Editor_Blocks::parse_path( $path ) ) {
			return false;
		}

		$block    = Strope_Frontend_Editor_Blocks::get_block( parse_blocks( $post->post_content ), $path );
		$tr_block = Strope_Frontend_Editor_Blocks::get_block( parse_blocks( $tr->post_content ), $path );

		if ( null === $block || null === $tr_block || null === $block['blockName'] || $block['blockName'] !== $tr_block['blockName'] ) {
			return false;
		}

		$fields = Strope_Frontend_Editor_Blocks::get_block_fields( $tr_block );
		return ! empty( $fields ) && ( 'auto' === $field || '' === $field || in_array( $field, $fields, true ) );
	}

	/**
	 * Returns the status of the text in each language, for the language switch.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post  Post being viewed.
	 * @param string  $path  Path of the text.
	 * @param string  $field Field of the block.
	 * @return array[]
	 */
	private function get_languages_status( WP_Post $post, string $path, string $field ): array {
		$languages = array();

		foreach ( $this->model->get_languages_list() as $language ) {
			$translation = $this->get_translation( $post, $path, $field, $language );
			$languages[] = array_merge(
				(array) $this->language_data( $language ),
				array( 'status' => $translation['status'] )
			);
		}

		return $languages;
	}

	/**
	 * Returns the text in all the languages.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post  Post being viewed.
	 * @param string  $path  Path of the text.
	 * @param string  $field Field of the block.
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_overview( WP_Post $post, string $path, string $field ) {
		$base = $this->get_item_data( $post, $path, $field, null );

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$base      = $base->get_data();
		$field     = (string) $base['field'];
		$languages = array();

		foreach ( $this->model->get_languages_list() as $language ) {
			$translation = $this->get_translation( $post, $path, $field, $language );
			$html        = null;

			if ( in_array( $translation['status'], array( 'ok', 'current' ), true ) ) {
				$html = $this->get_text_html( $translation['post'], $path, $field );
			}

			$languages[] = array_merge(
				(array) $this->language_data( $language ),
				array(
					'status'  => $translation['status'],
					'post'    => $translation['post'] instanceof WP_Post ? $translation['post']->ID : 0,
					'html'    => $html,
					'message' => $translation['message'],
					'links'   => $translation['links'],
				)
			);
		}

		return rest_ensure_response(
			array(
				'overview'    => true,
				'post'        => $post->ID,
				'viewed'      => $post->ID,
				'path'        => $path,
				'field'       => $field,
				'block'       => $base['block'],
				'block_title' => $base['block_title'],
				'pre'         => ! empty( $base['pre'] ),
				'languages'   => $languages,
			)
		);
	}

	/**
	 * Returns the html of a text of a post, as displayed in the panel (sanitized).
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $path  Path of the text.
	 * @param string  $field Field of the block.
	 * @return string|null Null if not found.
	 */
	private function get_text_html( WP_Post $post, string $path, string $field ): ?string {
		if ( in_array( $path, array( 'title', 'excerpt' ), true ) ) {
			$property = 'post_' . $path;
			return esc_html( $post->$property );
		}

		if ( Strope_Frontend_Editor_ACF::is_path( $path ) ) {
			$acf_field = Strope_Frontend_Editor_ACF::get_field( $post->ID, $path );
			return null === $acf_field ? null : Strope_Frontend_Editor_ACF::render( $post->ID, $acf_field, Strope_Frontend_Editor_ACF::get_value( $post->ID, $acf_field ) );
		}

		$block = null === Strope_Frontend_Editor_Blocks::parse_path( $path ) ? null : Strope_Frontend_Editor_Blocks::get_block( parse_blocks( $post->post_content ), $path );
		$html  = null === $block ? null : Strope_Frontend_Editor_Blocks::get_field_html( $block, $field );

		if ( null === $html ) {
			return null;
		}

		$spec = Strope_Frontend_Editor_Blocks::get_field_spec( $block['blockName'], $field );
		return Strope_Frontend_Editor_Blocks::sanitize_inline_html( $html, ! empty( $spec['pre'] ) );
	}

	/**
	 * Returns the link opening a post in edit mode, optionally with a text selected.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post Post.
	 * @param string  $path Path of the text to select, empty for none.
	 * @return string
	 */
	private function get_edit_mode_link( WP_Post $post, string $path ): string {
		$args = array(
			Strope_Frontend_Editor::QUERY_ARG => 1,
			Strope_Frontend_Editor::NONCE_ARG => wp_create_nonce( Strope_Frontend_Editor::NONCE_ACTION ),
		);

		if ( '' !== $path ) {
			$args[ Strope_Frontend_Editor::FOCUS_ARG ] = $path;
		}

		return add_query_arg( $args, get_permalink( $post ) );
	}

	/**
	 * Returns the link to create the translation of a post, if the current user can.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post         $post     Post.
	 * @param Strope_Language $language Language of the translation.
	 * @return string Empty string if the user can't create the translation.
	 */
	private function get_add_translation_link( WP_Post $post, Strope_Language $language ): string {
		$type = get_post_type_object( $post->post_type );

		if ( ! $type || ! current_user_can( $type->cap->create_posts ) || ( function_exists( 'strope_user_can_translate_language' ) && ! strope_user_can_translate_language( $language ) ) ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'post_type' => $post->post_type,
				'from_post' => $post->ID,
				'new_lang'  => $language->slug,
			),
			admin_url( 'post-new.php' )
		);

		return wp_nonce_url( $url, 'new-post-translation' );
	}

	/**
	 * Returns the data needed to edit a block, the title, the excerpt or a custom field of a post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post      $post   Post.
	 * @param string       $path   Path of the text.
	 * @param string       $field  Field of the block.
	 * @param WP_Post|null $source Optional. Post whose text is displayed as source, defaults to the source of the translation.
	 * @return WP_REST_Response|WP_Error
	 */
	private function get_item_data( WP_Post $post, string $path, string $field, ?WP_Post $source ) {
		if ( null === $source ) {
			$source_id = $this->get_source_post_id( $post->ID );
			$source    = $source_id && current_user_can( 'read_post', $source_id ) ? get_post( $source_id ) : null;
		}
		$edit_link = (string) get_edit_post_link( $post->ID, 'raw' );

		$data = array(
			'post'        => $post->ID,
			'path'        => $path,
			'field'       => '',
			'fields'      => array(),
			'block'       => '',
			'block_title' => '',
			'supported'   => false,
			'editable'    => false,
			'message'     => '',
			'pre'         => false,
			'plain'       => false,
			'html'        => '',
			'text'        => '',
			'hash'        => '',
			'language'    => $this->language_data( $this->model->post->get_language( $post->ID ) ),
			'source'      => array(
				'post'     => $source ? $source->ID : 0,
				'language' => $source ? $this->language_data( $this->model->post->get_language( $source->ID ) ) : null,
				'html'     => null,
				'text'     => null, // Raw source text, in the format of the edited one (for machine translation).
				'message'  => $source ? '' : __( 'No source post', 'strope-translate' ),
			),
			'edit_link'   => $edit_link,
		);

		if ( in_array( $path, array( 'title', 'excerpt' ), true ) ) {
			$property = 'post_' . $path;

			$data['field']       = 'text';
			$data['fields']      = array(
				array(
					'name'  => 'text',
					'label' => __( 'Text', 'strope-translate' ),
				),
			);
			$data['block']       = $path;
			$data['block_title'] = 'title' === $path ? __( 'Title', 'strope-translate' ) : __( 'Excerpt', 'strope-translate' );
			$data['supported']   = true;
			$data['editable']    = true;
			$data['pre']         = 'excerpt' === $path;
			$data['plain']       = true; // Edited as raw text (markup included) in a text area.
			$data['text']        = (string) $post->$property;
			$data['hash']        = md5( $post->$property );

			if ( $source ) {
				$data['source']['html'] = esc_html( $source->$property );
				$data['source']['text'] = (string) $source->$property;
			}

			return rest_ensure_response( $data );
		}

		if ( Strope_Frontend_Editor_ACF::is_path( $path ) ) {
			$field = Strope_Frontend_Editor_ACF::get_field( $post->ID, $path );

			if ( null === $field ) {
				return new WP_Error( 'strope_fe_field_not_found', __( 'This field was not found in the post. The post may have been modified, please reload the page.', 'strope-translate' ), array( 'status' => 404 ) );
			}

			$value = Strope_Frontend_Editor_ACF::get_value( $post->ID, $field );

			$data['field']       = 'value';
			$data['fields']      = array(
				array(
					'name'  => 'value',
					'label' => __( 'Text', 'strope-translate' ),
				),
			);
			$data['block']       = 'acf';
			$data['block_title'] = Strope_Frontend_Editor_ACF::get_label( $field );
			$data['supported']   = true;
			$data['editable']    = true;
			$data['plain']       = true; // Raw value (markup included for text areas and editors) edited as text, sanitized server side.
			$data['multiline']   = 'text' !== $field['type'];
			$data['text']        = $value;
			$data['hash']        = md5( $value );

			if ( $source ) {
				$source_field = Strope_Frontend_Editor_ACF::get_field( $source->ID, $path );
				if ( null !== $source_field && $source_field['key'] === $field['key'] ) {
					$source_value           = Strope_Frontend_Editor_ACF::get_value( $source->ID, $source_field );
					$data['source']['html'] = Strope_Frontend_Editor_ACF::render( $source->ID, $source_field, $source_value );
					$data['source']['text'] = $source_value;
				} else {
					$data['source']['message'] = __( 'No matching source field', 'strope-translate' );
				}
			}

			return rest_ensure_response( $data );
		}

		if ( 'content' === $path ) {
			$data['block']       = 'classic';
			$data['block_title'] = __( 'Classic content', 'strope-translate' );
			$data['message']     = __( 'This content was not created with blocks. Open it in the editor to translate it.', 'strope-translate' );
			return rest_ensure_response( $data );
		}

		$blocks = parse_blocks( $post->post_content );
		$block  = Strope_Frontend_Editor_Blocks::get_block( $blocks, $path );

		if ( null === $block || null === $block['blockName'] ) {
			return new WP_Error( 'strope_fe_block_not_found', __( 'This block was not found in the post. The post may have been modified, please reload the page.', 'strope-translate' ), array( 'status' => 404 ) );
		}

		$editor_path = Strope_Frontend_Editor_Blocks::get_editor_path( $blocks, $path );
		if ( $edit_link && '' !== $editor_path ) {
			$data['edit_link'] = add_query_arg( 'strope_fe_block', $editor_path, $edit_link );
		}

		$type                = WP_Block_Type_Registry::get_instance()->get_registered( $block['blockName'] );
		$data['block']       = $block['blockName'];
		$data['block_title'] = $type && ! empty( $type->title ) ? $type->title : $block['blockName'];
		$data['hash']        = Strope_Frontend_Editor_Blocks::get_hash( $block );

		$fields = Strope_Frontend_Editor_Blocks::get_block_fields( $block );
		if ( empty( $fields ) ) {
			$data['message'] = __( 'This block cannot be edited here. Open it in the block editor to translate it.', 'strope-translate' );
			return rest_ensure_response( $data );
		}

		if ( ! in_array( $field, $fields, true ) ) {
			$field = $fields[0];
		}

		$spec = Strope_Frontend_Editor_Blocks::get_field_spec( $block['blockName'], $field );
		$html = (string) Strope_Frontend_Editor_Blocks::get_field_html( $block, $field );

		$data['supported'] = true;
		$data['field']     = $field;
		$data['pre']       = ! empty( $spec['pre'] );
		$data['fields']    = array_map(
			function ( $name ) {
				return array(
					'name'  => $name,
					'label' => Strope_Frontend_Editor_Blocks::get_field_label( $name ),
				);
			},
			$fields
		);

		// The HTML sent to the client is always sanitized, even when it is the stored one.
		$data['html'] = Strope_Frontend_Editor_Blocks::sanitize_inline_html( $html, $data['pre'] );

		if ( Strope_Frontend_Editor_Blocks::is_inline_html( $html ) ) {
			$data['editable'] = true;
		} else {
			$data['message'] = __( 'This text contains formatting that cannot be edited here. Open it in the block editor to translate it.', 'strope-translate' );
		}

		if ( $source ) {
			$source_block = Strope_Frontend_Editor_Blocks::get_block( parse_blocks( $source->post_content ), $path );
			$source_html  = null;
			if ( null !== $source_block && $source_block['blockName'] === $block['blockName'] ) {
				$source_html = Strope_Frontend_Editor_Blocks::get_field_html( $source_block, $field );
			}

			if ( null === $source_html ) {
				$data['source']['message'] = __( 'No matching source block', 'strope-translate' );
			} else {
				$data['source']['html'] = Strope_Frontend_Editor_Blocks::sanitize_inline_html( $source_html, $data['pre'] );
				$data['source']['text'] = $data['source']['html'];
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Saves an edited text into the post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_item( WP_REST_Request $request ) {
		$post    = get_post( (int) $request['post'] );
		$path    = (string) $request['path'];
		$content = (string) $request['content'];

		if ( in_array( $path, array( 'title', 'excerpt' ), true ) ) {
			$property = 'post_' . $path;

			if ( ! hash_equals( md5( $post->$property ), (string) $request['hash'] ) ) {
				return $this->conflict();
			}

			/*
			 * Sanitized as when saved from the block editor: `wp_update_post()` applies the `title_save_pre`
			 * and `excerpt_save_pre` filters, which run kses for users without the `unfiltered_html` capability.
			 */
			$value = str_replace( array( "\r\n", "\r" ), "\n", $content );
			$value = 'title' === $path ? trim( str_replace( "\n", ' ', $value ) ) : trim( $value );

			$result = $this->update_post( $post, array( $property => $value ), $property );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$post = get_post( $post->ID );
			return rest_ensure_response(
				array(
					'text'     => (string) $post->$property,
					'hash'     => md5( $post->$property ),
					'rendered' => wp_kses_post( 'title' === $path ? get_the_title( $post ) : $post->post_excerpt ),
				)
			);
		}

		if ( Strope_Frontend_Editor_ACF::is_path( $path ) ) {
			return $this->save_field( $post, $path, $content, (string) $request['hash'] );
		}

		$indexes = Strope_Frontend_Editor_Blocks::parse_path( $path );
		if ( null === $indexes ) {
			return new WP_Error( 'strope_fe_not_editable', __( 'This content cannot be edited here.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		$block  = Strope_Frontend_Editor_Blocks::get_block( $blocks, $path );

		if ( null === $block || ( '' !== $request['block'] && $request['block'] !== $block['blockName'] ) ) {
			return $this->conflict();
		}

		if ( ! hash_equals( Strope_Frontend_Editor_Blocks::get_hash( $block ), (string) $request['hash'] ) ) {
			return $this->conflict();
		}

		$field = (string) $request['field'];
		$spec  = Strope_Frontend_Editor_Blocks::get_field_spec( $block['blockName'], $field );
		$old   = null === $spec ? null : Strope_Frontend_Editor_Blocks::get_field_html( $block, $field );

		if ( null === $old ) {
			return new WP_Error( 'strope_fe_not_editable', __( 'This block cannot be edited here.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( ! Strope_Frontend_Editor_Blocks::is_inline_html( $old ) ) {
			return new WP_Error( 'strope_fe_not_editable', __( 'This text contains formatting that cannot be edited here.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		$html = Strope_Frontend_Editor_Blocks::sanitize_inline_html( $content, ! empty( $spec['pre'] ) );
		if ( false !== strpos( $html, '<!--' ) || ! Strope_Frontend_Editor_Blocks::is_inline_html( $html ) ) {
			return new WP_Error( 'strope_fe_invalid_content', __( 'Only inline formatting is allowed in this text.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		$block = Strope_Frontend_Editor_Blocks::set_field_html( $block, $field, $html );

		if ( null === $block ) {
			return new WP_Error( 'strope_fe_not_editable', __( 'This block cannot be edited here.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( $html !== $old ) {
			$blocks = Strope_Frontend_Editor_Blocks::replace_block( $blocks, $indexes, $block );
			$result = $this->update_post( $post, array( 'post_content' => serialize_blocks( $blocks ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Read back what was actually stored (save filters may have altered the content).
		$stored = Strope_Frontend_Editor_Blocks::get_block( parse_blocks( (string) get_post_field( 'post_content', $post->ID, 'raw' ) ), $path );
		if ( null === $stored ) {
			return $this->conflict();
		}

		$stored_html = Strope_Frontend_Editor_Blocks::sanitize_inline_html( (string) Strope_Frontend_Editor_Blocks::get_field_html( $stored, $field ), ! empty( $spec['pre'] ) );

		return rest_ensure_response(
			array(
				'html'     => $stored_html,
				'hash'     => Strope_Frontend_Editor_Blocks::get_hash( $stored ),
				'rendered' => $stored_html,
			)
		);
	}

	/**
	 * Updates a post so that a revision is created and the save hooks run.
	 *
	 * The save is refused when the post is locked by another user, or when kses would alter
	 * parts of the post that the current user did not edit (`wp_update_post()` saves all the fields).
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post   Post to update.
	 * @param array   $data   Post data to update (unslashed).
	 * @param string  $edited Optional. Post field entirely replaced by the user's text, empty when
	 *                        only a part of a field was edited.
	 * @return true|WP_Error
	 */
	private function update_post( WP_Post $post, array $data, string $edited = '' ) {
		$post_id = $post->ID;

		$locked = $this->check_lock( $post_id );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		if ( $this->kses_alters_post( $post, $data, $edited ) ) {
			return new WP_Error(
				'strope_fe_unfiltered_html',
				__( 'This post contains code that you are not allowed to save (such as scripts or embeds), and saving it from here would remove it. Please use the regular editor or ask an administrator.', 'strope-translate' ),
				array( 'status' => 403 )
			);
		}

		// Holds the post lock while saving, as the post editors do.
		$previous_lock = (string) get_post_meta( $post_id, '_edit_lock', true );
		$lock          = wp_set_post_lock( $post_id );

		$data['ID'] = $post_id;
		$result     = wp_update_post( wp_slash( $data ), true );

		// Releases the lock, unless another user took it over in the meantime.
		if ( $lock && implode( ':', $lock ) === get_post_meta( $post_id, '_edit_lock', true ) ) {
			if ( '' === $previous_lock ) {
				delete_post_meta( $post_id, '_edit_lock' );
			} else {
				update_post_meta( $post_id, '_edit_lock', $previous_lock );
			}
		}

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		/**
		 * Fires after a post has been edited with the front-end translation editor.
		 *
		 * @since 4.1.0
		 *
		 * @param int   $post_id Post ID.
		 * @param array $data    Updated post data.
		 */
		do_action( 'strope_frontend_editor_post_updated', $post_id, $data );

		return true;
	}

	/**
	 * Returns an error if the post is locked by another user.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	private function check_lock( int $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$lock_user = wp_check_post_lock( $post_id );
		if ( ! $lock_user ) {
			return true;
		}

		$lock_user = get_userdata( $lock_user );
		return new WP_Error(
			'strope_fe_locked',
			sprintf(
				/* translators: %s: User display name. */
				__( '%s is currently editing this post. Please try again later.', 'strope-translate' ),
				$lock_user ? $lock_user->display_name : __( 'Another user', 'strope-translate' )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Saves an edited custom field value into the post.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $path    Custom field path (`acf:{meta name}`).
	 * @param string  $content Edited value.
	 * @param string  $hash    Hash of the value when the edition started.
	 * @return WP_REST_Response|WP_Error
	 */
	private function save_field( WP_Post $post, string $path, string $content, string $hash ) {
		$field = Strope_Frontend_Editor_ACF::get_field( $post->ID, $path );

		if ( null === $field ) {
			return new WP_Error( 'strope_fe_not_editable', __( 'This field cannot be edited here.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		if ( ! hash_equals( md5( Strope_Frontend_Editor_ACF::get_value( $post->ID, $field ) ), $hash ) ) {
			return $this->conflict();
		}

		$locked = $this->check_lock( $post->ID );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$value  = Strope_Frontend_Editor_ACF::sanitize( $field, $content );
		$stored = Strope_Frontend_Editor_ACF::save( $post->ID, $field, $value );

		/** This action is documented in modules/frontend-editor/frontend-editor-rest.php */
		do_action( 'strope_frontend_editor_post_updated', $post->ID, array( $field['name'] => $stored ) );

		return rest_ensure_response(
			array(
				'text'     => $stored,
				'hash'     => md5( $stored ),
				'rendered' => Strope_Frontend_Editor_ACF::render( $post->ID, $field, $stored ),
			)
		);
	}

	/**
	 * Tells whether kses would alter parts of the post that the current user did not edit.
	 *
	 * For users without the `unfiltered_html` capability, the save filters run kses on all
	 * the fields of the post, which would e.g. remove the scripts of a Custom HTML block
	 * added by an administrator. Differences only due to the HTML normalization done by kses
	 * (quotes, entities, self-closing tags...) are ignored.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post   Post to update.
	 * @param array   $data   Post data to update (unslashed).
	 * @param string  $edited Post field entirely replaced by the user's text, not checked.
	 * @return bool
	 */
	private function kses_alters_post( WP_Post $post, array $data, string $edited ): bool {
		// Same filters as in `kses_init_filters()`.
		$fields = array(
			'post_title'            => array( 'title_save_pre', 'wp_filter_kses' ),
			'post_content'          => array( 'content_save_pre', 'wp_filter_post_kses' ),
			'post_excerpt'          => array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
			'post_content_filtered' => array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
		);

		foreach ( $fields as $field => $filter ) {
			if ( $field === $edited || false === has_filter( $filter[0], $filter[1] ) ) {
				continue;
			}

			$value = isset( $data[ $field ] ) ? (string) $data[ $field ] : (string) $post->$field;
			if ( '' === $value ) {
				continue;
			}

			// `wp_filter_kses()` uses the current filter as context.
			$filtered = wp_kses( $value, 'wp_filter_kses' === $filter[1] ? $filter[0] : 'post' );

			if ( $filtered !== $value && $this->get_html_tokens( $filtered ) !== $this->get_html_tokens( $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a normalized list of the tokens of an HTML string, to compare HTML strings.
	 *
	 * @since 4.1.0
	 *
	 * @param string $html HTML string.
	 * @return array
	 */
	private function get_html_tokens( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$tokens    = array();
		$text      = null;

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			// Adjacent texts are merged and decoded, as kses may encode some characters.
			if ( '#text' === $type ) {
				$text .= $processor->get_modifiable_text();
				continue;
			}

			if ( null !== $text ) {
				$tokens[] = array( '#text', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$text     = null;
			}

			$token = array( $type, $processor->get_token_name(), $processor->is_tag_closer(), $processor->get_modifiable_text() );

			if ( '#tag' === $type && ! $processor->is_tag_closer() ) {
				$attributes = array();
				foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
					$value = $processor->get_attribute( $name );
					if ( 'style' === $name && is_string( $value ) ) {
						// kses (`safecss_filter_attr()`) trims the declarations.
						$value = implode( ';', array_filter( array_map( 'trim', explode( ';', $value ) ), 'strlen' ) );
					}
					$attributes[ $name ] = $value;
				}
				ksort( $attributes );
				$token[] = $attributes;
			}

			$tokens[] = $token;
		}

		if ( null !== $text ) {
			$tokens[] = array( '#text', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		if ( $processor->paused_at_incomplete_token() ) {
			$tokens[] = array( '#incomplete' );
		}

		return $tokens;
	}

	/**
	 * Returns the error sent when the post was modified since the page was loaded.
	 *
	 * @since 4.1.0
	 *
	 * @return WP_Error
	 */
	private function conflict(): WP_Error {
		return new WP_Error(
			'strope_fe_conflict',
			__( 'This content has been modified in the meantime. Please reload the page and try again.', 'strope-translate' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Saves a string translation.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_string( WP_REST_Request $request ) {
		$type     = (string) $request['type'];
		$domain   = (string) $request['domain'];
		$context  = (string) $request['context'];
		$singular = (string) $request['singular'];

		if ( '' === $singular || ! hash_equals( Strope_Frontend_Editor_Strings::sign( $type, $domain, $context, $singular ), (string) $request['id'] ) ) {
			return new WP_Error( 'strope_fe_invalid_string', __( 'Invalid string.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		$language = $this->model->get_language( (string) $request['lang'] );
		if ( ! $language instanceof Strope_Language ) {
			return new WP_Error( 'strope_fe_invalid_language', __( 'Invalid language.', 'strope-translate' ), array( 'status' => 400 ) );
		}

		// Users may be restricted to some languages by the translation workflow.
		if ( function_exists( 'strope_user_can_translate_language' ) && ! strope_user_can_translate_language( $language ) ) {
			return new WP_Error( 'strope_fe_forbidden_language', __( 'Sorry, you are not allowed to translate into this language.', 'strope-translate' ), array( 'status' => 403 ) );
		}

		$translation = (string) $request['translation'];

		if ( Strope_Frontend_Editor_Strings::TYPE_REGISTERED === $type ) {
			$translation = Strope_Frontend_Editor_Strings::save_registered( $language, $singular, $translation );
			if ( is_wp_error( $translation ) ) {
				return $translation;
			}
		} else {
			/*
			 * Plain text is sent to the string discovery module, which sanitizes it and encodes
			 * the special characters itself for users without the `unfiltered_html` capability.
			 */
			$translation = trim( str_replace( array( "\r\n", "\r" ), "\n", $translation ) );
			$result      = Strope_Frontend_Editor_Strings::save_discovered( $domain, $context, $singular, $language, $translation );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$translation = Strope_Frontend_Editor_Strings::get_discovered_translation( $domain, $context, $singular, $language, $translation );
		}

		return rest_ensure_response( array( 'translation' => $translation ) );
	}
}
