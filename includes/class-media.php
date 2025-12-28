<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Media {

    // Cấu hình Request
    public function custom_http_request_args( $args, $url ) {
        $args['sslverify'] = false; 
        $args['timeout']   = 300; // 5 phút cho mỗi file
        $args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $args['headers']['Referer'] = 'https://google.com';
        return $args;
    }

    /**
     * HÀM WRAPPER: Thử tải X lần, nếu thất bại thì trả về false
     */
    public function sideload_with_retry( $url, $post_id, $retry_times = 3 ) {
        $attempt = 0;
        $success = false;
        $result = false;

        while ( $attempt < $retry_times && ! $success ) {
            $result = $this->sideload( $url, $post_id );
            
            if ( $result && ! empty( $result['url'] ) ) {
                $success = true;
            } else {
                $attempt++;
                // Nghỉ 1 giây trước khi thử lại để tránh bị chặn IP
                sleep(1); 
                // Ghi log nhẹ nếu cần debug
                // error_log("SCP: Retry {$attempt}/{$retry_times} for URL: " . $url);
            }
        }

        return $success ? $result : false;
    }

    public function process_content( $post_id, $content, $source_url = '', $remove_phrases = [] ) {
        if ( empty( $content ) ) return [ 'content' => '', 'first_image_id' => 0 ];

        // 1. Clean Junk
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $content);
        
        $dom = new DOMDocument();
        @$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        $xpath = new DOMXPath($dom);

        // 2. Remove Phrases
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

        // 3. Clean Attributes
        $allowed = ['src', 'href', 'alt', 'title', 'controls', 'poster', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'loading', 'class', 'id', 'style'];
        foreach ($xpath->query('//*') as $node) {
            $attrs = []; if ($node->hasAttributes()) foreach ($node->attributes as $attr) $attrs[] = $attr->name;
            foreach ($attrs as $attr) {
                if ( strpos($attr, 'on') === 0 ) { $node->removeAttribute($attr); continue; }
                if ( !in_array($attr, $allowed) ) $node->removeAttribute($attr);
            }
        }

        $source_domain = $this->get_domain_from_url($source_url);
        $first_image_id = 0;

        // --- 4. XỬ LÝ ẢNH (Tải trực tiếp) ---
        $images = $dom->getElementsByTagName('img');
        $imgs_array = []; foreach ($images as $img) { $imgs_array[] = $img; }
        
        foreach ( $imgs_array as $img ) {
            $src = $img->getAttribute('src');
            if ( empty($src) ) $src = $img->getAttribute('data-src');
            if ( empty($src) ) continue;

            $abs_src = $this->resolve_url( $src, $source_domain );
            
            // Gọi hàm Retry (thử 2 lần với ảnh)
            $result = $this->sideload_with_retry( $abs_src, $post_id, 2 );
            
            if ( $result ) {
                $img->setAttribute('src', $result['url']);
                if ( $first_image_id === 0 ) $first_image_id = $result['id'];
            } else {
                $img->setAttribute('src', $abs_src); // Fallback link gốc
            }
            $this->add_center_style($img);
        }

        // --- 5. XỬ LÝ VIDEO (Tải trực tiếp có Retry) ---
        $videos = $dom->getElementsByTagName('video');
        $vids_array = []; foreach ($videos as $v) { $vids_array[] = $v; }

        foreach ( $vids_array as $video ) {
            $src = $video->getAttribute('src');
            if ( empty( $src ) ) {
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $source) {
                    $src = $source->getAttribute('src');
                    if($src) break; 
                }
            }

            if ( $src ) {
                $abs_src = $this->resolve_url( $src, $source_domain );
                
                // Gọi hàm Retry (thử 3 lần với Video)
                // Đây là chỗ thay đổi quan trọng: Không dùng Queue nữa mà tải luôn
                $result = $this->sideload_with_retry( $abs_src, $post_id, 3 );
                
                if ( $result ) {
                    $final_url = $result['url'];
                } else {
                    $final_url = $abs_src; // Nếu 3 lần đều lỗi -> Dùng link gốc
                }

                // Gán URL cuối cùng
                $video->setAttribute('src', $final_url);
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $s) $s->setAttribute('src', $final_url);

                $this->add_center_style($video);
                $video->setAttribute('controls', 'controls');
            }
        }

        // --- 6. IFRAMES (Giữ nguyên) ---
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
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        if ( empty( $url ) ) return false;
        
        $clean_url = strtok($url, '?');
        $ext = strtolower( pathinfo( $clean_url, PATHINFO_EXTENSION ) );
        $allowed = ['jpg','jpeg','png','gif','webp','svg','bmp','mp4','mov','avi','wmv','mkv','webm'];
        if ( ! in_array( $ext, $allowed ) ) return false;

        add_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10, 2 );
        $tmp = download_url( $url );
        remove_filter( 'http_request_args', [ $this, 'custom_http_request_args' ], 10 );

        if ( is_wp_error( $tmp ) ) return false;

        $file_array = array( 'name' => basename( $clean_url ), 'tmp_name' => $tmp );
        if ( empty( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) ) ) $file_array['name'] = md5( $url ) . '.' . ($ext ? $ext : 'jpg');

        $id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $id ) ) { @unlink( $file_array['tmp_name'] ); return false; }

        if ( $is_featured ) set_post_thumbnail( $post_id, $id );

        return [ 'id' => $id, 'url' => wp_get_attachment_url( $id ) ];
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