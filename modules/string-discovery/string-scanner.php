<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Scans the active themes and plugins for gettext strings.
 *
 * The scan is split in small steps (a few files each) driven from the admin, and its state is
 * stored in a non-autoloaded option so that it can be resumed.
 *
 * @since 4.1.0
 */
class Strope_String_Scanner {
	/**
	 * Option storing the current scan job.
	 *
	 * @var string
	 */
	const JOB_OPTION = 'strope_string_discovery_job';

	/**
	 * Option storing the scanned components and their last scan date.
	 *
	 * @var string
	 */
	const COMPONENTS_OPTION = 'strope_string_discovery_components';

	/**
	 * Option used as a lock so that only one scan request runs at a time.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'strope_string_discovery_lock';

	/**
	 * Duration after which a lock is considered stale, in seconds.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 60;

	/**
	 * Max size of a scanned file.
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 1048576;

	/**
	 * Gettext functions and the position of their arguments.
	 *
	 * @var array
	 */
	const FUNCTIONS = array(
		'__'           => array( 'singular' => 0, 'domain' => 1 ),
		'_e'           => array( 'singular' => 0, 'domain' => 1 ),
		'esc_html__'   => array( 'singular' => 0, 'domain' => 1 ),
		'esc_html_e'   => array( 'singular' => 0, 'domain' => 1 ),
		'esc_attr__'   => array( 'singular' => 0, 'domain' => 1 ),
		'esc_attr_e'   => array( 'singular' => 0, 'domain' => 1 ),
		'_x'           => array( 'singular' => 0, 'context' => 1, 'domain' => 2 ),
		'_ex'          => array( 'singular' => 0, 'context' => 1, 'domain' => 2 ),
		'esc_html_x'   => array( 'singular' => 0, 'context' => 1, 'domain' => 2 ),
		'esc_attr_x'   => array( 'singular' => 0, 'context' => 1, 'domain' => 2 ),
		'_n'           => array( 'singular' => 0, 'plural' => 1, 'domain' => 3 ),
		'_nx'          => array( 'singular' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4 ),
		'_n_noop'      => array( 'singular' => 0, 'plural' => 1, 'domain' => 2 ),
		'_nx_noop'     => array( 'singular' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3 ),
	);

	/**
	 * Directories never scanned.
	 *
	 * @var string[]
	 */
	const EXCLUDED_DIRS = array( 'vendor', 'node_modules', 'tests', 'test', '.git', '.svn', 'bower_components' );

	/**
	 * Returns the active components (themes and plugins), excluding this plugin.
	 *
	 * @since 4.1.0
	 *
	 * @return array[] Components keyed by id, each with keys type, slug, name, path, domain.
	 */
	public static function get_active_components(): array {
		$components = array();

		$stylesheet = get_stylesheet();
		$template   = get_template();

		foreach ( array_unique( array( $stylesheet, $template ) ) as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}

			$components[ 'theme:' . $slug ] = array(
				'type'   => 'theme',
				'slug'   => $slug,
				'name'   => $theme->get( 'Name' ),
				'path'   => $theme->get_stylesheet_directory(),
				'domain' => (string) $theme->get( 'TextDomain' ),
			);
		}

