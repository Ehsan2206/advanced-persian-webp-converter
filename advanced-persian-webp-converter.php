<?php
/**
 * Plugin Name: Advanced WebP Converter - Ultimate
 * Plugin URI: https://github.com/advanced-persian/webp-converter
 * Description: تبدیل هوشمند تصاویر به WebP/AVIF + بهینه‌سازی پیشرفته + CDN + Lazyload + کش هوشمند
 * Version: 4.0.0
 * Author: Advanced Persian Team
 * License: GPLv2
 * Text Domain: persian-webp
 * Requires at least: 5.8
 * Tested up to: 6.8
 */

if (!defined('ABSPATH')) {
    exit;
}

// تعریف ثابت‌ها
define('PWC_VERSION', '4.0.0');
define('PWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PWC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PWC_CACHE_DIR', WP_CONTENT_DIR . '/pwc-cache/');
define('PWC_CACHE_URL', WP_CONTENT_URL . '/pwc-cache/');

// بررسی وابستگی‌ها
add_action('admin_init', 'pwc_check_dependencies');
function pwc_check_dependencies() {
    $errors = [];
    
    if (!extension_loaded('gd') && !extension_loaded('imagick')) {
        $errors[] = 'نیاز به حداقل یکی از کتابخانه‌های GD یا Imagick دارید';
    }
    
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = 'نیاز به PHP نسخه 7.4 یا بالاتر دارید';
    }
    
    if (!empty($errors)) {
        add_action('admin_notices', function() use ($errors) {
            echo '<div class="error"><p><strong>WebP Converter:</strong> ' . implode(' | ', $errors) . '</p></div>';
        });
        return false;
    }
    
    return true;
}

// لود خودکار کلاس‌ها
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'PWC_') === 0) {
        $file = PWC_PLUGIN_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// راه‌اندازی پلاگین
add_action('plugins_loaded', 'pwc_init_plugin');
function pwc_init_plugin() {
    // راه‌اندازی core
    PWC_Core::get_instance();
    
    // راه‌اندازی ماژول‌ها
    if (is_admin()) {
        PWC_Admin::get_instance();
    }
    
    // راه‌اندازی frontend
    PWC_Frontend::get_instance();
}

// فعال‌سازی
register_activation_hook(__FILE__, 'pwc_activation');
function pwc_activation() {
    require_once PWC_PLUGIN_DIR . 'includes/class-pwc-install.php';
    PWC_Install::activate();
}

// غیرفعال‌سازی
register_deactivation_hook(__FILE__, 'pwc_deactivation');
function pwc_deactivation() {
    require_once PWC_PLUGIN_DIR . 'includes/class-pwc-install.php';
    PWC_Install::deactivate();
}

// حذف
register_uninstall_hook(__FILE__, 'pwc_uninstall');
function pwc_uninstall() {
    require_once PWC_PLUGIN_DIR . 'includes/class-pwc-install.php';
    PWC_Install::uninstall();
}