<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Media {

    public function sideload( $url, $post_id, $is_featured = false ) {
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );

        if ( empty( $url ) ) return false;
        // Bỏ qua nếu không phải file media
        if ( ! preg_match( '/\.(jpg|jpeg|png|gif|webp|mp4|mov|avi|wmv)$/i', parse_url( $url, PHP_URL_PATH ) ) ) return false;

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return false;

        $file_array = array(
            'name'     => basename( parse_url( $url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        );
        if ( empty( pathinfo( $file_array['name'], PATHINFO_EXTENSION ) ) ) $file_array['name'] = md5( $url ) . '.jpg';

        $id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $id ) ) { @unlink( $file_array['tmp_name'] ); return false; }

        if ( $is_featured ) set_post_thumbnail( $post_id, $id );

        return [ 'id'  => $id, 'url' => wp_get_attachment_url( $id ) ];
    }

    /**
     * @param array $remove_phrases Mảng các từ khóa cần xóa
     */
    public function process_content( $post_id, $content, $source_url = '', $remove_phrases = [] ) {
        if ( empty( $content ) ) return [ 'content' => '', 'first_image_id' => 0 ];

        // 1. CLEAN JUNK HTML (Làm sạch rác)
        // Xóa các thẻ style, script, class, id để bài viết sạch sẽ
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $content);
        
        // Remove attributes (class, id, style, onclick...) giữ lại href, src
        // Cách dùng regex này hơi mạnh tay, nếu muốn giữ style căn bản thì bỏ dòng này
        // $content = preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i",'<$1$2>', $content); 
        // Nhưng DOMDocument xử lý tốt hơn ở dưới.

        $dom = new DOMDocument();
        @$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        
        $xpath = new DOMXPath($dom);

        // Xóa các node chứa từ khóa cấm (Ví dụ: "Xem thêm", "Nguồn:")
        if ( ! empty( $remove_phrases ) ) {
            foreach ( $xpath->query('//text()') as $textNode ) {
                foreach ( $remove_phrases as $phrase ) {
                    if ( stripos( $textNode->nodeValue, $phrase ) !== false ) {
                        // Xóa cả thẻ cha nếu nó ngắn (vd <p>Xem thêm...</p>)
                        $parentNode = $textNode->parentNode;
                        if ( $parentNode && strlen(trim($parentNode->nodeValue)) < 100 ) {
                            $parentNode->parentNode->removeChild($parentNode);
                        } else {
                            // Nếu đoạn văn dài, chỉ xóa từ khóa
                            $textNode->nodeValue = str_ireplace( $phrase, '', $textNode->nodeValue );
                        }
                    }
                }
            }
        }

        // Clean Attributes (Chỉ giữ lại src, href, alt)
        // Duyệt qua tất cả các phần tử
        foreach ($xpath->query('//*') as $node) {
            $attrs = [];
            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attr) {
                    $attrs[] = $attr->name;
                }
            }
            foreach ($attrs as $attr) {
                // Giữ lại các thuộc tính cần thiết
                if ( !in_array($attr, ['src', 'href', 'alt', 'title', 'controls']) ) {
                    $node->removeAttribute($attr);
                }
            }
        }

        $updated = false;
        $first_image_id = 0;
        $source_domain = $this->get_domain_from_url($source_url);

        // --- XỬ LÝ ẢNH ---
        $images = $dom->getElementsByTagName('img');
        $imgs_array = []; foreach ($images as $img) { $imgs_array[] = $img; }

        foreach ( $imgs_array as $img ) {
            $src = $img->getAttribute('src');
            if ( empty( $src ) ) continue;

            $abs_src = $this->resolve_url( $src, $source_domain );
            $result = $this->sideload( $abs_src, $post_id );
            
            if ( $result ) {
                $img->setAttribute('src', $result['url']);
                // Auto Center Style
                $img->setAttribute('style', 'display: block; margin: 20px auto; max-width: 100%; height: auto;');
                
                if ( $first_image_id === 0 ) $first_image_id = $result['id'];
                $updated = true;
            }
        }

        // --- XỬ LÝ VIDEO ---
        $videos = $dom->getElementsByTagName('video');
        $vids_array = []; foreach ($videos as $v) { $vids_array[] = $v; }

        foreach ( $vids_array as $video ) {
            $src = $video->getAttribute('src');
            $source_tag_found = false;
            $target_source_element = null;

            if ( empty( $src ) ) {
                $sources = $video->getElementsByTagName('source');
                foreach($sources as $source) {
                    $src = $source->getAttribute('src');
                    if($src) { $source_tag_found = true; $target_source_element = $source; break; }
                }
            }

            if ( $src ) {
                $abs_src = $this->resolve_url( $src, $source_domain );
                $result = $this->sideload( $abs_src, $post_id );
                
                if ( $result ) {
                    $new_url = $result['url'];
                    if ( $source_tag_found && $target_source_element ) {
                        $target_source_element->setAttribute('src', $new_url);
                    } else {
                        $video->setAttribute('src', $new_url);
                    }
                    $video->setAttribute('style', 'display: block; margin: 20px auto; max-width: 100%;');
                    $video->setAttribute('controls', 'controls');
                    $updated = true;
                }
            }
        }

        $new_content = $dom->saveHTML();
        
        // Cắt bỏ thẻ html/body do DOMDocument tự thêm vào
        $new_content = preg_replace('/^<!DOCTYPE.+?>/', '', str_replace( array('<html>', '</html>', '<body>', '</body>'), array('', '', '', ''), $new_content ) );

        wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );

        return [ 'content' => $new_content, 'first_image_id' => $first_image_id ];
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