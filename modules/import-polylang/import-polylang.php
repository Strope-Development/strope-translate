<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Imports the data of a site previously using Polylang or Polylang Pro (version 3.4 or later).
 *
 * Both plugins share the same taxonomies to store the languages and the translation groups,
 * so most of the data is used as is. This class converts what differs: settings, secondary language terms,
 * strings translations, language switchers in menus, widgets and blocks, per language widget visibility
 * and user preferences.
 *
 * The conversion is made in place: the site must be backed up before importing.
 * As long as such data are found and not imported, Strope Translate doesn't load anything else,
 * to avoid working with incomplete data.
 *
 * @since 4.0.0
 */
class Strope_Import_Polylang {
	/**
	 * Name of the option storing the settings of the previous plugin.
	 *
	 * @var string
	 */
	const SOURCE_OPTION = 'polylang';

	/**
	 * Name of the option storing the import date.
	 *
	 * @var string
	 */
	const DONE_OPTION = 'strope_translate_imported';

	/**
	 * Name of the option storing the steps completed by an import which didn't finish.
	 *
	 * @since 4.1.0
	 *
	 * @var string
	 */
	const PROGRESS_OPTION = 'strope_translate_import_progress';

	/**
	 * Name of the option used as a lock while importing.
	 *
	 * @since 4.1.0
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'strope_translate_import_lock';

	/**
	 * Duration in seconds after which a lock is considered as left by an import which died.
	 *
	 * @since 4.1.0
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT = 3600;

	/**
	 * Admin post action.
	 *
	 * @var string
	 */
	const ACTION = 'strope_import_polylang';

	/**
	 * Oldest supported version of the previous plugin data.
	 *
	 * @var string
	 */
	const MIN_SOURCE_VERSION = '3.4';

	/**
	 * Secondary language taxonomies, whose term slugs are prefixed.
	 *
	 * @var string[]
	 */
	const LANGUAGE_TAXONOMIES = array( 'term_language' );

	/**
	 * Tells whether there are data waiting to be imported.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public static function is_pending(): bool {
		return is_array( get_option( self::SOURCE_OPTION ) ) && ! get_option( self::DONE_OPTION );
	}

	/**
	 * Loads the import interfaces, while the rest of the plugin is not loaded.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public static function load() {
		$import = new self();

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $import, 'display_notice' ) );
			add_action( 'admin_post_' . self::ACTION, array( $import, 'handle_request' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command(
				'strope import-polylang',
				array( $import, 'cli_import' ),
				array( 'shortdesc' => 'Imports the languages, translations and settings of a site previously using Polylang. Back up the database first.' )
			);
		}
	}

	/**
	 * WP-CLI command importing the data.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function cli_import() {
		$result = $this->import();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( $this->format_report( $result ) );
	}

	/**
	 * Displays the import notice or the result of the import.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function display_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['strope_imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$result = get_transient( 'strope_import_result_' . get_current_user_id() );
			delete_transient( 'strope_import_result_' . get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $result->get_error_message() ) );
			} elseif ( is_array( $result ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p><p>%s</p></div>',
					esc_html__( 'The data have been imported.', 'strope-translate' ),
					esc_html( $this->format_report( $result ) )
				);
			}
		}

		if ( ! self::is_pending() ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Strope Translate: existing multilingual data found', 'strope-translate' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Strope Translate can import the languages, translations, strings translations, settings, language switchers and widgets of your site from Polylang (version 3.4 or later). It stays inactive until the data are imported.', 'strope-translate' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'The data are converted in place: please back up your database before importing.', 'strope-translate' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><?php submit_button( __( 'Import from Polylang', 'strope-translate' ), 'primary', 'submit', false ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the import request sent from the admin notice.
	 *
	 * @since 4.0.0
	 *
	 * @return void
	 */
	public function handle_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import data.', 'strope-translate' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$result = $this->import();
		set_transient( 'strope_import_result_' . get_current_user_id(), $result, HOUR_IN_SECONDS );

