<?php
/**
 * Export actions class file
 *
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class that handles export actions
 *
 * @file
 *
 * @since 3.3
 */
class Strope_Export_Bulk_Option extends Strope_Bulk_Translate_Option {

	/**
	 * @var Strope_Export_Download
	 */
	private $downloader;

	/**
	 * Strope_Export_Bulk_Option constructor.
	 *
	 * @since 3.3
	 * @since 3.6 Added parameter `$downloader`.
	 *
	 * @param Strope_Model           $model      Used to query languages and post translations.
	 * @param Strope_Export_Download $downloader Instance of the downloader.
	 */
	public function __construct( Strope_Model $model, Strope_Export_Download $downloader ) {
		parent::__construct(
			array(
				'name'        => 'strope_export_post',
				'description' => __( 'Export selected content into a file', 'strope-translate' ),
				'priority'    => 15,
			),
			$model
		);

		$this->downloader = $downloader;
	}

	/**
	 * Displays the input bulk option in the bulk translate form.
	 *
	 * @since 3.6
	 *
	 * @param string $selected The selected option name.
	 * @return void
	 */
	public function display( string $selected ) {
		parent::display( $selected );
		$supported_formats = ( new Strope_File_Format_Factory() )->get_supported_formats( 'posts' );
		if ( empty( $supported_formats ) ) {
			return;
		}
		include __DIR__ . '/view-export-file-format.php';
	}

	/**
	 * Defines wether the export Bulk Translate option is available given the admin panel and user logged.
	 * Do not add the 'strope_export_post' bulk translate option if LIBXML extension is not loaded, no matter the screen.
	 *
	 * @since 3.3
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! extension_loaded( 'libxml' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( empty( $screen ) ) {
			return false;
		}

		switch ( $screen->base ) {
			case 'edit':
			case 'upload':
				$post_type_object = get_post_type_object( $screen->post_type );

				if ( empty( $post_type_object ) ) {
					return false;
				}

				$capability = $post_type_object->cap->edit_posts;
				break;
			default:
				return false;
		}

		return current_user_can( $capability );
	}


	/**
	 *
	 * Export post content for converter.
	 *
	 * @since 3.3
	 * @since 3.6 Returns a WP_Error instead of an array.
	 *
	 * @param int[]    $post_ids         The ids of the posts selected for export.
	 * @param string[] $target_languages The target languages.
	 * @return WP_Error Notices to be displayed to the user when something wrong occurs when exporting.
	 *
	 * @phpstan-param non-empty-array<int<1,max>> $post_ids
	 * @phpstan-param non-empty-array<string> $target_languages
	 */
	public function do_bulk_action( $post_ids, $target_languages ): WP_Error {
		check_admin_referer( 'strope_translate', '_strope_translate_nonce' );

		$file_format_factory = new Strope_File_Format_Factory();
		$filetype            = ! empty( $_GET['filetype'] ) ? sanitize_key( $_GET['filetype'] ) : '';
		$filetype            = $file_format_factory->split_filetype( $filetype );
		$file_format         = $file_format_factory->from_extension( $filetype['extension'] );

		if ( is_wp_error( $file_format ) ) {
			return $file_format;
		}

		/** @var array<Strope_Language|false> */
		$target_languages = array_combine(
			$target_languages,
			array_map( array( $this->model, 'get_language' ), $target_languages )
		);
		$target_languages = array_filter( $target_languages );

		if ( empty( $target_languages ) ) {
			return new WP_Error( 'invalid-target-languages', __( 'Error: invalid target languages.', 'strope-translate' ) );
		}

		$posts = get_posts(
			array(
				'post__in'               => $post_ids,
				'posts_per_page'         => count( $post_ids ),
				'orderby'                => 'post__in',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'post_type'              => $this->model->get_translated_post_types(),
				'post_status'            => 'any',
			)
		);

		// Only export the posts that the current user is allowed to edit.
		$posts = array_filter(
			$posts,
			function ( $post ) {
				return current_user_can( 'edit_post', $post->ID );
			}
		);

		if ( empty( $posts ) ) {
			return new WP_Error( 'strope_no_posts_selected', __( 'The posts selected for translation could not be found.', 'strope-translate' ) );
		}

		$is_ambiguous = $this->is_ambiguous( $posts );

		if ( is_wp_error( $is_ambiguous ) ) {
			return $is_ambiguous;
		}

		$posts_keyed_with_source_lang = $this->get_posts_by_language( $posts );
		if ( empty( $posts_keyed_with_source_lang ) ) {
			return new WP_Error( 'strope_posts_with_no_language', __( 'The posts selected for translation have no language.', 'strope-translate' ) );
		}

		$posts_by_target_lang = $this->get_posts_by_target_language( $posts_keyed_with_source_lang, array_keys( $target_languages ) );
		if ( empty( $posts_by_target_lang ) ) {
			return new WP_Error( 'strope_same_target_language', __( 'The posts are already in the target language. Please select a different language for the translation.', 'strope-translate' ) );
		}

		$export_container = new Strope_Export_Container( $file_format->get_export_class( $filetype['version'] ) );
		$export_objects   = new Strope_Export_Data_From_Posts( $this->model );

		foreach ( $posts_by_target_lang as $target_language_slug => $posts ) {
			/** @var Strope_Language $target_language This cannot be false. */
			$target_language = $this->model->get_language( $target_language_slug );

			$export_objects->send_to_export( $export_container, $posts, $target_language, array( 'include_translated_items' => true ) );
		}

		return $this->downloader->create( $export_container );
	}

