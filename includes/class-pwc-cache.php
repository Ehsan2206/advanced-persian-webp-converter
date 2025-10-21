<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Cache {
    private $cache_dir;
    private $cache_url;
    private $max_size;
    
    public function __construct() {
        $this->cache_dir = PWC_CACHE_DIR;
        $this->cache_url = PWC_CACHE_URL;
        $this->max_size = get_option('pwc_cache_max_size', 500) * 1024 * 1024; // به بایت
        
        $this->init_cache();
    }
    
    private function init_cache() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            
            // ایجاد فایل امنیتی
            file_put_contents($this->cache_dir . '.htaccess', '
Order Deny,Allow
Deny from all
<Files ~ "\.(webp|avif)$">
    Allow from all
</Files>
');
            
            file_put_contents($this->cache_dir . 'index.html', '');
        }
    }
    
    public function get($key, $group = 'default') {
        $file_path = $this->get_file_path($key, $group);
        
        if (file_exists($file_path)) {
            $data = file_get_contents($file_path);
            $cache_data = unserialize($data);
            
            // بررسی انقضا
            if (isset($cache_data['expires']) && $cache_data['expires'] < time()) {
                $this->delete($key, $group);
                return false;
            }
            
            return $cache_data['data'] ?? false;
        }
        
        return false;
    }
    
    public function set($key, $data, $group = 'default', $expires = 0) {
        $file_path = $this->get_file_path($key, $group);
        $dir = dirname($file_path);
        
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        $cache_data = [
            'data' => $data,
            'created' => time(),
            'expires' => $expires ? time() + $expires : 0
        ];
        
        $result = file_put_contents($file_path, serialize($cache_data), LOCK_EX);
        
        if ($result !== false) {
            $this->enforce_limits();
            return true;
        }
        
        return false;
    }
    
    public function delete($key, $group = 'default') {
        $file_path = $this->get_file_path($key, $group);
        
        if (file_exists($file_path)) {
            return unlink($file_path);
        }
        
        return true;
    }
    
    public function clear_group($group) {
        $group_dir = $this->cache_dir . $group . '/';
        
        if (file_exists($group_dir)) {
            $files = glob($group_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            return true;
        }
        
        return false;
    }
    
    public function clear_all() {
        $files = glob($this->cache_dir . '*');
        $deleted = 0;
        
        foreach ($files as $file) {
            if (is_file($file) && basename($file) !== '.htaccess' && basename($file) !== 'index.html') {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        // پاکسازی پوشه‌های گروه
        $groups = glob($this->cache_dir . '*/', GLOB_ONLYDIR);
        foreach ($groups as $group_dir) {
            $group_files = glob($group_dir . '*');
            foreach ($group_files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
            if (is_dir($group_dir)) {
                rmdir($group_dir);
            }
        }
        
        return $deleted;
    }
    
    public function get_stats() {
        $total_size = 0;
        $total_files = 0;
        
        $files = $this->get_all_files();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $total_size += filesize($file);
                $total_files++;
            }
        }
        
        return [
            'total_files' => $total_files,
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'max_size_mb' => round($this->max_size / 1024 / 1024, 2),
            'usage_percent' => $this->max_size > 0 ? round(($total_size / $this->max_size) * 100, 2) : 0
        ];
    }
    
    public function cleanup() {
        $this->clear_expired();
        $this->enforce_limits();
    }
    
    private function clear_expired() {
        $files = $this->get_all_files();
        $cleared = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $data = file_get_contents($file);
                $cache_data = unserialize($data);
                
                if (isset($cache_data['expires']) && $cache_data['expires'] > 0 && $cache_data['expires'] < time()) {
                    if (unlink($file)) {
                        $cleared++;
                    }
                }
            }
        }
        
        return $cleared;
    }
    
    private function enforce_limits() {
        $stats = $this->get_stats();
        
        if ($stats['total_size'] > $this->max_size) {
            $this->remove_oldest_files();
        }
    }
    
    private function remove_oldest_files() {
        $files = $this->get_all_files_with_mtime();
        
        // مرتب‌سازی بر اساس زمان تغییر (قدیمی‌ترین اول)
        usort($files, function($a, $b) {
            return $a['mtime'] - $b['mtime'];
        });
        
        $current_size = $this->get_stats()['total_size'];
        $target_size = $this->max_size * 0.8; // تا 80% کاهش بده
        
        $removed = 0;
        
        foreach ($files as $file) {
            if ($current_size <= $target_size) {
                break;
            }
            
            if (unlink($file['path'])) {
                $current_size -= $file['size'];
                $removed++;
            }
        }
        
        return $removed;
    }
    
    private function get_file_path($key, $group) {
        $hash = md5($key);
        $subdir = substr($hash, 0, 2);
        
        if ($group === 'default') {
            return $this->cache_dir . $subdir . '/' . $hash . '.cache';
        }
        
        return $this->cache_dir . $group . '/' . $subdir . '/' . $hash . '.cache';
    }
    
    private function get_all_files() {
        $files = [];
        $pattern = $this->cache_dir . '**/*.cache';
        
        $found = glob($pattern, GLOB_BRACE);
        if ($found) {
            $files = array_merge($files, $found);
        }
        
        return $files;
    }
    
    private function get_all_files_with_mtime() {
        $files = [];
        $all_files = $this->get_all_files();
        
        foreach ($all_files as $file_path) {
            if (is_file($file_path)) {
                $files[] = [
                    'path' => $file_path,
                    'size' => filesize($file_path),
                    'mtime' => filemtime($file_path)
                ];
            }
        }
        
        return $files;
    }
    
    public function ajax_clear_cache() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $cleared = $this->clear_all();
        
        wp_send_json_success([
            'message' => 'کش با موفقیت پاکسازی شد',
            'cleared_files' => $cleared
        ]);
    }
}