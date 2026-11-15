<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages copy and synchronization of terms and post metas
 *
 * @since 1.2
 */
class Strope_Admin_Sync extends Strope_Sync {

	/**
	 * Constructor
	 *
	 * @since 1.2
	 *
	 * @param object $strope_translate The Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		parent::__construct( $strope_translate );

		add_filter( 'wp_insert_post_parent', array( $this, 'wp_insert_post_parent' ), 10, 3 );
		add_filter( 'wp_insert_post_data', array( $this, 'wp_insert_post_data' ) );
		add_filter( 'use_block_editor_for_post', array( $this, 'new_post_translation' ), 5000 ); // After content duplication.
	}

	/**
	 * Translate post parent if exists when using "Add new" ( translation )
	 *
	 * @since 0.6
	 *
	 * @param int   $post_parent Post parent ID
	 * @param int   $post_id     Post ID, unused
	 * @param array $postarr     Array of parsed post data
	 * @return int
	 */
	public function wp_insert_post_parent( $post_parent, $post_id, $postarr ) {
		if ( isset( $_GET['from_post'], $_GET['new_lang'], $_GET['post_type'] ) ) {
			check_admin_referer( 'new-post-translation' );
			// Make sure not to impact media translations created at the same time
			if ( $_GET['post_type'] === $postarr['post_type'] && current_user_can( 'read_post', (int) $_GET['from_post'] ) && ( $id = wp_get_post_parent_id( (int) $_GET['from_post'] ) ) && $parent = $this->model->post->get_translation( $id, sanitize_key( $_GET['new_lang'] ) ) ) {
				$post_parent = $parent;
			}
		}
		return $post_parent;
	}

	/**
	 * Copy menu order, comment, ping status and optionally the date when creating a new translation
	 *
	 * @since 2.5
	 *
	 * @param array $data An array of slashed post data.
	 * @return array
	 */
	public function wp_insert_post_data( $data ) {
		if ( isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'] ) && 'post-new.php' === $GLOBALS['pagenow'] && $this->model->is_translated_post_type( $data['post_type'] ) ) {
			check_admin_referer( 'new-post-translation' );

			$from_post_id = (int) $_GET['from_post'];
			$from_post    = get_post( $from_post_id );

			if ( $from_post instanceof WP_Post && strope_current_user_can_copy_post( $from_post->ID ) ) {
				foreach ( array( 'menu_order', 'comment_status', 'ping_status' ) as $property ) {
					$data[ $property ] = $from_post->$property;
				}

				// Copy the date only if the synchronization is activated
				if ( in_array( 'post_date', $this->options['sync'] ) ) {
					$data['post_date']     = $from_post->post_date;
					$data['post_date_gmt'] = $from_post->post_date_gmt;
				}
			}
		}

		return $data;
	}

	/**
	 * Copy post metas, and taxonomies when using "Add new" ( translation )
	 *
	 * @since 2.5
	 * @since 3.1 Use of use_block_editor_for_post filter instead of rest_api_init which is triggered too early in WP 5.8.
	 *
	 * @param bool $is_block_editor Whether the post can be edited or not.
	 * @return bool
	 */
	public function new_post_translation( $is_block_editor ) {
		global $post;
		static $done = array();

		if ( ! empty( $post ) && isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'] ) && 'post-new.php' === $GLOBALS['pagenow'] && $this->model->is_translated_post_type( $post->post_type ) ) {
			check_admin_referer( 'new-post-translation' );

			// post-new.php only checks that the user can create posts: the source post must also be readable.
			$from_post_id = (int) $_GET['from_post'];
			$lang         = $this->model->get_language( sanitize_key( $_GET['new_lang'] ) );

			if ( ! $from_post_id || ! $lang || ! empty( $done[ $from_post_id ] ) || ! strope_current_user_can_copy_post( $from_post_id ) ) {
				return $is_block_editor;
			}

			$done[ $from_post_id ] = true; // Avoid a second duplication in the block editor. Using an array only to allow multiple phpunit tests.

			$this->taxonomies->copy( $from_post_id, $post->ID, $lang->slug );
			$this->post_metas->copy( $from_post_id, $post->ID, $lang->slug );

			if ( is_sticky( $from_post_id ) ) {
				stick_post( $post->ID );
			}
		}

		return $is_block_editor;
	}

	/**
	 * Get post fields to synchronize.
	 *
	 * @since 2.4
	 *
	 * @param WP_Post $post Post object.
	 * @return array Fields to synchronize.
	 */
	protected function get_fields_to_sync( $post ) {
		global $wpdb;

		$postarr = parent::get_fields_to_sync( $post );

		// For new drafts, save the date now otherwise it is overridden by WP. Thanks to JoryHogeveen. See #32.
		if ( in_array( 'post_date', $this->options['sync'] ) && isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'] ) && 'post-new.php' === $GLOBALS['pagenow'] ) {
			check_admin_referer( 'new-post-translation' );

			unset( $postarr['post_date'] );
			unset( $postarr['post_date_gmt'] );

			$original = get_post( (int) $_GET['from_post'] );

			if ( $original instanceof WP_Post && strope_current_user_can_copy_post( $original->ID ) ) {
				$wpdb->update(
					$wpdb->posts,
					array(
						'post_date'     => $original->post_date,
						'post_date_gmt' => $original->post_date_gmt,
					),
					array( 'ID' => $post->ID )
				);
			}
		}

		if ( isset( $GLOBALS['post_type'] ) ) {
			$post_type = $GLOBALS['post_type'];
		} elseif ( isset( $_REQUEST['post_type'] ) ) {
			$post_type = sanitize_key( $_REQUEST['post_type'] ); // 2nd case for quick edit
		}

		// Make sure not to impact media translations when creating them at the same time as post
		if ( in_array( 'post_parent', $this->options['sync'] ) && ( ! isset( $post_type ) || $post_type !== $post->post_type ) ) {
			unset( $postarr['post_parent'] );
		}

		return $postarr;
	}

