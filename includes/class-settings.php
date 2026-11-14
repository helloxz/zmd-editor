<?php
namespace ZMD\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Settings page and option handling.
 */
final class Settings {

	const OPTION       = 'zmd_editor_settings';
	const TOKEN_OPTION = 'zmd_imgurl_token';

	/** @var array|null */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'         => array( 'post', 'page' ),
			'editor_height'      => '70vh',
			'editor_theme'       => 'solar',
			'show_stats'         => false,
			'imgurl_base_url'    => 'https://www.imgurl.org',
			'imgurl_album_id'    => 0,
			'imgurl_dedup'       => false,
			'imgurl_watermark'   => false,
			'imgurl_compress'    => false,
			'imgurl_max_size'    => 10,
			'imgurl_url_path'    => 'data.url',
		);
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ZMD_EDITOR_FILE ), array( $this, 'action_links' ) );
		add_filter( 'pre_update_option_' . self::TOKEN_OPTION, array( $this, 'pre_update_token' ), 10, 2 );
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public function get() {
		if ( null === $this->cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$settings = array_merge( self::defaults(), $saved );

			// Migrate the old full endpoint URLs to the single base URL.
			if ( ! isset( $settings['imgurl_base_url'] ) || ! is_string( $settings['imgurl_base_url'] ) || '' === trim( $settings['imgurl_base_url'] ) ) {
				$legacy = '';
				if ( isset( $saved['imgurl_upload_url'] ) && is_string( $saved['imgurl_upload_url'] ) ) {
					$legacy = preg_replace( '#/api/v3/upload/?$#', '', $saved['imgurl_upload_url'] );
				}
				if ( ( ! is_string( $legacy ) || '' === $legacy ) && isset( $saved['imgurl_album_url'] ) && is_string( $saved['imgurl_album_url'] ) ) {
					$legacy = preg_replace( '#/api/v3/album_list/?$#', '', $saved['imgurl_album_url'] );
				}
				$settings['imgurl_base_url'] = ( is_string( $legacy ) && '' !== $legacy ) ? $legacy : self::defaults()['imgurl_base_url'];
			}
			$settings['imgurl_base_url'] = rtrim( $settings['imgurl_base_url'], '/' );
			unset( $settings['imgurl_upload_url'], $settings['imgurl_album_url'], $settings['imgurl_params_json'] );

			if ( ! is_array( $settings['post_types'] ) ) {
				$settings['post_types'] = array( 'post', 'page' );
			}
			$settings['post_types'] = array_values(
				array_filter(
					$settings['post_types'],
					static function ( $type ) {
						return is_string( $type ) && '' !== $type;
					}
				)
			);

			$this->cache = $settings;
		}
		return $this->cache;
	}

	/**
	 * ImgURL token. A wp-config constant wins over the database option.
	 *
	 * @return string
	 */
	public function get_token() {
		if ( $this->is_constant_token() ) {
			return trim( ZMD_IMGURL_TOKEN );
		}
		$token = get_option( self::TOKEN_OPTION, '' );
		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Whether the token comes from a constant.
	 *
	 * @return bool
	 */
	public function is_constant_token() {
		return defined( 'ZMD_IMGURL_TOKEN' ) && is_string( ZMD_IMGURL_TOKEN ) && '' !== trim( ZMD_IMGURL_TOKEN );
	}

	/**
	 * Enabled post type names.
	 *
	 * @return array
	 */
	public function enabled_post_types() {
		$types = array();
		foreach ( $this->get()['post_types'] as $type ) {
			if ( is_string( $type ) && '' !== $type ) {
				$types[] = $type;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Check whether a post type is enabled.
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function is_enabled_post_type( $post_type ) {
		return is_string( $post_type ) && in_array( $post_type, $this->enabled_post_types(), true );
	}

	/**
	 * Effective upload size in MB, clamped by the server.
	 *
	 * @return int
	 */
	public function effective_max_size() {
		$configured = absint( $this->get()['imgurl_max_size'] );
		if ( $configured < 1 ) {
			$configured = 10;
		}

		$server = wp_max_upload_size();
		if ( $server > 0 ) {
			$server_mb = (int) floor( $server / MB_IN_BYTES );
			if ( $server_mb > 0 ) {
				// Leave room for multipart boundaries and other fields.
				$server_mb  = max( 1, $server_mb - 1 );
				$configured = min( $configured, $server_mb );
			}
		}

		return max( 1, $configured );
	}

	/**
	 * Add the settings menu.
	 */
	public function add_menu() {
		add_menu_page(
			'ZMD Editor',
			'ZMD Editor',
			'manage_options',
			'zmd-editor',
			array( $this, 'render_page' ),
			'dashicons-edit-page',
			80
		);
	}

	/**
	 * Register settings, sections and fields.
	 */
	public function register_settings() {
		register_setting(
			'zmd_editor',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);

		register_setting(
			'zmd_editor',
			self::TOKEN_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_token' ),
				'default'           => '',
			)
		);

		add_settings_section( 'zmd_editor_general', '编辑器', null, 'zmd_editor' );
		add_settings_field( 'zmd_post_types', '启用的文章类型', array( $this, 'field_post_types' ), 'zmd_editor', 'zmd_editor_general' );
		add_settings_field( 'zmd_editor_height', '编辑器高度', array( $this, 'field_editor_height' ), 'zmd_editor', 'zmd_editor_general' );
		add_settings_field( 'zmd_editor_theme', '编辑器主题', array( $this, 'field_editor_theme' ), 'zmd_editor', 'zmd_editor_general' );
		add_settings_field( 'zmd_show_stats', '统计栏', array( $this, 'field_show_stats' ), 'zmd_editor', 'zmd_editor_general' );

		add_settings_section( 'zmd_editor_imgurl', 'ImgURL 图床', array( $this, 'section_imgurl' ), 'zmd_editor' );
		add_settings_field( 'zmd_imgurl_base_url', 'ImgURL 地址', array( $this, 'field_base_url' ), 'zmd_editor', 'zmd_editor_imgurl' );
		add_settings_field( 'zmd_imgurl_token', 'Token', array( $this, 'field_token' ), 'zmd_editor', 'zmd_editor_imgurl' );
		add_settings_field( 'zmd_imgurl_album_id', '默认相册', array( $this, 'field_album_id' ), 'zmd_editor', 'zmd_editor_imgurl' );
		add_settings_field( 'zmd_imgurl_flags', '上传参数', array( $this, 'field_flags' ), 'zmd_editor', 'zmd_editor_imgurl' );
		add_settings_field( 'zmd_imgurl_max_size', '最大文件大小', array( $this, 'field_max_size' ), 'zmd_editor', 'zmd_editor_imgurl' );
		add_settings_field( 'zmd_imgurl_url_path', '响应 URL 路径', array( $this, 'field_url_path' ), 'zmd_editor', 'zmd_editor_imgurl' );

		add_settings_section( 'zmd_editor_info', '系统信息', array( $this, 'section_info' ), 'zmd_editor' );
	}

	/**
	 * Settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap zmd-settings">
			<h1>ZMD Editor</h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'zmd_editor' );
				do_settings_sections( 'zmd_editor' );
				?>
				<p class="submit">
					<?php submit_button( '保存设置', 'primary', 'submit', false ); ?>
					<button type="button" class="button" id="zmd-test-connection">测试连接</button>
					<span id="zmd-test-result" class="zmd-status" aria-live="polite"></span>
				</p>
			</form>
			<p class="description">启用文章类型前，请确认该类型没有需要保留的 HTML 旧文章；本插件不转换旧内容。</p>
		</div>
		<?php
	}

	/**
	 * Enqueue settings assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'toplevel_page_zmd-editor' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'zmd-admin', ZMD_EDITOR_URL . 'assets/admin.css', array(), ZMD_EDITOR_VERSION );
		wp_enqueue_script( 'zmd-admin', ZMD_EDITOR_URL . 'assets/admin.js', array(), ZMD_EDITOR_VERSION, true );
		wp_localize_script(
			'zmd-admin',
			'zmdAdmin',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'zmd/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'hasToken' => '' !== $this->get_token(),
				'i18n'     => array(
					'noToken'   => '请先填写并保存 ImgURL Token。',
					'testing'   => '正在连接…',
					'success'   => '连接成功，共 %d 个相册。',
					'failed'    => '连接失败：',
					'badJson'   => '服务器返回格式错误。',
					'busy'      => '请稍候…',
					'emptyList' => '连接成功，但没有返回相册。',
				),
			)
		);
	}

	/**
	 * Add a settings link on the plugins page.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=zmd-editor' ) ) . '">设置</a>';
		return $links;
	}

	/**
	 * Sanitize settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$out = $defaults;

		$available = get_post_types( array( 'public' => true ), 'names' );
		unset( $available['attachment'] );
		$types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$types = array_filter( $types, 'is_string' );
		$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), array_keys( $available ) ) );
		$out['post_types'] = $types;

		$height = isset( $input['editor_height'] ) && is_string( $input['editor_height'] ) ? trim( $input['editor_height'] ) : $defaults['editor_height'];
		if ( ! preg_match( '/^\d+(?:\.\d+)?(?:px|vh|rem|em)$/', $height ) ) {
			add_settings_error( self::OPTION, 'zmd_height', '编辑器高度格式不正确，已恢复为 70vh。', 'error' );
			$height = $defaults['editor_height'];
		}
		$out['editor_height'] = $height;

		$theme              = isset( $input['editor_theme'] ) && is_string( $input['editor_theme'] ) ? $input['editor_theme'] : 'solar';
		$out['editor_theme'] = in_array( $theme, array( 'solar', 'cave' ), true ) ? $theme : 'solar';

		$out['show_stats'] = ! empty( $input['show_stats'] );

		$out['imgurl_base_url'] = $this->sanitize_base_url(
			isset( $input['imgurl_base_url'] ) ? $input['imgurl_base_url'] : '',
			$defaults['imgurl_base_url']
		);

		$out['imgurl_album_id'] = isset( $input['imgurl_album_id'] ) ? absint( $input['imgurl_album_id'] ) : 0;
		$out['imgurl_dedup']    = ! empty( $input['imgurl_dedup'] );
		$out['imgurl_watermark'] = ! empty( $input['imgurl_watermark'] );
		$out['imgurl_compress'] = ! empty( $input['imgurl_compress'] );

		$max = isset( $input['imgurl_max_size'] ) ? absint( $input['imgurl_max_size'] ) : 10;
		if ( $max < 1 ) {
			$max = 10;
		}
		if ( $max > 100 ) {
			$max = 100;
		}
		$out['imgurl_max_size'] = $max;

		$path = isset( $input['imgurl_url_path'] ) && is_string( $input['imgurl_url_path'] ) ? trim( $input['imgurl_url_path'] ) : 'data.url';
		if ( '' === $path || ! preg_match( '/^[A-Za-z0-9_.\-\[\]]+$/', $path ) ) {
			add_settings_error( self::OPTION, 'zmd_url_path', '响应 URL 路径格式不正确，已恢复为 data.url。', 'error' );
			$path = 'data.url';
		}
		$out['imgurl_url_path'] = $path;

		$this->cache = null;
		return $out;
	}

	/**
	 * Sanitize the token field.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_token( $value ) {
		if ( $this->is_constant_token() ) {
			$old = get_option( self::TOKEN_OPTION, '' );
			return is_string( $old ) ? $old : '';
		}
		return sanitize_text_field( trim( is_string( $value ) ? $value : '' ) );
	}

	/**
	 * Keep the old token when the field is left blank.
	 *
	 * @param mixed $new New value.
	 * @param mixed $old Old value.
	 * @return mixed
	 */
	public function pre_update_token( $new, $old ) {
		if ( $this->is_constant_token() ) {
			return $old;
		}
		if ( ! empty( $_POST['zmd_clear_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the nonce.
			return '';
		}
		if ( ! is_string( $new ) || '' === trim( $new ) ) {
			return is_string( $old ) ? $old : '';
		}
		return $new;
	}

	/* ---------------------------------------------------------------------
	 * Section callbacks
	 * ------------------------------------------------------------------ */

	public function section_imgurl() {
		echo '<p>图片上传走 WordPress 服务端代理，Token 不会出现在浏览器页面里。只需填 ImgURL 地址，插件自动拼接上传和相册接口。</p>';
	}

	public function section_info() {
		$token       = $this->get_token();
		$token_src   = $this->is_constant_token() ? 'wp-config 常量 ZMD_IMGURL_TOKEN' : ( '' !== $token ? '数据库选项' : '未配置' );
		$server_max  = wp_max_upload_size();
		$server_text = $server_max > 0 ? size_format( $server_max ) : '未知/不限';
		$settings    = $this->get();
		$base        = isset( $settings['imgurl_base_url'] ) ? rtrim( (string) $settings['imgurl_base_url'], '/' ) : '';
		?>
		<table class="widefat striped zmd-info-table">
			<tbody>
				<tr><td>插件版本</td><td><?php echo esc_html( ZMD_EDITOR_VERSION ); ?></td></tr>
				<tr><td>OverType 版本</td><td><?php echo esc_html( ZMD_OVERTYPE_VERSION ); ?></td></tr>
				<tr><td>WordPress</td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
				<tr><td>PHP</td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
				<tr><td>ImgURL 地址</td><td><code><?php echo esc_html( $base ); ?></code></td></tr>
				<tr><td>上传接口</td><td><code><?php echo esc_html( $base . '/api/v3/upload' ); ?></code></td></tr>
				<tr><td>相册接口</td><td><code><?php echo esc_html( $base . '/api/v3/album_list' ); ?></code></td></tr>
				<tr><td>服务器上传上限</td><td><?php echo esc_html( $server_text ); ?></td></tr>
				<tr><td>生效的单张上限</td><td><?php echo esc_html( $this->effective_max_size() . ' MB' ); ?></td></tr>
				<tr><td>upload_max_filesize</td><td><?php echo esc_html( (string) ini_get( 'upload_max_filesize' ) ); ?></td></tr>
				<tr><td>post_max_size</td><td><?php echo esc_html( (string) ini_get( 'post_max_size' ) ); ?></td></tr>
				<tr><td>memory_limit</td><td><?php echo esc_html( (string) ini_get( 'memory_limit' ) ); ?></td></tr>
				<tr><td>Token 来源</td><td><?php echo esc_html( $token_src ); ?></td></tr>
			</tbody>
		</table>
		<p class="description">服务器需要能出站访问 ImgURL；如果上传失败，先检查服务器网络和接口地址。</p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field callbacks
	 * ------------------------------------------------------------------ */

	public function field_post_types() {
		$settings = $this->get();
		$enabled  = $settings['post_types'];
		$types    = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );

		echo '<fieldset>';
		foreach ( $types as $type ) {
			printf(
				'<label style="display:block;margin:4px 0"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( self::OPTION ),
				esc_attr( $type->name ),
				checked( in_array( $type->name, $enabled, true ), true, false ),
				esc_html( $type->labels->singular_name )
			);
		}
		echo '<p class="description">不勾选则插件不接管任何编辑器。</p>';
		echo '</fieldset>';
	}

	public function field_editor_height() {
		$settings = $this->get();
		printf(
			'<input type="text" class="small-text" name="%1$s[editor_height]" value="%2$s" /> <span class="description">例如 70vh、600px</span>',
			esc_attr( self::OPTION ),
			esc_attr( $settings['editor_height'] )
		);
	}

	public function field_editor_theme() {
		$settings = $this->get();
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[editor_theme]">
			<option value="solar" <?php selected( $settings['editor_theme'], 'solar' ); ?>>Solar（浅色）</option>
			<option value="cave" <?php selected( $settings['editor_theme'], 'cave' ); ?>>Cave（深色）</option>
		</select>
		<?php
	}

	public function field_show_stats() {
		$settings = $this->get();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[show_stats]" value="1" <?php checked( ! empty( $settings['show_stats'] ) ); ?> />
			显示字数、行数统计
		</label>
		<?php
	}

	public function field_base_url() {
		$settings = $this->get();
		printf(
			'<input type="url" class="regular-text code" name="%1$s[imgurl_base_url]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['imgurl_base_url'] )
		);
		echo '<p class="description">只填地址，例如 <code>https://www.imgurl.org</code>。插件会自动拼接 <code>/api/v3/upload</code> 和 <code>/api/v3/album_list</code>。</p>';
	}

	public function field_token() {
		$constant = $this->is_constant_token();
		$token    = $this->get_token();
		?>
		<input type="password" class="regular-text" name="<?php echo esc_attr( self::TOKEN_OPTION ); ?>" id="zmd-imgurl-token" value="" autocomplete="new-password" <?php disabled( $constant ); ?> />
		<?php if ( $constant ) : ?>
			<p class="description">已通过 <code>ZMD_IMGURL_TOKEN</code> 常量配置，设置页不保存 Token。</p>
		<?php elseif ( '' !== $token ) : ?>
			<p class="description">已保存：<code><?php echo esc_html( $this->mask_token( $token ) ); ?></code>。留空表示不修改。</p>
			<label><input type="checkbox" name="zmd_clear_token" value="1" /> 清除 Token</label>
		<?php else : ?>
			<p class="description">在 ImgURL 控制台 → 开放接口获取，<code>sk-</code> 开头。</p>
		<?php endif; ?>
		<?php
	}

	public function field_album_id() {
		$settings = $this->get();
		$album_id = absint( $settings['imgurl_album_id'] );
		?>
		<select name="<?php echo esc_attr( self::OPTION ); ?>[imgurl_album_id]" id="zmd-album-id">
			<option value="0" <?php selected( $album_id, 0 ); ?>>默认相册（0）</option>
			<?php if ( $album_id > 0 ) : ?>
				<option value="<?php echo esc_attr( $album_id ); ?>" selected="selected"><?php echo esc_html( '#' . $album_id . '（已保存）' ); ?></option>
			<?php endif; ?>
		</select>
		<button type="button" class="button" id="zmd-refresh-albums">刷新相册</button>
		<p class="description">点击“刷新相册”调用相册接口；也可以保持默认相册 0。</p>
		<?php
	}

	public function field_flags() {
		$settings = $this->get();
		?>
		<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[imgurl_dedup]" value="1" <?php checked( ! empty( $settings['imgurl_dedup'] ) ); ?> /> 按内容去重（dedup）</label><br />
		<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[imgurl_watermark]" value="1" <?php checked( ! empty( $settings['imgurl_watermark'] ) ); ?> /> 文字水印（需要账号开启）</label><br />
		<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[imgurl_compress]" value="1" <?php checked( ! empty( $settings['imgurl_compress'] ) ); ?> /> 压缩图片</label>
		<?php
	}

	public function field_max_size() {
		$settings = $this->get();
		printf(
			'<input type="number" min="1" max="100" step="1" class="small-text" name="%1$s[imgurl_max_size]" value="%2$s" /> MB',
			esc_attr( self::OPTION ),
			esc_attr( $settings['imgurl_max_size'] )
		);
		echo '<p class="description">实际生效上限：<strong>' . esc_html( $this->effective_max_size() . ' MB' ) . '</strong>（受 PHP upload_max_filesize / post_max_size 限制）。</p>';
	}

	public function field_url_path() {
		$settings = $this->get();
		printf(
			'<input type="text" class="regular-text code" name="%1$s[imgurl_url_path]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['imgurl_url_path'] )
		);
		echo '<p class="description">默认 <code>data.url</code>。兼容其它图床时可改成例如 <code>data.links.url</code>。</p>';
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Validate an endpoint URL.
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $default Default value.
	 * @param string $code    Error code.
	 * @param string $label   Field label.
	 * @return string
	 */
	private function sanitize_base_url( $value, $default ) {
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return $default;
		}
		$url    = esc_url_raw( $value );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			add_settings_error( self::OPTION, 'zmd_base_url', 'ImgURL 地址必须是 http/https 地址，已恢复默认值。', 'error' );
			return $default;
		}
		$url = rtrim( $url, '/' );
		// Be forgiving if a full endpoint was pasted instead of the base URL.
		$url = preg_replace( '#/(?:api/v3/(?:upload|album_list))$#', '', $url );
		return ( is_string( $url ) && '' !== $url ) ? $url : $default;
	}

	/**
	 * Mask a token for display.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private function mask_token( $token ) {
		$token = (string) $token;
		$len   = strlen( $token );
		if ( $len <= 8 ) {
			return str_repeat( '•', max( 4, $len ) );
		}
		return substr( $token, 0, 4 ) . '…' . substr( $token, -4 );
	}
}
