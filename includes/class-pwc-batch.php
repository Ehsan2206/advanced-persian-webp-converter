<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once PWC_PLUGIN_DIR . 'includes/wp-background-processing.php';

class PWC_Batch extends WP_Background_Process {
    protected $action = 'pwc_batch_processor';
    private $batch_size = 10;
    
    public function __construct() {
        parent::__construct();
        $this->batch_size = get_option('pwc_batch_size', 5);
    }
    
    protected function task($item) {
        $result = $this->process_item($item);
        
        // به‌روزرسانی پیشرفت
        $this->update_progress();
        
        if ($result['success']) {
            $this->log_success($item, $result);
            return false; // حذف از صف
        } else {
            $this->log_error($item, $result);
            
            // اگر خطا موقتی است، دوباره امتحان کن
            if ($result['retry'] ?? false) {
                return $item;
            }
            
            return false;
        }
    }
    
    protected function complete() {
        parent::complete();
        
        // پاکسازی و بهینه‌سازی
        $this->cleanup();
        
        // ارسال نوتیفیکیشن
        $this->send_completion_notification();
        
        error_log('PWC: Batch processing completed successfully');
    }
    
    public function start_batch_convert($image_ids = []) {
        if (empty($image_ids)) {
            $image_ids = $this->get_all_convertible_images();
        }
        
        if (empty($image_ids)) {
            return [
                'success' => false,
                'message' => 'هیچ تصویری برای تبدیل یافت نشد'
            ];
        }
        
        // بررسی منابع سیستم
        if (!$this->check_system_resources()) {
            return [
                'success' => false,
                'message' => 'منابع سیستم کافی نیست. لطفاً بعداً تلاش کنید.'
            ];
        }
        
        // افزودن به صف
        foreach ($image_ids as $image_id) {
            $this->push_to_queue($image_id);
        }
        
        $this->save()->dispatch();
        
        // ذخیره اطلاعات batch
        $this->save_batch_info($image_ids);
        
        return [
            'success' => true,
            'message' => 'تبدیل دسته‌ای شروع شد',
            'total' => count($image_ids),
            'batch_id' => $this->get_batch_id()
        ];
    }
    
    public function start_batch_revert($image_ids = []) {
        if (empty($image_ids)) {
            $image_ids = $this->get_all_converted_images();
        }
        
        if (empty($image_ids)) {
            return [
                'success' => false,
                'message' => 'هیچ تصویر تبدیل شده‌ای یافت نشد'
            ];
        }
        
        foreach ($image_ids as $image_id) {
            $this->push_to_queue(['action' => 'revert', 'id' => $image_id]);
        }
        
        $this->save()->dispatch();
        
        return [
            'success' => true,
            'message' => 'بازگشت دسته‌ای شروع شد',
            'total' => count($image_ids)
        ];
    }
    
    private function process_item($item) {
        if (is_array($item) && isset($item['action']) && $item['action'] === 'revert') {
            return $this->revert_single_image($item['id']);
        }
        
        return $this->convert_single_image($item);
    }
    
    private function convert_single_image($attachment_id) {
        $core = PWC_Core::get_instance();
        
        try {
            $file_path = get_attached_file($attachment_id);
            
            if (!$file_path || !file_exists($file_path)) {
                return [
                    'success' => false,
                    'error' => 'فایل یافت نشد',
                    'retry' => false
                ];
            }
            
            // تبدیل تصویر
            $result = $core->converter->convert_image($file_path, 
                get_option('pwc_output_format', 'webp'), 
                $attachment_id
            );
            
            if ($result['success']) {
                // به‌روزرسانی metadata
                update_post_meta($attachment_id, '_pwc_converted', time());
                update_post_meta($attachment_id, '_pwc_conversion_data', $result);
                
                // پاکسازی کش
                $core->cache->clear_group('images');
                
                return [
                    'success' => true,
                    'data' => $result
                ];
            }
            
            return [
                'success' => false,
                'error' => 'تبدیل ناموفق بود',
                'retry' => true
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'retry' => false
            ];
        }
    }
    
