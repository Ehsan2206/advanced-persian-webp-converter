<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Converter {
    private $supported_formats = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp'];
    private $output_formats = ['webp', 'avif'];
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('pwc_settings', []);
    }
    
    public function handle_upload($metadata, $attachment_id) {
        if (!$this->should_convert()) {
            return $metadata;
        }
        
        $file_path = get_attached_file($attachment_id);
        
        if (!$this->is_supported_format($file_path)) {
            return $metadata;
        }
        
        // ذخیره مسیر اصلی
        if (!get_post_meta($attachment_id, '_pwc_original', true)) {
            update_post_meta($attachment_id, '_pwc_original', $file_path);
        }
        
        $results = [];
        $output_format = $this->get_output_format();
        
        // تبدیل به فرمت انتخابی
        $result = $this->convert_image($file_path, $output_format, $attachment_id);
        
        if ($result['success']) {
            $results[$output_format] = $result;
            
            // ایجاد نسخه‌های مختلف سایز
            $this->generate_sizes($attachment_id, $metadata, $output_format);
            
            // به‌روزرسانی metadata
            $this->update_attachment_metadata($attachment_id, $result['converted_path'], $output_format);
        }
        
        // ذخیره نتایج
        update_post_meta($attachment_id, '_pwc_conversion_results', $results);
        
        return $metadata;
    }
    
    public function replace_content_images($content) {
        if (!$this->should_replace_images() || empty($content)) {
            return $content;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $new_src = $this->get_converted_url($src);
            
            if ($new_src && $new_src !== $src) {
                $img->setAttribute('src', $new_src);
                
                // اضافه کردن srcset اگر وجود دارد
                $srcset = $img->getAttribute('srcset');
                if ($srcset) {
                    $new_srcset = $this->replace_srcset($srcset);
                    $img->setAttribute('srcset', $new_srcset);
                }
                
                // اضافه کردن attributes برای lazyload
                if ($this->should_lazyload()) {
                    $this->add_lazyload_attributes($img);
                }
            }
        }
        
        return $dom->saveHTML();
    }
    
    public function replace_single_image($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (!$this->should_replace_images() || empty($html)) {
            return $html;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $new_src = $this->get_converted_url($src);
            
            if ($new_src && $new_src !== $src) {
                $img->setAttribute('src', $new_src);
                
                $srcset = $img->getAttribute('srcset');
                if ($srcset) {
                    $new_srcset = $this->replace_srcset($srcset);
                    $img->setAttribute('srcset', $new_srcset);
                }
            }
        }
        
        return $dom->saveHTML();
    }
    
    private function convert_image($file_path, $output_format, $attachment_id = null) {
        $quality = $this->get_quality_setting();
        $method = $this->get_conversion_method();
        
        $result = [
            'success' => false,
            'original_size' => filesize($file_path),
            'converted_size' => 0,
            'savings' => 0,
            'quality' => $quality,
            'method' => $method
        ];
        
        try {
            $converted_path = $this->generate_output_path($file_path, $output_format);
            
            if ($method === 'imagick' && class_exists('Imagick')) {
                $success = $this->convert_with_imagick($file_path, $converted_path, $output_format, $quality);
            } else {
                $success = $this->convert_with_gd($file_path, $converted_path, $output_format, $quality);
            }
            
            if ($success && file_exists($converted_path)) {
                $result['success'] = true;
                $result['converted_path'] = $converted_path;
                $result['converted_size'] = filesize($converted_path);
                $result['savings'] = $result['original_size'] - $result['converted_size'];
                $result['reduction'] = round(($result['savings'] / $result['original_size']) * 100, 2);
            }
        } catch (Exception $e) {
            error_log('PWC Conversion Error: ' . $e->getMessage());
        }
        
        return $result;
    }
    
    private function convert_with_imagick($input_path, $output_path, $format, $quality) {
        try {
            $image = new Imagick($input_path);
            
            // تنظیمات پیشرفته Imagick
            $image->setImageCompressionQuality($quality);
            
            if ($this->should_strip_metadata()) {
                $image->stripImage();
            }
            
            if ($format === 'webp') {
                $image->setImageFormat('WEBP');
                $image->setOption('webp:method', '6');
                $image->setOption('webp:alpha-quality', '100');
            } elseif ($format === 'avif') {
                $image->setImageFormat('AVIF');
                $image->setOption('heic:quality', $quality);
            }
            
            $result = $image->writeImage($output_path);
            $image->destroy();
            
            return $result;
        } catch (Exception $e) {
            error_log('PWC Imagick Error: ' . $e->getMessage());
            return false;
        }
    }
    
    private function convert_with_gd($input_path, $output_path, $format, $quality) {
        $image = null;
        $type = exif_imagetype($input_path);
        
        switch ($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($input_path);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($input_path);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($input_path);
                break;
            default:
                return false;
        }
        
        if (!$image) {
            return false;
        }
        
        $success = false;
        
        if ($format === 'webp' && function_exists('imagewebp')) {
            $success = imagewebp($image, $output_path, $quality);
        }
        
        imagedestroy($image);
        return $success;
    }
    
    private function get_converted_url($original_url) {
        // منطق پیدا کردن URL تبدیل شده
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $original_url);
        
        if (file_exists($file_path)) {
            $output_format = $this->get_output_format();
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
    
    private function should_convert() {
        return !empty($this->settings['enable_conversion']);
    }
    
    private function should_replace_images() {
        return !empty($this->settings['enable_replacement']);
    }
    
    private function should_lazyload() {
        return !empty($this->settings['enable_lazyload']);
    }
    
    private function should_strip_metadata() {
        return !empty($this->settings['strip_metadata']);
    }
    
    private function get_output_format() {
        return $this->settings['output_format'] ?? 'webp';
    }
    
    private function get_quality_setting() {
        return $this->settings['quality'] ?? 80;
    }
    
    private function get_conversion_method() {
        return class_exists('Imagick') ? 'imagick' : 'gd';
    }
    
    private function is_supported_format($file_path) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        return in_array($ext, $this->supported_formats);
    }
    
    private function generate_sizes($attachment_id, $metadata, $output_format) {
        // تولید سایزهای مختلف برای تصویر تبدیل شده
        if (isset($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_data) {
                $size_path = str_replace(wp_basename($metadata['file']), $size_data['file'], $metadata['file']);
                $full_size_path = get_attached_file($attachment_id);
                $size_file_path = str_replace(wp_basename($full_size_path), $size_data['file'], $full_size_path);
                
                if (file_exists($size_file_path)) {
                    $this->convert_image($size_file_path, $output_format);
                }
            }
        }
    }
    
    private function update_attachment_metadata($attachment_id, $converted_path, $output_format) {
        // به‌روزرسانی metadataهای وردپرس
        $upload_dir = wp_upload_dir();
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $converted_path);
        
        update_attached_file($attachment_id, $relative_path);
        
        // تولید metadata جدید
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $converted_path);
        wp_update_attachment_metadata($attachment_id, $new_metadata);
    }
    
    private function replace_srcset($srcset) {
        $sources = explode(', ', $srcset);
        $new_sources = [];
        
        foreach ($sources as $source) {
            $parts = explode(' ', $source);
            $url = $parts[0];
            $descriptor = isset($parts[1]) ? $parts[1] : '';
            
            $new_url = $this->get_converted_url($url);
            if ($new_url) {
                $new_sources[] = $new_url . ($descriptor ? ' ' . $descriptor : '');
            } else {
                $new_sources[] = $source;
            }
        }
        
        return implode(', ', $new_sources);
    }
    
    private function add_lazyload_attributes($img) {
        $src = $img->getAttribute('src');
        $img->setAttribute('data-src', $src);
        $img->setAttribute('src', 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==');
        $img->setAttribute('class', $img->getAttribute('class') . ' pwc-lazyload');
        
        $srcset = $img->getAttribute('srcset');
        if ($srcset) {
            $img->setAttribute('data-srcset', $srcset);
            $img->removeAttribute('srcset');
        }
    }
}