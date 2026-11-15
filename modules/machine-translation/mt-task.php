<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Task type of the job queue: the machine translation of one post into one language.
 *
 * The plan extracts the text of the post into segments (placeholders and markup protected by the segmenter)
 * and reuses the translation memory; the other segments are the units, translated in the browsers of the
 * editors. Each translation is checked when it comes back; the finalization puts the post together and saves
 * it through the same code as the XLIFF import (a draft by default, with a revision, flagged as needing a
 * review). It runs as the user who asked for the translation.
 *
 * Job input: `post` (source post id), `source` and `target` (language slugs), `route` (engine languages,
 * e.g. `nl-en-de`), `code` (engine code of the target language), `status` (`draft` or `publish`), `run`.
 *
 * @since 4.2.0
 */
class Strope_MT_Task extends Strope_Translate\MT\Worker\Task {
	/**
	 * Task type.
	 */
	const TYPE = 'translate-post';

	/**
	 * {@inheritDoc}
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * Batches of up to 40 segments or 8,000 characters, of one language pair (the affinity).
	 *
	 * @return array
	 */
	public function options(): array {
		return array(
			'lease_seconds' => 180,
			'max_attempts'  => 3,
			'backoff'       => 15,
			'batch_units'   => 40,
			'batch_cost'    => 8000,
		);
	}

	/**
	 * A batch holds one language pair, and a browser gets the pair it has loaded first.
	 *
	 * @param mixed $job_input Job input.
	 * @return string
	 */
	public function affinity( $job_input ): string {
		return (string) ( $job_input['route'] ?? '' );
	}

	/**
	 * Plans in two steps. The first one only checks that there is something to do; it runs when the job is
	 * created, so a run of hundreds of posts is created quickly and the posts to skip are reported at once.
	 * The second one, continued by the queue in later requests, extracts the text: the memory gives the
	 * segments translated before, the others are the units.
	 *
	 * @param mixed                                $input   Job input.
	 * @param string|null                          $cursor  Null, then `extract`.
	 * @param Strope_Translate\MT\Worker\Outputs $outputs Unused.
	 * @return array|WP_Error A WP_Error when there is nothing to do (at the first step, the job is then not
	 *                        created).
	 */
	public function plan( $input, ?string $cursor, Strope_Translate\MT\Worker\Outputs $outputs ) {
		$model  = Strope()->model;
		$post   = get_post( (int) ( $input['post'] ?? 0 ) );
		$source = $model->get_language( (string) ( $input['source'] ?? '' ) );
		$target = $model->get_language( (string) ( $input['target'] ?? '' ) );

		if ( ! $post instanceof WP_Post || ! $source || ! $target ) {
			return new WP_Error( 'strope_mt_gone', __( 'The post or its language no longer exists.', 'strope-translate' ) );
		}

		$tr_id = (int) $model->post->get( $post->ID, $target );
		if ( $tr_id && ! Strope_MT_Queue::can_overwrite( $tr_id ) ) {
			return new WP_Error(
				'strope_mt_kept',
				sprintf(
					/* translators: 1: post title, 2: language name */
					__( '"%1$s" already has a translation into %2$s written or edited by a person, it is kept.', 'strope-translate' ),
					get_the_title( $post ),
					$target->name
				)
			);
		}

		if ( null === $cursor ) {
			return array(
				'units' => array(),
				'next'  => 'extract',
			);
		}

		$entries     = Strope_MT_Export_Collector::collect_post( $post, $source, $target );
		$split_args  = Strope_Machine_Translation::get_split_args( $source, $target );
		$fingerprint = Strope_MT_Engine_Files::get_route_fingerprint( (string) $input['route'] );
		$document    = array();
		$segments    = array();

		foreach ( $entries as $entry ) {
			$parts = array();

			foreach ( Strope_MT_Segmenter::split( $entry['source'], $split_args ) as $part ) {
				if ( is_string( $part ) ) {
					$parts[] = $part;
					continue;
				}

				$idx        = count( $segments );
				$parts[]    = $idx;
				$segments[] = array(
					'text' => $part['text'],
					'mode' => (int) $part['mode'],
					'meta' => $part['meta'],
					'hash' => md5( $fingerprint . '|' . $part['mode'] . '|' . $part['text'] ),
				);
			}

			$document[] = array(
				'c' => $entry['context'],
				's' => $entry['source'],
				'p' => $parts,
			);
		}

		$memory = Strope_MT_Store::get_memory( wp_list_pluck( $segments, 'hash' ) );
		$known  = array();
		$units  = array();

		foreach ( $segments as $idx => $segment ) {
			if ( isset( $memory[ $segment['hash'] ] ) ) {
				$restored = self::restore( $segment, $memory[ $segment['hash'] ], (string) $input['code'] );
				if ( ! is_wp_error( $restored ) ) {
					$known[ $idx ] = $restored;
					continue;
				}
			}

			$units[] = array(
				'key'   => 's' . $idx,
				'input' => $segment,
				'cost'  => max( 1, strlen( $segment['text'] ) ),
			);
		}

		return array(
			'units' => $units,
			'next'  => null,
			'data'  => array(
				'document'    => $document,
				'known'       => (object) $known,
				'source_hash' => class_exists( 'Strope_Translation_Status' ) ? (string) Strope_Translation_Status::get_hash( $post->ID ) : '',
			),
		);
	}

