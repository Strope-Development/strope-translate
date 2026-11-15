<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * An abstract class to handle cross domain data and single sign on
 * Inspired by https://github.com/humanmade/Mercator/
 *
 * @since 2.0
 */
abstract class Strope_Xdata_Base {
	/**
	 * Name of the cookie binding a cross domain data transfer to the browser which initiated it.
	 *
	 * @since 4.1.0
	 *
	 * @var string
	 */
	const STATE_COOKIE = 'strope_xdata_state';

	/**
	 * Lifetime of the state cookie, in seconds.
	 *
	 * @since 4.1.0
	 *
	 * @var int
	 */
	const STATE_LIFETIME = 5 * MINUTE_IN_SECONDS;

	/**
	 * Stores the plugin options.
	 *
	 * @var array
	 */
	public $options;

	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * @var Strope_Links_Model
	 */
	public $links_model;

	/**
	 * Constructor
	 *
	 * @since 2.0
	 *
	 * @param object $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->options     = &$strope_translate->options;
		$this->model       = &$strope_translate->model;
		$this->links_model = &$strope_translate->links_model;

		if ( empty( $_POST['wp_customize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Don't do that in the customizr as the redirect breaks things.
			add_action( 'wp_ajax_strope_xdata_get', array( $this, 'xdata_get' ) );
			add_action( 'wp_ajax_nopriv_strope_xdata_get', array( $this, 'xdata_get' ) );
			add_action( 'wp_ajax_strope_xdata_set', array( $this, 'xdata_set' ) );
			add_action( 'wp_ajax_nopriv_strope_xdata_set', array( $this, 'xdata_set' ) );
			add_action( 'wp_ajax_strope_xdata_start', array( $this, 'xdata_start' ) );
			add_action( 'wp_ajax_nopriv_strope_xdata_start', array( $this, 'xdata_start' ) );
			add_action( 'wp_ajax_strope_xdata_login', array( $this, 'xdata_login' ) );
			add_action( 'wp_ajax_nopriv_strope_xdata_login', array( $this, 'xdata_login' ) );
		}

		// Login redirect.
		add_action( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 ); // Must be defined in child class.
		add_filter( 'admin_url', array( $this, 'admin_url' ), 5 ); // Before Strope_Frontend_Filters_Links.

		// Customizer.
		add_filter( 'customize_allowed_urls', array( $this, 'customize_allowed_urls' ) );
		add_filter( 'allowed_http_origins', array( $this, 'allowed_http_origins' ) );
	}

	/**
	 * Get the time-dependent variable for nonce creation.
	 * Same as wp_nonce_tick() with a specific filter.
	 *
	 * @since 2.1.2
	 *
	 * @return float
	 */
	protected function nonce_tick() {
		/**
		 * Filters the lifespan of nonces in seconds.
		 * For full compatibility with cache plugins, the nonce life should be 2 times greater than the cache lifespan
		 *
		 * @since 2.1.2
		 *
		 * @param int $lifespan Lifespan of nonces in seconds. 0 makes the "nonce" time independent.
		 */
		$nonce_life = apply_filters( 'strope_xdata_nonce_life', DAY_IN_SECONDS );
		return $nonce_life ? ceil( time() / ( $nonce_life / 2 ) ) : 0;
	}

	/**
	 * Creates a user independent nonce
	 *
	 * @since 2.0
	 *
	 * @param string $action Context of the nonce.
	 * @return string The nonce value.
	 */
	public function create_nonce( $action ) {
		$i = $this->nonce_tick();
		return substr( wp_hash( $i . '|' . $action, 'nonce' ), -12, 10 );
	}

	/**
	 * Verifies a user independent nonce
	 *
	 * @since 2.0
	 *
	 * @param string $nonce  The nonce value.
	 * @param string $action Context of the nonce.
	 * @return bool True if the nonce value is correct, false otherwise.
	 */
	public function verify_nonce( $nonce, $action ) {
		$nonce = (string) $nonce;

		if ( empty( $nonce ) ) {
			return false;
		}

		$i = $this->nonce_tick();

		$expected = substr( wp_hash( $i . '|' . $action, 'nonce' ), -12, 10 );
		if ( hash_equals( $expected, $nonce ) ) { // Since WP 3.9.2.
			return true;
		}

		$expected = substr( wp_hash( ( $i - 1 ) . '|' . $action, 'nonce' ), -12, 10 );
		return hash_equals( $expected, $nonce );
	}

