<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to store cross domain data
 *
 * @since 2.0
 * @since 4.0.0 Data are stored in short lived transients instead of options and user metas.
 */
class Strope_Xdata_Session_Manager {
	/**
	 * Lifetime of the stored data, in seconds.
	 * Cross domain data are consumed within a few seconds by a redirect.
	 *
	 * @var int
	 */
	const LIFETIME = 5 * MINUTE_IN_SECONDS;

	/**
	 * Writes cross domain data to the session
	 *
	 * @since 2.0
	 *
	 * @param string $key     A unique random key.
	 * @param array  $data    Data to store in the session.
	 * @param int    $user_id Optional, user id.
	 * @return void
	 */
	public function set( $key, $data, $user_id = 0 ) {
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		set_transient(
			$this->get_transient_name( $key ),
			array(
				'user_id' => (int) $user_id,
				'data'    => $data,
			),
			self::LIFETIME
		);
	}

	/**
	 * Reads cross domain data in the session
	 * And deletes the session to avoid a replay
	 *
	 * @since 2.0
	 * @since 4.1.0 The session can be used only by the request which actually deleted it.
	 *
	 * @param string $key The session key.
	 * @return array
	 */
	public function get( $key ) {
		$name    = $this->get_transient_name( $key );
		$session = get_transient( $name );

		// No replay: if concurrent requests read the session, only one of them can delete it.
		if ( ! delete_transient( $name ) ) {
			$session = false;
		}

		if ( ! is_array( $session ) || ! isset( $session['data'] ) || ! is_array( $session['data'] ) ) {
			wp_die( esc_html__( 'An error has occurred.', 'strope-translate' ) );
		}

		$data = $session['data'];

		if ( ! empty( $session['user_id'] ) ) {
			$data['user_id'] = (int) $session['user_id'];
		}

		return $data;
	}

	/**
	 * Returns the transient name for a session key.
	 *
	 * @since 4.0.0
	 *
	 * @param string $key The session key.
	 * @return string
	 */
	private function get_transient_name( $key ) {
		return 'strope_xdata_' . sanitize_key( $key );
	}
}
