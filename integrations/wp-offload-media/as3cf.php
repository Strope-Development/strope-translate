<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * A class to manage the integration with WP Offload Media Lite.
 * Version tested: 2.1.1
 *
 * Since WP Offload Media 2.3, the offload information is stored in a custom table (items)
 * instead of the `amazonS3_info` post meta. Both storages are supported.
 *
 * @since 2.6
 */
class Strope_AS3CF {
	/**
	 * Class of the media library items in WP Offload Media 2.3+.
	 *
	 * @since 4.1.0
	 *
	 * @var string
	 */
	const ITEM_CLASS = 'DeliciousBrains\WP_Offload_Media\Items\Media_Library_Item';

	/**
	 * Stores if a media is translated when it is deleted.
	 *
	 * @var bool[]
	 */
	private $is_media_translated = array();

	/**
	 * Initializes filters and actions.
	 *
	 * @since 2.6
	 */
	public function init() {
		add_filter( 'strope_copy_post_metas', array( $this, 'copy_post_metas' ) );
		add_action( 'delete_attachment', array( $this, 'check_translated_media' ), 5 ); // Before Strope Translate deletes the translations information.
		add_action( 'delete_attachment', array( $this, 'prevent_file_deletion' ), 15 ); // Between Strope Translate and WP Offload Media.

		// WP Offload Media 2.3+.
		add_action( 'strope_translate_media', array( $this, 'translate_media' ), 20, 2 ); // After the copy of the metas.
		add_action( 'as3cf_post_upload_attachment', array( $this, 'item_uploaded' ), 10, 2 );
		add_action( 'as3cf_post_upload_item', array( $this, 'item_uploaded' ) );
	}

	/**
	 * Synchronizes post metas (WP Offload Media < 2.3).
	 *
	 * @since 2.6
	 *
	 * @param array $metas List of custom fields names.
	 * @return array
	 */
	public function copy_post_metas( $metas ) {
		$metas[] = 'amazonS3_info';
		$metas[] = 'as3cf_filesize_total';
		return $metas;
	}

	/**
	 * Checks if the deleted attachment was translated and stores the information.
	 *
	 * @since 2.6
	 *
	 * @param int $post_id Id of the attachment being deleted.
	 */
	public function check_translated_media( $post_id ) {
		// Ignore the translations already deleted in the same request (e.g. bulk deletion).
		$translations = array_filter(
			strope_get_post_translations( $post_id ),
			function ( $tr_id ) use ( $post_id ) {
				return (int) $tr_id !== (int) $post_id && get_post( (int) $tr_id );
			}
		);

		$this->is_media_translated[ $post_id ] = ! empty( $translations );
	}

	/**
	 * Deletes the WP Offload Media information from the attachment being deleted.
	 * That way WP Offload Media won't delete the file stored in the cloud.
	 * Done after Strope Translate has deleted the translations information, to avoid the synchronization of the deletion
	 * and of course before WP Offload Media deletes the file, normally at priority 20.
	 *
	 * @since 2.6
	 *
	 * @param int $post_id Id of the attachment being deleted.
	 */
	public function prevent_file_deletion( $post_id ) {
		if ( empty( $this->is_media_translated[ $post_id ] ) ) {
			return;
		}

		// WP Offload Media < 2.3.
		delete_post_meta( $post_id, 'amazonS3_info' );
		delete_post_meta( $post_id, 'as3cf_filesize_total' );

		// WP Offload Media 2.3+: the files are still used by the translations, which have their own item.
		$item = $this->get_item( $post_id );
		if ( $item && method_exists( $item, 'delete' ) ) {
			$item->delete();
		}
	}

	/**
	 * Gives a new media translation the offload information of its source (WP Offload Media 2.3+),
	 * so that it is served from the bucket like its source.
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Id of the source media.
	 * @param int $tr_id   Id of the new media translation.
	 * @return void
	 */
	public function translate_media( $post_id, $tr_id ) {
		$item = $this->get_item( $post_id );

		if ( $item ) {
			$this->duplicate_item( $item, (int) $tr_id );
		}
	}