	/**
	 * Builds an ajax request url to the domain defined by a language
	 *
	 * @since 2.0
	 *
	 * @param string              $lang The language slug.
	 * @param (string|int|bool)[] $args Existing url parameters.
	 * @return string The ajax url.
	 */
	public function ajax_url( $lang, $args ) {
		$url = admin_url( 'admin-ajax.php' );
		$url = $this->links_model->switch_language_in_link( $url, $this->model->get_language( $lang ) );
		$url = add_query_arg( $args, $url );
		return $url;
	}

	/**
	 * Returns a random key, used as session key or state.
	 *
	 * @since 4.1.0
	 *
	 * @return string
	 */
	protected function generate_key() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Tells whether a string is a valid state.
	 *
	 * @since 4.1.0
	 *
	 * @param mixed $state State.
	 * @return bool
	 */
	protected function is_valid_state( $state ) {
		return is_string( $state ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $state );
	}

	/**
	 * Sets or removes the state cookie on the current domain.
	 *
	 * @since 4.1.0
	 *
	 * @param string $state State, empty to remove the cookie.
	 * @return void
	 */
	protected function set_state_cookie( $state ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::STATE_COOKIE,
			$state,
			array(
				'expires'  => '' === $state ? time() - YEAR_IN_SECONDS : time() + self::STATE_LIFETIME,
				'path'     => SITECOOKIEPATH,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Stores data to transfer in a user session
	 *
	 * @since 2.0
	 * @since 4.1.0 Added the $state parameter. The session key is random.
	 *
	 * @param string $redirect Url to redirect to.
	 * @param bool   $nologin  True if we shoul not attempt to login.
	 * @param string $state    State of the browser allowed to receive the data.
	 * @return string Session key.
	 */
	protected function create_data_session( $redirect, $nologin, $state ) {
		$key = '';

		/**
		 * Filters the data to transfer from one domain to the other
		 *
		 * @since 2.0
		 *
		 * @param array $data The data to transfer from one domain to the other.
		 */
		$data = apply_filters( 'strope_get_xdata', array() );

		if ( is_user_logged_in() && ! $nologin ) {
			$data['token'] = wp_get_session_token();
		}

		if ( ! empty( $data ) ) {
			$data['redirect'] = $redirect;
			$data['time']     = time();
			$data['state']    = hash( 'sha256', $state ); // Only the browser holding the state can use the data.

			$key = $this->generate_key();

			/**
			 * Filters the session manager class where cross domain data are temporarily stored
			 *
			 * @since 2.0
			 *
			 * @param string $class Class name of the session manager.
			 */
			$session_manager_class = apply_filters( 'strope_xdata_session_manager', 'Strope_Xdata_Session_Manager' );

			$session_manager = new $session_manager_class();
			$session_manager->set( $key, $data );
		}

		return $key;
	}

	/**
	 * Get javascript for the cross domain request when the language has just switched
	 *
	 * @since 2.0
	 *
	 * @param string $redirect Redirect url.
	 * @param string $lang     New language slug.
	 * @return string Javascript code.
	 */
	protected function maybe_get_xdomain_js( $redirect, $lang ) {
		if ( ! empty( $_COOKIE[ STROPE_COOKIE ] ) && $_COOKIE[ STROPE_COOKIE ] !== $lang ) {
			$args = array(
				'action'   => 'strope_xdata_get',
				'redirect' => urlencode( $redirect ),
				'nonce'    => $this->create_nonce( 'xdata_get' ),
				'nologin'  => ! empty( $_GET['nologin'] ), // phpcs:ignore WordPress.Security.NonceVerification
			);

			/*
			 * A random state is stored in a cookie of the current domain and sent with the data request,
			 * so that the data can be received only by this browser.
			 */
			return sprintf(
				'( function () {
					var bytes = new Uint8Array( 16 ), state = "", i, xhr;
					if ( ! window.crypto || ! window.crypto.getRandomValues ) {
						return;
					}
					window.crypto.getRandomValues( bytes );
					for ( i = 0; i < bytes.length; i++ ) {
						state += ( "0" + bytes[ i ].toString( 16 ) ).slice( -2 );
					}
					document.cookie = "%3$s=" + state + "; path=%4$s; max-age=%5$d; samesite=lax" + ( "https:" === window.location.protocol ? "; secure" : "" );
					xhr = new XMLHttpRequest();
					xhr.open( "GET", "%1$s" + "&state=" + state, true );
					xhr.withCredentials = true;
					xhr.onreadystatechange = function () {
						if ( 4 == this.readyState && 200 == this.status && this.responseText && -1 != this.responseText ) {
							window.location.replace( "%2$s" + "&key=" + this.responseText );
						}
					};
					xhr.send();
				} )();',
				esc_url_raw( $this->ajax_url( sanitize_key( $_COOKIE[ STROPE_COOKIE ] ), $args ) ),
				esc_url_raw( $this->ajax_url( $lang, array( 'action' => 'strope_xdata_set' ) ) ),
				esc_js( self::STATE_COOKIE ),
				esc_js( SITECOOKIEPATH ),
				(int) self::STATE_LIFETIME
			);
		}
		return '';
	}