    private function revert_single_image($attachment_id) {
        try {
            $original_path = get_post_meta($attachment_id, '_pwc_original', true);
            
            if (!$original_path || !file_exists($original_path)) {
                return [
                    'success' => false,
                    'error' => 'فایل اصلی یافت نشد'
                ];
            }
            
            $current_file = get_attached_file($attachment_id);
            
            // بازگرداندن فایل اصلی
            if (copy($original_path, $current_file)) {
                // حذف metadataهای تبدیل
                delete_post_meta($attachment_id, '_pwc_original');
                delete_post_meta($attachment_id, '_pwc_converted');
                delete_post_meta($attachment_id, '_pwc_conversion_data');
                
                // بازسازی metadata
                $metadata = wp_generate_attachment_metadata($attachment_id, $current_file);
                wp_update_attachment_metadata($attachment_id, $metadata);
                
                return ['success' => true];
            }
            
            return [
                'success' => false,
                'error' => 'خطا در بازگرداندن فایل'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function get_all_convertible_images() {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_pwc_converted',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        return $query->posts;
    }
    
    private function get_all_converted_images() {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_pwc_converted',
                    'compare' => 'EXISTS'
                ]
            ]
        ];
        
        $query = new WP_Query($args);
        return $query->posts;
    }
    
    private function check_system_resources() {
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_usage = memory_get_usage(true);
        
        // اگر استفاده از حافظه بیش از 80% باشد
        if ($memory_usage > ($memory_limit * 0.8)) {
            return false;
        }
        
        // بررسی load average
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 0.8) {
                return false;
            }
        }
        
        return true;
    }
    
    private function update_progress() {
        $progress = get_option('pwc_batch_progress', [
            'total' => 0,
            'processed' => 0,
            'percentage' => 0
        ]);
        
        $progress['processed']++;
        $progress['percentage'] = $progress['total'] > 0 ? 
            round(($progress['processed'] / $progress['total']) * 100) : 0;
        
        update_option('pwc_batch_progress', $progress);
    }
    
    private function save_batch_info($image_ids) {
        $batch_info = [
            'started' => current_time('mysql'),
            'total_items' => count($image_ids),
            'processed_items' => 0,
            'status' => 'processing'
        ];
        
        update_option('pwc_current_batch', $batch_info);
        
        // ذخیره پیشرفت
        update_option('pwc_batch_progress', [
            'total' => count($image_ids),
            'processed' => 0,
            'percentage' => 0
        ]);
    }
    
    private function cleanup() {
        delete_option('pwc_current_batch');
        delete_option('pwc_batch_progress');
        
        // بهینه‌سازی دیتابیس
        $this->optimize_database();
    }
    
    private function optimize_database() {
        global $wpdb;
        
        $tables = ['posts', 'postmeta', 'options'];
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$wpdb->$table}");
        }
    }
    
    private function send_completion_notification() {
        $admin_email = get_option('admin_email');
        $subject = '✅ تبدیل دسته‌ای تصاویر کامل شد';
        $message = 'فرآیند تبدیل دسته‌ای تصاویر با موفقیت به پایان رسید.';
        
        wp_mail($admin_email, $subject, $message);
    }
    
    private function log_success($item, $result) {
        error_log("PWC: Successfully processed image {$item}");
    }
    
    private function log_error($item, $result) {
        error_log("PWC: Failed to process image {$item} - " . ($result['error'] ?? 'Unknown error'));
    }
    
    private function get_batch_id() {
        return md5(uniqid('pwc_batch_', true));
    }
    
    public function get_progress() {
        return get_option('pwc_batch_progress', [
            'total' => 0,
            'processed' => 0,
            'percentage' => 0
        ]);
    }
    
    public function cancel_batch() {
        $this->cancel_process();
        $this->cleanup();
        
        return ['success' => true, 'message' => 'فرآیند متوقف شد'];
    }
}