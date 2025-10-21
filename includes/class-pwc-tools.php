<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Tools {
    private $core;
    
    public function __construct() {
        $this->core = PWC_Core::get_instance();
        $this->setup_hooks();
    }
    
    private function setup_hooks() {
        add_action('wp_ajax_pwc_health_check', [$this, 'ajax_health_check']);
        add_action('wp_ajax_pwc_optimize_db', [$this, 'ajax_optimize_db']);
        add_action('wp_ajax_pwc_view_logs', [$this, 'ajax_view_logs']);
        add_action('wp_ajax_pwc_reset_plugin', [$this, 'ajax_reset_plugin']);
        add_action('wp_ajax_pwc_delete_all_data', [$this, 'ajax_delete_all_data']);
        add_action('wp_ajax_pwc_test_image', [$this, 'ajax_test_image']);
        add_action('wp_ajax_pwc_bulk_actions', [$this, 'ajax_bulk_actions']);
    }
    
    public function ajax_health_check() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $health_report = $this->run_health_check();
        wp_send_json_success($health_report);
    }
    
    public function ajax_optimize_db() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $result = $this->optimize_database();
        wp_send_json_success($result);
    }
    
    public function ajax_view_logs() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $logs = $this->get_system_logs();
        wp_send_json_success($logs);
    }
    
    public function ajax_reset_plugin() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $result = $this->reset_plugin();
        wp_send_json_success($result);
    }
    
    public function ajax_delete_all_data() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $result = $this->delete_all_data();
        wp_send_json_success($result);
    }
    
    public function ajax_test_image() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $result = $this->test_image_conversion();
        wp_send_json_success($result);
    }
    
    private function run_health_check() {
        $report = [
            'status' => 'healthy',
            'checks' => [],
            'recommendations' => []
        ];
        
        // بررسی PHP
        $report['checks']['php_version'] = $this->check_php_version();
        $report['checks']['php_extensions'] = $this->check_php_extensions();
        $report['checks']['php_memory'] = $this->check_php_memory();
        
        // بررسی وردپرس
        $report['checks']['wordpress'] = $this->check_wordpress();
        $report['checks']['uploads'] = $this->check_uploads_directory();
        
        // بررسی پلاگین
        $report['checks']['plugin_settings'] = $this->check_plugin_settings();
        $report['checks']['conversion_method'] = $this->check_conversion_method();
        
        // بررسی سیستم فایل
        $report['checks']['file_permissions'] = $this->check_file_permissions();
        $report['checks']['cache_directory'] = $this->check_cache_directory();
        
        // تعیین وضعیت کلی
        $failed_checks = array_filter($report['checks'], function($check) {
            return $check['status'] === 'error';
        });
        
        if (count($failed_checks) > 3) {
            $report['status'] = 'critical';
        } elseif (count($failed_checks) > 0) {
            $report['status'] = 'warning';
        }
        
        // تولید توصیه‌ها
        $report['recommendations'] = $this->generate_recommendations($report['checks']);
        
        return $report;
    }
    
    private function check_php_version() {
        $current = PHP_VERSION;
        $required = '7.4';
        
        return [
            'name' => 'نسخه PHP',
            'current' => $current,
            'required' => $required,
            'status' => version_compare($current, $required, '>=') ? 'success' : 'error',
            'message' => version_compare($current, $required, '>=') 
                ? 'نسخه PHP مناسب است' 
                : 'نیاز به ارتقاء PHP دارید'
        ];
    }
    
    private function check_php_extensions() {
        $required = ['gd', 'mbstring'];
        $recommended = ['imagick', 'curl'];
        
        $missing_required = [];
        $missing_recommended = [];
        
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing_required[] = $ext;
            }
        }
        
        foreach ($recommended as $ext) {
            if (!extension_loaded($ext)) {
                $missing_recommended[] = $ext;
            }
        }
        
        $status = empty($missing_required) ? 'success' : 'error';
        $message = '';
        
        if (!empty($missing_required)) {
            $message = 'افزونه‌های ضروری missing: ' . implode(', ', $missing_required);
        } elseif (!empty($missing_recommended)) {
            $message = 'افزونه‌های توصیه شده missing: ' . implode(', ', $missing_recommended);
            $status = 'warning';
        } else {
            $message = 'تمام افزونه‌ها نصب هستند';
        }
        
        return [
            'name' => 'افزونه‌های PHP',
            'current' => implode(', ', get_loaded_extensions()),
            'required' => implode(', ', array_merge($required, $recommended)),
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function check_php_memory() {
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $limit_bytes = wp_convert_hr_to_bytes($memory_limit);
        
        $usage_percent = ($memory_usage / $limit_bytes) * 100;
        
        if ($usage_percent > 90) {
            $status = 'error';
            $message = 'مصرف حافظه بسیار بالا است';
        } elseif ($usage_percent > 70) {
            $status = 'warning';
            $message = 'مصرف حافظه نسبتاً بالا است';
        } else {
            $status = 'success';
            $message = 'مصرف حافظه مناسب است';
        }
        
        return [
            'name' => 'مصرف حافظه',
            'current' => size_format($memory_usage),
            'limit' => $memory_limit,
            'usage_percent' => round($usage_percent, 1),
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function check_wordpress() {
        $upload_dir = wp_upload_dir();
        $is_writable = wp_is_writable($upload_dir['basedir']);
        
        return [
            'name' => 'پیکربندی وردپرس',
            'current' => 'نسخه ' . get_bloginfo('version'),
            'required' => '5.8+',
            'status' => $is_writable ? 'success' : 'error',
            'message' => $is_writable 
                ? 'پوشه آپلود قابل نوشتن است' 
                : 'پوشه آپلود قابل نوشتن نیست'
        ];
    }
    
    private function check_uploads_directory() {
        $upload_dir = wp_upload_dir();
        $free_space = disk_free_space($upload_dir['basedir']);
        $total_space = disk_total_space($upload_dir['basedir']);
        $free_percent = ($free_space / $total_space) * 100;
        
        if ($free_percent < 10) {
            $status = 'error';
            $message = 'فضای دیسک بسیار کم است';
        } elseif ($free_percent < 20) {
            $status = 'warning';
            $message = 'فضای دیسک کم است';
        } else {
            $status = 'success';
            $message = 'فضای دیسک مناسب است';
        }
        
        return [
            'name' => 'فضای ذخیره‌سازی',
            'current' => size_format($free_space) . ' آزاد',
            'total' => size_format($total_space),
            'free_percent' => round($free_percent, 1),
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function check_plugin_settings() {
        $settings = get_option('pwc_settings', []);
        $issues = [];
        
        if (empty($settings['enable_conversion'])) {
            $issues[] = 'تبدیل خودکار غیرفعال است';
        }
        
        if (empty($settings['quality']) || $settings['quality'] < 50) {
            $issues[] = 'کیفیت تبدیل بسیار پایین است';
        }
        
        $status = empty($issues) ? 'success' : 'warning';
        
        return [
            'name' => 'تنظیمات پلاگین',
            'current' => empty($issues) ? 'تنظیمات مناسب' : 'نیاز به بررسی',
            'issues' => $issues,
            'status' => $status,
            'message' => empty($issues) 
                ? 'تنظیمات بهینه هستند' 
                : 'مواردی نیاز به توجه دارند: ' . implode(', ', $issues)
        ];
    }
    
    private function check_conversion_method() {
        if (class_exists('Imagick')) {
            $method = 'Imagick';
            $status = 'success';
            $message = 'Imagick در دسترس است';
        } elseif (extension_loaded('gd')) {
            $method = 'GD';
            $status = 'warning';
            $message = 'فقط GD در دسترس است (Imagick توصیه می‌شود)';
        } else {
            $method = 'هیچکدام';
            $status = 'error';
            $message = 'هیچ کتابخانه تصویری در دسترس نیست';
        }
        
        return [
            'name' => 'روش تبدیل',
            'current' => $method,
            'recommended' => 'Imagick',
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function check_file_permissions() {
        $upload_dir = wp_upload_dir();
        $test_file = $upload_dir['basedir'] . '/pwc_test.txt';
        
        // تست نوشتن
        $write_test = file_put_contents($test_file, 'test');
        $read_test = $write_test ? file_get_contents($test_file) : false;
        
        if ($write_test && $read_test) {
            unlink($test_file);
            $status = 'success';
            $message = 'دسترسی فایل مناسب است';
        } else {
            $status = 'error';
            $message = 'مشکل در دسترسی فایل';
        }
        
        return [
            'name' => 'دسترسی فایل',
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function check_cache_directory() {
        $cache_dir = PWC_CACHE_DIR;
        
        if (!file_exists($cache_dir)) {
            $status = 'warning';
            $message = 'پوشه کش وجود ندارد';
        } elseif (!is_writable($cache_dir)) {
            $status = 'error';
            $message = 'پوشه کش قابل نوشتن نیست';
        } else {
            $status = 'success';
            $message = 'پوشه کش قابل دسترس است';
        }
        
        return [
            'name' => 'پوشه کش',
            'status' => $status,
            'message' => $message
        ];
    }
    
    private function generate_recommendations($checks) {
        $recommendations = [];
        
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                switch ($check['name']) {
                    case 'نسخه PHP':
                        $recommendations[] = 'ارتقاء PHP به نسخه 7.4 یا بالاتر';
                        break;
                    case 'افزونه‌های PHP':
                        $recommendations[] = 'نصب افزونه‌های GD و Imagick';
                        break;
                    case 'مصرف حافظه':
                        $recommendations[] = 'افزایش memory_limit در php.ini';
                        break;
                    case 'پیکربندی وردپرس':
                        $recommendations[] = 'تنظیم مجوزهای پوشه آپلود';
                        break;
                }
            } elseif ($check['status'] === 'warning') {
                switch ($check['name']) {
                    case 'روش تبدیل':
                        $recommendations[] = 'نصب Imagick برای کیفیت تبدیل بهتر';
                        break;
                    case 'فضای ذخیره‌سازی':
                        $recommendations[] = 'پاکسازی فضای دیسک';
                        break;
                    case 'تنظیمات پلاگین':
                        $recommendations[] = 'بررسی تنظیمات پلاگین';
                        break;
                }
            }
        }
        
        return array_unique($recommendations);
    }
    
    private function optimize_database() {
        global $wpdb;
        
        $tables = ['posts', 'postmeta', 'options'];
        $optimized = [];
        
        foreach ($tables as $table) {
            $result = $wpdb->query("OPTIMIZE TABLE {$wpdb->$table}");
            $optimized[$table] = $result !== false;
        }
        
        // پاکسازی transientها
        $transients = $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '%transient%' 
            AND option_name NOT LIKE '%_pwc_%'
        ");
        
        return [
            'success' => true,
            'message' => 'دیتابیس با موفقیت بهینه‌سازی شد',
            'optimized_tables' => $optimized,
            'cleaned_transients' => $transients
        ];
    }
    
    private function get_system_logs() {
        $logs = get_option('pwc_conversion_logs', []);
        $error_logs = get_option('pwc_error_logs', []);
        
        return [
            'conversion_logs' => array_slice(array_reverse($logs), 0, 50),
            'error_logs' => array_slice(array_reverse($error_logs), 0, 50),
            'system_logs' => $this->get_recent_system_logs()
        ];
    }
    
    private function get_recent_system_logs() {
        // خواندن لاگ فایل سرور (در صورت وجود)
        $log_file = WP_CONTENT_DIR . '/debug.log';
        $logs = [];
        
        if (file_exists($log_file) && is_readable($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $pwc_logs = array_filter($lines, function($line) {
                return strpos($line, 'PWC') !== false || strpos($line, 'pwc') !== false;
            });
            $logs = array_slice(array_reverse($pwc_logs), 0, 20);
        }
        
        return $logs;
    }
    
    private function reset_plugin() {
        // حذف تنظیمات
        delete_option('pwc_settings');
        delete_option('pwc_conversion_logs');
        delete_option('pwc_error_logs');
        delete_option('pwc_batch_progress');
        delete_option('pwc_current_batch');
        
        // حذف metadataهای پست‌ها
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_pwc_%'");
        
        // پاکسازی کش
        $this->core->cache->clear_all();
        
        // بازگردانی تنظیمات پیش‌فرض
        $this->set_default_settings();
        
        return [
            'success' => true,
            'message' => 'پلاگین با موفقیت بازنشانی شد'
        ];
    }
    
    private function delete_all_data() {
        // حذف تمام داده‌ها (شامل تصاویر تبدیل شده)
        global $wpdb;
        
        // پیدا کردن تمام تصاویر تبدیل شده
        $converted_images = $wpdb->get_col("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_pwc_converted'
        ");
        
        // بازگرداندن تصاویر به حالت اصلی
        foreach ($converted_images as $image_id) {
            $this->revert_single_image($image_id);
        }
        
        // حذف تمام metadataها
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_pwc_%'");
        
        // حذف تنظیمات و لاگ‌ها
        delete_option('pwc_settings');
        delete_option('pwc_conversion_logs');
        delete_option('pwc_error_logs');
        delete_option('pwc_batch_progress');
        delete_option('pwc_current_batch');
        
        // پاکسازی کامل کش
        $this->core->cache->clear_all();
        
        return [
            'success' => true,
            'message' => 'تمامی داده‌ها با موفقیت حذف شدند',
            'reverted_images' => count($converted_images)
        ];
    }
    
    private function revert_single_image($attachment_id) {
        $original_path = get_post_meta($attachment_id, '_pwc_original', true);
        $current_file = get_attached_file($attachment_id);
        
        if ($original_path && file_exists($original_path) && $current_file !== $original_path) {
            // حذف فایل تبدیل شده
            if (file_exists($current_file)) {
                unlink($current_file);
            }
            
            // بازگردانی فایل اصلی
            copy($original_path, $current_file);
            
            // بازسازی metadata
            $metadata = wp_generate_attachment_metadata($attachment_id, $current_file);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }
        
        // حذف metadataهای تبدیل
        delete_post_meta($attachment_id, '_pwc_original');
        delete_post_meta($attachment_id, '_pwc_converted');
        delete_post_meta($attachment_id, '_pwc_conversion_data');
    }
    
    private function test_image_conversion() {
        // ایجاد یک تصویر تست
        $test_image = $this->create_test_image();
        
        if (!$test_image) {
            return [
                'success' => false,
                'message' => 'خطا در ایجاد تصویر تست'
            ];
        }
        
        // تست تبدیل
        $result = $this->core->converter->convert_image($test_image, 'webp');
        
        // پاکسازی فایل‌های تست
        unlink($test_image);
        if (isset($result['converted_path']) && file_exists($result['converted_path'])) {
            unlink($result['converted_path']);
        }
        
        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'تست تبدیل موفقیت‌آمیز بود' : 'تست تبدیل ناموفق بود',
            'result' => $result
        ];
    }
    
    private function create_test_image() {
        $upload_dir = wp_upload_dir();
        $test_path = $upload_dir['path'] . '/pwc_test_image.jpg';
        
        // ایجاد یک تصویر ساده با GD
        $image = imagecreate(200, 200);
        $background = imagecolorallocate($image, 255, 255, 255);
        $text_color = imagecolorallocate($image, 0, 0, 0);
        
        imagestring($image, 5, 50, 90, 'PWC Test Image', $text_color);
        imagejpeg($image, $test_path, 90);
        imagedestroy($image);
        
        return file_exists($test_path) ? $test_path : false;
    }
    
    private function set_default_settings() {
        $default_settings = [
            'enable_conversion' => 1,
            'enable_replacement' => 1,
            'output_format' => 'webp',
            'quality' => 80,
            'compression_level' => 'medium',
            'strip_metadata' => 1,
            'enable_lazyload' => 1,
            'enable_caching' => 1,
            'cache_max_size' => 500,
            'enable_cdn' => 0,
            'cdn_url' => ''
        ];
        
        update_option('pwc_settings', $default_settings);
    }
    
    public function ajax_bulk_actions() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $action = $_POST['action_type'] ?? '';
        $items = $_POST['items'] ?? [];
        
        switch ($action) {
            case 'convert_selected':
                $result = $this->bulk_convert($items);
                break;
            case 'revert_selected':
                $result = $this->bulk_revert($items);
                break;
            case 'delete_selected':
                $result = $this->bulk_delete_metadata($items);
                break;
            default:
                $result = ['success' => false, 'message' => 'عملیات نامعتبر'];
        }
        
        wp_send_json_success($result);
    }
    
    private function bulk_convert($image_ids) {
        $batch = new PWC_Batch();
        return $batch->start_batch_convert($image_ids);
    }
    
    private function bulk_revert($image_ids) {
        $batch = new PWC_Batch();
        return $batch->start_batch_revert($image_ids);
    }
    
    private function bulk_delete_metadata($image_ids) {
        global $wpdb;
        
        $placeholders = implode(',', array_fill(0, count($image_ids), '%d'));
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->postmeta} 
            WHERE post_id IN ($placeholders) 
            AND meta_key LIKE '_pwc_%'
        ", $image_ids));
        
        return [
            'success' => true,
            'message' => "متادیتاهای $deleted تصویر حذف شد",
            'deleted_count' => $deleted
        ];
    }
}