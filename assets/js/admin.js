/**
 * اسکریپت‌های ادمین
 */
jQuery(document).ready(function($) {
    var tabStorageKey = 'cloudtartSupportActiveTab';
    var urlParams = new URLSearchParams(window.location.search);
    var defaultTab = cloudtartSupport.defaultTab || 'expiry';
    var savedTab = window.localStorage ? localStorage.getItem(tabStorageKey) : '';
    var requestedTab = urlParams.get('tab');
    var activeTab = requestedTab || savedTab || defaultTab;

    function refreshUrlParams() {
        urlParams = new URLSearchParams(window.location.search);
    }

    function syncWpRefererField() {
        var currentPath = window.location.pathname;
        var currentSearch = window.location.search || '';
        $('input[name="_wp_http_referer"]').val(currentPath + currentSearch);
    }

    function syncUrlForTab(tabId) {
        if (!tabId) {
            return;
        }

        urlParams.set('tab', tabId);
        window.history.replaceState({}, '', window.location.pathname + '?' + urlParams.toString());
        syncWpRefererField();
    }

    function showTab(tabId, updateUrl) {
        if (!tabId || $('#' + tabId).length === 0) {
            tabId = defaultTab;
        }

        $('.tab-content').hide().removeClass('show');
        $('#' + tabId).show().addClass('show');
        $('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
        $('.nav-tab-wrapper .nav-tab').each(function() {
            var tabHref = new URL($(this).attr('href'), window.location.origin);
            if (tabHref.searchParams.get('tab') === tabId) {
                $(this).addClass('nav-tab-active');
            }
        });

        if (window.localStorage) {
            localStorage.setItem(tabStorageKey, tabId);
        }

        // Keep the unified-save hidden input in sync so the server can redirect
        // back to the tab the user was on when they pressed save.
        $('#cloudtart_active_tab').val(tabId);

        if (updateUrl) {
            syncUrlForTab(tabId);
        }
    }

    showTab(activeTab, !requestedTab && activeTab !== defaultTab);
    syncWpRefererField();

    $('.nav-tab-wrapper .nav-tab').on('click', function(event) {
        if (
            event.which !== 1 ||
            event.metaKey ||
            event.ctrlKey ||
            event.shiftKey ||
            event.altKey
        ) {
            return;
        }

        event.preventDefault();
        var targetUrl = new URL($(this).attr('href'), window.location.origin);
        var tabId = targetUrl.searchParams.get('tab');

        if (!tabId) {
            tabId = defaultTab;
        }

        showTab(tabId, true);
    });

    // Unified save form: when any tab's "Save All Settings" button is pressed
    // the same outer form is submitted. Record the currently visible tab so
    // the server-side handler can redirect the user back to it.
    $('#cloudtart-unified-form').on('submit', function() {
        var visibleTabId = $('.tab-content:visible').first().attr('id') || $('#cloudtart_active_tab').val() || defaultTab;
        $('#cloudtart_active_tab').val(visibleTabId);
        if (window.localStorage && visibleTabId) {
            localStorage.setItem(tabStorageKey, visibleTabId);
        }
    });

    $(window).on('popstate', function() {
        refreshUrlParams();
        var tabFromUrl = urlParams.get('tab');
        showTab(tabFromUrl || defaultTab, false);
        syncWpRefererField();
    });

    // Only toggles visibility. The fields stay enabled on purpose: disabled
    // controls are left out of the submission, which used to make a collapsed
    // section look like "the admin cleared this list" on save.
    function setInternalCdnSectionState(state) {
        var $content = $(state.contentSelector);
        var $hint = $(state.hintSelector);

        if ($content.length === 0 || $hint.length === 0) {
            return;
        }

        $content.toggle(!!state.enabled);
        $hint.toggle(!state.enabled);
    }

    function updateInternalCdnVisibility() {
        var redirectEnabled = $('input[name="cloudtart_internal_cdn_settings[enabled]"]').is(':checked');
        var blockExternalEnabled = $('input[name="cloudtart_internal_cdn_settings[block_external_requests]"]').is(':checked');
        var blockDomainsEnabled = $('input[name="cloudtart_internal_cdn_settings[block_domains]"]').is(':checked');
        // فهرست مجازها هم در «مسدودسازی فایل‌های خارجی صفحه» و هم در «حالت اینترانت» (درخواست‌های سرور) استفاده می‌شود.
        var allowListUsed = blockExternalEnabled || $('input[name="cloudtart_internal_cdn_settings[block_external_http]"]').is(':checked');

        setInternalCdnSectionState({
            enabled: redirectEnabled,
            contentSelector: '#cdn-replacements-wrapper',
            hintSelector: '#cdn-replacements-hint'
        });

        setInternalCdnSectionState({
            enabled: blockDomainsEnabled,
            contentSelector: '#cdn-blocked-domains-wrapper',
            hintSelector: '#cdn-blocked-domains-hint'
        });

        setInternalCdnSectionState({
            enabled: allowListUsed,
            contentSelector: '#cdn-allowed-domains-wrapper',
            hintSelector: '#cdn-allowed-domains-hint'
        });

        setInternalCdnSectionState({
            enabled: allowListUsed,
            contentSelector: '#cdn-allowed-keywords-wrapper',
            hintSelector: '#cdn-allowed-keywords-hint'
        });
    }

    $('body').on('change', 'input[name="cloudtart_internal_cdn_settings[enabled]"], input[name="cloudtart_internal_cdn_settings[block_external_requests]"], input[name="cloudtart_internal_cdn_settings[block_domains]"], input[name="cloudtart_internal_cdn_settings[block_external_http]"]', function() {
        updateInternalCdnVisibility();
    });

    updateInternalCdnVisibility();

    // گزینه‌های پخش آنلاین فایل‌های صوتی ووکامرس فقط وقتی قابلیت روشن است دیده می‌شوند.
    function updateWcAudioStreamVisibility() {
        var $toggle = $('#cloudtart-enable-wc-audio-stream');
        if ($toggle.length === 0) {
            return;
        }

        var enabled = $toggle.is(':checked');
        $('#wc-audio-stream-options').toggle(enabled);
        $('#wc-audio-stream-hint').toggle(!enabled);
    }

    $('body').on('change', '#cloudtart-enable-wc-audio-stream', updateWcAudioStreamVisibility);
    updateWcAudioStreamVisibility();

    $('body').on('change', '#cloudtart-wc-audio-theme', function() {
        var theme = $(this).val();
        $('.ct-theme-swatch').each(function() {
            $(this).toggleClass('is-selected', $(this).data('theme') === theme);
        });
    });

    $('body').on('click', '.ct-theme-swatch', function() {
        $('#cloudtart-wc-audio-theme').val($(this).data('theme')).trigger('change');
    });

    // تست اتصال
    $('#test-connection').on('click', function() {
        var $button = $(this);
        var $status = $('#connection-status');
        
        $button.prop('disabled', true).text(cloudtartSupport.i18n.testing);
        $status.html('<div class="notice notice-info inline"><p>' + cloudtartSupport.i18n.testingConnection + '</p></div>');
        
        $.ajax({
            url: cloudtartSupport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cloudtart_test_connection',
                nonce: cloudtartSupport.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text(cloudtartSupport.i18n.testConnection);
                
                if (response.success) {
                    var serverInfo = '';
                    if (response.data.server_info) {
                        serverInfo = '<br><strong>' + cloudtartSupport.i18n.serverVersionLabel + '</strong> ' + response.data.server_info.version;
                        serverInfo += '<br><strong>' + cloudtartSupport.i18n.serverStatusLabel + '</strong> ' + response.data.server_info.status;
                    }
                    
                    $status.html('<div class="notice notice-success inline"><p>' + response.data.message + serverInfo + '</p></div>');
                } else {
                    $status.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
        $button.prop('disabled', false).text(cloudtartSupport.i18n.testConnection);
        $status.html('<div class="notice notice-error inline"><p>' + cloudtartSupport.i18n.connectionError + '</p></div>');
            }
        });
    });
    
    // تازه‌سازی لاگ‌ها
    $('#refresh-logs').on('click', function() {
        var $button = $(this);
        
        $button.prop('disabled', true).text(cloudtartSupport.i18n.loading);
        
        $.ajax({
            url: cloudtartSupport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cloudtart_refresh_logs',
                nonce: cloudtartSupport.nonce
            },
            success: function(response) {
                $button.prop('disabled', false).text(cloudtartSupport.i18n.refreshLogs);
                
                if (response.success) {
                    $('#logs-content').val(response.data.logs);
                    $('#error-logs-content').val(response.data.error_logs);
                } else {
                    alert(cloudtartSupport.i18n.logsFetchError + response.data.message);
                }
            },
            error: function() {
        $button.prop('disabled', false).text(cloudtartSupport.i18n.refreshLogs);
        alert(cloudtartSupport.i18n.connectionError);
            }
        });
    });
    
    // مدیریت دامنه‌ها
    $('#add-domain').on('click', function() {
        var domainName = $('#domain-name').val();
        var domainExpiry = $('#domain-expiry').val();
        var $message = $('#domain-message');
        
        if (!domainName || !domainExpiry) {
            $message.html('<div class="notice notice-error inline"><p>' + cloudtartSupport.i18n.domainRequired + '</p></div>');
            return;
        }
        
        $.ajax({
            url: cloudtartSupport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cloudtart_add_domain',
                nonce: cloudtartSupport.nonce,
                domain_name: domainName,
                domain_expiry: domainExpiry
            },
            beforeSend: function() {
                $message.html('<div class="notice notice-info inline"><p>' + cloudtartSupport.i18n.addingDomain + '</p></div>');
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    $('#domain-name').val('');
                    $('#domain-expiry').val('');
                    // بازنشانی صفحه برای نمایش دامنه جدید
                    location.reload();
                } else {
                    $message.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $message.html('<div class="notice notice-error inline"><p>' + cloudtartSupport.i18n.connectionError + '</p></div>');
            }
        });
    });
    
    // حذف دامنه
    $('body').on('click', '.remove-domain', function() {
        var $row = $(this).closest('tr');
        var index = $(this).data('index');
        
        if (!confirm(cloudtartSupport.i18n.deleteDomainConfirm)) {
            return;
        }
        
        $.ajax({
            url: cloudtartSupport.ajaxUrl,
            type: 'POST',
            data: {
                action: 'cloudtart_remove_domain',
                nonce: cloudtartSupport.nonce,
                domain_index: index
            },
            beforeSend: function() {
                $row.addClass('deleting');
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(function() {
                        $(this).remove();
                        // اگر آخرین دامنه بود، پیام "هیچ دامنه‌ای ثبت نشده است" را نمایش بده
                        if ($('#domains-list tbody tr').length === 0) {
                            $('#domains-list tbody').html('<tr><td colspan="4">' + cloudtartSupport.i18n.noDomains + '</td></tr>');
                        }
                    });
                } else {
                    $row.removeClass('deleting');
                    alert(response.data.message);
                }
            },
            error: function() {
                $row.removeClass('deleting');
                alert(cloudtartSupport.i18n.connectionError);
            }
        });
    });

    function appendIndexedRow(config) {
        var newRow = $(config.templateSelector).clone().removeAttr('id');
        newRow.show();

        newRow.find('input, select, textarea').each(function(fieldIndex) {
            var $field = $(this);
            if (typeof config.nameBuilder === 'function') {
                $field.attr('name', config.nameBuilder($field, fieldIndex, config.index));
            }
        });

        $(config.targetSelector).append(newRow);
        config.onAdded && config.onAdded(newRow);
        config.index++;
        return config.index;
    }

    var replacementState = {
        index: $('#cdn-replacements-table tbody tr').length
    };

    $('#add-replacement-rule').on('click', function() {
        replacementState.index = appendIndexedRow({
            templateSelector: '#replacement-rule-template',
            targetSelector: '#cdn-replacements-table tbody',
            index: replacementState.index,
            nameBuilder: function($field, fieldIndex, rowIndex) {
                if ($field.is('select')) {
                    return 'cloudtart_internal_cdn_settings[replacements][' + rowIndex + '][replace]';
                }

                return 'cloudtart_internal_cdn_settings[replacements][' + rowIndex + '][original]';
            }
        });
    });

    $('#cdn-replacements-wrapper').on('click', '.remove-rule', function() {
        $(this).closest('tr').remove();
    });

    var blockedState = {
        index: $('#cdn-blocked-domains-table tbody tr').length
    };

    $('#add-blocked-domain').on('click', function() {
        blockedState.index = appendIndexedRow({
            templateSelector: '#blocked-domain-template',
            targetSelector: '#cdn-blocked-domains-table tbody',
            index: blockedState.index,
            nameBuilder: function($field, fieldIndex, rowIndex) {
                return 'cloudtart_internal_cdn_settings[blocked_domains][' + rowIndex + ']';
            }
        });
    });

    $('#cdn-blocked-domains-wrapper').on('click', '.remove-blocked-domain', function() {
        $(this).closest('tr').remove();
    });

    var allowedDomainState = {
        index: $('#cdn-allowed-domains-table tbody tr').length
    };

    $('#add-allowed-domain').on('click', function() {
        allowedDomainState.index = appendIndexedRow({
            templateSelector: '#allowed-domain-template',
            targetSelector: '#cdn-allowed-domains-table tbody',
            index: allowedDomainState.index,
            nameBuilder: function($field, fieldIndex, rowIndex) {
                return 'cloudtart_internal_cdn_settings[allowed_domains][' + rowIndex + ']';
            }
        });
    });

    $('#cdn-allowed-domains-wrapper').on('click', '.remove-allowed-domain', function() {
        $(this).closest('tr').remove();
    });

    var allowedKeywordState = {
        index: $('#cdn-allowed-keywords-table tbody tr').length
    };

    $('#add-allowed-keyword').on('click', function() {
        allowedKeywordState.index = appendIndexedRow({
            templateSelector: '#allowed-keyword-template',
            targetSelector: '#cdn-allowed-keywords-table tbody',
            index: allowedKeywordState.index,
            nameBuilder: function($field, fieldIndex, rowIndex) {
                return 'cloudtart_internal_cdn_settings[allowed_keywords][' + rowIndex + ']';
            }
        });
    });

    $('#cdn-allowed-keywords-wrapper').on('click', '.remove-allowed-keyword', function() {
        $(this).closest('tr').remove();
    });

    $('#cdn-upload-button').on('click', function() {
        var fileInput = $('#cdn-file-upload')[0];
        var $button = $(this);
        var $status = $('#cdn-upload-status');

        if (!fileInput || !fileInput.files.length) {
            $status.html('<div class="notice notice-error inline"><p>' + cloudtartSupport.i18n.uploadSelectFile + '</p></div>');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'cloudtart_cdn_upload_file');
        formData.append('nonce', cloudtartSupport.nonce);
        formData.append('file', fileInput.files[0]);

        $button.prop('disabled', true).text(cloudtartSupport.i18n.uploading);
        $status.html('<div class="notice notice-info inline"><p>' + cloudtartSupport.i18n.uploading + '</p></div>');

        $.ajax({
            url: cloudtartSupport.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    var optionValue = response.data.optionValue;
                    var optionLabel = response.data.optionLabel;

                    if ($('select option[value="' + optionValue.replace(/"/g, '\\"') + '"]').length === 0) {
                        $('select[name*="[replacements]"][name$="[replace]"]').each(function() {
                            $(this).append($('<option>', {
                                value: optionValue,
                                text: optionLabel
                            }));
                        });

                        $('#replacement-rule-template select').append($('<option>', {
                            value: optionValue,
                            text: optionLabel
                        }));
                    }

                    $status.html('<div class="notice notice-success inline"><p>' + (response.data.message || cloudtartSupport.i18n.uploadSuccess) + '</p></div>');
                    $('#cdn-file-upload').val('');
                } else {
                    $status.html('<div class="notice notice-error inline"><p>' + ((response.data && response.data.message) || cloudtartSupport.i18n.uploadFailed) + '</p></div>');
                }
            },
            error: function(xhr) {
                var message = cloudtartSupport.i18n.uploadFailed;

                if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    message = xhr.responseJSON.data.message;
                }

                $status.html('<div class="notice notice-error inline"><p>' + message + '</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false).text(cloudtartSupport.i18n.upload);
            }
        });
    });

    function initSortableTables() {
        document.querySelectorAll('.sortable-table th[data-sort]').forEach(function(header) {
            header.addEventListener('click', function() {
                var table = this.closest('table');
                var tbody = table.querySelector('tbody');
                var thIndex = Array.from(this.parentElement.children).indexOf(this);
                var sortDirection = this.classList.contains('sort-asc') ? 'desc' : 'asc';

                table.querySelectorAll('th').forEach(function(th) {
                    th.classList.remove('sort-asc', 'sort-desc');
                });

                this.classList.add('sort-' + sortDirection);

                Array.from(tbody.querySelectorAll('tr'))
                    .sort(function(a, b) {
                        var aValue = a.children[thIndex].textContent.trim();
                        var bValue = b.children[thIndex].textContent.trim();
                        var aNumber = parseFloat(aValue);
                        var bNumber = parseFloat(bValue);

                        if (!isNaN(aNumber) && !isNaN(bNumber)) {
                            aValue = aNumber;
                            bValue = bNumber;
                        }

                        if (aValue === bValue) {
                            return 0;
                        }

                        if (sortDirection === 'asc') {
                            return aValue > bValue ? 1 : -1;
                        }

                        return aValue < bValue ? 1 : -1;
                    })
                    .forEach(function(row) {
                        tbody.appendChild(row);
                    });
            });
        });
    }

    initSortableTables();
});
