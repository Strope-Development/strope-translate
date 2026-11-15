<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the files of the translation engine: the Bergamot WebAssembly binary, shipped with the plugin,
 * and the language models published by Mozilla for Firefox Translations (Remote Settings collection
 * `translations-models-v2`, MPL-2.0).
 *
 * The models are downloaded once by the server into the uploads directory, checked against the SHA-256
 * hashes of the index, and served from the site to the browser workers of the editors. Nothing else is
 * ever sent to Mozilla: only the public index and model files are fetched.
 *
 * @since 4.2.0
 */
class Strope_MT_Engine_Files {
	/**
	 * Remote Settings server and the only host allowed for the attachments.
	 */
	const SERVER          = 'https://firefox.settings.services.mozilla.com/v1';
	const ATTACHMENT_HOST = 'firefox-settings-attachments.cdn.mozilla.net';

	/**
	 * Collection of the language models, as used by Firefox.
	 */
	const MODELS_COLLECTION = 'translations-models-v2';

	/**
	 * Engine compatibility: the bundled `bergamot-translator.js` loader and WebAssembly binary are the v0.6.0
	 * release, which pairs with the 3.x language models.
	 */
	const ENGINE_RELEASE      = 'v0.6.0';
	const MODEL_MAJOR_VERSION = 3;

	/**
	 * The WebAssembly binary of the engine, shipped with the plugin next to its JavaScript loader: the record
	 * `bergamot-translator` 4.0 (release v0.6.0) of the Firefox collection `translations-wasm-v2`,
	 * decompressed (WordPress.org does not accept compressed files in plugins). The hash is SHA-256. See
	 * `vendor/bergamot-translator/README.md` to update it.
	 *
	 * @since 4.3.0
	 */
	const ENGINE_FILE = array(
		'name'    => 'bergamot-translator',
		'version' => '4.0',
		'file'    => 'bergamot-translator.wasm',
		'hash'    => 'f38ef807636a7c994afedaab7ff8ffe0d590d21897f05139f747b80fd7bbe926',
		'size'    => 4960506,
	);

	/**
	 * Options.
	 */
	const INDEX_OPTION     = 'strope_mt_index';
	const INSTALLED_OPTION = 'strope_mt_installed';

	/**
	 * How long the index is kept before being fetched again.
	 */
	const INDEX_TTL = DAY_IN_SECONDS;

	/**
	 * Pivot language: the models translate from and into English.
	 */
	const PIVOT = 'en';

	/**
	 * Locales whose engine code is not the language part of the locale.
	 *
	 * @var string[]
	 */
	const LOCALE_CODES = array(
		'zh_CN' => 'zh-Hans',
		'zh_SG' => 'zh-Hans',
		'zh_TW' => 'zh-Hant',
		'zh_HK' => 'zh-Hant',
		'nb_NO' => 'nb',
		'nn_NO' => 'nn',
		'fil'   => 'tl',
	);

	/**
	 * Returns the engine language code of a language, e.g. `nl` for `nl_NL`.
	 *
	 * @since 4.2.0
	 *
	 * @param Strope_Language $language Language.
	 * @return string
	 */
	public static function get_code( $language ): string {
		$locale = (string) $language->locale;

		if ( isset( self::LOCALE_CODES[ $locale ] ) ) {
			$code = self::LOCALE_CODES[ $locale ];
		} elseif ( 0 === strpos( $locale, 'zh' ) ) {
			$code = 'zh-Hans';
		} else {
			$code = strtolower( (string) strtok( $locale, '_' ) );
		}

		/**
		 * Filters the code of a language for the translation engine.
		 *
		 * @since 4.2.0
		 *
		 * @param string          $code     Engine language code, e.g. `nl`.
		 * @param Strope_Language $language The language.
		 */
		return (string) apply_filters( 'strope_mt_language_code', $code, $language );
	}

	/**
	 * Returns the route between two engine codes: `nl-en` (one model) or `nl-en-de` (through English).
	 *
	 * @since 4.2.0
	 *
	 * @param string $from Source code.
	 * @param string $to   Target code.
	 * @return string Empty if the languages are the same.
	 */
	public static function get_route( string $from, string $to ): string {
		if ( $from === $to ) {
			return '';
		}

		if ( self::PIVOT === $from || self::PIVOT === $to ) {
			return "$from-$to";
		}

		return "$from-" . self::PIVOT . "-$to";
	}

