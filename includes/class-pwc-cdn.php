<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_CDN {
    private $enabled;
    private $cdn_url;
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('pwc_settings', []);
        $this->enabled = !empty($this->settings['enable_cdn']);
        $this->cdn_url = $this->settings['cdn_url'] ?? '';
        
        if ($this->enabled && $this->cdn_url) {
            $this->setup_hooks();
        }
    }
    
    private function setup_hooks() {
        // جایگزینی URL تصاویر با CDN
        add_filter('wp_get_attachment_url', [$this, 'replace_attachment_url'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'replace_srcset_urls'], 10, 5);
        
        // جایگزینی در محتوا
        add_filter('the_content', [$this, 'replace_content_urls'], 99);
        
        // پاکسازی کش CDN
        add_action('pwc_after_image_conversion', [$this, 'purge_cdn_cache'], 10, 2);
    }
    
    public function replace_attachment_url($url, $post_id) {
        if (!$this->should_use_cdn($url)) {
            return $url;
        }
        
        return $this->convert_to_cdn_url($url);
    }
    
    public function replace_srcset_urls($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->enabled || empty($sources)) {
            return $sources;
        }
        
        foreach ($sources as $width => $source) {
            if (isset($source['url']) && $this->should_use_cdn($source['url'])) {
                $sources[$width]['url'] = $this->convert_to_cdn_url($source['url']);
            }
        }
        
        return $sources;
    }
    
    public function replace_content_urls($content) {
        if (!$this->enabled || empty($content)) {
            return $content;
        }
        
        $site_url = site_url();
        $cdn_url = $this->cdn_url;
        
        // جایگزینی ساده برای تصاویر
        $content = str_replace(
            $site_url . '/wp-content/uploads/',
            $cdn_url . '/wp-content/uploads/',
            $content
        );
        
        return $content;
    }
    
    public function purge_cdn_cache($image_path, $converted_path) {
        if (!$this->enabled) {
            return;
        }
        
        $urls_to_purge = [];
        
        // اضافه کردن URL اصلی
        $upload_dir = wp_upload_dir();
        $original_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $image_path);
        $urls_to_purge[] = $this->convert_to_cdn_url($original_url);
        
        // اضافه کردن URL تبدیل شده
        $converted_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $converted_path);
        $urls_to_purge[] = $this->convert_to_cdn_url($converted_url);
        
        // پاکسازی کش CDN
        $this->purge_urls($urls_to_purge);
    }
    
    private function convert_to_cdn_url($url) {
        $site_url = site_url();
        $cdn_url = rtrim($this->cdn_url, '/');
        
        return str_replace($site_url, $cdn_url, $url);
    }
    
    private function should_use_cdn($url) {
        // فقط تصاویر از آپلودها را جایگزین کن
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];
        
        return strpos($url, $upload_url) !== false;
    }
    
    private function purge_urls($urls) {
        $cdn_provider = $this->settings['cdn_provider'] ?? 'generic';
        
        switch ($cdn_provider) {
            case 'cloudflare':
                return $this->purge_cloudflare($urls);
            case 'bunny':
                return $this->purge_bunny($urls);
            case 'keycdn':
                return $this->purge_keycdn($urls);
            default:
                return $this->purge_generic($urls);
        }
    }
    
    private function purge_cloudflare($urls) {
        $zone_id = $this->settings['cloudflare_zone_id'] ?? '';
        $api_key = $this->settings['cloudflare_api_key'] ?? '';
        $email = $this->settings['cloudflare_email'] ?? '';
        
        if (empty($zone_id) || empty($api_key) || empty($email)) {
            return false;
        }
        
        $data = ['files' => $urls];
        
        $response = wp_remote_post("https://api.cloudflare.com/client/v4/zones/{$zone_id}/purge_cache", [
            'headers' => [
                'X-Auth-Email' => $email,
                'X-Auth-Key' => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 30
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    private function purge_bunny($urls) {
        $api_key = $this->settings['bunny_api_key'] ?? '';
        $pull_zone_id = $this->settings['bunny_pull_zone_id'] ?? '';
        
        if (empty($api_key) || empty($pull_zone_id)) {
            return false;
        }
        
        foreach ($urls as $url) {
            $purge_url = "https://bunnycdn.com/api/pullzone/{$pull_zone_id}/purgeCache?url=" . urlencode($url);
            
            $response = wp_remote_post($purge_url, [
                'headers' => [
                    'AccessKey' => $api_key,
                ],
                'timeout' => 30
            ]);
        }
        
        return true;
    }
    
    private function purge_generic($urls) {
        // پاکسازی عمومی - درخواست HEAD به هر URL
        foreach ($urls as $url) {
            wp_remote_head($url, [
                'timeout' => 5,
                'redirection' => 0
            ]);
        }
        
        return true;
    }
    
    public function test_connection() {
        if (!$this->enabled || empty($this->cdn_url)) {
            return ['success' => false, 'message' => 'CDN غیرفعال است'];
        }
        
        $test_url = $this->cdn_url . '/wp-content/uploads/test-image.jpg';
        
        $response = wp_remote_head($test_url, [
            'timeout' => 10,
            'redirection' => 0
        ]);
        
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code === 200 || $status_code === 404) {
            return ['success' => true, 'message' => 'اتصال CDN موفقیت‌آمیز بود'];
        } else {
            return ['success' => false, 'message' => "خطا در اتصال: کد وضعیت {$status_code}"];
        }
    }
}