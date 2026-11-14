<?php
namespace ZMD\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * ImgURL v3 API client (server-side proxy).
 */
final class ImgURL {

	/** @var Settings */
	private $settings;

	/**
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Upload one image.
	 *
	 * @param string $tmp_path      Temporary file path.
	 * @param string $original_name Original filename.
	 * @param string $mime          Validated MIME type.
	 * @return array|\WP_Error
	 */
	public function upload( $tmp_path, $original_name, $mime ) {
		$token = $this->settings->get_token();
		if ( '' === $token ) {
			return new \WP_Error( 'zmd_no_token', '尚未配置 ImgURL Token。', array( 'status' => 400 ) );
		}

		$settings = $this->settings->get();
		$base     = isset( $settings['imgurl_base_url'] ) ? rtrim( (string) $settings['imgurl_base_url'], '/' ) : '';
		if ( '' === $base ) {
			return new \WP_Error( 'zmd_no_endpoint', '尚未配置 ImgURL 地址。', array( 'status' => 400 ) );
		}
		$endpoint = $base . '/api/v3/upload';

		$params = array(
			'album_id'  => absint( $settings['imgurl_album_id'] ),
			'dedup'     => (bool) $settings['imgurl_dedup'],
			'watermark' => (bool) $settings['imgurl_watermark'],
			'compress'  => (bool) $settings['imgurl_compress'],
		);

		$ext      = $this->extension_for_mime( $mime );
		$filename = 'zmd-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;

		$boundary  = '----ZMD' . wp_generate_password( 24, false, false );
		$file_data = @file_get_contents( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $file_data ) {
			return new \WP_Error( 'zmd_read_failed', '无法读取上传的图片文件。', array( 'status' => 500 ) );
		}

		$eol   = "\r\n";
		$body  = '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $eol;
		$body .= 'Content-Type: ' . $mime . $eol . $eol;
		$body .= $file_data . $eol;
		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="params"' . $eol . $eol;
		$body .= wp_json_encode( $params ) . $eol;
		$body .= '--' . $boundary . '--' . $eol;

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'zmd_http_error', '无法连接 ImgURL：' . $response->get_error_message(), array( 'status' => 502 ) );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw       = (string) wp_remote_retrieve_body( $response );
		$json      = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'zmd_bad_response', 'ImgURL 返回格式不正确（HTTP ' . $http_code . '）。', array( 'status' => 502 ) );
		}

		$code = isset( $json['code'] ) ? (int) $json['code'] : 0;
		if ( 200 !== $code ) {
			$msg    = isset( $json['msg'] ) ? (string) $json['msg'] : '';
			$status = 429 === $http_code ? 429 : 400;
			return new \WP_Error(
				'zmd_imgurl_error',
				$this->friendly_error( $msg ),
				array(
					'status'     => $status,
					'imgurl_msg' => $msg,
				)
			);
		}

		$url = $this->value_by_path( $json, $settings['imgurl_url_path'] );
		if ( ! is_string( $url ) || '' === $url ) {
			return new \WP_Error( 'zmd_no_url', 'ImgURL 返回成功，但没有找到图片 URL；请检查“响应 URL 路径”。', array( 'status' => 502 ) );
		}

		$url = esc_url_raw( $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return new \WP_Error( 'zmd_bad_url', 'ImgURL 返回的 URL 不合法。', array( 'status' => 502 ) );
		}

		$data = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();

		return array(
			'url'      => $url,
			'imgid'    => isset( $data['imgid'] ) ? (string) $data['imgid'] : '',
			'width'    => isset( $data['width'] ) ? (int) $data['width'] : 0,
			'height'   => isset( $data['height'] ) ? (int) $data['height'] : 0,
			'filename' => isset( $data['filename'] ) ? (string) $data['filename'] : $filename,
			'size'     => isset( $data['size'] ) ? (int) $data['size'] : 0,
		);
	}

	/**
	 * Fetch albums for the test button and album picker.
	 *
	 * @return array|\WP_Error
	 */
	public function albums() {
		$token = $this->settings->get_token();
		if ( '' === $token ) {
			return new \WP_Error( 'zmd_no_token', '尚未配置 ImgURL Token。', array( 'status' => 400 ) );
		}

		$settings = $this->settings->get();
		$base     = isset( $settings['imgurl_base_url'] ) ? rtrim( (string) $settings['imgurl_base_url'], '/' ) : '';
		if ( '' === $base ) {
			return new \WP_Error( 'zmd_no_endpoint', '尚未配置 ImgURL 地址。', array( 'status' => 400 ) );
		}
		$endpoint = $base . '/api/v3/album_list';

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'zmd_http_error', '无法连接 ImgURL：' . $response->get_error_message(), array( 'status' => 502 ) );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw       = (string) wp_remote_retrieve_body( $response );
		$json      = json_decode( $raw, true );

		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'zmd_bad_response', 'ImgURL 返回格式不正确（HTTP ' . $http_code . '）。', array( 'status' => 502 ) );
		}

		$code = isset( $json['code'] ) ? (int) $json['code'] : 0;
		if ( 200 !== $code ) {
			$msg = isset( $json['msg'] ) ? (string) $json['msg'] : '';
			return new \WP_Error( 'zmd_imgurl_error', $this->friendly_error( $msg ), array( 'status' => 400 ) );
		}

		$items = array();
		if ( isset( $json['data']['items'] ) && is_array( $json['data']['items'] ) ) {
			foreach ( $json['data']['items'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$items[] = array(
					'album_id' => isset( $item['album_id'] ) ? (int) $item['album_id'] : 0,
					'name'     => isset( $item['name'] ) ? (string) $item['name'] : '',
				);
			}
		}

		return $items;
	}

	/**
	 * Resolve a dot-notation path in the API response.
	 *
	 * @param array  $data Response data.
	 * @param string $path Dot path, e.g. data.url.
	 * @return mixed|null
	 */
	private function value_by_path( $data, $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return null;
		}

		$current = $data;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return null;
			}
			$current = $current[ $part ];
		}
		return $current;
	}

	/**
	 * Extension used for the proxied multipart filename.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function extension_for_mime( $mime ) {
		$map = array(
			'image/jpeg'               => 'jpg',
			'image/png'                => 'png',
			'image/gif'                => 'gif',
			'image/webp'               => 'webp',
			'image/bmp'                => 'bmp',
			'image/x-icon'             => 'ico',
			'image/vnd.microsoft.icon' => 'ico',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'jpg';
	}

	/**
	 * Map ImgURL error codes to readable Chinese.
	 *
	 * @param string $msg Raw msg field.
	 * @return string
	 */
	private function friendly_error( $msg ) {
		$map = array(
			'invalid.token'            => 'ImgURL Token 无效、已禁用或已过期。',
			'no.sub'                   => 'ImgURL 账号没有有效套餐。',
			'unsupported.image.format' => 'ImgURL 不支持该图片格式。',
			'invalid.image'            => '图片已损坏或无法解码。',
			'image.too.small'          => '图片尺寸过小（宽或高小于 8 像素）。',
			'image.too.large'          => '图片尺寸过大。',
			'storage.quota.limit'      => 'ImgURL 存储空间已满。',
			'daily.limit.exceeded'     => 'ImgURL 今日上传次数已达上限。',
			'monthly.limit.exceeded'   => 'ImgURL 本月上传次数已达上限。',
			'invalid.album'            => '相册不存在或不属于当前账号。',
			'invalid.params'           => '上传参数不合法。',
			'upload.too.frequent'      => '上传太频繁，请稍后再试。',
		);

		if ( isset( $map[ $msg ] ) ) {
			return $map[ $msg ];
		}
		if ( 0 === strpos( $msg, 'Exceeds the maximum size limit of' ) ) {
			return '图片超过 ImgURL 套餐的单张大小限制。';
		}
		return '' === $msg ? 'ImgURL 请求失败（未知错误）。' : 'ImgURL 返回错误：' . $msg;
	}
}
