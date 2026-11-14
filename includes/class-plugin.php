<?php
namespace ZMD\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Settings */
	private $settings;

	/** @var Editor */
	private $editor;

	/** @var Upload */
	private $upload;

	/**
	 * Singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register all hooks.
	 */
	public function init() {
		$this->settings = new Settings();
		$this->editor   = new Editor( $this->settings );
		$this->upload   = new Upload( $this->settings );

		$this->settings->init();
		$this->editor->init();
		$this->upload->init();
	}

	/**
	 * Activation: create default options.
	 */
	public static function activate() {
		if ( false === get_option( 'zmd_editor_settings', false ) ) {
			add_option( 'zmd_editor_settings', Settings::defaults() );
		}
		if ( false === get_option( 'zmd_imgurl_token', false ) ) {
			add_option( 'zmd_imgurl_token', '', '', 'no' );
		}
	}
}
