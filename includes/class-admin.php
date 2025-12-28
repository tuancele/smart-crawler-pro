<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SCP_Admin {
    private $crawler;
    private $cron; // Thêm biến cron
    private $opt_src = 'scp_sources';
    private $opt_fb  = 'scp_fb_settings';

    // Cần truyền thêm Cron Instance vào Admin
    public function __construct( $crawler_instance ) {
        $this->crawler = $crawler_instance;
        // Chúng ta sẽ khởi tạo Cron ở file chính và có thể gọi qua global hoặc pass vào, 
        // ở đây để đơn giản ta khởi tạo mới hoặc lấy instance nếu singleton. 
        // Nhưng tốt nhất sửa file smart-crawler-pro.php để truyền $cron vào đây.
        // Tạm thời ta new SCP_Cron ở đây để kích hoạt.
        $this->cron = new SCP_Cron($this->crawler); 

        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'actions' ] );
    }

    public function menu() {
        add_management_page( 'Smart Crawler Pro', 'Smart Crawler Pro', 'manage_options', 'smart-crawler-pro', [ $this, 'view' ] );
    }

    public function actions() {
        // ... (Giữ nguyên Save FB, Add Source, Delete Source) ...
        if ( isset( $_POST['scp_save_fb'] ) && check_admin_referer( 'scp_fb', 'nonce' ) ) {
            update_option( $this->opt_fb, [ 'page_id' => sanitize_text_field( $_POST['pid'] ), 'access_token' => sanitize_textarea_field( $_POST['token'] ) ]);
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }
        if ( isset( $_POST['scp_add_src'] ) && check_admin_referer( 'scp_src', 'nonce' ) ) {
            $s = get_option( $this->opt_src, [] );
            $s[] = [ 'url' => sanitize_text_field( $_POST['url'] ), 'cat_id' => intval( $_POST['cat'] ) ];
            update_option( $this->opt_src, $s );
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }
        if ( isset( $_POST['del_idx'] ) ) {
            $s = get_option( $this->opt_src, [] );
            unset( $s[ intval( $_POST['del_idx'] ) ] );
            update_option( $this->opt_src, array_values($s) );
            wp_redirect( admin_url('admin.php?page=smart-crawler-pro') ); exit;
        }

        // --- NÚT CHẠY NGAY ---
        if ( isset( $_POST['scp_run_background'] ) ) {
            // Gọi hàm Start Background trong Class Cron
            $this->cron->start_background_job();
            
            add_action('admin_notices', function(){ 
                echo '<div class="notice notice-info"><p>Đã kích hoạt tiến trình ngầm! Hệ thống sẽ tự động lấy 5 bài, nghỉ 5 phút rồi lấy tiếp. Bạn có thể tắt tab này.</p></div>'; 
            });
        }
        
        // Nút Dừng khẩn cấp
        if ( isset( $_POST['scp_stop_job'] ) ) {
            wp_clear_scheduled_hook('scp_cron_batch_event');
            update_option('scp_crawler_state', ['is_running' => false, 'log' => 'Đã dừng thủ công.']);
             add_action('admin_notices', function(){ 
                echo '<div class="notice notice-warning"><p>Đã dừng tiến trình.</p></div>'; 
            });
        }
    }

    public function view() {
        $srcs = get_option( $this->opt_src, [] );
        $fb   = get_option( $this->opt_fb, [] );
        $state = get_option( 'scp_crawler_state', [] );
        $is_running = isset($state['is_running']) && $state['is_running'];
        ?>
        <div class="wrap">
            <h1>Smart Crawler Pro (Auto Farm Mode)</h1>
            
            <div class="card" style="padding:15px; margin-bottom:20px; background: <?php echo $is_running ? '#e6fffa' : '#fff'; ?>; border-left: 4px solid <?php echo $is_running ? '#00a32a' : '#ddd'; ?>;">
                <h3>Trạng thái hệ thống</h3>
                <p><strong>Status:</strong> <?php echo $is_running ? '<span style="color:green;font-weight:bold">ĐANG CHẠY NGẦM...</span>' : 'Đang nghỉ'; ?></p>
                <p><strong>Log:</strong> <?php echo isset($state['log']) ? $state['log'] : 'Chưa có hoạt động'; ?></p>
                <p><strong>Tiến độ:</strong> Đang ở Nguồn #<?php echo isset($state['source_index'])?$state['source_index']:0; ?>, Trang <?php echo isset($state['page'])?$state['page']:1; ?></p>
            </div>

            <div style="margin-bottom:20px;">
                <form method="post" style="display:inline-block">
                    <button name="scp_run_background" class="button button-primary button-hero">CHẠY AUTO FARM NGAY</button>
                </form>
                
                <?php if($is_running): ?>
                <form method="post" style="display:inline-block; margin-left:10px;">
                    <button name="scp_stop_job" class="button button-secondary">Dừng khẩn cấp</button>
                </form>
                <?php endif; ?>
            </div>

            <div style="display:flex; gap:20px;">
                <div style="flex:1; background:#fff; padding:20px; border:1px solid #ddd;">
                    <h3>Cấu hình Facebook</h3>
                    <form method="post">
                        <?php wp_nonce_field( 'scp_fb', 'nonce' ); ?>
                        <p><label>Page ID:</label><br><input type="text" name="pid" value="<?php echo @$fb['page_id']; ?>" style="width:100%"></p>
                        <p><label>Token:</label><br><textarea name="token" rows="3" style="width:100%"><?php echo @$fb['access_token']; ?></textarea></p>
                        <button type="submit" name="scp_save_fb" class="button button-primary">Lưu FB</button>
                    </form>
                </div>

                <div style="flex:1; background:#fff; padding:20px; border:1px solid #ddd;">
                    <h3>Thêm nguồn Crawl</h3>
                    <form method="post">
                        <?php wp_nonce_field( 'scp_src', 'nonce' ); ?>
                        <p><label>URL:</label><br><input type="url" name="url" placeholder="https://..." style="width:100%" required></p>
                        <p><label>Category:</label><br><?php wp_dropdown_categories(['name'=>'cat', 'hide_empty'=>0]); ?></p>
                        <button type="submit" name="scp_add_src" class="button button-secondary">Thêm nguồn</button>
                    </form>
                </div>
            </div>
            
            <br>
            <table class="widefat fixed striped">
                <thead><tr><th>URL</th><th>Category</th><th>Tổng bài đã lấy</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach($srcs as $k => $v): ?>
                    <tr>
                        <td><?php echo $v['url']; ?></td>
                        <td><?php echo get_cat_name($v['cat_id']); ?></td>
                        <td><?php echo @$v['last_count']; ?></td>
                        <td><form method="post"><input type="hidden" name="del_idx" value="<?php echo $k; ?>"><button class="button-link-delete">Xóa</button></form></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}