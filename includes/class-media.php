<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Media {
    private $logger; // Biến chứa Logger

    // Nhận Logger từ Constructor
    public function __construct( $logger_instance = null ) {
        if ( $logger_instance ) {
            $this->logger = $logger_instance;
        } else {
            // Fallback nếu không truyền vào (để tránh lỗi code cũ)
            $this->logger = new SCP_Logger();
        }
    }

    public function custom_http_request_args( $args, $url ) {
        $args['sslverify'] = false; 
        $args['timeout']   = 300; 
        $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $args['headers']['Referer'] = 'https://google.com';
        return $args;
    }

    public function sideload_with_retry( $url, $post_id, $retry_times = 3 ) {
        // Deduplication Check
        $existing_id = $this->get_existing_media_id( $url );
        if ( $existing_id ) return [ 'id' => $existing_id, 'url' => wp_get_attachment_url( $existing_id ) ];

        $attempt = 0;
        $success = false;
        $result = false;
        $last_error = '';

        while ( $attempt < $retry_times && ! $success ) {
            $result = $this->sideload( $url, $post_id );
            
            if ( $result && ! empty( $result['url'] ) ) {
                $success = true;
            } else {
                $attempt++;
                // Lưu lại lỗi của lần thử cuối cùng
                if ( is_wp_error( $result ) ) {
                    $last_error = $result->get_error_message();
                } else {
                    $last_error = "Unknown error / Download failed";
                }
                sleep(1);
            }
        }

        // --- GHI LOG NẾU THẤT BẠI HOÀN TOÀN ---
        if ( ! $success ) {
            $this->logger->log( 
                $post_id, 
                $url, 
                "Thất bại sau $retry_times lần thử. Lỗi cuối: $last_error", 
                'download_fail' 
            );
        }

        return $success ? $result : false;
    }

    // ... (Giữ nguyên các hàm process_content, get_existing_media_id, add_center_style) ...
    // COPY LẠI CÁC HÀM NÀY TỪ BẢN V17
    private function get_existing_media_id( $url ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_scp_media_source_url' AND meta_value = %s LIMIT 1", $url );
        $id = $wpdb->get_var( $query );
        if ( $id && wp_get_attachment_url( $id ) ) return $id;
        return false;
    }

    public function process_content( $post_id, $content, $source_url = '', $remove_phrases = [] ) {
        // ... (Logic giữ nguyên như V17) ...
        // Để tiết kiệm không gian câu trả lời, tôi không paste lại hàm này vì nó không thay đổi logic,
        // chỉ cần đảm bảo class này có constructor nhận $logger và gọi sideload_with_retry
        
        // (Copy nội dung hàm process_content từ bản V17 vào đây)
        if ( empty( $content ) ) return [ 'content' => '', 'first_image_id' => 0 ];
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $content);
        $dom = new DOMDocument();
        @$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        $xpath = new DOMXPath($dom);
        if ( ! empty( $remove_phrases ) ) {
            foreach ( $xpath->query('//text()') as $textNode ) {
                foreach ( $remove_phrases as $phrase ) {
                    if ( stripos( $textNode->nodeValue, $phrase ) !== false ) {
                        $p = $textNode->parentNode;
                        if($p && strlen(trim($p->nodeValue)) < 200) $p->parentNode->removeChild($p);
                        else $textNode->nodeValue = str_ireplace( $phrase, '', $textNode->nodeValue );
                    }
                }
            }
        }
        $allowed = ['src', 'href', 'alt', 'title', 'controls', 'poster', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'loading', 'class', 'id', 'style', 'data-src', 'data-lazy'];
        foreach ($xpath->query('//*') as $node) {
            $attrs = []; if ($node->hasAttributes()) foreach ($node->attributes as $attr) $attrs[] = $attr->name;
            foreach ($attrs as $attr) {
                if ( strpos($attr, 'on') === 0 ) { $node->removeAttribute($attr); continue; }
                if ( !in_array($attr, $allowed) ) $node->removeAttribute($attr);
            }
        }
        $source_domain = $this->get_domain_from_url($source_url);
        $first_image_id = 0;
        
        // Image
        $images = $dom->getElementsByTagName('img');
        $imgs_array = []; foreach ($images as $img) { $imgs_array[] = $img; }
        foreach ( $imgs_array as $img ) {
            $src = $img->getAttribute('src');
            if ( empty($src) ) $src = $img->getAttribute('data-src');
            if ( empty($src) ) continue;
            $abs_src = $this->resolve_url( $src, $source_domain );
            $result = $this->sideload_with_retry( $abs_src, $post_id, 2 );
            if ( $result ) {
                $img->setAttribute('src', $result['url']);
                if ( $first_image_id === 0 ) $first_image_id = $result['id'];
            } else {
                $img->setAttribute('src', $abs_src);
            }
            $this->add_center_style($img);
        }
        
        // Video
        $videos = $dom->getElementsByTagName('video');
        $vids_array = []; foreach ($videos as $v) { $vids_array[] = $v; }
        foreach ( $vids_array as $video ) {
            $src = $video->getAttribute('src');
            if ( empty( $src ) ) {
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $source) { $src = $source->getAttribute('src'); if($src) break; }
            }
            if ( $src ) {
                $abs_src = $this->resolve_url( $src, $source_domain );
                $result = $this->sideload_with_retry( $abs_src, $post_id, 3 );
                $final_url = $result ? $result['url'] : $abs_src;
                $video->setAttribute('src', $final_url);
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $s) $s->setAttribute('src', $final_url);
                $this->add_center_style($video);
                $video->setAttribute('controls', 'controls');
            }
        }
        
        // Iframe
        $iframes = $dom->getElementsByTagName('iframe');
        foreach ($iframes as $iframe) {
            $src = $iframe->getAttribute('src');
            if(empty($src)) continue;
            $abs_src = $this->resolve_url($src, $source_domain);
            $iframe->setAttribute('src', $abs_src);
            if(!$iframe->hasAttribute('width')) $iframe->setAttribute('width', '100%');
            $this->add_center_style($iframe);
        }

        $new_content = $dom->saveHTML();
        $new_content = str_replace(array('<html>', '</html>', '<body>', '</body>'), '', $new_content);
        $new_content = preg_replace('/^<!DOCTYPE.+?>/', '', $new_content);
        $new_content = str_replace('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">', '', $new_content);
        wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
        return [ 'content' => $new_content, 'first_image_id' => $first_image_id ];
    }

    public function sideload( $url, $post_id, $is_featured = false ) {
        // ... (Code sideload của V17) ...
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        if ( empty( $url ) ) return new WP_Error('empty_url', 'URL rỗng');
        
        set_time_limit(0);
        $clean_url = strtok($url, '?');
        $ext = strtolower( pathinfo( $clean_url, PATHINFO_EXTENSION ) );
        $allowed = ['jpg','jpeg','png','gif','webp','svg','bmp','mp4','mov','avi','wmv','mkv','webm'];
        if ( ! in_array( $ext, $allowed ) ) return new WP_Error('invalid_type', 'Đuôi file không hỗ trợ: '.$ext);

        add_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10, 2 );
        $tmp = download_url( $url );
        remove_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10 );

        if ( is_wp_error( $tmp ) ) return $tmp; // Trả về WP_Error để ghi log

        $file_array = array( 'name' => basename( $clean_url ), 'tmp_name' => $tmp );
        if ( empty( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) ) ) $file_array['name'] = md5( $url ) . '.' . ($ext ? $ext : 'jpg');

        $id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $id ) ) { 
            @unlink( $file_array['tmp_name'] ); 
            return $id; // Trả về WP_Error
        }

        update_post_meta( $id, '_scp_media_source_url', $url );
        if ( $is_featured ) set_post_thumbnail( $post_id, $id );

        return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ];
    }
    
    // Helper URL
    private function add_center_style($node) {
        $old_style = $node->getAttribute('style');
        if(strpos($old_style, 'display') === false) {
            $new_style = 'display: block; margin: 20px auto; max-width: 100%;';
            if($node->nodeName == 'img') $new_style .= ' height: auto;';
            $node->setAttribute('style', $new_style . $old_style);
        }
    }
    private function get_domain_from_url($url) {
        if(empty($url)) return '';
        $parsed = parse_url($url);
        return isset($parsed['scheme']) && isset($parsed['host']) ? $parsed['scheme'] . '://' . $parsed['host'] : '';
    }
    private function resolve_url( $url, $domain ) {
        if ( strpos( $url, '//' ) === 0 ) return 'https:' . $url;
        if ( strpos( $url, 'http' ) === 0 ) return $url;
        if ( strpos( $url, '/' ) === 0 ) return $domain . $url;
        return $url;
    }
}