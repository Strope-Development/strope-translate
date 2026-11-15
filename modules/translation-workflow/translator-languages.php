<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Restricts users to the languages assigned to them ("Translation languages").
 *
 * The languages are stored per site in the user meta `{$blog_prefix}strope_translator_languages`.
 * An empty value means no restriction, except for users with the translator role who have no access
 * to the content in any language until languages are assigned to them.
 * Restricted users can't edit, delete or publish content (posts and terms) in other languages,
 * can't create translations in other languages and only see their languages in the language selectors
 * and in the strings translations table. Content without language is not restricted.
 *
 * @since 4.1.0
 */
class Strope_Translator_Languages {
	/**
	 * User meta key storing the language slugs, without the site prefix.
	 *
	 * @see Strope_Translator_Languages::get_meta_key()
	 *
	 * @var string
	 */
	const META_KEY = 'strope_translator_languages';

	/**
	 * @var Strope_Model
	 */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;

		// Capabilities.
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 20, 4 );
		add_filter( 'strope_pre_current_user_can_synchronize_post', array( $this, 'pre_can_synchronize' ) );
		add_filter( 'strope_pre_current_user_can_synchronize_term', array( $this, 'pre_can_synchronize' ) );
		add_filter( 'strope_can_set_language', array( $this, 'can_set_language' ), 10, 4 );

		// Requests creating content or assigning a language.
		add_action( 'admin_init', array( $this, 'guard_admin_request' ), 0 );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest_request' ), 10, 3 );

		// Language selectors and links.
		add_filter( 'strope_admin_preferred_language', array( $this, 'preferred_language' ) );
		add_filter( 'strope_get_new_post_translation_link', array( $this, 'new_translation_link' ), 20, 2 );
		add_filter( 'strope_get_new_term_translation_link', array( $this, 'new_translation_link' ), 20, 2 );
		add_filter( 'strope_strings_table_languages', array( $this, 'filter_languages_list' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Language slug changes.
		add_action( 'strope_update_language', array( $this, 'update_language' ), 10, 2 );

		// User profile.
		add_action( 'edit_user_profile', array( $this, 'profile_field' ) );
		add_action( 'show_user_profile', array( $this, 'profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
		add_filter( 'manage_users_columns', array( $this, 'users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'users_column' ), 10, 3 );
	}

	/**
	 * Returns the user meta key storing the language slugs for the current site.
	 * The languages are assigned per site, as the user roles.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	public static function get_meta_key() {
		global $wpdb;

		return $wpdb->get_blog_prefix() . self::META_KEY;
	}

	/**
	 * Returns the languages assigned to a user (empty array if the user is not restricted).
	 * Users with the translator role are always restricted: with no languages assigned, they get `array( '' )`
	 * which doesn't match any language.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return string[]
	 */
	public static function get_user_languages( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return array();
		}

		$slugs = get_user_meta( $user_id, self::get_meta_key(), true );

		if ( empty( $slugs ) || ! is_array( $slugs ) ) {
			// No languages assigned: translators have no access, other users are not restricted.
			$user  = get_userdata( $user_id );
			$slugs = $user instanceof WP_User && in_array( Strope_Translation_Workflow::ROLE, (array) $user->roles, true ) ? array( '' ) : array();
		} else {
			// Ignore deleted languages. If none of the assigned languages exists anymore, the user stays restricted.
			$existing = Strope()->model->get_languages_list( array( 'fields' => 'slug' ) );
			$slugs    = array_values( array_intersect( array_map( 'strval', $slugs ), $existing ) );
			$slugs    = empty( $slugs ) ? array( '' ) : $slugs;
		}

		/**
		 * Filters the languages a user is allowed to translate into.
		 * An empty array means no restriction.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $slugs   Language slugs.
		 * @param int      $user_id User ID.
		 */
		$slugs = (array) apply_filters( 'strope_user_translation_languages', $slugs, $user_id );

		return $slugs;
	}

	/**
	 * Tells whether a user is restricted to some languages.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function is_restricted( $user_id = 0 ) {
		return ! empty( self::get_user_languages( $user_id ) );
	}

	/**
	 * Tells whether a user can translate into a language.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language|string $language Language object or slug.
	 * @param int                    $user_id  Optional user ID, defaults to the current user.
	 * @return bool
	 */
	public static function user_can_translate( $language, $user_id = 0 ) {
		$slugs = self::get_user_languages( $user_id );

		if ( empty( $slugs ) ) {
			return true;
		}

		$slug = $language instanceof Strope_Language ? $language->slug : (string) $language;

		return '' !== $slug && in_array( $slug, $slugs, true );
	}

	/**
	 * Denies the edition, deletion and publication of content in languages not assigned to the user.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Additional arguments.
	 * @return string[]
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( Strope_Capabilities::ASSIGN_LANGUAGES === $cap ) {
			// Restricted users can't assign languages directly with the language taxonomy terms, which would bypass `can_set_language()`.
			if ( self::is_restricted( $user_id ) ) {
				$caps[] = 'do_not_allow';
			}
			return $caps;
		}

		if ( empty( $args[0] ) || ! is_numeric( $args[0] ) ) {
			return $caps;
		}

		if ( in_array( $cap, array( 'edit_post', 'edit_page', 'delete_post', 'delete_page', 'publish_post' ), true ) ) {
			if ( ! self::is_restricted( $user_id ) ) {
				return $caps;
			}

			$post = get_post( (int) $args[0] );

			if ( $post instanceof WP_Post && $this->model->is_translated_post_type( $post->post_type ) ) {
				$language = $this->model->post->get_language( $post->ID );

				if ( $language && ! self::user_can_translate( $language, $user_id ) ) {
					$caps[] = 'do_not_allow';
				}
			}
		} elseif ( in_array( $cap, array( 'edit_term', 'delete_term' ), true ) ) {
			if ( ! self::is_restricted( $user_id ) ) {
				return $caps;
			}

			$term = get_term( (int) $args[0] );

			if ( $term instanceof WP_Term && $this->model->is_translated_taxonomy( $term->taxonomy ) ) {
				$language = $this->model->term->get_language( $term->term_id );

				if ( $language && ! self::user_can_translate( $language, $user_id ) ) {
					$caps[] = 'do_not_allow';
				}
			}
		}

		return $caps;
	}

	/**
	 * Enables the full capability check before synchronizing content for restricted users,
	 * so that they can't modify translations in other languages through the synchronization.
	 *
	 * @since 4.1.0
	 *
	 * @param bool|null $check Whether to check the capabilities.
	 * @return bool|null
	 */
	public function pre_can_synchronize( $check ) {
		return self::is_restricted() ? null : $check;
	}

	/**
	 * Prevents the current user from moving content from or to a language not assigned to them.
	 * This is the check done where the language is written, whatever the request; the request guards below
	 * only stop such requests earlier with a meaningful message.
	 *
	 * @since 4.1.0
	 *
	 * @param bool                  $allowed      Whether the language can be changed.
	 * @param int                   $id           Object ID.
	 * @param Strope_Language|false $language     New language, false to remove the language.
	 * @param Strope_Language|false $old_language Current language, false if the object has no language.
	 * @return bool
	 */
	public function can_set_language( $allowed, $id, $language, $old_language ) {
		if ( ! $allowed || ! self::is_restricted() ) {
			return $allowed;
		}

		foreach ( array( $old_language, $language ) as $lang ) {
			if ( $lang instanceof Strope_Language && ! self::user_can_translate( $lang ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the language corresponding to a request value, if any.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return Strope_Language|false
	 */
	protected function get_requested_language( $value ) {
		if ( ! is_string( $value ) || '' === $value || '-1' === $value ) {
			return false;
		}

		// Look for the raw value first (slug, locale or term ID), as some handlers do, then for the sanitized one.
		$language = $this->model->get_language( $value );
		return $language ? $language : $this->model->get_language( sanitize_key( $value ) );
	}

	/**
	 * Stops admin requests creating content in, or moving content to, a language not assigned to the user.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function guard_admin_request() {
		if ( ! self::is_restricted() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$requested = array();

		foreach ( array( 'new_lang', 'inline_lang_choice' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$requested[] = wp_unslash( $_GET[ $key ] );
			}
		}

		foreach ( array( 'post_lang_choice', 'inline_lang_choice', 'term_lang_choice' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$requested[] = wp_unslash( $_POST[ $key ] );
			}
		}

		// Language change in the classic editor languages metabox.
		if ( wp_doing_ajax() && isset( $_POST['action'], $_POST['lang'] ) && 'post_lang_choice' === $_POST['action'] ) {
			$requested[] = wp_unslash( $_POST['lang'] );
		}

		// Bulk translate action.
		if ( isset( $_GET['strope-translate-lang'] ) && is_array( $_GET['strope-translate-lang'] ) ) {
			$requested = array_merge( $requested, array_values( wp_unslash( $_GET['strope-translate-lang'] ) ) );
		}

		// Media language (media edit screen and media modal).
		if ( isset( $_REQUEST['attachments'] ) && is_array( $_REQUEST['attachments'] ) ) {
			foreach ( wp_unslash( $_REQUEST['attachments'] ) as $attachment ) {
				if ( is_array( $attachment ) && isset( $attachment['language'] ) ) {
					$requested[] = $attachment['language'];
				}
			}
		}

		// Synchronized posts created in other languages.
		if ( isset( $_POST['strope_sync_post'], $_POST['post_ID'] ) && is_array( $_POST['strope_sync_post'] ) ) {
			$requested = array_merge( $requested, $this->get_new_synchronized_languages( (int) $_POST['post_ID'], wp_unslash( $_POST['strope_sync_post'] ) ) );
		}
		// phpcs:enable

		foreach ( $requested as $value ) {
			$language = $this->get_requested_language( $value );
			if ( $language && ! self::user_can_translate( $language ) ) {
				wp_die(
					esc_html(
						sprintf(
							/* translators: %s is a language name */
							__( 'Sorry, you are not allowed to create or edit content in %s.', 'strope-translate' ),
							$language->name
						)
					),
					esc_html__( 'Not allowed', 'strope-translate' ),
					array(
						'response'  => 403,
						'back_link' => true,
					)
				);
			}
		}
	}

	/**
	 * Returns the languages in which a post synchronization would create a new post.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $sync_post Synchronizations, language slugs as keys and 'true' as value.
	 * @return string[]
	 */
	protected function get_new_synchronized_languages( $post_id, $sync_post ) {
		$languages = array();

		foreach ( $sync_post as $slug => $value ) {
			if ( 'true' === $value && is_string( $slug ) && ! $this->model->post->get_translation( $post_id, sanitize_key( $slug ) ) ) {
				$languages[] = $slug;
			}
		}

		return $languages;
	}

	/**
	 * Returns the REST routes of the translated post types and taxonomies.
	 *
	 * @since 4.1.0
	 *
	 * @return string[] Lowercase routes (the REST server matches routes case-insensitively).
	 */
	protected function get_translated_rest_routes() {
		$routes = array();

		foreach ( $this->model->get_translated_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( $object && $object->show_in_rest ) {
				$routes[] = strtolower( '/' . ( $object->rest_namespace ? $object->rest_namespace : 'wp/v2' ) . '/' . ( $object->rest_base ? $object->rest_base : $object->name ) );
			}
		}

		foreach ( $this->model->get_translated_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( $object && $object->show_in_rest ) {
				$routes[] = strtolower( '/' . ( $object->rest_namespace ? $object->rest_namespace : 'wp/v2' ) . '/' . ( $object->rest_base ? $object->rest_base : $object->name ) );
			}
		}

		return $routes;
	}

	/**
	 * Stops REST requests creating content in, or moving content to, a language not assigned to the user.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed           $result  Response to replace the requested version with.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function guard_rest_request( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true ) || ! self::is_restricted() ) {
			return $result;
		}

		// The REST server matches the routes case-insensitively.
		$route   = strtolower( untrailingslashit( $request->get_route() ) );
		$matches = false;
		$create  = false;

		foreach ( $this->get_translated_rest_routes() as $base ) {
			if ( $route === $base || 0 === strpos( $route, $base . '/' ) ) {
				$matches = true;
				$create  = $route === $base;
				break;
			}
		}

		if ( ! $matches ) {
			return $result;
		}

		// New content must be created in one of the user's languages: default to the first one.
		if ( $create && 'POST' === $request->get_method() && ! $this->get_requested_language( $request->get_param( 'lang' ) ) ) {
			$allowed = array_filter( array_map( array( $this->model, 'get_language' ), array_filter( self::get_user_languages() ) ) );

			if ( empty( $allowed ) ) {
				return new WP_Error(
					'strope_language_not_allowed',
					__( 'Sorry, you are not allowed to create content in any language.', 'strope-translate' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			$request->set_param( 'lang', reset( $allowed )->slug );
		}

		$requested = array( $request->get_param( 'lang' ) );
		$sync_post = $request->get_param( 'strope_sync_post' );

		if ( is_array( $sync_post ) ) {
			// When creating a post, all the synchronized posts are new.
			$post_id   = preg_match( '#/(\d+)$#', $route, $id ) ? (int) $id[1] : 0;
			$requested = array_merge( $requested, $this->get_new_synchronized_languages( $post_id, $sync_post ) );
		}

		foreach ( $requested as $value ) {
			$language = $this->get_requested_language( $value );
			if ( $language && ! self::user_can_translate( $language ) ) {
				return new WP_Error(
					'strope_language_not_allowed',
					sprintf(
						/* translators: %s is a language name */
						__( 'Sorry, you are not allowed to create or edit content in %s.', 'strope-translate' ),
						$language->name
					),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		return $result;
	}

	/**
	 * Makes sure the preferred language (used for new content) is one of the user's languages.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language|false $language Preferred language.
	 * @return Strope_Language|false
	 */
	public function preferred_language( $language ) {
		$slugs = self::get_user_languages();

		if ( empty( $slugs ) || ( $language instanceof Strope_Language && in_array( $language->slug, $slugs, true ) ) ) {
			return $language;
		}

		$allowed = $this->model->get_language( reset( $slugs ) );
		return $allowed ? $allowed : $language;
	}

	/**
	 * Removes the links to create translations in languages not assigned to the user.
	 *
	 * @since 4.1.0
	 *
	 * @param string          $link     The new translation link.
	 * @param Strope_Language $language The language of the new translation.
	 * @return string
	 */
	public function new_translation_link( $link, $language ) {
		return self::user_can_translate( $language ) ? $link : '';
	}

	/**
	 * Keeps only the languages assigned to the current user in a list of languages.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Language[] $languages List of languages.
	 * @return Strope_Language[]
	 */
	public function filter_languages_list( $languages ) {
		if ( ! self::is_restricted() ) {
			return $languages;
		}

		return array_values(
			array_filter(
				(array) $languages,
				function ( $language ) {
					return $language instanceof Strope_Language && self::user_can_translate( $language );
				}
			)
		);
	}

	/**
	 * Hides the languages not assigned to the current user in the language selectors.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		$screen = get_current_screen();

		if ( empty( $screen ) || ! in_array( $screen->base, array( 'post', 'edit', 'edit-tags', 'term', 'upload' ), true ) || ! self::is_restricted() ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script( 'strope_translation_workflow', plugins_url( '/js/build/translation-workflow' . $suffix . '.js', STROPE_TRANSLATE_FILE ), array(), STROPE_TRANSLATE_VERSION, true );
		wp_localize_script(
			'strope_translation_workflow',
			'strope_translator_languages',
			array(
				'all'     => $this->model->get_languages_list( array( 'fields' => 'slug' ) ),
				'allowed' => self::get_user_languages(),
			)
		);
	}

	/**
	 * Follows the language slug changes in the users translation languages.
	 *
	 * @since 4.1.0
	 *
	 * @param array           $args New language data, see Strope_Admin_Model::update_language().
	 * @param Strope_Language $lang Previous language.
	 * @return void
	 */
	public function update_language( $args, $lang ) {
		if ( ! $lang instanceof Strope_Language || empty( $args['slug'] ) || ! is_string( $args['slug'] ) || $args['slug'] === $lang->slug ) {
			return;
		}

		$new_slug = sanitize_key( $args['slug'] );
		$user_ids = get_users(
			array(
				'fields'     => 'ID',
				'meta_key'     => self::get_meta_key(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => '"' . $lang->slug . '"', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => 'LIKE',
			)
		);

		foreach ( $user_ids as $user_id ) {
			$slugs = get_user_meta( (int) $user_id, self::get_meta_key(), true );

			if ( is_array( $slugs ) && in_array( $lang->slug, $slugs, true ) ) {
				// Direct update: the new slug may not be registered yet.
				$old_slug = $lang->slug;
				$slugs    = array_map(
					function ( $slug ) use ( $old_slug, $new_slug ) {
						return $old_slug === $slug ? $new_slug : (string) $slug;
					},
					$slugs
				);
				$slugs    = array_values( array_unique( $slugs ) );
				update_user_meta( (int) $user_id, self::get_meta_key(), $slugs );
			}
		}
	}

	/**
	 * Tells whether the current user can edit the translation languages of a user.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function current_user_can_edit_languages( $user_id ) {
		return get_current_user_id() !== (int) $user_id
			&& current_user_can( 'edit_user', $user_id )
			&& ( current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) || current_user_can( 'edit_users' ) );
	}

	/**
	 * Displays the "Translation languages" field in the user profile.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_User $user User being edited.
	 * @return void
	 */
	public function profile_field( $user ) {
		$assigned  = self::get_user_languages( $user->ID );
		$languages = $this->model->get_languages_list();

		if ( ! self::current_user_can_edit_languages( $user->ID ) ) {
			// Read-only information for the user themself.
			if ( empty( $assigned ) || get_current_user_id() !== $user->ID ) {
				return;
			}
			?>
			<h2><?php esc_html_e( 'Translation', 'strope-translate' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Translation languages', 'strope-translate' ); ?></th>
					<td>
						<?php
						$names = array();
						foreach ( $languages as $language ) {
							if ( in_array( $language->slug, $assigned, true ) ) {
								$names[] = $language->name;
							}
						}
						echo esc_html( empty( $names ) ? __( 'None', 'strope-translate' ) : implode( ', ', $names ) );
						?>
						<p class="description"><?php esc_html_e( 'You can only create and edit content in these languages.', 'strope-translate' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
			return;
		}
		?>
		<h2><?php esc_html_e( 'Translation', 'strope-translate' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Translation languages', 'strope-translate' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Translation languages', 'strope-translate' ); ?></span></legend>
						<?php
						wp_nonce_field( 'strope_translator_languages_' . $user->ID, '_strope_translator_languages_nonce' );
						foreach ( $languages as $language ) {
							printf(
								'<label><input type="checkbox" name="strope_translator_languages[]" value="%s"%s /> %s</label><br />',
								esc_attr( $language->slug ),
								checked( in_array( $language->slug, $assigned, true ), true, false ),
								esc_html( $language->name )
							);
						}
						?>
						<p class="description">
							<?php
							if ( in_array( Strope_Translation_Workflow::ROLE, (array) $user->roles, true ) ) {
								esc_html_e( 'The user can only create and edit content (and translate strings) in the checked languages. Translators without languages cannot edit any translated content.', 'strope-translate' );
							} else {
								esc_html_e( 'The user can only create and edit content (and translate strings) in the checked languages. Leave all unchecked for no restriction.', 'strope-translate' );
							}
							?>
						</p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the "Translation languages" field.
	 *
	 * @since 4.1.0
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function save_profile_field( $user_id ) {
		if ( ! isset( $_POST['_strope_translator_languages_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_strope_translator_languages_nonce'] ), 'strope_translator_languages_' . $user_id ) ) {
			return;
		}

		if ( ! self::current_user_can_edit_languages( $user_id ) ) {
			return;
		}

		self::set_user_languages( $user_id, isset( $_POST['strope_translator_languages'] ) ? (array) wp_unslash( $_POST['strope_translator_languages'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Sets the languages of a user (no capability check).
	 *
	 * @since 4.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $slugs   Language slugs, empty for no restriction.
	 * @return void
	 */
	public static function set_user_languages( $user_id, $slugs ) {
		$existing = Strope()->model->get_languages_list( array( 'fields' => 'slug' ) );
		$slugs    = array_values( array_intersect( $existing, array_map( 'sanitize_key', array_filter( $slugs, 'is_string' ) ) ) );

		if ( empty( $slugs ) ) {
			delete_user_meta( $user_id, self::get_meta_key() );
		} else {
			update_user_meta( $user_id, self::get_meta_key(), $slugs );
		}
	}

	/**
	 * Adds the translation languages column to the users list table.
	 *
	 * @since 4.1.0
	 *
	 * @param string[] $columns Columns.
	 * @return string[]
	 */
	public function users_columns( $columns ) {
		if ( current_user_can( Strope_Capabilities::MANAGE_LANGUAGES ) || current_user_can( 'edit_users' ) ) {
			$columns['strope_translator_languages'] = esc_html__( 'Translation languages', 'strope-translate' );
		}
		return $columns;
	}

	/**
	 * Fills the translation languages column of the users list table.
	 *
	 * @since 4.1.0
	 *
	 * @param string $output      Column output.
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string
	 */
	public function users_column( $output, $column_name, $user_id ) {
		if ( 'strope_translator_languages' !== $column_name ) {
			return $output;
		}

		$slugs = self::get_user_languages( $user_id );

		if ( empty( $slugs ) ) {
			return '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'All languages', 'strope-translate' ) . '</span>';
		}

		$names = array();
		foreach ( $slugs as $slug ) {
			$language = '' === $slug ? false : $this->model->get_language( $slug );
			if ( $language ) {
				$names[] = $language->name;
			}
		}

		return empty( $names ) ? esc_html__( 'None', 'strope-translate' ) : esc_html( implode( ', ', $names ) );
	}
}
