<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Facebook {
    private $option_fb = 'scp_fb_settings';

    public function share( $post_id ) {
        $fb_settings = get_option( $this->option_fb, [] );
        $page_id     = isset( $fb_settings['page_id'] ) ? $fb_settings['page_id'] : '';
        $access_token= isset( $fb_settings['access_token'] ) ? $fb_settings['access_token'] : '';

        if ( empty( $page_id ) || empty( $access_token ) ) return;
        if ( get_post_meta( $post_id, '_scp_fb_shared', true ) ) return;

        $post_title = get_the_title( $post_id );
        $post_link  = get_permalink( $post_id );
        $message    = $post_title . "\n\n👉 Xem chi tiết tại: " . $post_link;

        $url = "https://graph.facebook.com/v18.0/{$page_id}/feed";
        $body = [
            'message'      => $message,
            'link'         => $post_link,
            'access_token' => $access_token
        ];

        $response = wp_remote_post( $url, [ 'body' => $body, 'timeout' => 60 ] );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $body['id'] ) ) {
                update_post_meta( $post_id, '_scp_fb_shared', $body['id'] );
            }
        }
    }
}