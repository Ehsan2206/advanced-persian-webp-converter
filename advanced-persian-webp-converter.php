<?php
/**
 * Plugin Name: مبدل پیشرفته وبپی فارسی
 * Plugin URI: https://github.com/Ehsan2206/advanced-persian-webp-converter
 * Description: تبدیل هوشمند تصاویر به فرمت WebP با پشتیبانی کامل از زبان فارسی و بهینه‌سازی حافظه
 * Version: 2.0.0
 * Author: احسان
 * Text Domain: apwc
 * License: GPL v2 or later
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌ها
define('APWC_VERSION', '2.0.0');
define('APWC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('APWC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('APWC_BATCH_SIZE', 3);
define('APWC_MAX_FILE_SIZE', 5242880);

class AdvancedPersianWebPConverter {
    
    private $allowed_mime_types = array('image/jpeg', 'image/png');
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_apwc_convert_batch', array($this, 'ajax_convert_batch'));
        add_action('wp_ajax_apwc_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_apwc_delete_webp', array($this, 'ajax_delete_webp'));
        add_action('add_attachment', array($this, 'convert_on_upload'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // افزایش حافظه برای صفحات پلاگین
        add_action('admin_init', array($this, 'increase_memory_limit'));
    }
    
    public function init() {
        load_plugin_textdomain('apwc', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function increase_memory_limit() {
        if (isset($_GET['page']) && $_GET['page'] === 'webp-converter') {
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', 300);
        }
    }
    
    public function register_settings() {
        register_setting('apwc_settings', 'apwc_quality');
        register_setting('apwc_settings', 'apwc_auto_convert');
        register_setting('apwc_settings', 'apwc_preserve_original');
        register_setting('apwc_settings', 'apwc_convert_sizes');
    }
    
    public function add_admin_menu() {
        add_media_page(
            __('مبدل WebP', 'apwc'),
            __('مبدل WebP', 'apwc'),
            'manage_options',
            'webp-converter',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'media_page_webp-converter') {
            return;
        }
        
        wp_enqueue_script('apwc-admin', APWC_PLUGIN_URL . 'assets/admin.js', array('jquery'), APWC_VERSION, true);
        wp_enqueue_style('apwc-admin', APWC_PLUGIN_URL . 'assets/admin.css', array(), APWC_VERSION);
        
        wp_localize_script('apwc-admin', 'apwc_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('apwc_nonce'),
            'i18n' => array(
                'processing' => __('در حال پردازش...', 'apwc'),
                'complete' => __('تکمیل شد!', 'apwc'),
                'error' => __('خطا', 'apwc'),
                'converted' => __('تبدیل شده', 'apwc'),
                'remaining' => __('باقی‌مانده', 'apwc')
            )
        ));
    }
    
    public function admin_page() {
        $stats = $this->get_conversion_stats();
        ?>
        <div class="wrap apwc-wrap">
            <h1 class="apwc-title">🎨 مبدل پیشرفته WebP فارسی</h1>
            
            <!-- کارت آمار -->
            <div class="apwc-card">
                <h2 class="apwc-card-title">📊 آمار تبدیل</h2>
                <div class="apwc-stats-grid">
                    <div class="apwc-stat-item">
                        <span class="apwc-stat-number"><?php echo $stats['total_images']; ?></span>
                        <span class="apwc-stat-label">کل تصاویر</span>
                    </div>
                    <div class="apwc-stat-item">
                        <span class="apwc-stat-number"><?php echo $stats['converted_images']; ?></span>
                        <span class="apwc-stat-label">تبدیل شده</span>
                    </div>
                    <div class="apwc-stat-item">
                        <span class="apwc-stat-number"><?php echo $stats['remaining_images']; ?></span>
                        <span class="apwc-stat-label">باقی‌مانده</span>
                    </div>
                    <div class="apwc-stat-item">
                        <span class="apwc-stat-number"><?php echo $stats['webp_size']; ?></span>
                        <span class="apwc-stat-label">صرفه‌جویی فضای</span>
                    </div>
                </div>
                <button id="apwc-refresh-stats" class="apwc-btn apwc-btn-secondary">🔄 بروزرسانی آمار</button>
            </div>

            <!-- کارت تبدیل -->
            <div class="apwc-card">
                <h2 class="apwc-card-title">⚡ تبدیل تصاویر</h2>
                <p class="apwc-description">تصاویر موجود را به فرمت WebP تبدیل کنید. این فرآیند به صورت دسته‌ای انجام می‌شود تا از مصرف حافظه جلوگیری شود.</p>
                
                <div id="apwc-progress" class="apwc-progress-container" style="display: none;">
                    <div class="apwc-progress-bar">
                        <div class="apwc-progress-fill" id="apwc-progress-fill"></div>
                    </div>
                    <div class="apwc-progress-info">
                        <span id="apwc-progress-text">در حال پردازش...</span>
                        <span id="apwc-memory-usage" class="apwc-memory-info"></span>
                    </div>
                </div>
                
                <div class="apwc-actions">
                    <button id="apwc-start-conversion" class="apwc-btn apwc-btn-primary">
                        🚀 شروع تبدیل دسته‌ای
                    </button>
                    <button id="apwc-stop-conversion" class="apwc-btn apwc-btn-danger" style="display: none;">
                        ⏹ توقف تبدیل
                    </button>
                    <button id="apwc-delete-webp" class="apwc-btn apwc-btn-warning">
                        🗑 حذف فایل‌های WebP
                    </button>
                </div>
                
                <div id="apwc-results" class="apwc-results"></div>
            </div>

            <!-- کارت تنظیمات -->
            <div class="apwc-card">
                <h2 class="apwc-card-title">⚙️ تنظیمات</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('apwc_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">کیفیت تصویر</th>
                            <td>
                                <select name="apwc_quality" class="apwc-select">
                                    <?php for ($i = 60; $i <= 90; $i += 10): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($i, get_option('apwc_quality', 80)); ?>>
                                            <?php echo $i; ?>%
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">کیفیت تصاویر WebP (توصیه شده: 80%)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">تبدیل خودکار</th>
                            <td>
                                <label class="apwc-switch">
                                    <input type="checkbox" name="apwc_auto_convert" value="1" <?php checked(1, get_option('apwc_auto_convert', 1)); ?> />
                                    <span class="apwc-slider"></span>
                                </label>
                                <span class="apwc-switch-label">تبدیل خودکار تصاویر جدید پس از آپلود</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">حفظ تصاویر اصلی</th>
                            <td>
                                <label class="apwc-switch">
                                    <input type="checkbox" name="apwc_preserve_original" value="1" <?php checked(1, get_option('apwc_preserve_original', 1)); ?> />
                                    <span class="apwc-slider"></span>
                                </label>
                                <span class="apwc-switch-label">حفظ تصاویر اصلی در کنار فایل‌های WebP</span>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('💾 ذخیره تنظیمات', 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- کارت اطلاعات -->
            <div class="apwc-card">
                <h2 class="apwc-card-title">ℹ️ اطلاعات فنی</h2>
                <div class="apwc-info-grid">
                    <div class="apwc-info-item">
                        <strong>مصرف حافظه فعلی:</strong>
                        <span><?php echo $this->format_bytes(memory_get_usage(true)); ?></span>
                    </div>
                    <div class="apwc-info-item">
                        <strong>حداکثر مصرف حافظه:</strong>
                        <span><?php echo $this->format_bytes(memory_get_peak_usage(true)); ?></span>
                    </div>
                    <div class="apwc-info-item">
                        <strong>پشتیبانی از WebP:</strong>
                        <span><?php echo function_exists('imagewebp') ? '✅ فعال' : '❌ غیرفعال'; ?></span>
                    </div>
                    <div class="apwc-info-item">
                        <strong>ورژن پلاگین:</strong>
                        <span><?php echo APWC_VERSION; ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function ajax_get_stats() {
        $this->check_nonce();
        $stats = $this->get_conversion_stats();
        wp_send_json_success($stats);
    }
    
    public function ajax_convert_batch() {
        $this->check_nonce();
        
        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
        $converted_count = isset($_POST['converted_count']) ? intval($_POST['converted_count']) : 0;
        
        // آزادسازی حافظه
        $this->free_memory();
        
        $result = $this->process_batch($batch);
        
        wp_send_json_success(array(
            'batch' => $batch + 1,
            'converted_count' => $converted_count + $result['converted'],
            'total_processed' => ($batch * APWC_BATCH_SIZE) + $result['processed'],
            'has_more' => $result['has_more'],
            'memory_usage' => $this->format_bytes(memory_get_usage(true)),
            'memory_peak' => $this->format_bytes(memory_get_peak_usage(true))
        ));
    }
    
    public function ajax_delete_webp() {
        $this->check_nonce();
        
        $deleted = $this->delete_webp_files();
        
        wp_send_json_success(array(
            'deleted_count' => $deleted,
            'message' => sprintf(__('%d فایل WebP حذف شد.', 'apwc'), $deleted)
        ));
    }
    
    private function process_batch($batch) {
        $offset = $batch * APWC_BATCH_SIZE;
        $images = $this->get_images_batch(APWC_BATCH_SIZE, $offset);
        $converted = 0;
        $processed = 0;
        
        if (empty($images)) {
            return array('converted' => 0, 'processed' => 0, 'has_more' => false);
        }
        
        foreach ($images as $image_id) {
            $processed++;
            
            if (get_post_meta($image_id, '_webp_converted', true)) {
                continue;
            }
            
            if ($this->convert_image($image_id)) {
                $converted++;
                update_post_meta($image_id, '_webp_converted', true);
            }
            
            // آزادسازی حافظه بعد از هر تصویر
            if ($processed % 2 === 0) {
                $this->free_memory();
            }
        }
        
        return array(
            'converted' => $converted,
            'processed' => $processed,
            'has_more' => count($images) === APWC_BATCH_SIZE
        );
    }
    
    private function get_images_batch($limit, $offset) {
        global $wpdb;
        
        $query = $wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type IN ('image/jpeg', 'image/png')
            ORDER BY ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset);
        
        return $wpdb->get_col($query);
    }
    
    public function convert_on_upload($attachment_id) {
        if (!get_option('apwc_auto_convert', 1)) {
            return;
        }
        
        $this->convert_image($attachment_id);
    }
    
    private function convert_image($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $file_size = filesize($file_path);
        if ($file_size > APWC_MAX_FILE_SIZE) {
            error_log('APWC: تصویر بسیار حجیم - ' . $file_path);
            return false;
        }
        
        $mime_type = get_post_mime_type($attachment_id);
        if (!in_array($mime_type, $this->allowed_mime_types)) {
            return false;
        }
        
        $quality = get_option('apwc_quality', 80);
        $webp_path = $file_path . '.webp';
        
        if (file_exists($webp_path)) {
            return true;
        }
        
        try {
            switch ($mime_type) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($file_path);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($file_path);
                    if ($image) {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;
                default:
                    return false;
            }
            
            if (!$image) {
                return false;
            }
            
            $result = imagewebp($image, $webp_path, $quality);
            imagedestroy($image);
            
            return $result && file_exists($webp_path);
            
        } catch (Exception $e) {
            error_log('APWC خطای تبدیل: ' . $e->getMessage());
            return false;
        }
    }
    
    private function delete_webp_files() {
        global $wpdb;
        
        $images = $wpdb->get_col("
            SELECT meta_value FROM {$wpdb->postmeta} 
            WHERE meta_key = '_webp_converted'
        ");
        
        $deleted = 0;
        
        foreach ($images as $image_id) {
            $file_path = get_attached_file($image_id);
            $webp_path = $file_path . '.webp';
            
            if (file_exists($webp_path)) {
                if (unlink($webp_path)) {
                    $deleted++;
                    delete_post_meta($image_id, '_webp_converted');
                }
            }
        }
        
        return $deleted;
    }
    
    private function get_conversion_stats() {
        global $wpdb;
        
        $total_images = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type IN ('image/jpeg', 'image/png')
        ");
        
        $converted_images = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_webp_converted' 
            AND meta_value = '1'
        ");
        
        // محاسبه صرفه‌جویی فضای تخمینی (فرض: 30% صرفه‌جویی)
        $savings = $converted_images * 100000; // فرضی
        
        return array(
            'total_images' => (int)$total_images,
            'converted_images' => (int)$converted_images,
            'remaining_images' => (int)($total_images - $converted_images),
            'webp_size' => $this->format_bytes($savings)
        );
    }
    
    private function check_nonce() {
        if (!wp_verify_nonce($_POST['nonce'], 'apwc_nonce')) {
            wp_send_json_error('Nonce نامعتبر');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
    }
    
    private function free_memory() {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

new AdvancedPersianWebPConverter();
?>