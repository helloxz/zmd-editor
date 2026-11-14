<?php
/**
 * Plugin Name: ZMD Editor
 * Description: 适用于 WordPress 的 Markdown 编辑器，支持将图片上传到 ImgURL 图床。
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: xiaoz
 * Author URI: https://blog.xiaoz.org
 * License: AGPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: zmd-editor
 */

defined( 'ABSPATH' ) || exit;

define( 'ZMD_EDITOR_VERSION', '0.1.5' );
define( 'ZMD_OVERTYPE_VERSION', '2.8.0' );
define( 'ZMD_EDITOR_FILE', __FILE__ );
define( 'ZMD_EDITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZMD_EDITOR_URL', plugin_dir_url( __FILE__ ) );

require_once ZMD_EDITOR_DIR . 'includes/class-settings.php';
require_once ZMD_EDITOR_DIR . 'includes/class-editor.php';
require_once ZMD_EDITOR_DIR . 'includes/class-imgurl.php';
require_once ZMD_EDITOR_DIR . 'includes/class-upload.php';
require_once ZMD_EDITOR_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\ZMD\Editor\Plugin', 'activate' ) );

add_action( 'plugins_loaded', function () {
	\ZMD\Editor\Plugin::instance()->init();
} );
