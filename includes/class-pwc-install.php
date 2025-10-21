<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Install {
    public static function activate() {
        self::create_tables();
        self::set_default_options();
        self::schedule_events();
        self::create_cache_directory();
    }
    
    public static function deactivate() {
        self::clear_scheduled_events();
    }
    
    public static function uninstall() {
        self::delete_tables();
        self::delete_options();
        self::clear_scheduled_events();
        self::remove_cache_directory();
    }
    
    private static function create_tables() {
        // در صورت نیاز به جداول سفارشی
    }
    
    private static function set_default_options() {
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
            'cdn_url' => '',
            'batch_size' => 5,
            'max_memory' => 128
        ];
        
        add_option('pwc_settings', $default_settings);
        add_option('pwc_version', PWC_VERSION);
        add_option('pwc_install_date', current_time('mysql'));
    }
    
    private static function schedule_events() {
        if (!wp_next_scheduled('pwc_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'pwc_daily_maintenance');
        }
        
        if (!wp_next_scheduled('pwc_weekly_report')) {
            wp_schedule_event(time(), 'weekly', 'pwc_weekly_report');
        }
    }
    
    private static function clear_scheduled_events() {
        wp_clear_scheduled_hook('pwc_daily_maintenance');
        wp_clear_scheduled_hook('pwc_weekly_report');
    }
    
    private static function create_cache_directory() {
        $cache_dir = PWC_CACHE_DIR;
        
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
            
            // ایجاد فایل‌های امنیتی
            file_put_contents($cache_dir . '.htaccess', '
Order Deny,Allow
Deny from all
<Files ~ "\.(webp|avif)$">
    Allow from all
</Files>
');
            
            file_put_contents($cache_dir . 'index.html', '');
        }
    }
    
    private static function remove_cache_directory() {
        $cache_dir = PWC_CACHE_DIR;
        
        if (file_exists($cache_dir)) {
            $files = glob($cache_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($cache_dir);
        }
    }
    
    private static function delete_tables() {
        // حذف جداول سفارشی در صورت وجود
    }
    
    private static function delete_options() {
        $options = [
            'pwc_settings',
            'pwc_version',
            'pwc_install_date',
            'pwc_conversion_logs',
            'pwc_error_logs',
            'pwc_batch_progress',
            'pwc_current_batch',
            'pwc_cache_hits',
            'pwc_cache_misses'
        ];
        
        foreach ($options as $option) {
            delete_option($option);
        }
        
        // حذف metadataهای پست‌ها
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_pwc_%'");
    }
}