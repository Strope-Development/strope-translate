<?php
/**
 * @package Strope-Translate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

/**
 * Sends the translation jobs email notifications (plain text).
 *
 * Notifications are grouped by recipient and sent at the end of the request,
 * so that creating jobs in bulk sends one email per translator.
 *
 * @since 4.1.0
 */
class Strope_Translation_Jobs_Notifier {
	/**
	 * Cron hook of the daily digest.
	 *
	 * @var string
	 */
	const DIGEST_HOOK = 'strope_translation_jobs_digest';

	/**
	 * @var Strope_Translation_Jobs
	 */
	protected $jobs;

	/**
	 * Queued notifications, by user ID then type.
	 *
	 * @var array
	 */
	protected $queue = array();

	/**
	 * Constructor.
	 *
	 * @since 4.1.0
	 *
	 * @param Strope_Translation_Jobs $jobs Jobs model.
	 */
	public function __construct( $jobs ) {
		$this->jobs = $jobs;

		add_action( 'strope_translation_job_created', array( $this, 'job_created' ), 10, 2 );
		add_action( 'strope_translation_job_assigned', array( $this, 'job_created' ), 10, 2 );
		add_action( 'strope_translation_job_status_changed', array( $this, 'status_changed' ), 10, 4 );
		add_action( self::DIGEST_HOOK, array( $this, 'send_digest' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Tells whether notifications are enabled.
	 *
	 * @since 4.1.0
	 *
	 * @return bool
	 */
	protected function enabled() {
		return (bool) Strope_Translation_Workflow::get_options()['notifications'];
	}

	/**
	 * Queues a notification.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $user_id Recipient.
	 * @param string $type    Notification type.
	 * @param array  $job     Job data.
	 * @return void
	 */
	protected function enqueue( $user_id, $type, $job ) {
		if ( ! $user_id || get_current_user_id() === (int) $user_id ) {
			return; // Don't notify users of their own actions.
		}
		$this->queue[ (int) $user_id ][ $type ][ $job['id'] ] = $job;
	}

	/**
	 * Notifies the translators when a job is created or assigned.
	 *
	 * @since 4.1.0
	 *
	 * @param int   $job_id Job ID.
	 * @param array $job    Job data.
	 * @return void
	 */
	public function job_created( $job_id, $job ) {
		if ( ! $this->enabled() || empty( $job ) ) {
			return;
		}

		if ( $job['assignee'] ) {
			$this->enqueue( $job['assignee'], 'assigned', $job );
			return;
		}

		foreach ( $this->jobs->get_translators( $job['lang'] ) as $user ) {
			$this->enqueue( $user->ID, 'available', $job );
		}
	}

	/**
	 * Notifies the creator of a job when it is ready for review or done.
	 *
	 * @since 4.1.0
	 *
	 * @param int    $job_id     Job ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param array  $job        Job data.
	 * @return void
	 */
	public function status_changed( $job_id, $new_status, $old_status, $job ) {
		if ( ! $this->enabled() || empty( $job ) || ! in_array( $new_status, array( 'review', 'done' ), true ) ) {
			return;
		}

		$this->enqueue( $job['created_by'], $new_status, $job );
	}

	/**
	 * Sends the queued notifications.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function flush() {
		$queue       = $this->queue;
		$this->queue = array();

		foreach ( $queue as $user_id => $types ) {
			foreach ( $types as $type => $jobs ) {
				$this->send( $user_id, $type, array_values( $jobs ) );
			}
		}
	}

	/**
	 * Sends the daily digest of open jobs to each translator.
	 *
	 * @since 4.1.0
	 *
	 * @return void
	 */
	public function send_digest() {
		if ( ! $this->enabled() || ! Strope_Translation_Workflow::get_options()['digest'] ) {
			return;
		}

		$users = $this->jobs->get_translators();

		// Also users assigned to jobs without being translators of a language.
		foreach ( $this->jobs->query( array( 'status' => array( 'new', 'in_progress' ), 'per_page' => -1 ) )['jobs'] as $job ) {
			if ( $job['assignee'] && ( $user = get_userdata( $job['assignee'] ) ) ) {
				$users[] = $user;
			}
		}

		$done = array();
		foreach ( $users as $user ) {
			if ( isset( $done[ $user->ID ] ) ) {
				continue;
			}
			$done[ $user->ID ] = true;

			$jobs = $this->jobs->query(
				array(
					'status'   => array( 'new', 'in_progress' ),
					'for_user' => $user->ID,
					'per_page' => 50,
					'orderby'  => 'due',
					'order'    => 'ASC',
				)
			)['jobs'];

			if ( ! empty( $jobs ) ) {
				$this->send( $user->ID, 'digest', $jobs );
			}
		}
	}

	/**
	 * Returns a plain text description of a job.
	 *
	 * @since 4.1.0
	 *
	 * @param array $job Job data.
	 * @return string
	 */
	protected function describe_job( $job ) {
		$language = Strope()->model->get_language( $job['lang'] );
		$title    = get_the_title( $job['source'] );
		$lines    = array(
			/* translators: %s is a post title */
			sprintf( __( 'Content: %s', 'strope-translate' ), '' === $title ? '#' . $job['source'] : wp_specialchars_decode( $title, ENT_QUOTES ) ),
			/* translators: %s is a language name */
			sprintf( __( 'Language: %s', 'strope-translate' ), $language ? $language->name : $job['lang'] ),
		);

		if ( $job['due'] ) {
			/* translators: %s is a date */
			$lines[] = sprintf( __( 'Due date: %s', 'strope-translate' ), mysql2date( get_option( 'date_format' ), $job['due'] ) );
		}

		if ( '' !== $job['note'] ) {
			/* translators: %s is a free text */
			$lines[] = sprintf( __( 'Note: %s', 'strope-translate' ), $job['note'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sends a notification.
	 *
	 * @since 4.1.0
	 *
	 * @param int     $user_id Recipient.
	 * @param string  $type    'assigned', 'available', 'review', 'done' or 'digest'.
	 * @param array[] $jobs    Jobs.
	 * @return bool Whether the email was sent.
	 */
	protected function send( $user_id, $type, $jobs ) {
		$user = get_userdata( $user_id );

		if ( ! $user || empty( $user->user_email ) || empty( $jobs ) ) {
			return false;
		}

		$switched = switch_to_user_locale( $user->ID );

		$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$count    = count( $jobs );
		$url      = admin_url( 'admin.php?page=mlang_jobs' );

		switch ( $type ) {
			case 'assigned':
				/* translators: %d is a number of jobs */
				$subject = sprintf( _n( '%d translation job has been assigned to you', '%d translation jobs have been assigned to you', $count, 'strope-translate' ), $count );
				$intro   = _n( 'The following translation job has been assigned to you:', 'The following translation jobs have been assigned to you:', $count, 'strope-translate' );
				break;
			case 'available':
				/* translators: %d is a number of jobs */
				$subject = sprintf( _n( '%d new translation job is available', '%d new translation jobs are available', $count, 'strope-translate' ), $count );
				$intro   = _n( 'The following translation job is available in your languages:', 'The following translation jobs are available in your languages:', $count, 'strope-translate' );
				break;
			case 'review':
				/* translators: %d is a number of jobs */
				$subject = sprintf( _n( '%d translation is ready for review', '%d translations are ready for review', $count, 'strope-translate' ), $count );
				$intro   = _n( 'The following translation job is ready for review:', 'The following translation jobs are ready for review:', $count, 'strope-translate' );
				break;
			case 'done':
				/* translators: %d is a number of jobs */
				$subject = sprintf( _n( '%d translation job is done', '%d translation jobs are done', $count, 'strope-translate' ), $count );
				$intro   = _n( 'The following translation job is done:', 'The following translation jobs are done:', $count, 'strope-translate' );
				break;
			default:
				/* translators: %d is a number of jobs */
				$subject = sprintf( _n( 'You have %d open translation job', 'You have %d open translation jobs', $count, 'strope-translate' ), $count );
				$intro   = __( 'Here are your open translation jobs:', 'strope-translate' );
				break;
		}

		$subject = sprintf( '[%1$s] %2$s', $blogname, $subject );

		$body = $intro . "\n\n";
		foreach ( $jobs as $job ) {
			$body .= $this->describe_job( $job ) . "\n";

			if ( in_array( $type, array( 'review', 'done' ), true ) && $job['translation'] && ( $link = get_edit_post_link( $job['translation'], 'raw' ) ) ) {
				/* translators: %s is a URL */
				$body .= sprintf( __( 'Translation: %s', 'strope-translate' ), $link ) . "\n";
			}
			$body .= "\n";
		}
		/* translators: %s is a URL */
		$body .= sprintf( __( 'See the translation jobs: %s', 'strope-translate' ), $url ) . "\n";

		/**
		 * Filters the subject of a translation job notification.
		 *
		 * @since 4.1.0
		 *
		 * @param string  $subject Subject.
		 * @param string  $type    'assigned', 'available', 'review', 'done' or 'digest'.
		 * @param array[] $jobs    Jobs.
		 * @param WP_User $user    Recipient.
		 */
		$subject = (string) apply_filters( 'strope_translation_job_email_subject', $subject, $type, $jobs, $user );

		/**
		 * Filters the body (plain text) of a translation job notification.
		 *
		 * @since 4.1.0
		 *
		 * @param string  $body Body.
		 * @param string  $type 'assigned', 'available', 'review', 'done' or 'digest'.
		 * @param array[] $jobs Jobs.
		 * @param WP_User $user Recipient.
		 */
		$body = (string) apply_filters( 'strope_translation_job_email_body', $body, $type, $jobs, $user );

		if ( $switched ) {
			restore_previous_locale();
		}

		if ( '' === $subject || '' === $body ) {
			return false;
		}

		return wp_mail( $user->user_email, $subject, $body, array( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) ) );
	}
}
