<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Adapter_WP extends SCP_Adapter_Base {

    public function is_platform( $url ) {
        // WordPress thường không có đặc điểm nhận dạng qua URL ngay, 
        // nên logic sẽ là: Nếu Blogspot từ chối thì mặc định thử cái này cuối cùng.
        // Hoặc check /wp-json/
        return true; // Mặc định coi là WP nếu các adapter khác fail
    }

    public function fetch_posts( $url, $page, $per_page ) {
        $base_api = $this->detect_wp_api_base( $url );
        if ( ! $base_api ) return new WP_Error( 'no_api', 'Không tìm thấy WP API' );

        $api_url = $base_api . '&per_page=' . $per_page . '&page=' . $page;
        $response = $this->request( $api_url );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code($response) >= 400 ) {
            return new WP_Error( 'api_error', 'Lỗi kết nối WP API' );
        }

        $raw_data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $raw_data ) || ! is_array( $raw_data ) ) return [];

        return $this->normalize( $raw_data );
    }

    // Chuẩn hóa dữ liệu về định dạng chung của Plugin
    private function normalize( $posts ) {
        $result = [];
        foreach ( $posts as $p ) {
            $obj = new stdClass();
            $obj->id            = $p->id;
            $obj->title         = $p->title->rendered;
            $obj->content       = $p->content->rendered;
            $obj->date          = $p->date;
            $obj->original_link = $p->link;
            
            // Xử lý Tags
            $obj->tags = [];
            if ( isset( $p->_embedded->{'wp:term'} ) ) {
                foreach ( $p->_embedded->{'wp:term'} as $taxonomy ) {
                    foreach ( $taxonomy as $term ) {
                        if ( $term->taxonomy == 'post_tag' ) $obj->tags[] = $term->name;
                    }
                }
            }

            // Xử lý Ảnh Featured
            $obj->featured_img = '';
            if ( isset( $p->_embedded->{'wp:featuredmedia'}[0]->source_url ) ) {
                $obj->featured_img = $p->_embedded->{'wp:featuredmedia'}[0]->source_url;
            } elseif ( isset($p->_links->{'wp:featuredmedia'}[0]->href) ) {
                // Fetch lazy image url
                $media_res = $this->request( $p->_links->{'wp:featuredmedia'}[0]->href );
                if ( ! is_wp_error($media_res) ) {
                    $m = json_decode( wp_remote_retrieve_body($media_res) );
                    if(isset($m->source_url)) $obj->featured_img = $m->source_url;
                }
            }

            $result[] = $obj;
        }
        return $result;
    }

    private function detect_wp_api_base( $url ) {
        $url = untrailingslashit( $url );
        $parsed = parse_url( $url );
        $domain = $parsed['scheme'] . '://' . $parsed['host'];
        $base = $domain . '/wp-json/wp/v2/posts?_embed';
        if ( $url == $domain ) return $base;

        // Check category
        $slug = end( explode( '/', $url ) );
        $transient_key = 'scp_cat_id_' . md5($slug);
        $cat_id = get_transient($transient_key);

        if ( false === $cat_id ) {
            $cat_api = $domain . '/wp-json/wp/v2/categories?slug=' . $slug;
            $cat_res = $this->request( $cat_api );
            if ( ! is_wp_error( $cat_res ) ) {
                $cats = json_decode( wp_remote_retrieve_body( $cat_res ) );
                if ( ! empty( $cats ) && isset( $cats[0]->id ) ) {
                    $cat_id = $cats[0]->id;
                    set_transient($transient_key, $cat_id, 86400);
                }
            }
        }
        if ( $cat_id ) return $base . '&categories=' . $cat_id;
        return $base;
    }
}