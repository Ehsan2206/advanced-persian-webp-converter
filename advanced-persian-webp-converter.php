<?php
/**
 * Plugin Name: Advanced Persian WebP Converter
 * Plugin URI: https://github.com/Ehsan2206/advanced-persian-webp-converter
 * Description: Convert images to WebP format with Persian language support
 * Version: 1.5.0
 * Author: Ehsan
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: advanced-persian-webp-converter
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants for memory optimization
define('APWC_BATCH_SIZE', 3);
define('APWC_MAX_FILE_SIZE', 5242880); // 5MB
define('APWC_MEMORY_LIMIT', '256M');

class Advanced_Persian_WebP_Converter {
    
    private $batch_size;
    
    public function __construct() {
        $this->batch_size = APWC_BATCH_SIZE;
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_apwc_convert_batch', array($this, 'ajax_convert_batch'));
        add_action('add_attachment', array($this, 'convert_on_upload'));
        
        // Increase memory limit for this plugin only
        add_action('init', array($this, 'increase_memory_limit'));
    }
    
    public function increase_memory_limit() {
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'advanced-persian-webp-converter') {
            @ini_set('memory_limit', APWC_MEMORY_LIMIT);
            @ini_set('max_execution_time', 300);
        }
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Advanced Persian WebP Converter',
            'WebP Converter',
            'manage_options',
            'advanced-persian-webp-converter',
            array($this, 'admin_page')
        );
    }
    
    public function admin_init() {
        register_setting('apwc_settings', 'apwc_quality');
        register_setting('apwc_settings', 'apwc_auto_convert');
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Advanced Persian WebP Converter</h1>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Conversion Statistics</h2>
                <div id="apwc-stats">
                    <p>Loading statistics...</p>
                </div>
                <button id="apwc-refresh-stats" class="button button-secondary">Refresh Stats</button>
            </div>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Convert Images</h2>
                <p>Convert your existing images to WebP format in small batches to prevent memory issues.</p>
                
                <div id="apwc-progress" style="display: none;">
                    <div style="background: #f0f0f0; border-radius: 10px; height: 20px; margin: 10px 0; overflow: hidden;">
                        <div id="apwc-progress-bar" style="background: #0073aa; height: 100%; width: 0%; transition: width 0.3s ease; border-radius: 10px;"></div>
                    </div>
                    <p id="apwc-progress-text" style="font-size: 12px; color: #666;"></p>
                </div>
                
                <button id="apwc-start-conversion" class="button button-primary">Start Batch Conversion</button>
                <div id="apwc-results" style="margin-top: 15px;"></div>
            </div>
            
            <div style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
                <h2>Settings</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('apwc_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Image Quality</th>
                            <td>
                                <select name="apwc_quality">
                                    <?php for ($i = 60; $i <= 90; $i += 10): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($i, get_option('apwc_quality', 80)); ?>>
                                            <?php echo $i; ?>%
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto-convert new uploads</th>
                            <td>
                                <input type="checkbox" name="apwc_auto_convert" value="1" <?php checked(1, get_option('apwc_auto_convert', 1)); ?> />
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let isConverting = false;
            let currentBatch = 0;
            let totalConverted = 0;
            
            // Load initial stats
            loadStats();
            
            $('#apwc-refresh-stats').on('click', loadStats);
            
            $('#apwc-start-conversion').on('click', function() {
                if (isConverting) return;
                startConversion();
            });
            
            function loadStats() {
                $.post(ajaxurl, {
                    action: 'apwc_get_stats'
                }, function(response) {
                    if (response.success) {
                        $('#apwc-stats').html(
                            '<p>Total Images: ' + response.data.total + '</p>' +
                            '<p>Converted: ' + response.data.converted + '</p>' +
                            '<p>Remaining: ' + response.data.remaining + '</p>'
                        );
                    }
                });
            }
            
            function startConversion() {
                isConverting = true;
                currentBatch = 0;
                totalConverted = 0;
                
                $('#apwc-progress').show();
                $('#apwc-start-conversion').prop('disabled', true).text('Processing...');
                $('#apwc-results').html('');
                
                processBatch();
            }
            
            function processBatch() {
                $.post(ajaxurl, {
                    action: 'apwc_convert_batch',
                    batch: currentBatch,
                    converted_count: totalConverted
                }, function(response) {
                    if (response.success) {
                        currentBatch = response.data.batch;
                        totalConverted = response.data.converted_count;
                        
                        // Update progress
                        const progressPercent = Math.min(100, (currentBatch * 10));
                        $('#apwc-progress-bar').css('width', progressPercent + '%');
                        $('#apwc-progress-text').text(
                            'Batch ' + currentBatch + ' | Converted: ' + totalConverted + 
                            ' | Memory: ' + response.data.memory_usage
                        );
                        
                        if (response.data.has_more) {
                            setTimeout(processBatch, 1000);
                        } else {
                            finishConversion(response.data);
                        }
                    } else {
                        showError(response.data);
                    }
                }).fail(function(xhr, status, error) {
                    showError('AJAX Error: ' + error);
                });
            }
            
            function finishConversion(data) {
                isConverting = false;
                $('#apwc-start-conversion').prop('disabled', false).text('Start Batch Conversion');
                $('#apwc-results').html(
                    '<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px;">' +
                    '<p>Conversion complete! Converted ' + totalConverted + ' images.</p>' +
                    '<p>Final Memory Usage: ' + data.memory_usage + '</p>' +
                    '</div>'
                );
                loadStats();
            }
            
            function showError(message) {
                isConverting = false;
                $('#apwc-start-conversion').prop('disabled', false).text('Start Batch Conversion');
                $('#apwc-results').html(
                    '<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 10px; border-radius: 4px;">' +
                    '<p>Error: ' + message + '</p>' +
                    '</div>'
                );
            }
        });
        </script>
        <?php
    }
    
    public function ajax_convert_batch() {
        $this->check_nonce();
        
        $batch = isset($_POST['batch']) ? intval($_POST['batch']) : 0;
        $converted_count = isset($_POST['converted_count']) ? intval($_POST['converted_count']) : 0;
        
        // Free memory from previous operations
        $this->free_memory();
        
        $result = $this->process_batch($batch);
        
        wp_send_json_success(array(
            'batch' => $batch + 1,
            'converted_count' => $converted_count + $result['converted'],
            'has_more' => $result['has_more'],
            'memory_usage' => $this->format_bytes(memory_get_usage(true))
        ));
    }
    
    private function process_batch($batch) {
        $offset = $batch * $this->batch_size;
        $images = $this->get_images_batch($this->batch_size, $offset);
        $converted = 0;
        
        if (empty($images)) {
            return array('converted' => 0, 'has_more' => false);
        }
        
        foreach ($images as $image_id) {
            if ($this->convert_single_image($image_id)) {
                $converted++;
            }
            
            // Free memory after each image
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        return array(
            'converted' => $converted,
            'has_more' => count($images) === $this->batch_size
        );
    }
    
    private function get_images_batch($limit, $offset) {
        global $wpdb;
        
        $query = $wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type IN ('image/jpeg', 'image/png')
            ORDER BY ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset);
        
        return $wpdb->get_col($query);
    }
    
    private function convert_single_image($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        // Check file size
        $file_size = filesize($file_path);
        if ($file_size > APWC_MAX_FILE_SIZE) {
            error_log('APWC: Image too large - ' . $file_path);
            return false;
        }
        
        $mime_type = get_post_mime_type($attachment_id);
        $quality = get_option('apwc_quality', 80);
        $webp_path = $file_path . '.webp';
        
        if (file_exists($webp_path)) {
            return true;
        }
        
        try {
            switch ($mime_type) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($file_path);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($file_path);
                    if ($image) {
                        imagepalettetotruecolor($image);
                        imagealphablending($image, true);
                        imagesavealpha($image, true);
                    }
                    break;
                default:
                    return false;
            }
            
            if (!$image) {
                return false;
            }
            
            $result = imagewebp($image, $webp_path, $quality);
            imagedestroy($image);
            
            return $result && file_exists($webp_path);
            
        } catch (Exception $e) {
            error_log('APWC Conversion Error: ' . $e->getMessage());
            return false;
        }
    }
    
    public function convert_on_upload($attachment_id) {
        if (!get_option('apwc_auto_convert', 1)) {
            return;
        }
        
        $this->convert_single_image($attachment_id);
    }
    
    private function free_memory() {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    private function check_nonce() {
        if (!check_ajax_referer('apwc_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public static function get_stats() {
        global $wpdb;
        
        $total_images = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND post_mime_type IN ('image/jpeg', 'image/png')
        ");
        
        // For now, return basic stats
        return array(
            'total' => $total_images,
            'converted' => 0,
            'remaining' => $total_images
        );
    }
}

// Initialize the plugin
new Advanced_Persian_WebP_Converter();

// AJAX handler for stats
add_action('wp_ajax_apwc_get_stats', function() {
    $stats = Advanced_Persian_WebP_Converter::get_stats();
    wp_send_json_success($stats);
});

// Add nonce creation
add_action('admin_head', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'advanced-persian-webp-converter') {
        wp_nonce_field('apwc_nonce', 'apwc_nonce');
    }
});
?>