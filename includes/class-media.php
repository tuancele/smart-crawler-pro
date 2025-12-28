<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Media {
    private $logger;

    public function __construct( $logger_instance = null ) {
        $this->logger = $logger_instance ? $logger_instance : new SCP_Logger();
    }

    public function custom_http_request_args( $args, $url ) {
        $args['sslverify'] = false; 
        $args['timeout']   = 300; 
        $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $args['headers']['Referer'] = 'https://google.com';
        return $args;
    }

    /**
     * TẢI FILE CÓ CHECK TRÙNG LẶP
     */
    public function sideload_with_retry( $url, $post_id, $retry_times = 3 ) {
        // 1. Check tồn tại
        $existing_id = $this->get_existing_media_id( $url );
        if ( $existing_id ) {
            return [ 'id' => $existing_id, 'url' => wp_get_attachment_url( $existing_id ) ];
        }

        // 2. Tải mới
        $attempt = 0;
        $success = false;
        $result = false;
        $last_error = '';

        while ( $attempt < $retry_times && ! $success ) {
            $result = $this->sideload( $url, $post_id );
            
            if ( $result && ! empty( $result['url'] ) && ! is_wp_error($result) ) {
                $success = true;
            } else {
                $attempt++;
                $last_error = is_wp_error($result) ? $result->get_error_message() : 'Unknown error';
                sleep(1); 
            }
        }

        if ( ! $success ) {
            if ( method_exists( $this->logger, 'log' ) ) {
                $this->logger->log( $post_id, $url, "Fail after $retry_times retries: $last_error", 'download_fail' );
            }
            return false;
        }

        return $result;
    }

    /**
     * XỬ LÝ NỘI DUNG (ĐÃ SỬA LỖI MẤT NỘI DUNG/RÁC XML)
     */
    public function process_content( $post_id, $content, $source_url = '', $remove_phrases = [] ) {
        if ( empty( $content ) ) return [ 'content' => '', 'first_image_id' => 0 ];

        // 1. Clean Junk Script/Style
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $content);
        
        // 2. KHỞI TẠO DOM (SỬ DỤNG MB_CONVERT_ENCODING ĐỂ FIX LỖI FONT & XML RÁC)
        $dom = new DOMDocument();
        
        // Chuyển đổi sang HTML-ENTITIES là cách an toàn nhất cho UTF-8 mà không cần hack XML header
        $content_encoded = mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' );
        
        // Load HTML (Tắt báo lỗi cú pháp lặt vặt của HTML5)
        libxml_use_internal_errors(true);
        @$dom->loadHTML( $content_encoded, LIBXML_HTML_NODEFDTD ); // Bỏ LIBXML_HTML_NOIMPLIED để DOM tự tạo wrapper chuẩn
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // 3. Remove Phrases (Xóa từ khóa)
        if ( ! empty( $remove_phrases ) ) {
            foreach ( $xpath->query('//text()') as $textNode ) {
                foreach ( $remove_phrases as $phrase ) {
                    if ( stripos( $textNode->nodeValue, $phrase ) !== false ) {
                        $p = $textNode->parentNode;
                        // Chỉ xóa đoạn văn ngắn
                        if($p && strlen(trim($p->nodeValue)) < 200) {
                            $p->parentNode->removeChild($p);
                        } else {
                            $textNode->nodeValue = str_ireplace( $phrase, '', $textNode->nodeValue );
                        }
                    }
                }
            }
        }

        // 4. Clean Attributes
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
        $content_modified = false;

        // --- XỬ LÝ ẢNH ---
        $images = $dom->getElementsByTagName('img');
        $imgs_array = []; foreach ($images as $img) { $imgs_array[] = $img; }
        
        foreach ( $imgs_array as $img ) {
            $src = $img->getAttribute('src');
            if ( empty($src) ) $src = $img->getAttribute('data-src');
            if ( empty($src) ) continue;

            $abs_src = $this->resolve_url( $src, $source_domain );
            
            // Tải về (Retry 2 lần)
            $result = $this->sideload_with_retry( $abs_src, $post_id, 2 );
            
            if ( $result ) {
                $img->setAttribute('src', $result['url']);
                if ( $first_image_id === 0 ) $first_image_id = $result['id'];
                $content_modified = true;
            } else {
                $img->setAttribute('src', $abs_src); // Fallback
            }
            $this->add_center_style($img);
        }

        // --- XỬ LÝ VIDEO ---
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
                // Tải về (Retry 3 lần)
                $result = $this->sideload_with_retry( $abs_src, $post_id, 3 );
                
                $final_url = $result ? $result['url'] : $abs_src;

                $video->setAttribute('src', $final_url);
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $s) $s->setAttribute('src', $final_url);

                $this->add_center_style($video);
                $video->setAttribute('controls', 'controls');
                $content_modified = true;
            }
        }

        // --- XỬ LÝ IFRAME ---
        $iframes = $dom->getElementsByTagName('iframe');
        foreach ($iframes as $iframe) {
            $src = $iframe->getAttribute('src');
            if(empty($src)) continue;
            $abs_src = $this->resolve_url($src, $source_domain);
            $iframe->setAttribute('src', $abs_src);
            if(!$iframe->hasAttribute('width')) $iframe->setAttribute('width', '100%');
            $this->add_center_style($iframe);
        }

        // --- LƯU HTML CHUẨN (FIX LỖI CŨ) ---
        $new_content = '';
        $body = $dom->getElementsByTagName('body')->item(0);
        
        // Chỉ lấy nội dung bên trong thẻ body để tránh lấy thừa <html><body>
        if ( $body ) {
            foreach ($body->childNodes as $child) {
                $new_content .= $dom->saveHTML($child);
            }
        } else {
            // Fallback nếu không tìm thấy body
            $new_content = $dom->saveHTML();
        }

        // Kiểm tra an toàn: Nếu new_content bị rỗng bất thường (lỗi parse), giữ lại content cũ
        if ( empty( trim($new_content) ) && ! empty( trim($content) ) ) {
            $new_content = $content;
            error_log("SCP Error: Empty content generated for Post ID $post_id. Reverting to original.");
        }

        wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );

        return [ 'content' => $new_content, 'first_image_id' => $first_image_id ];
    }

    // --- CÁC HÀM HỖ TRỢ KHÁC (GIỮ NGUYÊN) ---
    public function sideload( $url, $post_id, $is_featured = false ) {
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        if ( empty( $url ) ) return new WP_Error('empty_url', 'URL rỗng');
        
        set_time_limit(0);
        $clean_url = strtok($url, '?');
        $ext = strtolower( pathinfo( $clean_url, PATHINFO_EXTENSION ) );
        $allowed = ['jpg','jpeg','png','gif','webp','svg','bmp','mp4','mov','avi','wmv','mkv','webm'];
        if ( ! in_array( $ext, $allowed ) ) return new WP_Error('invalid_type', 'Type not allowed: '.$ext);

        add_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10, 2 );
        $tmp = download_url( $url );
        remove_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10 );

        if ( is_wp_error( $tmp ) ) return $tmp;

        $file_array = array( 'name' => basename( $clean_url ), 'tmp_name' => $tmp );
        if ( empty( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) ) ) $file_array['name'] = md5( $url ) . '.' . ($ext ? $ext : 'jpg');

        $id = media_handle_sideload( $file_array, $post_id );

        if ( is_wp_error( $id ) ) { @unlink( $file_array['tmp_name'] ); return $id; }

        update_post_meta( $id, '_scp_media_source_url', $url );
        if ( $is_featured ) set_post_thumbnail( $post_id, $id );

        return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ];
    }

    private function get_existing_media_id( $url ) {
        global $wpdb;
        $query = $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_scp_media_source_url' AND meta_value = %s LIMIT 1", $url );
        $id = $wpdb->get_var( $query );
        if ( $id && wp_get_attachment_url( $id ) ) return $id;
        return false;
    }

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