<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Adapter_Blogspot extends SCP_Adapter_Base {

    public function is_platform( $url ) {
        // 1. Check nhanh tên miền
        if ( strpos( $url, 'blogspot.com' ) !== false ) return true;

        // 2. Check sâu (Probe) cho custom domain
        $probe_url = trailingslashit( strtok($url, '?') ) . 'feeds/posts/default?alt=json&max-results=1';
        $cache_key = 'scp_is_blogspot_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) return ($cached === 'yes');

        $response = $this->request( $probe_url );
        $is_blog = false;

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code($response) == 200 ) {
            $json = json_decode( wp_remote_retrieve_body( $response ) );
            if ( (isset( $json->feed->entry )) || (isset( $json->feed ) && isset( $json->generator ) && strpos($json->generator->{'$t'}, 'Blogger') !== false) ) {
                $is_blog = true;
            }
        }
        set_transient( $cache_key, ($is_blog ? 'yes' : 'no'), 86400 );
        return $is_blog;
    }

    public function fetch_posts( $url, $page, $per_page ) {
        $start_index = ($page - 1) * $per_page + 1;
        $clean_url = strtok($url, '?');
        $api_url = trailingslashit( $clean_url ) . 'feeds/posts/default?alt=json&max-results=' . $per_page . '&start-index=' . $start_index;

        $response = $this->request( $api_url );
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code($response) >= 400 ) {
            return new WP_Error( 'api_error', 'Lỗi kết nối Blogspot API' );
        }

        $raw_data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $raw_data->feed->entry ) ) return [];

        return $this->normalize( $raw_data->feed->entry );
    }

    private function normalize( $entries ) {
        $result = [];
        foreach ( $entries as $entry ) {
            $obj = new stdClass();
            $obj->id            = md5( $entry->id->{'$t'} ); // ID blogspot rất dài
            $obj->title         = isset($entry->title->{'$t'}) ? $entry->title->{'$t'} : 'No Title';
            $obj->content       = isset($entry->content->{'$t'}) ? $entry->content->{'$t'} : '';
            $obj->date          = isset($entry->published->{'$t'}) ? $entry->published->{'$t'} : date('Y-m-d H:i:s');
            $obj->original_link = ''; 
            
            // Tìm link gốc
            foreach($entry->link as $l) {
                if($l->rel == 'alternate') { $obj->original_link = $l->href; break; }
            }

            // Xử lý Tags
            $obj->tags = [];
            if ( isset( $entry->category ) ) {
                foreach ( $entry->category as $cat ) {
                    if ( isset( $cat->term ) ) $obj->tags[] = $cat->term;
                }
            }

            // Xử lý Ảnh
            $obj->featured_img = '';
            if ( isset( $entry->{'media$thumbnail'}->url ) ) {
                $obj->featured_img = str_replace( '/s72-c/', '/s1600/', $entry->{'media$thumbnail'}->url );
            }

            $result[] = $obj;
        }
        return $result;
    }
}