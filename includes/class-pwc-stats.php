<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Stats {
    private $db;
    
    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }
    
    public function get_overall_stats() {
        return [
            'conversion' => $this->get_conversion_stats(),
            'savings' => $this->get_savings_stats(),
            'performance' => $this->get_performance_stats(),
            'system' => $this->get_system_stats()
        ];
    }
    
    public function get_conversion_stats() {
        $total_images = $this->get_total_images_count();
        $converted_images = $this->get_converted_images_count();
        $failed_conversions = $this->get_failed_conversions_count();
        
        return [
            'total_images' => $total_images,
            'converted_images' => $converted_images,
            'failed_conversions' => $failed_conversions,
            'conversion_rate' => $total_images > 0 ? round(($converted_images / $total_images) * 100, 2) : 0,
            'pending_images' => $total_images - $converted_images
        ];
    }
    
    public function get_savings_stats() {
        $original_size = $this->get_original_total_size();
        $converted_size = $this->get_converted_total_size();
        $savings = $original_size - $converted_size;
        
        return [
            'original_size' => $this->format_bytes($original_size),
            'converted_size' => $this->format_bytes($converted_size),
            'savings_size' => $this->format_bytes($savings),
            'savings_percent' => $original_size > 0 ? round(($savings / $original_size) * 100, 2) : 0,
            'savings_bytes' => $savings
        ];
    }
    
    public function get_performance_stats() {
        $avg_conversion_time = $this->get_avg_conversion_time();
        $success_rate = $this->get_conversion_success_rate();
        $recent_activity = $this->get_recent_activity();
        
        return [
            'avg_conversion_time' => $avg_conversion_time,
            'success_rate' => $success_rate,
            'recent_activity' => $recent_activity,
            'cache_hit_rate' => $this->get_cache_hit_rate()
        ];
    }
    
    public function get_system_stats() {
        return [
            'server' => $this->get_server_info(),
            'php' => $this->get_php_info(),
            'wordpress' => $this->get_wordpress_info(),
            'plugin' => $this->get_plugin_info()
        ];
    }
    
    public function get_daily_stats($days = 30) {
        $results = $this->db->get_results("
            SELECT 
                DATE(meta_value) as date,
                COUNT(*) as conversions,
                SUM(CAST(meta2.meta_value AS UNSIGNED)) as original_size,
                SUM(CAST(meta3.meta_value AS UNSIGNED)) as converted_size
            FROM {$this->db->postmeta} pm
            LEFT JOIN {$this->db->postmeta} meta2 ON pm.post_id = meta2.post_id AND meta2.meta_key = '_pwc_original_size'
            LEFT JOIN {$this->db->postmeta} meta3 ON pm.post_id = meta3.post_id AND meta3.meta_key = '_pwc_converted_size'
            WHERE pm.meta_key = '_pwc_converted'
            AND pm.meta_value >= DATE_SUB(NOW(), INTERVAL $days DAY)
            GROUP BY DATE(pm.meta_value)
            ORDER BY date DESC
        ", ARRAY_A);
        
        return $results;
    }
    
    public function get_format_stats() {
        return $this->db->get_results("
            SELECT 
                COUNT(*) as count,
                post_mime_type as format
            FROM {$this->db->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
            GROUP BY post_mime_type
            ORDER BY count DESC
        ", ARRAY_A);
    }
    
    public function get_top_savings($limit = 10) {
        return $this->db->get_results("
            SELECT 
                p.ID,
                p.post_title,
                meta1.meta_value as original_size,
                meta2.meta_value as converted_size,
                (CAST(meta1.meta_value AS UNSIGNED) - CAST(meta2.meta_value AS UNSIGNED)) as savings
            FROM {$this->db->posts} p
            INNER JOIN {$this->db->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_pwc_converted'
            INNER JOIN {$this->db->postmeta} meta1 ON p.ID = meta1.post_id AND meta1.meta_key = '_pwc_original_size'
            INNER JOIN {$this->db->postmeta} meta2 ON p.ID = meta2.post_id AND meta2.meta_key = '_pwc_converted_size'
            ORDER BY savings DESC
            LIMIT $limit
        ", ARRAY_A);
    }
    
    private function get_total_images_count() {
        return (int) $this->db->get_var("
            SELECT COUNT(*) 
            FROM {$this->db->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type LIKE 'image/%'
        ");
    }
    
    private function get_converted_images_count() {
        return (int) $this->db->get_var("
            SELECT COUNT(*) 
            FROM {$this->db->postmeta} 
            WHERE meta_key = '_pwc_converted'
        ");
    }
    
    private function get_failed_conversions_count() {
        return (int) $this->db->get_var("
            SELECT COUNT(*) 
            FROM {$this->db->postmeta} 
            WHERE meta_key = '_pwc_conversion_failed'
        ");
    }
    
    private function get_original_total_size() {
        return (int) $this->db->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED)) 
            FROM {$this->db->postmeta} 
            WHERE meta_key = '_pwc_original_size'
        ");
    }
    
    private function get_converted_total_size() {
        return (int) $this->db->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED)) 
            FROM {$this->db->postmeta} 
            WHERE meta_key = '_pwc_converted_size'
        ");
    }
    
    private function get_avg_conversion_time() {
        return (float) $this->db->get_var("
            SELECT AVG(CAST(meta_value AS UNSIGNED)) 
            FROM {$this->db->postmeta} 
            WHERE meta_key = '_pwc_conversion_time'
        ");
    }
    
    private function get_conversion_success_rate() {
        $total = $this->get_total_images_count();
        $converted = $this->get_converted_images_count();
        
        return $total > 0 ? round(($converted / $total) * 100, 2) : 0;
    }
    
    private function get_cache_hit_rate() {
        $hits = get_option('pwc_cache_hits', 0);
        $misses = get_option('pwc_cache_misses', 0);
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
    
    private function get_recent_activity() {
        return $this->db->get_results("
            SELECT 
                p.post_title,
                pm.meta_value as converted_time,
                meta1.meta_value as original_size,
                meta2.meta_value as converted_size
            FROM {$this->db->postmeta} pm
            INNER JOIN {$this->db->posts} p ON pm.post_id = p.ID
            LEFT JOIN {$this->db->postmeta} meta1 ON pm.post_id = meta1.post_id AND meta1.meta_key = '_pwc_original_size'
            LEFT JOIN {$this->db->postmeta} meta2 ON pm.post_id = meta2.post_id AND meta2.meta_key = '_pwc_converted_size'
            WHERE pm.meta_key = '_pwc_converted'
            ORDER BY pm.meta_value DESC
            LIMIT 10
        ", ARRAY_A);
    }
    
    private function get_server_info() {
        return [
            'software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : 'N/A',
            'memory_usage' => $this->format_bytes(memory_get_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
    }
    
    private function get_php_info() {
        return [
            'version' => PHP_VERSION,
            'extensions' => [
                'gd' => extension_loaded('gd'),
                'imagick' => extension_loaded('imagick'),
                'curl' => extension_loaded('curl'),
                'mbstring' => extension_loaded('mbstring')
            ]
        ];
    }
    
    private function get_wordpress_info() {
        return [
            'version' => get_bloginfo('version'),
            'multisite' => is_multisite(),
            'uploads_dir' => wp_upload_dir()['basedir'],
            'uploads_url' => wp_upload_dir()['baseurl']
        ];
    }
    
    private function get_plugin_info() {
        return [
            'version' => PWC_VERSION,
            'active_features' => $this->get_active_features(),
            'settings' => $this->get_settings_summary()
        ];
    }
    
    private function get_active_features() {
        $settings = get_option('pwc_settings', []);
        $features = [];
        
        if (!empty($settings['enable_conversion'])) $features[] = 'تبدیل خودکار';
        if (!empty($settings['enable_replacement'])) $features[] = 'جایگزینی خودکار';
        if (!empty($settings['enable_lazyload'])) $features[] = 'Lazyload';
        if (!empty($settings['enable_cdn'])) $features[] = 'CDN';
        if (!empty($settings['enable_caching'])) $features[] = 'کش';
        
        return $features;
    }
    
    private function get_settings_summary() {
        $settings = get_option('pwc_settings', []);
        
        return [
            'output_format' => $settings['output_format'] ?? 'webp',
            'quality' => $settings['quality'] ?? 80,
            'compression_level' => $settings['compression_level'] ?? 'medium'
        ];
    }
    
    public function log_conversion($attachment_id, $result) {
        $log_entry = [
            'time' => current_time('mysql'),
            'attachment_id' => $attachment_id,
            'result' => $result,
            'memory_usage' => memory_get_usage(true)
        ];
        
        $logs = get_option('pwc_conversion_logs', []);
        $logs[] = $log_entry;
        
        // نگه‌داری فقط 1000 لاگ آخر
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        update_option('pwc_conversion_logs', $logs);
    }
    
    public function cleanup_old_data() {
        // حذف لاگ‌های قدیمی‌تر از 30 روز
        $logs = get_option('pwc_conversion_logs', []);
        $cutoff = strtotime('-30 days');
        
        $logs = array_filter($logs, function($log) use ($cutoff) {
            return strtotime($log['time']) > $cutoff;
        });
        
        update_option('pwc_conversion_logs', array_values($logs));
        
        // بهینه‌سازی جداول
        $this->optimize_tables();
    }
    
    private function optimize_tables() {
        $tables = ['posts', 'postmeta', 'options'];
        
        foreach ($tables as $table) {
            $this->db->query("OPTIMIZE TABLE {$this->db->$table}");
        }
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function ajax_get_stats() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $type = $_POST['type'] ?? 'overall';
        
        switch ($type) {
            case 'overall':
                $data = $this->get_overall_stats();
                break;
            case 'daily':
                $data = $this->get_daily_stats();
                break;
            case 'formats':
                $data = $this->get_format_stats();
                break;
            case 'top_savings':
                $data = $this->get_top_savings();
                break;
            default:
                $data = $this->get_overall_stats();
        }
        
        wp_send_json_success($data);
    }
    
    public function get_api_status() {
        return [
            'status' => 'active',
            'version' => PWC_VERSION,
            'timestamp' => current_time('mysql'),
            'stats' => $this->get_conversion_stats()
        ];
    }
}