<?php
/**
 * Uninstall ZMD Editor.
 *
 * @package ZMD\Editor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'zmd_editor_settings' );
delete_option( 'zmd_imgurl_token' );