	/**
	 * Returns the model directions (e.g. `nl-en`) needed by a route.
	 *
	 * @since 4.2.0
	 *
	 * @param string $route Route.
	 * @return string[]
	 */
	public static function get_route_directions( string $route ): array {
		$codes = self::split_route( $route );
		$out   = array();

		for ( $i = 0; $i < count( $codes ) - 1; $i++ ) {
			$out[] = $codes[ $i ] . '-' . $codes[ $i + 1 ];
		}

		return $out;
	}

	/**
	 * Splits a route into language codes. Codes may contain a dash (`zh-Hans`).
	 *
	 * @since 4.2.0
	 *
	 * @param string $route Route.
	 * @return string[]
	 */
	public static function split_route( string $route ): array {
		preg_match_all( '/zh-Han[st]|[a-z]{2,3}/', $route, $matches );
		return $matches[0];
	}

	/**
	 * Returns the directions needed by the languages of the site, with their availability.
	 *
	 * @since 4.2.0
	 *
	 * @return array[] Keyed by direction, each with keys `from`, `to` (language names), `available`, `installed`, `size`.
	 */
	public static function get_site_directions(): array {
		$languages = Strope()->model->get_languages_list();
		$index     = self::get_index();
		$installed = self::get_installed();
		$names     = array();
		$out       = array();

		foreach ( $languages as $language ) {
			$names[ self::get_code( $language ) ] = $language->name;
		}

		foreach ( $languages as $source ) {
			foreach ( $languages as $target ) {
				$route = self::get_route( self::get_code( $source ), self::get_code( $target ) );
				if ( '' === $route ) {
					continue;
				}

				foreach ( self::get_route_directions( $route ) as $direction ) {
					if ( isset( $out[ $direction ] ) ) {
						continue;
					}

					list( $from, $to ) = self::split_direction( $direction );
					$size              = 0;
					foreach ( (array) ( $index['models'][ $direction ]['files'] ?? array() ) as $file ) {
						$size += (int) $file['size'];
					}

					$out[ $direction ] = array(
						'from'      => $names[ $from ] ?? $from,
						'to'        => $names[ $to ] ?? $to,
						'available' => isset( $index['models'][ $direction ] ),
						'installed' => isset( $installed['models'][ $direction ] ),
						'outdated'  => isset( $installed['models'][ $direction ], $index['models'][ $direction ] ) && $installed['models'][ $direction ]['version'] !== $index['models'][ $direction ]['version'],
						'size'      => $size,
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Splits a direction into its two codes.
	 *
	 * @since 4.2.0
	 *
	 * @param string $direction Direction, e.g. `nl-en`.
	 * @return string[]
	 */
	public static function split_direction( string $direction ): array {
		$codes = self::split_route( $direction );
		return array( $codes[0] ?? '', $codes[1] ?? '' );
	}

	/**
	 * Tells whether a route can be translated with the installed files.
	 *
	 * @since 4.2.0
	 *
	 * @param string $route Route.
	 * @return bool
	 */
	public static function is_route_installed( string $route ): bool {
		$installed = self::get_installed();

		if ( empty( $installed['wasm'] ) || '' === $route ) {
			return false;
		}

		foreach ( self::get_route_directions( $route ) as $direction ) {
			if ( empty( $installed['models'][ $direction ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the installed files.
	 *
	 * @since 4.2.0
	 * @since 4.3.0 `wasm` is always the engine shipped with the plugin, `self::ENGINE_FILE`.
	 *
	 * @return array {
	 *     @type array $wasm   File descriptor of the engine, `self::ENGINE_FILE`.
	 *     @type array $models Keyed by direction, each with `version`, `architecture` and `files` keyed by file type.
	 * }
	 */
	public static function get_installed(): array {
		$installed = get_option( self::INSTALLED_OPTION );
		$installed = is_array( $installed ) ? $installed : array();

		// The engine is shipped with the plugin since 1.0.0: only the models are installed.
		return array_merge( array( 'models' => array() ), $installed, array( 'wasm' => self::ENGINE_FILE ) );
	}

	/**
	 * Returns the index of the available files, fetched from Remote Settings when missing or old.
	 *
	 * @since 4.2.0
	 *
	 * @param bool $refresh Whether to fetch the index even if it is recent.
	 * @return array|WP_Error {
	 *     @type int   $fetched Timestamp.
	 *     @type array $models  Keyed by direction.
	 * }
	 */
	public static function get_index( bool $refresh = false ) {
		$index = get_option( self::INDEX_OPTION );

		if ( ! $refresh && is_array( $index ) && ! empty( $index['fetched'] ) && time() - (int) $index['fetched'] < self::INDEX_TTL ) {
			return $index;
		}

		$fresh = self::fetch_index();

		if ( is_wp_error( $fresh ) ) {
			// Keep using the previous index if any.
			return is_array( $index ) && ! empty( $index['models'] ) ? $index : $fresh;
		}

		update_option( self::INDEX_OPTION, $fresh, false );
		return $fresh;
	}

	/**
	 * Fetches the index from Remote Settings.
	 *
	 * @since 4.2.0
	 *
	 * @return array|WP_Error
	 */
	protected static function fetch_index() {
		$model_records = self::fetch_collection( self::MODELS_COLLECTION );
		if ( is_wp_error( $model_records ) ) {
			return $model_records;
		}

		// Models: for each direction and file type, the most recent compatible record.
		$best = array();
		foreach ( $model_records as $record ) {
			if ( ! self::accepts( $record, self::MODEL_MAJOR_VERSION ) || empty( $record['sourceLanguage'] ) || empty( $record['targetLanguage'] ) || empty( $record['fileType'] ) ) {
				continue;
			}

			$direction = $record['sourceLanguage'] . '-' . $record['targetLanguage'];
			$type      = (string) $record['fileType'];

			if ( ! in_array( $type, array( 'model', 'lex', 'vocab', 'srcvocab', 'trgvocab' ), true ) ) {
				continue;
			}

			if ( ! isset( $best[ $direction ][ $type ] ) || self::compare_versions( $record['version'], $best[ $direction ][ $type ]['version'] ) > 0 ) {
				$best[ $direction ][ $type ] = $record;
			}
		}

		$models = array();
		foreach ( $best as $direction => $records ) {
			// A direction needs a model, a shortlist and either one vocabulary or a pair of them, from the same version.
			$version = $records['model']['version'] ?? '';
			$types   = isset( $records['vocab'] ) ? array( 'model', 'lex', 'vocab' ) : array( 'model', 'lex', 'srcvocab', 'trgvocab' );
			$files   = array();

			foreach ( $types as $type ) {
				if ( empty( $records[ $type ] ) || $records[ $type ]['version'] !== $version ) {
					continue 2;
				}
				$files[ $type ] = self::describe_record( $records[ $type ] );
			}

			$models[ $direction ] = array(
				'version'      => $version,
				'architecture' => (string) ( $records['model']['architecture'] ?? '' ),
				'files'        => $files,
			);
		}

		ksort( $models );

		return array(
			'fetched' => time(),
			'models'  => $models,
		);
	}

	/**
	 * Fetches the records of a collection.
	 *
	 * @since 4.2.0
	 *
	 * @param string $collection Collection name.
	 * @return array[]|WP_Error
	 */
	protected static function fetch_collection( string $collection ) {
		$response = wp_safe_remote_get(
			self::SERVER . "/buckets/main/collections/$collection/changeset?_expected=0",
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['changes'] ) || ! is_array( $data['changes'] ) ) {
			return new WP_Error( 'strope_mt_index', __( 'The Firefox Translations index could not be read.', 'strope-translate' ) );
		}

		return $data['changes'];
	}

	/**
	 * Tells whether a record is usable: compatible major version, not a pre-release, and meant for desktop browsers.
	 *
	 * @since 4.2.0
	 *
	 * @param array $record Record.
	 * @param int   $major  Supported major version.
	 * @return bool
	 */
	protected static function accepts( array $record, int $major ): bool {
		$version = (string) ( $record['version'] ?? '' );

		if ( ! preg_match( '/^(\d+)\.\d+$/', $version, $matches ) || (int) $matches[1] !== $major ) {
			return false; // Wrong major version, or alpha/beta.
		}

		if ( empty( $record['attachment']['location'] ) || empty( $record['attachment']['hash'] ) ) {
			return false;
		}

		$filter = trim( (string) ( $record['filter_expression'] ?? '' ) );

		return '' === $filter || "env.appinfo.OS != 'Android'" === $filter;
	}

	/**
	 * Compares two `major.minor` versions.
	 *
	 * @since 4.2.0
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 * @return int
	 */
	protected static function compare_versions( string $a, string $b ): int {
		return version_compare( $a, $b );
	}

	/**
	 * Returns the part of a record needed to download and check a file.
	 *
	 * @since 4.2.0
	 *
	 * @param array $record Record.
	 * @return array
	 */
	protected static function describe_record( array $record ): array {
		return array(
			'id'                => (string) $record['id'],
			'version'           => (string) $record['version'],
			'name'              => (string) ( $record['name'] ?? '' ),
			'location'          => (string) $record['attachment']['location'],
			'hash'              => strtolower( (string) $record['attachment']['hash'] ),
			'size'              => (int) $record['attachment']['size'],
			'decompressed_hash' => strtolower( (string) ( $record['decompressedHash'] ?? '' ) ),
			'decompressed_size' => (int) ( $record['decompressedSize'] ?? 0 ),
		);
	}

	/**
	 * Returns the directory where the files are stored.
	 *
	 * @since 4.2.0
	 *
	 * @return array {
	 *     @type string $path Absolute path, without trailing slash.
	 *     @type string $url  URL, without trailing slash.
	 * }
	 */
	public static function get_storage(): array {
		$uploads = wp_get_upload_dir();

		return array(
			'path' => untrailingslashit( $uploads['basedir'] ) . '/strope-translate/models',
			'url'  => untrailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . '/strope-translate/models',
		);
	}

	/**
	 * Returns the stored file name of a descriptor: its hash, so that a new version never reuses a cached URL.
	 *
	 * @since 4.2.0
	 *
	 * @param array $file File descriptor.
	 * @return string
	 */
	protected static function file_name( array $file ): string {
		return $file['hash'] . '.zst';
	}

	/**
	 * Downloads the next missing file needed by a direction. Called repeatedly by the
	 * admin page so that each request downloads one file only.
	 *
	 * @since 4.2.0
	 *
	 * @param string $direction Direction, e.g. `nl-en`.
	 * @return array|WP_Error {
	 *     @type bool $complete   True when everything is installed.
	 *     @type int  $done_bytes Bytes installed so far for this direction.
	 *     @type int  $total      Total bytes.
	 * }
	 */
	public static function install_step( string $direction ) {
		$index = self::get_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}

		if ( empty( $index['models'][ $direction ] ) ) {
			return new WP_Error( 'strope_mt_unknown_direction', __( 'This language pair is not available.', 'strope-translate' ) );
		}

		$installed = self::get_installed();
		$entry     = $index['models'][ $direction ];
		$queue     = $entry['files'];
		$total     = 0;
		$done      = 0;
		$next      = null;

		foreach ( $queue as $type => $file ) {
			$total += (int) $file['size'];

			if ( self::is_file_present( $file ) ) {
				$done += (int) $file['size'];
			} elseif ( null === $next ) {
				$next = array( $type, $file );
			}
		}

		if ( null !== $next ) {
			list( $type, $file ) = $next;
			$result              = self::download( $file );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$done += (int) $file['size'];
		}

		$complete = $done >= $total;

		if ( $complete ) {
			$installed['models'][ $direction ] = $entry;
			update_option( self::INSTALLED_OPTION, $installed, false );

			// Files of a previous version of the engine or of this model.
			self::delete_unused_files();
		}

		return array(
			'complete'   => $complete,
			'done_bytes' => $done,
			'total'      => $total,
		);
	}

	/**
	 * Removes the files of a direction.
	 *
	 * @since 4.2.0
	 *
	 * @param string $direction Direction.
	 * @return void
	 */
	public static function uninstall( string $direction ) {
		$installed = self::get_installed();
		unset( $installed['models'][ $direction ] );

		if ( empty( $installed['models'] ) ) {
			$installed['wasm'] = array();
		}

		update_option( self::INSTALLED_OPTION, $installed, false );
		self::delete_unused_files();
	}

	/**
	 * Deletes the stored files no longer used by an installed direction.
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function delete_unused_files() {
		$installed = self::get_installed();
		$keep      = array();

		foreach ( $installed['models'] as $model ) {
			foreach ( $model['files'] as $file ) {
				$keep[] = self::file_name( $file );
			}
		}

		$storage = self::get_storage();
		foreach ( (array) glob( $storage['path'] . '/*.zst' ) as $path ) {
			if ( ! in_array( basename( (string) $path ), $keep, true ) ) {
				wp_delete_file( (string) $path );
			}
		}
	}

	/**
	 * Deletes all the files (uninstall).
	 *
	 * @since 4.2.0
	 *
	 * @return void
	 */
	public static function delete_all_files() {
		$storage = self::get_storage();

		foreach ( (array) glob( $storage['path'] . '/*' ) as $path ) {
			wp_delete_file( (string) $path );
		}

		@rmdir( $storage['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Tells whether a file is stored and complete.
	 *
	 * @since 4.2.0
	 *
	 * @param array $file File descriptor.
	 * @return bool
	 */
	protected static function is_file_present( array $file ): bool {
		$storage = self::get_storage();
		$path    = $storage['path'] . '/' . self::file_name( $file );

		return is_file( $path ) && filesize( $path ) === (int) $file['size'];
	}

	/**
	 * Downloads a file and checks its hash.
	 *
	 * @since 4.2.0
	 *
	 * @param array $file File descriptor.
	 * @return true|WP_Error
	 */
	protected static function download( array $file ) {
		$storage = self::get_storage();

		if ( ! wp_mkdir_p( $storage['path'] ) ) {
			return new WP_Error( 'strope_mt_storage', __( 'The folder for the translation models could not be created in the uploads directory.', 'strope-translate' ) );
		}

		if ( ! is_file( $storage['path'] . '/index.php' ) ) {
			file_put_contents( $storage['path'] . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $file['hash'] ) || ! preg_match( '#^[A-Za-z0-9/_.-]+$#', $file['location'] ) || false !== strpos( $file['location'], '..' ) ) {
			return new WP_Error( 'strope_mt_bad_file', __( 'Invalid file in the translations index.', 'strope-translate' ) );
		}

		$url  = 'https://' . self::ATTACHMENT_HOST . '/' . ltrim( $file['location'], '/' );
		$tmp  = $storage['path'] . '/' . self::file_name( $file ) . '.part';
		$dest = $storage['path'] . '/' . self::file_name( $file );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'  => 600,
				'stream'   => true,
				'filename' => $tmp,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'strope_mt_download', __( 'A translation model file could not be downloaded. Please try again.', 'strope-translate' ) );
		}

		if ( ! hash_equals( $file['hash'], (string) hash_file( 'sha256', $tmp ) ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'strope_mt_hash', __( 'A downloaded translation model file was damaged (its checksum does not match). Please try again.', 'strope-translate' ) );
		}

		if ( ! rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return new WP_Error( 'strope_mt_storage', __( 'A translation model file could not be saved.', 'strope-translate' ) );
		}

		return true;
	}

	/**
	 * Returns what a browser worker needs to load the engine and the installed models.
	 *
	 * @since 4.2.0
	 *
	 * @return array
	 */
	public static function get_worker_manifest(): array {
		$installed = self::get_installed();
		$storage   = self::get_storage();
		$out       = array(
			'release'    => self::ENGINE_RELEASE,
			'wasm'       => null,
			'directions' => array(),
		);

		$describe = function ( array $file ) use ( $storage ) {
			return array(
				'url'               => $storage['url'] . '/' . self::file_name( $file ),
				'name'              => (string) $file['name'],
				'size'              => (int) $file['size'],
				'decompressed_hash' => (string) $file['decompressed_hash'],
				'decompressed_size' => (int) $file['decompressed_size'],
			);
		};

		// The engine is a file of the plugin; its hash in the URL never lets a browser reuse another version.
		// It is not compressed, so its content is checked against the hash of the file itself.
		$out['wasm'] = array(
			'url'               => plugins_url( 'vendor/bergamot-translator/' . self::ENGINE_FILE['file'], __FILE__ ) . '?ver=' . substr( self::ENGINE_FILE['hash'], 0, 12 ),
			'name'              => self::ENGINE_FILE['name'],
			'size'              => self::ENGINE_FILE['size'],
			'compressed'        => false,
			'decompressed_hash' => self::ENGINE_FILE['hash'],
			'decompressed_size' => self::ENGINE_FILE['size'],
		);

		foreach ( $installed['models'] as $direction => $model ) {
			$files = array();
			foreach ( $model['files'] as $type => $file ) {
				$files[ $type ] = $describe( $file );
			}
			$out['directions'][ $direction ] = array(
				'version' => $model['version'],
				'files'   => $files,
			);
		}

		return $out;
	}

	/**
	 * Returns a short identifier of the models used by a route, part of the translation memory keys so
	 * that an updated model does not reuse the output of the previous one.
	 *
	 * @since 4.2.0
	 *
	 * @param string $route Route.
	 * @return string
	 */
	public static function get_route_fingerprint( string $route ): string {
		$installed = self::get_installed();
		$parts     = array( self::ENGINE_RELEASE );

		foreach ( self::get_route_directions( $route ) as $direction ) {
			$parts[] = $direction . ':' . substr( (string) ( $installed['models'][ $direction ]['files']['model']['hash'] ?? '' ), 0, 12 );
		}

		return implode( '|', $parts );
	}
}