	/**
	 * The browser gets the text and the language pair only.
	 *
	 * @param mixed $unit_input Segment.
	 * @param mixed $job_input  Job input.
	 * @return array
	 */
	public function payload( $unit_input, $job_input ) {
		return array(
			'text'  => (string) $unit_input['text'],
			'html'  => 1 === (int) $unit_input['mode'],
			'route' => (string) $job_input['route'],
		);
	}

	/**
	 * Translators only work on the posts they can read and the languages they may translate into.
	 *
	 * @param WP_User $user       Worker user.
	 * @param mixed   $unit_input Segment.
	 * @param mixed   $job_input  Job input.
	 * @return bool
	 */
	public function authorize( WP_User $user, $unit_input, $job_input ): bool {
		if ( ! user_can( $user, Strope_Capabilities::MANAGE_TRANSLATIONS ) ) {
			return false;
		}

		// The worker receives the text of the source post: it must be allowed to read it.
		$post_id = (int) ( $job_input['post'] ?? 0 );
		if ( $post_id <= 0 || ! user_can( $user, 'read_post', $post_id ) ) {
			return false;
		}

		$language = Strope()->model->get_language( (string) ( $job_input['target'] ?? '' ) );

		return $language && ( ! function_exists( 'strope_user_can_translate_language' ) || strope_user_can_translate_language( $language, $user->ID ) );
	}

	/**
	 * The engine output must keep the placeholders, the markup and the protected words of the segment.
	 *
	 * @param mixed $unit_input Segment.
	 * @param mixed $result     Engine output.
	 * @param array $artifacts  Unused.
	 * @param mixed $job_input  Job input.
	 * @return true|WP_Error
	 */
	public function validate( $unit_input, $result, array $artifacts, $job_input ) {
		if ( ! is_string( $result ) ) {
			return new WP_Error( 'strope_mt_result', __( 'The translation engine returned no text.', 'strope-translate' ) );
		}

		$restored = self::restore( $unit_input, $result, (string) $job_input['code'] );

		return is_wp_error( $restored ) ? $restored : true;
	}

	/**
	 * Puts the translated post together and saves it, once. Runs as the user who asked for the translation.
	 *
	 * @param mixed                                $input   Job input.
	 * @param Strope_Translate\MT\Worker\Outputs $outputs Results.
	 * @param int                                  $job_id  Job id.
	 * @return array|WP_Error `post`: id of the translation.
	 */
	public function finalize( $input, Strope_Translate\MT\Worker\Outputs $outputs, int $job_id ) {
		$failed = count( $outputs->failures() );

		if ( $failed ) {
			return new WP_Error(
				'strope_mt_failed',
				sprintf(
					/* translators: %d: number of text parts */
					_n( '%d part of the text could not be translated.', '%d parts of the text could not be translated.', $failed, 'strope-translate' ),
					$failed
				)
			);
		}

		$data         = (array) $outputs->data();
		$translations = array_map( 'strval', (array) ( $data['known'] ?? array() ) );

		foreach ( $outputs->completed() as $key => $unit ) {
			$restored = self::restore( $unit['input'], (string) $unit['result'], (string) $input['code'] );
			if ( is_wp_error( $restored ) ) {
				return $restored; // Checked on arrival: only if the segmenter changed in between.
			}
			$translations[ (int) substr( (string) $key, 1 ) ] = $restored;
			Strope_MT_Store::set_memory( (string) $unit['input']['hash'], (string) $unit['result'] );
		}

		$tr_id = Strope_MT_Queue::save_translation( $input, (array) ( $data['document'] ?? array() ), $translations, (string) ( $data['source_hash'] ?? '' ) );

		return is_wp_error( $tr_id ) ? $tr_id : array( 'post' => $tr_id );
	}

	/**
	 * Restores the engine output of a segment.
	 *
	 * @param array  $segment Segment: `text`, `mode`, `meta`.
	 * @param string $output  Engine output.
	 * @param string $code    Engine code of the target language.
	 * @return string|WP_Error
	 */
	protected static function restore( array $segment, string $output, string $code ) {
		return Strope_MT_Segmenter::restore( (string) $segment['text'], $output, (int) $segment['mode'], (array) json_decode( (string) wp_json_encode( $segment['meta'] ), true ), $code );
	}
}
