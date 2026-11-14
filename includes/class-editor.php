<?php
namespace ZMD\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Classic editor takeover and Markdown save integrity.
 */
final class Editor {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 100, 2 );
		add_filter( 'gutenberg_can_edit_post_type', array( $this, 'disable_block_editor' ), 100, 2 );
		add_filter( 'wp_editor_settings', array( $this, 'filter_editor_settings' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'content_save_pre', array( $this, 'preserve_markdown' ), 999 );
		add_action( 'admin_notices', array( $this, 'token_notice' ) );
	}

	/**
	 * Disable Gutenberg for enabled post types. Gutenberg stays installed
	 * and remains available for every other post type.
	 *
	 * @param bool   $use       Current value.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function disable_block_editor( $use, $post_type ) {
		if ( $this->settings->is_enabled_post_type( $post_type ) ) {
			return false;
		}
		return $use;
	}

	/**
	 * Turn off TinyMCE, quicktags and media buttons for the content field.
	 *
	 * @param array  $settings  Editor settings.
	 * @param string $editor_id Editor id.
	 * @return array
	 */
	public function filter_editor_settings( $settings, $editor_id ) {
		if ( 'content' !== $editor_id || ! $this->is_editor_screen() ) {
			return $settings;
		}

		$settings['tinymce']       = false;
		$settings['quicktags']     = false;
		$settings['media_buttons'] = false;
		$settings['textarea_rows'] = 20;

		return $settings;
	}

	/**
	 * Enqueue OverType and the bridge script only on supported post screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_editor_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $this->is_editor_screen() ) {
			return;
		}

		$settings = $this->settings->get();
		$max      = $this->settings->effective_max_size();

		wp_enqueue_style( 'zmd-editor', ZMD_EDITOR_URL . 'assets/editor.css', array(), ZMD_EDITOR_VERSION );
		wp_enqueue_script( 'zmd-overtype', ZMD_EDITOR_URL . 'assets/overtype/overtype.min.js', array(), ZMD_OVERTYPE_VERSION, true );
		wp_enqueue_script( 'zmd-editor', ZMD_EDITOR_URL . 'assets/editor.js', array( 'zmd-overtype' ), ZMD_EDITOR_VERSION, true );

		wp_localize_script(
			'zmd-editor',
			'zmdEditor',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'zmd/v1/' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'maxSize'      => $max * MB_IN_BYTES,
				'maxSizeLabel' => $max . ' MB',
				'theme'        => $settings['editor_theme'],
				'height'       => $settings['editor_height'],
				'showStats'    => ! empty( $settings['show_stats'] ),
				'hasToken'     => '' !== $this->settings->get_token(),
				'i18n'         => array(
					'placeholder'   => '开始写 Markdown…',
					'noToken'       => '请先在 ZMD Editor 设置中配置 ImgURL Token。',
					'tooLarge'      => '图片超过大小限制：',
					'uploaded'      => '图片已上传并插入。',
					'uploadFailed'  => '图片上传失败。',
					'uploadPending' => '图片还在上传，请稍候再保存。',
					'badResponse'   => '服务器返回格式错误。',
				),
			)
		);
	}

	/**
	 * Preserve the raw Markdown submitted by the editor.
	 *
	 * WordPress runs convert_invalid_entities, balanceTags and KSES on
	 * content_save_pre. For admins with unfiltered_html we return the exact
	 * submitted content so `&`, `<` inside code blocks and backslashes are
	 * not rewritten.
	 *
	 * @param string $content Sanitized content.
	 * @return string
	 */
	public function preserve_markdown( $content ) {
		if ( ! is_admin() || ! current_user_can( 'unfiltered_html' ) ) {
			return $content;
		}

		$raw       = null;
		$post_type = '';

		if ( isset( $_POST['content'] ) && is_string( $_POST['content'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the post save nonce is verified by core before this filter runs.
			$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
			if ( 'post-quickdraft-save' === $action ) {
				return $content;
			}

			$raw = $_POST['content']; // Slashed. Core unslashes the data right before the DB write.
			if ( isset( $_POST['post_type'] ) && is_string( $_POST['post_type'] ) ) {
				$post_type = sanitize_key( wp_unslash( $_POST['post_type'] ) );
			}
		} elseif (
			isset( $_POST['data'] ) && is_array( $_POST['data'] ) &&
			isset( $_POST['data']['wp_autosave'] ) && is_array( $_POST['data']['wp_autosave'] ) &&
			isset( $_POST['data']['wp_autosave']['content'] ) && is_string( $_POST['data']['wp_autosave']['content'] )
		) {
			// Heartbeat autosave: content is nested under data[wp_autosave].
			$raw = $_POST['data']['wp_autosave']['content']; // Slashed.
			if ( isset( $_POST['data']['wp_autosave']['post_type'] ) && is_string( $_POST['data']['wp_autosave']['post_type'] ) ) {
				$post_type = sanitize_key( wp_unslash( $_POST['data']['wp_autosave']['post_type'] ) );
			}
		}

		if ( null === $raw || ! $this->settings->is_enabled_post_type( $post_type ) ) {
			return $content;
		}

		return $raw;
	}

	/**
	 * Warn when the ImgURL token is missing.
	 */
	public function token_notice() {
		if ( ! $this->is_editor_screen() || '' !== $this->settings->get_token() ) {
			return;
		}

		$url = admin_url( 'admin.php?page=zmd-editor' );
		echo '<div class="notice notice-warning"><p>';
		echo esc_html( 'ZMD Editor：尚未配置 ImgURL Token，图片上传暂不可用。' );
		echo ' <a href="' . esc_url( $url ) . '">' . esc_html( '去设置' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Whether the current screen is the classic editor for an enabled type.
	 *
	 * @return bool
	 */
	private function is_editor_screen() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return false;
		}
		if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return false;
		}

		$post_type = $screen->post_type;
		if ( ! $post_type && isset( $_GET['post_type'] ) && is_string( $_GET['post_type'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
			$post_type = sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}
		if ( ! $post_type ) {
			$post_type = 'post';
		}

		return $this->settings->is_enabled_post_type( $post_type );
	}
}
