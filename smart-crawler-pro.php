<?php
/**
 * Plugin Name: Smart Crawler Pro (Modular V9)
 * Description: Plugin tự động lấy tin (WP & Blogspot), upload R2 và share Facebook. Cấu trúc Adapter Pattern.
 * Version: 9.0
 * Author: Gemini Expert
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCP_PATH', plugin_dir_path( __FILE__ ) );

// Nạp các file chức năng
require_once SCP_PATH . 'includes/class-media.php';
require_once SCP_PATH . 'includes/class-facebook.php';

// Nạp Adapters (QUAN TRỌNG: Nạp file này trước khi nạp Crawler)
require_once SCP_PATH . 'includes/adapters/abstract-adapter.php';
require_once SCP_PATH . 'includes/adapters/class-adapter-wp.php';
require_once SCP_PATH . 'includes/adapters/class-adapter-blogspot.php';

require_once SCP_PATH . 'includes/class-crawler.php';
require_once SCP_PATH . 'includes/class-cron.php';
require_once SCP_PATH . 'includes/class-admin.php';

class Smart_Crawler_Pro {
    public function __construct() {
        $media    = new SCP_Media();
        $facebook = new SCP_Facebook();
        $crawler  = new SCP_Crawler( $media, $facebook ); 
        
        new SCP_Cron( $crawler );
        new SCP_Admin( $crawler );
    }
}

new Smart_Crawler_Pro();