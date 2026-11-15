<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to manage admin notices
 * displayed only to admin, based on 'manage_options' capability
 * and only on dashboard, plugins and Strope Translate admin pages
 *
 * @since 2.3.9
 * @since 2.7 Dismissed notices are stored in an option instead of a user meta
 */
class Strope_Admin_Notices {
	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Stores custom notices.
	 *
	 * @var string[]
	 */
	private static $notices = array();

	/**
	 * Constructor
	 * Setup actions
	 *
	 * @since 2.3.9
	 *
	 * @param object $strope_translate The Strope Translate object.
	 */
	public function __construct( $strope_translate ) {
		$this->options = &$strope_translate->options;

		add_action( 'admin_init', array( $this, 'hide_notice' ) );
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	/**
	 * Add a custom notice
	 *
	 * @since 2.3.9
	 *
	 * @param string $name Notice name
	 * @param string $html Content of the notice
	 * @return void
	 */
	public static function add_notice( $name, $html ) {
		self::$notices[ $name ] = $html;
	}

	/**
	 * Get custom notices.
	 *
	 * @since 2.3.9
	 *
	 * @return string[]
	 */
	public static function get_notices() {
		return self::$notices;
	}

	/**
	 * Has a notice been dismissed?
	 *
	 * @since 2.3.9
	 *
	 * @param string $notice Notice name
	 * @return bool
	 */
	public static function is_dismissed( $notice ) {
		$dismissed = get_option( 'strope_dismissed_notices', array() );

		// Handle legacy user meta
		$dismissed_meta = get_user_meta( get_current_user_id(), 'strope_dismissed_notices', true );
		if ( is_array( $dismissed_meta ) ) {
			if ( array_diff( $dismissed_meta, $dismissed ) ) {
				$dismissed = array_merge( $dismissed, $dismissed_meta );
				update_option( 'strope_dismissed_notices', $dismissed );
			}
			if ( ! is_multisite() ) {
				// Don't delete on multisite to avoid the notices to appear in other sites.
				delete_user_meta( get_current_user_id(), 'strope_dismissed_notices' );
			}
		}

		return in_array( $notice, $dismissed );
	}

	/**
	 * Should we display notices on this screen?
	 *
	 * @since 2.3.9
	 *
	 * @param  string $notice The notice name.
	 * @return bool
	 */
	protected function can_display_notice( $notice ) {
		$screen = get_current_screen();

		if ( empty( $screen ) ) {
			return false;
		}

		$screen_id = sanitize_title( __( 'Languages', 'strope-translate' ) );

		/**
		 * Filter admin notices which can be displayed
		 *
		 * @since 2.7.0
		 *
		 * @param bool   $display Whether the notice should be displayed or not.
		 * @param string $notice  The notice name.
		 */
		return apply_filters(
			'strope_can_display_notice',
			in_array(
				$screen->id,
				array(
					'dashboard',
					'plugins',
					'toplevel_page_mlang',
					$screen_id . '_page_mlang_strings',
					$screen_id . '_page_mlang_settings',
				)
			),
			$notice
		);
	}

	/**
	 * Stores a dismissed notice in the database.
	 *
	 * @since 2.3.9
	 *
	 * @param string $notice Notice name.
	 * @return void
	 */
	public static function dismiss( $notice ) {
		$dismissed = get_option( 'strope_dismissed_notices', array() );

		if ( ! in_array( $notice, $dismissed ) ) {
			$dismissed[] = $notice;
			update_option( 'strope_dismissed_notices', array_unique( $dismissed ) );
		}
	}

	/**
	 * Handle a click on the dismiss button
	 *
	 * @since 2.3.9
	 *
	 * @return void
	 */
	public function hide_notice() {
		if ( isset( $_GET['strope-hide-notice'], $_GET['_strope_notice_nonce'] ) && current_user_can( 'manage_options' ) ) {
			$notice = sanitize_key( $_GET['strope-hide-notice'] );
			check_admin_referer( 'strope_hide_notice_' . $notice, '_strope_notice_nonce' );
			self::dismiss( $notice );
			wp_safe_redirect( remove_query_arg( array( 'strope-hide-notice', '_strope_notice_nonce' ), wp_get_referer() ) );
			exit;
		}
	}

	/**
	 * Displays notices
	 *
	 * @since 2.3.9
	 *
	 * @return void
	 */
	public function display_notices() {
		if ( current_user_can( 'manage_options' ) ) {
			foreach ( $this->get_notices() as $notice => $html ) {
				if ( $this->can_display_notice( $notice ) && ! $this->is_dismissed( $notice ) ) {
					?>
					<div class="strope-notice notice notice-info">
						<?php
						$this->dismiss_button( $notice );
						echo wp_kses_post( $html );
						?>
					</div>
					<?php
				}
			}
		}
	}

	/**
	 * Displays a dismiss button
	 *
	 * @since 2.3.9
	 *
	 * @param string $name Notice name
	 * @return void
	 */
	public function dismiss_button( $name ) {
		printf(
			'<a class="notice-dismiss" href="%s"><span class="screen-reader-text">%s</span></a>',
			esc_url( wp_nonce_url( add_query_arg( 'strope-hide-notice', $name ), 'strope_hide_notice_' . $name, '_strope_notice_nonce' ) ),
			/* translators: accessibility text */
			esc_html__( 'Dismiss this notice.', 'strope-translate' )
		);
	}


}