		$plugins = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$self = plugin_basename( STROPE_TRANSLATE_FILE );

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_unique( $plugins ) as $plugin ) {
			$plugin = (string) $plugin;
			if ( $plugin === $self || ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) || validate_file( $plugin ) ) {
				continue;
			}

			$dir  = dirname( $plugin );
			$slug = '.' === $dir ? basename( $plugin, '.php' ) : $dir;
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );

			$components[ 'plugin:' . $slug ] = array(
				'type'   => 'plugin',
				'slug'   => $slug,
				'name'   => empty( $data['Name'] ) ? $slug : $data['Name'],
				'path'   => '.' === $dir ? WP_PLUGIN_DIR . '/' . $plugin : WP_PLUGIN_DIR . '/' . $dir,
				'domain' => empty( $data['TextDomain'] ) ? $slug : $data['TextDomain'],
			);
		}

		/**
		 * Filters the components (themes and plugins) scanned for strings.
		 *
		 * @since 4.1.0
		 *
		 * @param array[] $components Components keyed by id, each with keys type, slug, name, path (directory or single file), domain.
		 */
		return (array) apply_filters( 'strope_string_discovery_components', $components );
	}

	/**
	 * Returns the scanned components with their stats.
	 *
	 * @since 4.1.0
	 *
	 * @return array[]
	 */
	public static function get_components(): array {
		$components = get_option( self::COMPONENTS_OPTION );
		return is_array( $components ) ? $components : array();
	}

	/**
	 * Returns the list of files to scan in a component, relative to its path.
	 *
	 * @since 4.1.0
	 *
	 * @param string $path Directory or single file.
	 * @return string[]
	 */
	public static function list_files( string $path ): array {
		if ( is_file( $path ) ) {
			return array( basename( $path ) );
		}

		$files = array();
		if ( ! is_dir( $path ) ) {
			return $files;
		}

		$path     = wp_normalize_path( untrailingslashit( $path ) );
		$excluded = self::EXCLUDED_DIRS;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
				function ( $file ) use ( $excluded ) {
					/** @var SplFileInfo $file */
					if ( $file->isDir() ) {
						return ! $file->isLink() && ! in_array( strtolower( $file->getFilename() ), $excluded, true );
					}
					return true;
				}
			)
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			$name = $file->getFilename();
			$ext  = strtolower( $file->getExtension() );

			if ( 'php' !== $ext && 'block.json' !== $name && 'theme.json' !== $name && ! ( 'json' === $ext && false !== strpos( wp_normalize_path( $file->getPath() ), '/styles' ) ) ) {
				continue;
			}

			if ( $file->getSize() > self::MAX_FILE_SIZE ) {
				continue;
			}

			$files[] = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $path ) ), '/' );
		}

		sort( $files );
		return $files;
	}

	/**
	 * Starts a new scan of all active components.
	 *
	 * @since 4.1.0
	 *
	 * @return array|WP_Error Progress, see `self::get_progress()`, or an error if another scan request is running.
	 */
	public static function start() {
		return self::locked( array( self::class, 'do_start' ) );
	}

	/**
	 * Processes the next files of the current job.
	 *
	 * @since 4.1.0
	 *
	 * @param float $time_budget Max time spent, in seconds.
	 * @return array|WP_Error Progress, see `self::get_progress()`, or an error if another scan request is running.
	 */
	public static function step( float $time_budget = 3.0 ) {
		return self::locked( array( self::class, 'do_step' ), $time_budget );
	}

	/**
	 * Runs a callback while holding the scan lock.
	 *
	 * @since 4.1.0
	 *
	 * @param callable $callback Callback.
	 * @param mixed    ...$args  Callback arguments.
	 * @return array|WP_Error
	 */
	protected static function locked( $callback, ...$args ) {
		if ( ! self::acquire_lock() ) {
			return new WP_Error( 'strope_scan_locked', __( 'Another scan request is running, please retry in a moment.', 'strope-translate' ), array( 'status' => 409 ) );
		}

		try {
			return call_user_func_array( $callback, $args );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Acquires the scan lock. Uses an atomic insert in the options table (bypassing the options cache).
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected static function acquire_lock(): bool {
		global $wpdb;

		// Remove a stale lock, for example after a fatal error.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d", self::LOCK_OPTION, time() - self::LOCK_TIMEOUT ) );

		return 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) time() ) );
	}

	/**
	 * Releases the scan lock.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected static function release_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );
	}

	/**
	 * Starts a new scan, the lock being held.
	 *
	 * @since 4.1.0
	 *
	 * @return array Progress.
	 */
	protected static function do_start(): array {
		Strope_String_Discovery_Store::maybe_install();

		$components = self::get_active_components();
		$queue      = array();
		$total      = 0;

		foreach ( $components as $id => $component ) {
			$files = self::list_files( $component['path'] );
			$queue[ $id ] = array(
				'path'   => $component['path'],
				'domain' => $component['domain'],
				'files'  => $files,
				'total'  => count( $files ),
			);
			$total += count( $files );
		}

		$job = array(
			'started'    => Strope_String_Discovery_Store::now(),
			'queue'      => $queue,
			'total'      => $total,
			'done'       => 0,
			'components' => $components,
		);

		delete_option( self::JOB_OPTION );
		add_option( self::JOB_OPTION, $job, '', false );

		return self::get_progress( $job );
	}

	/**
	 * Returns the current job, null if none.
	 *
	 * @since 4.1.0
	 *
	 * @return array|null
	 */
	public static function get_job() {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) && isset( $job['queue'], $job['started'] ) ? $job : null;
	}

	/**
	 * Processes the next files of the current job.
	 *
	 * @since 4.1.0
	 *
	 * @param float $time_budget Max time spent, in seconds.
	 * @return array Progress, see `self::get_progress()`.
	 */
	protected static function do_step( float $time_budget = 3.0 ): array {
		$job = self::get_job();
		if ( ! $job ) {
			return self::get_progress( null );
		}

		$start     = microtime( true );
		$processed = 0;

		foreach ( $job['queue'] as $id => &$item ) {
			$strings = array();

			while ( ! empty( $item['files'] ) ) {
				$file = array_shift( $item['files'] );
				$path = is_file( $item['path'] ) ? $item['path'] : $item['path'] . '/' . $file;

				foreach ( self::scan_file( $path, $file, $item['domain'] ) as $hash => $string ) {
					if ( isset( $strings[ $hash ] ) ) {
						$strings[ $hash ]['sources'] = array_merge( $strings[ $hash ]['sources'], $string['sources'] );
					} else {
						$strings[ $hash ] = $string;
					}
				}

				++$processed;
				++$job['done'];

				if ( microtime( true ) - $start > $time_budget ) {
					break;
				}
			}

			Strope_String_Discovery_Store::save_scanned_strings( $strings, $id, $job['started'] );

			if ( empty( $item['files'] ) ) {
				self::finish_component( $id, $job );
				unset( $job['queue'][ $id ] );
			}

			if ( microtime( true ) - $start > $time_budget ) {
				break;
			}
		}
		unset( $item );

		if ( empty( $job['queue'] ) ) {
			// Remove the strings of components which are not active anymore (only if they have no translations).
			Strope_String_Discovery_Store::delete_stale_strings( null, $job['started'], array_keys( $job['components'] ) );

			$components = self::get_components();
			foreach ( array_keys( $components ) as $id ) {
				if ( ! isset( $job['components'][ $id ] ) ) {
					unset( $components[ $id ] );
				}
			}
			self::update_components( $components );
			delete_option( self::JOB_OPTION );

			return self::get_progress( null, true );
		}

		update_option( self::JOB_OPTION, $job, false );

		return self::get_progress( $job );
	}

	/**
	 * Finalizes the scan of a component.
	 *
	 * @since 4.1.0
	 *
	 * @param string $id  Component id.
	 * @param array  $job Current job.
	 * @return void
	 */
	protected static function finish_component( string $id, array $job ) {
		Strope_String_Discovery_Store::delete_stale_strings( $id, $job['started'] );

		$components = self::get_components();
		$component  = $job['components'][ $id ];
		unset( $component['path'] );

		$component['last_scan'] = time();
		$component['files']     = $job['queue'][ $id ]['total'];
		$components[ $id ]      = $component;

		self::update_components( $components );
	}

	/**
	 * Stores the components.
	 *
	 * @since 4.1.0
	 *
	 * @param array $components Components.
	 * @return void
	 */
	protected static function update_components( array $components ) {
		if ( false === get_option( self::COMPONENTS_OPTION ) ) {
			add_option( self::COMPONENTS_OPTION, $components, '', false );
		} else {
			update_option( self::COMPONENTS_OPTION, $components, false );
		}
	}

	/**
	 * Returns the progress of a job.
	 *
	 * @since 4.1.0
	 *
	 * @param array|null $job      Job.
	 * @param bool       $finished Whether a scan just finished.
	 * @return array
	 */
	public static function get_progress( $job, bool $finished = false ): array {
		return array(
			'running'  => ! empty( $job ),
			'finished' => $finished,
			'total'    => empty( $job ) ? 0 : (int) $job['total'],
			'done'     => empty( $job ) ? 0 : (int) $job['done'],
			'current'  => empty( $job['queue'] ) ? '' : (string) $job['components'][ (string) array_key_first( $job['queue'] ) ]['name'],
		);
	}

	/**
	 * Scans a file.
	 *
	 * @since 4.1.0
	 *
	 * @param string $path           Absolute path.
	 * @param string $relative       Path relative to the component, used for the sources.
	 * @param string $default_domain Text domain of the component, used for block.json and theme.json without explicit domain.
	 * @return array Strings keyed by hash.
	 */
	public static function scan_file( string $path, string $relative, string $default_domain ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > self::MAX_FILE_SIZE ) {
			return array();
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return array();
		}

		$name = basename( $path );

		if ( 'block.json' === $name ) {
			return self::scan_json( $content, $relative, 'block', $default_domain );
		}

		if ( '.json' === substr( $name, -5 ) ) {
			return self::scan_json( $content, $relative, 'theme', $default_domain );
		}

		return self::scan_php( $content, $relative );
	}

	/**
	 * Extracts the gettext calls with literal arguments from PHP code.
	 *
	 * @since 4.1.0
	 *
	 * @param string $code     PHP code.
	 * @param string $relative File path used for the sources.
	 * @return array Strings keyed by hash.
	 */
	public static function scan_php( string $code, string $relative ): array {
		// Quick check to avoid tokenizing files without any translation.
		if ( ! preg_match( '/\b(?:__|_[enx]|_ex|_nx|_n_noop|_nx_noop|esc_(?:html|attr)_[_ex])\s*\(/', $code ) ) {
			return array();
		}

		try {
			$tokens = token_get_all( $code );
		} catch ( Throwable $e ) {
			return array();
		}

		$strings = array();
		$count   = count( $tokens );
		$ignore  = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) ) {
				continue;
			}

			$name = $tokens[ $i ][1];
			if ( T_STRING === $tokens[ $i ][0] ) {
				// Exclude methods, static methods, function declarations and namespaced functions.
				$prev = $i - 1;
				while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], $ignore, true ) ) {
					--$prev;
				}
				if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], self::get_excluded_previous_tokens(), true ) ) {
					continue;
				}
				// PHP < 8: `\__()` is a global call but `Foo\__()` is a namespaced function.
				if ( $prev >= 1 && is_array( $tokens[ $prev ] ) && T_NS_SEPARATOR === $tokens[ $prev ][0] && is_array( $tokens[ $prev - 1 ] ) && in_array( $tokens[ $prev - 1 ][0], array( T_STRING, T_NAMESPACE ), true ) ) {
					continue;
				}
			} elseif ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $tokens[ $i ][0] ) {
				$name = ltrim( $name, '\\' );
			} else {
				continue;
			}

			if ( ! isset( self::FUNCTIONS[ $name ] ) ) {
				continue;
			}

			// Next significant token must be an opening parenthesis.
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $ignore, true ) ) {
				++$j;
			}
			if ( $j >= $count || '(' !== $tokens[ $j ] ) {
				continue;
			}

			$args = self::parse_arguments( $tokens, $j, $count );
			$map  = self::FUNCTIONS[ $name ];

			$singular = isset( $args[ $map['singular'] ] ) ? $args[ $map['singular'] ] : null;
			$plural   = isset( $map['plural'] ) ? ( isset( $args[ $map['plural'] ] ) ? $args[ $map['plural'] ] : null ) : '';
			$context  = isset( $map['context'] ) ? ( isset( $args[ $map['context'] ] ) ? $args[ $map['context'] ] : null ) : '';
			$domain   = array_key_exists( $map['domain'], $args ) ? $args[ $map['domain'] ] : 'default';

			// Only literal strings are supported.
			if ( ! is_string( $singular ) || '' === $singular || ! is_string( $plural ) || ! is_string( $context ) || ! is_string( $domain ) || '' === $domain ) {
				continue;
			}

			self::add_string( $strings, $domain, $context, $singular, $plural, $relative . ':' . $tokens[ $i ][2] );
		}

		return $strings;
	}

	/**
	 * Returns the tokens which, before a function name, mean that it's not a function call.
	 *
	 * @since 4.1.0
	 *
	 * @return int[]
	 */
	protected static function get_excluded_previous_tokens(): array {
		$tokens = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$tokens[] = T_NULLSAFE_OBJECT_OPERATOR;
		}
		return $tokens;
	}

	/**
	 * Parses the arguments of a function call.
	 *
	 * @since 4.1.0
	 *
	 * @param array $tokens Tokens.
	 * @param int   $open   Index of the opening parenthesis.
	 * @param int   $count  Number of tokens.
	 * @return array Arguments: the decoded string for literal arguments, false for other arguments.
	 */
	protected static function parse_arguments( array $tokens, int $open, int $count ): array {
		$args    = array();
		$depth   = 0;
		$current = array();

		for ( $k = $open + 1; $k < $count; $k++ ) {
			$token = $tokens[ $k ];

			if ( is_string( $token ) ) {
				if ( '(' === $token || '[' === $token || '{' === $token ) {
					++$depth;
				} elseif ( ')' === $token || ']' === $token || '}' === $token ) {
					if ( 0 === $depth ) {
						if ( ! empty( $current ) ) {
							$args[] = self::evaluate_argument( $current );
						}
						return $args;
					}
					--$depth;
				} elseif ( ',' === $token && 0 === $depth ) {
					$args[]  = self::evaluate_argument( $current );
					$current = array();
					continue;
				}
			} elseif ( T_CURLY_OPEN === $token[0] || T_DOLLAR_OPEN_CURLY_BRACES === $token[0] ) {
				++$depth;
			}

			$current[] = $token;

			if ( count( $args ) > 5 ) {
				break;
			}
		}

		return $args;
	}

	/**
	 * Evaluates an argument: only literal strings (optionally concatenated) are supported.
	 *
	 * @since 4.1.0
	 *
	 * @param array $tokens Tokens of the argument.
	 * @return string|false
	 */
	protected static function evaluate_argument( array $tokens ) {
		$value    = '';
		$expect   = true; // Whether a string is expected (vs a concatenation operator).
		$heredoc  = false;
		$has_text = false;

		foreach ( $tokens as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			if ( is_array( $token ) && T_START_HEREDOC === $token[0] ) {
				if ( ! $expect ) {
					return false;
				}
				$heredoc = array( 'nowdoc' => false !== strpos( $token[1], "'" ), 'text' => '' );
				continue;
			}

			if ( false !== $heredoc ) {
				if ( is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
					$heredoc['text'] .= $token[1];
					continue;
				}
				if ( is_array( $token ) && T_END_HEREDOC === $token[0] ) {
					$text  = preg_replace( '/\r?\n$/', '', $heredoc['text'] );
					$text  = self::remove_heredoc_indentation( (string) $text, $token[1] );
					$value .= $heredoc['nowdoc'] ? $text : self::unescape_double_quoted( $text );
					$heredoc  = false;
					$expect   = false;
					$has_text = true;
					continue;
				}
				return false; // Interpolation.
			}

			if ( $expect && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$value   .= self::decode_string( $token[1] );
				$expect   = false;
				$has_text = true;
				continue;
			}

			if ( ! $expect && '.' === $token ) {
				$expect = true;
				continue;
			}

			return false;
		}

		return $has_text && ! $expect ? $value : false;
	}

	/**
	 * Removes the closing marker indentation of a flexible heredoc (PHP 7.3+).
	 *
	 * @since 4.1.0
	 *
	 * @param string $text   Heredoc text.
	 * @param string $closer Closing token.
	 * @return string
	 */
	protected static function remove_heredoc_indentation( string $text, string $closer ): string {
		if ( ! preg_match( '/^([ \t]+)/', $closer, $matches ) ) {
			return $text;
		}

		$indent = $matches[1];
		$lines  = explode( "\n", $text );
		foreach ( $lines as &$line ) {
			if ( 0 === strpos( $line, $indent ) ) {
				$line = (string) substr( $line, strlen( $indent ) );
			}
		}
		unset( $line );

		return implode( "\n", $lines );
	}

	/**
	 * Decodes a PHP string literal.
	 *
	 * @since 4.1.0
	 *
	 * @param string $literal Literal including quotes.
	 * @return string
	 */
	public static function decode_string( string $literal ): string {
		if ( strlen( $literal ) > 0 && ( 'b' === $literal[0] || 'B' === $literal[0] ) ) {
			$literal = substr( $literal, 1 );
		}

		$quote = $literal[0];
		$inner = substr( $literal, 1, -1 );

		if ( "'" === $quote ) {
			return (string) preg_replace( '/\\\\([\\\\\'])/', '$1', $inner );
		}

		return self::unescape_double_quoted( $inner );
	}

	/**
	 * Interprets the escape sequences of a double quoted string.
	 *
	 * @since 4.1.0
	 *
	 * @param string $text String content.
	 * @return string
	 */
	protected static function unescape_double_quoted( string $text ): string {
		return (string) preg_replace_callback(
			'/\\\\(?:([nrtvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
			function ( $m ) {
				if ( isset( $m[1] ) && '' !== $m[1] ) {
					$map = array(
						'n'  => "\n",
						'r'  => "\r",
						't'  => "\t",
						'v'  => "\v",
						'e'  => "\e",
						'f'  => "\f",
						'\\' => '\\',
						'$'  => '$',
						'"'  => '"',
					);
					return $map[ $m[1] ];
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return chr( octdec( $m[2] ) & 255 );
				}
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return chr( hexdec( $m[3] ) );
				}
				$code = hexdec( $m[4] );
				return function_exists( 'mb_chr' ) ? (string) mb_chr( (int) $code, 'UTF-8' ) : '';
			},
			$text
		);
	}

	/**
	 * Extracts the translatable strings from a block.json or theme.json file, using the WordPress i18n schemas.
	 *
	 * @since 4.1.0
	 *
	 * @param string $json           JSON content.
	 * @param string $relative       File path used for the sources.
	 * @param string $type           'block' or 'theme'.
	 * @param string $default_domain Default text domain.
	 * @return array Strings keyed by hash.
	 */
	public static function scan_json( string $json, string $relative, string $type, string $default_domain ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$schema_file = ABSPATH . WPINC . ( 'block' === $type ? '/block-i18n.json' : '/theme-i18n.json' );
		if ( ! is_readable( $schema_file ) ) {
			return array();
		}

		$schema = json_decode( (string) file_get_contents( $schema_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( empty( $schema ) ) {
			return array();
		}

		$domain = 'block' === $type && ! empty( $data['textdomain'] ) && is_string( $data['textdomain'] ) ? $data['textdomain'] : $default_domain;
		if ( '' === $domain ) {
			return array();
		}

		$strings = array();
		self::extract_with_schema( $strings, $schema, $data, $domain, $relative );
		return $strings;
	}

	/**
	 * Recursively extracts strings from data according to an i18n schema.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $strings  Found strings, passed by reference.
	 * @param mixed  $schema   I18n schema.
	 * @param mixed  $data     Data.
	 * @param string $domain   Text domain.
	 * @param string $relative Source file.
	 * @return void
	 */
	protected static function extract_with_schema( array &$strings, $schema, $data, string $domain, string $relative ) {
		if ( is_string( $schema ) ) {
			if ( is_string( $data ) && '' !== $data ) {
				self::add_string( $strings, $domain, $schema, $data, '', $relative );
			} elseif ( is_array( $data ) ) {
				foreach ( $data as $value ) {
					if ( is_string( $value ) && '' !== $value ) {
						self::add_string( $strings, $domain, $schema, $value, '', $relative );
					}
				}
			}
			return;
		}

		if ( is_array( $schema ) && is_array( $data ) ) {
			foreach ( $data as $value ) {
				self::extract_with_schema( $strings, $schema[0], $value, $domain, $relative );
			}
			return;
		}

		if ( is_object( $schema ) && is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( isset( $schema->$key ) ) {
					self::extract_with_schema( $strings, $schema->$key, $value, $domain, $relative );
				} elseif ( isset( $schema->{'*'} ) ) {
					self::extract_with_schema( $strings, $schema->{'*'}, $value, $domain, $relative );
				}
			}
		}
	}

	/**
	 * Adds a string to a list.
	 *
	 * @since 4.1.0
	 *
	 * @param array  $strings  Strings, passed by reference.
	 * @param string $domain   Text domain.
	 * @param string $context  Context.
	 * @param string $singular Singular.
	 * @param string $plural   Plural.
	 * @param string $source   Source.
	 * @return void
	 */
	protected static function add_string( array &$strings, string $domain, string $context, string $singular, string $plural, string $source ) {
		if ( strlen( $domain ) > 191 ) {
			return;
		}

		$hash = Strope_String_Discovery_Store::hash( $domain, $context, $singular, $plural );

		if ( isset( $strings[ $hash ] ) ) {
			$strings[ $hash ]['sources'][] = $source;
			return;
		}

		$strings[ $hash ] = array(
			'domain'   => $domain,
			'context'  => $context,
			'singular' => $singular,
			'plural'   => $plural,
			'sources'  => array( $source ),
		);
	}
}
