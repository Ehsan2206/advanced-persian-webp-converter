<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// حذف options
$options = [
    'pwc_settings',
    'pwc_version', 
    'pwc_install_date',
    'pwc_conversion_logs',
    'pwc_error_logs',
    'pwc_batch_progress',
    'pwc_current_batch',
    'pwc_cache_hits',
    'pwc_cache_misses',
    'pwc_cloud_settings',
    'pwc_ai_settings',
    'pwc_realtime_settings'
];

foreach ($options as $option) {
    delete_option($option);
}

// حذف metadataهای پست‌ها
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_pwc_%'");

// حذف فایل‌های کش
$cache_dir = WP_CONTENT_DIR . '/pwc-cache/';
if (file_exists($cache_dir)) {
    $files = glob($cache_dir . '*');
    if ($files) {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    if (is_dir($cache_dir)) {
        rmdir($cache_dir);
    }
}

// حذف transientها
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%pwc%'");

// حذف scheduled hooks
wp_clear_scheduled_hook('pwc_daily_maintenance');
wp_clear_scheduled_hook('pwc_weekly_report');

// حذف لاگ فایل
$log_file = WP_CONTENT_DIR . '/pwc-converter.log';
if (file_exists($log_file)) {
    unlink($log_file);
}