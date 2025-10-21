<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Frontend {
    private static $instance = null;
    private $settings;
    private $core;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->core = PWC_Core::get_instance();
        $this->settings = get_option('pwc_settings', []);
        $this->setup_hooks();
    }
    
    private function setup_hooks() {
        // بهینه‌سازی frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_head', [$this, 'add_preload_tags']);
        add_action('wp_footer', [$this, 'add_performance_metrics']);
        
        // جایگزینی پیشرفته تصاویر
        add_filter('the_content', [$this, 'optimize_content_images'], 99);
        add_filter('post_thumbnail_html', [$this, 'optimize_thumbnail'], 10, 5);
        add_filter('wp_get_attachment_image_attributes', [$this, 'optimize_attachment_attributes'], 10, 3);
        add_filter('wp_calculate_image_srcset', [$this, 'optimize_srcset'], 10, 5);
        
        // بهینه‌سازی CSS و JS
        add_action('wp_print_styles', [$this, 'dequeue_unused_styles']);
        add_action('wp_print_scripts', [$this, 'dequeue_unused_scripts']);
        
        // اضافه کردن schema markup
        add_action('wp_head', [$this, 'add_schema_markup']);
        
        // مدیریت کش مرورگر
        add_action('send_headers', [$this, 'add_cache_headers']);
    }
    
    public function enqueue_assets() {
        if ($this->should_optimize_frontend()) {
            // استایل‌های بهینه‌سازی شده
            wp_enqueue_style('pwc-optimized', PWC_PLUGIN_URL . 'assets/css/optimized.css', [], PWC_VERSION);
            
            // اسکریپت‌های ضروری
            wp_enqueue_script('pwc-frontend', PWC_PLUGIN_URL . 'assets/js/frontend.js', [], PWC_VERSION, true);
            
            // محلی‌سازی
            wp_localize_script('pwc-frontend', 'pwcFrontend', [
                'lazyloadEnabled' => !empty($this->settings['enable_lazyload']),
                'cdnEnabled' => !empty($this->settings['enable_cdn']),
                'siteUrl' => site_url()
            ]);
        }
    }
    
    public function add_preload_tags() {
        if (!$this->should_optimize_frontend()) {
            return;
        }
        
        // پیش‌بارگذاری فونت‌های حیاتی
        echo '<link rel="preload" href="' . PWC_PLUGIN_URL . 'assets/fonts/iransans.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
        
        // پیش‌بارگذاری تصاویر مهم
        if (is_singular()) {
            $post_id = get_the_ID();
            $thumbnail_id = get_post_thumbnail_id($post_id);
            
            if ($thumbnail_id) {
                $converted_url = $this->get_converted_image_url($thumbnail_id, 'large');
                if ($converted_url) {
                    echo '<link rel="preload" as="image" href="' . esc_url($converted_url) . '">' . "\n";
                }
            }
        }
    }
    
    public function add_performance_metrics() {
        if (!current_user_can('manage_options') || !$this->should_optimize_frontend()) {
            return;
        }
        
        ?>
        <script>
        // اندازه‌گیری عملکرد frontend
        window.addEventListener('load', function() {
            var timing = performance.timing;
            var loadTime = timing.loadEventEnd - timing.navigationStart;
            var domReady = timing.domContentLoadedEventEnd - timing.navigationStart;
            
            console.log('PWC Performance Metrics:');
            console.log('Page Load Time: ' + loadTime + 'ms');
            console.log('DOM Ready: ' + domReady + 'ms');
            
            // ارسال به Google Analytics (در صورت وجود)
            if (typeof gtag !== 'undefined') {
                gtag('event', 'timing_complete', {
                    'name': 'page_load',
                    'value': loadTime,
                    'event_category': 'PWC Performance'
                });
            }
        });
        </script>
        <?php
    }
    
    public function optimize_content_images($content) {
        if (empty($content) || !$this->should_optimize_images()) {
            return $content;
        }
        
        // استفاده از DOMDocument برای پردازش ایمن
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $this->optimize_single_image($img);
        }
        
        return $dom->saveHTML();
    }
    
    public function optimize_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (empty($html) || !$this->should_optimize_images()) {
            return $html;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $this->optimize_single_image($img);
        }
        
        return $dom->saveHTML();
    }
    
    public function optimize_attachment_attributes($attr, $attachment, $size) {
        if (!$this->should_optimize_images()) {
            return $attr;
        }
        
        // جایگزینی با تصویر تبدیل شده
        $converted_url = $this->get_converted_image_url($attachment->ID, $size);
        
        if ($converted_url && isset($attr['src'])) {
            $attr['src'] = $converted_url;
        }
        
        // بهینه‌سازی srcset
        if (isset($attr['srcset'])) {
            $attr['srcset'] = $this->optimize_srcset_string($attr['srcset']);
        }
        
        // اضافه کردن loading lazy
        if (!isset($attr['loading']) && $this->should_lazyload()) {
            $attr['loading'] = 'lazy';
        }
        
        // اضافه کردن decoding async
        if (!isset($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }
        
        return $attr;
    }
    
    public function optimize_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!$this->should_optimize_images() || empty($sources)) {
            return $sources;
        }
        
        foreach ($sources as $width => $source) {
            if (isset($source['url'])) {
                $converted_url = $this->get_converted_image_url($attachment_id, $width);
                if ($converted_url) {
                    $sources[$width]['url'] = $converted_url;
                }
            }
        }
        
        return $sources;
    }
    
    private function optimize_single_image($img) {
        $src = $img->getAttribute('src');
        
        // جایگزینی با تصویر تبدیل شده
        $converted_url = $this->get_converted_image_url_from_url($src);
        
        if ($converted_url && $converted_url !== $src) {
            $img->setAttribute('src', $converted_url);
            
            // بهینه‌سازی srcset
            $srcset = $img->getAttribute('srcset');
            if ($srcset) {
                $optimized_srcset = $this->optimize_srcset_string($srcset);
                $img->setAttribute('srcset', $optimized_srcset);
            }
        }
        
        // اضافه کردن attributes بهینه‌سازی
        if (!$img->hasAttribute('loading')) {
            $img->setAttribute('loading', 'lazy');
        }
        
        if (!$img->hasAttribute('decoding')) {
            $img->setAttribute('decoding', 'async');
        }
        
        // اضافه کردن ابعاد دقیق
        if (!$img->hasAttribute('width') && !$img->hasAttribute('height')) {
            $dimensions = $this->get_image_dimensions($src);
            if ($dimensions) {
                $img->setAttribute('width', $dimensions['width']);
                $img->setAttribute('height', $dimensions['height']);
            }
        }
    }
    
    private function get_converted_image_url($attachment_id, $size = 'full') {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $output_format = $this->settings['output_format'] ?? 'webp';
        $converted_path = $this->generate_output_path($file_path, $output_format);
        
        if (file_exists($converted_path)) {
            $upload_dir = wp_upload_dir();
            return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $converted_path);
        }
        
        return false;
    }
    
    private function get_converted_image_url_from_url($image_url) {
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
        
        if (file_exists($file_path)) {
            $output_format = $this->settings['output_format'] ?? 'webp';
            $converted_path = $this->generate_output_path($file_path, $output_format);
            
            if (file_exists($converted_path)) {
                return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $converted_path);
            }
        }
        
        return false;
    }
    
    private function generate_output_path($file_path, $format) {
        $info = pathinfo($file_path);
        return $info['dirname'] . '/' . $info['filename'] . '.' . $format;
    }
    
    private function optimize_srcset_string($srcset) {
        $sources = explode(', ', $srcset);
        $optimized_sources = [];
        
        foreach ($sources as $source) {
            $parts = explode(' ', $source);
            $url = $parts[0];
            $descriptor = isset($parts[1]) ? $parts[1] : '';
            
            $optimized_url = $this->get_converted_image_url_from_url($url);
            if ($optimized_url) {
                $optimized_sources[] = $optimized_url . ($descriptor ? ' ' . $descriptor : '');
            } else {
                $optimized_sources[] = $source;
            }
        }
        
        return implode(', ', $optimized_sources);
    }
    
    private function get_image_dimensions($image_url) {
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
        
        if (file_exists($file_path)) {
            $size = getimagesize($file_path);
            if ($size) {
                return [
                    'width' => $size[0],
                    'height' => $size[1]
                ];
            }
        }
        
        return false;
    }
    
    public function dequeue_unused_styles() {
        if (!$this->should_optimize_frontend() || is_admin()) {
            return;
        }
        
        // حذف استایل‌های غیرضروری
        $dequeue_styles = [
            'dashicons', // فقط در admin نیاز است
            'wp-block-library' // در صورت استفاده نکردن از block editor
        ];
        
        foreach ($dequeue_styles as $style) {
            if (wp_style_is($style, 'enqueued')) {
                wp_dequeue_style($style);
            }
        }
    }
    
    public function dequeue_unused_scripts() {
        if (!$this->should_optimize_frontend() || is_admin()) {
            return;
        }
        
        // حذف اسکریپت‌های غیرضروری
        $dequeue_scripts = [
            'jquery-migrate' // در صورت عدم نیاز به سازگاری با jQuery قدیمی
        ];
        
        foreach ($dequeue_scripts as $script) {
            if (wp_script_is($script, 'enqueued')) {
                wp_dequeue_script($script);
            }
        }
    }
    
    public function add_schema_markup() {
        if (!$this->should_optimize_frontend()) {
            return;
        }
        
        if (is_singular() && has_post_thumbnail()) {
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'ImageObject',
                'contentUrl' => get_the_post_thumbnail_url(get_the_ID(), 'full'),
                'license' => get_site_url(),
                'acquireLicensePage' => get_site_url()
            ];
            
            echo '<script type="application/ld+json">' . json_encode($schema) . '</script>' . "\n";
        }
    }
    
    public function add_cache_headers() {
        if (!$this->should_optimize_frontend()) {
            return;
        }
        
        // اضافه کردن هدرهای کش برای تصاویر
        if (preg_match('/\.(webp|avif|jpg|jpeg|png|gif)$/i', $_SERVER['REQUEST_URI'])) {
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
        }
    }
    
    private function should_optimize_frontend() {
        return !is_admin() && !is_preview() && !is_feed();
    }
    
    private function should_optimize_images() {
        return !empty($this->settings['enable_replacement']) && $this->should_optimize_frontend();
    }
    
    private function should_lazyload() {
        return !empty($this->settings['enable_lazyload']) && $this->should_optimize_frontend();
    }
    
    // متدهای کمکی برای توسعه‌دهندگان
    public function get_optimized_image_url($image_url, $format = null) {
        if (!$format) {
            $format = $this->settings['output_format'] ?? 'webp';
        }
        
        return $this->get_converted_image_url_from_url($image_url) ?: $image_url;
    }
    
    public function is_image_optimized($image_url) {
        $converted_url = $this->get_converted_image_url_from_url($image_url);
        return $converted_url && $converted_url !== $image_url;
    }
    
    public function get_optimization_stats() {
        return [
            'total_optimized' => $this->core->stats->get_converted_images_count(),
            'total_savings' => $this->core->stats->get_savings_stats(),
            'performance_impact' => $this->calculate_performance_impact()
        ];
    }
    
    private function calculate_performance_impact() {
        // محاسبه تأثیر بهینه‌سازی بر عملکرد
        $stats = $this->core->stats->get_savings_stats();
        $savings_bytes = $stats['savings_bytes'];
        
        // فرض: هر مگابایت صرفه‌جویی ≈ 0.1 ثانیه بهبود سرعت
        $improvement = ($savings_bytes / (1024 * 1024)) * 0.1;
        
        return [
            'estimated_improvement' => round($improvement, 2) . ' seconds',
            'bandwidth_savings' => $stats['savings_size'],
            'savings_percent' => $stats['savings_percent']
        ];
    }
}