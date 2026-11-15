<?php
/**
 * @package Strope-Translate
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_metadata( 'user', 0, 'strope_duplicate_content', '', true );
