<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Cron {
    private $crawler;
    private $media_worker; // Instance xử lý media
    private $hook_batch = 'scp_cron_batch_event'; 
    private $hook_media = 'scp_cron_media_worker'; // Hook mới cho Media

    public function __construct( $crawler_instance ) {
        $this->crawler = $crawler_instance;
        // Lấy media instance từ crawler ra để gọi hàm xử lý queue
        $this->media_worker = $crawler_instance->get_media_instance();

        add_filter( 'cron_schedules', [ $this, 'add_intervals' ] );
        add_action( $this->hook_batch, [ $this, 'process_batch_job' ] );
        
        // Đăng ký Action cho Worker xử lý Media
        add_action( $this->hook_media, [ $this, 'process_media_queue' ] );
        
        register_activation_hook( dirname(dirname(__FILE__)) . '/smart-crawler-pro.php', [ $this, 'activate' ] );
        register_deactivation_hook( dirname(dirname(__FILE__)) . '/smart-crawler-pro.php', [ $this, 'deactivate' ] );
    }

    public function add_intervals( $schedules ) {
        // Thêm lịch chạy mỗi 1 phút
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'Every Minute (Media Worker)'
        ];
        return $schedules;
    }

    // Job chính: Crawl bài (Chạy theo chuỗi - Chain)
    public function start_background_job() {
        update_option( 'scp_crawler_state', [
            'source_index' => 0, 'page' => 1, 'is_running' => true, 'log' => 'Khởi động...'
        ]);
        wp_clear_scheduled_hook( $this->hook_batch );
        wp_schedule_single_event( time(), $this->hook_batch );
        
        // Kích hoạt luôn Media Worker chạy song song
        if ( ! wp_next_scheduled( $this->hook_media ) ) {
            wp_schedule_event( time(), 'every_minute', $this->hook_media );
        }
    }

    public function process_batch_job() {
        $should_continue = $this->crawler->process_single_batch();
        if ( $should_continue ) {
            wp_schedule_single_event( time() + 300, $this->hook_batch ); // Nghỉ 5 phút
        } else {
            // Khi crawl xong hết, ta KHÔNG tắt Media Worker ngay
            // Để nó chạy nốt cho hết hàng đợi
            $state = get_option('scp_crawler_state');
            $state['is_running'] = false;
            $state['log'] = 'Crawl xong. Đang xử lý media ngầm...';
            update_option('scp_crawler_state', $state);
        }
    }

    // Job phụ: Xử lý hàng đợi Media (Chạy mỗi phút)
    public function process_media_queue() {
        // Mỗi lần chạy xử lý 1 video để tránh quá tải
        $this->media_worker->process_queue_item();
    }

    public function activate() {
        if ( ! wp_next_scheduled( $this->hook_media ) ) {
            wp_schedule_event( time(), 'every_minute', $this->hook_media );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( $this->hook_batch );
        wp_clear_scheduled_hook( $this->hook_media );
        delete_option( 'scp_crawler_state' );
    }
}