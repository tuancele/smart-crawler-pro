<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Admin {
    private $crawler;
    private $cron;
    private $opt_src = 'scp_sources';
    private $opt_fb  = 'scp_fb_settings';

    public function __construct( $crawler_instance ) {
        $this->crawler = $crawler_instance;
        $this->cron = new SCP_Cron($this->crawler); 
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'actions' ] );
    }

    public function menu() {
        add_management_page( 'Smart Crawler Pro', 'Smart Crawler Pro', 'manage_options', 'smart-crawler-pro', [ $this, 'view' ] );
    }

    public function actions() {
        // ... (Giữ nguyên các hành động Save FB, Add Src, Del Src, Start/Stop Job) ...
        if ( isset( $_POST['scp_save_fb'] ) && check_admin_referer( 'scp_fb', 'nonce' ) ) {
            update_option( $this->opt_fb, [ 'page_id' => sanitize_text_field( $_POST['pid'] ), 'access_token' => sanitize_textarea_field( $_POST['token'] ) ]);
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }
        if ( isset( $_POST['scp_add_src'] ) && check_admin_referer( 'scp_src', 'nonce' ) ) {
            // (Copy lại code bulk add ở bản V15)
            $s = get_option( $this->opt_src, [] );
            $raw_input = $_POST['urls']; $cat_id = intval( $_POST['cat'] );
            $lines = preg_split( '/\r\n|[\r\n]/', $raw_input ); $lines = array_unique( $lines );
            $added_count = 0;
            foreach ( $lines as $line ) {
                $url = trim( $line ); if ( empty( $url ) ) continue;
                if ( filter_var($url, FILTER_VALIDATE_URL) ) {
                    $exists = false;
                    foreach ($s as $existing) { if ( untrailingslashit($existing['url']) === untrailingslashit($url) ) { $exists = true; break; } }
                    if ( ! $exists ) { $s[] = [ 'url' => $url, 'cat_id' => $cat_id, 'current_page' => 1, 'last_count' => 0, 'added_date' => current_time('mysql') ]; $added_count++; }
                }
            }
            if ( $added_count > 0 ) update_option( $this->opt_src, $s );
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro&added='.$added_count) ); exit;
        }
        if ( isset( $_POST['del_idx'] ) ) {
            $s = get_option( $this->opt_src, [] ); unset( $s[ intval( $_POST['del_idx'] ) ] ); update_option( $this->opt_src, array_values($s) );
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }
        if ( isset( $_POST['scp_run_background'] ) ) {
            $this->cron->start_background_job(); wp_redirect( admin_url('admin.php?page=smart-crawler-pro&status=started') ); exit;
        }
        if ( isset( $_POST['scp_stop_job'] ) ) {
            wp_clear_scheduled_hook('scp_cron_batch_event'); update_option('scp_crawler_state', ['is_running' => false, 'log' => 'Đã dừng thủ công.']);
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro&status=stopped') ); exit;
        }

        // --- NEW: CHỨC NĂNG RECHECK ---
        if ( isset( $_POST['scp_recheck_media'] ) ) {
            $result = $this->crawler->fix_broken_media( 10 ); // Quét 10 bài mỗi lần ấn
            $msg = "Đã rà soát 10 bài. Sửa thành công: " . $result['count'] . " bài. " . ($result['log'] ? "Log: " . $result['log'] : "");
            // Lưu log tạm vào transient để hiển thị
            set_transient('scp_recheck_msg', $msg, 60);
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }
    }

    public function view() {
        $srcs  = get_option( $this->opt_src, [] );
        $fb    = get_option( $this->opt_fb, [] );
        $state = get_option( 'scp_crawler_state', [] );
        $is_running = isset($state['is_running']) && $state['is_running'];
        $recheck_msg = get_transient('scp_recheck_msg');

        if ( $recheck_msg ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Kết quả Rà soát:</strong> ' . esc_html($recheck_msg) . '</p></div>';
            delete_transient('scp_recheck_msg');
        }
        if ( isset($_GET['added']) ) echo '<div class="notice notice-success is-dismissible"><p>Đã thêm <strong>'.intval($_GET['added']).'</strong> nguồn.</p></div>';
        if ( isset($_GET['status']) && $_GET['status']=='started' ) echo '<div class="notice notice-info is-dismissible"><p><strong>Hệ thống đang chạy!</strong></p></div>';
        ?>
        <style>
            .scp-card { background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .scp-status-box { border-left: 4px solid #72aee6; background: #f0f6fc; }
            .scp-status-running { border-left-color: #00a32a; background: #edfaef; }
            .scp-table-wrapper { overflow-x: auto; border: 1px solid #e5e5e5; }
            .scp-table { width: 100%; border-collapse: collapse; }
            .scp-table th, .scp-table td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; }
            .col-url { width: 45%; word-break: break-all; } .col-cat { width: 15%; } .col-stat { width: 25%; font-size: 12px; } .col-action { width: 15%; text-align: right; }
            .scp-flex-row { display: flex; gap: 20px; flex-wrap: wrap; } .scp-col { flex: 1; min-width: 300px; }
            h2.scp-title { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; font-size: 1.3em; }
            .scp-log-box { background: #fff; border: 1px solid #ddd; padding: 10px; font-family: monospace; color: #555; margin-top: 5px; max-height: 100px; overflow-y: auto; }
        </style>

        <div class="wrap">
            <h1 class="wp-heading-inline">Smart Crawler Pro (V16 - Direct Retry)</h1>
            <hr class="wp-header-end">
            
            <div class="scp-card <?php echo $is_running ? 'scp-status-running' : 'scp-status-box'; ?>">
                <h2 class="scp-title">Monitor Hệ Thống</h2>
                <div style="display: flex; align-items: start; justify-content: space-between; gap: 20px;">
                    <div style="flex: 1;">
                        <p><strong>Tình trạng:</strong> <?php echo $is_running ? '<span style="color:#00a32a;font-weight:bold">⚡ ĐANG CHẠY</span>' : '💤 Đang nghỉ'; ?></p>
                        <p><strong>Tiến độ:</strong> Nguồn #<?php echo isset($state['source_index'])?$state['source_index'] + 1 : 0; ?></p>
                        <div class="scp-log-box"><?php echo isset($state['log']) ? $state['log'] : '-'; ?></div>
                        
                        <div style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ccc;">
                            <strong>Công cụ sửa lỗi:</strong> Nếu thấy bài viết bị thiếu ảnh/video, hãy bấm nút này.
                            <form method="post" style="display:inline-block; margin-left: 10px;">
                                <button name="scp_recheck_media" class="button button-small">🛠️ Rà soát & Tải lại Media (10 bài gần nhất)</button>
                            </form>
                        </div>
                    </div>
                    <div>
                        <?php if(!$is_running): ?>
                        <form method="post"><button name="scp_run_background" class="button button-primary button-hero">▶ CHẠY</button></form>
                        <?php else: ?>
                        <form method="post"><button name="scp_stop_job" class="button button-secondary">🛑 DỪNG</button></form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="scp-flex-row">
                <div class="scp-col scp-card">
                    <h2 class="scp-title">Cấu hình FB</h2>
                    <form method="post">
                        <?php wp_nonce_field( 'scp_fb', 'nonce' ); ?>
                        <p><label>Page ID:</label><br><input type="text" name="pid" value="<?php echo @$fb['page_id']; ?>" class="large-text"></p>
                        <p><label>Token:</label><br><textarea name="token" rows="3" class="large-text"><?php echo @$fb['access_token']; ?></textarea></p>
                        <button type="submit" name="scp_save_fb" class="button button-primary">Lưu FB</button>
                    </form>
                </div>
                <div class="scp-col scp-card">
                    <h2 class="scp-title">Thêm nguồn (Bulk)</h2>
                    <form method="post">
                        <?php wp_nonce_field( 'scp_src', 'nonce' ); ?>
                        <textarea name="urls" rows="6" class="large-text" placeholder="https://site1.com&#10;https://site2.com" required></textarea>
                        <p><label>Lưu vào:</label> <?php wp_dropdown_categories(['name'=>'cat', 'hide_empty'=>0]); ?></p>
                        <button type="submit" name="scp_add_src" class="button button-secondary">✚ Thêm</button>
                    </form>
                </div>
            </div>
            
            <div class="scp-card">
                <h2 class="scp-title">Danh sách nguồn (<?php echo count($srcs); ?>)</h2>
                <?php if(!empty($srcs)): ?>
                    <div class="scp-table-wrapper">
                        <table class="scp-table widefat striped">
                            <thead><tr><th class="col-url">URL</th><th class="col-cat">Mục tiêu</th><th class="col-stat">Tiến độ</th><th class="col-action"></th></tr></thead>
                            <tbody>
                                <?php foreach($srcs as $k => $v): ?>
                                <tr>
                                    <td class="col-url"><a href="<?php echo esc_url($v['url']); ?>" target="_blank"><?php echo esc_html($v['url']); ?></a></td>
                                    <td class="col-cat"><?php echo get_cat_name($v['cat_id']); ?></td>
                                    <td class="col-stat">Page: <strong><?php echo isset($v['current_page']) ? $v['current_page'] : 1; ?></strong> | Bài: <?php echo isset($v['last_count']) ? $v['last_count'] : 0; ?></td>
                                    <td class="col-action"><form method="post"><input type="hidden" name="del_idx" value="<?php echo $k; ?>"><button class="button-link-delete">Xóa</button></form></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}