	/**
	 * Gives the offload information of a media to its translations when it is offloaded (WP Offload Media 2.3+).
	 *
	 * @since 4.1.0
	 *
	 * @param int|object  $post_id Attachment id or WP Offload Media item, depending on the action.
	 * @param object|null $item    WP Offload Media item, if passed by the action.
	 * @return void
	 */
	public function item_uploaded( $post_id, $item = null ) {
		$class = self::ITEM_CLASS;

		if ( ! $item instanceof $class ) {
			$item = $post_id instanceof $class ? $post_id : $this->get_item( (int) $post_id );
		}

		if ( ! $item instanceof $class || ! method_exists( $item, 'source_id' ) ) {
			return;
		}

		$source_id = (int) $item->source_id();

		foreach ( strope_get_post_translations( $source_id ) as $tr_id ) {
			if ( (int) $tr_id !== $source_id ) {
				$this->duplicate_item( $item, (int) $tr_id );
			}
		}
	}

	/**
	 * Returns the WP Offload Media item of an attachment (WP Offload Media 2.3+).
	 *
	 * @since 4.1.0
	 *
	 * @param int $post_id Attachment id.
	 * @return object|null The item, null if there is none or WP Offload Media is older than 2.3.
	 */
	protected function get_item( $post_id ) {
		$class = self::ITEM_CLASS;

		if ( ! class_exists( $class ) || ! is_callable( array( $class, 'get_by_source_id' ) ) ) {
			return null;
		}

		$item = call_user_func( array( $class, 'get_by_source_id' ), (int) $post_id );

		return $item instanceof $class ? $item : null;
	}

	/**
	 * Creates the WP Offload Media item of a media translation from the item of its source.
	 * Both attachments share the same files, so they share the same objects in the bucket.
	 *
	 * @since 4.1.0
	 *
	 * @param object $item  Item of the source media.
	 * @param int    $tr_id Id of the media translation.
	 * @return void
	 */
	protected function duplicate_item( $item, $tr_id ) {
		foreach ( array( 'provider', 'region', 'bucket', 'path', 'is_private', 'source_path', 'original_filename' ) as $method ) {
			if ( ! method_exists( $item, $method ) ) {
				return;
			}
		}

		$tr_item = $this->get_item( $tr_id );

		if ( $tr_item ) {
			// Don't touch a translation which has its own file or which is already up to date.
			if ( ! method_exists( $tr_item, 'source_path' ) || $tr_item->source_path() !== $item->source_path() || ( $tr_item->bucket() === $item->bucket() && $tr_item->path() === $item->path() ) ) {
				return;
			}

			if ( ! method_exists( $tr_item, 'delete' ) ) {
				return;
			}

			$tr_item->delete(); // Only the database row: the objects in the bucket are kept.
		}

		// Private sizes (2.3 to 2.5) or extra info (2.6+).
		if ( method_exists( $item, 'extra_info' ) ) {
			$extra = $item->extra_info();
		} elseif ( method_exists( $item, 'private_sizes' ) ) {
			$extra = $item->private_sizes();
		} else {
			$extra = array();
		}

		$class = get_class( $item );

		try {
			$tr_item = new $class(
				$item->provider(),
				$item->region(),
				$item->bucket(),
				$item->path(),
				$item->is_private(),
				$tr_id,
				$item->source_path(),
				$item->original_filename(),
				$extra,
				null, // New item.
				method_exists( $item, 'originator' ) ? $item->originator() : 0, // 2.6+.
				method_exists( $item, 'is_verified' ) ? $item->is_verified() : true // 2.6+.
			);

			if ( method_exists( $tr_item, 'save' ) ) {
				$tr_item->save();
			}
		} catch ( Throwable $e ) {
			// Unexpected version of WP Offload Media: the translation is just not served from the bucket.
			return;
		}
	}
}
