<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Cron {
    private $crawler;
    private $hook_batch = 'scp_cron_batch_event'; // Hook cho từng lần chạy nhỏ

    public function __construct( $crawler_instance ) {
        $this->crawler = $crawler_instance;

        // Đăng ký action cho Cron
        add_action( $this->hook_batch, [ $this, 'process_batch_job' ] );
        
        // Setup và Cleanup
        register_activation_hook( dirname(dirname(__FILE__)) . '/smart-crawler-pro.php', [ $this, 'activate' ] );
        register_deactivation_hook( dirname(dirname(__FILE__)) . '/smart-crawler-pro.php', [ $this, 'deactivate' ] );
    }

    /**
     * Hàm này được gọi khi bấm nút "Chạy ngay"
     */
    public function start_background_job() {
        // Reset trạng thái về ban đầu (Trang 1, Nguồn 0)
        update_option( 'scp_crawler_state', [
            'source_index' => 0,
            'page'         => 1,
            'is_running'   => true,
            'log'          => 'Bắt đầu chạy ngầm lúc ' . current_time('mysql')
        ]);

        // Xóa các cron cũ nếu có để tránh chạy chồng chéo
        wp_clear_scheduled_hook( $this->hook_batch );

        // Lên lịch chạy NGAY LẬP TỨC (single event)
        wp_schedule_single_event( time(), $this->hook_batch );
    }

    /**
     * Hàm xử lý chính (được Cron gọi)
     */
    public function process_batch_job() {
        // Gọi Crawler xử lý 1 mẻ (5 bài)
        $should_continue = $this->crawler->process_single_batch();

        if ( $should_continue ) {
            // Nếu Crawler bảo "Còn bài, chạy tiếp đi"
            // -> Đặt lịch chạy lần tới sau 5 PHÚT (300 giây)
            wp_schedule_single_event( time() + 300, $this->hook_batch );
            
            // Log trạng thái
            $state = get_option('scp_crawler_state');
            $state['log'] = 'Đã xong mẻ này. Nghỉ 5 phút... (Next: ' . date('H:i:s', time() + 300) . ')';
            update_option('scp_crawler_state', $state);
        } else {
            // Nếu xong hết -> Dừng, không đặt lịch nữa
            $state = get_option('scp_crawler_state');
            $state['is_running'] = false;
            $state['log'] = 'Hoàn tất toàn bộ quy trình lúc ' . current_time('mysql');
            update_option('scp_crawler_state', $state);
        }
    }

    public function activate() {
        // Không cần làm gì đặc biệt khi active
    }

    public function deactivate() {
        wp_clear_scheduled_hook( $this->hook_batch );
        delete_option( 'scp_crawler_state' );
    }
}