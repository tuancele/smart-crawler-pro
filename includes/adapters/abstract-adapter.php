<?php
if ( ! defined( 'ABSPATH' ) ) exit;

abstract class SCP_Adapter_Base {
    
    protected $user_agents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    /**
     * Hàm kiểm tra xem URL này có thuộc nền tảng của Adapter không
     * @return boolean
     */
    abstract public function is_platform( $url );

    /**
     * Hàm lấy dữ liệu bài viết
     * @return array Danh sách bài viết đã chuẩn hóa
     */
    abstract public function fetch_posts( $url, $page, $per_page );

    /**
     * Helper: Lấy ngẫu nhiên User Agent
     */
    protected function get_random_ua() {
        return $this->user_agents[ array_rand( $this->user_agents ) ];
    }

    /**
     * Helper: Gọi request (Dùng chung cho các con)
     */
    protected function request( $api_url ) {
        $args = [
            'timeout'   => 60,
            'sslverify' => false,
            'user-agent'=> $this->get_random_ua()
        ];
        return wp_remote_get( $api_url, $args );
    }
}