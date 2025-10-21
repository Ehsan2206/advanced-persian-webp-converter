<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Optimizer {
    private $compression_levels = [
        'lossless' => ['quality' => 100, 'method' => 1],
        'high' => ['quality' => 85, 'method' => 3],
        'medium' => ['quality' => 75, 'method' => 4],
        'low' => ['quality' => 60, 'method' => 6]
    ];
    
    public function analyze_image($image_path) {
        $analysis = [
            'type' => 'unknown',
            'complexity' => 0.5,
            'has_alpha' => false,
            'recommended_quality' => 80,
            'recommended_method' => 'balanced'
        ];
        
        if (!file_exists($image_path)) {
            return $analysis;
        }
        
        // تشخیص نوع تصویر
        $analysis['type'] = $this->detect_image_type($image_path);
        
        // تحلیل پیچیدگی
        $analysis['complexity'] = $this->analyze_complexity($image_path, $analysis['type']);
        
        // تشخیص آلفا
        $analysis['has_alpha'] = $this->has_alpha_channel($image_path);
        
        // پیشنهاد کیفیت
        $analysis['recommended_quality'] = $this->suggest_quality($analysis);
        $analysis['recommended_method'] = $this->suggest_method($analysis);
        
        return $analysis;
    }
    
    public function smart_compress($image_path, $original_quality) {
        $analysis = $this->analyze_image($image_path);
        
        $settings = [
            'quality' => $analysis['recommended_quality'],
            'method' => $this->compression_levels[$analysis['recommended_method']]['method'],
            'strip_metadata' => true,
            'optimize' => true
        ];
        
        // تنظیمات ویژه بر اساس نوع تصویر
        switch ($analysis['type']) {
            case 'photo':
                $settings['quality'] = max(70, $settings['quality']);
                break;
            case 'graphic':
                $settings['quality'] = min(90, $settings['quality'] + 10);
                break;
            case 'screenshot':
                $settings['method'] = 4; // حفظ sharpness متن
                break;
        }
        
        return $settings;
    }
    
    private function detect_image_type($image_path) {
        $filename = strtolower(basename($image_path));
        $size = getimagesize($image_path);
        
        // تشخیص بر اساس نام فایل
        if (strpos($filename, 'logo') !== false || strpos($filename, 'icon') !== false) {
            return 'graphic';
        }
        
        if (strpos($filename, 'screenshot') !== false || strpos($filename, 'screen') !== false) {
            return 'screenshot';
        }
        
        // تشخیص بر اساس ابعاد
        if ($size) {
            $ratio = $size[0] / $size[1];
            
            // تصاویر مربعی معمولاً لوگو/آیکون هستند
            if ($ratio >= 0.9 && $ratio <= 1.1) {
                return 'graphic';
            }
            
            // تصاویر بسیار عریض معمولاً screenshot هستند
            if ($ratio > 2) {
                return 'screenshot';
            }
        }
        
        // تشخیص بر اساس سایز فایل
        $file_size = filesize($image_path) / 1024; // KB
        
        if ($file_size < 100) {
            return 'graphic';
        }
        
        return 'photo';
    }
    
    private function analyze_complexity($image_path, $type) {
        $complexity = 0.5;
        $file_size = filesize($image_path) / (1024 * 1024); // MB
        
        switch ($type) {
            case 'graphic':
                $complexity = max(0.1, min(0.4, $file_size * 3));
                break;
            case 'screenshot':
                $complexity = max(0.6, min(0.9, $file_size * 1.2));
                break;
            case 'photo':
                $complexity = max(0.5, min(0.95, $file_size));
                break;
        }
        
        return $complexity;
    }
    
    private function has_alpha_channel($image_path) {
        if (class_exists('Imagick')) {
            try {
                $image = new Imagick($image_path);
                $has_alpha = $image->getImageAlphaChannel();
                $image->destroy();
                return $has_alpha;
            } catch (Exception $e) {
                return false;
            }
        }
        
        $type = exif_imagetype($image_path);
        return $type === IMAGETYPE_PNG;
    }
    
    private function suggest_quality($analysis) {
        $base_quality = 80;
        
        if ($analysis['type'] === 'graphic') {
            $base_quality = 90;
        } elseif ($analysis['type'] === 'screenshot') {
            $base_quality = 85;
        }
        
        // تنظیم بر اساس پیچیدگی
        if ($analysis['complexity'] > 0.8) {
            $base_quality += 10;
        } elseif ($analysis['complexity'] < 0.3) {
            $base_quality -= 15;
        }
        
        // تنظیم برای تصاویر با آلفا
        if ($analysis['has_alpha']) {
            $base_quality += 5;
        }
        
        return min(100, max(40, $base_quality));
    }
    
    private function suggest_method($analysis) {
        if ($analysis['type'] === 'graphic') {
            return 'high';
        } elseif ($analysis['type'] === 'screenshot') {
            return 'medium';
        }
        
        return $analysis['complexity'] > 0.7 ? 'high' : 'medium';
    }
}