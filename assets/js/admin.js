/**
 * Site Bloat & Storage Auditor - Admin Dashboard JavaScript
 *
 * Handles tab navigation, asynchronous AJAX audits, modal interactions,
 * thumbnail deletions, and quarantine lifecycle.
 *
 * @package Site_Bloat_Auditor
 */
(function($) {
    'use strict';

    // Global localization and environment setup
    var sbaData = window.sbaData || {};
    var sbaI18n = sbaData.i18n || {};
    var sbaNonce = sbaData.nonce || '';
    var sbaAjaxUrl = sbaData.ajax_url || window.ajaxurl || '';
    var adminPluginsUrl = sbaData.admin_plugins_url || 'plugins.php';

    /**
     * Localized string lookup helper with fallback.
     *
     * @param {string} key         Translation key.
     * @param {string} defaultText Fallback English string.
     * @return {string} Translated string or fallback.
     */
    function sba__(key, defaultText) {
        if (sbaI18n[key] !== undefined) {
            return sbaI18n[key];
        }
        return defaultText !== undefined ? defaultText : key;
    }

    $(function() {
        var allTables = [];
        var allPlugins = [];
        var allMedia = [];
        var unusedMedia = [];
        var oversizedMedia = [];
        var optimizedMedia = [];
        var duplicatesData = [];
        var accessibilityData = null;
        var discrepanciesData = null;
        var quarantinedMedia = (sbaData.quarantined_files && Array.isArray(sbaData.quarantined_files)) ? sbaData.quarantined_files : [];
        var selectedQuarantineIds = [];
        var currentFilter = 'all';
        var currentPluginFilter = 'all';
        var currentMediaFilter = 'all';
        var totalMediaSizeMb = 0;
        var reclaimableSpaceMb = 0;
        var potentialSavingsMb = 0;
        var mediaScanDone = false;
        var pendingDropTable = null;

        // Security Helper: Escape HTML strings before DOM insertion (prevents double-escaping)
        function sbaEscapeHtml(str) {
            if (str === null || str === undefined) {
                return '';
            }
            // Decode pre-encoded HTML entities first to prevent &amp;amp; artifacts
            var decoded = String(str)
                .replace(/&amp;/g, '&')
                .replace(/&#038;/g, '&')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&quot;/g, '"')
                .replace(/&#039;/g, "'")
                .replace(/&#8217;/g, "'");

            return decoded
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // Tab-Isolated Toast Notice Helper
        function showNotice(message, isError, targetTab) {
            var noticeClass = isError ? 'notice-error' : 'notice-success';
            var dismissText = sba__( 'Dismiss this notice.', 'Dismiss this notice.' );
            var dismissBtn = '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' + dismissText + '</span></button>';
            var html = '<div class="notice ' + noticeClass + ' is-dismissible" style="padding: 10px 14px; position: relative;"><p>' + message + '</p>' + dismissBtn + '</div>';

            var $container = null;
            if (targetTab) {
                $container = $('#sba-notice-' + targetTab);
            }
            if (!$container || !$container.length) {
                var $activePane = $('.sba-tab-pane.active');
                var $paneNotice = $activePane.find('.sba-tab-notice-container');
                $container = ($paneNotice.length) ? $paneNotice : $('#sba-notice-container');
            }

            // Hide other notices to ensure clean, isolated UI feedback per tab
            $('#sba-notice-container, .sba-tab-notice-container').not($container).empty().hide();

            $container.html(html).fadeIn(150);
            $('html, body').animate({ scrollTop: $container.offset().top - 40 }, 200);
        }

        // Dismissible notice click handler (supports native .notice-dismiss)
        $(document).on('click', '.sba-dashboard-wrap .notice-dismiss', function(e) {
            e.preventDefault();
            $(this).closest('.notice').fadeOut(150, function() {
                $(this).remove();
            });
        });

        // Number formatter
        function formatNumber(num) {
            return (num || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Format KB or MB
        function formatSize(kb) {
            if (kb >= 1024) {
                return (kb / 1024).toFixed(2) + ' MB';
            }
            return Number(kb || 0).toFixed(2) + ' KB';
        }

        // =====================================================================
        // MAIN 3-TAB NAVIGATION
        // =====================================================================
        $('.sba-main-tab-btn').on('click', function(e) {
            e.preventDefault();
            var targetTab = $(this).data('main-tab');

            $('.sba-main-tab-btn').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');

            // Clear notice banners when switching tabs so notices do not bleed across tabs
            $('#sba-notice-container, .sba-tab-notice-container').empty().hide();

            $('.sba-tab-pane').removeClass('active').hide();
            $('#sba-tab-pane-' + targetTab).addClass('active').fadeIn(150);
        });

        // =====================================================================
        // TAB 1: DATABASE AUDIT LOGIC
        // =====================================================================
        function renderTables() {
            var searchQuery = $('#sba-table-search').val().toLowerCase().trim();
            var $tbody = $('#sba-tables-body');
            $tbody.empty();

            var filtered = allTables.filter(function(t) {
                if (currentFilter !== 'all') {
                    if (currentFilter === 'active_plugin' || currentFilter === 'active') {
                        if (t.status !== 'active_plugin' && t.status !== 'active') {
                            return false;
                        }
                    } else if (t.status !== currentFilter) {
                        return false;
                    }
                }
                if (searchQuery) {
                    var nameMatch = t.name && t.name.toLowerCase().indexOf(searchQuery) !== -1;
                    var sourceMatch = t.source && t.source.toLowerCase().indexOf(searchQuery) !== -1;
                    var pluginMatch = t.likely_plugin && t.likely_plugin.toLowerCase().indexOf(searchQuery) !== -1;
                    if (!nameMatch && !sourceMatch && !pluginMatch) {
                        return false;
                    }
                }
                return true;
            });

            var orphanedCount = allTables.filter(function(t) { return t.status === 'orphaned'; }).length;
            if (currentFilter === 'orphaned' || (currentFilter === 'all' && orphanedCount > 0)) {
                $('#sba-orphaned-warning-banner').show();
            } else {
                $('#sba-orphaned-warning-banner').hide();
            }

            if (filtered.length === 0) {
                $('#sba-empty-state').show();
                return;
            }
            $('#sba-empty-state').hide();

            $.each(filtered, function(index, tbl) {
                var isCore = (tbl.status === 'core');
                var isActive = (tbl.status === 'active_plugin' || tbl.status === 'active');
                var rowClass = 'sba-row-' + sbaEscapeHtml(tbl.status);
                var badgeHtml = '';
                var subtextHtml = '';
                var actionHtml = '';

                var likelyPlugin = tbl.likely_plugin || tbl.source || sba__( 'Unknown / Custom Table', 'Unknown / Custom Table' );
                var safeLikelyPlugin = sbaEscapeHtml(likelyPlugin);
                var safeTableName = sbaEscapeHtml(tbl.name);

                if (isCore) {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-core"><span class="dashicons dashicons-wordpress"></span> ' + sba__( 'CORE', 'CORE' ) + '</span>';
                    subtextHtml = '<div class="sba-table-subtext"><span class="sba-belongs-to">' + sba__( 'WordPress Core Table', 'WordPress Core Table' ) + '</span></div>';
                    actionHtml = '<span style="color: #94a3b8; font-size: 12px; font-weight: 500;">' + sba__( 'Protected', 'Protected' ) + '</span>';
                } else if (isActive) {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes-alt"></span> ' + sba__( 'ACTIVE PLUGIN', 'ACTIVE PLUGIN' ) + '</span>';
                    subtextHtml = '<div class="sba-table-subtext">' +
                                  '<span class="sba-belongs-to">' + sba__( 'Belongs to: ', 'Belongs to: ' ) + '<strong>' + safeLikelyPlugin + '</strong></span>' +
                                  '<span class="sba-plugin-state-badge sba-state-active" title="' + sba__( 'Plugin is currently active', 'Plugin is currently active' ) + '"><span class="dashicons dashicons-yes"></span> ' + sba__( 'Active', 'Active' ) + '</span>' +
                                  '</div>';
                    actionHtml = '<span style="color: #15803d; font-size: 12px; font-weight: 600;">' + sba__( 'Active', 'Active' ) + '</span>';
                } else {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-warning"></span> ' + sba__( 'ORPHANED', 'ORPHANED' ) + '</span>';
                    subtextHtml = '<div class="sba-table-subtext">' +
                                  '<span class="sba-belongs-to">' + sba__( 'Belongs to: ', 'Belongs to: ' ) + '<strong>' + safeLikelyPlugin + '</strong></span>' +
                                  '<span class="sba-plugin-state-badge sba-state-inactive-deleted" title="' + sba__( 'Plugin is deactivated or uninstalled from this site', 'Plugin is deactivated or uninstalled from this site' ) + '">' +
                                  '<span class="dashicons dashicons-dismiss"></span> ' + sba__( 'Plugin is deleted/inactive', 'Plugin is deleted/inactive' ) +
                                  '</span>' +
                                  '</div>';
                    actionHtml = '<button type="button" class="button button-small sba-drop-btn" data-table="' + safeTableName + '" data-rows="' + parseInt(tbl.row_count, 10) + '" data-size="' + parseFloat(tbl.size_kb) + '" data-plugin="' + safeLikelyPlugin + '">' +
                                 '<span class="dashicons dashicons-trash"></span> <span>' + sba__( 'Drop Table', 'Drop Table' ) + '</span>' +
                                 '</button>';
                }

                var html = '<tr id="sba-row-' + safeTableName + '" class="' + rowClass + '">' +
                    '<td><span class="sba-table-name">' + safeTableName + '</span></td>' +
                    '<td>' + badgeHtml + subtextHtml + '</td>' +
                    '<td>' + formatNumber(tbl.row_count) + '</td>' +
                    '<td>' + formatSize(tbl.size_kb) + '</td>' +
                    '<td style="text-align: right;">' + actionHtml + '</td>' +
                '</tr>';

                $tbody.append(html);
            });
        }

        function updateCounts() {
            var total = allTables.length;
            var core = allTables.filter(function(t) { return t.status === 'core'; }).length;
            var active = allTables.filter(function(t) { return t.status === 'active_plugin' || t.status === 'active'; }).length;
            var orphaned = allTables.filter(function(t) { return t.status === 'orphaned'; }).length;

            $('#sba-count-all').text(total);
            $('#sba-count-core').text(core);
            $('#sba-count-active').text(active);
            $('#sba-count-orphaned').text(orphaned);

            $('#sba-stat-total-tables').text(total);
            $('#sba-stat-tables-meta').text(core + ' Core • ' + active + ' Active • ' + orphaned + ' Orphaned');
        }

        // Run Quick DB Audit
        $('#sba-run-scan').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $btn.find('.sba-btn-label').text(sba__( 'Auditing Database...', 'Auditing Database...' ));
            $('#sba-notice-container').hide();

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_database',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $btn.find('.sba-btn-label').text(sba__( 'Re-run DB Audit', 'Re-run DB Audit' ));

                if (response.success) {
                    var d = response.data;
                    allTables = d.tables || [];

                    $('#sba-stat-autoload-size').text(formatSize(d.autoload_size_kb));
                    $('#sba-stat-autoload-count').text(formatNumber(d.autoload_count) + ' ' + sba__( 'autoload rows', 'autoload rows' ));
                    $('#sba-stat-revisions').text(formatNumber(d.revisions_count));
                    var revBytes = Number(d.revisions_bytes || 0);
                    var revKb = Number(d.revisions_size_kb || 0);
                    var relBytes = Number(d.revisions_releasable_bytes !== undefined ? d.revisions_releasable_bytes : revBytes);
                    var relKb = Number(d.revisions_releasable_kb !== undefined ? d.revisions_releasable_kb : revKb);
                    $('#sba-stat-revisions-size').text(formatSize(revKb) + ' (' + formatNumber(revBytes) + ' bytes)');
                    $('#sba-stat-revisions-releasable').text(formatSize(relKb) + ' (' + formatNumber(relBytes) + ' bytes)');
                    $('#sba-stat-transients').text(formatNumber(d.transients_count));

                    updateCounts();
                    renderTables();

                    $('#sba-results').fadeIn(200);
                } else {
                    showNotice(response.data || sba__( 'Scan failed.', 'Scan failed.' ), true);
                }
            }).fail(function() {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $btn.find('.sba-btn-label').text(sba__( 'Run Quick DB Audit', 'Run Quick DB Audit' ));
                showNotice(sba__( 'Server communication error while scanning.', 'Server communication error while scanning.' ), true);
            });
        });

        // Table Tabs
        $('.sba-table-tab-btn').on('click', function(e) {
            e.preventDefault();
            $('.sba-table-tab-btn').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('tab');
            renderTables();
        });

        // Table Search
        $('#sba-table-search').on('input', function() {
            renderTables();
        });

        // 1-Click Cleaner: Transients
        $('#sba-clean-transients-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-action-label');
            var originalText = $label.text();

            $btn.prop('disabled', true).addClass('is-loading');
            $icon.addClass('sba-rotating');
            $label.text(sba__( 'Clearing...', 'Clearing...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_clean_transients',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                $icon.removeClass('sba-rotating');
                $label.text(originalText);

                if (response.success && response.data) {
                    $('#sba-stat-transients').text(formatNumber(response.data.remaining_transients || 0));
                    showNotice(response.data.message || sba__( 'Expired transients purged successfully.', 'Expired transients purged successfully.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to purge transients.', 'Failed to purge transients.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading');
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__( 'Server error while purging expired transients.', 'Server error while purging expired transients.' ), true);
            });
        });

        // 1-Click Cleaner: Revisions
        $('#sba-clean-revisions-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-action-label');
            var originalText = $label.text();

            if (!confirm(sba__( 'Safely clean post revisions older than the last 3 edits per post? Recent 3 revisions will be preserved.', 'Safely clean post revisions older than the last 3 edits per post? Recent 3 revisions will be preserved.' ))) {
                return;
            }

            $btn.prop('disabled', true).addClass('is-loading');
            $icon.addClass('sba-rotating');
            $label.text(sba__( 'Cleaning...', 'Cleaning...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_clean_revisions',
                _ajax_nonce: sbaNonce,
                keep: 3
            }, function(response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                $icon.removeClass('sba-rotating');
                $label.text(originalText);

                if (response.success && response.data) {
                    var rem = response.data.remaining_revisions || 0;
                    $('#sba-stat-revisions').text(formatNumber(rem));
                    if (response.data.stats) {
                        var s = response.data.stats;
                        $('#sba-stat-revisions-size').text(formatSize(s.total_kb || 0) + ' (' + formatNumber(s.total_bytes || 0) + ' bytes)');
                        $('#sba-stat-revisions-releasable').text(formatSize(s.releasable_kb || 0) + ' (' + formatNumber(s.releasable_bytes || 0) + ' bytes)');
                    }
                    showNotice(response.data.message || sba__( 'Post revisions cleaned successfully.', 'Post revisions cleaned successfully.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to clean revisions.', 'Failed to clean revisions.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading');
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__( 'Server error while cleaning post revisions.', 'Server error while cleaning post revisions.' ), true);
            });
        });

        // =====================================================================
        // REVISIONS MODAL & MANAGEMENT (100% IN SITE BLOAT AUDITOR)
        // =====================================================================
        var currentRevisions = [];

        function updateRevisionsUI(stats) {
            if (!stats) return;
            var count = Number(stats.total_count || 0);
            var bytes = Number(stats.total_bytes || 0);
            var kb = Number(stats.total_kb || 0);
            var relBytes = Number(stats.releasable_bytes !== undefined ? stats.releasable_bytes : bytes);
            var relKb = Number(stats.releasable_kb !== undefined ? stats.releasable_kb : kb);

            // Update Dashboard KPI Card
            $('#sba-stat-revisions').text(formatNumber(count));
            $('#sba-stat-revisions-size').text(formatSize(kb) + ' (' + formatNumber(bytes) + ' bytes)');
            $('#sba-stat-revisions-releasable').text(formatSize(relKb) + ' (' + formatNumber(relBytes) + ' bytes)');

            // Update Modal Stats
            $('#sba-modal-rev-count').text(formatNumber(count));
            $('#sba-modal-rev-bytes').text(formatSize(kb) + ' (' + formatNumber(bytes) + ' bytes)');
            $('#sba-modal-rev-releasable').text(formatSize(relKb) + ' (' + formatNumber(relBytes) + ' bytes)');
        }

        function showRevModalNotice(msg, isError) {
            var $box = $('#sba-revisions-modal-notice');
            $box.removeClass('notice-success notice-error notice-warning')
                .addClass(isError ? 'notice-error' : 'notice-success')
                .html('<p>' + sbaEscapeHtml(msg) + '</p>')
                .fadeIn(150);
            setTimeout(function() {
                $box.fadeOut(300);
            }, 6000);
        }

        function loadRevisionsModal() {
            $('#sba-revisions-modal-notice').hide().empty();
            $('#sba-revisions-loading').show();
            $('#sba-revisions-table-container, #sba-revisions-empty').hide();
            $('#sba-rev-select-all').prop('checked', false);
            $('#sba-rev-delete-selected-btn').prop('disabled', true);
            $('#sba-revisions-modal').fadeIn(200);

            $.post(sbaAjaxUrl, {
                action: 'sba_get_post_revisions',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $('#sba-revisions-loading').hide();
                if (response.success && response.data) {
                    currentRevisions = response.data.revisions || [];
                    updateRevisionsUI(response.data.stats);
                    renderRevisionsTable(currentRevisions);
                } else {
                    showRevModalNotice(response.data || sba__( 'Failed to load revisions.', 'Failed to load revisions.' ), true);
                }
            }).fail(function() {
                $('#sba-revisions-loading').hide();
                showRevModalNotice(sba__( 'Server error while loading revisions.', 'Server error while loading revisions.' ), true);
            });
        }

        function renderRevisionsTable(revisions) {
            var $tbody = $('#sba-revisions-tbody');
            $tbody.empty();

            if (!revisions || revisions.length === 0) {
                $('#sba-revisions-table-container').hide();
                $('.sba-revisions-toolbar').hide();
                $('#sba-revisions-empty').show();
                return;
            }

            $('.sba-revisions-toolbar').show();
            $('#sba-revisions-empty').hide();

            $.each(revisions, function(index, rev) {
                var safeParent = sbaEscapeHtml(rev.parent_title || 'Post');
                var safeType = sbaEscapeHtml(rev.parent_type || 'post');
                var safeDate = sbaEscapeHtml(rev.date || '');
                var safeHuman = sbaEscapeHtml(rev.human_date || '');
                var safeAuthor = sbaEscapeHtml(rev.author || 'Admin');
                var bytes = Number(rev.total_bytes || 0);
                var kb = Number(rev.size_kb || 0);

                var rowHtml = '<tr id="sba-rev-row-' + rev.id + '">' +
                    '<td><input type="checkbox" class="sba-rev-checkbox" value="' + rev.id + '" data-bytes="' + bytes + '" /></td>' +
                    '<td>' +
                        '<strong>' + safeParent + '</strong> ' +
                        '<span class="sba-badge-sub sba-badge-posttype">' + safeType + '</span>' +
                        '<div class="description" style="font-size: 11px; margin-top: 2px;">Revision #' + rev.id + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<div>' + safeDate + '</div>' +
                        '<div class="description" style="font-size: 11px;">by ' + safeAuthor + ' • ' + safeHuman + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<div><strong style="color: #0f172a;">' + formatNumber(bytes) + ' bytes</strong></div>' +
                        '<div class="description" style="font-size: 11px; color: #16a34a; font-weight: 600;">' + formatSize(kb) + ' freed on clean</div>' +
                    '</td>' +
                    '<td style="text-align: right;">' +
                        '<button type="button" class="button button-small button-link-delete sba-delete-single-rev-btn" data-id="' + rev.id + '" data-bytes="' + bytes + '">' +
                            '<span class="dashicons dashicons-trash"></span> ' +
                            '<span class="sba-del-text">Delete</span>' +
                        '</button>' +
                    '</td>' +
                '</tr>';

                $tbody.append(rowHtml);
            });

            $('#sba-revisions-table-container').show();
        }

        // Click on revision count or KPI card to view list
        $(document).on('click', '.sba-card-revisions, #sba-stat-revisions-trigger, #sba-stat-revisions, .sba-stat-revisions-clickable, .sba-rev-click-hint', function(e) {
            if ($(e.target).closest('#sba-clean-revisions-btn').length) {
                return;
            }
            e.preventDefault();
            loadRevisionsModal();
        });

        // Close revisions modal
        $(document).on('click', '.sba-modal-revisions-close', function(e) {
            e.preventDefault();
            $('#sba-revisions-modal').fadeOut(150);
        });

        // Close modal when clicking on overlay background
        $('#sba-revisions-modal').on('click', function(e) {
            if ($(e.target).is('#sba-revisions-modal')) {
                $('#sba-revisions-modal').fadeOut(150);
            }
        });

        // Delete single revision
        $(document).on('click', '.sba-delete-single-rev-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var revId = $btn.data('id');
            var revBytes = $btn.data('bytes');

            if (!confirm(sba__( 'Are you sure you want to permanently delete this revision? This action cannot be undone.', 'Are you sure you want to permanently delete this revision? This action cannot be undone.' ))) {
                return;
            }

            var $row = $('#sba-rev-row-' + revId);
            var $icon = $btn.find('.dashicons');
            var $text = $btn.find('.sba-del-text');
            $btn.prop('disabled', true);
            $icon.addClass('sba-rotating');
            $text.text(sba__( 'Deleting...', 'Deleting...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_single_revision',
                _ajax_nonce: sbaNonce,
                revision_id: revId
            }, function(response) {
                if (response.success && response.data) {
                    $row.fadeOut(200, function() {
                        $(this).remove();
                        currentRevisions = currentRevisions.filter(function(r) { return r.id != revId; });
                        updateRevisionsUI(response.data.stats);
                        if (currentRevisions.length === 0) {
                            renderRevisionsTable([]);
                        }
                    });
                    showRevModalNotice(response.data.message || sba__( 'Revision deleted successfully.', 'Revision deleted successfully.' ), false);
                } else {
                    $btn.prop('disabled', false);
                    $icon.removeClass('sba-rotating');
                    $text.text('Delete');
                    showRevModalNotice(response.data || sba__( 'Failed to delete revision.', 'Failed to delete revision.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $text.text('Delete');
                showRevModalNotice(sba__( 'Server error while deleting revision.', 'Server error while deleting revision.' ), true);
            });
        });

        // Checkbox selection in modal
        $(document).on('change', '#sba-rev-select-all', function() {
            var isChecked = $(this).is(':checked');
            $('.sba-rev-checkbox').prop('checked', isChecked);
            updateSelectedRevsCount();
        });

        $(document).on('change', '.sba-rev-checkbox', function() {
            updateSelectedRevsCount();
        });

        function updateSelectedRevsCount() {
            var selected = $('.sba-rev-checkbox:checked').length;
            var total = $('.sba-rev-checkbox').length;
            $('#sba-rev-select-all').prop('checked', total > 0 && selected === total);
            if (selected > 0) {
                $('#sba-rev-delete-selected-btn').prop('disabled', false).find('.sba-btn-text').text('Delete Selected (' + selected + ')');
            } else {
                $('#sba-rev-delete-selected-btn').prop('disabled', true).find('.sba-btn-text').text('Delete Selected');
            }
        }

        // Bulk delete selected
        $('#sba-rev-delete-selected-btn').on('click', function(e) {
            e.preventDefault();
            var selectedIds = [];
            $('.sba-rev-checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });

            if (selectedIds.length === 0) return;

            if (!confirm(sba__( 'Are you sure you want to permanently delete the %d selected revisions? This action cannot be undone.', 'Are you sure you want to permanently delete the %d selected revisions? This action cannot be undone.' ).replace('%d', selectedIds.length))) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).addClass('is-loading');

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_bulk_revisions',
                _ajax_nonce: sbaNonce,
                revision_ids: selectedIds
            }, function(response) {
                $btn.removeClass('is-loading');
                if (response.success && response.data) {
                    $.each(selectedIds, function(_, id) {
                        $('#sba-rev-row-' + id).remove();
                        currentRevisions = currentRevisions.filter(function(r) { return r.id != id; });
                    });
                    updateRevisionsUI(response.data.stats);
                    if (currentRevisions.length === 0) {
                        renderRevisionsTable([]);
                    } else {
                        updateSelectedRevsCount();
                    }
                    showRevModalNotice(response.data.message || sba__( 'Revisions deleted successfully.', 'Revisions deleted successfully.' ), false);
                } else {
                    showRevModalNotice(response.data || sba__( 'Failed to delete revisions.', 'Failed to delete revisions.' ), true);
                }
            }).fail(function() {
                $btn.removeClass('is-loading');
                showRevModalNotice(sba__( 'Server error while deleting revisions.', 'Server error while deleting revisions.' ), true);
            });
        });

        // Delete all revisions
        $('#sba-rev-delete-all-btn').on('click', function(e) {
            e.preventDefault();
            if (currentRevisions.length === 0) return;

            if (!confirm(sba__( 'Are you sure you want to permanently delete ALL post revisions? This action cannot be undone.', 'Are you sure you want to permanently delete ALL post revisions? This action cannot be undone.' ))) {
                return;
            }

            var allIds = currentRevisions.map(function(r) { return r.id; });
            var $btn = $(this);
            $btn.prop('disabled', true).addClass('is-loading');

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_bulk_revisions',
                _ajax_nonce: sbaNonce,
                revision_ids: allIds
            }, function(response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success && response.data) {
                    currentRevisions = [];
                    $('#sba-revisions-tbody').empty();
                    updateRevisionsUI(response.data.stats);
                    renderRevisionsTable([]);
                    showRevModalNotice(response.data.message || sba__( 'Revisions deleted successfully.', 'Revisions deleted successfully.' ), false);
                } else {
                    showRevModalNotice(response.data || sba__( 'Failed to delete revisions.', 'Failed to delete revisions.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading');
                showRevModalNotice(sba__( 'Server error while deleting revisions.', 'Server error while deleting revisions.' ), true);
            });
        });

        // Top Safety Card: Full Backup ZIP feedback
        $('#sba-btn-full-backup-zip').on('click', function() {
            var $btn = $(this);
            var $label = $btn.find('.sba-full-backup-zip-label');
            var originalText = $label.text();
            $label.text(sba__( 'Compressing Backup...', 'Compressing Backup...' ));
            setTimeout(function() {
                $label.text(originalText);
            }, 5000);
        });

        // Base64 Binary Download Helper
        function downloadBinaryBase64File(filename, base64Data, mimeType) {
            var byteCharacters = atob(base64Data);
            var byteNumbers = new Array(byteCharacters.length);
            for (var i = 0; i < byteCharacters.length; i++) {
                byteNumbers[i] = byteCharacters.charCodeAt(i);
            }
            var byteArray = new Uint8Array(byteNumbers);
            var blob = new Blob([byteArray], { type: mimeType || 'application/zip' });
            var link = document.createElement('a');
            var url = window.URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', filename);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        }

        // Drop Modal Dialog Open
        $(document).on('click', '.sba-drop-btn', function(e) {
            e.preventDefault();
            var tableName = $(this).data('table');
            var rows = $(this).data('rows');
            var size = $(this).data('size');
            var likelyPlugin = $(this).data('plugin') || sba__( 'Unknown / Custom Table', 'Unknown / Custom Table' );

            pendingDropTable = tableName;
            $('#sba-modal-table-name').text(tableName);
            $('#sba-modal-table-meta').text(formatNumber(rows) + ' ' + sba__( 'rows', 'rows' ) + ' • ' + formatSize(size));
            $('#sba-modal-table-plugin').html(sbaEscapeHtml(sba__( 'Belongs to: ', 'Belongs to: ' )) + '<strong>' + sbaEscapeHtml(likelyPlugin) + '</strong> &bull; <span style="color: #b91c1c; font-weight: 600;">' + sbaEscapeHtml(sba__( 'Plugin is deleted/inactive', 'Plugin is deleted/inactive' )) + '</span>');

            $('#sba-modal-backup-feedback').hide().empty();
            $('#sba-modal-drop-btn, #sba-modal-single-backup-btn, .sba-modal-cancel').prop('disabled', false);
            $('#sba-modal-drop-btn .sba-drop-btn-label').text(sba__( 'Permanently Drop Table', 'Permanently Drop Table' ));

            $('#sba-drop-modal').fadeIn(150);
        });

        // Close Modal
        $('.sba-modal-close, .sba-modal-cancel').on('click', function(e) {
            e.preventDefault();
            $('#sba-drop-modal').fadeOut(150);
            pendingDropTable = null;
        });

        $('#sba-drop-modal').on('click', function(e) {
            if ($(e.target).is('#sba-drop-modal')) {
                $('#sba-drop-modal').fadeOut(150);
                pendingDropTable = null;
            }
        });

        // Single Table Backup (.zip)
        $('#sba-modal-single-backup-btn').on('click', function(e) {
            e.preventDefault();
            if (!pendingDropTable) return;

            var $btn = $(this);
            var $label = $btn.find('.sba-backup-btn-label');
            var originalText = $label.text();
            $btn.prop('disabled', true);
            $label.text(sba__( 'Downloading Backup (.zip)...', 'Downloading Backup (.zip)...' ));

            var tableName = pendingDropTable;

            $.post(sbaAjaxUrl, {
                action: 'sba_backup_table',
                _ajax_nonce: sbaNonce,
                table_name: tableName
            }, function(response) {
                $btn.prop('disabled', false);
                $label.text(originalText);

                if (response.success && response.data && response.data.base64) {
                    downloadBinaryBase64File(response.data.filename, response.data.base64, response.data.mime);
                    $('#sba-modal-backup-feedback')
                        .html('<strong>✓ ' + sba__( 'Backup downloaded successfully: ', 'Backup downloaded successfully: ' ) + '</strong> <code>' + sbaEscapeHtml(response.data.filename) + '</code>')
                        .fadeIn()
                        .delay(4000)
                        .fadeOut();
                } else {
                    showNotice(response.data || sba__( 'Failed to export table backup.', 'Failed to export table backup.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $label.text(originalText);
                showNotice(sba__( 'Server communication error while exporting backup.', 'Server communication error while exporting backup.' ), true);
            });
        });

        // Permanently Drop Table
        $('#sba-modal-drop-btn').on('click', function(e) {
            e.preventDefault();
            if (!pendingDropTable) return;

            var tableName = pendingDropTable;
            var $confirmBtn = $(this);
            var $backupBtn = $('#sba-modal-single-backup-btn');
            var $cancelBtn = $('.sba-modal-cancel');

            $confirmBtn.prop('disabled', true);
            $backupBtn.prop('disabled', true);
            $cancelBtn.prop('disabled', true);
            $confirmBtn.find('.sba-drop-btn-label').text(sba__( 'Permanently Dropping...', 'Permanently Dropping...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_drop_table',
                _ajax_nonce: sbaNonce,
                table_name: tableName
            }, function(dropResponse) {
                $('#sba-drop-modal').fadeOut(150);
                $confirmBtn.prop('disabled', false);
                $backupBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $confirmBtn.find('.sba-drop-btn-label').text(sba__( 'Permanently Drop Table', 'Permanently Drop Table' ));

                if (dropResponse.success) {
                    var successMsg = dropResponse.data.message || (sba__( 'Table dropped successfully: ', 'Table dropped successfully: ' ) + sbaEscapeHtml(tableName));
                    showNotice(successMsg, false);

                    allTables = allTables.filter(function(t) {
                        return t.name !== tableName;
                    });

                    var $row = $('#sba-row-' + tableName);
                    if ($row.length) {
                        $row.css('background-color', '#fee2e2');
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            updateCounts();
                            renderTables();
                        });
                    } else {
                        updateCounts();
                        renderTables();
                    }
                } else {
                    showNotice(dropResponse.data || sba__( 'Failed to drop table.', 'Failed to drop table.' ), true);
                }
                pendingDropTable = null;
            }).fail(function() {
                $('#sba-drop-modal').fadeOut(150);
                $confirmBtn.prop('disabled', false);
                $backupBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $confirmBtn.find('.sba-drop-btn-label').text(sba__( 'Permanently Drop Table', 'Permanently Drop Table' ));
                showNotice(sba__( 'Server communication error while dropping table.', 'Server communication error while dropping table.' ), true);
                pendingDropTable = null;
            });
        });

        // =====================================================================
        // TAB 2: MEDIA FILES & STORAGE AUDIT LOGIC
        // =====================================================================

        // Update Quarantine Action Buttons state and labels
        function updateQuarantineActionButtons() {
            var count = selectedQuarantineIds.length;
            var totalQuarantined = (quarantinedMedia && Array.isArray(quarantinedMedia)) ? quarantinedMedia.length : 0;

            if (currentMediaFilter !== 'quarantined') {
                $('.sba-quarantine-toolbar-actions').hide();
                $('#sba-btn-empty-quarantine').hide();
                $('#sba-btn-restore-selected').hide();
                $('#sba-btn-empty-all-quarantine').hide();
                return;
            }

            $('.sba-quarantine-toolbar-actions').show();

            if (totalQuarantined === 0) {
                $('#sba-btn-empty-quarantine').hide();
                $('#sba-btn-restore-selected').hide();
                $('#sba-btn-empty-all-quarantine').hide();
                return;
            }

            $('#sba-btn-empty-quarantine').show().attr('title', sba__( 'Empty Quarantine Permanently', 'Empty Quarantine Permanently' ));
            if (count > 0) {
                $('#sba-btn-empty-quarantine-label').text(sba__( 'Delete Selected Permanently', 'Delete Selected Permanently' ) + ' (' + count + ')');
                $('#sba-quarantine-selected-count-restore').text(count);
                $('#sba-btn-restore-selected').show();
                $('#sba-btn-empty-all-quarantine').show();
            } else {
                $('#sba-btn-empty-quarantine-label').text(sba__( 'Empty Quarantine Permanently', 'Empty Quarantine Permanently' ));
                $('#sba-btn-restore-selected').hide();
                $('#sba-btn-empty-all-quarantine').hide();
            }
        }

        // Synchronize header "Select All" checkbox state
        function updateQuarantineSelectAllState() {
            var $allCheckboxes = $('.sba-quarantine-item-cb');
            var totalVisible = $allCheckboxes.length;
            if (totalVisible === 0) {
                $('#sba-quarantine-select-all').prop('checked', false).prop('indeterminate', false);
                return;
            }

            var checkedCount = $allCheckboxes.filter(':checked').length;
            if (checkedCount === 0) {
                $('#sba-quarantine-select-all').prop('checked', false).prop('indeterminate', false);
            } else if (checkedCount === totalVisible) {
                $('#sba-quarantine-select-all').prop('checked', true).prop('indeterminate', false);
            } else {
                $('#sba-quarantine-select-all').prop('checked', false).prop('indeterminate', true);
            }
        }

        function renderMediaTable() {
            var searchQuery = $('#sba-media-search').val().toLowerCase().trim();
            var $tbody = $('#sba-media-body');
            $tbody.empty();

            var isQuarantinedTab = (currentMediaFilter === 'quarantined');
            if (isQuarantinedTab) {
                $('.sba-quarantine-cb-col').show();
            } else {
                $('.sba-quarantine-cb-col').hide();
            }

            if (currentMediaFilter === 'duplicates') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }
                if (!duplicatesData || duplicatesData.length === 0) {
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__( 'No duplicate files detected!', 'No duplicate files detected!' ));
                    $('#sba-media-clean-desc').text(sba__( 'All scanned media files have distinct cryptographic SHA-256 hashes.', 'All scanned media files have distinct cryptographic SHA-256 hashes.' ));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderDuplicatesView($tbody, searchQuery);
                return;
            }

            if (currentMediaFilter === 'accessibility') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }
                if (!accessibilityData || !accessibilityData.items || accessibilityData.items.length === 0) {
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__( 'Excellent Image Accessibility!', 'Excellent Image Accessibility!' ));
                    $('#sba-media-clean-desc').text(sba__( 'All images contain descriptive alternative text (alt text) and informative filenames conforming to WCAG 2.1 guidelines.', 'All images contain descriptive alternative text (alt text) and informative filenames conforming to WCAG 2.1 guidelines.' ));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderAccessibilityView($tbody, searchQuery);
                return;
            }

            if (currentMediaFilter === 'discrepancies') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }
                var discTotal = 0;
                if (discrepanciesData) {
                    discTotal = (discrepanciesData.missing_originals ? discrepanciesData.missing_originals.length : 0) +
                                (discrepanciesData.missing_subsizes ? discrepanciesData.missing_subsizes.length : 0) +
                                (discrepanciesData.unattached_files ? discrepanciesData.unattached_files.length : 0);
                }
                if (discTotal === 0) {
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__( 'Filesystem and metadata are in complete sync!', 'Filesystem and metadata are in complete sync!' ));
                    $('#sba-media-clean-desc').text(sba__( 'All attachment records have valid files on disk and all generated thumbnail sub-sizes are present.', 'All attachment records have valid files on disk and all generated thumbnail sub-sizes are present.' ));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderDiscrepanciesView($tbody, searchQuery);
                return;
            }

            // Determine active dataset based on currentMediaFilter
            var sourceList = [];
            if (currentMediaFilter === 'all') {
                sourceList = allMedia;
            } else if (currentMediaFilter === 'unused') {
                sourceList = unusedMedia;
            } else if (currentMediaFilter === 'oversized') {
                sourceList = oversizedMedia;
            } else if (currentMediaFilter === 'optimized') {
                sourceList = optimizedMedia;
            } else if (currentMediaFilter === 'quarantined') {
                sourceList = quarantinedMedia;
            }

            // If user selects Quarantined tab
            if (currentMediaFilter === 'quarantined') {
                $('#sba-media-initial-state').hide();
                $('#sba-media-clean-state').hide();

                if (!quarantinedMedia || quarantinedMedia.length === 0) {
                    $('#sba-quarantined-empty-state').show();
                    $('#sba-media-table-container').hide();
                    updateQuarantineActionButtons();
                    return;
                }

                $('#sba-quarantined-empty-state').hide();
                $('#sba-media-table-container').show();
            } else {
                $('#sba-quarantined-empty-state').hide();

                // If scan has not run yet
                if (!mediaScanDone) {
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }

                $('#sba-media-initial-state').hide();

                // If scan has run but current category has 0 items
                if (sourceList.length === 0) {
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();

                    if (currentMediaFilter === 'unused') {
                        $('#sba-media-clean-title').text(sba__( 'Your Media Library is clean! No unlinked images detected.', 'Your Media Library is clean! No unlinked images detected.' ));
                        $('#sba-media-clean-desc').text(sba__( 'All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.', 'All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.' ));
                    } else if (currentMediaFilter === 'oversized') {
                        $('#sba-media-clean-title').text(sba__( 'All images meet display benchmarks! No oversized images found.', 'All images meet display benchmarks! No oversized images found.' ));
                        $('#sba-media-clean-desc').text(sba__( 'None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.', 'None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.' ));
                    } else if (currentMediaFilter === 'optimized') {
                        $('#sba-media-clean-title').text(sba__( 'No optimized images found.', 'No optimized images found.' ));
                        $('#sba-media-clean-desc').text(sba__( 'Active images require downscaling/compression or are currently unused.', 'Active images require downscaling/compression or are currently unused.' ));
                    } else {
                        $('#sba-media-clean-title').text(sba__( 'No media attachments found.', 'No media attachments found.' ));
                        $('#sba-media-clean-desc').text(sba__( 'The WordPress Media Library currently contains 0 attachments.', 'The WordPress Media Library currently contains 0 attachments.' ));
                    }
                    return;
                }

                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
            }

            // Filter by search query
            var filtered = sourceList.filter(function(m) {
                if (!searchQuery) {
                    return true;
                }
                var nameMatch = m.name && m.name.toLowerCase().indexOf(searchQuery) !== -1;
                var titleMatch = m.title && m.title.toLowerCase().indexOf(searchQuery) !== -1;
                var dimMatch = m.original_dimensions && m.original_dimensions.toLowerCase().indexOf(searchQuery) !== -1;
                var pathMatch = (m.file_path && m.file_path.toLowerCase().indexOf(searchQuery) !== -1) ||
                                (m.relative_path && m.relative_path.toLowerCase().indexOf(searchQuery) !== -1);

                var placeMatch = false;
                if (m.usage_places && m.usage_places.length > 0) {
                    for (var pi = 0; pi < m.usage_places.length; pi++) {
                        var pTitle = m.usage_places[pi].title || '';
                        var pType = m.usage_places[pi].type || '';
                        if (pTitle.toLowerCase().indexOf(searchQuery) !== -1 || pType.toLowerCase().indexOf(searchQuery) !== -1) {
                            placeMatch = true;
                            break;
                        }
                    }
                }

                return nameMatch || titleMatch || dimMatch || pathMatch || placeMatch;
            });

            if (filtered.length === 0) {
                var emptyColspan = isQuarantinedTab ? 6 : 5;
                $tbody.html('<tr><td colspan="' + emptyColspan + '" style="text-align: center; color: #64748b; padding: 30px;">' + sba__( 'No media items match your search filter.', 'No media items match your search filter.' ) + '</td></tr>');
                if (isQuarantinedTab) {
                    updateQuarantineSelectAllState();
                }
                updateQuarantineActionButtons();
                return;
            }

            $.each(filtered, function(index, item) {
                var safeId = parseInt(item.id, 10);
                var safeName = sbaEscapeHtml(item.name);
                var safeTitle = sbaEscapeHtml(item.title);
                var rawPath = item.file_path || (item.relative_path ? 'wp-content/uploads/' + item.relative_path.replace(/^[/\\]+/, '') : (item.name ? 'wp-content/uploads/' + item.name : ''));
                var safePath = rawPath ? sbaEscapeHtml(rawPath) : '';
                var safeDate = sbaEscapeHtml(item.upload_date || item.quarantined_date || '');
                var safeOrigDim = sbaEscapeHtml(item.original_dimensions || item.dimensions || 'N/A');
                var safeRecDim = sbaEscapeHtml(item.recommended_dimensions || 'N/A');
                var safeSize = sbaEscapeHtml(item.file_size_formatted || item.size_formatted || '0 B');
                var safeEstSize = sbaEscapeHtml(item.estimated_size_formatted || '');
                var safeSavings = sbaEscapeHtml(item.potential_savings_formatted || '');
                var safePreview = sbaEscapeHtml(item.preview_url || '');
                var safeBadgeText = sbaEscapeHtml(item.recommendation_badge || '');
                var badgeClass = sbaEscapeHtml(item.badge_class || 'sba-pill-warning');
                var rawEditUrl = (item.edit_url && typeof item.edit_url === 'string') ? item.edit_url.replace(/&#038;/g, '&').replace(/&amp;/g, '&') : '';
                var safeEditUrl = rawEditUrl ? sbaEscapeHtml(rawEditUrl) : '';
                var subsizeCount = parseInt(item.subsize_count, 10) || 0;
                var isOversized = !!item.is_oversized;
                var isItemQuarantined = isQuarantinedTab || (item.category === 'quarantined');

                // 1. Preview Column
                var previewHtml = '';
                if (safePreview) {
                    previewHtml = '<img src="' + safePreview + '" class="sba-media-thumb" alt="' + safeName + '" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'flex\';" />' +
                                  '<div class="sba-media-thumb-placeholder" style="display: none;"><span class="dashicons ' + (isItemQuarantined ? 'dashicons-shield' : 'dashicons-format-image') + '"></span></div>';
                } else {
                    previewHtml = '<div class="sba-media-thumb-placeholder"><span class="dashicons ' + (isItemQuarantined ? 'dashicons-shield' : 'dashicons-format-image') + '"></span></div>';
                }

                // 2. File & Dimensions Column
                var titleSubtext = (safeTitle && safeTitle !== safeName) ? '<div style="font-size: 11px; color: #64748b; margin-top: 2px;">' + safeTitle + '</div>' : '';
                var pathHtml = safePath ? '<div class="sba-table-source sba-media-file-path" title="' + sba__( 'Relative path in uploads directory', 'Relative path in uploads directory' ) + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>' : '';
                var datePrefix = isItemQuarantined ? sba__( 'Quarantined: ', 'Quarantined: ' ) : sba__( 'Uploaded: ', 'Uploaded: ' );
                var dateHtml = safeDate ? '<div class="sba-table-source">' + datePrefix + safeDate + '</div>' : '';

                var dimHtml = '';
                if (isOversized) {
                    dimHtml = '<div class="sba-dim-row">' +
                        '<span class="sba-dim-pill sba-dim-oversized" title="' + sba__( 'Resolution exceeds web display benchmark', 'Resolution exceeds web display benchmark' ) + '">' +
                            '<span class="dashicons dashicons-warning"></span> ' + sba__( 'Original: ', 'Original: ' ) + '<strong>' + safeOrigDim + '</strong>' +
                        '</span>' +
                        '<span class="sba-dim-arrow">→</span>' +
                        '<span class="sba-dim-pill sba-dim-recommended">' +
                            sba__( 'Rec: ', 'Rec: ' ) + '<strong>' + safeRecDim + '</strong>' +
                        '</span>' +
                    '</div>';
                } else if (safeOrigDim && safeOrigDim !== 'N/A') {
                    dimHtml = '<div class="sba-dim-row">' +
                        '<span class="sba-dim-pill sba-dim-ok">' +
                            '<span class="dashicons dashicons-yes-alt"></span> <strong>' + safeOrigDim + '</strong>' +
                        '</span>' +
                    '</div>';
                }

                var subsizeToggleHtml = '';
                var subsizeAccordionRowHtml = '';

                if (!isItemQuarantined && item.subsizes && item.subsizes.length > 0) {
                    var subsizesCount = item.subsizes.length;
                    subsizeToggleHtml = '<div class="sba-subsize-wrapper">' +
                        '<button type="button" class="sba-subsize-toggle-btn" data-target="#sba-subsizes-row-' + safeId + '" aria-expanded="false">' +
                            '<span class="dashicons dashicons-images-alt"></span> ' +
                            '<span class="sba-subsize-toggle-label">' + sba__( 'View Generated Sizes', 'View Generated Sizes' ) + ' (' + subsizesCount + ')</span> ' +
                            '<span class="dashicons dashicons-arrow-down-alt2 sba-subsize-toggle-arrow"></span>' +
                        '</button>' +
                    '</div>';

                    var subsizesItemsHtml = '';
                    for (var si = 0; si < item.subsizes.length; si++) {
                        var sub = item.subsizes[si];
                        var safeSubKey = sbaEscapeHtml(sub.size_key);
                        var safeSubFile = sbaEscapeHtml(sub.file);
                        var safeSubDim = sbaEscapeHtml(sub.dimensions || 'N/A');
                        var safeSubBytes = sbaEscapeHtml(sub.file_size_formatted || '0 B');
                        var statusBadgeHtml = '';
                        var actionBtnHtml = '';

                        if (sub.status === 'protected_responsive') {
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-responsive" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-smartphone"></span> ' + sba__( 'Responsive Size (Protected)', 'Responsive Size (Protected)' ) +
                            '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-subsize-disabled" disabled title="' + sba__( 'Protected: Required for mobile and tablet responsive srcset.', 'Protected: Required for mobile and tablet responsive srcset.' ) + '">' +
                                '<span class="dashicons dashicons-lock"></span> <span>' + sba__( 'Protected', 'Protected' ) + '</span>' +
                            '</button>';
                        } else if (sub.status === 'core_standard_size') {
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-blue" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-admin-settings"></span> ' + sba__( 'Standard Core Size', 'Standard Core Size' ) +
                            '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__( 'Delete Size', 'Delete Size' ) + '</span>' +
                            '</button>';
                        } else if (sub.status === 'unverified_template_usage') {
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-warning" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-visibility"></span> ' + sba__( 'Theme Size (Unverified Template Usage)', 'Theme Size (Unverified Template Usage)' ) +
                            '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__( 'Delete Size', 'Delete Size' ) + '</span>' +
                            '</button>';
                        } else {
                            // potential_unused or unreferenced
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-redundant" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-warning"></span> ' + sba__( 'Unregistered / Legacy Size', 'Unregistered / Legacy Size' ) +
                            '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__( 'Delete Size', 'Delete Size' ) + '</span>' +
                            '</button>';
                        }

                        subsizesItemsHtml += '<tr id="sba-subsize-item-' + safeId + '-' + safeSubKey + '">' +
                            '<td><code class="sba-subsize-code">' + safeSubKey + '</code></td>' +
                            '<td style="color: #334155; font-family: monospace; font-size: 11px;">' + safeSubFile + '</td>' +
                            '<td>' + safeSubDim + '</td>' +
                            '<td><strong>' + safeSubBytes + '</strong></td>' +
                            '<td>' + statusBadgeHtml + '</td>' +
                            '<td style="text-align: right;">' + actionBtnHtml + '</td>' +
                        '</tr>';
                    }

                    subsizeAccordionRowHtml = '<tr id="sba-subsizes-row-' + safeId + '" class="sba-subsizes-accordion-row" style="display: none;">' +
                        '<td colspan="5" class="sba-subsizes-accordion-cell">' +
                            '<div class="sba-subsizes-panel">' +
                                '<div class="sba-subsizes-panel-header">' +
                                    '<div class="sba-subsizes-panel-title">' +
                                        '<span class="dashicons dashicons-images-alt"></span> ' +
                                        '<strong>' + sba__( 'Generated Thumbnail Variations', 'Generated Thumbnail Variations' ) + ' (' + subsizesCount + ')</strong>' +
                                    '</div>' +
                                    '<div class="sba-subsizes-panel-desc">' +
                                        sba__( 'Standard responsive sizes are protected for mobile devices. Redundant sizes can be safely pruned to free disk space.', 'Standard responsive sizes are protected for mobile devices. Redundant sizes can be safely pruned to free disk space.' ) +
                                    '</div>' +
                                '</div>' +
                                '<div class="sba-subsizes-table-wrap">' +
                                    '<table class="sba-subsizes-table">' +
                                        '<thead>' +
                                            '<tr>' +
                                                '<th style="width: 15%;">' + sba__( 'Size Key', 'Size Key' ) + '</th>' +
                                                '<th style="width: 25%;">' + sba__( 'Filename', 'Filename' ) + '</th>' +
                                                '<th style="width: 15%;">' + sba__( 'Dimensions', 'Dimensions' ) + '</th>' +
                                                '<th style="width: 12%;">' + sba__( 'File Size', 'File Size' ) + '</th>' +
                                                '<th style="width: 23%;">' + sba__( 'Responsive / Audit Status', 'Responsive / Audit Status' ) + '</th>' +
                                                '<th style="width: 10%; text-align: right;">' + sba__( 'Action', 'Action' ) + '</th>' +
                                            '</tr>' +
                                        '</thead>' +
                                        '<tbody>' +
                                            subsizesItemsHtml +
                                        '</tbody>' +
                                    '</table>' +
                                '</div>' +
                            '</div>' +
                        '</td>' +
                    '</tr>';
                } else if (subsizeCount > 0) {
                    subsizeToggleHtml = '<div class="sba-subsize-badge"><span class="dashicons dashicons-images-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: middle;"></span> + ' + subsizeCount + ' ' + sba__( 'thumbnails', 'thumbnails' ) + '</div>';
                }

                // 3. Usage Context Column
                var usageHtml = '';
                if (isItemQuarantined) {
                    usageHtml = '<span class="sba-usage-tag sba-usage-tag-quarantined"><span class="dashicons dashicons-shield"></span> ' + sba__( 'In Quarantine Trash', 'In Quarantine Trash' ) + '</span>';
                } else if (item.usage_places && item.usage_places.length > 0) {
                    var placesCount = item.usage_places.length;
                    usageHtml = '<div style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">' +
                        sba__( 'Used in ', 'Used in ' ) + placesCount + ' ' + (placesCount === 1 ? sba__( 'location:', 'location:' ) : sba__( 'locations:', 'locations:' )) +
                    '</div><div class="sba-usage-list">';

                    var maxShow = Math.min(placesCount, 3);
                    for (var pi = 0; pi < maxShow; pi++) {
                        var place = item.usage_places[pi];
                        var pTitle = sbaEscapeHtml(place.title || sba__( 'Untitled Post', 'Untitled Post' ));
                        var pType = place.type || 'Content';

                        var tagClass = 'sba-usage-tag-content';
                        var iconClass = 'dashicons-admin-post';
                        var typeLabel = '';

                        if (pType === 'Featured') {
                            tagClass = 'sba-usage-tag-featured';
                            iconClass = 'dashicons-star-filled';
                            typeLabel = ' (' + sba__( 'Featured', 'Featured' ) + ')';
                        } else if (pType === 'Product Gallery') {
                            tagClass = 'sba-usage-tag-product';
                            iconClass = 'dashicons-cart';
                            typeLabel = ' (' + sba__( 'Gallery', 'Gallery' ) + ')';
                        } else if (pType === 'Logo' || pType === 'Site Icon') {
                            tagClass = 'sba-usage-tag-theme';
                            iconClass = 'dashicons-admin-appearance';
                            typeLabel = ' (' + sbaEscapeHtml(pType) + ')';
                        } else if (pType === 'Attached Parent') {
                            tagClass = 'sba-usage-tag-content';
                            iconClass = 'dashicons-paperclip';
                            typeLabel = ' (' + sba__( 'Attached', 'Attached' ) + ')';
                        }

                        usageHtml += '<div class="sba-usage-tag ' + tagClass + '" title="' + pTitle + '">' +
                            '<span class="dashicons ' + iconClass + '"></span>' +
                            '<span><strong>' + pTitle + '</strong>' + typeLabel + '</span>' +
                        '</div>';
                    }

                    if (placesCount > 3) {
                        usageHtml += '<div class="sba-usage-more">+ ' + (placesCount - 3) + ' ' + sba__( 'more locations', 'more locations' ) + '</div>';
                    }
                    usageHtml += '</div>';
                } else {
                    usageHtml = '<span class="sba-usage-tag sba-usage-tag-unused"><span class="dashicons dashicons-no-alt"></span> ' + sba__( '0 References (Unlinked)', '0 References (Unlinked)' ) + '</span>';
                }

                // 4. File Size & Savings Column
                var sizeHtml = '';
                if (isItemQuarantined) {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                               '<div class="sba-table-source">' + sba__( 'Isolated on disk', 'Isolated on disk' ) + '</div>';
                } else if (item.category === 'unused') {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                               '<div class="sba-savings-tag sba-savings-reclaimable" style="margin-top: 4px;">' +
                                   '<span class="dashicons dashicons-trash" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                                   sba__( 'Potentially Reclaimable', 'Potentially Reclaimable' ) +
                               '</div>';
                } else if (item.category === 'oversized' && item.potential_savings_bytes > 0 && safeSavings) {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' +
                                   safeSize + ' <span style="color: #94a3b8; font-weight: normal; margin: 0 3px;">→</span> <span style="color: #15803d; font-weight: 700;">~' + safeEstSize + '</span>' +
                               '</div>' +
                               '<div class="sba-savings-tag" style="margin-top: 4px;">' +
                                   '<span class="dashicons dashicons-arrow-down-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                                   sba__( 'Save ~', 'Save ~' ) + safeSavings +
                               '</div>';
                } else {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                               '<div class="sba-table-source" style="color: #16a34a; font-weight: 600; margin-top: 4px;">' +
                                   '<span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span> ' +
                                   sba__( 'Web Optimized', 'Web Optimized' ) +
                               '</div>';
                }

                // Build Edit in WP button
                var editButtonHtml = '';
                if (safeEditUrl) {
                    editButtonHtml = '<a href="' + safeEditUrl + '" target="_blank" class="button button-small sba-btn-edit-wp">' +
                        '<span class="dashicons dashicons-edit"></span> <span>' +
                        sba__( 'Edit in WP', 'Edit in WP' ) + '</span>' +
                    '</a>';
                } else {
                    editButtonHtml = '<button type="button" class="button button-small sba-btn-edit-wp" disabled style="opacity: 0.5; cursor: not-allowed;" title="' + sba__( 'Attachment editor link unavailable', 'Attachment editor link unavailable' ) + '">' +
                        '<span class="dashicons dashicons-edit"></span> <span>' +
                        sba__( 'Edit in WP', 'Edit in WP' ) + '</span>' +
                    '</button>';
                }

                // 5. Recommendation & Action Column
                var actionHtml = '';
                if (isItemQuarantined) {
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-shield"></span> ' + sba__( 'Quarantined', 'Quarantined' ) + '</span></div>' +
                                 '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                                     '<button type="button" class="button sba-btn-restore-item" data-id="' + safeId + '" data-name="' + safeName + '">' +
                                         '<span class="dashicons dashicons-undo"></span> <span>' + sba__( 'Restore', 'Restore' ) + '</span>' +
                                     '</button>' +
                                     '<button type="button" class="button sba-btn-delete-single-quarantine" data-id="' + safeId + '" data-name="' + safeName + '" title="' + sba__( 'Permanently delete this quarantined file', 'Permanently delete this quarantined file' ) + '">' +
                                         '<span class="dashicons dashicons-trash"></span> <span>' + sba__( 'Delete', 'Delete' ) + '</span>' +
                                     '</button>' +
                                 '</div>';
                } else if (item.category === 'unused') {
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-shield"></span> ' + (safeBadgeText || sba__( 'Review & Quarantine', 'Review & Quarantine' )) + '</span></div>' +
                                 '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                                     '<button type="button" class="button sba-btn-quarantine-item" data-id="' + safeId + '" data-name="' + safeName + '">' +
                                         '<span class="dashicons dashicons-shield"></span> <span>' + sba__( 'Move to Quarantine', 'Move to Quarantine' ) + '</span>' +
                                     '</button>' +
                                     editButtonHtml +
                                 '</div>';
                } else if (item.category === 'oversized') {
                    var optimizeBtnHtml = '';
                    if (item.has_backup) {
                        optimizeBtnHtml = '<button type="button" class="button button-small sba-btn-rollback-item" data-id="' + safeId + '" title="' + sba__( 'Rollback to original uncompressed image', 'Rollback to original uncompressed image' ) + '">' +
                            '<span class="dashicons dashicons-undo"></span> <span>' + sba__( 'Rollback', 'Rollback' ) + '</span>' +
                        '</button>';
                    } else {
                        optimizeBtnHtml = '<button type="button" class="button button-small button-primary sba-btn-optimize-item" data-id="' + safeId + '" data-w="' + (item.recommended_width || 1920) + '" data-h="' + (item.recommended_height || 1080) + '" title="' + sba__( 'Resize and optimize this image safely', 'Resize and optimize this image safely' ) + '">' +
                            '<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__( 'Optimize & WebP', 'Optimize & WebP' ) + '</span>' +
                        '</button>';
                    }

                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill ' + badgeClass + '"><span class="dashicons dashicons-warning"></span> ' + safeBadgeText + '</span></div>' +
                                 '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                                     optimizeBtnHtml +
                                     editButtonHtml +
                                 '</div>';
                } else {
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes"></span> ' + sba__( 'Web Optimized', 'Web Optimized' ) + '</span></div>' +
                                 '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">' +
                                     editButtonHtml +
                                 '</div>';
                }

                // Checkbox Column (only rendered on Quarantined tab)
                var cbHtml = '';
                var rowSelectedClass = '';
                if (isQuarantinedTab) {
                    var isChecked = selectedQuarantineIds.indexOf(safeId) !== -1;
                    cbHtml = '<td class="sba-quarantine-cb-col" style="text-align: center; vertical-align: middle;">' +
                        '<input type="checkbox" class="sba-quarantine-item-cb" value="' + safeId + '" data-id="' + safeId + '" data-name="' + safeName + '"' + (isChecked ? ' checked="checked"' : '') + ' aria-label="' + sba__( 'Select', 'Select' ) + ' ' + safeName + '" />' +
                    '</td>';
                    if (isChecked) {
                        rowSelectedClass = ' sba-row-selected';
                    }
                }

                var rowHtml = '<tr id="sba-media-row-' + safeId + '" class="' + rowSelectedClass + '">' +
                    cbHtml +
                    '<td style="text-align: center;">' + previewHtml + '</td>' +
                    '<td>' +
                        '<strong class="sba-table-name" style="font-size: 13px; color: #0f172a;">' + safeName + '</strong>' +
                        titleSubtext +
                        pathHtml +
                        dateHtml +
                        dimHtml +
                        subsizeToggleHtml +
                    '</td>' +
                    '<td>' + usageHtml + '</td>' +
                    '<td>' + sizeHtml + '</td>' +
                    '<td style="text-align: right;">' + actionHtml + '</td>' +
                '</tr>' + subsizeAccordionRowHtml;

                $tbody.append(rowHtml);
            });

            if (isQuarantinedTab) {
                updateQuarantineSelectAllState();
            }
            updateQuarantineActionButtons();
        }

        // Initialize display if quarantined items exist on initial page load
        if (quarantinedMedia && quarantinedMedia.length > 0) {
            $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length)).removeClass('sba-badge-gray').addClass('sba-badge-red');
        }
        updateQuarantineActionButtons();

        // Filter Tabs Event Handler
        $('.sba-media-filter-btn').on('click', function(e) {
            e.preventDefault();
            $('.sba-media-filter-btn').removeClass('active');
            $(this).addClass('active');
            currentMediaFilter = $(this).data('filter');

            if (currentMediaFilter !== 'quarantined') {
                selectedQuarantineIds = [];
            }

            $('#sba-media-oversized-banner').toggle(currentMediaFilter === 'oversized');
            $('#sba-media-quarantine-banner').toggle(currentMediaFilter === 'quarantined');
            $('#sba-media-duplicates-banner').toggle(currentMediaFilter === 'duplicates');
            $('#sba-media-accessibility-banner').toggle(currentMediaFilter === 'accessibility');
            $('#sba-media-discrepancies-banner').toggle(currentMediaFilter === 'discrepancies');

            updateQuarantineActionButtons();
            renderMediaTable();
        });

        // Search Input Event Handler
        $('#sba-media-search').on('input', function() {
            renderMediaTable();
        });

        // Media KPI Cards Click to Filter
        $('#sba-card-media-oversized, #sba-stat-media-oversized').on('click', function(e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="oversized"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        $('#sba-card-media-unused, #sba-stat-media-unused').on('click', function(e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="unused"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        $('#sba-card-media-total, #sba-stat-media-size').on('click', function(e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="all"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        // Trigger Media Scan
        $('#sba-run-media-scan').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-btn-label');
            var originalText = $label.text();

            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $label.text(sba__( 'Scanning Media Library...', 'Scanning Media Library...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_media_files',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(sba__( 'Re-scan Media Files', 'Re-scan Media Files' ));

                if (response.success && response.data) {
                    var d = response.data;
                    mediaScanDone = true;
                    allMedia = d.all_items || [];
                    unusedMedia = d.unused_items || [];
                    oversizedMedia = d.oversized_items || [];
                    optimizedMedia = d.optimized_items || [];
                    quarantinedMedia = d.quarantined_items || [];
                    totalMediaSizeMb = d.total_media_size_mb || 0;
                    reclaimableSpaceMb = d.reclaimable_space_mb || 0;
                    potentialSavingsMb = d.potential_savings_mb || 0;

                    // Update 5 Strictly Separated Summary Storage Metric Cards (Priority 2)
                    var potReclaimableMb      = d.potentially_reclaimable_mb !== undefined ? parseFloat(d.potentially_reclaimable_mb) : reclaimableSpaceMb;
                    var estSavingsMb          = d.estimated_optimization_savings_mb !== undefined ? parseFloat(d.estimated_optimization_savings_mb) : potentialSavingsMb;
                    var backupStorageMb       = d.backup_storage_consumed_mb !== undefined ? parseFloat(d.backup_storage_consumed_mb) : parseFloat(d.quarantined_size_mb || 0);
                    var physicallyReclaimedMb = d.actual_reclaimed_mb !== undefined ? parseFloat(d.actual_reclaimed_mb) : 0;

                    $('#sba-stat-media-size').text(totalMediaSizeMb.toFixed(2) + ' MB');
                    $('#sba-stat-media-unused').text(formatNumber(unusedMedia.length));
                    $('#sba-stat-media-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-stat-media-reclaimable, #sba-stat-media-savings').text(potReclaimableMb.toFixed(2) + ' MB');
                    $('#sba-stat-media-opt-savings').text(estSavingsMb.toFixed(2) + ' MB');
                    $('#sba-stat-media-backup-storage').text(backupStorageMb.toFixed(2) + ' MB');
                    $('#sba-stat-media-reclaimed').text(physicallyReclaimedMb.toFixed(2) + ' MB');

                    duplicatesData = d.duplicates || [];
                    accessibilityData = d.accessibility || null;
                    discrepanciesData = d.discrepancies || null;

                    // Update Filter Tab Badges
                    $('#sba-media-count-all').text(formatNumber(allMedia.length));
                    $('#sba-media-count-unused').text(formatNumber(unusedMedia.length));
                    $('#sba-media-count-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-media-count-optimized').text(formatNumber(optimizedMedia.length));
                    $('#sba-media-count-duplicates').text(formatNumber(duplicatesData.length));
                    var accessCount = (accessibilityData && accessibilityData.items) ? accessibilityData.items.length : 0;
                    $('#sba-media-count-accessibility').text(formatNumber(accessCount));
                    var discCount = 0;
                    if (discrepanciesData) {
                        discCount = (discrepanciesData.missing_originals ? discrepanciesData.missing_originals.length : 0) +
                                    (discrepanciesData.missing_subsizes ? discrepanciesData.missing_subsizes.length : 0) +
                                    (discrepanciesData.unattached_files ? discrepanciesData.unattached_files.length : 0);
                    }
                    $('#sba-media-count-discrepancies').text(formatNumber(discCount));
                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));

                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    } else {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    }
                    updateQuarantineActionButtons();

                    renderMediaTable();

                    var noticeMessage = sba__( 'Media scan complete: Scanned %1$d attachments. %2$d unused, %3$d oversized (%4$s MB total savings opportunity).', 'Media scan complete: Scanned %1$d attachments. %2$d unused, %3$d oversized (%4$s MB total savings opportunity).' )
                        .replace('%1$d', d.total_scanned || 0)
                        .replace('%2$d', unusedMedia.length)
                        .replace('%3$d', oversizedMedia.length)
                        .replace('%4$s', (reclaimableSpaceMb + potentialSavingsMb).toFixed(2));

                    showNotice(noticeMessage, false);
                } else {
                    showNotice(response.data || sba__( 'Failed to scan media files.', 'Failed to scan media files.' ), true);
                }
            }).fail(function() {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__( 'Server error while scanning media library.', 'Server error while scanning media library.' ), true);
            });
        });

        // Move Item to Quarantine Trash
        $(document).on('click', '.sba-btn-quarantine-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Moving...', 'Moving...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_quarantine_media',
                _ajax_nonce: sbaNonce,
                attachment_id: attId
            }, function(response) {
                if (response.success && response.data) {
                    var itemIndex = unusedMedia.findIndex(function(m) { return parseInt(m.id, 10) === attId; });
                    var movedItem = null;
                    if (itemIndex !== -1) {
                        movedItem = unusedMedia.splice(itemIndex, 1)[0];
                        reclaimableSpaceMb = Math.max(0, reclaimableSpaceMb - ((movedItem.file_size_bytes || 0) / (1024 * 1024)));
                    }

                    var allIndex = allMedia.findIndex(function(m) { return parseInt(m.id, 10) === attId; });
                    if (allIndex !== -1) {
                        allMedia.splice(allIndex, 1);
                    }

                    if (response.data.item) {
                        quarantinedMedia.unshift(response.data.item);
                    } else if (movedItem) {
                        quarantinedMedia.unshift(movedItem);
                    }

                    $('#sba-stat-media-unused').text(formatNumber(unusedMedia.length));
                    $('#sba-stat-media-reclaimable, #sba-stat-media-savings').text(reclaimableSpaceMb.toFixed(2) + ' MB');
                    $('#sba-media-count-all').text(formatNumber(allMedia.length));
                    $('#sba-media-count-unused').text(formatNumber(unusedMedia.length));
                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));

                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    }
                    updateQuarantineActionButtons();

                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Item successfully quarantined.', 'Item successfully quarantined.' ), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-shield"></span> ' + sba__( 'Move to Quarantine Trash', 'Move to Quarantine Trash' ));
                    showNotice(response.data || sba__( 'Failed to quarantine item.', 'Failed to quarantine item.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-shield"></span> ' + sba__( 'Move to Quarantine Trash', 'Move to Quarantine Trash' ));
                showNotice(sba__( 'Server communication error while quarantining item.', 'Server communication error while quarantining item.' ), true);
            });
        });

        // Restore Single Item from Quarantine
        $(document).on('click', '.sba-btn-restore-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Restoring...', 'Restoring...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_restore_media',
                _ajax_nonce: sbaNonce,
                attachment_id: attId
            }, function(response) {
                if (response.success && response.data) {
                    var qIndex = quarantinedMedia.findIndex(function(m) { return parseInt(m.id, 10) === attId; });
                    if (qIndex !== -1) {
                        quarantinedMedia.splice(qIndex, 1);
                    }
                    var sIndex = selectedQuarantineIds.indexOf(attId);
                    if (sIndex !== -1) {
                        selectedQuarantineIds.splice(sIndex, 1);
                    }

                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    } else {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    }

                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Item successfully restored to Media Library.', 'Item successfully restored to Media Library.' ), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-undo"></span> ' + sba__( 'Restore', 'Restore' ));
                    showNotice(response.data || sba__( 'Failed to restore item.', 'Failed to restore item.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-undo"></span> ' + sba__( 'Restore', 'Restore' ));
                showNotice(sba__( 'Server communication error while restoring item.', 'Server communication error while restoring item.' ), true);
            });
        });

        // Header "Select All" Checkbox for Quarantine
        $(document).on('change', '#sba-quarantine-select-all', function() {
            var isChecked = $(this).is(':checked');
            $('.sba-quarantine-item-cb').each(function() {
                var $cb = $(this);
                var id = parseInt($cb.val(), 10);
                $cb.prop('checked', isChecked);
                var $row = $cb.closest('tr');
                if (isChecked) {
                    $row.addClass('sba-row-selected');
                    if (selectedQuarantineIds.indexOf(id) === -1) {
                        selectedQuarantineIds.push(id);
                    }
                } else {
                    $row.removeClass('sba-row-selected');
                    var sIdx = selectedQuarantineIds.indexOf(id);
                    if (sIdx !== -1) {
                        selectedQuarantineIds.splice(sIdx, 1);
                    }
                }
            });
            updateQuarantineActionButtons();
        });

        // Individual Row Checkbox for Quarantine
        $(document).on('change', '.sba-quarantine-item-cb', function() {
            var $cb = $(this);
            var id = parseInt($cb.val(), 10);
            var isChecked = $cb.is(':checked');
            var $row = $cb.closest('tr');

            if (isChecked) {
                $row.addClass('sba-row-selected');
                if (selectedQuarantineIds.indexOf(id) === -1) {
                    selectedQuarantineIds.push(id);
                }
            } else {
                $row.removeClass('sba-row-selected');
                var sIdx = selectedQuarantineIds.indexOf(id);
                if (sIdx !== -1) {
                    selectedQuarantineIds.splice(sIdx, 1);
                }
            }

            updateQuarantineSelectAllState();
            updateQuarantineActionButtons();
        });

        // Empty Quarantine Permanently or Delete Selected Quarantined Files
        $('#sba-btn-empty-quarantine').on('click', function(e) {
            e.preventDefault();
            var hasSelection = selectedQuarantineIds.length > 0;
            var confirmMsg = '';

            if (hasSelection) {
                confirmMsg = sba__(
                    'Are you sure you want to permanently delete the %d selected quarantined media file(s)? This action cannot be undone.',
                    'Are you sure you want to permanently delete the %d selected quarantined media file(s)? This action cannot be undone.'
                ).replace('%d', selectedQuarantineIds.length);
            } else {
                confirmMsg = sba__(
                    'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.',
                    'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.'
                );
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).addClass('is-loading');

            var postData = {
                action: 'sba_empty_quarantine',
                _ajax_nonce: sbaNonce
            };

            if (hasSelection) {
                postData.attachment_ids = selectedQuarantineIds.slice();
            }

            $.post(sbaAjaxUrl, postData, function(response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success && response.data) {
                    if (hasSelection) {
                        var deletedIds = response.data.deleted_ids || selectedQuarantineIds;
                        quarantinedMedia = quarantinedMedia.filter(function(m) {
                            return deletedIds.indexOf(parseInt(m.id, 10)) === -1;
                        });
                        selectedQuarantineIds = [];
                    } else {
                        quarantinedMedia = [];
                        selectedQuarantineIds = [];
                    }

                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    } else {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    }

                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Quarantine trash updated successfully.', 'Quarantine trash updated successfully.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to empty quarantine.', 'Failed to empty quarantine.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading');
                showNotice(sba__( 'Server error while deleting quarantined files.', 'Server error while deleting quarantined files.' ), true);
            });
        });

        // Empty All Quarantine (Regardless of Selection)
        $('#sba-btn-empty-all-quarantine').on('click', function(e) {
            e.preventDefault();
            if (!confirm(sba__( 'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.', 'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.' ))) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sbaAjaxUrl, {
                action: 'sba_empty_quarantine',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $btn.prop('disabled', false);
                if (response.success && response.data) {
                    quarantinedMedia = [];
                    selectedQuarantineIds = [];
                    $('#sba-media-count-quarantined').text('0').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Quarantine trash emptied successfully.', 'Quarantine trash emptied successfully.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to empty quarantine.', 'Failed to empty quarantine.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                showNotice(sba__( 'Server error while emptying quarantine.', 'Server error while emptying quarantine.' ), true);
            });
        });

        // Restore Selected Quarantined Items
        $('#sba-btn-restore-selected').on('click', function(e) {
            e.preventDefault();
            if (selectedQuarantineIds.length === 0) {
                return;
            }

            var confirmMsg = sba__(
                'Are you sure you want to restore the %d selected media file(s) back to active Media Library?',
                'Are you sure you want to restore the %d selected media file(s) back to active Media Library?'
            ).replace('%d', selectedQuarantineIds.length);

            if (!confirm(confirmMsg)) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true).addClass('is-loading');

            var toRestore = selectedQuarantineIds.slice();

            $.post(sbaAjaxUrl, {
                action: 'sba_restore_media',
                _ajax_nonce: sbaNonce,
                attachment_ids: toRestore
            }, function(response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success && response.data) {
                    quarantinedMedia = quarantinedMedia.filter(function(m) {
                        return toRestore.indexOf(parseInt(m.id, 10)) === -1;
                    });
                    selectedQuarantineIds = [];

                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    } else {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    }

                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Selected items successfully restored to Media Library.', 'Selected items successfully restored to Media Library.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to restore selected items.', 'Failed to restore selected items.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading');
                showNotice(sba__( 'Server error while restoring items.', 'Server error while restoring items.' ), true);
            });
        });

        // Delete Single Item Permanently from Quarantine
        $(document).on('click', '.sba-btn-delete-single-quarantine', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);
            var attName = $btn.data('name') || '';

            var confirmMsg = sba__(
                'Are you sure you want to permanently delete "%s" from quarantine? This action cannot be undone.',
                'Are you sure you want to permanently delete "%s" from quarantine? This action cannot be undone.'
            ).replace('%s', attName);

            if (!confirm(confirmMsg)) {
                return;
            }

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Deleting...', 'Deleting...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_empty_quarantine',
                _ajax_nonce: sbaNonce,
                attachment_ids: [attId]
            }, function(response) {
                if (response.success && response.data) {
                    var qIndex = quarantinedMedia.findIndex(function(m) { return parseInt(m.id, 10) === attId; });
                    if (qIndex !== -1) {
                        quarantinedMedia.splice(qIndex, 1);
                    }
                    var sIndex = selectedQuarantineIds.indexOf(attId);
                    if (sIndex !== -1) {
                        selectedQuarantineIds.splice(sIndex, 1);
                    }

                    $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                    if (quarantinedMedia.length > 0) {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                    } else {
                        $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    }

                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__( 'Quarantined file deleted permanently.', 'Quarantined file deleted permanently.' ), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__( 'Delete', 'Delete' ));
                    showNotice(response.data || sba__( 'Failed to delete quarantined item.', 'Failed to delete quarantined item.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__( 'Delete', 'Delete' ));
                showNotice(sba__( 'Server communication error while deleting quarantined item.', 'Server communication error while deleting quarantined item.' ), true);
            });
        });

        // Toggle "View Generated Sizes" Accordion
        $(document).on('click', '.sba-subsize-toggle-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var targetId = $btn.data('target');
            var $targetRow = $(targetId);

            $btn.toggleClass('is-active');
            var isExpanded = $btn.hasClass('is-active');
            $btn.attr('aria-expanded', isExpanded ? 'true' : 'false');
            $targetRow.toggle();
        });

        // Safe Single Thumbnail Sub-size Pruning Action
        $(document).on('click', '.sba-btn-delete-subsize', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('att-id'), 10);
            var sizeKey = $btn.data('size-key');
            var fileName = $btn.data('file');

            if (!confirm(sba__( 'Are you sure you want to permanently delete this thumbnail variation? The physical file will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).', 'Are you sure you want to permanently delete this thumbnail variation? The physical file will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).' ))) {
                return;
            }

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Deleting...', 'Deleting...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_single_thumbnail_subsize',
                _ajax_nonce: sbaNonce,
                attachment_id: attId,
                size_key: sizeKey
            }, function(response) {
                if (response.success && response.data) {
                    // Update dataset cache across all tab lists
                    var updateDatasetItem = function(list) {
                        if (!list || !list.length) return;
                        var it = list.find(function(m) { return parseInt(m.id, 10) === attId; });
                        if (it && it.subsizes) {
                            it.subsizes = it.subsizes.filter(function(s) { return s.size_key !== sizeKey; });
                            it.subsize_count = it.subsizes.length;
                            if (response.data.new_total_bytes !== undefined) {
                                it.file_size_bytes = response.data.new_total_bytes;
                                it.file_size_formatted = response.data.new_total_formatted;
                            }
                        }
                    };

                    updateDatasetItem(allMedia);
                    updateDatasetItem(unusedMedia);
                    updateDatasetItem(oversizedMedia);
                    updateDatasetItem(optimizedMedia);

                    // Animate removal of the sub-size item row in the accordion
                    var $subRow = $('#sba-subsize-item-' + attId + '-' + sizeKey);
                    $subRow.css('background', '#fee2e2').fadeOut(300, function() {
                        $(this).remove();

                        var currentItem = allMedia.find(function(m) { return parseInt(m.id, 10) === attId; });
                        var remCount = (currentItem && currentItem.subsizes) ? currentItem.subsizes.length : 0;
                        var $parentRow = $('#sba-media-row-' + attId);

                        if (remCount > 0) {
                            $parentRow.find('.sba-subsize-toggle-label').text(
                                sba__( 'View Generated Sizes', 'View Generated Sizes' ) + ' (' + remCount + ')'
                            );
                            $('#sba-subsizes-row-' + attId).find('.sba-subsizes-panel-title strong').text(
                                sba__( 'Generated Thumbnail Variations', 'Generated Thumbnail Variations' ) + ' (' + remCount + ')'
                            );
                        } else {
                            $parentRow.find('.sba-subsize-wrapper').remove();
                            $('#sba-subsizes-row-' + attId).remove();
                        }

                        // Update formatted file size in column 4
                        if (response.data.new_total_formatted) {
                            $parentRow.find('td:nth-child(4) > div:first-child').text(response.data.new_total_formatted);
                        }
                    });

                    showNotice(response.data.message || sba__( 'Thumbnail variant deleted successfully.', 'Thumbnail variant deleted successfully.' ), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__( 'Delete Size', 'Delete Size' ));
                    showNotice(response.data || sba__( 'Failed to delete thumbnail variant.', 'Failed to delete thumbnail variant.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__( 'Delete Size', 'Delete Size' ));
                showNotice(sba__( 'Server error while deleting thumbnail variant.', 'Server error while deleting thumbnail variant.' ), true);
            });
        });

        // =====================================================================
        // TAB 3: PLUGIN AUDIT LOGIC
        // =====================================================================
        function renderPlugins() {
            var searchQuery = $('#sba-plugin-search').val().toLowerCase().trim();
            var $tbody = $('#sba-plugins-body');
            $tbody.empty();

            var filtered = allPlugins.filter(function(p) {
                // Tab filter: 'all', 'dependencies', 'unverified', 'outdated', 'inactive'
                if (currentPluginFilter === 'dependencies') {
                    if (!p.dependency_issue && p.risk_level !== 'Inactive Dependency') {
                        return false;
                    }
                } else if (currentPluginFilter === 'unverified') {
                    if (p.is_self || (p.repo_status !== 'unverified' && p.risk_level !== 'High')) {
                        return false;
                    }
                } else if (currentPluginFilter === 'outdated') {
                    if (!p.is_outdated && p.classification !== 'outdated') {
                        return false;
                    }
                } else if (currentPluginFilter === 'inactive') {
                    if (p.is_active) {
                        return false;
                    }
                }

                // Search query
                if (searchQuery) {
                    var nameMatch = p.name && p.name.toLowerCase().indexOf(searchQuery) !== -1;
                    var slugMatch = p.slug && p.slug.toLowerCase().indexOf(searchQuery) !== -1;
                    var authorMatch = p.author && p.author.toLowerCase().indexOf(searchQuery) !== -1;
                    var fileMatch = p.plugin_file && p.plugin_file.toLowerCase().indexOf(searchQuery) !== -1;
                    var explMatch = p.risk_explanation && p.risk_explanation.toLowerCase().indexOf(searchQuery) !== -1;
                    var noteMatch = p.risk_note && p.risk_note.toLowerCase().indexOf(searchQuery) !== -1;
                    if (!nameMatch && !slugMatch && !authorMatch && !fileMatch && !explMatch && !noteMatch) {
                        return false;
                    }
                }
                return true;
            });

            if (filtered.length === 0) {
                $('#sba-plugins-empty-state').show();
                return;
            }
            $('#sba-plugins-empty-state').hide();

            $.each(filtered, function(index, p) {
                var safeName = sbaEscapeHtml(p.name);
                var safeVersion = sbaEscapeHtml(p.version);
                var safeAuthor = sbaEscapeHtml(p.author || '');
                var safeAuthorUri = p.author_uri ? sbaEscapeHtml(p.author_uri) : '';
                var safeFile = sbaEscapeHtml(p.plugin_file);
                var safeExpl = sbaEscapeHtml(p.risk_explanation);
                var safeRepoUrl = p.repo_url ? sbaEscapeHtml(p.repo_url) : '';
                var safeTestedWp = p.tested_wp ? sbaEscapeHtml(p.tested_wp) : '';
                var safeUpdatedDate = p.last_updated_date ? sbaEscapeHtml(p.last_updated_date) : '';
                var sizeText = (parseFloat(p.size_mb) || 0).toFixed(2) + ' MB';

                // 1. Plugin & Author Column
                var authorHtml = '';
                if (safeAuthor) {
                    if (safeAuthorUri) {
                        authorHtml = '<div class="sba-table-source">' + sba__( 'By ', 'By ' ) + '<a href="' + safeAuthorUri + '" target="_blank" rel="noopener noreferrer">' + safeAuthor + '</a></div>';
                    } else {
                        authorHtml = '<div class="sba-table-source">' + sba__( 'By ', 'By ' ) + safeAuthor + '</div>';
                    }
                }

                var pluginColHtml = '<strong class="sba-plugin-name">' + safeName + '</strong> ' +
                    '<span class="sba-plugin-version">v' + safeVersion + '</span>' +
                    authorHtml +
                    '<div class="sba-table-source" style="font-size: 11px; font-family: monospace; color: #64748b; margin-top: 2px;"><code>' + safeFile + '</code></div>';

                // 2. Status Badge (Active vs Inactive)
                var statusBadge = '';
                if (p.is_active) {
                    statusBadge = '<span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes-alt"></span> ' + sba__( 'Active', 'Active' ) + '</span>';
                } else {
                    statusBadge = '<span class="sba-badge-pill sba-pill-inactive"><span class="dashicons dashicons-dismiss"></span> ' + sba__( 'Inactive', 'Inactive' ) + '</span>';
                }

                // 3. Repo Verification Badge
                var repoBadge = '';
                if (p.is_self) {
                    repoBadge = '<span class="sba-badge-pill sba-pill-self" title="' + sba__( 'Site Bloat & Storage Auditor (This Auditor)', 'Site Bloat & Storage Auditor (This Auditor)' ) + '">' +
                        '<span class="dashicons dashicons-admin-tools"></span> ' + sba__( 'Local Plugin (This Auditor)', 'Local Plugin (This Auditor)' ) +
                    '</span>';
                } else if (p.repo_status === 'verified') {
                    repoBadge = '<div style="margin-bottom: 4px;">' +
                        '<a href="' + safeRepoUrl + '" target="_blank" rel="noopener noreferrer" class="sba-badge-pill sba-pill-verified" title="' + sba__( 'View on official WordPress.org Directory', 'View on official WordPress.org Directory' ) + '">' +
                            '<span class="dashicons dashicons-yes"></span> ' + sba__( 'WordPress.org Verified', 'WordPress.org Verified' ) + ' <span class="dashicons dashicons-external" style="font-size: 11px; width: 11px; height: 11px; margin-left: 2px;"></span>' +
                        '</a>' +
                    '</div>';
                    if (safeTestedWp || safeUpdatedDate) {
                        var metaParts = [];
                        if (safeTestedWp) {
                            metaParts.push(sba__( 'Tested up to WP ', 'Tested up to WP ' ) + safeTestedWp);
                        }
                        if (safeUpdatedDate) {
                            metaParts.push(safeUpdatedDate);
                        }
                        repoBadge += '<div class="sba-table-source" style="font-size: 11px;">' + metaParts.join(' &bull; ') + '</div>';
                    }
                } else {
                    repoBadge = '<span class="sba-badge-pill sba-pill-unverified" title="' + sba__( 'Unverified plugins are not found in the official WordPress.org repository. Inspect them carefully for security, authenticity, and ongoing maintenance.', 'Unverified plugins are not found in the official WordPress.org repository. Inspect them carefully for security, authenticity, and ongoing maintenance.' ) + '">' +
                        '<span class="dashicons dashicons-warning"></span> ' + sba__( 'Unverified / Not in Directory', 'Unverified / Not in Directory' ) +
                    '</span>';
                }

                // 4. Disk & Database Linked Footprint Column
                var dbCount = parseInt(p.db_tables_count, 10) || 0;
                var dbSize = sbaEscapeHtml(p.db_tables_size || '0 KB');
                var tableLabel = (dbCount === 1) ? sba__( 'Table', 'Table' ) : sba__( 'Tables', 'Tables' );
                var dbFormatted = dbCount + ' ' + tableLabel + ' (' + dbSize + ')';

                var diskHtml = '<div style="line-height: 1.45;">' +
                    '<div style="font-size: 13px; font-weight: 700; color: #0f172a;">Disk: ' + sizeText + '</div>' +
                    '<div style="font-size: 12px; font-weight: 600; color: #475569; margin-top: 3px;">DB: ' + dbFormatted + '</div>' +
                '</div>';

                // 5. Risk & Action Column
                var riskBadge = '';
                var riskNoteHtml = '';

                if (p.is_self || p.risk_level === 'Local Active' || p.risk_level === 'Local Plugin') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-self"><span class="dashicons dashicons-admin-tools"></span> ' + sba__( 'Local Active', 'Local Active' ) + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #1d4ed8; text-align: right; line-height: 1.35; max-width: 250px;">' + safeExpl + '</div>';
                } else if (p.dependency_issue || p.risk_level === 'Dependency Inactive' || p.risk_level === 'Inactive Dependency') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-dependency"><span class="dashicons dashicons-warning"></span> ' + sba__( 'Dependency Inactive', 'Dependency Inactive' ) + '</span>';
                    var depNoteText = p.risk_note ? sbaEscapeHtml(p.risk_note) : safeExpl;
                    riskNoteHtml = '<div style="font-size: 11px; color: #b45309; text-align: right; line-height: 1.35; max-width: 250px; font-weight: 500;">' + depNoteText + '</div>';
                } else if (p.risk_level === 'High') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-high"><span class="dashicons dashicons-warning"></span> ' + sba__( 'High Risk', 'High Risk' ) + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #dc2626; text-align: right; line-height: 1.35; max-width: 250px;">' + safeExpl + '</div>';
                } else if (p.risk_level === 'Medium') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-medium"><span class="dashicons dashicons-clock"></span> ' + sba__( 'Medium Risk', 'Medium Risk' ) + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #b45309; text-align: right; line-height: 1.35; max-width: 250px;">' + safeExpl + '</div>';
                } else {
                    riskBadge = '<span class="sba-risk-badge sba-risk-low"><span class="dashicons dashicons-shield"></span> ' + sba__( 'Low Risk', 'Low Risk' ) + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #64748b; text-align: right; line-height: 1.35; max-width: 250px;">' + safeExpl + '</div>';
                }

                var actionBtnHtml = '<a href="' + adminPluginsUrl + '" class="button button-small sba-btn-manage-plugin" target="_blank" title="' + sba__( 'Manage this plugin in WordPress Plugins screen', 'Manage this plugin in WordPress Plugins screen' ) + '">' +
                    '<span class="dashicons dashicons-admin-plugins"></span> <span>' +
                    sba__( 'Manage in Plugins', 'Manage in Plugins' ) + '</span>' +
                '</a>';

                var html = '<tr>' +
                    '<td>' + pluginColHtml + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + repoBadge + '</td>' +
                    '<td>' + diskHtml + '</td>' +
                    '<td style="text-align: right;">' +
                        '<div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">' +
                            '<div>' + riskBadge + '</div>' +
                            riskNoteHtml +
                            '<div style="margin-top: 4px;">' + actionBtnHtml + '</div>' +
                        '</div>' +
                    '</td>' +
                '</tr>';

                $tbody.append(html);
            });
        }

        function updatePluginCounts(data) {
            var total = (data && data.total_count !== undefined) ? data.total_count : allPlugins.length;
            var active = (data && data.active_count !== undefined) ? data.active_count : allPlugins.filter(function(p) { return p.is_active; }).length;
            var inactive = (data && data.inactive_count !== undefined) ? data.inactive_count : (total - active);
            var unverified = (data && data.unverified_count !== undefined) ? data.unverified_count : allPlugins.filter(function(p) { return p.repo_status === 'unverified' && !p.is_self; }).length;
            var outdated = (data && data.outdated_count !== undefined) ? data.outdated_count : allPlugins.filter(function(p) { return p.is_outdated; }).length;
            var dependencies = (data && data.dependencies_count !== undefined) ? data.dependencies_count : allPlugins.filter(function(p) { return p.dependency_issue || p.risk_level === 'Dependency Inactive' || p.risk_level === 'Inactive Dependency'; }).length;
            var totalRisks = (data && data.total_risks_count !== undefined) ? data.total_risks_count : (allPlugins.filter(function(p) { return p.risk_level === 'High' || p.risk_level === 'Medium' || p.dependency_issue; }).length);
            var highRisks = (data && data.high_risk_count !== undefined) ? data.high_risk_count : allPlugins.filter(function(p) { return p.risk_level === 'High'; }).length;
            var mediumRisks = (data && data.medium_risk_count !== undefined) ? data.medium_risk_count : allPlugins.filter(function(p) { return p.risk_level === 'Medium'; }).length;

            var totalSize = (data && data.total_size_mb !== undefined) ? data.total_size_mb : 0;

            // 4 KPI Metric Cards
            $('#sba-stat-plugins-total-size').text(totalSize.toFixed(2) + ' MB');
            $('#sba-stat-total-plugins').text(total);
            $('#sba-stat-active-inactive-plugins').text(active + ' ' + sba__( 'Active', 'Active' ) + ' • ' + inactive + ' ' + sba__( 'Inactive', 'Inactive' ));

            $('#sba-stat-unverified-plugins').text(unverified);
            if (unverified > 0) {
                $('#sba-stat-unverified-badge').show();
            } else {
                $('#sba-stat-unverified-badge').hide();
            }

            $('#sba-stat-risks-total').text(totalRisks);
            $('#sba-stat-risks-breakdown').text(highRisks + ' ' + sba__( 'High', 'High' ) + ' • ' + mediumRisks + ' ' + sba__( 'Medium Risk', 'Medium Risk' ));

            // Filter Tabs Counts
            $('#sba-plugin-count-all').text(total);
            $('#sba-plugin-count-dependencies').text(dependencies);
            $('#sba-plugin-count-unverified').text(unverified);
            $('#sba-plugin-count-outdated').text(outdated);
            $('#sba-plugin-count-inactive').text(inactive);

            // Security Risk Warning Banner
            if (unverified > 0 || totalRisks > 0) {
                var securityMsg = unverified + ' ' + sba__( 'plugin(s) were not found in the official WordPress.org repository. These represent potential security or maintenance risks if not from a verified trusted vendor.', 'plugin(s) were not found in the official WordPress.org repository. These represent potential security or maintenance risks if not from a verified trusted vendor.' );
                $('#sba-plugin-security-text').text(securityMsg);
                $('#sba-plugin-security-banner').show();
            } else {
                $('#sba-plugin-security-banner').hide();
            }
        }

        // Run Plugin Audit
        $('#sba-run-plugin-scan').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-btn-label');
            var originalText = $label.text();

            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $label.text(sba__( 'Verifying with WordPress.org...', 'Verifying with WordPress.org...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_plugins_audit',
                _ajax_nonce: sbaNonce
            }, function(response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(sba__( 'Re-run Plugin Audit', 'Re-run Plugin Audit' ));

                if (response.success && response.data) {
                    var d = response.data;
                    allPlugins = d.plugins || [];

                    updatePluginCounts(d);
                    renderPlugins();

                    showNotice(sba__( 'Plugin audit complete. Results verified with WordPress.org API.', 'Plugin audit complete. Results verified with WordPress.org API.' ), false);
                } else {
                    showNotice(response.data || sba__( 'Failed to scan plugins.', 'Failed to scan plugins.' ), true);
                }
            }).fail(function() {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__( 'Server communication error while scanning plugins.', 'Server communication error while scanning plugins.' ), true);
            });
        });

        // Plugin Tabs Filter
        $('.sba-plugin-tab-btn').on('click', function(e) {
            e.preventDefault();
            $('.sba-plugin-tab-btn').removeClass('active');
            $(this).addClass('active');
            currentPluginFilter = $(this).data('tab');
            renderPlugins();
        });

        // Plugin Search
        $('#sba-plugin-search').on('input', function() {
            renderPlugins();
        });

        // =====================================================================
        // PHASE 1: DUPLICATES, ACCESSIBILITY, AND DISCREPANCY VIEWS
        // =====================================================================

        function renderDuplicatesView($tbody, searchQuery) {
            $.each(duplicatesData, function(gIdx, group) {
                var groupHash = sbaEscapeHtml(group.hash.substring(0, 16)) + '...';
                var groupCount = group.count;
                var groupSavings = sbaEscapeHtml(group.potential_savings_formatted);
                var groupRec = sbaEscapeHtml(group.recommendation);

                var headerRowHtml = '<tr class="sba-duplicate-group-header">' +
                    '<td colspan="5">' +
                        '<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">' +
                            '<div>' +
                                '<strong style="color: #0f172a; font-size: 13px;"><span class="dashicons dashicons-images-alt" style="margin-right: 4px;"></span> Duplicate Group (' + groupCount + ' files)</strong> ' +
                                '<code style="font-size: 11px; margin-left: 6px; background: #e2e8f0; padding: 2px 6px; border-radius: 3px;">SHA-256: ' + groupHash + '</code>' +
                            '</div>' +
                            '<div>' +
                                '<span class="sba-savings-tag" style="margin-right: 8px;"><span class="dashicons dashicons-arrow-down-alt"></span> Save ' + groupSavings + '</span>' +
                                '<span class="sba-badge-sub" style="background: #fef3c7; color: #92400e;">' + groupRec + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</td>' +
                '</tr>';
                $tbody.append(headerRowHtml);

                $.each(group.items, function(iIdx, item) {
                    var safeId = parseInt(item.id, 10);
                    var safeName = sbaEscapeHtml(item.name);
                    var safePath = sbaEscapeHtml(item.file_path || ('wp-content/uploads/' + (item.relative_path || '')));
                    var safeSize = sbaEscapeHtml(item.file_size_formatted || '0 B');
                    var safePreview = sbaEscapeHtml(item.preview_url || '');
                    var isUsed = item.is_in_use;
                    var editUrl = 'post.php?post=' + safeId + '&action=edit';

                    var previewHtml = safePreview ? '<img src="' + safePreview + '" class="sba-media-thumb" alt="' + safeName + '" />' : '<div class="sba-media-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>';
                    var pathHtml = '<div class="sba-table-source sba-media-file-path" title="' + sba__( 'Relative path in uploads directory', 'Relative path in uploads directory' ) + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>';

                    var usageHtml = isUsed ?
                        '<span class="sba-usage-tag sba-usage-tag-content"><span class="dashicons dashicons-yes"></span> ' + (item.usage_count || 1) + ' Reference(s)</span>' :
                        '<span class="sba-usage-tag sba-usage-tag-unused"><span class="dashicons dashicons-no-alt"></span> 0 References (Review Required)</span>';

                    var actionHtml = '<div style="display: flex; gap: 6px; justify-content: flex-end;">' +
                        (isUsed ? '' : '<button type="button" class="button button-small sba-btn-quarantine-item" data-id="' + safeId + '" data-name="' + safeName + '"><span class="dashicons dashicons-shield"></span> Move to Quarantine</button>') +
                        '<a href="' + editUrl + '" target="_blank" class="button button-small sba-btn-edit-wp"><span class="dashicons dashicons-edit"></span> Edit in WP</a>' +
                    '</div>';

                    var keeperTag = item.is_keeper ?
                        '<span class="sba-badge-sub" style="background: #e0f2fe; color: #0369a1; margin-left: 6px; font-size: 10px; padding: 2px 6px; border-radius: 3px; font-weight: 600;"><span class="dashicons dashicons-star-filled" style="font-size: 11px; width: 11px; height: 11px; line-height: 1; vertical-align: text-top;"></span> Primary Copy</span>' : '';

                    var itemRowHtml = '<tr>' +
                        '<td style="text-align: center;">' + previewHtml + '</td>' +
                        '<td><strong class="sba-table-name">' + safeName + '</strong>' + keeperTag + pathHtml + '</td>' +
                        '<td>' + usageHtml + '</td>' +
                        '<td><strong>' + safeSize + '</strong></td>' +
                        '<td style="text-align: right;">' + actionHtml + '</td>' +
                    '</tr>';
                    $tbody.append(itemRowHtml);
                });
            });
        }

        function renderAccessibilityView($tbody, searchQuery) {
            $.each(accessibilityData.items, function(idx, item) {
                var safeId = parseInt(item.id, 10);
                var safeName = sbaEscapeHtml(item.name);
                var safePath = sbaEscapeHtml(item.file_path || '');
                var safeDim = sbaEscapeHtml(item.dimensions || 'N/A');
                var safeSize = sbaEscapeHtml(item.file_size || '0 B');
                var altText = sbaEscapeHtml(item.alt_text || '');
                var editUrl = sbaEscapeHtml(item.edit_url || ('post.php?post=' + safeId + '&action=edit'));

                var issuesBadges = '';
                if (item.issues && item.issues.indexOf('missing_alt_attr') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-red" style="margin-right: 4px;">' + sba__( 'Missing Alt Attribute', 'Missing Alt Attribute' ) + '</span>';
                }
                if (item.issues && item.issues.indexOf('empty_alt') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-orange" style="margin-right: 4px;">' + sba__( 'Empty Alt Text (Decorative?)', 'Empty Alt Text (Decorative?)' ) + '</span>';
                }
                if (item.issues && item.issues.indexOf('unhelpful_alt') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-orange" style="margin-right: 4px;">' + sba__( 'Repetitive / Generic Alt', 'Repetitive / Generic Alt' ) + '</span>';
                }
                if (item.issues && item.issues.indexOf('undescriptive_filename') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-gray" style="margin-right: 4px;">' + sba__( 'Generic Filename', 'Generic Filename' ) + '</span>';
                }

                var suggestionsHtml = '';
                if (item.suggestions && item.suggestions.length > 0) {
                    suggestionsHtml = '<div style="font-size: 11px; color: #475569; margin-top: 4px;">' +
                        '<strong>Suggestion:</strong> ' + sbaEscapeHtml(item.suggestions.join(' ')) +
                    '</div>';
                }

                var altDisplay = altText ? '<code>"' + altText + '"</code>' : '<em style="color: #94a3b8;">None</em>';

                var rowHtml = '<tr>' +
                    '<td style="text-align: center;"><div class="sba-media-thumb-placeholder"><span class="dashicons dashicons-universal-access"></span></div></td>' +
                    '<td>' +
                        '<strong class="sba-table-name">' + safeName + '</strong>' +
                        (safePath ? '<div class="sba-table-source sba-media-file-path" title="' + sba__( 'Relative path in uploads directory', 'Relative path in uploads directory' ) + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>' : '') +
                        '<div style="margin-top: 4px;">' + issuesBadges + '</div>' +
                        suggestionsHtml +
                    '</td>' +
                    '<td><div style="font-size: 12px;"><strong>Alt:</strong> ' + altDisplay + '</div></td>' +
                    '<td><strong>' + safeSize + '</strong><div class="sba-table-source">' + safeDim + '</div></td>' +
                    '<td style="text-align: right;">' +
                        '<a href="' + editUrl + '" target="_blank" class="button button-small sba-btn-edit-wp"><span class="dashicons dashicons-edit"></span> ' + sba__( 'Add Alt in WP', 'Add Alt in WP' ) + '</a>' +
                    '</td>' +
                '</tr>';
                $tbody.append(rowHtml);
            });
        }

        function renderDiscrepanciesView($tbody, searchQuery) {
            // Render missing originals
            if (discrepanciesData.missing_originals && discrepanciesData.missing_originals.length > 0) {
                $.each(discrepanciesData.missing_originals, function(i, item) {
                    var safeId = parseInt(item.attachment_id, 10);
                    var safeTitle = sbaEscapeHtml(item.title || ('Attachment #' + safeId));
                    var safePath = sbaEscapeHtml(item.expected_path || item.relative_path || '');

                    var rowHtml = '<tr>' +
                        '<td style="text-align: center;"><div class="sba-media-thumb-placeholder" style="background: #fef2f2; color: #dc2626;"><span class="dashicons dashicons-warning"></span></div></td>' +
                        '<td>' +
                            '<strong class="sba-table-name" style="color: #dc2626;">' + safeTitle + '</strong>' +
                            '<div class="sba-table-source sba-media-file-path">Expected: <code>' + safePath + '</code></div>' +
                        '</td>' +
                        '<td><span class="sba-badge-sub sba-badge-red">' + sba__( 'Missing Original File', 'Missing Original File' ) + '</span></td>' +
                        '<td><span style="color: #94a3b8;">0 B (File absent)</span></td>' +
                        '<td style="text-align: right;"><a href="post.php?post=' + safeId + '&action=edit" target="_blank" class="button button-small"><span class="dashicons dashicons-edit"></span> Edit Record</a></td>' +
                    '</tr>';
                    $tbody.append(rowHtml);
                });
            }

            // Render missing subsizes
            if (discrepanciesData.missing_subsizes && discrepanciesData.missing_subsizes.length > 0) {
                $.each(discrepanciesData.missing_subsizes, function(i, item) {
                    var safeId = parseInt(item.attachment_id, 10);
                    var safeKey = sbaEscapeHtml(item.size_key || '');
                    var safeFile = sbaEscapeHtml(item.subfile || '');

                    var rowHtml = '<tr>' +
                        '<td style="text-align: center;"><div class="sba-media-thumb-placeholder" style="background: #fffbeb; color: #d97706;"><span class="dashicons dashicons-warning"></span></div></td>' +
                        '<td>' +
                            '<strong class="sba-table-name">' + safeFile + '</strong>' +
                            '<div class="sba-table-source">Attachment #' + safeId + ' &bull; Subsize key: <code>' + safeKey + '</code></div>' +
                        '</td>' +
                        '<td><span class="sba-badge-sub sba-badge-orange">' + sba__( 'Missing Thumbnail Sub-size', 'Missing Thumbnail Sub-size' ) + '</span></td>' +
                        '<td><span style="color: #94a3b8;">Missing</span></td>' +
                        '<td style="text-align: right;"><a href="post.php?post=' + safeId + '&action=edit" target="_blank" class="button button-small"><span class="dashicons dashicons-edit"></span> View</a></td>' +
                    '</tr>';
                    $tbody.append(rowHtml);
                });
            }
        }

        // 1-Click Optimize Image Handler
        $(document).on('click', '.sba-btn-optimize-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);
            var targetW = parseInt($btn.data('w'), 10) || 1920;
            var targetH = parseInt($btn.data('h'), 10) || 1080;

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Optimizing...', 'Optimizing...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_optimize_image',
                _ajax_nonce: sbaNonce,
                attachment_id: attId,
                max_width: targetW,
                max_height: targetH,
                quality: 82
            }, function(response) {
                if (response.success && response.data) {
                    showNotice(response.data.message || sba__( 'Image optimized successfully!', 'Image optimized successfully!' ), false);
                    $('#sba-rescan-media-btn').trigger('click');
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> ' + sba__( 'Optimize & WebP', 'Optimize & WebP' ));
                    showNotice(response.data || sba__( 'Failed to optimize image.', 'Failed to optimize image.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> ' + sba__( 'Optimize & WebP', 'Optimize & WebP' ));
                showNotice(sba__( 'Server error while optimizing image.', 'Server error while optimizing image.' ), true);
            });
        });

        // 1-Click Rollback Optimization Handler
        $(document).on('click', '.sba-btn-rollback-item', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__( 'Restoring...', 'Restoring...' ));

            $.post(sbaAjaxUrl, {
                action: 'sba_rollback_image_optimization',
                _ajax_nonce: sbaNonce,
                attachment_id: attId
            }, function(response) {
                if (response.success && response.data) {
                    showNotice(response.data.message || sba__( 'Original image restored successfully!', 'Original image restored successfully!' ), false);
                    $('#sba-rescan-media-btn').trigger('click');
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> ' + sba__( 'Rollback', 'Rollback' ));
                    showNotice(response.data || sba__( 'Failed to rollback image.', 'Failed to rollback image.' ), true);
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> ' + sba__( 'Rollback', 'Rollback' ));
                showNotice(sba__( 'Server error while rolling back image.', 'Server error while rolling back image.' ), true);
            });
        });
    });
})(jQuery);
