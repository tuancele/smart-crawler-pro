<?php
/**
 * Plugin Name: Smart Crawler Pro (Ultimate V18 - Logger System)
 * Description: Plugin tự động lấy tin, upload R2, Share FB và ghi Log lỗi chi tiết.
 * Version: 18.0
 * Author: Gemini Expert
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCP_PATH', plugin_dir_path( __FILE__ ) );

// Nạp các file chức năng
require_once SCP_PATH . 'includes/class-logger.php'; // <--- FILE MỚI
require_once SCP_PATH . 'includes/class-media.php';
require_once SCP_PATH . 'includes/class-facebook.php';

// Nạp Adapters
require_once SCP_PATH . 'includes/adapters/abstract-adapter.php';
require_once SCP_PATH . 'includes/adapters/class-adapter-wp.php';
require_once SCP_PATH . 'includes/adapters/class-adapter-blogspot.php';

require_once SCP_PATH . 'includes/class-crawler.php';
require_once SCP_PATH . 'includes/class-cron.php';
require_once SCP_PATH . 'includes/class-admin.php';

class Smart_Crawler_Pro {
    public function __construct() {
        $logger   = new SCP_Logger(); // Khởi tạo Logger
        $media    = new SCP_Media( $logger ); // Truyền Logger vào Media
        $facebook = new SCP_Facebook();
        $crawler  = new SCP_Crawler( $media, $facebook ); 
        
        new SCP_Cron( $crawler );
        new SCP_Admin( $crawler, $logger ); // Truyền Logger vào Admin để hiển thị
    }
}

// Hook tạo bảng khi active plugin
register_activation_hook( __FILE__, function() {
    require_once SCP_PATH . 'includes/class-logger.php';
    $logger = new SCP_Logger();
    $logger->create_table();
});

new Smart_Crawler_Pro();