		// The languages page is available only once the import is done.
		$url = is_wp_error( $result ) ? admin_url( 'index.php' ) : admin_url( 'admin.php?page=mlang' );
		wp_safe_redirect( add_query_arg( 'strope_imported', 1, $url ) );
		exit;
	}

	/**
	 * Imports the data.
	 *
	 * @since 4.0.0
	 *
	 * @return int[]|WP_Error Number of converted items per data type, an error if the import can't be done.
	 */
	public function import() {
		$source = get_option( self::SOURCE_OPTION );

		if ( ! is_array( $source ) ) {
			return new WP_Error( 'strope_import_no_data', __( 'There are no data to import.', 'strope-translate' ) );
		}

		if ( get_option( self::DONE_OPTION ) ) {
			return new WP_Error( 'strope_import_done', __( 'The data have already been imported.', 'strope-translate' ) );
		}

		if ( empty( $source['version'] ) || version_compare( $source['version'], self::MIN_SOURCE_VERSION, '<' ) ) {
			return new WP_Error(
				'strope_import_version',
				/* translators: %s is a version number. */
				sprintf( __( 'The data can only be imported from version %s or later. Please update the previous plugin, visit its settings once, then import again.', 'strope-translate' ), self::MIN_SOURCE_VERSION )
			);
		}

		// Only one import at a time: a second run in parallel (e.g. a double submission) would convert the data twice.
		if ( ! $this->lock() ) {
			return new WP_Error( 'strope_import_running', __( 'An import is already running. Please wait a few minutes before trying again.', 'strope-translate' ) );
		}

		// Read again from the database, now that the lock is acquired, in case another import has just finished.
		if ( $this->get_option_from_db( self::DONE_OPTION ) ) {
			$this->unlock();
			return new WP_Error( 'strope_import_done', __( 'The data have already been imported.', 'strope-translate' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$steps = array(
			'settings'      => function () use ( $source ) {
				return $this->import_settings( $source );
			},
			'languages'     => function () {
				return $this->import_language_terms();
			},
			'strings'       => function () {
				return $this->rename_meta_key( 'termmeta', '_pll_strings_translations', '_strope_strings_translations' );
			},
			'menu_items'    => function () {
				return $this->import_menu_items();
			},
			'blocks'        => function () {
				return $this->import_blocks();
			},
			'widgets'       => function () {
				return $this->import_widgets();
			},
			'user_settings' => function () {
				return $this->import_user_meta();
			},
		);

		/*
		 * Steps completed by a previous interrupted import are not run again,
		 * e.g. to avoid overwriting the settings modified since then.
		 */
		$progress = $this->get_option_from_db( self::PROGRESS_OPTION );
		$progress = is_array( $progress ) ? $progress : array();
		$report   = array();

		try {
			foreach ( $steps as $step => $callback ) {
				if ( array_key_exists( $step, $progress ) ) {
					$report[ $step ] = (int) $progress[ $step ];
					continue;
				}

				$report[ $step ]   = (int) $callback();
				$progress[ $step ] = $report[ $step ];
				update_option( self::PROGRESS_OPTION, $progress, false );
			}

			update_option( self::DONE_OPTION, time(), false );
			delete_option( self::PROGRESS_OPTION );
		} finally {
			$this->unlock();
		}

		// Clean caches.
		delete_transient( 'strope_languages_list' );
		delete_transient( 'strope_activation_redirect' ); // No setup wizard for an already configured site.
		delete_transient( 'pll_languages_list' );
		delete_transient( 'pll_translated_slugs' );
		delete_option( 'rewrite_rules' );
		wp_cache_flush();

		return $report;
	}

	/**
	 * Imports the settings.
	 *
	 * @since 4.0.0
	 *
	 * @param array $source Settings of the previous plugin.
	 * @return int Number of imported options.
	 */
	protected function import_settings( array $source ): int {
		$options            = wp_parse_args( $source, Strope_Install::get_default_options() );
		$options['version'] = STROPE_TRANSLATE_DATA_VERSION; // The data are converted to the current format.
		$options['legacy_api'] = 1; // Themes and plugins may call the functions of the previous plugin.

		// Machine translation isn't part of Strope Translate.
		unset( $options['machine_translation_enabled'], $options['machine_translation_services'] );

		update_option( 'strope_translate', $options );
		$count = 1;

		$copies = array(
			'polylang_wpml_strings' => 'strope_translate_wpml_strings', // Strings registered with the WPML API.
			'pll_dismissed_notices' => 'strope_dismissed_notices',
		);

		foreach ( $copies as $from => $to ) {
			$value = get_option( $from );
			if ( ! empty( $value ) && empty( get_option( $to ) ) ) {
				update_option( $to, $value );
				++$count;
			}
		}

		// The site is already configured: no need to suggest the setup wizard.
		$dismissed = (array) get_option( 'strope_dismissed_notices', array() );
		update_option( 'strope_dismissed_notices', array_values( array_unique( array_merge( $dismissed, array( 'wizard' ) ) ) ) );

		return $count;
	}

	/**
	 * Converts the slugs of the secondary language terms (`pll_en` to `strope_en` in the `term_language` taxonomy).
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of converted terms.
	 */
	protected function import_language_terms(): int {
		global $wpdb;

		$taxonomies   = self::LANGUAGE_TAXONOMIES;
		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		/*
		 * Remove unused terms which may have been created with the new prefix, and would conflict with the converted ones.
		 * Since 4.1.0, only the terms having a counterpart still to convert are concerned, so that the terms
		 * converted by a previous run are never deleted.
		 */
		$duplicates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				INNER JOIN {$wpdb->term_taxonomy} AS tt2 ON tt2.taxonomy = tt.taxonomy
				INNER JOIN {$wpdb->terms} AS t2 ON t2.term_id = tt2.term_id AND t2.slug = CONCAT( 'pll_', SUBSTRING( t.slug, 8 ) )
				WHERE tt.taxonomy IN ({$placeholders}) AND tt.count = 0 AND t.slug LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $taxonomies, array( $wpdb->esc_like( 'strope_' ) . '%' ) )
			)
		);

		foreach ( $duplicates as $duplicate ) {
			wp_delete_term( (int) $duplicate->term_id, $duplicate->taxonomy );
		}

		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
				SET t.slug = CONCAT( 'strope_', SUBSTRING( t.slug, 5 ) )
				WHERE tt.taxonomy IN ({$placeholders}) AND t.slug LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $taxonomies, array( $wpdb->esc_like( 'pll_' ) . '%' ) )
			)
		);

		foreach ( $taxonomies as $taxonomy ) {
			clean_taxonomy_cache( $taxonomy );
		}

		return (int) $count;
	}

	/**
	 * Converts the language switchers in classic menus.
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of converted menu items.
	 */
	protected function import_menu_items(): int {
		global $wpdb;

		$count = $this->rename_meta_key( 'postmeta', '_pll_menu_item', '_strope_menu_item' );

		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => '#strope_switcher' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			array(
				'meta_key'   => '_menu_item_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '#pll_switcher', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $count;
	}

	/**
	 * Converts the language switcher blocks stored in posts, templates, template parts, patterns and navigations.
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of converted posts.
	 */
	protected function import_blocks(): int {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, 'wp:polylang/', 'wp:strope-translate/' ) WHERE post_content LIKE %s",
				'%' . $wpdb->esc_like( 'wp:polylang/' ) . '%'
			)
		);
	}

	/**
	 * Converts the language switcher widgets and the language visibility of the widgets and widget blocks.
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of converted widgets.
	 */
	protected function import_widgets(): int {
		global $wpdb;

		$count        = 0;
		$option_names = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'widget_' ) . '%' )
		);

		foreach ( $option_names as $option_name ) {
			$widgets = get_option( $option_name );

			if ( ! is_array( $widgets ) ) {
				continue;
			}

			$changed = false;

			foreach ( $widgets as $number => $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$original = $instance;

				// Language visibility of classic widgets.
				if ( array_key_exists( 'pll_lang', $instance ) ) {
					$instance['strope_lang'] = $instance['pll_lang'];
					unset( $instance['pll_lang'] );
				}

				// Widget blocks: language visibility attribute, language switcher blocks and legacy widgets.
				if ( isset( $instance['content'] ) && is_string( $instance['content'] ) ) {
					$instance['content'] = str_replace(
						array( '"pll_lang":', 'wp:polylang/', '"idBase":"polylang"' ),
						array( '"strope_lang":', 'wp:strope-translate/', '"idBase":"strope_translate"' ),
						$instance['content']
					);
				}

				if ( $instance !== $original ) {
					$widgets[ $number ] = $instance;
					$changed            = true;
					++$count;
				}
			}

			if ( $changed ) {
				update_option( $option_name, $widgets );
			}
		}

		// Language switcher widgets: their settings and their position in sidebars.
		$switchers = get_option( 'widget_polylang' );

		if ( is_array( $switchers ) && false === get_option( 'widget_strope_translate' ) ) {
			update_option( 'widget_strope_translate', $switchers );
			$count += count( array_filter( $switchers, 'is_array' ) );
		}

		$sidebars = get_option( 'sidebars_widgets' );

		if ( is_array( $sidebars ) ) {
			foreach ( $sidebars as $sidebar => $widget_ids ) {
				if ( is_array( $widget_ids ) ) {
					$sidebars[ $sidebar ] = preg_replace( '/^polylang-(\d+)$/', 'strope_translate-$1', $widget_ids );
				}
			}
			update_option( 'sidebars_widgets', $sidebars );
		}

		return $count;
	}

	/**
	 * Converts the user preferences (content language filter, duplication toggles, screen options...).
	 *
	 * @since 4.0.0
	 *
	 * @return int Number of converted user metas.
	 */
	protected function import_user_meta(): int {
		global $wpdb;

		$keys = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'pll_' ) . '%' )
		);

		$count = 0;

		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, 'pll_machine_translation' ) ) {
				continue; // Machine translation isn't part of Strope Translate.
			}

			$count += $this->rename_meta_key( 'usermeta', $key, 'strope_' . substr( $key, strlen( 'pll_' ) ) );
		}

		return $count;
	}

	/**
	 * Renames a meta key, unless the object already has a meta with the new key.
	 *
	 * @since 4.0.0
	 *
	 * @param string $table Meta table name without prefix: 'postmeta', 'termmeta' or 'usermeta'.
	 * @param string $from  Old meta key.
	 * @param string $to    New meta key.
	 * @return int Number of renamed metas.
	 */
	protected function rename_meta_key( string $table, string $from, string $to ): int {
		global $wpdb;

		$columns = array(
			'postmeta' => 'post_id',
			'termmeta' => 'term_id',
			'usermeta' => 'user_id',
		);

		if ( ! isset( $columns[ $table ] ) ) {
			return 0;
		}

		$column = $columns[ $table ];
		$table  = $wpdb->$table;

		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} AS m1 LEFT JOIN {$table} AS m2 ON m2.{$column} = m1.{$column} AND m2.meta_key = %s
				SET m1.meta_key = %s
				WHERE m1.meta_key = %s AND m2.meta_key IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$to,
				$to,
				$from
			)
		);

		wp_cache_flush();

		return (int) $count;
	}

	/**
	 * Acquires the import lock.
	 * Inspired by `WP_Upgrader::create_lock()`: the lock is an option created atomically.
	 *
	 * @since 4.1.0
	 *
	 * @return bool True if the lock is acquired, false if another import holds it.
	 */
	protected function lock(): bool {
		global $wpdb;

		$acquired = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				self::LOCK_OPTION,
				time()
			)
		);

		if ( ! $acquired ) {
			$locked_at = (int) $this->get_option_from_db( self::LOCK_OPTION );

			if ( $locked_at > time() - self::LOCK_TIMEOUT ) {
				return false;
			}

			// The lock has been left by an import which died: take it over, unless another request just did it.
			$acquired = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					time(),
					self::LOCK_OPTION,
					$locked_at
				)
			);
		}

		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return (bool) $acquired;
	}

	/**
	 * Releases the import lock.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	protected function unlock() {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/**
	 * Reads an option directly from the database, bypassing the caches which may be outdated
	 * when several requests run at the same time.
	 *
	 * @since 4.1.0
	 *
	 * @param string $name Option name.
	 * @return mixed The option value, false if the option doesn't exist.
	 */
	protected function get_option_from_db( string $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? false : maybe_unserialize( $value );
	}

	/**
	 * Formats the import report.
	 *
	 * @since 4.0.0
	 *
	 * @param int[] $report Number of converted items per data type.
	 * @return string
	 */
	public function format_report( array $report ): string {
		$labels = array(
			/* translators: %d: number of settings. */
			'settings'      => __( 'Settings: %d', 'strope-translate' ),
			/* translators: %d: number of languages. */
			'languages'     => __( 'Languages: %d', 'strope-translate' ),
			/* translators: %d: number of string translations. */
			'strings'       => __( 'Strings translations: %d', 'strope-translate' ),
			/* translators: %d: number of language switchers in menus. */
			'menu_items'    => __( 'Menu language switchers: %d', 'strope-translate' ),
			/* translators: %d: number of posts with language switcher blocks. */
			'blocks'        => __( 'Contents with language switcher blocks: %d', 'strope-translate' ),
			/* translators: %d: number of widgets. */
			'widgets'       => __( 'Widgets: %d', 'strope-translate' ),
			/* translators: %d: number of user preferences. */
			'user_settings' => __( 'User preferences: %d', 'strope-translate' ),
		);

		$lines = array();
		foreach ( $labels as $key => $label ) {
			if ( isset( $report[ $key ] ) ) {
				$lines[] = sprintf( $label, (int) $report[ $key ] );
			}
		}

		return implode( ' – ', $lines );
	}
}