	/**
	 * Get post data from id
	 *
	 * @since 3.3
	 *
	 * @param int    $post_id         The ID of the post to export.
	 * @param string $target_language Targeted languages.
	 */
	public function translate( $post_id, $target_language ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	}

	/**
	 * Groups `WP_Post` objects by their source language.
	 *
	 * @since 3.4
	 *
	 * @param WP_Post[] $posts An array of `WP_Post` objects to translate.
	 * @return WP_Post[][]     An array, keyed with lang slugs, and containing arrays of `WP_Post` objects.
	 *
	 * @phpstan-return array<non-empty-string, non-empty-list<WP_Post>>
	 */
	protected function get_posts_by_language( array $posts ) {
		$posts_keyed_with_source_lang = array();

		// Set the posts to translate for each source language.
		foreach ( $posts as $post ) {
			$post_lang = $this->model->post->get_language( $post->ID );
			if ( empty( $post_lang ) ) {
				continue;
			}
			/** @phpstan-var array<non-empty-string, non-empty-list<WP_Post>> $posts_keyed_with_source_lang */
			$posts_keyed_with_source_lang[ $post_lang->slug ][] = $post;
		}

		return $posts_keyed_with_source_lang;
	}

	/**
	 * Groups `WP_Post` objects by their target language.
	 *
	 * @since 3.4
	 *
	 * @param WP_Post[][] $posts            An array of `WP_Post` objects to translate.
	 * @param string[]    $target_languages The target language slugs for translation.
	 * @return WP_Post[][]                  An array, keyed with lang slugs, and containing arrays of `WP_Post` objects.
	 *
	 * @phpstan-return array<non-empty-string, non-empty-list<WP_Post>>
	 */
	protected function get_posts_by_target_language( array $posts, array $target_languages ) {
		if ( empty( $posts ) || empty( $target_languages ) ) {
			return array();
		}

		$translation_matrix = array();

		// Set the posts to translate for each target language.
		foreach ( $target_languages as $target_language ) {
			$_posts = array_values( array_diff_key( $posts, array( $target_language => 1 ) ) );
			if ( ! empty( $_posts ) ) {
				/** @phpstan-var array<non-empty-string, non-empty-list<WP_Post>> $translation_matrix */
				$translation_matrix[ $target_language ] = array_merge( ...$_posts );
			}
		}

		return $translation_matrix;
	}

	/**
	 * Checks there is no ambiguity in the selected posts to export.
	 * Ambiguity happens when 2 posts that are translations of each other are selected to be translated.
	 *
	 * @since 3.3
	 * @since 3.4 Parameter changed from int[] to WP_Post[]
	 *
	 * @param WP_Post[] $posts The posts selected for export.
	 * @return WP_Error|false An error if an ambiguity is found, false otherwise. The error message should not be
	 *                        escaped: it contains `<br>` tags and the texts are already escaped.
	 */
	protected function is_ambiguous( array $posts ) {
		$duplicates = $this->find_duplicate_translations( wp_list_pluck( $posts, 'ID' ) );

		if ( empty( $duplicates ) ) {
			return false;
		}

		$post_titles = wp_list_pluck( $posts, 'post_title', 'ID' ); // List of post titles keyed by post ID.

		if ( empty( $post_titles ) ) {
			// Uh?
			return false;
		}

		// Wrap post titles into quotes.
		$post_titles = array_map(
			function ( $post_title ) {
				// translators: %s is a post title.
				return sprintf( _x( '"%s"', 'quoted post title', 'strope-translate' ), $post_title );
			},
			$post_titles
		);

		$message = esc_html__( 'Ambiguous choice of contents to export. Please do not select contents that are translations of each other.', 'strope-translate' );

		foreach ( $duplicates as $duplicate ) {
			$duplicate_titles = array_intersect_key( $post_titles, array_flip( $duplicate ) );

			$message .= '<br/>' . esc_html(
				sprintf(
					// translators: %s is a list of post titles.
					_x( '- %s.', 'ambiguous posts list', 'strope-translate' ),
					wp_sprintf_l( '%l', $duplicate_titles )
				)
			);
		}

		return new WP_Error( 'strope_export_target_source_language', $message );
	}

	/**
	 * Find duplicate translations among the given list of post IDs.
	 *
	 * @since 3.3
	 *
	 * @param int[] $post_ids The ids of the posts selected for export.
	 * @return int[][] A list of arrays of post IDs.
	 *
	 * @phpstan-param array<int<1,max>> $post_ids
	 * @phpstan-return array<non-empty-array<int<1,max>>>
	 */
	protected function find_duplicate_translations( array $post_ids ) {
		$return  = array();
		$all_ids = array();

		foreach ( $post_ids as $post_id ) {
			if ( in_array( $post_id, $all_ids, true ) ) {
				// Already processed this case (already met one of this post's translations).
				continue;
			}

			/** @var array<int<1,max>> $translation_ids */
			$translation_ids = $this->model->post->get_translations( $post_id );

			// Look for this post's translations in the list of posts to translate.
			$common_post_ids = array_intersect( $post_ids, $translation_ids );

			if ( count( $common_post_ids ) <= 1 ) {
				// No translations found except this post.
				continue;
			}

			// Ambiguity found.
			$return[] = $common_post_ids;
			$all_ids  = array_merge( $all_ids, $common_post_ids );
		}

		return $return;
	}
}
