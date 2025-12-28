<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Crawler {
    private $option_sources = 'scp_sources';
    private $media;
    private $facebook;
    
    private $posts_per_batch = 5; 
    private $max_empty_pages = 5;
    
    private $remove_phrases = [ 'xem thêm', 'bài viết liên quan', 'theo nguồn', 'nguồn:', 'source:', 'đăng bởi', 'nhãn:' ];

    public function __construct( $media_instance, $facebook_instance ) {
        $this->media    = $media_instance;
        $this->facebook = $facebook_instance;
    }

    public function process_single_batch() {
        set_time_limit(0); 
        ini_set('memory_limit', '1024M');

        $sources = get_option( $this->option_sources, [] );
        if ( empty( $sources ) ) return false;

        $state = get_option( 'scp_crawler_state', [ 'source_index' => 0, 'page' => 1, 'empty_count' => 0, 'log' => '' ] );
        $s_idx = $state['source_index'] ?? 0;
        $page  = $state['page'] ?? 1;
        $empty_count = $state['empty_count'] ?? 0;

        if ( ! isset( $sources[ $s_idx ] ) ) return false;

        $current_source = $sources[ $s_idx ];
        $url = $current_source['url'];

        // --- FACTORY: CHỌN ADAPTER PHÙ HỢP ---
        $adapter = null;
        
        // 1. Thử Blogspot trước
        $blogger = new SCP_Adapter_Blogspot();
        if ( $blogger->is_platform( $url ) ) {
            $adapter = $blogger;
        } else {
            // 2. Mặc định là WP
            $adapter = new SCP_Adapter_WP();
        }
        // --------------------------------------

        // Gọi Adapter lấy bài
        $posts_list = $adapter->fetch_posts( $url, $page, $this->posts_per_batch );

        if ( is_wp_error( $posts_list ) ) {
            return $this->advance_to_next_source( $state, "Lỗi: " . $posts_list->get_error_message() );
        }
        if ( empty( $posts_list ) ) {
            return $this->advance_to_next_source( $state, "Trang $page trả về rỗng (Hết bài)." );
        }

        // --- QUY TRÌNH IMPORT CHUNG (Không cần sửa khi thêm nguồn mới) ---
        $imported_count = 0;
        $logs_detail = [];

        foreach ( $posts_list as $post_data ) {
            // post_data bây giờ đã được chuẩn hóa (id, title, content, tags, featured_img)
            if ( $this->post_exists( $post_data->id, $url ) ) continue;

            $post_id = $this->insert_post( $post_data, $current_source['cat_id'] );
            
            if ( $post_id ) {
                update_post_meta( $post_id, '_scp_original_id', $post_data->id );
                update_post_meta( $post_id, '_scp_source_url', $url );
                update_post_meta( $post_id, '_scp_original_link', $post_data->original_link );

                // Tags
                if ( ! empty( $post_data->tags ) ) wp_set_post_tags( $post_id, $post_data->tags, true );

                // Featured Image
                $featured_ok = false;
                if ( ! empty( $post_data->featured_img ) ) {
                    $res = $this->media->sideload( $post_data->featured_img, $post_id, true );
                    if ( $res && !empty($res['id']) ) $featured_ok = true;
                }

                // Content Processing
                $c_res = $this->media->process_content( $post_id, $post_data->content, $url, $this->remove_phrases );

                // Fallback Image
                if ( ! $featured_ok && ! empty( $c_res['first_image_id'] ) ) {
                    set_post_thumbnail( $post_id, $c_res['first_image_id'] );
                }

                // FB Share
                $this->facebook->share( $post_id );
                
                $imported_count++;
                $logs_detail[] = mb_substr($post_data->title, 0, 15) . "..";
            }
        }

        // Logic chuyển trang/nguồn (Giữ nguyên)
        if ( $imported_count == 0 ) {
            $state['empty_count'] = $empty_count + 1;
        } else {
            $state['empty_count'] = 0;
            $sources[ $s_idx ]['last_run'] = current_time( 'mysql' );
            $sources[ $s_idx ]['last_count'] = ($sources[ $s_idx ]['last_count'] ?? 0) + $imported_count;
            update_option( $this->option_sources, $sources );
        }

        $log_msg = "Nguồn #{$s_idx} (".get_class($adapter).") - Page {$page}: +{$imported_count} bài.";
        if(!empty($logs_detail)) $log_msg .= " (" . implode(', ', $logs_detail) . ")";

        if ( $state['empty_count'] >= $this->max_empty_pages ) {
            return $this->advance_to_next_source( $state, "Skip #{$s_idx} do trùng lặp." );
        }

        $state['page'] = $page + 1;
        $state['log'] = $log_msg . " - Next: " . date('H:i:s', time() + 300);
        update_option( 'scp_crawler_state', $state );

        return true; 
    }

    // --- HELPER METHODS ---
    private function post_exists( $oid, $url ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_scp_original_id' AND meta_value = %s LIMIT 1", $oid );
        $result = $wpdb->get_var($query);
        if ($result) {
            $url_check = get_post_meta($result, '_scp_source_url', true);
            if ($url_check === $url) return true;
        }
        return false;
    }

    private function insert_post( $data, $cat_id ) {
        return wp_insert_post([
            'post_title'    => sanitize_text_field( $data->title ),
            'post_content'  => $data->content,
            'post_status'   => 'publish',
            'post_type'     => 'post',
            'post_category' => [ $cat_id ],
            'post_date'     => date('Y-m-d H:i:s', strtotime($data->date))
        ]);
    }

    private function advance_to_next_source( $state, $reason = '' ) {
        $state['source_index'] = $state['source_index'] + 1;
        $state['page'] = 1;
        $state['empty_count'] = 0;
        $state['log'] = $reason . " -> Next Source.";
        update_option( 'scp_crawler_state', $state );
        
        $sources = get_option( $this->option_sources, [] );
        if ( isset( $sources[ $state['source_index'] ] ) ) return true;
        
        $state['is_running'] = false;
        $state['log'] = "ĐÃ HOÀN TẤT.";
        update_option( 'scp_crawler_state', $state );
        return false;
    }
}