	/**
	 * Response to strope_xdata_get request
	 * Writes cross domain data in a user session
	 *
	 * @since 2.0
	 *
	 * @return void
	 */
	public function xdata_get() {
		$state = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Whitelist origin + nonce verification + state of the requesting browser.
		if ( ! is_allowed_http_origin() || ! isset( $_GET['nonce'], $_GET['redirect'] ) || ! $this->verify_nonce( sanitize_key( $_GET['nonce'] ), 'xdata_get' ) || ! $this->is_valid_state( $state ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die();
		}

		// CORS.
		send_origin_headers();

		// Response.
		$key = $this->create_data_session( esc_url_raw( wp_unslash( $_GET['redirect'] ) ), ! empty( $_GET['nologin'] ), $state ); // phpcs:ignore WordPress.Security.NonceVerification
		wp_die( empty( $key ) ? '-1' : esc_html( $key ) );
	}

	/**
	 * Response to strope_xdata_set request
	 * Final step in the cross domain data
	 * Login the user on the current domain
	 * Redirect to the url requested by the usee
	 *
	 * @since 2.0
	 * @since 4.1.0 The data are used only by the browser holding the state they are bound to.
	 *
	 * @return void
	 */
	public function xdata_set() {
		if ( empty( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die();
		}

		/** This filter is documented in modules/xdata/xdata.php */
		$session_manager_class = apply_filters( 'strope_xdata_session_manager', 'Strope_Xdata_Session_Manager' );

		$session_manager = new $session_manager_class();

		$data = $session_manager->get( sanitize_key( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification

		// The state is single use too.
		$state = isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) ) : '';
		$this->set_state_cookie( '' );

		/*
		 * Prevents login CSRF: the data requested by another browser (e.g. with the session
		 * of an attacker's account) are ignored. The redirection is kept.
		 */
		if ( ! empty( $data['state'] ) && is_string( $data['state'] ) && $this->is_valid_state( $state ) && hash_equals( $data['state'], hash( 'sha256', $state ) ) ) {
			if ( ! empty( $data['user_id'] ) && ! empty( $data['token'] ) && time() < $data['time'] + 2 * MINUTE_IN_SECONDS ) {
				// FIXME Use auth_cookie_expiration to sync the expiration time?
				wp_set_auth_cookie( $data['user_id'], false, '', $data['token'] ); // WP 4.3+
			}

			/**
			 * Fires before the redirection to the requested url
			 *
			 * @since 2.0
			 *
			 * @param array $data Data transferred from one domain to the other.
			 */
			do_action( 'strope_set_xdata', $data );
		}

		if ( empty( $data['redirect'] ) ) {
			$data['redirect'] = admin_url();
		}
		wp_safe_redirect( $data['redirect'] );
		exit;
	}

	/**
	 * Response to strope_xdata_start request, on the main domain, after a login on another domain
	 * Sets the state cookie, then requests the session data from the domain where the user logged in
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function xdata_start() {
		$lang     = isset( $_GET['lang'] ) ? sanitize_key( $_GET['lang'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$redirect = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( '' !== $lang && ! $this->model->get_language( $lang ) ) {
			wp_die();
		}

		$state = $this->generate_key();
		$this->set_state_cookie( $state );

		$args = array(
			'action'   => 'strope_xdata_login',
			'redirect' => urlencode( $redirect ),
			'state'    => $state,
		);

		wp_safe_redirect( '' === $lang ? add_query_arg( $args, admin_url( 'admin-ajax.php' ) ) : $this->ajax_url( $lang, $args ) );
		exit;
	}

	/**
	 * Response to strope_xdata_login request, on the domain where the user logged in
	 * Writes the user session in the cross domain data and redirects to the main domain to login there
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function xdata_login() {
		$state    = isset( $_GET['state'] ) ? sanitize_key( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$redirect = isset( $_GET['redirect'] ) ? esc_url_raw( wp_unslash( $_GET['redirect'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$key      = '';

		if ( is_user_logged_in() && $this->is_valid_state( $state ) ) {
			$key = $this->create_data_session( $redirect, false, $state );
		}

		if ( empty( $key ) ) {
			wp_safe_redirect( empty( $redirect ) ? admin_url() : $redirect );
			exit;
		}

		// Always to the main domain, where the state cookie was set.
		wp_safe_redirect( add_query_arg( array( 'action' => 'strope_xdata_set', 'key' => $key ), admin_url( 'admin-ajax.php' ) ) );
		exit;
	}

	/**
	 * Redirects to the main domain to login there too
	 *
	 * @since 2.0
	 * @since 4.1.0 The login on the main domain starts by setting a state there.
	 *
	 * @param string           $redirect_to           The redirect destination URL.
	 * @param string           $requested_redirect_to The requested redirect destination URL passed as a parameter.
	 * @param WP_User|WP_Error $user                  WP_User object if login was successful, WP_Error object otherwise.
	 * @return string
	 */
	public function _login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( false !== strpos( $redirect_to, 'wp-admin' ) ) {
			$redirect_to = $this->links_model->remove_language_from_link( $redirect_to );
		}

		/*
		 * Login on main domain to access admin.
		 * Or if the wp-login.php is already on main domain, login on the current domain.
		 * The main domain sets a state cookie, then the current domain (where the user is now logged in)
		 * writes the session data bound to this state, and finally the main domain logs the user in.
		 */
		$url  = admin_url( 'admin-ajax.php' );
		$lang = $this->links_model->get_language_from_url();
		if ( ! empty( $lang ) ) {
			$url = $this->links_model->remove_language_from_link( $url );
		}

		$args = array(
			'action'   => 'strope_xdata_start',
			'lang'     => (string) $lang,
			'redirect' => urlencode( $redirect_to ),
		);

		return add_query_arg( $args, $url );
	}

	/**
	 * Forces the admin on the main domain
	 *
	 * @since 2.0
	 *
	 * @param string $url An admin url.
	 * @return string
	 */
	public function admin_url( $url ) {
		return $this->links_model->remove_language_from_link( $url );
	}

	/**
	 * Allows all our domains for cross domain requests in the customizer
	 *
	 * @since 2.0
	 *
	 * @param string[] $urls List of allowed urls.
	 * @return string[] Modified list of urls.
	 */
	public function customize_allowed_urls( $urls ) {
		foreach ( $this->links_model->get_hosts() as $host ) {
			$urls[] = ( is_ssl() ? 'https://' : 'http://' ) . trailingslashit( $host );
		}
		return array_unique( $urls );
	}

	/**
	 * Allows all our domains as origins ( for customizer cross domain requests )
	 *
	 * @since 2.0
	 *
	 * @param string[] $origins List of allowed urls.
	 * @return string[] Modified list of urls.
	 */
	public function allowed_http_origins( $origins ) {
		// Unencrypted origins are not trusted on a https site.
		$schemes = is_ssl() || 'https' === wp_parse_url( $this->links_model->home, PHP_URL_SCHEME ) ? array( 'https://' ) : array( 'http://', 'https://' );

		foreach ( $this->links_model->get_hosts() as $host ) {
			foreach ( $schemes as $scheme ) {
				$origins[] = $scheme . $host;
			}
		}
		return array_unique( $origins );
	}
}
