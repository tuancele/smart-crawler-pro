<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Crawler {
    private $option_sources = 'scp_sources';
    private $media;
    private $facebook;
    
    private $posts_per_batch = 5; 
    private $remove_phrases = [ 
        'xem thêm', 'bài viết liên quan', 'theo nguồn', 'nguồn:', 'source:', 
        'đăng bởi', 'nhãn:', 'chia sẻ', 'bấm vào đây', 'tại đây' 
    ];

    public function __construct( $media_instance, $facebook_instance ) {
        $this->media    = $media_instance;
        $this->facebook = $facebook_instance;
    }

    public function get_media_instance() {
        return $this->media;
    }

    public function process_single_batch() {
        set_time_limit(0); 
        ini_set('memory_limit', '1024M');

        $sources = get_option( $this->option_sources, [] );
        if ( empty( $sources ) ) return false;

        $state = get_option( 'scp_crawler_state', [ 'source_index' => 0, 'log' => '' ] );
        $s_idx = isset($state['source_index']) ? intval($state['source_index']) : 0;
        
        if ( $s_idx >= count($sources) ) $s_idx = 0;

        $current_source = $sources[ $s_idx ];
        $url = $current_source['url'];
        $page = isset($current_source['current_page']) ? intval($current_source['current_page']) : 1;
        if ( $page < 1 ) $page = 1;

        // Adapter Factory
        $adapter = null;
        $blogger = new SCP_Adapter_Blogspot();
        if ( $blogger->is_platform( $url ) ) $adapter = $blogger;
        else $adapter = new SCP_Adapter_WP();

        $posts_list = $adapter->fetch_posts( $url, $page, $this->posts_per_batch );

        if ( is_wp_error( $posts_list ) || empty( $posts_list ) ) {
            $err_msg = is_wp_error($posts_list) ? $posts_list->get_error_message() : "Hết bài";
            $sources[ $s_idx ]['current_page'] = $page + 1;
            update_option( $this->option_sources, $sources );
            return $this->advance_source_round_robin( $state, $sources, $s_idx, "Nguồn #$s_idx: $err_msg. Next." );
        }

        $imported_count = 0;
        $logs_detail = [];

        foreach ( $posts_list as $post_data ) {
            if ( $this->post_exists( $post_data->id, $url ) ) continue;

            $post_id = $this->insert_post( $post_data, $current_source['cat_id'] );
            
            if ( $post_id ) {
                update_post_meta( $post_id, '_scp_original_id', $post_data->id );
                update_post_meta( $post_id, '_scp_source_url', $url );
                update_post_meta( $post_id, '_scp_original_link', $post_data->original_link );

                if ( ! empty( $post_data->tags ) ) wp_set_post_tags( $post_id, $post_data->tags, true );

                // Featured Image
                $featured_ok = false;
                if ( ! empty( $post_data->featured_img ) ) {
                    $res = $this->media->sideload_with_retry( $post_data->featured_img, $post_id, 2 );
                    if ( $res && !empty($res['id']) ) $featured_ok = true;
                }

                // Process Content
                $c_res = $this->media->process_content( $post_id, $post_data->content, $url, $this->remove_phrases );

                if ( ! $featured_ok && ! empty( $c_res['first_image_id'] ) ) {
                    set_post_thumbnail( $post_id, $c_res['first_image_id'] );
                }

                // Share Facebook (Bọc trong Try-Catch)
                try {
                    $this->facebook->share( $post_id );
                } catch ( Exception $e ) {
                    error_log( 'SCP FB Error: ' . $e->getMessage() );
                }
                
                $imported_count++;
                $logs_detail[] = mb_substr($post_data->title, 0, 15) . "..";
            }
        }

        $sources[ $s_idx ]['last_run'] = current_time( 'mysql' );
        $sources[ $s_idx ]['last_count'] = ($sources[ $s_idx ]['last_count'] ?? 0) + $imported_count;
        $sources[ $s_idx ]['current_page'] = $page + 1;
        
        update_option( $this->option_sources, $sources );

        $log_msg = "Xong Nguồn #{$s_idx} - Page {$page}: +{$imported_count} bài.";
        if(!empty($logs_detail)) $log_msg .= " (" . implode(', ', $logs_detail) . ")";
        
        return $this->advance_source_round_robin( $state, $sources, $s_idx, $log_msg );
    }

    public function fix_broken_media( $limit = 10 ) {
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => $limit,
            'meta_key'       => '_scp_source_url',
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];

        $posts = get_posts( $args );
        $fixed_count = 0;
        $log = [];

        foreach ( $posts as $post ) {
            $content_orig = $post->post_content;
            $source_url   = get_post_meta( $post->ID, '_scp_source_url', true );
            
            // Recheck
            $result = $this->media->process_content( $post->ID, $content_orig, $source_url, $this->remove_phrases );
            
            // Check changes
            if ( strlen($result['content']) != strlen($content_orig) || $result['content'] !== $content_orig ) {
                $fixed_count++;
                $log[] = "ID {$post->ID}";
            }
        }

        return [ 'count' => $fixed_count, 'log' => implode(', ', $log) ];
    }

    private function advance_source_round_robin( $state, $sources, $current_idx, $msg ) {
        $next_idx = $current_idx + 1;
        if ( $next_idx >= count($sources) ) $next_idx = 0;

        $state['source_index'] = $next_idx;
        $state['log'] = $msg . " -> Next #$next_idx.";
        update_option( 'scp_crawler_state', $state );

        return true; 
    }

    private function post_exists( $oid, $url ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_scp_original_id' AND meta_value = %s LIMIT 1", $oid );
        $result = $wpdb->get_var($query);
        if ( $result ) {
            $url_check = get_post_meta($result, '_scp_source_url', true);
            if ( $url_check && strpos($url_check, parse_url($url, PHP_URL_HOST)) !== false ) return true;
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
}