	/**
	 * Synchronizes post fields in translations.
	 *
	 * @since 1.2
	 *
	 * @param int     $post_id      Post id.
	 * @param WP_Post $post         Post object.
	 * @param int[]   $translations Post translations.
	 */
	public function strope_save_post( $post_id, $post, $translations ) {
		parent::strope_save_post( $post_id, $post, $translations );

		// Sticky posts
		if ( in_array( 'sticky_posts', $this->options['sync'] ) && $post instanceof WP_Post ) {
			$is_sticky = $this->get_requested_sticky_status( $post );

			if ( null !== $is_sticky ) {
				$stickies = (array) get_option( 'sticky_posts', array() );
				if ( $is_sticky ) {
					$stickies = array_merge( $stickies, array_values( $translations ) );
				} else {
					$stickies = array_diff( $stickies, array_values( $translations ) );
				}
				update_option( 'sticky_posts', array_values( array_unique( array_map( 'intval', $stickies ) ) ) );
			}
		}
	}

	/**
	 * Returns the sticky status requested for a post saved from the post editor, quick edit or bulk edit,
	 * following the rules applied by WordPress in `edit_post()` and `bulk_edit_posts()`.
	 *
	 * @since 4.1.0
	 *
	 * @param WP_Post $post The saved post.
	 * @return bool|null True to stick, false to unstick, null when the request doesn't change the sticky status
	 *                   (no sticky field, "No change" in bulk edit, another post saved during the request,
	 *                   or a user who is not allowed to change the sticky status).
	 */
	protected function get_requested_sticky_status( WP_Post $post ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Nonces are verified by WordPress before saving the post.
		$post_type_object = get_post_type_object( $post->post_type );

		if ( empty( $post_type_object ) || ! current_user_can( $post_type_object->cap->edit_others_posts ) ) {
			return null;
		}

		// Bulk edit.
		if ( isset( $_REQUEST['bulk_edit'], $_REQUEST['post'], $_REQUEST['sticky'] ) && is_array( $_REQUEST['post'] ) ) {
			if ( ! in_array( $post->ID, array_map( 'intval', $_REQUEST['post'] ), true ) ) {
				return null;
			}

			switch ( $_REQUEST['sticky'] ) {
				case 'sticky':
					return true;
				case 'unsticky':
					return false;
				default:
					return null; // No change.
			}
		}

		// Post editor and quick edit: an unchecked checkbox is not sent.
		if ( isset( $_POST['action'], $_POST['post_ID'] ) && in_array( $_POST['action'], array( 'editpost', 'inline-save' ), true ) && (int) $_POST['post_ID'] === $post->ID ) {
			if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) {
				return null;
			}

			return ! empty( $_POST['sticky'] );
		}

		return null;
		// phpcs:enable
	}

	/**
	 * Some backward compatibility with Strope Translate < 2.3
	 * allows to call Strope()->sync->copy_post_metas() and Strope()->sync->copy_taxonomies()
	 * used for example by third party extensions
	 * the compatibility is however only partial as the 4th argument $sync is lost
	 *
	 * @since 2.3
	 *
	 * @param string $func Function name
	 * @param array  $args Function arguments
	 * @return mixed|void
	 */
	public function __call( $func, $args ) {
		$obj = substr( $func, 5 );

		if ( is_object( $this->$obj ) && method_exists( $this->$obj, 'copy' ) ) {
			if ( WP_DEBUG ) {
				$debug = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$i = 1 + empty( $debug[1]['line'] ); // The file and line are in $debug[2] if the function was called using call_user_func

				trigger_error( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					sprintf(
						'%1$s was called incorrectly in %3$s on line %4$s: the call to Strope()->sync->%1$s() has been deprecated in Strope Translate 2.3, use Strope()->sync->%2$s->copy() instead.' . "\nError handler",
						esc_html( $func ),
						esc_html( $obj ),
						esc_html( $debug[ $i ]['file'] ),
						absint( $debug[ $i ]['line'] )
					)
				);
			}
			return call_user_func_array( array( $this->$obj, 'copy' ), $args );
		}

		$debug = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		trigger_error( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			sprintf(
				'Call to undefined function Strope()->sync->%1$s() in %2$s on line %3$s' . "\nError handler",
				esc_html( $func ),
				esc_html( $debug[0]['file'] ),
				absint( $debug[0]['line'] )
			),
			E_USER_ERROR
		);
	}
}
