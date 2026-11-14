<?php
namespace ZMD\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints: upload proxy and album list.
 */
final class Upload {

	/** @var Settings */
	private $settings;

	/** @var ImgURL */
	private $imgurl;

	/**
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->imgurl   = new ImgURL( $settings );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			'zmd/v1',
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => array( $this, 'can_upload' ),
			)
		);

		register_rest_route(
			'zmd/v1',
			'/albums',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_albums' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Upload capability.
	 *
	 * @return bool
	 */
	public function can_upload() {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Settings capability.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle an image upload.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_upload( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'zmd_no_file', '没有收到图片文件。', array( 'status' => 400 ) );
		}

		$file = $files['file'];
		if ( ! isset( $file['name'] ) || is_array( $file['name'] ) ) {
			return new \WP_Error( 'zmd_one_file', '一次只能上传一张图片。', array( 'status' => 400 ) );
		}
		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error( 'zmd_upload_error', $this->upload_error_message( (int) $file['error'] ), array( 'status' => 400 ) );
		}

		$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'zmd_invalid_upload', '上传文件无效。', array( 'status' => 400 ) );
		}

		$max_mb    = $this->settings->effective_max_size();
		$max_bytes = $max_mb * MB_IN_BYTES;
		$size      = isset( $file['size'] ) ? (int) $file['size'] : (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 ) {
			return new \WP_Error( 'zmd_empty_file', '图片文件为空。', array( 'status' => 400 ) );
		}
		if ( $size > $max_bytes ) {
			return new \WP_Error( 'zmd_too_large', '图片超过 ' . $max_mb . ' MB 限制。', array( 'status' => 413 ) );
		}

		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return new \WP_Error( 'zmd_not_image', '文件不是有效的图片（支持 JPG、PNG、GIF、WebP、BMP、ICO）。', array( 'status' => 400 ) );
		}

		$type = isset( $info[2] ) ? (int) $info[2] : 0;
		$mime = $this->mime_for_type( $type );
		if ( '' === $mime ) {
			return new \WP_Error( 'zmd_bad_type', '不支持该图片格式。', array( 'status' => 400 ) );
		}

		// Simple per-user throttle: 30 uploads per minute.
		$rate_key = 'zmd_upload_rate_' . get_current_user_id();
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 30 ) {
			return new \WP_Error( 'zmd_rate_limit', '上传过于频繁，请稍后再试。', array( 'status' => 429 ) );
		}
		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		$result = $this->imgurl->upload( $tmp, (string) $file['name'], $mime );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Handle the album list request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_albums( $request ) {
		$result = $this->imgurl->albums();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'items' => $result ) );
	}

	/**
	 * Map a GD image type to a whitelisted MIME type.
	 *
	 * @param int $type IMAGETYPE_* constant.
	 * @return string
	 */
	private function mime_for_type( $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return 'image/jpeg';
			case IMAGETYPE_PNG:
				return 'image/png';
			case IMAGETYPE_GIF:
				return 'image/gif';
			case IMAGETYPE_WEBP:
				return 'image/webp';
			case IMAGETYPE_BMP:
				return 'image/bmp';
			case IMAGETYPE_ICO:
				return 'image/x-icon';
		}
		return '';
	}

	/**
	 * Human-readable PHP upload error.
	 *
	 * @param int $code UPLOAD_ERR_* constant.
	 * @return string
	 */
	private function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return '图片超过服务器上传大小限制。';
			case UPLOAD_ERR_PARTIAL:
				return '图片只上传了一部分，请重试。';
			case UPLOAD_ERR_NO_FILE:
				return '没有选择图片。';
			case UPLOAD_ERR_NO_TMP_DIR:
				return '服务器缺少临时目录。';
			case UPLOAD_ERR_CANT_WRITE:
				return '服务器无法写入临时文件。';
			case UPLOAD_ERR_EXTENSION:
				return '上传被服务器扩展阻止。';
			default:
				return '图片上传失败。';
		}
	}
}
