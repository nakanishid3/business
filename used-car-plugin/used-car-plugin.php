<?php
/**
 * Plugin Name: 中古車情報管理プラグイン
 * Description: 販売者登録・中古車情報管理・公開一覧をショートコードで提供するプラグイン
 * Version: 1.0.0
 * Author: Used Car Plugin
 * License: GPL v2 or later
 * Text Domain: used-car-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UCP_VERSION',    '1.0.0' );
define( 'UCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'UCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'UCP_PLUGIN_FILE', __FILE__ );

require_once UCP_PLUGIN_DIR . 'includes/class-ucp-database.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-auth.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-seller.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-car.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-upload.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-shortcodes.php';
require_once UCP_PLUGIN_DIR . 'includes/class-ucp-admin.php';

register_activation_hook( __FILE__, array( 'UCP_Database', 'create_tables' ) );

add_action( 'plugins_loaded', function () {
    UCP_Shortcodes::init();
    if ( is_admin() ) {
        UCP_Admin::init();
    }
} );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'usedcar-style',
        UCP_PLUGIN_URL . 'assets/css/usedcar.css',
        array(),
        UCP_VERSION
    );
    wp_enqueue_script(
        'usedcar-script',
        UCP_PLUGIN_URL . 'assets/js/usedcar.js',
        array( 'jquery' ),
        UCP_VERSION,
        true
    );
    wp_localize_script( 'usedcar-script', 'ucpData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'ucp_nonce' ),
        'pluginUrl' => UCP_PLUGIN_URL,
    ) );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( strpos( $hook, 'used-car' ) !== false ) {
        wp_enqueue_style(
            'usedcar-admin-style',
            UCP_PLUGIN_URL . 'assets/css/usedcar.css',
            array(),
            UCP_VERSION
        );
    }
} );
