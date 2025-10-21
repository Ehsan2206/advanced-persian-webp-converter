<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Admin {
    private static $instance = null;
    private $core;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->core = PWC_Core::get_instance();
        $this->setup_hooks();
    }
    
    private function setup_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_pwc_start_batch', [$this, 'ajax_start_batch']);
        add_action('wp_ajax_pwc_stop_batch', [$this, 'ajax_stop_batch']);
        add_action('wp_ajax_pwc_get_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_pwc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_pwc_export_stats', [$this, 'ajax_export_stats']);
        
        // لینک سریع در صفحه پلاگین‌ها
        add_filter('plugin_action_links_' . plugin_basename(PWC_PLUGIN_DIR . 'advanced-persian-webp-converter.php'), [$this, 'add_plugin_links']);
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'تبدیلر پیشرفته WebP',
            'WebP Converter',
            'manage_options',
            'pwc-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-format-image',
            30
        );
        
        add_submenu_page(
            'pwc-dashboard',
            'داشبورد - تبدیلر WebP',
            'داشبورد',
            'manage_options',
            'pwc-dashboard',
            [$this, 'render_dashboard']
        );
        
        add_submenu_page(
            'pwc-dashboard',
            'تنظیمات - تبدیلر WebP',
            'تنظیمات',
            'manage_options',
            'pwc-settings',
            [$this, 'render_settings']
        );
        
        add_submenu_page(
            'pwc-dashboard',
            'تبدیل دسته‌ای - تبدیلر WebP',
            'تبدیل دسته‌ای',
            'manage_options',
            'pwc-batch',
            [$this, 'render_batch']
        );
        
        add_submenu_page(
            'pwc-dashboard',
            'آمار و گزارشات - تبدیلر WebP',
            'آمار و گزارشات',
            'manage_options',
            'pwc-stats',
            [$this, 'render_stats']
        );
        
        add_submenu_page(
            'pwc-dashboard',
            'ابزارها - تبدیلر WebP',
            'ابزارها',
            'manage_options',
            'pwc-tools',
            [$this, 'render_tools']
        );
    }
    
    public function enqueue_assets($hook) {
        if (strpos($hook, 'pwc-') === false) {
            return;
        }
        
        wp_enqueue_style('pwc-admin', PWC_PLUGIN_URL . 'assets/css/admin.css', [], PWC_VERSION);
        wp_enqueue_script('pwc-admin', PWC_PLUGIN_URL . 'assets/js/admin.js', ['jquery', 'chart.js'], PWC_VERSION, true);
        
        // اضافه کردن Chart.js
        wp_enqueue_script('chart.js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        
        // محلی‌سازی اسکریپت
        wp_localize_script('pwc-admin', 'pwcAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pwc_nonce'),
            'strings' => [
                'processing' => 'در حال پردازش...',
                'completed' => 'کامل شد',
                'error' => 'خطا',
                'confirm_delete' => 'آیا مطمئن هستید؟'
            ]
        ]);
    }
    
    public function register_settings() {
        register_setting('pwc_settings_group', 'pwc_settings', [$this, 'sanitize_settings']);
        
        // بخش‌های تنظیمات
        add_settings_section('pwc_general_section', 'تنظیمات عمومی', [$this, 'general_section_callback'], 'pwc-settings');
        add_settings_section('pwc_compression_section', 'تنظیمات فشرده‌سازی', [$this, 'compression_section_callback'], 'pwc-settings');
        add_settings_section('pwc_optimization_section', 'بهینه‌سازی پیشرفته', [$this, 'optimization_section_callback'], 'pwc-settings');
        add_settings_section('pwc_cdn_section', 'تنظیمات CDN', [$this, 'cdn_section_callback'], 'pwc-settings');
        
        // فیلدهای تنظیمات
        $this->add_settings_fields();
    }
    
    private function add_settings_fields() {
        // تنظیمات عمومی
        add_settings_field(
            'enable_conversion',
            'فعال‌سازی تبدیل خودکار',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_general_section',
            ['name' => 'enable_conversion', 'label' => 'تبدیل خودکار تصاویر جدید']
        );
        
        add_settings_field(
            'enable_replacement',
            'جایگزینی خودکار',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_general_section',
            ['name' => 'enable_replacement', 'label' => 'جایگزینی خودکار تصاویر در محتوا']
        );
        
        add_settings_field(
            'output_format',
            'فرمت خروجی',
            [$this, 'select_field_callback'],
            'pwc-settings',
            'pwc_general_section',
            [
                'name' => 'output_format',
                'options' => [
                    'webp' => 'WebP (پیشنهادی)',
                    'avif' => 'AVIF (فشرده‌تر)'
                ]
            ]
        );
        
        // تنظیمات فشرده‌سازی
        add_settings_field(
            'quality',
            'کیفیت تبدیل',
            [$this, 'range_field_callback'],
            'pwc-settings',
            'pwc_compression_section',
            [
                'name' => 'quality',
                'min' => 40,
                'max' => 100,
                'step' => 1,
                'unit' => '%'
            ]
        );
        
        add_settings_field(
            'compression_level',
            'سطح فشرده‌سازی',
            [$this, 'select_field_callback'],
            'pwc-settings',
            'pwc_compression_section',
            [
                'name' => 'compression_level',
                'options' => [
                    'lossless' => 'بدون فشرده‌سازی (کیفیت 100%)',
                    'high' => 'کیفیت بالا (85%)',
                    'medium' => 'متوسط (75%)',
                    'low' => 'فشرده‌سازی بالا (60%)'
                ]
            ]
        );
        
        add_settings_field(
            'strip_metadata',
            'حذف متادیتا',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_compression_section',
            ['name' => 'strip_metadata', 'label' => 'حذف اطلاعات EXIF و متادیتا']
        );
        
        // بهینه‌سازی پیشرفته
        add_settings_field(
            'enable_lazyload',
            'Lazy Load',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_optimization_section',
            ['name' => 'enable_lazyload', 'label' => 'فعال‌سازی Lazy Load برای تصاویر']
        );
        
        add_settings_field(
            'enable_caching',
            'سیستم کش',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_optimization_section',
            ['name' => 'enable_caching', 'label' => 'فعال‌سازی کش تصاویر تبدیل شده']
        );
        
        add_settings_field(
            'cache_max_size',
            'حداکثر حجم کش',
            [$this, 'number_field_callback'],
            'pwc-settings',
            'pwc_optimization_section',
            ['name' => 'cache_max_size', 'min' => 50, 'max' => 5000, 'step' => 50, 'unit' => 'MB']
        );
        
        // تنظیمات CDN
        add_settings_field(
            'enable_cdn',
            'فعال‌سازی CDN',
            [$this, 'checkbox_field_callback'],
            'pwc-settings',
            'pwc_cdn_section',
            ['name' => 'enable_cdn', 'label' => 'استفاده از CDN برای تصاویر']
        );
        
        add_settings_field(
            'cdn_url',
            'آدرس CDN',
            [$this, 'text_field_callback'],
            'pwc-settings',
            'pwc_cdn_section',
            ['name' => 'cdn_url', 'placeholder' => 'https://cdn.yourdomain.com']
        );
    }
    
    public function render_dashboard() {
        $stats = $this->core->stats->get_overall_stats();
        ?>
        <div class="wrap pwc-dashboard">
            <h1 class="pwc-title">🎯 داشبورد تبدیلر پیشرفته WebP</h1>
            
            <!-- کارت‌های آمار -->
            <div class="pwc-stats-grid">
                <div class="pwc-stat-card">
                    <div class="pwc-stat-icon">📊</div>
                    <div class="pwc-stat-content">
                        <h3>تصاویر تبدیل شده</h3>
                        <div class="pwc-stat-number"><?php echo number_format($stats['conversion']['converted_images']); ?></div>
                        <div class="pwc-stat-desc">از <?php echo number_format($stats['conversion']['total_images']); ?> تصویر</div>
                    </div>
                </div>
                
                <div class="pwc-stat-card">
                    <div class="pwc-stat-icon">💾</div>
                    <div class="pwc-stat-content">
                        <h3>حجم ذخیره‌شده</h3>
                        <div class="pwc-stat-number"><?php echo $stats['savings']['savings_size']; ?></div>
                        <div class="pwc-stat-desc"><?php echo $stats['savings']['savings_percent']; ?>% کاهش</div>
                    </div>
                </div>
                
                <div class="pwc-stat-card">
                    <div class="pwc-stat-icon">⚡</div>
                    <div class="pwc-stat-content">
                        <h3>نرخ موفقیت</h3>
                        <div class="pwc-stat-number"><?php echo $stats['performance']['success_rate']; ?>%</div>
                        <div class="pwc-stat-desc">تبدیل موفق</div>
                    </div>
                </div>
                
                <div class="pwc-stat-card">
                    <div class="pwc-stat-icon">🕒</div>
                    <div class="pwc-stat-content">
                        <h3>میانگین زمان</h3>
                        <div class="pwc-stat-number"><?php echo $stats['performance']['avg_conversion_time']; ?>s</div>
                        <div class="pwc-stat-desc">برای هر تصویر</div>
                    </div>
                </div>
            </div>
            
            <!-- نمودارها و اطلاعات بیشتر -->
            <div class="pwc-dashboard-content">
                <div class="pwc-row">
                    <div class="pwc-col-6">
                        <div class="pwc-card">
                            <h3>📈 آمار تبدیل روزانه</h3>
                            <canvas id="pwc-daily-chart" height="250"></canvas>
                        </div>
                    </div>
                    <div class="pwc-col-6">
                        <div class="pwc-card">
                            <h3>🎯 فعالیت‌های اخیر</h3>
                            <div class="pwc-activity-list">
                                <?php $this->render_recent_activity($stats['performance']['recent_activity']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pwc-row">
                    <div class="pwc-col-12">
                        <div class="pwc-card">
                            <h3>🚀 اقدامات سریع</h3>
                            <div class="pwc-quick-actions">
                                <button class="button button-primary pwc-start-batch">شروع تبدیل دسته‌ای</button>
                                <button class="button button-secondary pwc-clear-cache">پاکسازی کش</button>
                                <button class="button" onclick="location.href='<?php echo admin_url('admin.php?page=pwc-settings'); ?>'">تنظیمات پیشرفته</button>
                                <button class="button" onclick="location.href='<?php echo admin_url('admin.php?page=pwc-stats'); ?>'">مشاهده گزارش کامل</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // بارگذاری نمودار روزانه
            $.ajax({
                url: pwcAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pwc_get_stats',
                    type: 'daily',
                    nonce: pwcAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        pwcRenderDailyChart(response.data);
                    }
                }
            });
        });
        
        function pwcRenderDailyChart(data) {
            var ctx = document.getElementById('pwc-daily-chart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(item => item.date),
                    datasets: [{
                        label: 'تعداد تبدیل‌ها',
                        data: data.map(item => item.conversions),
                        borderColor: '#0073aa',
                        backgroundColor: 'rgba(0, 115, 170, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        </script>
        <?php
    }
    
    public function render_settings() {
        ?>
        <div class="wrap pwc-settings">
            <h1 class="pwc-title">⚙️ تنظیمات تبدیلر WebP</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('pwc_settings_group');
                do_settings_sections('pwc-settings');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>
            
            <div class="pwc-settings-sidebar">
                <div class="pwc-card">
                    <h3>🎯 راهنمای تنظیمات</h3>
                    <div class="pwc-tips">
                        <p><strong>فرمت WebP:</strong> سازگاری بهتر با مرورگرها</p>
                        <p><strong>فرمت AVIF:</strong> فشرده‌سازی بهتر اما سازگاری کمتر</p>
                        <p><strong>کیفیت 75-85:</strong> مناسب برای اکثر وبسایت‌ها</p>
                        <p><strong>Lazy Load:</strong> بهبود سرعت بارگذاری صفحه</p>
                    </div>
                </div>
                
                <div class="pwc-card">
                    <h3>🧪 تست تنظیمات</h3>
                    <button class="button button-secondary pwc-test-settings">تست تنظیمات فعلی</button>
                    <div id="pwc-test-result"></div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function render_batch() {
        $pending = $this->core->stats->get_conversion_stats()['pending_images'];
        ?>
        <div class="wrap pwc-batch">
            <h1 class="pwc-title">📦 تبدیل دسته‌ای تصاویر</h1>
            
            <div class="pwc-batch-stats">
                <div class="pwc-batch-stat">
                    <span>تصاویر در انتظار تبدیل:</span>
                    <strong id="pwc-pending-count"><?php echo number_format($pending); ?></strong>
                </div>
            </div>
            
            <div class="pwc-progress-section">
                <div class="pwc-progress-bar">
                    <div id="pwc-progress-fill" style="width: 0%"></div>
                </div>
                <div class="pwc-progress-info">
                    <span id="pwc-progress-percent">0%</span>
                    <span id="pwc-progress-text">آماده</span>
                </div>
            </div>
            
            <div class="pwc-batch-controls">
                <button class="button button-primary pwc-start-batch">شروع تبدیل هوشمند</button>
                <button class="button pwc-pause-batch" disabled>توقف موقت</button>
                <button class="button button-secondary pwc-revert-batch">بازگشت همه به اصلی</button>
                <button class="button pwc-cancel-batch">لغو فرآیند</button>
            </div>
            
            <div class="pwc-batch-log">
                <h3>📝 لاگ عملیات</h3>
                <div id="pwc-log-content"></div>
            </div>
        </div>
        <?php
    }
    
    public function render_stats() {
        ?>
        <div class="wrap pwc-stats">
            <h1 class="pwc-title">📊 آمار و گزارشات کامل</h1>
            
            <div class="pwc-stats-tabs">
                <button class="pwc-tab-button active" data-tab="overview">نمای کلی</button>
                <button class="pwc-tab-button" data-tab="daily">آمار روزانه</button>
                <button class="pwc-tab-button" data-tab="formats">فرمت‌ها</button>
                <button class="pwc-tab-button" data-tab="savings">صرفه‌جویی</button>
            </div>
            
            <div class="pwc-tab-content active" id="overview">
                <?php $this->render_stats_overview(); ?>
            </div>
            
            <div class="pwc-tab-content" id="daily">
                <?php $this->render_daily_stats(); ?>
            </div>
            
            <div class="pwc-tab-content" id="formats">
                <?php $this->render_format_stats(); ?>
            </div>
            
            <div class="pwc-tab-content" id="savings">
                <?php $this->render_savings_stats(); ?>
            </div>
            
            <div class="pwc-export-section">
                <button class="button button-secondary pwc-export-stats">📥 خروجی Excel</button>
                <button class="button button-secondary pwc-export-charts">📊 خروجی نمودارها</button>
            </div>
        </div>
        <?php
    }
    
    public function render_tools() {
        ?>
        <div class="wrap pwc-tools">
            <h1 class="pwc-title">🛠️ ابزارهای پیشرفته</h1>
            
            <div class="pwc-tools-grid">
                <div class="pwc-tool-card">
                    <h3>🔍 بررسی سلامت سیستم</h3>
                    <p>بررسی وضعیت سرور، پیکربندی و وابستگی‌ها</p>
                    <button class="button pwc-run-health-check">اجرای بررسی سلامت</button>
                    <div id="pwc-health-result"></div>
                </div>
                
                <div class="pwc-tool-card">
                    <h3>🧹 بهینه‌ساز دیتابیس</h3>
                    <p>پاکسازی و بهینه‌سازی جداول دیتابیس</p>
                    <button class="button pwc-optimize-db">بهینه‌سازی دیتابیس</button>
                </div>
                
                <div class="pwc-tool-card">
                    <h3>📋 لاگ و خطاها</h3>
                    <p>مشاهده لاگ‌های سیستم و خطاهای تبدیل</p>
                    <button class="button pwc-view-logs">مشاهده لاگ‌ها</button>
                </div>
                
                <div class="pwc-tool-card danger-zone">
                    <h3>⚠️ منطقه خطر</h3>
                    <p>عملیات غیرقابل بازگشت</p>
                    <button class="button button-danger pwc-reset-all">بازنشانی کامل پلاگین</button>
                    <button class="button button-danger pwc-delete-all">حذف تمام داده‌ها</button>
                </div>
            </div>
        </div>
        <?php
    }
    
    // متدهای callback برای فیلدهای تنظیمات
    public function checkbox_field_callback($args) {
        $settings = get_option('pwc_settings', []);
        $checked = isset($settings[$args['name']]) ? 'checked' : '';
        ?>
        <label>
            <input type="checkbox" name="pwc_settings[<?php echo $args['name']; ?>]" value="1" <?php echo $checked; ?>>
            <?php echo $args['label']; ?>
        </label>
        <?php
    }
    
    public function select_field_callback($args) {
        $settings = get_option('pwc_settings', []);
        $current = $settings[$args['name']] ?? '';
        ?>
        <select name="pwc_settings[<?php echo $args['name']; ?>]">
            <?php foreach ($args['options'] as $value => $label): ?>
                <option value="<?php echo $value; ?>" <?php selected($current, $value); ?>>
                    <?php echo $label; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }
    
    public function range_field_callback($args) {
        $settings = get_option('pwc_settings', []);
        $current = $settings[$args['name']] ?? $args['min'];
        ?>
        <div class="pwc-range-field">
            <input type="range" name="pwc_settings[<?php echo $args['name']; ?>]" 
                   min="<?php echo $args['min']; ?>" max="<?php echo $args['max']; ?>" 
                   step="<?php echo $args['step']; ?>" value="<?php echo $current; ?>"
                   class="pwc-range">
            <span class="pwc-range-value"><?php echo $current . $args['unit']; ?></span>
        </div>
        <?php
    }
    
    public function sanitize_settings($input) {
        $sanitized = [];
        
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'quality':
                    $sanitized[$key] = intval($value);
                    break;
                case 'cache_max_size':
                    $sanitized[$key] = min(5000, max(50, intval($value)));
                    break;
                case 'cdn_url':
                    $sanitized[$key] = esc_url_raw($value);
                    break;
                default:
                    $sanitized[$key] = sanitize_text_field($value);
            }
        }
        
        return $sanitized;
    }
    
    // AJAX handlers
    public function ajax_start_batch() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $batch = new PWC_Batch();
        $result = $batch->start_batch_convert();
        
        wp_send_json_success($result);
    }
    
    public function ajax_get_progress() {
        check_ajax_referer('pwc_nonce', 'nonce');
        
        $batch = new PWC_Batch();
        $progress = $batch->get_progress();
        
        wp_send_json_success($progress);
    }
    
    // سایر متدهای رندر...
    private function render_recent_activity($activities) {
        if (empty($activities)) {
            echo '<p>هیچ فعالیتی ثبت نشده است.</p>';
            return;
        }
        
        foreach ($activities as $activity) {
            echo '<div class="pwc-activity-item">';
            echo '<span class="pwc-activity-time">' . date('H:i', strtotime($activity['converted_time'])) . '</span>';
            echo '<span class="pwc-activity-desc">' . ($activity['post_title'] ?: 'تصویر بدون عنوان') . '</span>';
            echo '</div>';
        }
    }
}