<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Crawler {
    private $option_sources = 'scp_sources';
    private $media;
    private $facebook;
    
    // Cấu hình Crawler
    private $posts_per_batch = 5; // Số bài mỗi lần chạy
    private $remove_phrases = [ 
        'xem thêm', 'bài viết liên quan', 'theo nguồn', 'nguồn:', 'source:', 
        'đăng bởi', 'nhãn:', 'chia sẻ', 'bấm vào đây', 'tại đây' 
    ];

    public function __construct( $media_instance, $facebook_instance ) {
        $this->media    = $media_instance;
        $this->facebook = $facebook_instance;
    }

    /**
     * Helper để Cron/Admin gọi lấy instance Media
     */
    public function get_media_instance() {
        return $this->media;
    }

    /**
     * CORE FUNCTION: Xử lý 1 mẻ (Batch) theo cơ chế Round-Robin
     */
    public function process_single_batch() {
        // Tăng giới hạn tài nguyên để xử lý media nặng
        set_time_limit(0); 
        ini_set('memory_limit', '1024M');

        // 1. Lấy danh sách nguồn
        $sources = get_option( $this->option_sources, [] );
        if ( empty( $sources ) ) return false;

        // 2. Lấy trạng thái hiện tại (Đang ở nguồn nào?)
        $state = get_option( 'scp_crawler_state', [ 'source_index' => 0, 'log' => '' ] );
        
        $s_idx = isset($state['source_index']) ? intval($state['source_index']) : 0;
        
        // Reset index nếu vượt quá số lượng nguồn (Vòng lặp Round-Robin)
        if ( $s_idx >= count($sources) ) {
            $s_idx = 0;
        }

        $current_source = $sources[ $s_idx ];
        $url = $current_source['url'];

        // Lấy trang hiện tại của nguồn này (Mặc định là 1)
        $page = isset($current_source['current_page']) ? intval($current_source['current_page']) : 1;
        if ( $page < 1 ) $page = 1;

        // 3. Chọn Adapter phù hợp (Factory Pattern)
        $adapter = null;
        $blogger = new SCP_Adapter_Blogspot();
        
        if ( $blogger->is_platform( $url ) ) {
            $adapter = $blogger;
        } else {
            $adapter = new SCP_Adapter_WP();
        }

        // 4. Gọi API lấy bài viết
        $posts_list = $adapter->fetch_posts( $url, $page, $this->posts_per_batch );

        // Xử lý trường hợp lỗi hoặc hết bài
        if ( is_wp_error( $posts_list ) || empty( $posts_list ) ) {
            // Log lý do
            $err_msg = is_wp_error($posts_list) ? $posts_list->get_error_message() : "Hết bài hoặc trang rỗng";
            
            // Logic quan trọng: Nếu lỗi/hết bài -> Vẫn tăng page lên để lần sau tìm sâu hơn (hoặc reset tùy logic)
            // Ở đây ta tăng page để tránh kẹt mãi ở trang lỗi
            $sources[ $s_idx ]['current_page'] = $page + 1;
            update_option( $this->option_sources, $sources );

            return $this->advance_source_round_robin( $state, $sources, $s_idx, "Nguồn #$s_idx ($url): $err_msg. Next." );
        }

        // 5. Bắt đầu Import
        $imported_count = 0;
        $logs_detail = [];

        foreach ( $posts_list as $post_data ) {
            // Kiểm tra trùng lặp
            if ( $this->post_exists( $post_data->id, $url ) ) continue;

            // Tạo bài viết mới
            $post_id = $this->insert_post( $post_data, $current_source['cat_id'] );
            
            if ( $post_id ) {
                // Lưu meta để tránh trùng lặp sau này
                update_post_meta( $post_id, '_scp_original_id', $post_data->id );
                update_post_meta( $post_id, '_scp_source_url', $url );
                update_post_meta( $post_id, '_scp_original_link', $post_data->original_link );

                // Gán Tags
                if ( ! empty( $post_data->tags ) ) wp_set_post_tags( $post_id, $post_data->tags, true );

                // Xử lý Ảnh đại diện (Featured Image)
                $featured_ok = false;
                if ( ! empty( $post_data->featured_img ) ) {
                    // Thử tải 2 lần, nếu lỗi thì bỏ qua (không set featured)
                    $res = $this->media->sideload_with_retry( $post_data->featured_img, $post_id, 2 );
                    if ( $res && !empty($res['id']) ) $featured_ok = true;
                }

                // Xử lý Nội dung (Ảnh, Video, Clean HTML)
                // Hàm này trong class-media.php V16 đã có cơ chế Retry & Fallback
                $c_res = $this->media->process_content( $post_id, $post_data->content, $url, $this->remove_phrases );

                // Nếu không có ảnh đại diện -> Lấy ảnh đầu tiên trong bài (đã tải về thành công)
                if ( ! $featured_ok && ! empty( $c_res['first_image_id'] ) ) {
                    set_post_thumbnail( $post_id, $c_res['first_image_id'] );
                }

                // Share Facebook
                $this->facebook->share( $post_id );
                
                $imported_count++;
                $logs_detail[] = mb_substr($post_data->title, 0, 20) . "..";
            }
        }

        // 6. Cập nhật thông tin nguồn sau khi chạy xong
        $sources[ $s_idx ]['last_run'] = current_time( 'mysql' );
        $sources[ $s_idx ]['last_count'] = ($sources[ $s_idx ]['last_count'] ?? 0) + $imported_count;
        $sources[ $s_idx ]['current_page'] = $page + 1; // Tăng số trang cho lần chạy sau
        
        update_option( $this->option_sources, $sources );

        // 7. Chuyển sang nguồn kế tiếp
        $log_msg = "Xong Nguồn #{$s_idx} - Page {$page}: +{$imported_count} bài.";
        if(!empty($logs_detail)) $log_msg .= " (" . implode(', ', $logs_detail) . ")";
        
        return $this->advance_source_round_robin( $state, $sources, $s_idx, $log_msg );
    }

    /**
     * TÍNH NĂNG: RECHECK & FIX BROKEN MEDIA
     * Quét các bài viết cũ để tải lại ảnh/video bị lỗi
     */
    public function fix_broken_media( $limit = 10 ) {
        // Lấy danh sách bài đã crawl
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => $limit,
            'meta_key'       => '_scp_source_url', // Chỉ lấy bài do plugin tạo
            'orderby'        => 'modified',        // Ưu tiên bài mới cập nhật gần đây
            'order'          => 'DESC',
        ];

        $posts = get_posts( $args );
        $fixed_count = 0;
        $log = [];

        foreach ( $posts as $post ) {
            $content_orig = $post->post_content;
            $source_url   = get_post_meta( $post->ID, '_scp_source_url', true );
            
            // Chạy lại quy trình xử lý media
            // Hàm này sẽ tự tìm các link chưa tải (link ngoài) và tải về (Retry 3 lần)
            // Nếu tải được -> Thay thế link ngoài bằng link nội bộ
            $result = $this->media->process_content( $post->ID, $content_orig, $source_url, $this->remove_phrases );
            
            // Kiểm tra xem nội dung có thay đổi không (có link nào được sửa không)
            // Lưu ý: process_content đã tự gọi wp_update_post rồi
            if ( strlen($result['content']) != strlen($content_orig) || $result['content'] !== $content_orig ) {
                $fixed_count++;
                $log[] = "ID {$post->ID}";
            }
        }

        return [
            'count' => $fixed_count,
            'log'   => implode(', ', $log)
        ];
    }

    /**
     * Helper: Chuyển sang nguồn tiếp theo (Vòng tròn)
     */
    private function advance_source_round_robin( $state, $sources, $current_idx, $msg ) {
        $next_idx = $current_idx + 1;
        
        // Quay vòng về 0 nếu hết danh sách
        if ( $next_idx >= count($sources) ) {
            $next_idx = 0;
        }

        $state['source_index'] = $next_idx;
        $state['log'] = $msg . " -> Chuyển Nguồn #$next_idx.";
        update_option( 'scp_crawler_state', $state );

        return true; // Trả về true để Cron biết là cần chạy tiếp
    }

    /**
     * Helper: Kiểm tra bài đã tồn tại chưa
     */
    private function post_exists( $oid, $url ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_scp_original_id' AND meta_value = %s LIMIT 1", $oid );
        $result = $wpdb->get_var($query);
        
        // Nếu tìm thấy ID gốc, check thêm URL nguồn để chắc chắn (tránh ID trùng giữa các site khác nhau)
        if ( $result ) {
            $url_check = get_post_meta($result, '_scp_source_url', true);
            // So sánh tương đối domain
            if ( $url_check && strpos($url_check, parse_url($url, PHP_URL_HOST)) !== false ) return true;
        }
        return false;
    }

    /**
     * Helper: Chèn bài viết vào CSDL
     */
    private function insert_post( $data, $cat_id ) {
        return wp_insert_post([
            'post_title'    => sanitize_text_field( $data->title ),
            'post_content'  => $data->content, // Nội dung thô, sẽ được process_content xử lý sau
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_category' => [ $cat_id ],
            'post_date'     => date('Y-m-d H:i:s', strtotime($data->date))
        ]);
    }
}