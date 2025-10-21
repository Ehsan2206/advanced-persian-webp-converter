<?php
if (!defined('ABSPATH')) {
    exit;
}

class PWC_Lazyload {
    private $enabled;
    private $settings;
    
    public function __construct() {
        $this->settings = get_option('pwc_settings', []);
        $this->enabled = !empty($this->settings['enable_lazyload']);
        
        if ($this->enabled) {
            $this->setup_hooks();
        }
    }
    
    private function setup_hooks() {
        // افزودن اسکریپت lazyload
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // پردازش تصاویر
        add_filter('the_content', [$this, 'process_content'], 20);
        add_filter('post_thumbnail_html', [$this, 'process_thumbnail'], 10, 5);
        add_filter('get_avatar', [$this, 'process_avatar'], 10, 6);
        add_filter('widget_text', [$this, 'process_content']);
        
        // افزودن noscript fallback
        add_filter('wp_get_attachment_image_attributes', [$this, 'add_lazyload_attributes'], 10, 3);
    }
    
    public function enqueue_scripts() {
        if ($this->should_lazyload()) {
            wp_enqueue_script(
                'pwc-lazyload',
                PWC_PLUGIN_URL . 'assets/js/lazyload.min.js',
                [],
                PWC_VERSION,
                true
            );
            
            wp_add_inline_script('pwc-lazyload', '
                document.addEventListener("DOMContentLoaded", function() {
                    var lazyImages = [].slice.call(document.querySelectorAll("img.pwc-lazy"));
                    
                    if ("IntersectionObserver" in window) {
                        var lazyImageObserver = new IntersectionObserver(function(entries, observer) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting) {
                                    var lazyImage = entry.target;
                                    lazyImage.src = lazyImage.dataset.src;
                                    lazyImage.srcset = lazyImage.dataset.srcset || "";
                                    lazyImage.classList.remove("pwc-lazy");
                                    lazyImageObserver.unobserve(lazyImage);
                                }
                            });
                        });
                        
                        lazyImages.forEach(function(lazyImage) {
                            lazyImageObserver.observe(lazyImage);
                        });
                    } else {
                        // Fallback برای مرورگرهای قدیمی
                        var lazyLoadThrottleTimeout;
                        function lazyLoad() {
                            if(lazyLoadThrottleTimeout) {
                                clearTimeout(lazyLoadThrottleTimeout);
                            }
                            
                            lazyLoadThrottleTimeout = setTimeout(function() {
                                var scrollTop = window.pageYOffset;
                                lazyImages.forEach(function(img) {
                                    if(img.offsetTop < (window.innerHeight + scrollTop)) {
                                        img.src = img.dataset.src;
                                        img.srcset = img.dataset.srcset || "";
                                        img.classList.remove("pwc-lazy");
                                    }
                                });
                                if(lazyImages.length == 0) { 
                                    document.removeEventListener("scroll", lazyLoad);
                                    window.removeEventListener("resize", lazyLoad);
                                    window.removeEventListener("orientationchange", lazyLoad);
                                }
                            }, 20);
                        }
                        
                        document.addEventListener("scroll", lazyLoad);
                        window.addEventListener("resize", lazyLoad);
                        window.addEventListener("orientationchange", lazyLoad);
                    }
                });
            ');
            
            // استایل‌های lazyload
            wp_add_inline_style('wp-block-library', '
                img.pwc-lazy {
                    opacity: 0;
                    transition: opacity 0.3s;
                }
                img.pwc-lazy.loaded {
                    opacity: 1;
                }
            ');
        }
    }
    
    public function process_content($content) {
        if (empty($content) || !$this->should_lazyload()) {
            return $content;
        }
        
        // جلوگیری از پردازش در حالت ویرایش
        if (is_admin() || is_feed() || is_preview()) {
            return $content;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $this->process_single_image($img);
        }
        
        return $dom->saveHTML();
    }
    
    public function process_thumbnail($html, $post_id, $post_thumbnail_id, $size, $attr) {
        if (empty($html) || !$this->should_lazyload()) {
            return $html;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $this->process_single_image($img);
        }
        
        return $dom->saveHTML();
    }
    
    public function process_avatar($avatar, $id_or_email, $size, $default, $alt, $args) {
        if (empty($avatar) || !$this->should_lazyload()) {
            return $avatar;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($avatar, 'HTML-ENTITIES', 'UTF-8'));
        
        $images = $dom->getElementsByTagName('img');
        
        foreach ($images as $img) {
            $this->process_single_image($img);
        }
        
        return $dom->saveHTML();
    }
    
    public function add_lazyload_attributes($attr, $attachment, $size) {
        if (!$this->should_lazyload()) {
            return $attr;
        }
        
        if (isset($attr['src'])) {
            $attr['data-src'] = $attr['src'];
            $attr['src'] = $this->get_placeholder();
        }
        
        if (isset($attr['srcset'])) {
            $attr['data-srcset'] = $attr['srcset'];
            unset($attr['srcset']);
        }
        
        $attr['class'] = isset($attr['class']) ? $attr['class'] . ' pwc-lazy' : 'pwc-lazy';
        
        return $attr;
    }
    
    private function process_single_image($img) {
        $src = $img->getAttribute('src');
        
        // رد کردن تصاویر کوچک و placeholderها
        if ($this->is_excluded($src) || $this->is_placeholder($src)) {
            return;
        }
        
        // افزودن attributes lazyload
        $img->setAttribute('data-src', $src);
        $img->setAttribute('src', $this->get_placeholder());
        
        $srcset = $img->getAttribute('srcset');
        if ($srcset) {
            $img->setAttribute('data-srcset', $srcset);
            $img->removeAttribute('srcset');
        }
        
        $sizes = $img->getAttribute('sizes');
        if ($sizes) {
            $img->setAttribute('data-sizes', 'auto');
            $img->removeAttribute('sizes');
        }
        
        // افزودن کلاس
        $class = $img->getAttribute('class');
        $img->setAttribute('class', $class . ' pwc-lazy');
        
        // افزودن noscript fallback
        $this->add_noscript_fallback($img, $src, $srcset);
    }
    
    private function add_noscript_fallback($img, $src, $srcset) {
        $noscript = $img->ownerDocument->createElement('noscript');
        $fallback_img = $img->ownerDocument->createElement('img');
        
        $fallback_img->setAttribute('src', $src);
        if ($srcset) {
            $fallback_img->setAttribute('srcset', $srcset);
        }
        
        // کپی کردن سایر attributes
        foreach ($img->attributes as $attr) {
            if (!in_array($attr->name, ['data-src', 'data-srcset', 'data-sizes'])) {
                $fallback_img->setAttribute($attr->name, $attr->value);
            }
        }
        
        $noscript->appendChild($fallback_img);
        $img->parentNode->insertBefore($noscript, $img->nextSibling);
    }
    
    private function should_lazyload() {
        if (is_admin() || is_feed() || is_preview() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return false;
        }
        
        return $this->enabled;
    }
    
    private function is_excluded($src) {
        $excluded_patterns = [
            '/wp-admin/',
            '/wp-includes/',
            '/\.svg$/i',
            '/\.gif$/i',
            '/pixel\.|analytics|tracking/i'
        ];
        
        foreach ($excluded_patterns as $pattern) {
            if (preg_match($pattern, $src)) {
                return true;
            }
        }
        
        // بررسی سایز تصویر
        if ($this->is_small_image($src)) {
            return true;
        }
        
        return false;
    }
    
    private function is_small_image($src) {
        // اگر بتوانیم ابعاد تصویر را بدست آوریم
        $size = getimagesize($src);
        if ($size && ($size[0] < 100 || $size[1] < 100)) {
            return true;
        }
        
        return false;
    }
    
    private function is_placeholder($src) {
        $placeholder_patterns = [
            '/data:image/',
            '/blank\.|placeholder|spacer/i',
            '/1x1\.|pixel\./i'
        ];
        
        foreach ($placeholder_patterns as $pattern) {
            if (preg_match($pattern, $src)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function get_placeholder() {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f0f0f0"/></svg>');
    }
}