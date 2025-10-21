<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Core {
    private static $instance = null;
    public $converter;
    public $optimizer;
    public $cache;
    public $lazyload;
    public $cdn;
    public $stats;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_modules();
        $this->setup_hooks();
    }
    
    private function load_dependencies() {
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-converter.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-optimizer.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-cache.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-lazyload.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-cdn.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-stats.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-batch.php';
        require_once PWC_PLUGIN_DIR . 'includes/class-pwc-tools.php';
    }
    
    private function init_modules() {
        $this->converter = new PWC_Converter();
        $this->optimizer = new PWC_Optimizer();
        $this->cache = new PWC_Cache();
        $this->lazyload = new PWC_Lazyload();
        $this->cdn = new PWC_CDN();
        $this->stats = new PWC_Stats();
        
        // راه‌اندازی batch processing
        if (is_admin()) {
            new PWC_Batch();
        }
        
        // راه‌اندازی tools
        new PWC_Tools();
    }
    
    private function setup_hooks() {
        // تبدیل تصاویر آپلود شده
        add_filter('wp_generate_attachment_metadata', [$this->converter, 'handle_upload'], 10, 2);
        
        // جایگزینی تصاویر در محتوا
        add_filter('the_content', [$this->converter, 'replace_content_images'], 99);
        add_filter('post_thumbnail_html', [$this->converter, 'replace_single_image'], 10, 5);
        
        // وب‌سرویس برای بررسی وضعیت
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // هوک‌های دوره‌ای
        add_action('pwc_daily_maintenance', [$this->cache, 'cleanup']);
        add_action('pwc_daily_maintenance', [$this->stats, 'cleanup_old_data']);
        
        // AJAX handlers
        add_action('wp_ajax_pwc_get_stats', [$this->stats, 'ajax_get_stats']);
        add_action('wp_ajax_pwc_clear_cache', [$this->cache, 'ajax_clear_cache']);
    }
    
    public function register_rest_routes() {
        register_rest_route('pwc/v1', '/status', [
            'methods' => 'GET',
            'callback' => [$this->stats, 'get_api_status'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    public function get_conversion_stats() {
        return $this->stats->get_overall_stats();
    }
}