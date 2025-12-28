<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Logger {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'scp_error_logs';
    }

    /**
     * Tạo bảng database khi kích hoạt plugin
     */
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            media_url text NOT NULL,
            error_message text NOT NULL,
            error_code varchar(50) DEFAULT '' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Ghi log lỗi
     */
    public function log( $post_id, $media_url, $message, $code = 'unknown' ) {
        global $wpdb;
        // Kiểm tra xem lỗi này cho url này đã log chưa để tránh spam DB
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $this->table_name WHERE post_id = %d AND media_url = %s AND error_code = %s LIMIT 1",
            $post_id, $media_url, $code
        ) );

        if ( ! $exists ) {
            $wpdb->insert(
                $this->table_name,
                [
                    'post_id'       => $post_id,
                    'media_url'     => $media_url,
                    'error_message' => $message,
                    'error_code'    => $code,
                    'created_at'    => current_time( 'mysql' )
                ]
            );
        }
    }

    /**
     * Lấy danh sách log
     */
    public function get_logs( $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM $this->table_name ORDER BY created_at DESC LIMIT $limit", ARRAY_A );
    }

    /**
     * Xóa toàn bộ log
     */
    public function clear_logs() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE $this->table_name" );
    }
    
    /**
     * Đếm số lượng lỗi
     */
    public function count_errors() {
        global $wpdb;
        return $wpdb->get_var( "SELECT COUNT(*) FROM $this->table_name" );
    }
}