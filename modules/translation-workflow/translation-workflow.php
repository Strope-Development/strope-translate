<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Bootstraps the translation workflow: translator role, per-user languages, translation jobs and notifications.
 *
 * @since 4.1.0
 */
class Strope_Translation_Workflow {
	/**
	 * Translator role name.
	 *
	 * @var string
	 */
	const ROLE = 'strope_translator';

	/**
	 * Meta capability to create, assign and cancel any translation job.
	 *
	 * @var string
	 */
	const MANAGE_JOBS = 'manage_translation_jobs';

	/**
	 * Meta capability to access the translation jobs screen.
	 *
	 * @var string
	 */
	const VIEW_JOBS = 'view_translation_jobs';

	/**
	 * @var Strope_Model
	 */
	public $model;

	/**
	 * @var Strope_Translator_Languages
	 */
	public $languages;

	/**
	 * @var Strope_Translation_Jobs
	 */
	public $jobs;

	/**
	 * @var Strope_Translation_Jobs_Notifier
	 */
	public $notifier;

	/**
	 * @var Strope_Translation_Jobs_Admin|null
	 */
	public $admin;

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Base $strope_translate Strope Translate object.
	 */
	public function __construct( &$strope_translate ) {
		$this->model = &$strope_translate->model;

		$this->languages = new Strope_Translator_Languages( $strope_translate );
		$this->jobs      = new Strope_Translation_Jobs( $this->model );
		$this->notifier  = new Strope_Translation_Jobs_Notifier( $this->jobs );

		add_action( 'init', array( $this, 'maybe_setup_role' ), 5 );
		add_action( 'init', array( $this, 'maybe_schedule_digest' ), 20 );
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );

		if ( is_admin() ) {
			$this->admin = new Strope_Translation_Jobs_Admin( $strope_translate, $this->jobs );
		}
	}

	/**
	 * Returns the module options merged with their default values.
	 *
	 * @since 4.1.0
	 *
	 * @return array {
	 *   @type int $translators_publish 1 if translators can publish translations.
	 *   @type int $notifications       1 to send email notifications.
	 *   @type int $digest              1 to send a daily digest of open jobs.
	 * }
	 */
	public static function get_options() {
		$options  = function_exists( 'Strope' ) && Strope() && isset( Strope()->options['translation_workflow'] ) ? (array) Strope()->options['translation_workflow'] : array();
		$defaults = array(
			'translators_publish' => 1,
			'notifications'       => 1,
			'digest'              => 0,
		);
		return array_map( 'absint', array_intersect_key( array_merge( $defaults, $options ), $defaults ) );
	}

	/**
	 * Creates the translator role on demand and keeps its publishing capabilities in sync with the settings.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function maybe_setup_role() {
		$role = get_role( self::ROLE );

		if ( empty( $role ) ) {
			$caps = array_fill_keys(
				array(
					'read',
					'upload_files',
					'edit_posts',
					'edit_others_posts',
					'edit_pages',
					'edit_others_pages',
					Strope_Capabilities::MANAGE_TRANSLATIONS,
				),
				true
			);
			// The role name is stored untranslated, as for the WordPress default roles, and translated on display.
			$role = add_role( self::ROLE, 'Translator', $caps );

			if ( empty( $role ) ) {
				return;
			}
		}

		$options = self::get_options();

		/*
		 * Translators who can't publish can't edit published content either, otherwise they could change
		 * published translations without review. They work on drafts and submit them for review.
		 */
		foreach ( array( 'publish_posts', 'publish_pages', 'edit_published_posts', 'edit_published_pages' ) as $cap ) {
			if ( $options['translators_publish'] && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			} elseif ( ! $options['translators_publish'] && $role->has_cap( $cap ) ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Schedules or unschedules the daily digest according to the settings.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function maybe_schedule_digest() {
		$options   = self::get_options();
		$scheduled = wp_next_scheduled( Strope_Translation_Jobs_Notifier::DIGEST_HOOK );

		if ( $options['digest'] && $options['notifications'] && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Strope_Translation_Jobs_Notifier::DIGEST_HOOK );
		} elseif ( ( ! $options['digest'] || ! $options['notifications'] ) && $scheduled ) {
			wp_clear_scheduled_hook( Strope_Translation_Jobs_Notifier::DIGEST_HOOK );
		}
	}

	/**
	 * Maps the translation jobs meta capabilities.
	 *
	 * - `manage_translation_jobs`: granted directly to a role/user, otherwise requires `manage_languages`.
	 * - `view_translation_jobs`: granted directly, or to users who can manage jobs or translations,
	 *   or to users who can edit posts and have translation languages assigned.
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
		if ( self::MANAGE_JOBS !== $cap && self::VIEW_JOBS !== $cap ) {
			return $caps;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return array( 'do_not_allow' );
		}

		if ( ! empty( $user->allcaps[ $cap ] ) ) {
			$caps = array( $cap );
		} elseif ( self::MANAGE_JOBS === $cap ) {
			$caps = map_meta_cap( Strope_Capabilities::MANAGE_LANGUAGES, $user_id );
		} elseif ( user_can( $user, self::MANAGE_JOBS ) || user_can( $user, Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			$caps = array( 'read' );
		} elseif ( Strope_Translator_Languages::get_user_languages( $user_id ) ) {
			$caps = array( 'edit_posts' );
		} else {
			$caps = array( 'do_not_allow' );
		}

		/**
		 * Filters the primitive capabilities required to manage or view translation jobs.
		 *
		 * @since 4.1.0
		 *
		 * @param string[] $caps    Primitive capabilities required.
		 * @param string   $cap     `manage_translation_jobs` or `view_translation_jobs`.
		 * @param int      $user_id User ID.
		 * @param array    $args    Additional arguments.
		 */
		return (array) apply_filters( 'strope_translation_jobs_map_meta_cap', $caps, $cap, $user_id, $args );
	}
}
