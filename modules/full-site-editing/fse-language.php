<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class that defines Strope Translate's current language in the site editor's screen.
 *
 * @since 3.2
 */
class Strope_FSE_Language extends Strope_FSE_Abstract_Module implements Strope_Module_Interface {

	/**
	 * Returns the module's name.
	 *
	 * @since 3.2
	 *
	 * @return string
	 */
	public static function get_name() {
		return 'fse_language';
	}

	/**
	 * Sub-module init.
	 *
	 * @since 3.2
	 *
	 * @return self
	 */
	public function init() {
		add_filter( 'strope_admin_current_language', array( $this, 'define_current_language_in_site_editor' ), 10, 2 );
		return $this;
	}

	/**
	 * Defines the current language in the site editor.
	 *
	 * @since 3.2
	 *
	 * @param  Strope_Language|false|null $curlang  Instance of the current language.
	 * @param  Strope_Admin_Base          $strope_translate Instance of the main Strope Translate's object.
	 * @return Strope_Language|false|null
	 */
	public function define_current_language_in_site_editor( $curlang, $strope_translate ) {
		if ( ! $strope_translate instanceof Strope_Admin_Base || ! Strope_FSE_Tools::is_site_editor() ) {
			return $curlang;
		}

		$editor_lang = $this->get_site_editor_language();

		if ( empty( $editor_lang ) ) {
			return false;
		}

		return $editor_lang;
	}

	/**
	 * Returns the language object to use in the site editor.
	 *
	 * @since 3.2
	 * @since 3.5 Removed `$model` parameter.
	 *
	 * @return Strope_Language|false
	 */
	private function get_site_editor_language() {
		$request = Strope_FSE_Tools::get_site_editor_request();

		if ( empty( $request['post_type'] ) ) {
			return $this->model->get_default_language();
		}

		$post = null;

		if ( Strope_FSE_Tools::is_template_post_type( $request['post_type'] ) ) {
			$post = Strope_FSE_Tools::get_template_post();
		} elseif ( ! empty( $request['post_id'] ) && is_numeric( $request['post_id'] ) ) {
			$post = get_post( (int) $request['post_id'] );
		}

		if ( empty( $post ) ) {
			return $this->model->get_default_language();
		}

		$post_lang = $this->model->post->get_language( $post->ID );

		if ( empty( $post_lang ) ) {
			return $this->model->get_default_language();
		}

		return $post_lang;
	}
}
