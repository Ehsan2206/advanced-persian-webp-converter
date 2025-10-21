jQuery(document).ready(function($) {
    'use strict';
    
    // مدیریت تب‌ها
    $('.pwc-tab-button').on('click', function(e) {
        e.preventDefault();
        const tabId = $(this).data('tab');
        
        $('.pwc-tab-button').removeClass('active');
        $('.pwc-tab-content').removeClass('active');
        
        $(this).addClass('active');
        $('#' + tabId).addClass('active');
        
        // ذخیره تب فعال
        localStorage.setItem('pwc_active_tab', tabId);
    });
    
    // بازیابی تب فعال
    const activeTab = localStorage.getItem('pwc_active_tab');
    if (activeTab) {
        $(`.pwc-tab-button[data-tab="${activeTab}"]`).click();
    }
    
    // مدیریت تبدیل دسته‌ای
    let isBatchProcessing = false;
    let progressInterval = null;
    
    // لود آمار اولیه
    loadInitialStats();
    
    $('#pwc-start-batch').on('click', function() {
        if (!isBatchProcessing) {
            startBatchConversion();
        }
    });
    
    $('#pwc-pause-batch').on('click', function() {
        pauseBatchConversion();
    });
    
    $('#pwc-reset-batch').on('click', function() {
        resetBatchConversion();
    });
    
    $('#pwc-revert-batch').on('click', function() {
        if (confirm(pwcAdmin.strings.confirm_action + ' تمام تصاویر تبدیل شده به حالت اصلی بازگردانده می‌شوند.')) {
            revertBatchConversion();
        }
    });
    
    function loadInitialStats() {
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_get_stats',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateStatsDisplay(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('خطا در دریافت آمار:', error);
            }
        });
    }
    
    function startBatchConversion() {
        isBatchProcessing = true;
        updateBatchUI('start');
        
        addLog('🚀 شروع فرآیند تبدیل دسته‌ای...', 'info');
        
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_start_batch',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    addLog('✅ ' + response.data.message, 'success');
                    startProgressPolling();
                } else {
                    addLog('❌ ' + response.data.message, 'error');
                    updateBatchUI('error');
                }
            },
            error: function(xhr, status, error) {
                addLog('❌ خطا در ارتباط با سرور: ' + error, 'error');
                updateBatchUI('error');
            }
        });
    }
    
    function startProgressPolling() {
        progressInterval = setInterval(function() {
            if (!isBatchProcessing) return;
            
            $.ajax({
                url: pwcAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'pwc_get_progress',
                    nonce: pwcAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateProgress(response.data);
                        
                        if (response.data.completed) {
                            completeBatchConversion();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('خطا در دریافت پیشرفت:', error);
                }
            });
        }, 2000);
    }
    
    function updateProgress(data) {
        // به‌روزرسانی نوار پیشرفت
        const percent = data.percent || 0;
        $('.pwc-progress-fill').css('width', percent + '%');
        $('.pwc-progress-percent').text(percent + '%');
        
        // به‌روزرسانی متن وضعیت
        if (data.status) {
            $('.pwc-progress-text').text(data.status);
        }
        
        // به‌روزرسانی آمار
        if (data.converted !== undefined) {
            $('#pwc-converted-count').text(data.converted);
        }
        
        // اضافه کردن لاگ
        if (data.message && !data.completed) {
            addLog('📸 ' + data.message, 'info');
        }
    }
    
    function pauseBatchConversion() {
        isBatchProcessing = false;
        clearInterval(progressInterval);
        updateBatchUI('pause');
        addLog('⏸️ فرآیند متوقف شد', 'warning');
    }
    
    function resetBatchConversion() {
        isBatchProcessing = false;
        clearInterval(progressInterval);
        updateBatchUI('reset');
        $('.pwc-batch-log').empty();
        addLog('🔄 فرآیند بازنشانی شد', 'info');
    }
    
    function completeBatchConversion() {
        isBatchProcessing = false;
        clearInterval(progressInterval);
        updateBatchUI('complete');
        addLog('🎉 تبدیل دسته‌ای با موفقیت کامل شد!', 'success');
        loadInitialStats(); // به‌روزرسانی آمار
    }
    
    function revertBatchConversion() {
        addLog('↩️ شروع بازگشت تصاویر به حالت اصلی...', 'info');
        
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_revert_batch',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    addLog('✅ ' + response.data.message, 'success');
                    if (response.data.errors && response.data.errors.length > 0) {
                        response.data.errors.forEach(error => {
                            addLog('⚠️ ' + error, 'warning');
                        });
                    }
                    setTimeout(() => location.reload(), 2000);
                } else {
                    addLog('❌ ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                addLog('❌ خطا در ارتباط با سرور: ' + error, 'error');
            }
        });
    }
    
    function updateBatchUI(state) {
        const $startBtn = $('#pwc-start-batch');
        const $pauseBtn = $('#pwc-pause-batch');
        const $resetBtn = $('#pwc-reset-batch');
        
        switch (state) {
            case 'start':
                $startBtn.prop('disabled', true).text(pwcAdmin.strings.processing);
                $pauseBtn.prop('disabled', false);
                $resetBtn.prop('disabled', false);
                break;
            case 'pause':
                $startBtn.prop('disabled', false).text('ادامه تبدیل');
                $pauseBtn.prop('disabled', true);
                break;
            case 'complete':
                $startBtn.prop('disabled', false).text('شروع مجدد');
                $pauseBtn.prop('disabled', true);
                $('.pwc-progress-text').text(pwcAdmin.strings.completed);
                break;
            case 'reset':
                $startBtn.prop('disabled', false).text('شروع تبدیل هوشمند');
                $pauseBtn.prop('disabled', true);
                $('.pwc-progress-fill').css('width', '0%');
                $('.pwc-progress-percent').text('0%');
                $('.pwc-progress-text').text('آماده');
                $('#pwc-converted-count').text('0');
                break;
            case 'error':
                $startBtn.prop('disabled', false).text('تلاش مجدد');
                $pauseBtn.prop('disabled', true);
                break;
        }
    }
    
    function addLog(message, type = 'info') {
        const logEntry = $('<div class="pwc-log-entry"></div>');
        logEntry.addClass('pwc-log-' + type);
        logEntry.text('[' + new Date().toLocaleTimeString() + '] ' + message);
        
        $('.pwc-batch-log').prepend(logEntry);
        
        // محدود کردن تعداد لاگ‌ها
        const $logs = $('.pwc-log-entry');
        if ($logs.length > 50) {
            $logs.last().remove();
        }
    }
    
    // پاکسازی کش
    $('#pwc-clear-cache').on('click', function() {
        if (confirm('آیا از پاکسازی کش اطمینان دارید؟')) {
            clearCache();
        }
    });
    
    function clearCache() {
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_clear_cache',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data.message, 'success');
                } else {
                    showMessage('❌ ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                showMessage('❌ خطا در ارتباط با سرور: ' + error, 'error');
            }
        });
    }
    
    // منطقه خطر
    $('#pwc-reset-all').on('click', function() {
        if (confirm('⚠️ آیا مطمئن هستید؟ تمام تنظیمات پلاگین بازنشانی خواهد شد!')) {
            resetAllSettings();
        }
    });
    
    $('#pwc-delete-all').on('click', function() {
        if (confirm('⚠️ آیا مطمئن هستید؟ تمام داده‌ها و تصاویر تبدیل شده حذف خواهند شد!')) {
            deleteAllData();
        }
    });
    
    function resetAllSettings() {
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_reset_settings',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data.message, 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showMessage('❌ ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                showMessage('❌ خطا در ارتباط با سرور: ' + error, 'error');
            }
        });
    }
    
    function deleteAllData() {
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_delete_all_data',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data.message, 'success');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showMessage('❌ ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                showMessage('❌ خطا در ارتباط با سرور: ' + error, 'error');
            }
        });
    }
    
    // نمایش پیام
    function showMessage(message, type = 'info') {
        const messageDiv = $('<div class="notice notice-' + type + ' is-dismissible"></div>');
        messageDiv.html('<p>' + message + '</p>');
        
        $('.wrap').prepend(messageDiv);
        
        setTimeout(() => {
            messageDiv.fadeOut(() => messageDiv.remove());
        }, 5000);
    }
    
    // به‌روزرسانی مقدار range
    $('.pwc-range').on('input', function() {
        const value = $(this).val();
        const unit = $(this).data('unit') || '';
        $(this).next('.pwc-range-value').text(value + unit);
    });
    
    // تست سلامت سیستم
    $('.pwc-health-check').on('click', function() {
        const $button = $(this);
        const originalText = $button.text();
        
        $button.prop('disabled', true).text('در حال بررسی...');
        
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_health_check',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayHealthResults(response.data);
                } else {
                    showMessage('❌ خطا در بررسی سلامت سیستم', 'error');
                }
                $button.prop('disabled', false).text(originalText);
            },
            error: function(xhr, status, error) {
                showMessage('❌ خطا در ارتباط با سرور: ' + error, 'error');
                $button.prop('disabled', false).text(originalText);
            }
        });
    });
    
    function displayHealthResults(report) {
        // نمایش نتایج بررسی سلامت
        // این قسمت می‌تواند گسترش یابد
        showMessage('✅ بررسی سلامت سیستم کامل شد. وضعیت: ' + report.status, 'success');
    }
    
    // بهینه‌سازی دیتابیس
    $('.pwc-optimize-db').on('click', function() {
        const $button = $(this);
        const originalText = $button.text();
        
        $button.prop('disabled', true).text('در حال بهینه‌سازی...');
        
        $.ajax({
            url: pwcAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'pwc_optimize_db',
                nonce: pwcAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('✅ ' + response.data.message, 'success');
                } else {
                    showMessage('❌ ' + response.data.message, 'error');
                }
                $button.prop('disabled', false).text(originalText);
            },
            error: function(xhr, status, error) {
                showMessage('❌ خطا در ارتباط با سرور: ' + error, 'error');
                $button.prop('disabled', false).text(originalText);
            }
        });
    });
});