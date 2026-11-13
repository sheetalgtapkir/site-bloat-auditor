/**
 * Site Bloat & Storage Auditor - Admin Dashboard JavaScript
 *
 * Handles tab navigation, asynchronous AJAX audits, modal interactions,
 * thumbnail deletions, and quarantine lifecycle.
 *
 * @package Site_Bloat_Auditor
 */
(function ($) {
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

    $(function () {
        var allTables = [];
        var allPlugins = [];
        var selectedPluginFiles = [];
        var activePluginBulkProcessing = false;
        var allMedia = [];
        var unusedMedia = [];
        var oversizedMedia = [];
        var optimizedMedia = [];
        var duplicatesData = [];
        var accessibilityData = null;
        var discrepanciesData = null;
        var quarantinedMedia = (sbaData.quarantined_files && Array.isArray(sbaData.quarantined_files)) ? sbaData.quarantined_files : [];
        var selectedQuarantineIds = [];
        var selectedMediaIds = [];
        var activeBulkProcessing = false;
        var currentFilter = 'all';
        var currentPluginFilter = 'all';
        var pluginPageSize = 25;
        var pluginCurrentPage = 1;
        var currentMediaFilter = 'all';
        var totalMediaSizeMb = 0;
        var reclaimableSpaceMb = 0;
        var potentialSavingsMb = 0;
        var activeOptimizingIds = {};
        var activeRollbackIds = {};
        var mediaScanDone = false;
        var pendingDropTable = null;
        var pendingCleanupAction = null; // 'transients', 'revisions', 'bulk_revisions'
        var pendingCleanupPayload = null;
        var currentDbSizeFormatted = $('#sba-stat-db-total-size').text().trim() || '0 MB';
        var currentDbSizeBytes = 0;
        var currentTransientsCount = parseInt($('#sba-stat-transients').text(), 10) || 0;
        var currentTransientsBytes = 0;
        var currentTransientsFormatted = '0 B';
        var currentCleanableRevisionsCount = 0;
        var currentCleanableRevisionsBytes = 0;
        var currentCleanableRevisionsFormatted = '0 B';
        var currentRecoverableBytes = 0;
        var currentRecoverableFormatted = $('#sba-stat-db-recoverable-size').text().trim() || '0 B';
        var currentEligibleItemsCount = 0;

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

        // Section C: Structured After-Cleanup Reporting Banner
        function showDatabaseCleanupReport(report) {
            var isErr = (report.errors > 0);
            var spaceNote = report.isEstimated ?
                sba__('Estimated space freed inside database pages. (In MySQL InnoDB, row deletion marks space for immediate reuse by new WordPress data; physical disk file shrinkage requires table defragmentation/OPTIMIZE).', 'Estimated space freed inside database pages. (In MySQL InnoDB, row deletion marks space for immediate reuse; physical disk file shrinkage requires table defragmentation/OPTIMIZE).') :
                sba__('Measured storage released on disk.', 'Measured storage released on disk.');

            var contentHtml = '<div class="sba-cleanup-result-banner ' + (isErr ? 'is-error' : '') + '">' +
                '<div class="sba-cleanup-result-content">' +
                '<strong><span class="dashicons dashicons-yes-alt" style="color: #16a34a; vertical-align: text-bottom;"></span> ' + sbaEscapeHtml(report.message) + '</strong>' +
                '<div class="sba-cleanup-result-meta">' +
                '<span>' + sba__('Records removed: ', 'Records removed: ') + '<strong>' + report.count + '</strong></span> &bull; ' +
                '<span>' + sba__('Space recovered: ', 'Space recovered: ') + '<strong>' + sbaEscapeHtml(report.spaceRecovered) + '</strong> (' + (report.isEstimated ? sba__('Estimated', 'Estimated') : sba__('Measured', 'Measured')) + ')</span> &bull; ' +
                '<span>' + sba__('Errors: ', 'Errors: ') + '<strong>' + (report.errors || 0) + '</strong></span> &bull; ' +
                '<span>' + sba__('Skipped: ', 'Skipped: ') + '<strong>' + (report.skipped || 0) + '</strong></span>' +
                '<div style="margin-top: 4px; font-size: 11.5px; color: #64748b;">' + spaceNote + '</div>' +
                '</div>' +
                '</div>' +
                '<button type="button" class="button button-secondary sba-btn-refresh-audit" style="display: inline-flex; align-items: center; gap: 4px;">' +
                '<span class="dashicons dashicons-update"></span> ' + sba__('Run Fresh DB Audit', 'Run Fresh DB Audit') +
                '</button>' +
                '</div>';

            var $container = $('#sba-notice-database');
            if (!$container.length) $container = $('#sba-notice-container');
            $('#sba-notice-container, .sba-tab-notice-container').not($container).empty().hide();
            $container.html(contentHtml).fadeIn(150);
        }

        // Trigger fresh DB audit from notice banner
        $(document).on('click', '.sba-btn-refresh-audit', function (e) {
            e.preventDefault();
            $('#sba-run-scan').trigger('click');
        });

        // Tab-Isolated Toast Notice Helper
        function showNotice(message, isError, targetTab) {
            var noticeClass = isError ? 'notice-error' : 'notice-success';
            var dismissText = sba__('Dismiss this notice.', 'Dismiss this notice.');
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
        $(document).on('click', '.sba-dashboard-wrap .notice-dismiss', function (e) {
            e.preventDefault();
            $(this).closest('.notice').fadeOut(150, function () {
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

        // Format Bytes to human readable string
        function formatBytes(bytes) {
            bytes = Number(bytes || 0);
            if (bytes >= 1073741824) {
                return (bytes / 1073741824).toFixed(2) + ' GB';
            }
            if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2) + ' MB';
            }
            if (bytes >= 1024) {
                return (bytes / 1024).toFixed(2) + ' KB';
            }
            return bytes + ' B';
        }

        // =====================================================================
        // MAIN 3-TAB NAVIGATION
        // =====================================================================
        $('.sba-main-tab-btn').on('click', function (e) {
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
        var lastDbScanFailed = false;

        function renderTables() {
            var searchQuery = $('#sba-table-search').val().toLowerCase().trim();
            var $tbody = $('#sba-tables-body');
            $tbody.empty();

            var filtered = allTables.filter(function (t) {
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

            var orphanedCount = allTables.filter(function (t) { return t.status === 'orphaned'; }).length;
            if (currentFilter === 'orphaned' || (currentFilter === 'all' && orphanedCount > 0)) {
                $('#sba-orphaned-warning-banner').show();
            } else {
                $('#sba-orphaned-warning-banner').hide();
            }

            if (filtered.length === 0) {
                var $emptyState = $('#sba-empty-state');
                var $emptyIcon  = $('#sba-empty-icon');
                var $emptyTitle = $('#sba-empty-title');
                var $emptyDesc  = $('#sba-empty-desc');

                if (lastDbScanFailed) {
                    $emptyIcon.attr('class', 'dashicons dashicons-warning').css('color', '#dc2626');
                    $emptyTitle.text(sba__('Scan Incomplete: Database tables could not be inspected', 'Scan Incomplete: Database tables could not be inspected'));
                    $emptyDesc.text(sba__('A database error or permission limitation prevented completing the audit. Please retry.', 'A database error or permission limitation prevented completing the audit. Please retry.')).show();
                } else if (allTables.length === 0) {
                    $emptyIcon.attr('class', 'dashicons dashicons-info').css('color', '#d97706');
                    $emptyTitle.text(sba__('No tables found in database', 'No tables found in database'));
                    $emptyDesc.text(sba__('0 database tables were found matching the WordPress prefix.', '0 database tables were found matching the WordPress prefix.')).show();
                } else {
                    $emptyIcon.attr('class', 'dashicons dashicons-yes-alt').css('color', '#16a34a');
                    $emptyTitle.text(sba__('No tables match the current filter', 'No tables match the current filter'));
                    $emptyDesc.text(sba__('Try selecting "All Tables" or clearing the search box to view database tables.', 'Try selecting "All Tables" or clearing the search box to view database tables.')).show();
                }
                $emptyState.show();
                return;
            }
            $('#sba-empty-state').hide();

            $.each(filtered, function (index, tbl) {
                var isCore = (tbl.status === 'core');
                var isActive = (tbl.status === 'active_plugin' || tbl.status === 'active');
                var rowClass = 'sba-row-' + sbaEscapeHtml(tbl.status);
                var badgeHtml = '';
                var subtextHtml = '';
                var actionHtml = '';

                var likelyPlugin = tbl.likely_plugin || tbl.source || sba__('Unknown / Custom Table', 'Unknown / Custom Table');
                var safeLikelyPlugin = sbaEscapeHtml(likelyPlugin);
                var safeTableName = sbaEscapeHtml(tbl.name);

                if (isCore) {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-core"><span class="dashicons dashicons-wordpress"></span> ' + sba__('CORE', 'CORE') + '</span>';
                    subtextHtml = '<div class="sba-table-subtext"><span class="sba-belongs-to">' + sba__('WordPress Core Table', 'WordPress Core Table') + '</span></div>';
                    actionHtml = '<span style="color: #94a3b8; font-size: 12px; font-weight: 500;">' + sba__('Protected', 'Protected') + '</span>';
                } else if (isActive) {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('ACTIVE PLUGIN', 'ACTIVE PLUGIN') + '</span>';
                    subtextHtml = '<div class="sba-table-subtext">' +
                        '<span class="sba-belongs-to">' + sba__('Belongs to: ', 'Belongs to: ') + '<strong>' + safeLikelyPlugin + '</strong></span>' +
                        '<span class="sba-plugin-state-badge sba-state-active" title="' + sba__('Plugin is currently active', 'Plugin is currently active') + '"><span class="dashicons dashicons-yes"></span> ' + sba__('Active', 'Active') + '</span>' +
                        '</div>';
                    actionHtml = '<span style="color: #15803d; font-size: 12px; font-weight: 600;">' + sba__('Active', 'Active') + '</span>';
                } else {
                    badgeHtml = '<span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-warning"></span> ' + sba__('ORPHANED', 'ORPHANED') + '</span>';
                    subtextHtml = '<div class="sba-table-subtext">' +
                        '<span class="sba-belongs-to">' + sba__('Belongs to: ', 'Belongs to: ') + '<strong>' + safeLikelyPlugin + '</strong></span>' +
                        '<span class="sba-plugin-state-badge sba-state-inactive-deleted" title="' + sba__('Plugin is deactivated or uninstalled from this site', 'Plugin is deactivated or uninstalled from this site') + '">' +
                        '<span class="dashicons dashicons-dismiss"></span> ' + sba__('Plugin is deleted/inactive', 'Plugin is deleted/inactive') +
                        '</span>' +
                        '</div>';
                    actionHtml = '<button type="button" class="button button-small sba-drop-btn" data-table="' + safeTableName + '" data-rows="' + parseInt(tbl.row_count, 10) + '" data-size="' + parseFloat(tbl.size_kb) + '" data-plugin="' + safeLikelyPlugin + '">' +
                        '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Drop Table', 'Drop Table') + '</span>' +
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
            var core = allTables.filter(function (t) { return t.status === 'core'; }).length;
            var active = allTables.filter(function (t) { return t.status === 'active_plugin' || t.status === 'active'; }).length;
            var orphaned = allTables.filter(function (t) { return t.status === 'orphaned'; }).length;

            $('#sba-count-all').text(total);
            $('#sba-count-core').text(core);
            $('#sba-count-active').text(active);
            $('#sba-count-orphaned').text(orphaned);

            $('#sba-stat-total-tables').text(total);
            $('#sba-stat-tables-meta').text(core + ' Core • ' + active + ' Active • ' + orphaned + ' Orphaned');
        }

        // Run Quick DB Audit
        $('#sba-run-scan').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $btn.find('.sba-btn-label').text(sba__('Auditing Database...', 'Auditing Database...'));
            $('#sba-notice-container').hide();

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_database',
                _ajax_nonce: sbaNonce
            }, function (response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $btn.find('.sba-btn-label').text(sba__('Re-run DB Audit', 'Re-run DB Audit'));

                if (response.success) {
                    lastDbScanFailed = false;
                    var d = response.data;
                    allTables = d.tables || [];

                    if (d.database_size_fmt) {
                        $('#sba-stat-db-total-size').text(d.database_size_fmt);
                        currentDbSizeFormatted = d.database_size_fmt;
                    }
                    if (d.recoverable_fmt) {
                        $('#sba-stat-db-recoverable-size').text(d.recoverable_fmt);
                        currentRecoverableFormatted = d.recoverable_fmt;
                    }
                    if (d.eligible_items_count !== undefined) {
                        currentEligibleItemsCount = parseInt(d.eligible_items_count, 10) || 0;
                        var itemLabel = currentEligibleItemsCount === 1 ? sba__('1 item', '1 item') : currentEligibleItemsCount + ' ' + sba__('items', 'items');
                        $('#sba-stat-db-eligible-items').text(itemLabel);
                        var eligibleMeta = sba__('Eligible for Review/Cleanup: ', 'Eligible for Review/Cleanup: ') +
                            (d.revisions_cleanable_count || 0) + ' ' + sba__('drafts', 'drafts') + ' • ' +
                            (d.transients_count || 0) + ' ' + sba__('temporary', 'temporary') + ' • ' +
                            (d.orphaned_count || 0) + ' ' + sba__('orphaned', 'orphaned');
                        $('#sba-stat-db-eligible-meta').text(eligibleMeta);
                    }

                    currentTransientsCount = parseInt(d.transients_count, 10) || 0;
                    currentTransientsBytes = parseInt(d.transients_bytes, 10) || 0;
                    currentTransientsFormatted = d.transients_fmt || formatSize(currentTransientsBytes / 1024);

                    currentCleanableRevisionsCount = parseInt(d.revisions_cleanable_count, 10) || 0;
                    currentCleanableRevisionsBytes = parseInt(d.revisions_cleanable_bytes, 10) || 0;
                    currentCleanableRevisionsFormatted = formatSize((d.revisions_cleanable_kb || 0));

                    $('#sba-stat-autoload-size').text(formatSize(d.autoload_size_kb));
                    $('#sba-stat-autoload-count').text(formatNumber(d.autoload_count) + ' ' + sba__('autoload rows', 'autoload rows'));
                    $('#sba-stat-revisions').text(formatNumber(d.revisions_count));
                    var revBytes = Number(d.revisions_bytes || 0);
                    var revKb = Number(d.revisions_size_kb || 0);
                    var relBytes = Number(d.revisions_releasable_bytes !== undefined ? d.revisions_releasable_bytes : revBytes);
                    var relKb = Number(d.revisions_releasable_kb !== undefined ? d.revisions_releasable_kb : revKb);
                    $('#sba-stat-revisions-size').text(formatSize(revKb) + ' (' + formatNumber(revBytes) + ' bytes)');
                    $('#sba-stat-revisions-releasable').text(formatSize(relKb) + ' (' + formatNumber(relBytes) + ' bytes)');
                    $('#sba-stat-transients').text(formatNumber(d.transients_count));
                    $('#sba-stat-transients-meta').text(formatNumber(d.transients_count) + ' ' + sba__('outdated rows', 'outdated rows') + ' (' + (d.transients_fmt || '0 B') + ')');

                    // Update Scan State Indicator Bar
                    var scanTime = d.scanned_at_fmt || (new Date()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    $('#sba-db-last-scan-time').text(scanTime);
                    $('#sba-db-scan-status-icon').attr('class', 'dashicons dashicons-yes-alt sba-scan-status-icon').css('color', '#16a34a');
                    if (allTables.length > 0) {
                        $('#sba-db-scan-status-text').text(
                            sba__('Scan Complete — All ', 'Scan Complete — All ') + allTables.length +
                            sba__(' tables analyzed (', ' tables analyzed (') + (d.core_count || 0) + sba__(' Core, ', ' Core, ') +
                            (d.active_plugin_count || 0) + sba__(' Active Plugins, ', ' Active Plugins, ') +
                            (d.orphaned_count || 0) + sba__(' Orphaned).', ' Orphaned).')
                        );
                    } else {
                        $('#sba-db-scan-status-text').text(sba__('Scan Complete — 0 tables found matching prefix.', 'Scan Complete — 0 tables found matching prefix.'));
                    }
                    $('#sba-db-scan-status-bar').show();

                    updateCounts();
                    renderTables();

                    $('#sba-results').fadeIn(200);
                } else {
                    lastDbScanFailed = true;
                    $('#sba-db-scan-status-icon').attr('class', 'dashicons dashicons-warning sba-scan-status-icon').css('color', '#dc2626');
                    $('#sba-db-scan-status-text').text(sba__('Scan Incomplete — Failed to analyze database tables.', 'Scan Incomplete — Failed to analyze database tables.'));
                    $('#sba-db-last-scan-time').text(sba__('Scan Failed', 'Scan Failed'));
                    $('#sba-db-scan-status-bar').show();
                    renderTables();
                    $('#sba-results').fadeIn(200);
                    showNotice(response.data || sba__('Scan failed.', 'Scan failed.'), true, 'database');
                }
            }).fail(function () {
                lastDbScanFailed = true;
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $btn.find('.sba-btn-label').text(sba__('Run Quick DB Audit', 'Run Quick DB Audit'));
                $('#sba-db-scan-status-icon').attr('class', 'dashicons dashicons-warning sba-scan-status-icon').css('color', '#dc2626');
                $('#sba-db-scan-status-text').text(sba__('Scan Incomplete — Server communication error while auditing database.', 'Scan Incomplete — Server communication error while auditing database.'));
                $('#sba-db-last-scan-time').text(sba__('Scan Failed', 'Scan Failed'));
                $('#sba-db-scan-status-bar').show();
                renderTables();
                $('#sba-results').fadeIn(200);
                showNotice(sba__('Server communication error while scanning.', 'Server communication error while scanning.'), true, 'database');
            });
        });

        // Table Tabs
        $('.sba-table-tab-btn').on('click', function (e) {
            e.preventDefault();
            $('.sba-table-tab-btn').removeClass('active');
            $(this).addClass('active');
            currentFilter = $(this).data('tab');
            renderTables();
        });

        // Table Search
        $('#sba-table-search').on('input', function () {
            renderTables();
        });

        // Toggle Tables Breakdown Button
        $('#sba-toggle-tables-btn').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $wrap = $('#sba-tables-content-wrap');
            var isExpanded = ($btn.attr('aria-expanded') === 'true');

            if (isExpanded) {
                $wrap.slideUp(200);
                $btn.attr('aria-expanded', 'false');
                $btn.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                var countText = allTables.length ? ' (' + allTables.length + ')' : '';
                $btn.find('.sba-toggle-tables-text').text(sba__('View Tables Breakdown', 'View Tables Breakdown') + countText);
            } else {
                $wrap.slideDown(200);
                $btn.attr('aria-expanded', 'true');
                $btn.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $btn.find('.sba-toggle-tables-text').text(sba__('Collapse Tables', 'Collapse Tables'));
            }
        });

        // Click on Database Tables Card to view/expand table breakdown
        $('#sba-card-tables-overview').on('click', function (e) {
            if ($(e.target).closest('.sba-info-icon-btn').length) {
                return;
            }
            e.preventDefault();
            var $btn = $('#sba-toggle-tables-btn');
            var $wrap = $('#sba-tables-content-wrap');
            if ($btn.attr('aria-expanded') !== 'true') {
                $wrap.slideDown(200);
                $btn.attr('aria-expanded', 'true');
                $btn.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $btn.find('.sba-toggle-tables-text').text(sba__('Collapse Tables', 'Collapse Tables'));
            }
            var $panel = $('#sba-tables-section-panel');
            if ($panel.length) {
                $('html, body').animate({
                    scrollTop: $panel.offset().top - 40
                }, 300);
            }
        });

        // =====================================================================
        // Accessible Metric Information Dialog System
        // =====================================================================
        var sbaMetricInfo = {
            db_total_size: {
                title: sba__('Current Database Size', 'Current Database Size'),
                meaning: sba__('The total measured storage footprint of all tables with your WordPress prefix inside the MySQL database, including both table rows and index data.', 'The total measured storage footprint of all tables with your WordPress prefix inside the MySQL database, including both table rows and index data.'),
                importance: sba__('A bloated database inflates backup sizes, slows SQL queries, lengthens migrations, and risks exceeding hosting disk limits.', 'A bloated database inflates backup sizes, slows SQL queries, lengthens migrations, and risks exceeding hosting disk limits.'),
                measurement: sba__('Queried directly from MySQL information_schema.TABLES by measuring the exact sum of data_length (row contents) and index_length (indexes) across all tables matching your site prefix.', 'Queried directly from MySQL information_schema.TABLES by measuring the exact sum of data_length (row contents) and index_length (indexes) across all tables matching your site prefix.'),
                proof: [
                    { tag: 'proves', text: sba__('The exact measured disk allocation reported by MySQL for all WordPress tables.', 'The exact measured disk allocation reported by MySQL for all WordPress tables.') },
                    { tag: 'not', text: sba__('Does NOT prove that all space is active data; InnoDB tablespaces often contain free internal pages from deleted rows.', 'Does NOT prove that all space is active data; InnoDB tablespaces often contain free internal pages from deleted rows.') }
                ],
                cleanup: sba__('Cleaning revisions and transients frees page space inside existing tables for reuse by future WordPress data. To shrink the physical .ibd file on the server disk, an OPTIMIZE TABLE operation is required.', 'Cleaning revisions and transients frees page space inside existing tables for reuse by future WordPress data. To shrink the physical .ibd file on the server disk, an OPTIMIZE TABLE operation is required.')
            },
            db_recoverable_space: {
                title: sba__('Estimated Recoverable Space', 'Estimated Recoverable Space'),
                meaning: sba__('A conservative estimate of internal database space currently occupied by safe cleanup candidates: old post revision drafts beyond retention limits, confirmed expired transients, and droppable orphaned tables.', 'A conservative estimate of internal database space currently occupied by safe cleanup candidates: old post revision drafts beyond retention limits, confirmed expired transients, and droppable orphaned tables.'),
                importance: sba__('Provides a realistic, safe projection of database storage that can be reclaimed without risking active site content or configuration.', 'Provides a realistic, safe projection of database storage that can be reclaimed without risking active site content or configuration.'),
                measurement: sba__('Calculated by summing the raw byte volume of cleanable revisions (older than 3 edits per post), confirmed expired transients in wp_options, and total table sizes of confirmed orphaned tables.', 'Calculated by summing the raw byte volume of cleanable revisions (older than 3 edits per post), confirmed expired transients in wp_options, and total table sizes of confirmed orphaned tables.'),
                proof: [
                    { tag: 'proves', text: sba__('The exact volume of eligible candidate rows and droppable tables identified for cleanup.', 'The exact volume of eligible candidate rows and droppable tables identified for cleanup.') },
                    { tag: 'not', text: sba__('Does NOT guarantee immediate physical server disk shrinkage for row deletions, because MySQL InnoDB reallocates freed page space internally rather than shrinking files. Dropping orphaned tables DOES immediately free physical disk files.', 'Does NOT guarantee immediate physical server disk shrinkage for row deletions, because MySQL InnoDB reallocates freed page space internally rather than shrinking files. Dropping orphaned tables DOES immediately free physical disk files.') }
                ],
                cleanup: sba__('Eligible candidate rows are purged from wp_posts, wp_postmeta, and wp_options. Freed space inside database pages is immediately reused for new WordPress data. Dropping orphaned tables permanently removes their .ibd files.', 'Eligible candidate rows are purged from wp_posts, wp_postmeta, and wp_options. Freed space inside database pages is immediately reused for new WordPress data. Dropping orphaned tables permanently removes their .ibd files.')
            },
            db_eligible_items: {
                title: sba__('Items Requiring Review', 'Items Requiring Review'),
                meaning: sba__('The total count of distinct database records and leftover tables identified as candidates for cleanup, pruning, or manual review.', 'The total count of distinct database records and leftover tables identified as candidates for cleanup, pruning, or manual review.'),
                importance: sba__('Breaks down database bloat into clear, reviewable units so administrators know exactly what records will be affected before confirming cleanup.', 'Breaks down database bloat into clear, reviewable units so administrators know exactly what records will be affected before confirming cleanup.'),
                measurement: sba__('Sum of old revision drafts beyond the safe 3-revision retention threshold, confirmed expired transient rows, and detected orphaned tables from deleted plugins.', 'Sum of old revision drafts beyond the safe 3-revision retention threshold, confirmed expired transient rows, and detected orphaned tables from deleted plugins.'),
                proof: [
                    { tag: 'proves', text: sba__('The exact count of database records that meet strict automated safety criteria for cleanup.', 'The exact count of database records that meet strict automated safety criteria for cleanup.') },
                    { tag: 'not', text: sba__('Does NOT include active transients, recent drafts, or active plugin tables, which are permanently protected.', 'Does NOT include active transients, recent drafts, or active plugin tables, which are permanently protected.') }
                ],
                cleanup: sba__('Revisions and transients can be safely cleaned via 1-click actions. Orphaned tables require individual inspection and manual confirmation with a backup download option.', 'Revisions and transients can be safely cleaned via 1-click actions. Orphaned tables require individual inspection and manual confirmation with a backup download option.')
            },
            autoload_data: {
                title: sba__('Data Loaded on Every Page', 'Data Loaded on Every Page'),
                meaning: sba__('Configuration settings, options, and plugin data marked with autoload = "yes" in the wp_options table, loaded into server memory on every single web request.', 'Configuration settings, options, and plugin data marked with autoload = "yes" in the wp_options table, loaded into server memory on every single web request.'),
                importance: sba__('Directly impacts PHP memory footprint and response time (TTFB) for every page view. Autoload sizes above 800 KB slow down server response and waste RAM on high-traffic sites.', 'Directly impacts PHP memory footprint and response time (TTFB) for every page view. Autoload sizes above 800 KB slow down server response and waste RAM on high-traffic sites.'),
                measurement: sba__('Measured by querying wp_options WHERE autoload = "yes" (or "on") for row count and measuring byte lengths of option names and option values.', 'Measured by querying wp_options WHERE autoload = "yes" (or "on") for row count and measuring byte lengths of option names and option values.'),
                proof: [
                    { tag: 'proves', text: sba__('The exact volume of options data loaded into PHP server RAM on every WordPress request.', 'The exact volume of options data loaded into PHP server RAM on every WordPress request.') },
                    { tag: 'not', text: sba__('Autoload size is a request-time MEMORY LOAD metric, NOT hard drive disk space. Reducing autoload saves server RAM and CPU, not disk storage.', 'Autoload size is a request-time MEMORY LOAD metric, NOT hard drive disk space. Reducing autoload saves server RAM and CPU, not disk storage.') }
                ],
                cleanup: sba__('Expired transients and obsolete plugin options configured to autoload are purged, reducing memory allocation per PHP worker and accelerating site bootstrap time.', 'Expired transients and obsolete plugin options configured to autoload are purged, reducing memory allocation per PHP worker and accelerating site bootstrap time.')
            },
            revisions: {
                title: sba__('Saved Content Versions', 'Saved Content Versions'),
                meaning: sba__('Historical copies of posts and pages saved in wp_posts and wp_postmeta every time an author edits or drafts content.', 'Historical copies of posts and pages saved in wp_posts and wp_postmeta every time an author edits or drafts content.'),
                importance: sba__('Accumulates over time to thousands of rows, inflating SQL backup sizes, fragmenting posts indexes, and slowing down wp-admin search and post queries.', 'Accumulates over time to thousands of rows, inflating SQL backup sizes, fragmenting posts indexes, and slowing down wp-admin search and post queries.'),
                measurement: sba__('Counted from wp_posts WHERE post_type = "revision". Cleanable revisions are calculated by grouping by parent post and measuring older revisions beyond the 3 most recent edits.', 'Counted from wp_posts WHERE post_type = "revision". Cleanable revisions are calculated by grouping by parent post and measuring older revisions beyond the 3 most recent edits.'),
                proof: [
                    { tag: 'proves', text: sba__('The exact count and measured raw bytes occupied by historical revisions in the database.', 'The exact count and measured raw bytes occupied by historical revisions in the database.') },
                    { tag: 'not', text: sba__('Does NOT slow down individual frontend page views (WordPress queries published posts directly by ID or slug), but does slow backend queries and backups.', 'Does NOT slow down individual frontend page views (WordPress queries published posts directly by ID or slug), but does slow backend queries and backups.') }
                ],
                cleanup: sba__('Site Bloat Auditor permanently preserves the 3 most recent edits per post and all published content. Only older revisions and orphaned revision records are safely removed.', 'Site Bloat Auditor permanently preserves the 3 most recent edits per post and all published content. Only older revisions and orphaned revision records are safely removed.')
            },
            transients: {
                title: sba__('Expired Temporary Data', 'Expired Temporary Data'),
                meaning: sba__('Cached data stored temporarily in wp_options (e.g. remote API responses, update checks, feeds) that have passed their set expiration timestamp.', 'Cached data stored temporarily in wp_options (e.g. remote API responses, update checks, feeds) that have passed their set expiration timestamp.'),
                importance: sba__('WordPress does not automatically delete expired transients until they are re-queried. Over time, abandoned expired transients cause database clutter.', 'WordPress does not automatically delete expired transients until they are re-queried. Over time, abandoned expired transients cause database clutter.'),
                measurement: sba__('Identified by querying wp_options for _transient_timeout_% where the timeout value is less than UNIX_TIMESTAMP(). Only rows strictly past expiration are counted.', 'Identified by querying wp_options for _transient_timeout_% where the timeout value is less than UNIX_TIMESTAMP(). Only rows strictly past expiration are counted.'),
                proof: [
                    { tag: 'proves', text: sba__('These transient cache rows are confirmed to be expired and safe to remove.', 'These transient cache rows are confirmed to be expired and safe to remove.') },
                    { tag: 'not', text: sba__('Does NOT include transients without expiration timestamps or active transients that are still valid.', 'Does NOT include transients without expiration timestamps or active transients that are still valid.') }
                ],
                cleanup: sba__('Confirmed expired transient records and their timeout options are permanently deleted from wp_options. Active cache items and permanent site settings are completely unaffected.', 'Confirmed expired transient records and their timeout options are permanently deleted from wp_options. Active cache items and permanent site settings are completely unaffected.')
            },
            tables_breakdown: {
                title: sba__('Database Tables & Orphaned Detector', 'Database Tables & Orphaned Detector'),
                meaning: sba__('A complete categorization of all database tables into WordPress Core tables, active plugin tables, and leftover orphaned tables from deleted plugins.', 'A complete categorization of all database tables into WordPress Core tables, active plugin tables, and leftover orphaned tables from deleted plugins.'),
                importance: sba__('Many plugins leave behind large custom tables when uninstalled, causing unnecessary disk bloat and slowing down full database backups.', 'Many plugins leave behind large custom tables when uninstalled, causing unnecessary disk bloat and slowing down full database backups.'),
                measurement: sba__('Every table with your WordPress prefix is checked against the WordPress Core schema and signatures of active plugins.', 'Every table with your WordPress prefix is checked against the WordPress Core schema and signatures of active plugins.'),
                proof: [
                    { tag: 'proves', text: sba__('Which tables are confirmed Core or Active, and which have no registered active plugin.', 'Which tables are confirmed Core or Active, and which have no registered active plugin.') },
                    { tag: 'not', text: sba__('Orphaned classification indicates no active plugin claims the table, but custom code might use it. Therefore, ORPHANED TABLES ARE NEVER DELETED AUTOMATICALLY.', 'Orphaned classification indicates no active plugin claims the table, but custom code might use it. Therefore, ORPHANED TABLES ARE NEVER DELETED AUTOMATICALLY.') }
                ],
                cleanup: sba__('Orphaned tables are never removed in bulk. Users can inspect individual table sizes, download an isolated SQL backup of that table, and manually confirm dropping it. Dropping immediately releases its .ibd file on disk.', 'Orphaned tables are never removed in bulk. Users can inspect individual table sizes, download an isolated SQL backup of that table, and manually confirm dropping it. Dropping immediately releases its .ibd file on disk.')
            }
        };

        var lastActiveInfoTrigger = null;

        $(document).on('click', '.sba-info-icon-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(this);
            var metricKey = $btn.data('metric');
            var info = sbaMetricInfo[metricKey];

            if (!info) return;

            lastActiveInfoTrigger = this;
            $btn.attr('aria-expanded', 'true');

            $('#sba-metric-info-heading').text(info.title);
            $('#sba-info-meaning').text(info.meaning);
            $('#sba-info-importance').text(info.importance);
            $('#sba-info-measurement').text(info.measurement);

            var proofHtml = '';
            $.each(info.proof, function (_, p) {
                var isProves = (p.tag === 'proves');
                var tagClass = isProves ? 'sba-info-proof-tag-proves' : 'sba-info-proof-tag-not';
                var tagLabel = isProves ? sba__('Proves', 'Proves') : sba__('Does Not Prove', 'Does Not Prove');
                proofHtml += '<div class="sba-info-proof-row">' +
                    '<span class="' + tagClass + '">' + tagLabel + '</span>' +
                    '<span>' + sbaEscapeHtml(p.text) + '</span>' +
                    '</div>';
            });
            $('#sba-info-proof').html(proofHtml);
            $('#sba-info-cleanup').text(info.cleanup);

            var $modal = $('#sba-metric-info-modal');
            $modal.fadeIn(150, function () {
                $modal.find('.sba-metric-info-close').first().focus();
            });
        });

        function closeMetricInfoModal() {
            var $modal = $('#sba-metric-info-modal');
            if ($modal.is(':visible')) {
                $modal.fadeOut(150);
                if (lastActiveInfoTrigger) {
                    $(lastActiveInfoTrigger).attr('aria-expanded', 'false').focus();
                    lastActiveInfoTrigger = null;
                }
            }
        }

        $(document).on('click', '.sba-metric-info-close', function (e) {
            e.preventDefault();
            closeMetricInfoModal();
        });

        $('#sba-metric-info-modal').on('click', function (e) {
            if ($(e.target).is('#sba-metric-info-modal')) {
                closeMetricInfoModal();
            }
        });

        // Accessible Keyboard Trapping & Escape Key for Metric Info Modal
        $(document).on('keydown', function (e) {
            var $infoModal = $('#sba-metric-info-modal');
            if ($infoModal.is(':visible')) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    e.preventDefault();
                    closeMetricInfoModal();
                    return;
                }
                if (e.key === 'Tab' || e.keyCode === 9) {
                    var focusables = $infoModal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
                    if (focusables.length === 0) return;
                    var first = focusables.first()[0];
                    var last = focusables.last()[0];
                    if (e.shiftKey) {
                        if (document.activeElement === first) {
                            e.preventDefault();
                            last.focus();
                        }
                    } else {
                        if (document.activeElement === last) {
                            e.preventDefault();
                            first.focus();
                        }
                    }
                }
            }
        });

        // 1-Click Cleaner: Expired Temporary Data (Section C Transparent Confirmation)
        $('#sba-clean-transients-btn').on('click', function (e) {
            e.preventDefault();
            if (currentTransientsCount <= 0) {
                showNotice(sba__('No expired temporary data rows found to clear. All temporary data is active and within its validity period.', 'No expired temporary data rows found to clear. All temporary data is active and within its validity period.'), false, 'database');
                return;
            }

            pendingCleanupAction = 'transients';
            pendingCleanupPayload = null;

            $('#sba-cleanup-modal-title-text').text(sba__('Confirm Cleanup: Expired Temporary Data', 'Confirm Cleanup: Expired Temporary Data'));
            $('#sba-cleanup-lead-desc').text(sba__('Review the cleanup summary below before proceeding. Only confirmed expired cache rows will be removed.', 'Review the cleanup summary below before proceeding. Only confirmed expired cache rows will be removed.'));
            $('#sba-cleanup-current-db-size').text(currentDbSizeFormatted);
            $('#sba-cleanup-selected-count').text(currentTransientsCount + ' ' + (currentTransientsCount === 1 ? sba__('expired transient row', 'expired transient row') : sba__('expired transient rows', 'expired transient rows')));
            $('#sba-cleanup-recoverable-space').text('~' + (currentTransientsFormatted || '0 B') + ' (' + sba__('Estimated', 'Estimated') + ')');
            $('#sba-cleanup-what-deleted').text(sba__('Cached transient timeout options and corresponding values in wp_options that have already expired. Active transients and permanent settings are safely preserved.', 'Cached transient timeout options and corresponding values in wp_options that have already expired. Active transients and permanent settings are safely preserved.'));
            $('#sba-cleanup-skipped-desc').text(sba__('All active transients within validity period and permanent settings are safely preserved.', 'All active transients within validity period and permanent settings are safely preserved.'));
            $('#sba-confirm-cleanup-execute-label').text(sba__('Confirm & Clear Expired Data', 'Confirm & Clear Expired Data'));

            $('#sba-db-cleanup-modal').fadeIn(150);
        });

        // 1-Click Cleaner: Saved Content Versions (Section C Transparent Confirmation)
        $('#sba-clean-revisions-btn').on('click', function (e) {
            e.preventDefault();
            var revTotal = parseInt($('#sba-stat-revisions').text(), 10) || 0;
            if (currentCleanableRevisionsCount <= 0 && revTotal <= 0) {
                showNotice(sba__('All post revisions are currently within the safe retention threshold (keeping the last 3 edits per post). No excess revisions eligible for cleanup.', 'All post revisions are currently within the safe retention threshold (keeping the last 3 edits per post). No excess revisions eligible for cleanup.'), false, 'database');
                return;
            }

            pendingCleanupAction = 'revisions';
            pendingCleanupPayload = { keep: 3 };

            var countLabel = currentCleanableRevisionsCount > 0 ? currentCleanableRevisionsCount : revTotal;
            $('#sba-cleanup-modal-title-text').text(sba__('Confirm Cleanup: Saved Content Versions', 'Confirm Cleanup: Saved Content Versions'));
            $('#sba-cleanup-lead-desc').text(sba__('Review the cleanup summary below before proceeding. Your published posts and the 3 most recent edits per post will be permanently kept.', 'Review the cleanup summary below before proceeding. Your published posts and the 3 most recent edits per post will be permanently kept.'));
            $('#sba-cleanup-current-db-size').text(currentDbSizeFormatted);
            $('#sba-cleanup-selected-count').text(countLabel + ' ' + (countLabel === 1 ? sba__('old revision draft', 'old revision draft') : sba__('old revision drafts', 'old revision drafts')));
            $('#sba-cleanup-recoverable-space').text('~' + (currentCleanableRevisionsFormatted || '0 B') + ' (' + sba__('Estimated', 'Estimated') + ')');
            $('#sba-cleanup-what-deleted').text(sba__('Draft copies older than the 3 most recent edits per post, plus any orphaned revisions without parent posts. Recent 3 revisions and current published content are preserved.', 'Draft copies older than the 3 most recent edits per post, plus any orphaned revisions without parent posts. Recent 3 revisions and current published content are preserved.'));
            $('#sba-cleanup-skipped-desc').text(sba__('The 3 most recent edits per post and all published content are permanently preserved.', 'The 3 most recent edits per post and all published content are permanently preserved.'));
            $('#sba-confirm-cleanup-execute-label').text(sba__('Confirm & Clean Versions', 'Confirm & Clean Versions'));

            $('#sba-db-cleanup-modal').fadeIn(150);
        });

        // Close/Cancel Cleanup Confirmation Modal (Ensures cancellation causes zero changes)
        $('.sba-cleanup-modal-cancel').on('click', function (e) {
            e.preventDefault();
            $('#sba-db-cleanup-modal').fadeOut(150);
            pendingCleanupAction = null;
            pendingCleanupPayload = null;
        });

        $('#sba-db-cleanup-modal').on('click', function (e) {
            if ($(e.target).is('#sba-db-cleanup-modal')) {
                $('#sba-db-cleanup-modal').fadeOut(150);
                pendingCleanupAction = null;
                pendingCleanupPayload = null;
            }
        });

        // Execute Confirmed Cleanup Action
        $('#sba-confirm-cleanup-execute-btn').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $cancelBtn = $('.sba-cleanup-modal-cancel');
            var originalLabel = $('#sba-confirm-cleanup-execute-label').text();

            if (!pendingCleanupAction) {
                $('#sba-db-cleanup-modal').fadeOut(150);
                return;
            }

            $btn.prop('disabled', true);
            $cancelBtn.prop('disabled', true);
            $('#sba-confirm-cleanup-execute-label').text(sba__('Cleaning...', 'Cleaning...'));

            if (pendingCleanupAction === 'transients') {
                $.post(sbaAjaxUrl, {
                    action: 'sba_clean_transients',
                    _ajax_nonce: sbaNonce
                }, function (response) {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);

                    if (response.success && response.data) {
                        var d = response.data;
                        currentTransientsCount = d.remaining_transients || 0;
                        currentTransientsBytes = 0;
                        currentTransientsFormatted = '0 B';
                        $('#sba-stat-transients').text(formatNumber(currentTransientsCount));
                        $('#sba-stat-transients-meta').text(formatNumber(currentTransientsCount) + ' ' + sba__('outdated rows', 'outdated rows') + ' (0 B)');
                        if (d.database_size_fmt) {
                            currentDbSizeFormatted = d.database_size_fmt;
                            $('#sba-stat-db-total-size').text(d.database_size_fmt);
                        }
                        showDatabaseCleanupReport({
                            count: d.deleted_count || 0,
                            spaceRecovered: d.freed_fmt || '0 B',
                            isEstimated: true,
                            errors: d.errors || 0,
                            skipped: d.skipped || 0,
                            message: d.message || sba__('Expired temporary data purged successfully.', 'Expired temporary data purged successfully.')
                        });
                    } else {
                        showNotice(response.data || sba__('Failed to purge expired temporary data.', 'Failed to purge expired temporary data.'), true, 'database');
                    }
                    pendingCleanupAction = null;
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);
                    showNotice(sba__('Server error while purging expired temporary data.', 'Server error while purging expired temporary data.'), true, 'database');
                    pendingCleanupAction = null;
                });
            } else if (pendingCleanupAction === 'revisions') {
                var keepCount = (pendingCleanupPayload && pendingCleanupPayload.keep) ? pendingCleanupPayload.keep : 3;
                $.post(sbaAjaxUrl, {
                    action: 'sba_clean_revisions',
                    _ajax_nonce: sbaNonce,
                    keep: keepCount
                }, function (response) {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);

                    if (response.success && response.data) {
                        var d = response.data;
                        var rem = d.remaining_revisions || 0;
                        $('#sba-stat-revisions').text(formatNumber(rem));
                        if (d.stats) {
                            var s = d.stats;
                            currentCleanableRevisionsCount = s.cleanable_count || 0;
                            currentCleanableRevisionsBytes = s.cleanable_bytes || 0;
                            currentCleanableRevisionsFormatted = formatSize(s.cleanable_kb || 0);
                            $('#sba-stat-revisions-size').text(formatSize(s.total_kb || 0) + ' (' + formatNumber(s.total_bytes || 0) + ' bytes)');
                            $('#sba-stat-revisions-releasable').text(formatSize(s.releasable_kb || 0) + ' (' + formatNumber(s.releasable_bytes || 0) + ' bytes)');
                        }
                        if (d.database_size_fmt) {
                            currentDbSizeFormatted = d.database_size_fmt;
                            $('#sba-stat-db-total-size').text(d.database_size_fmt);
                        }
                        showDatabaseCleanupReport({
                            count: d.deleted_count || 0,
                            spaceRecovered: d.freed_fmt || '0 B',
                            isEstimated: true,
                            errors: d.errors || 0,
                            skipped: d.skipped || 0,
                            message: d.message || sba__('Saved content versions cleaned successfully.', 'Saved content versions cleaned successfully.')
                        });
                    } else {
                        showNotice(response.data || sba__('Failed to clean revisions.', 'Failed to clean revisions.'), true, 'database');
                    }
                    pendingCleanupAction = null;
                    pendingCleanupPayload = null;
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);
                    showNotice(sba__('Server error while cleaning saved content versions.', 'Server error while cleaning saved content versions.'), true, 'database');
                    pendingCleanupAction = null;
                    pendingCleanupPayload = null;
                });
            } else if (pendingCleanupAction === 'bulk_revisions') {
                var revIds = (pendingCleanupPayload && pendingCleanupPayload.revision_ids) ? pendingCleanupPayload.revision_ids : [];
                $.post(sbaAjaxUrl, {
                    action: 'sba_delete_bulk_revisions',
                    _ajax_nonce: sbaNonce,
                    revision_ids: revIds
                }, function (response) {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);

                    if (response.success && response.data) {
                        var d = response.data;
                        $.each(revIds, function (_, id) {
                            $('#sba-rev-row-' + id).remove();
                            currentRevisions = currentRevisions.filter(function (r) { return r.id != id; });
                        });
                        updateRevisionsUI(d.stats);
                        if (currentRevisions.length === 0) {
                            renderRevisionsTable([]);
                        } else {
                            updateSelectedRevsCount();
                        }
                        if (d.database_size_fmt) {
                            currentDbSizeFormatted = d.database_size_fmt;
                            $('#sba-stat-db-total-size').text(d.database_size_fmt);
                        }
                        showRevModalNotice(d.message || sba__('Revisions deleted successfully.', 'Revisions deleted successfully.'), false);
                        showDatabaseCleanupReport({
                            count: d.deleted_count || 0,
                            spaceRecovered: d.freed_fmt || '0 B',
                            isEstimated: true,
                            errors: d.errors || 0,
                            skipped: d.skipped || 0,
                            message: d.message || sba__('Revisions deleted successfully.', 'Revisions deleted successfully.')
                        });
                    } else {
                        showRevModalNotice(response.data || sba__('Failed to delete revisions.', 'Failed to delete revisions.'), true);
                    }
                    pendingCleanupAction = null;
                    pendingCleanupPayload = null;
                }).fail(function () {
                    $btn.prop('disabled', false);
                    $cancelBtn.prop('disabled', false);
                    $('#sba-confirm-cleanup-execute-label').text(originalLabel);
                    $('#sba-db-cleanup-modal').fadeOut(150);
                    showRevModalNotice(sba__('Server error while deleting revisions.', 'Server error while deleting revisions.'), true);
                    pendingCleanupAction = null;
                    pendingCleanupPayload = null;
                });
            }
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
            setTimeout(function () {
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
            }, function (response) {
                $('#sba-revisions-loading').hide();
                if (response.success && response.data) {
                    currentRevisions = response.data.revisions || [];
                    updateRevisionsUI(response.data.stats);
                    renderRevisionsTable(currentRevisions);
                } else {
                    showRevModalNotice(response.data || sba__('Failed to load revisions.', 'Failed to load revisions.'), true);
                }
            }).fail(function () {
                $('#sba-revisions-loading').hide();
                showRevModalNotice(sba__('Server error while loading revisions.', 'Server error while loading revisions.'), true);
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

            $.each(revisions, function (index, rev) {
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
        $(document).on('click', '.sba-card-revisions, #sba-stat-revisions-trigger, #sba-stat-revisions, .sba-stat-revisions-clickable, .sba-rev-click-hint', function (e) {
            if ($(e.target).closest('#sba-clean-revisions-btn').length) {
                return;
            }
            e.preventDefault();
            loadRevisionsModal();
        });

        // Close revisions modal
        $(document).on('click', '.sba-modal-revisions-close', function (e) {
            e.preventDefault();
            $('#sba-revisions-modal').fadeOut(150);
        });

        // Close modal when clicking on overlay background
        $('#sba-revisions-modal').on('click', function (e) {
            if ($(e.target).is('#sba-revisions-modal')) {
                $('#sba-revisions-modal').fadeOut(150);
            }
        });

        // Delete single revision
        $(document).on('click', '.sba-delete-single-rev-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var revId = $btn.data('id');
            var revBytes = $btn.data('bytes');

            if (!confirm(sba__('Are you sure you want to permanently delete this revision? This action cannot be undone.', 'Are you sure you want to permanently delete this revision? This action cannot be undone.'))) {
                return;
            }

            var $row = $('#sba-rev-row-' + revId);
            var $icon = $btn.find('.dashicons');
            var $text = $btn.find('.sba-del-text');
            $btn.prop('disabled', true);
            $icon.addClass('sba-rotating');
            $text.text(sba__('Deleting...', 'Deleting...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_single_revision',
                _ajax_nonce: sbaNonce,
                revision_id: revId
            }, function (response) {
                if (response.success && response.data) {
                    $row.fadeOut(200, function () {
                        $(this).remove();
                        currentRevisions = currentRevisions.filter(function (r) { return r.id != revId; });
                        updateRevisionsUI(response.data.stats);
                        if (currentRevisions.length === 0) {
                            renderRevisionsTable([]);
                        }
                    });
                    showRevModalNotice(response.data.message || sba__('Revision deleted successfully.', 'Revision deleted successfully.'), false);
                } else {
                    $btn.prop('disabled', false);
                    $icon.removeClass('sba-rotating');
                    $text.text('Delete');
                    showRevModalNotice(response.data || sba__('Failed to delete revision.', 'Failed to delete revision.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $text.text('Delete');
                showRevModalNotice(sba__('Server error while deleting revision.', 'Server error while deleting revision.'), true);
            });
        });

        // Checkbox selection in modal
        $(document).on('change', '#sba-rev-select-all', function () {
            var isChecked = $(this).is(':checked');
            $('.sba-rev-checkbox').prop('checked', isChecked);
            updateSelectedRevsCount();
        });

        $(document).on('change', '.sba-rev-checkbox', function () {
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
        $('#sba-rev-delete-selected-btn').on('click', function (e) {
            e.preventDefault();
            var selectedIds = [];
            var selectedBytes = 0;
            $('.sba-rev-checkbox:checked').each(function () {
                var id = $(this).val();
                selectedIds.push(id);
                var match = currentRevisions.find(function (r) { return r.id == id; });
                if (match && match.bytes) selectedBytes += match.bytes;
            });

            if (selectedIds.length === 0) return;

            pendingCleanupAction = 'bulk_revisions';
            pendingCleanupPayload = { revision_ids: selectedIds };

            $('#sba-cleanup-modal-title-text').text(sba__('Confirm Deletion: Selected Content Versions', 'Confirm Deletion: Selected Content Versions'));
            $('#sba-cleanup-lead-desc').text(sba__('Review the cleanup summary below before proceeding with selected revision removal.', 'Review the cleanup summary below before proceeding with selected revision removal.'));
            $('#sba-cleanup-current-db-size').text(currentDbSizeFormatted);
            $('#sba-cleanup-selected-count').text(selectedIds.length + ' ' + (selectedIds.length === 1 ? sba__('selected revision draft', 'selected revision draft') : sba__('selected revision drafts', 'selected revision drafts')));
            $('#sba-cleanup-recoverable-space').text('~' + formatSize(selectedBytes / 1024) + ' (' + sba__('Estimated', 'Estimated') + ')');
            $('#sba-cleanup-what-deleted').text(sba__('The selected post revision draft records and their associated postmeta. Published posts and non-selected revisions are preserved.', 'The selected post revision draft records and their associated postmeta. Published posts and non-selected revisions are preserved.'));
            $('#sba-confirm-cleanup-execute-label').text(sba__('Confirm & Delete Selected', 'Confirm & Delete Selected'));

            $('#sba-db-cleanup-modal').fadeIn(150);
        });

        // Delete all revisions
        $('#sba-rev-delete-all-btn').on('click', function (e) {
            e.preventDefault();
            if (currentRevisions.length === 0) return;

            var allIds = currentRevisions.map(function (r) { return r.id; });
            var totalBytes = currentRevisions.reduce(function (sum, r) { return sum + (r.bytes || 0); }, 0);

            pendingCleanupAction = 'bulk_revisions';
            pendingCleanupPayload = { revision_ids: allIds };

            $('#sba-cleanup-modal-title-text').text(sba__('Confirm Deletion: All Saved Content Versions', 'Confirm Deletion: All Saved Content Versions'));
            $('#sba-cleanup-lead-desc').text(sba__('Review the cleanup summary below before proceeding. This will remove all revision drafts while keeping your current published posts intact.', 'Review the cleanup summary below before proceeding. This will remove all revision drafts while keeping your current published posts intact.'));
            $('#sba-cleanup-current-db-size').text(currentDbSizeFormatted);
            $('#sba-cleanup-selected-count').text(allIds.length + ' ' + sba__('total revision drafts', 'total revision drafts'));
            $('#sba-cleanup-recoverable-space').text('~' + formatSize(totalBytes / 1024) + ' (' + sba__('Estimated', 'Estimated') + ')');
            $('#sba-cleanup-what-deleted').text(sba__('All historical draft versions across all posts and pages. All currently published posts remain 100% untouched.', 'All historical draft versions across all posts and pages. All currently published posts remain 100% untouched.'));
            $('#sba-confirm-cleanup-execute-label').text(sba__('Confirm & Delete All Versions', 'Confirm & Delete All Versions'));

            $('#sba-db-cleanup-modal').fadeIn(150);
        });

        // Top Safety Card: Full Backup ZIP feedback
        $('#sba-btn-full-backup-zip').on('click', function () {
            var $btn = $(this);
            var $label = $btn.find('.sba-full-backup-zip-label');
            var originalText = $label.text();
            $label.text(sba__('Compressing Backup...', 'Compressing Backup...'));
            setTimeout(function () {
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

        // Drop Modal Dialog Open (Section C Transparent Confirmation)
        $(document).on('click', '.sba-drop-btn', function (e) {
            e.preventDefault();
            var tableName = $(this).data('table');
            var rows = $(this).data('rows');
            var size = $(this).data('size');
            var likelyPlugin = $(this).data('plugin') || sba__('Unknown / Custom Table', 'Unknown / Custom Table');

            pendingDropTable = tableName;
            $('#sba-modal-table-name').text(tableName);
            $('#sba-modal-table-meta').text(formatNumber(rows) + ' ' + sba__('rows', 'rows') + ' • ' + formatSize(size));
            $('#sba-modal-table-plugin').html(sbaEscapeHtml(sba__('Belongs to: ', 'Belongs to: ')) + '<strong>' + sbaEscapeHtml(likelyPlugin) + '</strong> &bull; <span style="color: #b91c1c; font-weight: 600;">' + sbaEscapeHtml(sba__('Plugin is deleted/inactive', 'Plugin is deleted/inactive')) + '</span>');

            $('#sba-modal-drop-current-db-size').text(currentDbSizeFormatted);
            $('#sba-modal-drop-selected-rows').text('1 ' + sba__('orphaned table', 'orphaned table') + ' (' + formatNumber(rows) + ' ' + sba__('rows', 'rows') + ')');
            $('#sba-modal-drop-recoverable-space').text(formatSize(size) + ' (' + sba__('Measured table data + index', 'Measured table data + index') + ')');
            $('#sba-modal-drop-what-deleted').text(sba__('The entire orphaned table structure and all stored rows. Unused by WordPress Core or active plugins.', 'The entire orphaned table structure and all stored rows. Unused by WordPress Core or active plugins.'));

            $('#sba-modal-backup-feedback').hide().empty();
            $('#sba-modal-drop-btn, #sba-modal-single-backup-btn, .sba-modal-cancel').prop('disabled', false);
            $('#sba-modal-drop-btn .sba-drop-btn-label').text(sba__('Permanently Drop Table', 'Permanently Drop Table'));

            $('#sba-drop-modal').fadeIn(150);
        });

        // Close Modal
        $('.sba-modal-close, .sba-modal-cancel').on('click', function (e) {
            e.preventDefault();
            $('#sba-drop-modal').fadeOut(150);
            pendingDropTable = null;
        });

        $('#sba-drop-modal').on('click', function (e) {
            if ($(e.target).is('#sba-drop-modal')) {
                $('#sba-drop-modal').fadeOut(150);
                pendingDropTable = null;
            }
        });

        // Single Table Backup (.zip)
        $('#sba-modal-single-backup-btn').on('click', function (e) {
            e.preventDefault();
            if (!pendingDropTable) return;

            var $btn = $(this);
            var $label = $btn.find('.sba-backup-btn-label');
            var originalText = $label.text();
            $btn.prop('disabled', true);
            $label.text(sba__('Downloading Backup (.zip)...', 'Downloading Backup (.zip)...'));

            var tableName = pendingDropTable;

            $.post(sbaAjaxUrl, {
                action: 'sba_backup_table',
                _ajax_nonce: sbaNonce,
                table_name: tableName
            }, function (response) {
                $btn.prop('disabled', false);
                $label.text(originalText);

                if (response.success && response.data && response.data.base64) {
                    downloadBinaryBase64File(response.data.filename, response.data.base64, response.data.mime);
                    $('#sba-modal-backup-feedback')
                        .html('<strong>✓ ' + sba__('Backup downloaded successfully: ', 'Backup downloaded successfully: ') + '</strong> <code>' + sbaEscapeHtml(response.data.filename) + '</code>')
                        .fadeIn()
                        .delay(4000)
                        .fadeOut();
                } else {
                    showNotice(response.data || sba__('Failed to export table backup.', 'Failed to export table backup.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $label.text(originalText);
                showNotice(sba__('Server communication error while exporting backup.', 'Server communication error while exporting backup.'), true);
            });
        });

        // Permanently Drop Table
        $('#sba-modal-drop-btn').on('click', function (e) {
            e.preventDefault();
            if (!pendingDropTable) return;

            var tableName = pendingDropTable;
            var $confirmBtn = $(this);
            var $backupBtn = $('#sba-modal-single-backup-btn');
            var $cancelBtn = $('.sba-modal-cancel');

            $confirmBtn.prop('disabled', true);
            $backupBtn.prop('disabled', true);
            $cancelBtn.prop('disabled', true);
            $confirmBtn.find('.sba-drop-btn-label').text(sba__('Permanently Dropping...', 'Permanently Dropping...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_drop_table',
                _ajax_nonce: sbaNonce,
                table_name: tableName
            }, function (dropResponse) {
                $('#sba-drop-modal').fadeOut(150);
                $confirmBtn.prop('disabled', false);
                $backupBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $confirmBtn.find('.sba-drop-btn-label').text(sba__('Permanently Drop Table', 'Permanently Drop Table'));

                if (dropResponse.success) {
                    var successMsg = dropResponse.data.message || (sba__('Table dropped successfully: ', 'Table dropped successfully: ') + sbaEscapeHtml(tableName));

                    allTables = allTables.filter(function (t) {
                        return t.name !== tableName;
                    });

                    if (dropResponse.data && dropResponse.data.database_size_fmt) {
                        currentDbSizeFormatted = dropResponse.data.database_size_fmt;
                        $('#sba-stat-db-total-size').text(dropResponse.data.database_size_fmt);
                    }

                    showDatabaseCleanupReport({
                        count: 1,
                        spaceRecovered: (dropResponse.data && dropResponse.data.freed_fmt) ? dropResponse.data.freed_fmt : '0 B',
                        isEstimated: false,
                        errors: 0,
                        skipped: 0,
                        message: successMsg
                    });

                    var $row = $('#sba-row-' + tableName);
                    if ($row.length) {
                        $row.css('background-color', '#fee2e2');
                        $row.fadeOut(300, function () {
                            $(this).remove();
                            updateCounts();
                            renderTables();
                        });
                    } else {
                        updateCounts();
                        renderTables();
                    }
                } else {
                    showNotice(dropResponse.data || sba__('Failed to drop table.', 'Failed to drop table.'), true, 'database');
                }
                pendingDropTable = null;
            }).fail(function () {
                $('#sba-drop-modal').fadeOut(150);
                $confirmBtn.prop('disabled', false);
                $backupBtn.prop('disabled', false);
                $cancelBtn.prop('disabled', false);
                $confirmBtn.find('.sba-drop-btn-label').text(sba__('Permanently Drop Table', 'Permanently Drop Table'));
                showNotice(sba__('Server communication error while dropping table.', 'Server communication error while dropping table.'), true);
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

            $('#sba-btn-empty-quarantine').show().attr('title', sba__('Empty Quarantine Permanently', 'Empty Quarantine Permanently'));
            if (count > 0) {
                $('#sba-btn-empty-quarantine-label').text(sba__('Delete Selected Permanently', 'Delete Selected Permanently') + ' (' + count + ')');
                $('#sba-quarantine-selected-count-restore').text(count);
                $('#sba-btn-restore-selected').show();
                $('#sba-btn-empty-all-quarantine').show();
            } else {
                $('#sba-btn-empty-quarantine-label').text(sba__('Empty Quarantine Permanently', 'Empty Quarantine Permanently'));
                $('#sba-btn-restore-selected').hide();
                $('#sba-btn-empty-all-quarantine').hide();
            }
        }

        // Synchronize header "Select All" checkbox state for Media Library
        function updateMediaSelectAllState() {
            var $allCheckboxes = $('.sba-media-item-cb:not(:disabled)');
            var totalVisible = $allCheckboxes.length;
            if (totalVisible === 0) {
                $('#sba-media-select-all').prop('checked', false).prop('indeterminate', false);
                return;
            }

            var checkedCount = $allCheckboxes.filter(':checked').length;
            if (checkedCount === 0) {
                $('#sba-media-select-all').prop('checked', false).prop('indeterminate', false);
            } else if (checkedCount === totalVisible) {
                $('#sba-media-select-all').prop('checked', true).prop('indeterminate', false);
            } else {
                $('#sba-media-select-all').prop('checked', false).prop('indeterminate', true);
            }
        }

        // Retrieve media objects for an array of attachment IDs
        function getSelectedMediaObjects(ids) {
            var found = [];
            var idSet = {};
            ids.forEach(function (id) { idSet[parseInt(id, 10)] = true; });

            if (allMedia && Array.isArray(allMedia)) {
                for (var i = 0; i < allMedia.length; i++) {
                    var mId = parseInt(allMedia[i].id, 10);
                    if (idSet[mId]) {
                        found.push(allMedia[i]);
                        delete idSet[mId];
                    }
                }
            }

            if (quarantinedMedia && Array.isArray(quarantinedMedia)) {
                for (var j = 0; j < quarantinedMedia.length; j++) {
                    var qId = parseInt(quarantinedMedia[j].id, 10);
                    if (idSet[qId]) {
                        found.push(quarantinedMedia[j]);
                        delete idSet[qId];
                    }
                }
            }

            Object.keys(idSet).forEach(function (remIdStr) {
                var remId = parseInt(remIdStr, 10);
                if (!remId) {
                    return;
                }
                var $cb = $('.sba-media-item-cb[value="' + remId + '"]').first();
                var fallbackName = ($cb.length && $cb.data('name')) ? String($cb.data('name')) : ('Attachment #' + remId);
                found.push({
                    id: remId,
                    name: fallbackName,
                    category: 'discrepancy',
                    edit_url: 'post.php?post=' + remId + '&action=edit',
                    file_size_bytes: 0
                });
            });

            return found;
        }

        // Update bulk action toolbar buttons and counts
        function updateBulkToolbar() {
            var count = selectedMediaIds.length;
            var $toolbar = $('#sba-media-bulk-toolbar');

            if (count === 0) {
                $toolbar.hide();
                return;
            }

            $toolbar.show();
            $('#sba-bulk-selection-count').text(count);
            $('#sba-bulk-selection-label').text(count === 1 ? sba__('image selected', 'image selected') : sba__('images selected', 'images selected'));

            var selectedObjs = getSelectedMediaObjects(selectedMediaIds);
            var eligibleOpt = 0;
            var eligibleQuar = 0;
            var eligibleRest = 0;
            var eligibleDel = selectedObjs.length;

            selectedObjs.forEach(function (item) {
                var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                if (isQuar) {
                    eligibleRest++;
                } else {
                    eligibleQuar++;
                    if (!item.has_backup) {
                        eligibleOpt++;
                    }
                }
            });

            // Optimize button
            var $btnOpt = $('#sba-bulk-btn-optimize');
            if (eligibleOpt > 0) {
                $btnOpt.show().prop('disabled', false).find('.sba-action-count').text(eligibleOpt);
            } else {
                $btnOpt.hide();
            }

            // Quarantine button
            var $btnQuar = $('#sba-bulk-btn-quarantine');
            if (eligibleQuar > 0) {
                $btnQuar.show().prop('disabled', false).find('.sba-action-count').text(eligibleQuar);
            } else {
                $btnQuar.hide();
            }

            // Restore button
            var $btnRest = $('#sba-bulk-btn-restore');
            if (eligibleRest > 0) {
                $btnRest.show().prop('disabled', false).find('.sba-action-count').text(eligibleRest);
            } else {
                $btnRest.hide();
            }

            // Delete button
            var $btnDel = $('#sba-bulk-btn-delete');
            if (eligibleDel > 0) {
                $btnDel.show().prop('disabled', false).find('.sba-action-count').text(eligibleDel);
            } else {
                $btnDel.hide();
            }

            // Edit button
            $('#sba-bulk-btn-edit').show();
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

        function buildMediaRowCheckboxHtml(safeId, safeName, isItemQuarantined) {
            if (!safeId || safeId <= 0) {
                return '<td class="sba-media-cb-col" style="text-align: center; vertical-align: middle;">' +
                    '<input type="checkbox" class="sba-media-item-cb" disabled="disabled" aria-label="' + sba__('Selection unavailable', 'Selection unavailable') + '" />' +
                    '</td>';
            }
            var isChecked = selectedMediaIds.indexOf(safeId) !== -1;
            return '<td class="sba-media-cb-col" style="text-align: center; vertical-align: middle;">' +
                '<input type="checkbox" class="sba-media-item-cb' + (isItemQuarantined ? ' sba-quarantine-item-cb' : '') + '" value="' + safeId + '" data-id="' + safeId + '" data-name="' + safeName + '"' + (isChecked ? ' checked="checked"' : '') + ' aria-label="' + sba__('Select', 'Select') + ' ' + safeName + '" />' +
                '</td>';
        }

        function syncMediaSelectionToVisibleRows() {
            var visibleIdMap = {};
            $('#sba-media-body').find('.sba-media-item-cb:not(:disabled)').each(function () {
                var rowId = parseInt($(this).val(), 10);
                if (rowId > 0) {
                    visibleIdMap[rowId] = true;
                }
            });
            selectedMediaIds = selectedMediaIds.filter(function (id) { return !!visibleIdMap[id]; });
            selectedQuarantineIds = selectedMediaIds;
            updateMediaSelectAllState();
            if (currentMediaFilter === 'quarantined') {
                updateQuarantineSelectAllState();
            }
            updateBulkToolbar();
            updateQuarantineActionButtons();
        }

        function clearVisibleMediaSelection() {
            selectedMediaIds = [];
            selectedQuarantineIds = [];
            updateMediaSelectAllState();
            updateBulkToolbar();
            updateQuarantineActionButtons();
        }

        function renderMediaTable() {
            var searchQuery = $('#sba-media-search').val().toLowerCase().trim();
            var $tbody = $('#sba-media-body');
            $tbody.empty();

            var isQuarantinedTab = (currentMediaFilter === 'quarantined');
            $('.sba-media-cb-col').show();

            if (currentMediaFilter === 'duplicates') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    clearVisibleMediaSelection();
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }
                if (!duplicatesData || duplicatesData.length === 0) {
                    clearVisibleMediaSelection();
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__('No duplicate files detected!', 'No duplicate files detected!'));
                    $('#sba-media-clean-desc').text(sba__('All scanned media files have distinct cryptographic SHA-256 hashes.', 'All scanned media files have distinct cryptographic SHA-256 hashes.'));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderDuplicatesView($tbody, searchQuery);
                syncMediaSelectionToVisibleRows();
                return;
            }

            if (currentMediaFilter === 'accessibility') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    clearVisibleMediaSelection();
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }
                if (!accessibilityData || !accessibilityData.items || accessibilityData.items.length === 0) {
                    clearVisibleMediaSelection();
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__('Excellent Image Accessibility!', 'Excellent Image Accessibility!'));
                    $('#sba-media-clean-desc').text(sba__('All images contain descriptive alternative text (alt text) and informative filenames conforming to WCAG 2.1 guidelines.', 'All images contain descriptive alternative text (alt text) and informative filenames conforming to WCAG 2.1 guidelines.'));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderAccessibilityView($tbody, searchQuery);
                syncMediaSelectionToVisibleRows();
                return;
            }

            if (currentMediaFilter === 'discrepancies') {
                $('#sba-media-initial-state').hide();
                $('#sba-quarantined-empty-state').hide();
                if (!mediaScanDone) {
                    clearVisibleMediaSelection();
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
                    clearVisibleMediaSelection();
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();
                    $('#sba-media-clean-title').text(sba__('Filesystem and metadata are in complete sync!', 'Filesystem and metadata are in complete sync!'));
                    $('#sba-media-clean-desc').text(sba__('All attachment records have valid files on disk and all generated thumbnail sub-sizes are present.', 'All attachment records have valid files on disk and all generated thumbnail sub-sizes are present.'));
                    return;
                }
                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
                renderDiscrepanciesView($tbody, searchQuery);
                syncMediaSelectionToVisibleRows();
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
                    clearVisibleMediaSelection();
                    $('#sba-quarantined-empty-state').show();
                    $('#sba-media-table-container').hide();
                    return;
                }

                $('#sba-quarantined-empty-state').hide();
                $('#sba-media-table-container').show();
            } else {
                $('#sba-quarantined-empty-state').hide();

                // If scan has not run yet
                if (!mediaScanDone) {
                    clearVisibleMediaSelection();
                    $('#sba-media-initial-state').show();
                    $('#sba-media-clean-state').hide();
                    $('#sba-media-table-container').hide();
                    return;
                }

                $('#sba-media-initial-state').hide();

                // If scan has run but current category has 0 items
                if (sourceList.length === 0) {
                    clearVisibleMediaSelection();
                    $('#sba-media-table-container').hide();
                    $('#sba-media-clean-state').show();

                    if (currentMediaFilter === 'unused') {
                        $('#sba-media-clean-title').text(sba__('Your Media Library is clean! No unlinked images detected.', 'Your Media Library is clean! No unlinked images detected.'));
                        $('#sba-media-clean-desc').text(sba__('All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.', 'All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.'));
                    } else if (currentMediaFilter === 'oversized') {
                        $('#sba-media-clean-title').text(sba__('All images meet display benchmarks! No oversized images found.', 'All images meet display benchmarks! No oversized images found.'));
                        $('#sba-media-clean-desc').text(sba__('None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.', 'None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.'));
                    } else if (currentMediaFilter === 'optimized') {
                        $('#sba-media-clean-title').text(sba__('No optimized images found.', 'No optimized images found.'));
                        $('#sba-media-clean-desc').text(sba__('Active images require downscaling/compression or are currently unused.', 'Active images require downscaling/compression or are currently unused.'));
                    } else {
                        $('#sba-media-clean-title').text(sba__('No media attachments found.', 'No media attachments found.'));
                        $('#sba-media-clean-desc').text(sba__('The WordPress Media Library currently contains 0 attachments.', 'The WordPress Media Library currently contains 0 attachments.'));
                    }
                    return;
                }

                $('#sba-media-clean-state').hide();
                $('#sba-media-table-container').show();
            }

            // Filter by search query
            var filtered = sourceList.filter(function (m) {
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

            // Prune selection so items hidden by search/filter are not inadvertently selected
            var visibleIds = filtered.map(function (m) { return parseInt(m.id, 10); });
            selectedMediaIds = selectedMediaIds.filter(function (id) { return visibleIds.indexOf(id) !== -1; });
            selectedQuarantineIds = selectedMediaIds;

            if (filtered.length === 0) {
                var emptyColspan = 6;
                $tbody.html('<tr><td colspan="' + emptyColspan + '" style="text-align: center; color: #64748b; padding: 30px;">' + sba__('No media items match your search filter.', 'No media items match your search filter.') + '</td></tr>');
                updateMediaSelectAllState();
                updateBulkToolbar();
                if (isQuarantinedTab) {
                    updateQuarantineSelectAllState();
                }
                updateQuarantineActionButtons();
                return;
            }

            $.each(filtered, function (index, item) {
                var safeId = parseInt(item.id, 10);
                var safeName = sbaEscapeHtml(item.name);
                var safeTitle = sbaEscapeHtml(item.title);
                var rawPath = item.file_path || (item.relative_path ? 'wp-content/uploads/' + item.relative_path.replace(/^[/\\]+/, '') : (item.name ? 'wp-content/uploads/' + item.name : ''));
                var safePath = rawPath ? sbaEscapeHtml(rawPath) : '';
                var safeDate = sbaEscapeHtml(item.upload_date || item.quarantined_date || '');
                var safeOrigDim = sbaEscapeHtml(item.original_dimensions || item.dimensions || 'N/A');
                var safeCurrDim = sbaEscapeHtml(item.current_dimensions || item.dimensions || item.original_dimensions || 'N/A');
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
                var isResized = !!item.is_resized;
                var targetReached = !!item.target_reached;
                var hasBackup = !!item.has_backup;
                var hasWebP = !!item.has_webp;
                var isOrigDimUnavailable = (item.original_dimensions === 'Original dimensions unavailable' || (hasBackup && item.has_verified_orig_dim === false));
                var isItemQuarantined = isQuarantinedTab || (item.category === 'quarantined');

                // 1. Preview Column
                var fullImageUrl = item.full_url || item.preview_url || '';
                var safeFullUrl = fullImageUrl ? sbaEscapeHtml(fullImageUrl) : '';

                var previewHtml = '';
                if (safePreview) {
                    var imgMarkup = '<img src="' + safePreview + '" class="sba-media-thumb" alt="' + safeName + '" onerror="this.style.display=\'none\'; var next=this.parentElement.nextElementSibling || this.nextElementSibling; if(next){next.style.display=\'flex\';}" />';
                    if (safeFullUrl) {
                        previewHtml = '<a href="' + safeFullUrl + '" target="_blank" rel="noopener noreferrer" class="sba-thumb-link" title="' + (isItemQuarantined ? sba__('Open quarantined image in new tab', 'Open quarantined image in new tab') : sba__('Open full image in new tab', 'Open full image in new tab')) + '" aria-label="' + (isItemQuarantined ? sba__('Open quarantined image in new tab', 'Open quarantined image in new tab') : sba__('Open full image in new tab', 'Open full image in new tab')) + ': ' + safeName + '">' +
                            imgMarkup +
                            '</a>' +
                            '<div class="sba-media-thumb-placeholder" style="display: none;"><span class="dashicons ' + (isItemQuarantined ? 'dashicons-shield' : 'dashicons-format-image') + '"></span></div>';
                    } else {
                        previewHtml = imgMarkup +
                            '<div class="sba-media-thumb-placeholder" style="display: none;"><span class="dashicons ' + (isItemQuarantined ? 'dashicons-shield' : 'dashicons-format-image') + '"></span></div>';
                    }
                } else {
                    if (safeFullUrl && !isItemQuarantined) {
                        previewHtml = '<a href="' + safeFullUrl + '" target="_blank" rel="noopener noreferrer" class="sba-thumb-link" title="' + sba__('Open full image in new tab', 'Open full image in new tab') + '" aria-label="' + sba__('Open full image in new tab', 'Open full image in new tab') + ': ' + safeName + '">' +
                            '<div class="sba-media-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>' +
                            '</a>';
                    } else {
                        previewHtml = '<div class="sba-media-thumb-placeholder" title="' + (isItemQuarantined ? sba__('Quarantined file preview unavailable', 'Quarantined file preview unavailable') : '') + '"><span class="dashicons ' + (isItemQuarantined ? 'dashicons-shield' : 'dashicons-format-image') + '"></span></div>';
                    }
                }

                // 2. File & Dimensions Column
                var titleSubtext = (safeTitle && safeTitle !== safeName) ? '<div style="font-size: 11px; color: #64748b; margin-top: 2px;">' + safeTitle + '</div>' : '';
                var pathHtml = safePath ? '<div class="sba-table-source sba-media-file-path" title="' + sba__('Relative path in uploads directory', 'Relative path in uploads directory') + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>' : '';
                var datePrefix = isItemQuarantined ? sba__('Quarantined: ', 'Quarantined: ') : sba__('Uploaded: ', 'Uploaded: ');
                var dateHtml = safeDate ? '<div class="sba-table-source">' + datePrefix + safeDate + '</div>' : '';

                var dimHtml = '';
                if (hasBackup && isResized) {
                    var origDimPill = '';
                    if (isOrigDimUnavailable) {
                        origDimPill = '<span class="sba-dim-pill sba-dim-warning" style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;" title="' + sba__('Original dimensions could not be verified from backup', 'Original dimensions could not be verified from backup') + '">' +
                            '<span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Original dimensions unavailable', 'Original dimensions unavailable') +
                            '</span>';
                    } else {
                        origDimPill = '<span class="sba-dim-pill sba-dim-original" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #475569;" title="' + sba__('Verified original dimensions before optimization', 'Verified original dimensions before optimization') + '">' +
                            '<span class="dashicons dashicons-backup" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Original: ', 'Original: ') + '<strong>' + safeOrigDim + '</strong>' +
                            '</span>';
                    }
                    dimHtml = '<div class="sba-dim-row">' +
                        origDimPill +
                        '<span class="sba-dim-arrow">→</span>' +
                        '<span class="sba-dim-pill sba-dim-current" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534;" title="' + sba__('Current resolution on disk', 'Current resolution on disk') + '">' +
                        '<span class="dashicons dashicons-yes-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeCurrDim + '</strong>' +
                        '</span>' +
                        '</div>';
                } else if (hasBackup && isOrigDimUnavailable) {
                    dimHtml = '<div class="sba-dim-row">' +
                        '<span class="sba-dim-pill sba-dim-warning" style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;">' +
                        '<span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Original dimensions unavailable', 'Original dimensions unavailable') +
                        '</span>' +
                        '<span class="sba-dim-arrow">→</span>' +
                        '<span class="sba-dim-pill sba-dim-current" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534;">' +
                        '<span class="dashicons dashicons-yes-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeCurrDim + '</strong>' +
                        '</span>' +
                        '</div>';
                } else if (isOversized) {
                    dimHtml = '<div class="sba-dim-row">' +
                        '<span class="sba-dim-pill sba-dim-oversized" title="' + sba__('Resolution exceeds web display benchmark', 'Resolution exceeds web display benchmark') + '">' +
                        '<span class="dashicons dashicons-warning"></span> ' + sba__('Original: ', 'Original: ') + '<strong>' + safeOrigDim + '</strong>' +
                        '</span>' +
                        '<span class="sba-dim-arrow">→</span>' +
                        '<span class="sba-dim-pill sba-dim-recommended">' +
                        sba__('Rec: ', 'Rec: ') + '<strong>' + safeRecDim + '</strong>' +
                        '</span>' +
                        '</div>';
                } else if (safeCurrDim && safeCurrDim !== 'N/A') {
                    dimHtml = '<div class="sba-dim-row">' +
                        '<span class="sba-dim-pill sba-dim-ok">' +
                        '<span class="dashicons dashicons-yes-alt"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeCurrDim + '</strong>' +
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
                        '<span class="sba-subsize-toggle-label">' + sba__('View Generated Sizes', 'View Generated Sizes') + ' (' + subsizesCount + ')</span> ' +
                        '<span class="dashicons dashicons-arrow-down-alt2 sba-subsize-toggle-arrow"></span>' +
                        '</button>' +
                        '</div>';

                    var subsizesItemsHtml = '';
                    var eligibleSubsizesCount = 0;
                    for (var si = 0; si < item.subsizes.length; si++) {
                        var sub = item.subsizes[si];
                        var safeSubKey = sbaEscapeHtml(sub.size_key);
                        var safeSubFile = sbaEscapeHtml(sub.file);
                        var safeSubDim = sbaEscapeHtml(sub.dimensions || 'N/A');
                        var safeSubBytes = sbaEscapeHtml(sub.file_size_formatted || '0 B');
                        var rawSubBytes = parseInt(sub.file_size_bytes, 10) || 0;
                        var statusBadgeHtml = '';
                        var actionBtnHtml = '';
                        var isSubDeletable = false;
                        var subProtectReason = '';

                        if (sub.status === 'in_use_protected' || (sub.is_protected && sub.usage_detected)) {
                            subProtectReason = sub.note || sba__('Protected: Actively in use on the site.', 'Protected: Actively in use on the site.');
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-success" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-yes-alt"></span> ' + sba__('In Use — Protected', 'In Use — Protected') +
                                '</span>';

                            var usageDetailsHtml = '';
                            if (sub.usage_location) {
                                usageDetailsHtml = '<div class="sba-subsize-usage-meta">' +
                                    '<span class="dashicons dashicons-location" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle; color: #047857;"></span> ' +
                                    '<strong>' + sbaEscapeHtml(sub.usage_location) + '</strong>' +
                                    (sub.usage_source ? '<div style="color: #64748b; font-size: 10px; margin-left: 15px;">Source: ' + sbaEscapeHtml(sub.usage_source) + (sub.usage_match ? ' (' + sbaEscapeHtml(sub.usage_match) + ')' : '') + '</div>' : '') +
                                    '</div>';
                            }
                            statusBadgeHtml += usageDetailsHtml;

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-subsize-disabled" disabled title="' + sbaEscapeHtml(subProtectReason) + '">' +
                                '<span class="dashicons dashicons-lock"></span> <span>' + sba__('Protected', 'Protected') + '</span>' +
                                '</button>';
                        } else if (sub.status === 'protected_responsive') {
                            subProtectReason = sba__('Protected: Required for mobile and tablet responsive srcset.', 'Protected: Required for mobile and tablet responsive srcset.');
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-responsive" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-smartphone"></span> ' + sba__('Responsive Size (Protected)', 'Responsive Size (Protected)') +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-subsize-disabled" disabled title="' + sbaEscapeHtml(subProtectReason) + '">' +
                                '<span class="dashicons dashicons-lock"></span> <span>' + sba__('Protected', 'Protected') + '</span>' +
                                '</button>';
                        } else if (sub.status === 'unknown_unverified' || sub.status === 'scan_incomplete') {
                            subProtectReason = sub.note || sba__('Deletion blocked: Needs verification.', 'Deletion blocked: Needs verification.');
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-warning" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-shield"></span> ' + (sub.status === 'scan_incomplete' ? sba__('Scan Incomplete', 'Scan Incomplete') : sba__('Unknown — Needs Verification', 'Unknown — Needs Verification')) +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-subsize-disabled" disabled title="' + sbaEscapeHtml(subProtectReason) + '">' +
                                '<span class="dashicons dashicons-shield"></span> <span>' + sba__('Blocked', 'Blocked') + '</span>' +
                                '</button>';
                        } else if (sub.status === 'unused_eligible') {
                            isSubDeletable = true;
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-redundant" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-warning"></span> ' + sba__('Unused — Eligible for Deletion', 'Unused — Eligible for Deletion') +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Delete Size', 'Delete Size') + '</span>' +
                                '</button>';
                        } else if (sub.status === 'core_standard_size') {
                            isSubDeletable = true;
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-blue" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-admin-settings"></span> ' + sba__('Standard Core Size', 'Standard Core Size') +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Delete Size', 'Delete Size') + '</span>' +
                                '</button>';
                        } else if (sub.status === 'unverified_template_usage') {
                            isSubDeletable = true;
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-warning" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-visibility"></span> ' + sba__('Theme Size (Unverified Template Usage)', 'Theme Size (Unverified Template Usage)') +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Delete Size', 'Delete Size') + '</span>' +
                                '</button>';
                        } else {
                            // potential_unused or unreferenced
                            isSubDeletable = true;
                            statusBadgeHtml = '<span class="sba-badge-subsize sba-badge-redundant" title="' + sbaEscapeHtml(sub.note || '') + '">' +
                                '<span class="dashicons dashicons-warning"></span> ' + sba__('Unused — Eligible for Deletion', 'Unused — Eligible for Deletion') +
                                '</span>';

                            actionBtnHtml = '<button type="button" class="button button-small sba-btn-delete-subsize" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-file="' + safeSubFile + '">' +
                                '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Delete Size', 'Delete Size') + '</span>' +
                                '</button>';
                        }

                        var subCbHtml = '';
                        if (isSubDeletable) {
                            eligibleSubsizesCount++;
                            subCbHtml = '<td class="sba-subsize-cb-col">' +
                                '<input type="checkbox" class="sba-subsize-item-cb" value="' + safeSubKey + '" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" data-bytes="' + rawSubBytes + '" data-file="' + safeSubFile + '" aria-label="' + sba__('Select', 'Select') + ' ' + safeSubKey + '" />' +
                                '</td>';
                        } else {
                            var disabledCbTitle = subProtectReason || sba__('Protected thumbnail variation cannot be selected for deletion.', 'Protected thumbnail variation cannot be selected for deletion.');
                            subCbHtml = '<td class="sba-subsize-cb-col">' +
                                '<input type="checkbox" class="sba-subsize-item-cb" disabled="disabled" data-att-id="' + safeId + '" data-size-key="' + safeSubKey + '" title="' + sbaEscapeHtml(disabledCbTitle) + '" aria-label="' + safeSubKey + ' (' + sba__('Protected', 'Protected') + ')" />' +
                                '</td>';
                        }

                        subsizesItemsHtml += '<tr id="sba-subsize-item-' + safeId + '-' + safeSubKey + '">' +
                            subCbHtml +
                            '<td><code class="sba-subsize-code">' + safeSubKey + '</code></td>' +
                            '<td style="color: #334155; font-family: monospace; font-size: 11px;">' + safeSubFile + '</td>' +
                            '<td>' + safeSubDim + '</td>' +
                            '<td><strong>' + safeSubBytes + '</strong></td>' +
                            '<td>' + statusBadgeHtml + '</td>' +
                            '<td style="text-align: right;">' + actionBtnHtml + '</td>' +
                            '</tr>';
                    }

                    var selectAllDisabledAttr = eligibleSubsizesCount === 0 ? ' disabled="disabled"' : '';
                    subsizeAccordionRowHtml = '<tr id="sba-subsizes-row-' + safeId + '" class="sba-subsizes-accordion-row" style="display: none;">' +
                        '<td colspan="6" class="sba-subsizes-accordion-cell">' +
                        '<div class="sba-subsizes-panel">' +
                        '<div class="sba-subsizes-panel-header">' +
                        '<div class="sba-subsizes-panel-title">' +
                        '<span class="dashicons dashicons-images-alt"></span> ' +
                        '<strong>' + sba__('Generated Thumbnail Variations', 'Generated Thumbnail Variations') + ' (' + subsizesCount + ')</strong>' +
                        '</div>' +
                        '<div class="sba-subsizes-panel-desc">' +
                        sba__('Standard responsive sizes are protected for mobile devices. Redundant sizes can be safely pruned to free disk space.', 'Standard responsive sizes are protected for mobile devices. Redundant sizes can be safely pruned to free disk space.') +
                        '</div>' +
                        '</div>' +
                        '<div id="sba-subsizes-bulk-bar-' + safeId + '" class="sba-subsizes-bulk-bar" style="display: none;">' +
                        '<div class="sba-subsizes-bulk-bar-left">' +
                        '<span class="dashicons dashicons-yes-alt"></span>' +
                        '<span class="sba-subsizes-selected-summary">0 ' + sba__('selected', 'selected') + '</span>' +
                        '<button type="button" class="button-link sba-subsizes-clear-btn" data-att-id="' + safeId + '">' + sba__('Clear', 'Clear') + '</button>' +
                        '</div>' +
                        '<div class="sba-subsizes-bulk-bar-actions">' +
                        '<button type="button" class="button button-small sba-btn-delete-subsizes-bulk" data-att-id="' + safeId + '">' +
                        '<span class="dashicons dashicons-trash"></span> ' +
                        '<span>' + sba__('Delete Selected Sizes', 'Delete Selected Sizes') + ' (<span class="sba-subsizes-selected-count">0</span>)</span>' +
                        '</button>' +
                        '</div>' +
                        '</div>' +
                        '<div class="sba-subsizes-table-wrap">' +
                        '<table class="sba-subsizes-table">' +
                        '<thead>' +
                        '<tr>' +
                        '<th class="sba-subsize-cb-col">' +
                        '<input type="checkbox" class="sba-subsizes-select-all" data-att-id="' + safeId + '" aria-label="' + sba__('Select All Eligible Sizes', 'Select All Eligible Sizes') + '" title="' + sba__('Select All Eligible Sizes', 'Select All Eligible Sizes') + '"' + selectAllDisabledAttr + ' />' +
                        '</th>' +
                        '<th style="width: 15%;">' + sba__('Size Key', 'Size Key') + '</th>' +
                        '<th style="width: 24%;">' + sba__('Filename', 'Filename') + '</th>' +
                        '<th style="width: 14%;">' + sba__('Dimensions', 'Dimensions') + '</th>' +
                        '<th style="width: 11%;">' + sba__('File Size', 'File Size') + '</th>' +
                        '<th style="width: 24%;">' + sba__('Responsive / Audit Status', 'Responsive / Audit Status') + '</th>' +
                        '<th style="width: 12%; text-align: right;">' + sba__('Action', 'Action') + '</th>' +
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
                    subsizeToggleHtml = '<div class="sba-subsize-badge"><span class="dashicons dashicons-images-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: middle;"></span> + ' + subsizeCount + ' ' + sba__('thumbnails', 'thumbnails') + '</div>';
                }

                // 3. Usage Context Column
                var usageHtml = '';
                if (isItemQuarantined) {
                    usageHtml = '<span class="sba-usage-tag sba-usage-tag-quarantined"><span class="dashicons dashicons-shield"></span> ' + sba__('In Quarantine Trash', 'In Quarantine Trash') + '</span>';
                } else if (item.usage_places && item.usage_places.length > 0) {
                    var placesCount = item.usage_places.length;
                    usageHtml = '<div style="font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px;">' +
                        sba__('Used in ', 'Used in ') + placesCount + ' ' + (placesCount === 1 ? sba__('location:', 'location:') : sba__('locations:', 'locations:')) +
                        '</div><div class="sba-usage-list">';

                    var maxShow = Math.min(placesCount, 3);
                    for (var pi = 0; pi < maxShow; pi++) {
                        var place = item.usage_places[pi];
                        var pTitle = sbaEscapeHtml(place.title || sba__('Untitled Post', 'Untitled Post'));
                        var pType = place.type || 'Content';

                        var tagClass = 'sba-usage-tag-content';
                        var iconClass = 'dashicons-admin-post';
                        var typeLabel = '';

                        if (pType === 'Featured') {
                            tagClass = 'sba-usage-tag-featured';
                            iconClass = 'dashicons-star-filled';
                            typeLabel = ' (' + sba__('Featured', 'Featured') + ')';
                        } else if (pType === 'Product Gallery') {
                            tagClass = 'sba-usage-tag-product';
                            iconClass = 'dashicons-cart';
                            typeLabel = ' (' + sba__('Gallery', 'Gallery') + ')';
                        } else if (pType === 'Logo' || pType === 'Site Icon') {
                            tagClass = 'sba-usage-tag-theme';
                            iconClass = 'dashicons-admin-appearance';
                            typeLabel = ' (' + sbaEscapeHtml(pType) + ')';
                        } else if (pType === 'Attached Parent') {
                            tagClass = 'sba-usage-tag-content';
                            iconClass = 'dashicons-paperclip';
                            typeLabel = ' (' + sba__('Attached', 'Attached') + ')';
                        }

                        usageHtml += '<div class="sba-usage-tag ' + tagClass + '" title="' + pTitle + '">' +
                            '<span class="dashicons ' + iconClass + '"></span>' +
                            '<span><strong>' + pTitle + '</strong>' + typeLabel + '</span>' +
                            '</div>';
                    }

                    if (placesCount > 3) {
                        usageHtml += '<div class="sba-usage-more">+ ' + (placesCount - 3) + ' ' + sba__('more locations', 'more locations') + '</div>';
                    }
                    usageHtml += '</div>';
                } else {
                    usageHtml = '<span class="sba-usage-tag sba-usage-tag-unused"><span class="dashicons dashicons-no-alt"></span> ' + sba__('0 References (Unlinked)', '0 References (Unlinked)') + '</span>';
                }

                // 4. File Size & Savings Column
                var sizeHtml = '';
                if (isItemQuarantined) {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                        '<div class="sba-table-source">' + sba__('Isolated on disk', 'Isolated on disk') + '</div>';
                } else if (item.category === 'unused') {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                        '<div class="sba-savings-tag sba-savings-reclaimable" style="margin-top: 4px;">' +
                        '<span class="dashicons dashicons-trash" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                        sba__('Potentially Reclaimable', 'Potentially Reclaimable') +
                        '</div>';
                } else if (hasBackup && (item.saved_bytes > 0 || (item.original_size_bytes && item.file_size_bytes && item.original_size_bytes > item.file_size_bytes))) {
                    var safeOrigSize = sbaEscapeHtml(item.original_size_formatted || (item.original_size_bytes ? formatBytes(item.original_size_bytes) : ''));
                    var safeCurrSize = safeSize;
                    var actualSavedBytes = item.saved_bytes || (item.original_size_bytes - item.file_size_bytes);
                    var safeSavedFmt = sbaEscapeHtml(item.saved_bytes_formatted || formatBytes(actualSavedBytes));
                    var savedPct = item.savings_percent || (item.original_size_bytes ? Math.round((actualSavedBytes / item.original_size_bytes) * 100) : 0);

                    var webpTagHtml = '';
                    if (hasWebP) {
                        webpTagHtml = '<div class="sba-webp-tag" style="margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px;" title="' + sba__('WebP companion image generated and active', 'WebP companion image generated and active') + '">' +
                            '<span class="dashicons dashicons-yes" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                            sba__('WebP Available', 'WebP Available') +
                            '</div>';
                    }

                    sizeHtml = '<div style="font-size: 13px;">' +
                        '<span style="text-decoration: line-through; color: #94a3b8; font-weight: 500;">' + safeOrigSize + '</span> ' +
                        '<span style="color: #94a3b8; margin: 0 2px;">→</span> ' +
                        '<span style="font-weight: 700; color: #0f172a;">' + safeCurrSize + '</span>' +
                        '</div>' +
                        '<div class="sba-savings-tag" style="margin-top: 4px; color: #16a34a; font-weight: 600;">' +
                        '<span class="dashicons dashicons-arrow-down-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                        sba__('Saved ', 'Saved ') + safeSavedFmt + ' (' + savedPct + '%)' +
                        '</div>' +
                        webpTagHtml;
                } else if (item.category === 'oversized' && item.potential_savings_bytes > 0 && safeSavings) {
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' +
                        safeSize + ' <span style="color: #94a3b8; font-weight: normal; margin: 0 3px;">→</span> <span style="color: #15803d; font-weight: 700;">~' + safeEstSize + '</span>' +
                        '</div>' +
                        '<div class="sba-savings-tag" style="margin-top: 4px;">' +
                        '<span class="dashicons dashicons-arrow-down-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                        sba__('Save ~', 'Save ~') + safeSavings +
                        '</div>';
                } else {
                    var webpTagHtml = '';
                    if (hasWebP) {
                        webpTagHtml = '<div class="sba-webp-tag" style="margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px;" title="' + sba__('WebP companion image generated and active', 'WebP companion image generated and active') + '">' +
                            '<span class="dashicons dashicons-yes" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                            sba__('WebP Available', 'WebP Available') +
                            '</div>';
                    }
                    sizeHtml = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + safeSize + '</div>' +
                        '<div class="sba-table-source" style="color: #16a34a; font-weight: 600; margin-top: 4px;">' +
                        '<span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span> ' +
                        sba__('Web Optimized', 'Web Optimized') +
                        '</div>' +
                        webpTagHtml;
                }

                // Build Edit in WP button
                var editButtonHtml = '';
                if (safeEditUrl) {
                    editButtonHtml = '<a href="' + safeEditUrl + '" target="_blank" class="button button-small sba-btn-edit-wp">' +
                        '<span class="dashicons dashicons-edit"></span> <span>' +
                        sba__('Edit in WP', 'Edit in WP') + '</span>' +
                        '</a>';
                } else {
                    editButtonHtml = '<button type="button" class="button button-small sba-btn-edit-wp" disabled style="opacity: 0.5; cursor: not-allowed;" title="' + sba__('Attachment editor link unavailable', 'Attachment editor link unavailable') + '">' +
                        '<span class="dashicons dashicons-edit"></span> <span>' +
                        sba__('Edit in WP', 'Edit in WP') + '</span>' +
                        '</button>';
                }

                // 5. Recommendation & Action Column
                var actionHtml = '';
                if (isItemQuarantined) {
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-shield"></span> ' + sba__('Quarantined', 'Quarantined') + '</span></div>' +
                        '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                        '<button type="button" class="button sba-btn-restore-item" data-id="' + safeId + '" data-name="' + safeName + '">' +
                        '<span class="dashicons dashicons-undo"></span> <span>' + sba__('Restore', 'Restore') + '</span>' +
                        '</button>' +
                        '<button type="button" class="button sba-btn-delete-single-quarantine" data-id="' + safeId + '" data-name="' + safeName + '" title="' + sba__('Permanently delete this quarantined file', 'Permanently delete this quarantined file') + '">' +
                        '<span class="dashicons dashicons-trash"></span> <span>' + sba__('Delete', 'Delete') + '</span>' +
                        '</button>' +
                        '</div>';
                } else if (item.category === 'unused') {
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-orphaned"><span class="dashicons dashicons-shield"></span> ' + (safeBadgeText || sba__('Review & Quarantine', 'Review & Quarantine')) + '</span></div>' +
                        '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                        '<button type="button" class="button sba-btn-quarantine-item" data-id="' + safeId + '" data-name="' + safeName + '">' +
                        '<span class="dashicons dashicons-shield"></span> <span>' + sba__('Move to Quarantine', 'Move to Quarantine') + '</span>' +
                        '</button>' +
                        editButtonHtml +
                        '</div>';
                } else if (item.category === 'oversized' && isOversized) {
                    var optimizeBtnHtml = '';
                    if (item.has_backup) {
                        optimizeBtnHtml = '<button type="button" class="button button-small sba-btn-rollback-item" data-id="' + safeId + '" title="' + sba__('Rollback to original uncompressed image', 'Rollback to original uncompressed image') + '">' +
                            '<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>' +
                            '</button>' +
                            '<button type="button" class="button button-small button-primary sba-btn-optimize-item" data-id="' + safeId + '" data-w="' + (item.recommended_width || 1200) + '" data-h="' + (item.recommended_height || 800) + '" title="' + sba__('Resize and optimize this image safely', 'Resize and optimize this image safely') + '">' +
                            '<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Scale Further', 'Scale Further') + '</span>' +
                            '</button>';
                    } else {
                        optimizeBtnHtml = '<button type="button" class="button button-small button-primary sba-btn-optimize-item" data-id="' + safeId + '" data-w="' + (item.recommended_width || 1920) + '" data-h="' + (item.recommended_height || 1080) + '" title="' + sba__('Resize and optimize this image safely', 'Resize and optimize this image safely') + '">' +
                            '<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Optimize & WebP', 'Optimize & WebP') + '</span>' +
                            '</button>';
                    }

                    var overBadgeText = item.recommendation_text ? sbaEscapeHtml(item.recommendation_text) : safeBadgeText;
                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill ' + badgeClass + '"><span class="dashicons dashicons-warning"></span> ' + overBadgeText + '</span></div>' +
                        '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                        optimizeBtnHtml +
                        editButtonHtml +
                        '</div>';
                } else {
                    var badgeText = (targetReached || hasBackup || isResized) ?
                        sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.') :
                        (item.recommendation_badge || sba__('Web Optimized', 'Web Optimized'));

                    var rollbackBtnHtml = '';
                    if (hasBackup) {
                        rollbackBtnHtml = '<button type="button" class="button button-small sba-btn-rollback-item" data-id="' + safeId + '" title="' + sba__('Rollback to original uncompressed image', 'Rollback to original uncompressed image') + '">' +
                            '<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>' +
                            '</button>';
                    }

                    actionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-active" title="' + sbaEscapeHtml(badgeText) + '"><span class="dashicons dashicons-yes"></span> ' + sbaEscapeHtml(badgeText) + '</span></div>' +
                        '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                        rollbackBtnHtml +
                        editButtonHtml +
                        '</div>';
                }

                // Checkbox Column (rendered for every media row)
                var isChecked = selectedMediaIds.indexOf(safeId) !== -1;
                var rowSelectedClass = isChecked ? ' sba-row-selected' : '';
                var cbHtml = buildMediaRowCheckboxHtml(safeId, safeName, isItemQuarantined);

                var rowHtml = '<tr id="sba-media-row-' + safeId + '" class="' + rowSelectedClass + '">' +
                    cbHtml +
                    '<td class="sba-col-preview" style="text-align: center;">' + previewHtml + '</td>' +
                    '<td class="sba-col-info">' +
                    '<strong class="sba-table-name" style="font-size: 13px; color: #0f172a;">' + safeName + '</strong>' +
                    titleSubtext +
                    pathHtml +
                    dateHtml +
                    dimHtml +
                    subsizeToggleHtml +
                    '</td>' +
                    '<td class="sba-col-usage">' + usageHtml + '</td>' +
                    '<td class="sba-col-size">' + sizeHtml + '</td>' +
                    '<td class="sba-col-actions" style="text-align: right;">' + actionHtml + '</td>' +
                    '</tr>' + subsizeAccordionRowHtml;

                $tbody.append(rowHtml);
            });

            updateMediaSelectAllState();
            updateBulkToolbar();
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
        $('.sba-media-filter-btn').on('click', function (e) {
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
        $('#sba-media-search').on('input', function () {
            renderMediaTable();
        });

        // Media KPI Cards Click to Filter
        $('#sba-card-media-oversized, #sba-stat-media-oversized').on('click', function (e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="oversized"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        $('#sba-card-media-unused, #sba-stat-media-unused').on('click', function (e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="unused"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        $('#sba-card-media-total, #sba-stat-media-size').on('click', function (e) {
            e.preventDefault();
            $('.sba-media-filter-btn[data-filter="all"]').trigger('click');
            var $table = $('#sba-media-table-container');
            if ($table.length && $table.is(':visible')) {
                $('html, body').animate({ scrollTop: $table.offset().top - 80 }, 300);
            }
        });

        // Trigger Media Scan
        $('#sba-run-media-scan').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-btn-label');
            var originalText = $label.text();

            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $label.text(sba__('Scanning Media Library...', 'Scanning Media Library...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_media_files',
                _ajax_nonce: sbaNonce
            }, function (response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(sba__('Re-scan Media Files', 'Re-scan Media Files'));

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
                    var potReclaimableMb = d.potentially_reclaimable_mb !== undefined ? parseFloat(d.potentially_reclaimable_mb) : reclaimableSpaceMb;
                    var estSavingsMb = d.estimated_optimization_savings_mb !== undefined ? parseFloat(d.estimated_optimization_savings_mb) : potentialSavingsMb;
                    var backupStorageMb = d.backup_storage_consumed_mb !== undefined ? parseFloat(d.backup_storage_consumed_mb) : parseFloat(d.quarantined_size_mb || 0);
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

                    var noticeMessage = sba__('Media scan complete: Scanned %1$d attachments. %2$d unused, %3$d oversized (%4$s MB total savings opportunity).', 'Media scan complete: Scanned %1$d attachments. %2$d unused, %3$d oversized (%4$s MB total savings opportunity).')
                        .replace('%1$d', d.total_scanned || 0)
                        .replace('%2$d', unusedMedia.length)
                        .replace('%3$d', oversizedMedia.length)
                        .replace('%4$s', (reclaimableSpaceMb + potentialSavingsMb).toFixed(2));

                    showNotice(noticeMessage, false);
                } else {
                    showNotice(response.data || sba__('Failed to scan media files.', 'Failed to scan media files.'), true);
                }
            }).fail(function () {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__('Server error while scanning media library.', 'Server error while scanning media library.'), true);
            });
        });

        // Move Item to Quarantine Trash
        $(document).on('click', '.sba-btn-quarantine-item', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Moving...', 'Moving...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_quarantine_media',
                _ajax_nonce: sbaNonce,
                attachment_id: attId
            }, function (response) {
                if (response.success && response.data) {
                    var itemIndex = unusedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    var movedItem = null;
                    if (itemIndex !== -1) {
                        movedItem = unusedMedia.splice(itemIndex, 1)[0];
                        reclaimableSpaceMb = Math.max(0, reclaimableSpaceMb - ((movedItem.file_size_bytes || 0) / (1024 * 1024)));
                    }

                    var allIndex = allMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    if (allIndex !== -1) {
                        allMedia.splice(allIndex, 1);
                    }

                    if (response.data.item) {
                        quarantinedMedia.unshift(response.data.item);
                    } else if (movedItem) {
                        quarantinedMedia.unshift(movedItem);
                    }

                    removeAttachmentFromSecondaryViews(attId);
                    var sIdx = selectedMediaIds.indexOf(attId);
                    if (sIdx !== -1) {
                        selectedMediaIds.splice(sIdx, 1);
                        selectedQuarantineIds = selectedMediaIds;
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
                    showNotice(response.data.message || sba__('Item successfully quarantined.', 'Item successfully quarantined.'), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-shield"></span> ' + sba__('Move to Quarantine Trash', 'Move to Quarantine Trash'));
                    showNotice(response.data || sba__('Failed to quarantine item.', 'Failed to quarantine item.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-shield"></span> ' + sba__('Move to Quarantine Trash', 'Move to Quarantine Trash'));
                showNotice(sba__('Server communication error while quarantining item.', 'Server communication error while quarantining item.'), true);
            });
        });

        // Restore Single Item from Quarantine
        $(document).on('click', '.sba-btn-restore-item', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Restoring...', 'Restoring...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_restore_media',
                _ajax_nonce: sbaNonce,
                attachment_id: attId
            }, function (response) {
                if (response.success && response.data) {
                    var qIndex = quarantinedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
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
                    showNotice(response.data.message || sba__('Item successfully restored to Media Library.', 'Item successfully restored to Media Library.'), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-undo"></span> ' + sba__('Restore', 'Restore'));
                    showNotice(response.data || sba__('Failed to restore item.', 'Failed to restore item.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-undo"></span> ' + sba__('Restore', 'Restore'));
                showNotice(sba__('Server communication error while restoring item.', 'Server communication error while restoring item.'), true);
            });
        });

        // Header "Select All" Checkbox for Media Library
        $(document).on('change', '#sba-media-select-all, #sba-quarantine-select-all', function () {
            var isChecked = $(this).is(':checked');
            $('.sba-media-item-cb:not(:disabled), .sba-quarantine-item-cb:not(:disabled)').each(function () {
                var $cb = $(this);
                var id = parseInt($cb.val(), 10);
                if (!id || id <= 0) {
                    return;
                }
                $cb.prop('checked', isChecked);
                var $row = $cb.closest('tr');
                if (isChecked) {
                    $row.addClass('sba-row-selected');
                    if (selectedMediaIds.indexOf(id) === -1) {
                        selectedMediaIds.push(id);
                    }
                } else {
                    $row.removeClass('sba-row-selected');
                    var sIdx = selectedMediaIds.indexOf(id);
                    if (sIdx !== -1) {
                        selectedMediaIds.splice(sIdx, 1);
                    }
                }
            });
            selectedQuarantineIds = selectedMediaIds;
            updateMediaSelectAllState();
            updateQuarantineSelectAllState();
            updateQuarantineActionButtons();
            updateBulkToolbar();
        });

        // Individual Row Checkbox for Media Library
        $(document).on('change', '.sba-media-item-cb, .sba-quarantine-item-cb', function () {
            var $cb = $(this);
            var id = parseInt($cb.val(), 10);
            if (!id || id <= 0) {
                return;
            }
            var isChecked = $cb.is(':checked');
            var $matching = $('.sba-media-item-cb[value="' + id + '"], .sba-quarantine-item-cb[value="' + id + '"]');
            $matching.prop('checked', isChecked);

            if (isChecked) {
                $matching.closest('tr').addClass('sba-row-selected');
                if (selectedMediaIds.indexOf(id) === -1) {
                    selectedMediaIds.push(id);
                }
            } else {
                $matching.closest('tr').removeClass('sba-row-selected');
                var sIdx = selectedMediaIds.indexOf(id);
                if (sIdx !== -1) {
                    selectedMediaIds.splice(sIdx, 1);
                }
            }

            selectedQuarantineIds = selectedMediaIds;
            updateMediaSelectAllState();
            updateQuarantineSelectAllState();
            updateQuarantineActionButtons();
            updateBulkToolbar();
        });

        // Clear Selection Button
        $(document).on('click', '#sba-bulk-btn-clear', function (e) {
            e.preventDefault();
            selectedMediaIds = [];
            selectedQuarantineIds = [];
            $('.sba-media-item-cb, #sba-media-select-all, #sba-quarantine-select-all').prop('checked', false).prop('indeterminate', false);
            $('.sba-row-selected').removeClass('sba-row-selected');
            updateBulkToolbar();
            updateQuarantineActionButtons();
        });

        // Prevent thumbnail link clicks from triggering row selection or parent click bubbling
        $(document).on('click', '.sba-thumb-link', function (e) {
            e.stopPropagation();
        });

        // Delete Confirmation Checkbox in Bulk Modal
        $(document).on('change', '#sba-bulk-modal-delete-ack', function () {
            var isChecked = $(this).is(':checked');
            var action = $('#sba-bulk-modal-btn-proceed').data('action');
            if (action === 'delete') {
                $('#sba-bulk-modal-btn-proceed').prop('disabled', !isChecked);
            }
        });

        // Bulk Action Button Click
        $(document).on('click', '.sba-bulk-action-btn', function (e) {
            e.preventDefault();
            if (activeBulkProcessing) {
                return;
            }

            var action = $(this).data('action');
            var selectedObjs = getSelectedMediaObjects(selectedMediaIds);
            if (selectedObjs.length === 0) {
                return;
            }

            if (action === 'edit') {
                if (selectedObjs.length === 1 && selectedObjs[0].edit_url) {
                    window.open(selectedObjs[0].edit_url, '_blank');
                } else {
                    window.open('upload.php', '_blank');
                }
                return;
            }

            openBulkConfirmationModal(action, selectedObjs);
        });

        // Open Confirmation Modal and prepare details
        function openBulkConfirmationModal(action, selectedObjs) {
            var $modal = $('#sba-media-bulk-modal');
            var eligible = [];
            var skipped = [];
            var actionTitle = '';
            var actionDesc = '';
            var actionIcon = 'dashicons-admin-generic';
            var proceedLabel = sba__('Proceed', 'Proceed');
            var proceedClass = 'button-primary';
            var savingsHtml = '0 KB';
            var showSavings = false;
            var safetyNote = '';

            if (action === 'optimize') {
                actionTitle = sba__('Confirm Bulk Image Optimization', 'Confirm Bulk Image Optimization');
                actionDesc = sba__('Selected images will be safely downscaled and compressed. Original backups (.sba-orig) will be created before modification.', 'Selected images will be safely downscaled and compressed. Original backups (.sba-orig) will be created before modification.');
                actionIcon = 'dashicons-image-rotate';
                proceedLabel = sba__('Optimize Eligible Images', 'Optimize Eligible Images');
                proceedClass = 'button-primary';
                safetyNote = sba__('Original files are preserved as .sba-orig backups and can be rolled back individually anytime.', 'Original files are preserved as .sba-orig backups and can be rolled back individually anytime.');
                showSavings = true;

                var totalSavingsBytes = 0;
                selectedObjs.forEach(function (item) {
                    var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                    if (isQuar) {
                        skipped.push({ item: item, reason: sba__('Currently in quarantine trash', 'Currently in quarantine trash') });
                    } else if (item.has_backup) {
                        skipped.push({ item: item, reason: sba__('Already optimized with active backup', 'Already optimized with active backup') });
                    } else {
                        eligible.push(item);
                        totalSavingsBytes += (item.potential_savings_bytes || 0);
                    }
                });
                savingsHtml = (totalSavingsBytes > 0) ? formatBytes(totalSavingsBytes) : sba__('Calculated during optimization', 'Calculated during optimization');
            } else if (action === 'quarantine') {
                actionTitle = sba__('Confirm Move to Quarantine', 'Confirm Move to Quarantine');
                actionDesc = sba__('Selected media and all generated thumbnail sub-sizes will be safely moved into /wp-content/uploads/sba-quarantine/.', 'Selected media and all generated thumbnail sub-sizes will be safely moved into /wp-content/uploads/sba-quarantine/.');
                actionIcon = 'dashicons-shield';
                proceedLabel = sba__('Move to Quarantine', 'Move to Quarantine');
                proceedClass = 'button-secondary';
                safetyNote = sba__('Quarantine uses a verified filesystem journal and can be undone with a single-click restore.', 'Quarantine uses a verified filesystem journal and can be undone with a single-click restore.');
                showSavings = true;

                var totalQuarBytes = 0;
                selectedObjs.forEach(function (item) {
                    var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                    if (isQuar) {
                        skipped.push({ item: item, reason: sba__('Already in quarantine trash', 'Already in quarantine trash') });
                    } else {
                        eligible.push(item);
                        totalQuarBytes += (item.file_size_bytes || item.file_size || item.size || 0);
                    }
                });
                savingsHtml = formatBytes(totalQuarBytes);
            } else if (action === 'restore') {
                actionTitle = sba__('Confirm Restore from Quarantine', 'Confirm Restore from Quarantine');
                actionDesc = sba__('Selected quarantined files and their thumbnail variations will be restored back to active media directories.', 'Selected quarantined files and their thumbnail variations will be restored back to active media directories.');
                actionIcon = 'dashicons-undo';
                proceedLabel = sba__('Restore Eligible Images', 'Restore Eligible Images');
                proceedClass = 'button-primary';
                safetyNote = sba__('Files are verified and reconnected with WordPress attachment records.', 'Files are verified and reconnected with WordPress attachment records.');
                showSavings = false;

                selectedObjs.forEach(function (item) {
                    var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                    if (!isQuar) {
                        skipped.push({ item: item, reason: sba__('Not currently quarantined', 'Not currently quarantined') });
                    } else {
                        eligible.push(item);
                    }
                });
            } else if (action === 'delete') {
                actionTitle = sba__('Confirm Permanent Deletion', 'Confirm Permanent Deletion');
                actionDesc = sba__('WARNING: This permanently deletes the physical media files, thumbnail variations, and database records. This action cannot be undone.', 'WARNING: This permanently deletes the physical media files, thumbnail variations, and database records. This action cannot be undone.');
                actionIcon = 'dashicons-trash';
                proceedLabel = sba__('Permanently Delete', 'Permanently Delete');
                proceedClass = 'sba-btn-danger';
                safetyNote = sba__('CAUTION: Files and database records will be permanently removed. Backups recommended.', 'CAUTION: Files and database records will be permanently removed. Backups recommended.');
                showSavings = true;

                var totalDelBytes = 0;
                selectedObjs.forEach(function (item) {
                    eligible.push(item);
                    totalDelBytes += (item.file_size_bytes || item.file_size || item.size || 0);
                });
                savingsHtml = formatBytes(totalDelBytes);
            }

            // Update modal headers & icons
            $('#sba-bulk-modal-action-title').text(actionTitle);
            $('#sba-bulk-modal-icon').attr('class', 'dashicons ' + actionIcon + ' sba-modal-icon-info');
            $('#sba-bulk-modal-desc').text(actionDesc);
            $('#sba-bulk-modal-safety-note').text(safetyNote);

            // Update stats
            $('#sba-bulk-modal-selected-count').text(selectedObjs.length + ' ' + (selectedObjs.length === 1 ? sba__('file', 'file') : sba__('files', 'files')));
            $('#sba-bulk-modal-eligible-count').text(eligible.length + ' ' + (eligible.length === 1 ? sba__('file', 'file') : sba__('files', 'files')));
            $('#sba-bulk-modal-skipped-count').text(skipped.length + ' ' + (skipped.length === 1 ? sba__('file', 'file') : sba__('files', 'files')));

            if (showSavings) {
                $('#sba-bulk-modal-savings-row').show();
                $('#sba-bulk-modal-savings-value').text(savingsHtml);
                $('#sba-bulk-modal-savings-label').text(action === 'optimize' ? sba__('Estimated Savings:', 'Estimated Savings:') : sba__('Disk Space to Reclaim:', 'Disk Space to Reclaim:'));
            } else {
                $('#sba-bulk-modal-savings-row').hide();
            }

            // Skipped Notice
            if (skipped.length > 0) {
                var reasonMap = {};
                skipped.forEach(function (s) {
                    reasonMap[s.reason] = (reasonMap[s.reason] || 0) + 1;
                });
                var reasonTexts = [];
                for (var r in reasonMap) {
                    reasonTexts.push(reasonMap[r] + ' ' + (reasonMap[r] === 1 ? sba__('file', 'file') : sba__('files', 'files')) + ' (' + r + ')');
                }
                $('#sba-bulk-modal-skipped-reasons').text(sba__('Note: Skipped ', 'Note: Skipped ') + reasonTexts.join(', '));
                $('#sba-bulk-modal-skipped-notice').show();
            } else {
                $('#sba-bulk-modal-skipped-notice').hide();
            }

            // Delete Acknowledgment
            if (action === 'delete') {
                $('#sba-bulk-modal-delete-confirm-wrap').show();
                $('#sba-bulk-modal-delete-ack').prop('checked', false);
                $('#sba-bulk-modal-btn-proceed').prop('disabled', true);
            } else {
                $('#sba-bulk-modal-delete-confirm-wrap').hide();
                $('#sba-bulk-modal-btn-proceed').prop('disabled', eligible.length === 0);
            }

            // Button styling & data
            var $proceedBtn = $('#sba-bulk-modal-btn-proceed');
            $proceedBtn.removeClass('button-primary button-secondary sba-btn-danger').addClass(proceedClass);
            $('#sba-bulk-modal-proceed-label').text(proceedLabel);
            $proceedBtn.data('action', action);
            $proceedBtn.data('eligible', eligible);
            $proceedBtn.data('skipped-count', skipped.length);

            // Populate File List
            var $list = $('#sba-bulk-modal-file-list');
            $list.empty();
            $('#sba-bulk-modal-file-list-summary').text(eligible.length + ' ' + sba__('eligible', 'eligible') + (skipped.length ? ', ' + skipped.length + ' ' + sba__('skipped', 'skipped') : ''));

            eligible.forEach(function (item) {
                var sizeText = item.file_size_formatted || item.size_formatted || '';
                var dimText = item.original_dimensions || item.dimensions || '';
                var metaText = [dimText, sizeText].filter(Boolean).join(' • ');
                var itemPreview = item.preview_url || '';
                var itemFull = item.full_url || item.preview_url || '';
                var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                var thumbHtml = '';
                if (itemFull) {
                    var thumbInner = itemPreview
                        ? '<img src="' + sbaEscapeHtml(itemPreview) + '" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid #cbd5e1; vertical-align: middle;" alt="' + sbaEscapeHtml(item.name) + '" onerror="this.style.display=\'none\';" />'
                        : '<span class="dashicons ' + (isQuar ? 'dashicons-shield' : 'dashicons-format-image') + '" style="font-size: 24px; width: 24px; height: 24px; color: #94a3b8; vertical-align: middle;"></span>';
                    thumbHtml = '<a href="' + sbaEscapeHtml(itemFull) + '" target="_blank" rel="noopener noreferrer" class="sba-thumb-link sba-modal-thumb-link" title="' + (isQuar ? sba__('Open quarantined image in new tab', 'Open quarantined image in new tab') : sba__('Open full image in new tab', 'Open full image in new tab')) + '" aria-label="' + sba__('Open image in new tab', 'Open image in new tab') + ': ' + sbaEscapeHtml(item.name) + '" style="margin-right: 10px; display: inline-flex; align-items: center;">' +
                        thumbInner +
                        '</a>';
                } else {
                    thumbHtml = '<span class="sba-media-thumb-placeholder" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; background: #f1f5f9; border-radius: 4px;"><span class="dashicons ' + (isQuar ? 'dashicons-shield' : 'dashicons-format-image') + '" style="font-size: 18px; width: 18px; height: 18px; color: #94a3b8;"></span></span>';
                }

                var rowHtml = '<div class="sba-file-list-item" style="display: flex; align-items: center; justify-content: space-between;">' +
                    '<div style="display: flex; align-items: center;">' +
                    thumbHtml +
                    '<div><strong>' + sbaEscapeHtml(item.name) + '</strong> <span style="color: #64748b; font-size: 11.5px; margin-left: 6px;">' + sbaEscapeHtml(metaText) + '</span></div>' +
                    '</div>' +
                    '<span class="sba-badge-subsize sba-badge-blue">' + sba__('Eligible', 'Eligible') + '</span>' +
                    '</div>';
                $list.append(rowHtml);
            });

            skipped.forEach(function (s) {
                var item = s.item;
                var itemPreview = item.preview_url || '';
                var itemFull = item.full_url || item.preview_url || '';
                var isQuar = (item.category === 'quarantined' || currentMediaFilter === 'quarantined');
                var thumbHtml = '';
                if (itemFull) {
                    var thumbInner = itemPreview
                        ? '<img src="' + sbaEscapeHtml(itemPreview) + '" style="width: 32px; height: 32px; object-fit: cover; border-radius: 4px; border: 1px solid #cbd5e1; vertical-align: middle; opacity: 0.6;" alt="' + sbaEscapeHtml(item.name) + '" onerror="this.style.display=\'none\';" />'
                        : '<span class="dashicons ' + (isQuar ? 'dashicons-shield' : 'dashicons-format-image') + '" style="font-size: 24px; width: 24px; height: 24px; color: #94a3b8; vertical-align: middle;"></span>';
                    thumbHtml = '<a href="' + sbaEscapeHtml(itemFull) + '" target="_blank" rel="noopener noreferrer" class="sba-thumb-link sba-modal-thumb-link" title="' + (isQuar ? sba__('Open quarantined image in new tab', 'Open quarantined image in new tab') : sba__('Open full image in new tab', 'Open full image in new tab')) + '" aria-label="' + sba__('Open image in new tab', 'Open image in new tab') + ': ' + sbaEscapeHtml(item.name) + '" style="margin-right: 10px; display: inline-flex; align-items: center;">' +
                        thumbInner +
                        '</a>';
                }
                var rowHtml = '<div class="sba-file-list-item is-skipped" style="display: flex; align-items: center; justify-content: space-between;">' +
                    '<div style="display: flex; align-items: center;">' +
                    thumbHtml +
                    '<div><span style="color: #64748b;">' + sbaEscapeHtml(item.name) + '</span></div>' +
                    '</div>' +
                    '<span class="sba-badge-subsize sba-badge-redundant">' + sba__('Skipped: ', 'Skipped: ') + sbaEscapeHtml(s.reason) + '</span>' +
                    '</div>';
                $list.append(rowHtml);
            });

            // Reset execution state in modal
            $('#sba-bulk-modal-progress').hide();
            $('#sba-bulk-progress-bar-fill').css('width', '0%');
            $('#sba-bulk-progress-percent').text('0%');
            $('#sba-bulk-modal-result-banner').hide();
            $('#sba-bulk-modal-btn-cancel').show().prop('disabled', false);
            $('#sba-bulk-modal-btn-proceed').show();
            $('#sba-bulk-modal-btn-done').hide();

            $modal.show();
        }

        // Proceed Click: Sequential Execution with Live Progress
        $(document).on('click', '#sba-bulk-modal-btn-proceed', async function (e) {
            e.preventDefault();
            if (activeBulkProcessing) {
                return;
            }

            var $btn = $(this);
            var action = $btn.data('action');
            var eligible = $btn.data('eligible') || [];
            var skippedCount = $btn.data('skipped-count') || 0;

            if (eligible.length === 0) {
                return;
            }

            activeBulkProcessing = true;
            $btn.prop('disabled', true);
            $('#sba-bulk-modal-btn-cancel').prop('disabled', true);
            $('.sba-bulk-modal-cancel').prop('disabled', true);
            $('#sba-bulk-modal-progress').show();

            var total = eligible.length;
            var completed = 0;
            var failed = 0;
            var failures = [];
            var totalFreedBytes = 0;

            for (var i = 0; i < total; i++) {
                var item = eligible[i];
                var stepNum = i + 1;

                $('#sba-bulk-progress-text').text(
                    sba__('Processing image %1$d of %2$d: %3$s...', 'Processing image %1$d of %2$d: %3$s...')
                        .replace('%1$d', stepNum)
                        .replace('%2$d', total)
                        .replace('%3$s', item.name)
                );

                var pct = Math.round(((stepNum - 1) / total) * 100);
                $('#sba-bulk-progress-bar-fill').css('width', pct + '%');
                $('#sba-bulk-progress-percent').text(pct + '%');

                try {
                    var res = await executeSingleBulkItem(action, item);
                    if (res && res.success) {
                        completed++;
                        if (action === 'delete' && res.data && res.data.freed_bytes) {
                            totalFreedBytes += res.data.freed_bytes;
                        } else if (action === 'optimize' && res.data && res.data.saved_bytes) {
                            totalFreedBytes += res.data.saved_bytes;
                        }
                        applyBulkItemSuccess(action, item, res.data);
                    } else {
                        failed++;
                        var errMsg = (res && res.data) ? res.data : sba__('Server reported an error.', 'Server reported an error.');
                        failures.push({ name: item.name, error: errMsg });
                    }
                } catch (err) {
                    failed++;
                    var netErr = (err && err.responseJSON && err.responseJSON.data) ? err.responseJSON.data : ((err && err.statusText) ? err.statusText : sba__('Request failed.', 'Request failed.'));
                    failures.push({ name: item.name, error: netErr });
                }

                var finishPct = Math.round((stepNum / total) * 100);
                $('#sba-bulk-progress-bar-fill').css('width', finishPct + '%');
                $('#sba-bulk-progress-percent').text(finishPct + '%');
            }

            // Finished loop
            $('#sba-bulk-modal-progress').hide();
            activeBulkProcessing = false;

            // Summary banner
            var $banner = $('#sba-bulk-modal-result-banner');
            var resultMsg = sba__('Action completed: %1$d succeeded, %2$d failed, %3$d skipped.', 'Action completed: %1$d succeeded, %2$d failed, %3$d skipped.')
                .replace('%1$d', completed)
                .replace('%2$d', failed)
                .replace('%3$d', skippedCount);

            if (failed > 0) {
                $banner.removeClass('notice-success').addClass('notice-warning');
                var errListHtml = '<ul style="margin: 4px 0 0 16px; list-style: disc;">';
                failures.forEach(function (f) {
                    errListHtml += '<li><strong>' + sbaEscapeHtml(f.name) + ':</strong> ' + sbaEscapeHtml(f.error) + '</li>';
                });
                errListHtml += '</ul>';
                $('#sba-bulk-modal-result-errors').html(errListHtml).show();
            } else {
                $banner.removeClass('notice-warning').addClass('notice-success');
                $('#sba-bulk-modal-result-errors').hide();
            }

            $('#sba-bulk-modal-result-msg').text(resultMsg);
            $banner.show();

            // Toggle modal buttons
            $btn.hide();
            $('#sba-bulk-modal-btn-cancel').hide();
            $('.sba-bulk-modal-cancel').prop('disabled', false);
            $('#sba-bulk-modal-btn-done').show();

            // Refresh table and storage metrics in place
            renderMediaTable();
            updateBulkToolbar();
        });

        // AJAX helper for each individual bulk item
        function executeSingleBulkItem(action, item) {
            var attId = parseInt(item.id, 10);
            var reqData = {
                _ajax_nonce: sbaNonce
            };

            if (action === 'optimize') {
                reqData.action = 'sba_optimize_image';
                reqData.attachment_id = attId;
                reqData.max_width = item.recommended_width || 1920;
                reqData.max_height = item.recommended_height || 1080;
                reqData.quality = 82;
            } else if (action === 'quarantine') {
                reqData.action = 'sba_quarantine_media';
                reqData.attachment_id = attId;
            } else if (action === 'restore') {
                reqData.action = 'sba_restore_media';
                reqData.attachment_id = attId;
            } else if (action === 'delete') {
                reqData.action = 'sba_bulk_delete_media';
                reqData.attachment_id = attId;
                reqData.confirmed = 'yes';
            }

            return $.ajax({
                url: sbaData.ajax_url,
                type: 'POST',
                data: reqData,
                dataType: 'json'
            });
        }

        function removeAttachmentFromSecondaryViews(attId) {
            if (duplicatesData && Array.isArray(duplicatesData)) {
                duplicatesData = duplicatesData.map(function (group) {
                    if (!group || !Array.isArray(group.items)) {
                        return group;
                    }
                    group.items = group.items.filter(function (it) { return parseInt(it.id, 10) !== attId; });
                    group.count = group.items.length;
                    return group;
                }).filter(function (group) {
                    return group && Array.isArray(group.items) && group.items.length >= 2;
                });
                $('#sba-media-count-duplicates').text(formatNumber(duplicatesData.length));
            }

            if (accessibilityData && Array.isArray(accessibilityData.items)) {
                accessibilityData.items = accessibilityData.items.filter(function (it) {
                    return parseInt(it.id, 10) !== attId;
                });
                $('#sba-media-count-accessibility').text(formatNumber(accessibilityData.items.length));
            }

            if (discrepanciesData) {
                if (Array.isArray(discrepanciesData.missing_originals)) {
                    discrepanciesData.missing_originals = discrepanciesData.missing_originals.filter(function (it) {
                        return parseInt(it.attachment_id, 10) !== attId;
                    });
                }
                if (Array.isArray(discrepanciesData.missing_subsizes)) {
                    discrepanciesData.missing_subsizes = discrepanciesData.missing_subsizes.filter(function (it) {
                        return parseInt(it.attachment_id, 10) !== attId;
                    });
                }
                var discCount = (discrepanciesData.missing_originals ? discrepanciesData.missing_originals.length : 0) +
                    (discrepanciesData.missing_subsizes ? discrepanciesData.missing_subsizes.length : 0) +
                    (discrepanciesData.unattached_files ? discrepanciesData.unattached_files.length : 0);
                $('#sba-media-count-discrepancies').text(formatNumber(discCount));
            }
        }

        // Apply item state change to in-memory datasets
        function applyBulkItemSuccess(action, item, responseData) {
            var attId = parseInt(item.id, 10);

            // Remove from selection
            var sIdx = selectedMediaIds.indexOf(attId);
            if (sIdx !== -1) {
                selectedMediaIds.splice(sIdx, 1);
            }
            selectedQuarantineIds = selectedMediaIds;

            if (action === 'optimize') {
                var optData = (responseData && responseData.data) ? responseData.data : responseData;
                // Update in allMedia
                if (allMedia && Array.isArray(allMedia)) {
                    var mItem = allMedia.find(function (m) { return parseInt(m.id, 10) === attId; });
                    if (mItem) {
                        mItem.is_optimized = true;
                        mItem.has_backup = true;
                        mItem.has_webp = true;
                        mItem.target_reached = true;
                        mItem.is_oversized = false;
                        mItem.category = 'optimized';
                        mItem.category_label = sba__('Optimized', 'Optimized');
                        mItem.badge_class = 'sba-pill-active';
                        var isWebPOpt = (optData && (optData.output_format === 'webp' || optData.is_webp));
                        mItem.recommendation_badge = isWebPOpt ? sba__('Web Optimized (WebP)', 'Web Optimized (WebP)') : sba__('Web Optimized', 'Web Optimized');
                        mItem.recommendation_text = sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.');
                        if (optData) {
                            if (optData.optimized_size_fmt || optData.file_size_formatted) {
                                mItem.file_size_formatted = optData.optimized_size_fmt || optData.file_size_formatted;
                            }
                            if (optData.optimized_size_bytes) {
                                mItem.file_size_bytes = optData.optimized_size_bytes;
                            }
                            if (optData.original_size_bytes) {
                                mItem.original_size_bytes = optData.original_size_bytes;
                                mItem.original_size_formatted = optData.original_size_fmt;
                            }
                            var savedB = (optData.freed_bytes !== undefined) ? optData.freed_bytes : (optData.saved_bytes || 0);
                            if (savedB > 0) {
                                mItem.saved_bytes = savedB;
                                mItem.saved_bytes_formatted = optData.freed_bytes_fmt || formatBytes(savedB);
                            }
                            if (optData.savings_percent) {
                                mItem.savings_percent = optData.savings_percent;
                            }
                            if (optData.original_dimensions && optData.original_dimensions !== 'N/A') {
                                mItem.original_dimensions = optData.original_dimensions;
                            }
                            if (optData.new_dimensions || optData.current_dimensions) {
                                mItem.current_dimensions = optData.new_dimensions || optData.current_dimensions;
                            }
                            mItem.is_resized = (mItem.original_dimensions && mItem.current_dimensions && mItem.original_dimensions !== mItem.current_dimensions && mItem.original_dimensions !== 'Original dimensions unavailable');
                        }
                    }
                }
                // Remove from oversizedMedia
                if (oversizedMedia && Array.isArray(oversizedMedia)) {
                    var oIdx = oversizedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    if (oIdx !== -1) {
                        var oItem = oversizedMedia.splice(oIdx, 1)[0];
                        var freedB = (responseData && responseData.saved_bytes) ? responseData.saved_bytes : (oItem.potential_savings_bytes || 0);
                        potentialSavingsMb = Math.max(0, potentialSavingsMb - (freedB / (1024 * 1024)));
                    }
                }
                // Add to optimizedMedia
                if (optimizedMedia && Array.isArray(optimizedMedia)) {
                    var alreadyOpt = optimizedMedia.some(function (m) { return parseInt(m.id, 10) === attId; });
                    if (!alreadyOpt) {
                        optimizedMedia.unshift(item);
                    }
                }
                $('#sba-stat-media-oversized').text(formatNumber(oversizedMedia.length));
                $('#sba-media-count-oversized').text(formatNumber(oversizedMedia.length));
                $('#sba-media-count-optimized').text(formatNumber(optimizedMedia.length));
                $('#sba-stat-media-savings').text(potentialSavingsMb.toFixed(2) + ' MB');
            } else if (action === 'quarantine') {
                // Remove from allMedia, unusedMedia, oversizedMedia
                if (unusedMedia && Array.isArray(unusedMedia)) {
                    var uIdx = unusedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    if (uIdx !== -1) {
                        unusedMedia.splice(uIdx, 1);
                    }
                }
                if (allMedia && Array.isArray(allMedia)) {
                    var aIdx = allMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    if (aIdx !== -1) {
                        allMedia.splice(aIdx, 1);
                    }
                }
                if (quarantinedMedia && Array.isArray(quarantinedMedia)) {
                    var quarRecord = (responseData && responseData.item) ? responseData.item : item;
                    quarRecord.category = 'quarantined';
                    quarantinedMedia.unshift(quarRecord);
                }
                removeAttachmentFromSecondaryViews(attId);
                $('#sba-media-count-all').text(formatNumber(allMedia.length));
                $('#sba-media-count-unused').text(formatNumber(unusedMedia.length));
                $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                if (quarantinedMedia.length > 0) {
                    $('#sba-media-count-quarantined').removeClass('sba-badge-gray').addClass('sba-badge-red');
                }
            } else if (action === 'restore') {
                // Remove from quarantinedMedia
                if (quarantinedMedia && Array.isArray(quarantinedMedia)) {
                    var qIdx = quarantinedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                    if (qIdx !== -1) {
                        quarantinedMedia.splice(qIdx, 1);
                    }
                }
                item.category = 'unused';
                if (allMedia && Array.isArray(allMedia)) {
                    allMedia.unshift(item);
                }
                if (unusedMedia && Array.isArray(unusedMedia)) {
                    unusedMedia.unshift(item);
                }
                $('#sba-media-count-all').text(formatNumber(allMedia.length));
                $('#sba-media-count-unused').text(formatNumber(unusedMedia.length));
                $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                if (quarantinedMedia.length === 0) {
                    $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                }
            } else if (action === 'delete') {
                // Remove from all datasets
                [allMedia, unusedMedia, oversizedMedia, optimizedMedia, quarantinedMedia].forEach(function (list) {
                    if (list && Array.isArray(list)) {
                        var idx = list.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                        if (idx !== -1) {
                            list.splice(idx, 1);
                        }
                    }
                });
                removeAttachmentFromSecondaryViews(attId);
                $('#sba-media-count-all').text(formatNumber(allMedia.length));
                $('#sba-media-count-unused').text(formatNumber(unusedMedia.length));
                $('#sba-media-count-oversized').text(formatNumber(oversizedMedia.length));
                $('#sba-media-count-optimized').text(formatNumber(optimizedMedia.length));
                $('#sba-media-count-quarantined').text(formatNumber(quarantinedMedia.length));
                if (quarantinedMedia.length === 0) {
                    $('#sba-media-count-quarantined').removeClass('sba-badge-red').addClass('sba-badge-gray');
                }
            }
        }

        // Close bulk confirmation modal
        $(document).on('click', '.sba-bulk-modal-cancel, #sba-bulk-modal-btn-done', function (e) {
            e.preventDefault();
            if (activeBulkProcessing) {
                return;
            }
            $('#sba-media-bulk-modal').hide();
        });

        // Empty Quarantine Permanently or Delete Selected Quarantined Files
        $('#sba-btn-empty-quarantine').on('click', function (e) {
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

            $.post(sbaAjaxUrl, postData, function (response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success && response.data) {
                    if (hasSelection) {
                        var deletedIds = response.data.deleted_ids || selectedQuarantineIds;
                        quarantinedMedia = quarantinedMedia.filter(function (m) {
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
                    showNotice(response.data.message || sba__('Quarantine trash updated successfully.', 'Quarantine trash updated successfully.'), false);
                } else {
                    showNotice(response.data || sba__('Failed to empty quarantine.', 'Failed to empty quarantine.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading');
                showNotice(sba__('Server error while deleting quarantined files.', 'Server error while deleting quarantined files.'), true);
            });
        });

        // Empty All Quarantine (Regardless of Selection)
        $('#sba-btn-empty-all-quarantine').on('click', function (e) {
            e.preventDefault();
            if (!confirm(sba__('Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.', 'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.'))) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sbaAjaxUrl, {
                action: 'sba_empty_quarantine',
                _ajax_nonce: sbaNonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success && response.data) {
                    quarantinedMedia = [];
                    selectedQuarantineIds = [];
                    $('#sba-media-count-quarantined').text('0').removeClass('sba-badge-red').addClass('sba-badge-gray');
                    updateQuarantineActionButtons();
                    renderMediaTable();
                    showNotice(response.data.message || sba__('Quarantine trash emptied successfully.', 'Quarantine trash emptied successfully.'), false);
                } else {
                    showNotice(response.data || sba__('Failed to empty quarantine.', 'Failed to empty quarantine.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                showNotice(sba__('Server error while emptying quarantine.', 'Server error while emptying quarantine.'), true);
            });
        });

        // Restore Selected Quarantined Items
        $('#sba-btn-restore-selected').on('click', function (e) {
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
            }, function (response) {
                $btn.prop('disabled', false).removeClass('is-loading');
                if (response.success && response.data) {
                    quarantinedMedia = quarantinedMedia.filter(function (m) {
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
                    showNotice(response.data.message || sba__('Selected items successfully restored to Media Library.', 'Selected items successfully restored to Media Library.'), false);
                } else {
                    showNotice(response.data || sba__('Failed to restore selected items.', 'Failed to restore selected items.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading');
                showNotice(sba__('Server error while restoring items.', 'Server error while restoring items.'), true);
            });
        });

        // Delete Single Item Permanently from Quarantine
        $(document).on('click', '.sba-btn-delete-single-quarantine', function (e) {
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

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Deleting...', 'Deleting...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_empty_quarantine',
                _ajax_nonce: sbaNonce,
                attachment_ids: [attId]
            }, function (response) {
                if (response.success && response.data) {
                    var qIndex = quarantinedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
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
                    showNotice(response.data.message || sba__('Quarantined file deleted permanently.', 'Quarantined file deleted permanently.'), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__('Delete', 'Delete'));
                    showNotice(response.data || sba__('Failed to delete quarantined item.', 'Failed to delete quarantined item.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__('Delete', 'Delete'));
                showNotice(sba__('Server communication error while deleting quarantined item.', 'Server communication error while deleting quarantined item.'), true);
            });
        });

        // Toggle "View Generated Sizes" Accordion
        $(document).on('click', '.sba-subsize-toggle-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var targetId = $btn.data('target');
            var $targetRow = $(targetId);

            $btn.toggleClass('is-active');
            var isExpanded = $btn.hasClass('is-active');
            $btn.attr('aria-expanded', isExpanded ? 'true' : 'false');
            $targetRow.toggle();
        });

        function updateSubsizesSelectionUI(attId) {
            var $panelRow = $('#sba-subsizes-row-' + attId);
            if (!$panelRow.length) {
                return;
            }
            var $eligible = $panelRow.find('.sba-subsize-item-cb:not(:disabled)');
            var $checked = $eligible.filter(':checked');
            var $selectAll = $panelRow.find('.sba-subsizes-select-all');
            var $bulkBar = $('#sba-subsizes-bulk-bar-' + attId);

            if ($eligible.length === 0) {
                $selectAll.prop({ checked: false, indeterminate: false, disabled: true });
                $bulkBar.hide();
                return;
            }

            $selectAll.prop('disabled', false);
            if ($checked.length === 0) {
                $selectAll.prop({ checked: false, indeterminate: false });
            } else if ($checked.length === $eligible.length) {
                $selectAll.prop({ checked: true, indeterminate: false });
            } else {
                $selectAll.prop({ checked: false, indeterminate: true });
            }

            var totalBytes = 0;
            $checked.each(function () {
                totalBytes += parseInt($(this).data('bytes'), 10) || 0;
            });

            var count = $checked.length;
            $bulkBar.find('.sba-subsizes-selected-summary').text(
                count + ' ' + sba__('selected', 'selected') + (totalBytes > 0 ? ' (' + formatBytes(totalBytes) + ')' : '')
            );
            $bulkBar.find('.sba-subsizes-selected-count').text(count);
            $bulkBar.find('.sba-btn-delete-subsizes-bulk').prop('disabled', count === 0);

            if (count > 0) {
                $bulkBar.css('display', 'flex');
            } else {
                $bulkBar.hide();
            }
        }

        function applySubsizesDeletionUI(attId, deletedKeys, data) {
            var updateDatasetItem = function (list) {
                if (!list || !list.length) return;
                var it = list.find(function (m) { return parseInt(m.id, 10) === attId; });
                if (it && it.subsizes) {
                    it.subsizes = it.subsizes.filter(function (s) { return deletedKeys.indexOf(s.size_key) === -1; });
                    it.subsize_count = it.subsizes.length;
                    if (data.new_total_bytes !== undefined) {
                        it.file_size_bytes = data.new_total_bytes;
                        it.file_size_formatted = data.new_total_formatted;
                    }
                }
            };

            updateDatasetItem(allMedia);
            updateDatasetItem(unusedMedia);
            updateDatasetItem(oversizedMedia);
            updateDatasetItem(optimizedMedia);

            if (data.actual_reclaimed_mb !== undefined) {
                $('#sba-stat-media-reclaimed').text(parseFloat(data.actual_reclaimed_mb).toFixed(2) + ' MB');
            }

            var $rowsToRemove = $();
            deletedKeys.forEach(function (k) {
                $rowsToRemove = $rowsToRemove.add('#sba-subsize-item-' + attId + '-' + k);
            });

            $rowsToRemove.css('background', '#fee2e2').fadeOut(300, function () {
                $(this).remove();
            }).promise().done(function () {
                var currentItem = allMedia.find(function (m) { return parseInt(m.id, 10) === attId; });
                var remCount = (currentItem && currentItem.subsizes) ? currentItem.subsizes.length : 0;
                var $parentRow = $('#sba-media-row-' + attId);

                if (remCount > 0) {
                    $parentRow.find('.sba-subsize-toggle-label').text(
                        sba__('View Generated Sizes', 'View Generated Sizes') + ' (' + remCount + ')'
                    );
                    $('#sba-subsizes-row-' + attId).find('.sba-subsizes-panel-title strong').text(
                        sba__('Generated Thumbnail Variations', 'Generated Thumbnail Variations') + ' (' + remCount + ')'
                    );
                    updateSubsizesSelectionUI(attId);
                } else {
                    $parentRow.find('.sba-subsize-wrapper').remove();
                    $('#sba-subsizes-row-' + attId).remove();
                }

                if (data.new_total_formatted) {
                    $parentRow.find('td.sba-col-size > div:first-child').text(data.new_total_formatted);
                }
            });
        }

        // Subsize Panel Select All Checkbox
        $(document).on('change', '.sba-subsizes-select-all', function () {
            var $cb = $(this);
            var attId = parseInt($cb.data('att-id'), 10);
            var isChecked = $cb.is(':checked');
            var $panelRow = $('#sba-subsizes-row-' + attId);

            $panelRow.find('.sba-subsize-item-cb:not(:disabled)').each(function () {
                $(this).prop('checked', isChecked);
                $(this).closest('tr').toggleClass('sba-row-selected', isChecked);
            });
            updateSubsizesSelectionUI(attId);
        });

        // Individual Subsize Checkbox
        $(document).on('change', '.sba-subsize-item-cb', function () {
            var $cb = $(this);
            var attId = parseInt($cb.data('att-id'), 10);
            $cb.closest('tr').toggleClass('sba-row-selected', $cb.is(':checked'));
            updateSubsizesSelectionUI(attId);
        });

        // Clear Subsize Selection
        $(document).on('click', '.sba-subsizes-clear-btn', function (e) {
            e.preventDefault();
            var attId = parseInt($(this).data('att-id'), 10);
            var $panelRow = $('#sba-subsizes-row-' + attId);
            $panelRow.find('.sba-subsize-item-cb:not(:disabled)').prop('checked', false).closest('tr').removeClass('sba-row-selected');
            updateSubsizesSelectionUI(attId);
        });

        // Safe Bulk Thumbnail Sub-size Pruning Action (Per Attachment Panel)
        $(document).on('click', '.sba-btn-delete-subsizes-bulk', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('att-id'), 10);
            var $panelRow = $('#sba-subsizes-row-' + attId);
            var $checked = $panelRow.find('.sba-subsize-item-cb:checked:not(:disabled)');

            if (!$checked.length) {
                return;
            }

            var sizeKeys = [];
            $checked.each(function () {
                var sk = $(this).data('size-key');
                if (sk) {
                    sizeKeys.push(String(sk));
                }
            });

            if (!sizeKeys.length) {
                return;
            }

            var confirmTmpl = sba__('Are you sure you want to permanently delete the %d selected thumbnail variation(s)? Physical files will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).', 'Are you sure you want to permanently delete the %d selected thumbnail variation(s)? Physical files will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).');
            if (!confirm(confirmTmpl.replace('%d', sizeKeys.length))) {
                return;
            }

            var defaultBtnHtml = $btn.html();
            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Deleting...', 'Deleting...'));
            $panelRow.find('.sba-btn-delete-subsize').prop('disabled', true);

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_bulk_thumbnail_subsizes',
                _ajax_nonce: sbaNonce,
                attachment_id: attId,
                size_keys: sizeKeys
            }, function (response) {
                $panelRow.find('.sba-btn-delete-subsize').prop('disabled', false);
                $btn.removeClass('is-loading').html(defaultBtnHtml);

                if (response.success && response.data) {
                    var deletedKeys = Array.isArray(response.data.deleted_keys) ? response.data.deleted_keys : [];
                    applySubsizesDeletionUI(attId, deletedKeys, response.data);
                    showNotice(response.data.message || sba__('Thumbnail variant deleted successfully.', 'Thumbnail variant deleted successfully.'), false);
                } else {
                    $btn.prop('disabled', false);
                    showNotice(response.data || sba__('Failed to delete selected thumbnail variations.', 'Failed to delete selected thumbnail variations.'), true);
                }
            }).fail(function () {
                $panelRow.find('.sba-btn-delete-subsize').prop('disabled', false);
                $btn.prop('disabled', false).removeClass('is-loading').html(defaultBtnHtml);
                showNotice(sba__('Server error while deleting selected thumbnail variations.', 'Server error while deleting selected thumbnail variations.'), true);
            });
        });

        // Safe Single Thumbnail Sub-size Pruning Action
        $(document).on('click', '.sba-btn-delete-subsize', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('att-id'), 10);
            var sizeKey = $btn.data('size-key');

            if (!confirm(sba__('Are you sure you want to permanently delete this thumbnail variation? The physical file will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).', 'Are you sure you want to permanently delete this thumbnail variation? The physical file will be removed from disk and metadata will be updated. (Note: thumbnails can be regenerated from the original file if needed later).'))) {
                return;
            }

            $btn.prop('disabled', true).addClass('is-loading').html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Deleting...', 'Deleting...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_delete_single_thumbnail_subsize',
                _ajax_nonce: sbaNonce,
                attachment_id: attId,
                size_key: sizeKey
            }, function (response) {
                if (response.success && response.data) {
                    applySubsizesDeletionUI(attId, [sizeKey], response.data);
                    showNotice(response.data.message || sba__('Thumbnail variant deleted successfully.', 'Thumbnail variant deleted successfully.'), false);
                } else {
                    $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__('Delete Size', 'Delete Size'));
                    showNotice(response.data || sba__('Failed to delete thumbnail variant.', 'Failed to delete thumbnail variant.'), true);
                }
            }).fail(function () {
                $btn.prop('disabled', false).removeClass('is-loading').html('<span class="dashicons dashicons-trash"></span> ' + sba__('Delete Size', 'Delete Size'));
                showNotice(sba__('Server error while deleting thumbnail variant.', 'Server error while deleting thumbnail variant.'), true);
            });
        });

        // =====================================================================
        // TAB 3: PLUGIN AUDIT LOGIC
        // =====================================================================

        function updatePluginSelectAllState() {
            var $displayed = $('#sba-plugins-body').find('.sba-plugin-item-cb:not(:disabled)');
            if ($displayed.length === 0) {
                $('#sba-plugins-select-all').prop('checked', false).prop('indeterminate', false);
                return;
            }
            var $checked = $displayed.filter(':checked');
            if ($checked.length === $displayed.length) {
                $('#sba-plugins-select-all').prop('checked', true).prop('indeterminate', false);
            } else if ($checked.length > 0) {
                $('#sba-plugins-select-all').prop('checked', false).prop('indeterminate', true);
            } else {
                $('#sba-plugins-select-all').prop('checked', false).prop('indeterminate', false);
            }
        }

        function updatePluginBulkToolbar() {
            var count = selectedPluginFiles.length;
            var $toolbar = $('#sba-plugins-bulk-toolbar');
            if (count === 0) {
                $toolbar.hide();
                return;
            }
            $toolbar.show();
            $('#sba-plugins-selected-count').text(count);
            $('#sba-plugins-selection-label').text(count === 1 ? sba__('plugin selected', 'plugin selected') : sba__('plugins selected', 'plugins selected'));
        }

        function renderPlugins() {
            var searchQuery = $('#sba-plugin-search').val().toLowerCase().trim();
            var $tbody = $('#sba-plugins-body');
            $tbody.empty();

            // Prune selectedPluginFiles: remove any items no longer eligible or installed
            selectedPluginFiles = selectedPluginFiles.filter(function (pf) {
                return allPlugins.some(function (p) {
                    return p.plugin_file === pf && p.is_deletable !== false && !p.is_self;
                });
            });

            var filtered = allPlugins.filter(function (p) {
                // Tab filter: 'all', 'usage_detected', 'active_unused', 'usage_unknown', 'inactive', 'unverified', 'dependencies', 'outdated'
                if (currentPluginFilter === 'usage_detected') {
                    if (p.usage_status !== 'functional_usage' && p.usage_status !== 'operational_activity' && p.usage_status !== 'usage_detected' && p.usage_status !== 'usage_detected_inactive') {
                        return false;
                    }
                } else if (currentPluginFilter === 'active_unused') {
                    var isUnusedActive = p.is_active && (p.usage_status === 'active_no_usage' || p.usage_status === 'no_usage_detected' || p.usage_status === 'configuration_only' || p.usage_status === 'database_footprint_only');
                    if (!isUnusedActive) {
                        return false;
                    }
                } else if (currentPluginFilter === 'usage_unknown') {
                    if (p.usage_status !== 'usage_unknown') {
                        return false;
                    }
                } else if (currentPluginFilter === 'inactive') {
                    if (p.is_active) {
                        return false;
                    }
                } else if (currentPluginFilter === 'unverified') {
                    if (p.is_self || (p.repo_status !== 'unverified' && p.risk_level !== 'High')) {
                        return false;
                    }
                } else if (currentPluginFilter === 'dependencies') {
                    if (!p.dependency_issue && p.risk_level !== 'Inactive Dependency') {
                        return false;
                    }
                } else if (currentPluginFilter === 'outdated') {
                    if (!p.is_outdated && p.classification !== 'outdated') {
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
                    var usageMatch = p.usage_label && p.usage_label.toLowerCase().indexOf(searchQuery) !== -1;
                    if (!nameMatch && !slugMatch && !authorMatch && !fileMatch && !explMatch && !noteMatch && !usageMatch) {
                        return false;
                    }
                }
                return true;
            });

            var totalFiltered = filtered.length;

            if (totalFiltered === 0) {
                $('#sba-plugins-empty-state').show();
                $('#sba-plugin-pagination-wrap').hide();
                updatePluginSelectAllState();
                updatePluginBulkToolbar();
                return;
            }
            $('#sba-plugins-empty-state').hide();

            // Pagination calculation
            var pageSizeNum = (pluginPageSize === 'all') ? totalFiltered : (parseInt(pluginPageSize, 10) || 25);
            var totalPages = (pageSizeNum > 0 && totalFiltered > 0) ? Math.ceil(totalFiltered / pageSizeNum) : 1;

            if (pluginCurrentPage > totalPages) {
                pluginCurrentPage = totalPages;
            }
            if (pluginCurrentPage < 1) {
                pluginCurrentPage = 1;
            }

            var startIndex = (pluginPageSize === 'all') ? 0 : (pluginCurrentPage - 1) * pageSizeNum;
            var endIndex = (pluginPageSize === 'all') ? totalFiltered : Math.min(startIndex + pageSizeNum, totalFiltered);
            var pagedList = filtered.slice(startIndex, endIndex);

            // Update Pagination UI
            $('#sba-plugin-pagination-wrap').show();
            var infoText = sba__('Showing ', 'Showing ') + (startIndex + 1) + ' ' + sba__('to', 'to') + ' ' + endIndex + ' ' + sba__('of', 'of') + ' ' + totalFiltered + ' ' + sba__('plugins', 'plugins');
            $('#sba-plugin-pagination-info').text(infoText);
            $('#sba-plugin-page-prev').prop('disabled', pluginCurrentPage <= 1);
            $('#sba-plugin-page-next').prop('disabled', pluginCurrentPage >= totalPages);

            var $numbersWrap = $('#sba-plugin-page-numbers');
            $numbersWrap.empty();
            if (totalPages > 1 && totalPages <= 8) {
                for (var pg = 1; pg <= totalPages; pg++) {
                    var actClass = (pg === pluginCurrentPage) ? ' active' : '';
                    $numbersWrap.append('<button type="button" class="sba-page-number' + actClass + '" data-page="' + pg + '">' + pg + '</button>');
                }
            } else if (totalPages > 8) {
                $numbersWrap.append('<span style="font-size: 12px; color: #475569; padding: 4px 6px;">' + sba__('Page ', 'Page ') + pluginCurrentPage + ' / ' + totalPages + '</span>');
            }

            $.each(pagedList, function (index, p) {
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

                var isProtected = (p.is_self || p.is_deletable === false);
                var isChecked = !isProtected && (selectedPluginFiles.indexOf(p.plugin_file) !== -1);
                var rowClass = isChecked ? ' class="sba-row-selected"' : '';

                // 0. Checkbox Column
                var cbColHtml = '';
                if (isProtected) {
                    var protReason = p.protect_reason || sba__('Protected plugin (cannot be modified)', 'Protected plugin (cannot be modified)');
                    cbColHtml = '<td class="sba-plugin-cb-col" style="text-align: center;">' +
                        '<input type="checkbox" disabled class="sba-plugin-item-cb" title="' + sbaEscapeHtml(protReason) + '" aria-label="' + sbaEscapeHtml(protReason) + '" />' +
                        '</td>';
                } else {
                    cbColHtml = '<td class="sba-plugin-cb-col" style="text-align: center;">' +
                        '<input type="checkbox" class="sba-plugin-item-cb" data-plugin-file="' + safeFile + '" ' + (isChecked ? 'checked' : '') + ' aria-label="' + sba__('Select ', 'Select ') + safeName + '" />' +
                        '</td>';
                }

                // 1. Plugin & Author / Path Column
                var authorHtml = '';
                if (safeAuthor) {
                    if (safeAuthorUri) {
                        authorHtml = '<div class="sba-table-source">' + sba__('By ', 'By ') + '<a href="' + safeAuthorUri + '" target="_blank" rel="noopener noreferrer" class="sba-plugin-link">' + safeAuthor + '</a></div>';
                    } else {
                        authorHtml = '<div class="sba-table-source">' + sba__('By ', 'By ') + safeAuthor + '</div>';
                    }
                }

                var pluginColHtml = '<strong class="sba-plugin-name">' + safeName + '</strong> ' +
                    '<span class="sba-plugin-version">v' + safeVersion + '</span>' +
                    authorHtml +
                    '<div class="sba-table-source" style="font-size: 11px; font-family: monospace; color: #64748b; margin-top: 2px;"><code>' + safeFile + '</code></div>';

                // 2. Status Badge (Active vs Inactive)
                var statusBadge = '';
                if (p.is_active) {
                    statusBadge = '<span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('Active', 'Active') + '</span>';
                } else {
                    statusBadge = '<span class="sba-badge-pill sba-pill-inactive"><span class="dashicons dashicons-dismiss"></span> ' + sba__('Inactive', 'Inactive') + '</span>';
                }

                // 3. Usage Detection & Evidence Column (7 distinct evidence-based statuses)
                var uStatus = p.usage_status || 'usage_unknown';
                var usageBadge = '';
                if (uStatus === 'functional_usage' || uStatus === 'usage_detected') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-functional"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('Functional Usage Detected', 'Functional Usage Detected') + '</span>';
                } else if (uStatus === 'operational_activity') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-operational"><span class="dashicons dashicons-admin-generic"></span> ' + sba__('Operational Activity Detected', 'Operational Activity Detected') + '</span>';
                } else if (uStatus === 'configuration_only') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-config"><span class="dashicons dashicons-admin-settings"></span> ' + sba__('Configuration Only', 'Configuration Only') + '</span>';
                } else if (uStatus === 'database_footprint_only') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-db-footprint"><span class="dashicons dashicons-database"></span> ' + sba__('Database Footprint Only', 'Database Footprint Only') + '</span>';
                } else if (uStatus === 'historical_data_only' || uStatus === 'usage_detected_inactive') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-historical"><span class="dashicons dashicons-backup"></span> ' + sba__('Historical Data Only', 'Historical Data Only') + '</span>';
                } else if (uStatus === 'active_no_usage' || (uStatus === 'no_usage_detected' && p.is_active)) {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-active-unused"><span class="dashicons dashicons-warning"></span> ' + sba__('Active · No Usage Detected', 'Active · No Usage Detected') + '</span>';
                } else if (uStatus === 'usage_unknown') {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-unknown"><span class="dashicons dashicons-editor-help"></span> ' + sba__('Usage Unknown / Incomplete Scan', 'Usage Unknown / Incomplete Scan') + '</span>';
                } else {
                    usageBadge = '<span class="sba-usage-badge sba-usage-badge-inactive"><span class="dashicons dashicons-dismiss"></span> ' + sba__('Inactive · No Usage Detected', 'Inactive · No Usage Detected') + '</span>';
                }

                var confidenceText = p.usage_confidence ? p.usage_confidence.charAt(0).toUpperCase() + p.usage_confidence.slice(1) : 'Medium';
                var confidenceHtml = '<div style="font-size: 10.5px; color: #64748b; margin-top: 2px;">' + sba__('Confidence: ', 'Confidence: ') + '<strong>' + sbaEscapeHtml(confidenceText) + '</strong></div>';

                var archetypeCategory = (p.archetype && p.archetype.category) ? p.archetype.category : '';
                var archetypeHtml = archetypeCategory ? ('<div style="font-size: 11px; color: #475569; margin-top: 2px;"><em>' + sbaEscapeHtml(archetypeCategory) + '</em></div>') : '';

                var firstEvidence = '';
                if (Array.isArray(p.evidence_functional) && p.evidence_functional.length > 0) {
                    firstEvidence = p.evidence_functional[0];
                } else if (Array.isArray(p.evidence_operational) && p.evidence_operational.length > 0) {
                    firstEvidence = p.evidence_operational[0];
                } else if (Array.isArray(p.evidence_db) && p.evidence_db.length > 0) {
                    firstEvidence = p.evidence_db[0];
                } else if (Array.isArray(p.evidence_config) && p.evidence_config.length > 0) {
                    firstEvidence = p.evidence_config[0];
                } else if (Array.isArray(p.evidence_historical) && p.evidence_historical.length > 0) {
                    firstEvidence = p.evidence_historical[0];
                } else if (Array.isArray(p.evidence_found) && p.evidence_found.length > 0) {
                    firstEvidence = p.evidence_found[0];
                } else if (Array.isArray(p.usage_evidence) && p.usage_evidence.length > 0) {
                    firstEvidence = p.usage_evidence[0];
                } else if (p.scan_limitations) {
                    firstEvidence = p.scan_limitations;
                }
                var evidenceBrief = firstEvidence ? ('<div style="font-size: 11px; color: #475569; margin-top: 3px; line-height: 1.35;">' + sbaEscapeHtml(firstEvidence) + '</div>') : '';

                var viewBtnLabel = (uStatus === 'active_no_usage' || uStatus === 'no_usage_detected') ? sba__('View Checks & Limitations', 'View Checks & Limitations') : sba__('View Evidence', 'View Evidence');
                var viewEvidenceBtn = '<button type="button" class="sba-btn-view-evidence sba-plugin-link" data-plugin-file="' + safeFile + '">' +
                    '<span class="dashicons dashicons-visibility" style="font-size: 13px; width: 13px; height: 13px;"></span> ' +
                    viewBtnLabel +
                    '</button>';

                var usageColHtml = '<div style="line-height: 1.4;">' +
                    usageBadge +
                    confidenceHtml +
                    archetypeHtml +
                    evidenceBrief +
                    '<div style="margin-top: 4px;">' + viewEvidenceBtn + '</div>' +
                    '</div>';

                // 4. Repo Verification & Risk Column
                var repoBadge = '';
                if (p.is_self) {
                    repoBadge = '<span class="sba-badge-pill sba-pill-self" title="' + sba__('Site Bloat & Storage Auditor (This Auditor)', 'Site Bloat & Storage Auditor (This Auditor)') + '">' +
                        '<span class="dashicons dashicons-admin-tools"></span> ' + sba__('Local Plugin (This Auditor)', 'Local Plugin (This Auditor)') +
                        '</span>';
                } else if (p.repo_status === 'verified') {
                    repoBadge = '<div style="margin-bottom: 4px;">' +
                        '<a href="' + safeRepoUrl + '" target="_blank" rel="noopener noreferrer" class="sba-badge-pill sba-pill-verified sba-plugin-link" title="' + sba__('View on official WordPress.org Directory', 'View on official WordPress.org Directory') + '">' +
                        '<span class="dashicons dashicons-yes"></span> ' + sba__('WordPress.org Verified', 'WordPress.org Verified') + ' <span class="dashicons dashicons-external" style="font-size: 11px; width: 11px; height: 11px; margin-left: 2px;"></span>' +
                        '</a>' +
                        '</div>';
                    if (safeTestedWp || safeUpdatedDate) {
                        var metaParts = [];
                        if (safeTestedWp) {
                            metaParts.push(sba__('Tested up to WP ', 'Tested up to WP ') + safeTestedWp);
                        }
                        if (safeUpdatedDate) {
                            metaParts.push(safeUpdatedDate);
                        }
                        repoBadge += '<div class="sba-table-source" style="font-size: 11px;">' + metaParts.join(' &bull; ') + '</div>';
                    }
                } else if (p.repo_status === 'theme_core') {
                    var safeThemeLabel = sbaEscapeHtml(p.repo_status_label || sba__('Theme Core Plugin', 'Theme Core Plugin'));
                    repoBadge = '<span class="sba-badge-pill sba-pill-theme-core" title="' + safeExpl + '">' +
                        '<span class="dashicons dashicons-admin-appearance"></span> ' + safeThemeLabel +
                        '</span>';
                } else if (p.repo_status === 'theme_bundled') {
                    var safeBundledLabel = sbaEscapeHtml(p.repo_status_label || sba__('Theme Bundled Plugin', 'Theme Bundled Plugin'));
                    repoBadge = '<span class="sba-badge-pill sba-pill-theme-bundled" title="' + safeExpl + '">' +
                        '<span class="dashicons dashicons-admin-appearance"></span> ' + safeBundledLabel +
                        '</span>';
                } else if (p.repo_status === 'api_unavailable') {
                    var safeApiLabel = sbaEscapeHtml(p.repo_status_label || sba__('Verification Unavailable', 'Verification Unavailable'));
                    repoBadge = '<span class="sba-badge-pill sba-pill-warning" title="' + safeExpl + '">' +
                        '<span class="dashicons dashicons-info"></span> ' + safeApiLabel +
                        '</span>';
                } else {
                    repoBadge = '<span class="sba-badge-pill sba-pill-unverified" title="' + sba__('Unverified plugins are not found in the official WordPress.org repository. Inspect them carefully for security, authenticity, and ongoing maintenance.', 'Unverified plugins are not found in the official WordPress.org repository. Inspect them carefully for security, authenticity, and ongoing maintenance.') + '">' +
                        '<span class="dashicons dashicons-warning"></span> ' + sba__('Unverified / Not in Directory', 'Unverified / Not in Directory') +
                        '</span>';
                }

                var riskBadge = '';
                var riskNoteHtml = '';
                if (p.is_self || p.risk_level === 'Local Active' || p.risk_level === 'Local Plugin') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-self"><span class="dashicons dashicons-admin-tools"></span> ' + sba__('Local Active', 'Local Active') + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #1d4ed8; line-height: 1.35; margin-top: 2px;">' + safeExpl + '</div>';
                } else if (p.dependency_issue || p.risk_level === 'Dependency Inactive' || p.risk_level === 'Inactive Dependency') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-dependency"><span class="dashicons dashicons-warning"></span> ' + sba__('Dependency Inactive', 'Dependency Inactive') + '</span>';
                    var depNoteText = p.risk_note ? sbaEscapeHtml(p.risk_note) : safeExpl;
                    riskNoteHtml = '<div style="font-size: 11px; color: #b45309; line-height: 1.35; font-weight: 500; margin-top: 2px;">' + depNoteText + '</div>';
                } else if (p.risk_level === 'High') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-high"><span class="dashicons dashicons-warning"></span> ' + sba__('High Risk', 'High Risk') + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #dc2626; line-height: 1.35; margin-top: 2px;">' + safeExpl + '</div>';
                } else if (p.risk_level === 'Medium') {
                    riskBadge = '<span class="sba-risk-badge sba-risk-medium"><span class="dashicons dashicons-clock"></span> ' + sba__('Medium Risk', 'Medium Risk') + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #b45309; line-height: 1.35; margin-top: 2px;">' + safeExpl + '</div>';
                } else {
                    riskBadge = '<span class="sba-risk-badge sba-risk-low"><span class="dashicons dashicons-shield"></span> ' + sba__('Low Risk', 'Low Risk') + '</span>';
                    riskNoteHtml = '<div style="font-size: 11px; color: #64748b; line-height: 1.35; margin-top: 2px;">' + safeExpl + '</div>';
                }

                var repoColHtml = '<div style="line-height: 1.4;">' +
                    repoBadge +
                    '<div style="margin-top: 4px;">' + riskBadge + '</div>' +
                    riskNoteHtml +
                    '</div>';

                // 5. Disk & Database Linked Footprint Column
                var dbCount = parseInt(p.db_tables_count, 10) || 0;
                var dbSize = sbaEscapeHtml(p.db_tables_size || '0 KB');
                var tableLabel = (dbCount === 1) ? sba__('Table', 'Table') : sba__('Tables', 'Tables');
                var dbFormatted = dbCount + ' ' + tableLabel + ' (' + dbSize + ')';

                var diskHtml = '<div style="line-height: 1.45;">' +
                    '<div style="font-size: 13px; font-weight: 700; color: #0f172a;">Disk: ' + sizeText + '</div>' +
                    '<div style="font-size: 12px; font-weight: 600; color: #475569; margin-top: 3px;">DB: ' + dbFormatted + '</div>' +
                    '</div>';

                // 6. Action Column
                var manageBtnHtml = '<a href="' + adminPluginsUrl + '" class="button button-small sba-btn-manage-plugin sba-plugin-link" target="_blank" title="' + sba__('Manage this plugin in WordPress Plugins screen', 'Manage this plugin in WordPress Plugins screen') + '">' +
                    '<span class="dashicons dashicons-admin-plugins"></span> <span>' + sba__('Manage in Plugins', 'Manage in Plugins') + '</span>' +
                    '</a>';

                var deactivateBtnHtml = '';
                if (p.is_active && !p.is_self && p.is_deletable !== false) {
                    deactivateBtnHtml = '<button type="button" class="button button-small sba-btn-single-deactivate-plugin" data-plugin-file="' + safeFile + '" title="' + sba__('Safely deactivate this plugin', 'Safely deactivate this plugin') + '">' +
                        '<span class="dashicons dashicons-dismiss"></span> ' + sba__('Deactivate', 'Deactivate') +
                        '</button>';
                }

                var deleteBtnHtml = '';
                if (!isProtected) {
                    deleteBtnHtml = '<button type="button" class="button button-small sba-btn-single-delete-plugin sba-btn-danger" data-plugin-file="' + safeFile + '" title="' + sba__('Safely delete this plugin', 'Safely delete this plugin') + '">' +
                        '<span class="dashicons dashicons-trash"></span> ' + sba__('Delete', 'Delete') +
                        '</button>';
                }

                var protectedBadgeHtml = '';
                if (isProtected && !p.is_self) {
                    var protBadgeLabel = p.is_theme_required ? sba__('Required by Theme', 'Required by Theme') : sba__('Protected', 'Protected');
                    protectedBadgeHtml = '<span class="sba-badge-pill sba-pill-core" style="font-size: 11px;" title="' + sbaEscapeHtml(p.protect_reason || protBadgeLabel) + '">' +
                        '<span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px;"></span> ' + protBadgeLabel +
                        '</span>';
                }

                var actionColHtml = '<div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">' +
                    manageBtnHtml +
                    deactivateBtnHtml +
                    deleteBtnHtml +
                    protectedBadgeHtml +
                    '</div>';

                var rowHtml = '<tr' + rowClass + '>' +
                    cbColHtml +
                    '<td>' + pluginColHtml + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + usageColHtml + '</td>' +
                    '<td>' + repoColHtml + '</td>' +
                    '<td>' + diskHtml + '</td>' +
                    '<td style="text-align: right;">' + actionColHtml + '</td>' +
                    '</tr>';

                $tbody.append(rowHtml);
            });

            updatePluginSelectAllState();
            updatePluginBulkToolbar();
        }

        // Toggle Expandable Usage Evidence (if any)
        $(document).on('click', '.sba-usage-evidence-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var idx = $(this).data('idx');
            var $list = $('#sba-evidence-list-' + idx);
            var $icon = $(this).find('.dashicons');
            if ($list.is(':visible')) {
                $list.slideUp(150);
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            } else {
                $list.slideDown(150);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        });

        // Open Evidence Panel Modal
        $(document).on('click', '.sba-btn-view-evidence', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var pluginFile = $(this).data('plugin-file');
            var p = allPlugins.find(function (item) { return item.plugin_file === pluginFile; });
            if (!p) return;

            $('#sba-evidence-modal-plugin-name').text(p.name + ' v' + (p.version || '1.0.0'));
            $('#sba-evidence-modal-plugin-file').text(p.plugin_file);

            var activeBadgeHtml = p.is_active ?
                '<span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('Active', 'Active') + '</span>' :
                '<span class="sba-badge-pill sba-pill-inactive"><span class="dashicons dashicons-dismiss"></span> ' + sba__('Inactive', 'Inactive') + '</span>';
            $('#sba-evidence-modal-active-badge').html(activeBadgeHtml);

            var uStatus = p.usage_status || 'usage_unknown';
            var uBadgeHtml = '';
            if (uStatus === 'functional_usage' || uStatus === 'usage_detected') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-functional"><span class="dashicons dashicons-yes-alt"></span> ' + sbaEscapeHtml(p.usage_label || 'Functional Usage Detected') + '</span>';
            } else if (uStatus === 'operational_activity') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-operational"><span class="dashicons dashicons-admin-generic"></span> ' + sbaEscapeHtml(p.usage_label || 'Operational Activity Detected') + '</span>';
            } else if (uStatus === 'configuration_only') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-config"><span class="dashicons dashicons-admin-settings"></span> ' + sbaEscapeHtml(p.usage_label || 'Configuration Only') + '</span>';
            } else if (uStatus === 'database_footprint_only') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-db-footprint"><span class="dashicons dashicons-database"></span> ' + sbaEscapeHtml(p.usage_label || 'Database Footprint Only') + '</span>';
            } else if (uStatus === 'historical_data_only' || uStatus === 'usage_detected_inactive') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-historical"><span class="dashicons dashicons-backup"></span> ' + sbaEscapeHtml(p.usage_label || 'Historical Data Only') + '</span>';
            } else if (uStatus === 'active_no_usage' || (uStatus === 'no_usage_detected' && p.is_active)) {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-active-unused"><span class="dashicons dashicons-warning"></span> ' + sbaEscapeHtml(p.usage_label || 'Active · No Usage Detected') + '</span>';
            } else if (uStatus === 'usage_unknown') {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-unknown"><span class="dashicons dashicons-editor-help"></span> ' + sbaEscapeHtml(p.usage_label || 'Usage Unknown / Incomplete Scan') + '</span>';
            } else {
                uBadgeHtml = '<span class="sba-usage-badge sba-usage-badge-inactive"><span class="dashicons dashicons-dismiss"></span> ' + sbaEscapeHtml(p.usage_label || 'Inactive · No Usage Detected') + '</span>';
            }
            $('#sba-evidence-modal-usage-badge').html(uBadgeHtml);

            // 1. Checks Performed
            var $checksList = $('#sba-evidence-checks-list');
            $checksList.empty();
            var checks = (Array.isArray(p.checks_performed) && p.checks_performed.length > 0) ? p.checks_performed : [
                sba__('Searched published posts for shortcode tags and references', 'Searched published posts for shortcode tags and references'),
                sba__('Searched post content for Gutenberg block comments', 'Searched post content for Gutenberg block comments'),
                sba__('Inspected active widgets registered in site sidebars', 'Inspected active widgets registered in site sidebars'),
                sba__('Checked for custom post types with published content', 'Checked for custom post types with published content'),
                sba__('Checked database for dedicated plugin tables', 'Checked database for dedicated plugin tables'),
                sba__('Checked post metadata for plugin-specific configurations', 'Checked post metadata for plugin-specific configurations'),
                sba__('Evaluated plugin category against background and infrastructure archetypes', 'Evaluated plugin category against background and infrastructure archetypes')
            ];
            checks.forEach(function (chk) {
                $checksList.append('<li>' + sbaEscapeHtml(chk) + '</li>');
            });

            // 2. Evidence Found (Grouped by Evidence Category)
            var $foundList = $('#sba-evidence-found-list');
            $foundList.empty();
            var hasStructuredEvidence = false;

            if (Array.isArray(p.evidence_functional) && p.evidence_functional.length > 0) {
                hasStructuredEvidence = true;
                $foundList.append('<li style="font-weight: 700; color: #15803d; list-style: none; margin-left: -18px; margin-top: 4px; margin-bottom: 2px;">' + sba__('Functional Usage Detected:', 'Functional Usage Detected:') + '</li>');
                p.evidence_functional.forEach(function (ev) {
                    $foundList.append('<li style="color: #166534; margin-bottom: 3px;">' + sbaEscapeHtml(ev) + '</li>');
                });
            }

            if (Array.isArray(p.evidence_operational) && p.evidence_operational.length > 0) {
                hasStructuredEvidence = true;
                $foundList.append('<li style="font-weight: 700; color: #0369a1; list-style: none; margin-left: -18px; margin-top: 6px; margin-bottom: 2px;">' + sba__('Operational Activity Detected:', 'Operational Activity Detected:') + '</li>');
                p.evidence_operational.forEach(function (ev) {
                    $foundList.append('<li style="color: #0c4a6e; margin-bottom: 3px;">' + sbaEscapeHtml(ev) + '</li>');
                });
            }

            if (Array.isArray(p.evidence_config) && p.evidence_config.length > 0) {
                hasStructuredEvidence = true;
                $foundList.append('<li style="font-weight: 700; color: #b45309; list-style: none; margin-left: -18px; margin-top: 6px; margin-bottom: 2px;">' + sba__('Configuration Detected:', 'Configuration Detected:') + '</li>');
                p.evidence_config.forEach(function (ev) {
                    $foundList.append('<li style="color: #78350f; margin-bottom: 3px;">' + sbaEscapeHtml(ev) + '</li>');
                });
            }

            if (Array.isArray(p.evidence_db) && p.evidence_db.length > 0) {
                hasStructuredEvidence = true;
                $foundList.append('<li style="font-weight: 700; color: #c2410c; list-style: none; margin-left: -18px; margin-top: 6px; margin-bottom: 2px;">' + sba__('Database Footprint (Storage Only):', 'Database Footprint (Storage Only):') + '</li>');
                p.evidence_db.forEach(function (ev) {
                    $foundList.append('<li style="color: #9a3412; margin-bottom: 3px;">' + sbaEscapeHtml(ev) + '</li>');
                });
            }

            if (Array.isArray(p.evidence_historical) && p.evidence_historical.length > 0) {
                hasStructuredEvidence = true;
                $foundList.append('<li style="font-weight: 700; color: #475569; list-style: none; margin-left: -18px; margin-top: 6px; margin-bottom: 2px;">' + sba__('Historical / Residual Data:', 'Historical / Residual Data:') + '</li>');
                p.evidence_historical.forEach(function (ev) {
                    $foundList.append('<li style="color: #334155; margin-bottom: 3px;">' + sbaEscapeHtml(ev) + '</li>');
                });
            }

            if (!hasStructuredEvidence) {
                var found = Array.isArray(p.evidence_found) ? p.evidence_found : [];
                if (found.length === 0) {
                    $foundList.append('<li style="color: #64748b; font-style: italic;">' + sba__('No positive usage evidence found across configured checks.', 'No positive usage evidence found across configured checks.') + '</li>');
                } else {
                    found.forEach(function (ev) {
                        $foundList.append('<li>' + sbaEscapeHtml(ev) + '</li>');
                    });
                }
            }

            if (p.db_tables_warning) {
                $foundList.append('<li style="list-style: none; margin-left: -18px; margin-top: 8px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 4px; color: #9a3412; font-size: 11px; line-height: 1.35;"><span class="dashicons dashicons-warning" style="font-size: 13px; width: 13px; height: 13px;"></span> ' + sbaEscapeHtml(p.db_tables_warning) + '</li>');
            }

            // 3. Evidence Not Found
            var $notFoundList = $('#sba-evidence-not-found-list');
            $notFoundList.empty();
            var notFound = Array.isArray(p.evidence_not_found) ? p.evidence_not_found : [];
            if (notFound.length === 0) {
                $notFoundList.append('<li style="color: #64748b; font-style: italic;">' + sba__('None recorded.', 'None recorded.') + '</li>');
            } else {
                notFound.forEach(function (nf) {
                    $notFoundList.append('<li>' + sbaEscapeHtml(nf) + '</li>');
                });
            }

            // 4. Scan Limitations
            $('#sba-evidence-limitations-text').text(p.scan_limitations || sba__('Static analysis inspects content, tables, and widgets. Plugins executing hooks or background tasks without content rows cannot be detected.', 'Static analysis inspects content, tables, and widgets. Plugins executing hooks or background tasks without content rows cannot be detected.'));

            // 5. Confidence Level
            var conf = p.usage_confidence ? p.usage_confidence.charAt(0).toUpperCase() + p.usage_confidence.slice(1) : 'Medium';
            $('#sba-evidence-confidence-level').text(conf);

            // 6. Recommended Action
            $('#sba-evidence-recommended-action').text(p.recommended_action || sba__('Review plugin functionality before making changes.', 'Review plugin functionality before making changes.'));

            $('#sba-plugin-evidence-modal').fadeIn(150);
        });

        $(document).on('click', '.sba-plugin-evidence-modal-close', function (e) {
            e.preventDefault();
            $('#sba-plugin-evidence-modal').fadeOut(150);
        });

        // Pagination Controls
        $(document).on('click', '.sba-page-number', function (e) {
            e.preventDefault();
            var pg = parseInt($(this).data('page'), 10);
            if (pg && pg !== pluginCurrentPage) {
                pluginCurrentPage = pg;
                renderPlugins();
            }
        });

        $(document).on('click', '#sba-plugin-page-prev', function (e) {
            e.preventDefault();
            if (pluginCurrentPage > 1) {
                pluginCurrentPage--;
                renderPlugins();
            }
        });

        $(document).on('click', '#sba-plugin-page-next', function (e) {
            e.preventDefault();
            pluginCurrentPage++;
            renderPlugins();
        });

        $(document).on('change', '#sba-plugin-page-size', function () {
            pluginPageSize = $(this).val();
            pluginCurrentPage = 1;
            renderPlugins();
        });

        // Individual Plugin Checkbox Click
        $(document).on('change', '.sba-plugin-item-cb', function (e) {
            var $cb = $(this);
            var pluginFile = $cb.data('plugin-file');
            var $row = $cb.closest('tr');

            if ($cb.is(':checked')) {
                $row.addClass('sba-row-selected');
                if (pluginFile && selectedPluginFiles.indexOf(pluginFile) === -1) {
                    selectedPluginFiles.push(pluginFile);
                }
            } else {
                $row.removeClass('sba-row-selected');
                var idx = selectedPluginFiles.indexOf(pluginFile);
                if (idx !== -1) {
                    selectedPluginFiles.splice(idx, 1);
                }
            }

            updatePluginSelectAllState();
            updatePluginBulkToolbar();
        });

        // Select All Plugins Checkbox (operates only on currently displayed/paged plugins)
        $(document).on('change', '#sba-plugins-select-all', function () {
            var isChecked = $(this).is(':checked');
            var $visibleCbs = $('#sba-plugins-body').find('.sba-plugin-item-cb:not(:disabled)');

            $visibleCbs.each(function () {
                var $cb = $(this);
                var pluginFile = $cb.data('plugin-file');
                var $row = $cb.closest('tr');

                $cb.prop('checked', isChecked);
                if (isChecked) {
                    $row.addClass('sba-row-selected');
                    if (pluginFile && selectedPluginFiles.indexOf(pluginFile) === -1) {
                        selectedPluginFiles.push(pluginFile);
                    }
                } else {
                    $row.removeClass('sba-row-selected');
                    var idx = selectedPluginFiles.indexOf(pluginFile);
                    if (idx !== -1) {
                        selectedPluginFiles.splice(idx, 1);
                    }
                }
            });

            updatePluginSelectAllState();
            updatePluginBulkToolbar();
        });

        // Clear Plugin Selection
        $(document).on('click', '#sba-plugins-btn-clear', function (e) {
            e.preventDefault();
            selectedPluginFiles = [];
            $('.sba-plugin-item-cb').prop('checked', false);
            $('#sba-plugins-select-all').prop('checked', false).prop('indeterminate', false);
            $('#sba-plugins-body tr').removeClass('sba-row-selected');
            updatePluginBulkToolbar();
        });

        // Prevent Row Links / Buttons from Triggering Other Row Events
        $(document).on('click', '.sba-plugin-link', function (e) {
            e.stopPropagation();
        });

        // Helper: Open Plugin Delete Confirmation Modal
        function openPluginDeleteModal(pluginFilesToDelete) {
            if (!pluginFilesToDelete || pluginFilesToDelete.length === 0) {
                return;
            }

            var $list = $('#sba-plugin-delete-list');
            $list.empty();

            var totalEligible = 0;
            var activeCount = 0;
            var inactiveCount = 0;
            var skippedCount = 0;

            pluginFilesToDelete.forEach(function (pf) {
                var pluginObj = allPlugins.find(function (p) { return p.plugin_file === pf; });
                var pName = pluginObj ? pluginObj.name : pf;
                var isActive = pluginObj ? pluginObj.is_active : false;
                var isDeletable = pluginObj ? (pluginObj.is_deletable !== false && !pluginObj.is_self) : true;

                if (!isDeletable) {
                    skippedCount++;
                    return;
                }

                totalEligible++;
                if (isActive) {
                    activeCount++;
                } else {
                    inactiveCount++;
                }

                var statusBadge = isActive ?
                    '<span class="sba-badge-pill sba-pill-active" style="font-size: 11px;"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('Active (will deactivate)', 'Active (will deactivate)') + '</span>' :
                    '<span class="sba-badge-pill sba-pill-inactive" style="font-size: 11px;"><span class="dashicons dashicons-dismiss"></span> ' + sba__('Inactive', 'Inactive') + '</span>';

                var dbWarningHtml = '';
                if (pluginObj && pluginObj.db_tables_warning) {
                    dbWarningHtml = '<div style="font-size: 11px; color: #c2410c; margin-top: 3px;"><span class="dashicons dashicons-database" style="font-size: 13px; width: 13px; height: 13px;"></span> ' + sbaEscapeHtml(pluginObj.db_tables_warning) + '</div>';
                } else if (pluginObj && parseInt(pluginObj.db_tables_count, 10) > 0) {
                    dbWarningHtml = '<div style="font-size: 11px; color: #c2410c; margin-top: 3px;"><span class="dashicons dashicons-database" style="font-size: 13px; width: 13px; height: 13px;"></span> ' +
                        sbaEscapeHtml(pluginObj.db_tables_count + ' dedicated database table(s) will remain in your database after deletion.') + '</div>';
                }

                var itemHtml = '<div class="sba-delete-plugin-item">' +
                    '<div><strong>' + sbaEscapeHtml(pName) + '</strong> <code style="font-size: 11px; color: #64748b; margin-left: 6px;">' + sbaEscapeHtml(pf) + '</code>' + dbWarningHtml + '</div>' +
                    '<div>' + statusBadge + '</div>' +
                    '</div>';
                $list.append(itemHtml);
            });

            if (totalEligible === 0) {
                showNotice(sba__('None of the selected plugins are eligible for deletion.', 'None of the selected plugins are eligible for deletion.'), true, 'plugins');
                return;
            }

            var summaryText = sba__('Total selected: ', 'Total selected: ') + totalEligible +
                ' (' + activeCount + ' ' + sba__('Active', 'Active') + ', ' + inactiveCount + ' ' + sba__('Inactive', 'Inactive') + ')';
            if (skippedCount > 0) {
                summaryText += ' • ' + skippedCount + ' ' + sba__('Protected / Skipped', 'Protected / Skipped');
            }
            $('#sba-plugin-delete-summary').text(summaryText);

            $('#sba-plugin-delete-confirm-cb').prop('checked', false);
            $('#sba-plugin-btn-confirm-delete').prop('disabled', true);
            $('#sba-plugin-delete-modal').fadeIn(150);
        }

        // Helper: Open Plugin Deactivation Confirmation Modal
        function openPluginDeactivateModal(pluginFilesToDeactivate) {
            if (!pluginFilesToDeactivate || pluginFilesToDeactivate.length === 0) return;

            var $list = $('#sba-plugin-deactivate-list');
            $list.empty();

            var eligibleFiles = [];
            var skippedCount = 0;

            pluginFilesToDeactivate.forEach(function (pf) {
                var p = allPlugins.find(function (item) { return item.plugin_file === pf; });
                var pName = p ? p.name : pf;
                var isActive = p ? p.is_active : false;
                var isSelf = p ? p.is_self : false;
                var isProtected = p ? (p.is_deletable === false) : false;

                if (!isActive || isSelf || isProtected) {
                    skippedCount++;
                    return;
                }

                eligibleFiles.push(pf);
                var itemHtml = '<div class="sba-delete-plugin-item">' +
                    '<div><strong>' + sbaEscapeHtml(pName) + '</strong> <code style="font-size: 11px; color: #64748b; margin-left: 6px;">' + sbaEscapeHtml(pf) + '</code></div>' +
                    '<span class="sba-badge-pill sba-pill-active" style="font-size: 11px;"><span class="dashicons dashicons-yes-alt"></span> ' + sba__('Active', 'Active') + '</span>' +
                    '</div>';
                $list.append(itemHtml);
            });

            if (eligibleFiles.length === 0) {
                showNotice(sba__('None of the selected plugins can be deactivated (they may already be inactive or protected).', 'None of the selected plugins can be deactivated (they may already be inactive or protected).'), true, 'plugins');
                return;
            }

            var summaryText = sba__('Total to deactivate: ', 'Total to deactivate: ') + eligibleFiles.length;
            if (skippedCount > 0) {
                summaryText += ' • ' + skippedCount + ' ' + sba__('Skipped (already inactive or protected)', 'Skipped (already inactive or protected)');
            }
            $('#sba-plugin-deactivate-summary').text(summaryText);
            $('#sba-plugin-deactivate-modal').fadeIn(150);
        }

        // Apply Bulk Actions Button Click
        $(document).on('click', '#sba-plugin-btn-bulk-apply', function (e) {
            e.preventDefault();
            var action = $('#sba-plugin-bulk-action-select').val();
            if (!action) {
                showNotice(sba__('Please select an action from the Bulk Actions menu.', 'Please select an action from the Bulk Actions menu.'), true, 'plugins');
                return;
            }
            if (selectedPluginFiles.length === 0) {
                showNotice(sba__('No plugins are currently selected.', 'No plugins are currently selected.'), true, 'plugins');
                return;
            }

            if (action === 'delete') {
                openPluginDeleteModal(selectedPluginFiles);
            } else if (action === 'deactivate') {
                openPluginDeactivateModal(selectedPluginFiles);
            }
        });

        // Single Plugin Deactivate Button Click
        $(document).on('click', '.sba-btn-single-deactivate-plugin', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var pluginFile = $(this).data('plugin-file');
            if (!pluginFile) return;
            openPluginDeactivateModal([pluginFile]);
        });

        // Single Plugin Delete Button Click
        $(document).on('click', '.sba-btn-single-delete-plugin', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var pluginFile = $(this).data('plugin-file');
            if (!pluginFile) return;
            openPluginDeleteModal([pluginFile]);
        });

        // Confirmation Checkbox Toggle for Delete
        $(document).on('change', '#sba-plugin-delete-confirm-cb', function () {
            $('#sba-plugin-btn-confirm-delete').prop('disabled', !this.checked);
        });

        // Close Plugin Modals
        $(document).on('click', '.sba-plugin-modal-close', function (e) {
            e.preventDefault();
            $('#sba-plugin-delete-modal').fadeOut(150);
        });

        $(document).on('click', '.sba-plugin-deactivate-modal-close', function (e) {
            e.preventDefault();
            $('#sba-plugin-deactivate-modal').fadeOut(150);
        });

        // Execute Plugin Deactivation
        $(document).on('click', '#sba-plugin-btn-confirm-deactivate', function (e) {
            e.preventDefault();
            var targetFiles = [];
            $('#sba-plugin-deactivate-list .sba-delete-plugin-item code').each(function () {
                var pf = $(this).text().trim();
                if (pf && targetFiles.indexOf(pf) === -1) {
                    targetFiles.push(pf);
                }
            });

            if (targetFiles.length === 0) {
                $('#sba-plugin-deactivate-modal').hide();
                return;
            }

            $('#sba-plugin-deactivate-modal').hide();
            $('#sba-plugin-progress-log').empty();
            $('#sba-plugin-progress-bar').css('width', '0%');
            $('#sba-plugin-progress-title-text').text(sba__('Deactivating Plugins...', 'Deactivating Plugins...'));
            $('#sba-plugin-progress-modal').fadeIn(150);

            var queue = targetFiles.slice();
            var totalCount = queue.length;
            var processed = 0;
            var deactivatedFiles = [];
            var failedFiles = [];

            function processNextDeactivate() {
                if (queue.length === 0) {
                    $('#sba-plugin-progress-bar').css('width', '100%');
                    $('#sba-plugin-progress-status').text(sba__('Deactivation finished!', 'Deactivation finished!'));

                    setTimeout(function () {
                        $('#sba-plugin-progress-modal').fadeOut(200);

                        // Update is_active in allPlugins
                        allPlugins.forEach(function (p) {
                            if (deactivatedFiles.indexOf(p.plugin_file) !== -1) {
                                p.is_active = false;
                                if (p.usage_status === 'active_no_usage') {
                                    p.usage_status = 'inactive';
                                    p.usage_label = sba__('Inactive · No Usage Detected', 'Inactive · No Usage Detected');
                                } else if (p.usage_status === 'usage_detected') {
                                    p.usage_status = 'usage_detected_inactive';
                                    p.usage_label = sba__('Usage Detected (Historical content)', 'Usage Detected (Historical content)');
                                }
                            }
                        });

                        selectedPluginFiles = [];
                        updatePluginCounts();
                        renderPlugins();

                        var msg = sba__('Plugin Deactivation Complete: ', 'Plugin Deactivation Complete: ') +
                            deactivatedFiles.length + ' ' + sba__('deactivated', 'deactivated');
                        if (failedFiles.length > 0) {
                            msg += ', ' + failedFiles.length + ' ' + sba__('failed', 'failed');
                        }
                        showNotice(msg, failedFiles.length > 0 && deactivatedFiles.length === 0, 'plugins');
                    }, 600);
                    return;
                }

                var currentPf = queue.shift();
                processed++;
                var pct = Math.round((processed / totalCount) * 100);
                $('#sba-plugin-progress-bar').css('width', pct + '%');

                var pluginObj = allPlugins.find(function (p) { return p.plugin_file === currentPf; });
                var pName = pluginObj ? pluginObj.name : currentPf;

                $('#sba-plugin-progress-status').text(
                    sba__('Deactivating ', 'Deactivating ') + processed + ' / ' + totalCount + ': ' + pName + '...'
                );

                $.post(sbaAjaxUrl, {
                    action: 'sba_deactivate_plugin',
                    plugin_file: currentPf,
                    confirmed: 'yes',
                    _ajax_nonce: sbaNonce
                }, function (response) {
                    if (response.success && response.data && response.data.deactivated_count > 0) {
                        deactivatedFiles.push(currentPf);
                        $('#sba-plugin-progress-log').append(
                            '<div style="color: #16a34a; margin-bottom: 3px;">✔ ' + sbaEscapeHtml(pName) + ': ' + sba__('Successfully deactivated.', 'Successfully deactivated.') + '</div>'
                        );
                    } else {
                        failedFiles.push(currentPf);
                        var errMsg = (response.data && response.data.errors && response.data.errors.length) ? response.data.errors[0] : (response.data || sba__('Deactivation failed.', 'Deactivation failed.'));
                        $('#sba-plugin-progress-log').append(
                            '<div style="color: #dc2626; margin-bottom: 3px;">✖ ' + sbaEscapeHtml(pName) + ': ' + sbaEscapeHtml(errMsg) + '</div>'
                        );
                    }
                    processNextDeactivate();
                }).fail(function () {
                    failedFiles.push(currentPf);
                    $('#sba-plugin-progress-log').append(
                        '<div style="color: #dc2626; margin-bottom: 3px;">✖ ' + sbaEscapeHtml(pName) + ': ' + sba__('Server communication error.', 'Server communication error.') + '</div>'
                    );
                    processNextDeactivate();
                });
            }

            processNextDeactivate();
        });

        // Execute Plugin Deletion via Sequential Queue
        $(document).on('click', '#sba-plugin-btn-confirm-delete', function (e) {
            e.preventDefault();
            if (!$('#sba-plugin-delete-confirm-cb').is(':checked')) {
                return;
            }

            var targetFiles = [];
            $('#sba-plugin-delete-list .sba-delete-plugin-item code').each(function () {
                var pf = $(this).text().trim();
                if (pf && targetFiles.indexOf(pf) === -1) {
                    targetFiles.push(pf);
                }
            });

            if (targetFiles.length === 0) {
                $('#sba-plugin-delete-modal').hide();
                return;
            }

            $('#sba-plugin-delete-modal').hide();
            $('#sba-plugin-progress-log').empty();
            $('#sba-plugin-progress-bar').css('width', '0%');
            $('#sba-plugin-progress-title-text').text(sba__('Deleting Plugins...', 'Deleting Plugins...'));
            $('#sba-plugin-progress-modal').fadeIn(150);

            var queue = targetFiles.slice();
            var totalCount = queue.length;
            var processed = 0;
            var deletedFiles = [];
            var failedFiles = [];
            var hadDatabaseTables = false;

            function processNextPlugin() {
                if (queue.length === 0) {
                    // Queue finished
                    $('#sba-plugin-progress-bar').css('width', '100%');
                    $('#sba-plugin-progress-status').text(sba__('Deletion finished!', 'Deletion finished!'));

                    setTimeout(function () {
                        $('#sba-plugin-progress-modal').fadeOut(200);

                        // Remove deleted files from allPlugins
                        allPlugins = allPlugins.filter(function (p) {
                            return deletedFiles.indexOf(p.plugin_file) === -1;
                        });

                        // Clear selections
                        selectedPluginFiles = [];

                        updatePluginCounts();
                        renderPlugins();

                        // Build summary notice
                        var msg = sba__('Plugin Deletion Complete: ', 'Plugin Deletion Complete: ') +
                            deletedFiles.length + ' ' + sba__('deleted', 'deleted');
                        if (failedFiles.length > 0) {
                            msg += ', ' + failedFiles.length + ' ' + sba__('failed', 'failed');
                        }

                        if (hadDatabaseTables) {
                            msg += '. ' + sba__('Notice: One or more deleted plugins left behind database tables. You can review and clean them in the Database Audit tab.', 'Notice: One or more deleted plugins left behind database tables. You can review and clean them in the Database Audit tab.');
                        }

                        showNotice(msg, failedFiles.length > 0 && deletedFiles.length === 0, 'plugins');
                    }, 600);
                    return;
                }

                var currentPf = queue.shift();
                processed++;
                var pct = Math.round((processed / totalCount) * 100);
                $('#sba-plugin-progress-bar').css('width', pct + '%');

                var pluginObj = allPlugins.find(function (p) { return p.plugin_file === currentPf; });
                var pName = pluginObj ? pluginObj.name : currentPf;
                if (pluginObj && pluginObj.db_tables_count && parseInt(pluginObj.db_tables_count, 10) > 0) {
                    hadDatabaseTables = true;
                }

                $('#sba-plugin-progress-status').text(
                    sba__('Deleting ', 'Deleting ') + processed + ' / ' + totalCount + ': ' + pName + '...'
                );

                $.post(sbaAjaxUrl, {
                    action: 'sba_delete_plugin',
                    plugin_file: currentPf,
                    confirmed: 'yes',
                    _ajax_nonce: sbaNonce
                }, function (response) {
                    if (response.success && response.data && response.data.deleted_count > 0) {
                        deletedFiles.push(currentPf);
                        $('#sba-plugin-progress-log').append(
                            '<div style="color: #16a34a; margin-bottom: 3px;">✔ ' + sbaEscapeHtml(pName) + ': ' + sba__('Successfully deleted.', 'Successfully deleted.') + '</div>'
                        );
                    } else {
                        failedFiles.push(currentPf);
                        var errMsg = (response.data && response.data.errors && response.data.errors.length) ? response.data.errors[0] : (response.data || sba__('Deletion failed.', 'Deletion failed.'));
                        $('#sba-plugin-progress-log').append(
                            '<div style="color: #dc2626; margin-bottom: 3px;">✖ ' + sbaEscapeHtml(pName) + ': ' + sbaEscapeHtml(errMsg) + '</div>'
                        );
                    }
                    processNextPlugin();
                }).fail(function () {
                    failedFiles.push(currentPf);
                    $('#sba-plugin-progress-log').append(
                        '<div style="color: #dc2626; margin-bottom: 3px;">✖ ' + sbaEscapeHtml(pName) + ': ' + sba__('Server communication error.', 'Server communication error.') + '</div>'
                    );
                    processNextPlugin();
                });
            }

            processNextPlugin();
        });

        function updatePluginCounts(data) {
            var total = (data && data.total_count !== undefined) ? data.total_count : allPlugins.length;
            var active = (data && data.active_count !== undefined) ? data.active_count : allPlugins.filter(function (p) { return p.is_active; }).length;
            var inactive = (data && data.inactive_count !== undefined) ? data.inactive_count : (total - active);

            var detected = (data && data.usage_detected_count !== undefined) ? data.usage_detected_count : allPlugins.filter(function (p) {
                return p.usage_status === 'functional_usage' || p.usage_status === 'operational_activity' || p.usage_status === 'usage_detected' || p.usage_status === 'usage_detected_inactive';
            }).length;
            var activeUnused = (data && data.active_no_usage_count !== undefined) ? data.active_no_usage_count : allPlugins.filter(function (p) {
                return p.is_active && (p.usage_status === 'active_no_usage' || p.usage_status === 'no_usage_detected' || p.usage_status === 'configuration_only' || p.usage_status === 'database_footprint_only');
            }).length;
            var usageUnknown = (data && data.usage_unknown_count !== undefined) ? data.usage_unknown_count : allPlugins.filter(function (p) {
                return p.usage_status === 'usage_unknown';
            }).length;

            var unverified = (data && data.unverified_count !== undefined) ? data.unverified_count : allPlugins.filter(function (p) { return p.repo_status === 'unverified' && !p.is_self; }).length;
            var outdated = (data && data.outdated_count !== undefined) ? data.outdated_count : allPlugins.filter(function (p) { return p.is_outdated; }).length;
            var dependencies = (data && data.dependencies_count !== undefined) ? data.dependencies_count : allPlugins.filter(function (p) { return p.dependency_issue || p.risk_level === 'Dependency Inactive' || p.risk_level === 'Inactive Dependency'; }).length;

            var totalSize = (data && data.total_size_mb !== undefined) ? data.total_size_mb : 0;

            // KPI Metric Cards
            $('#sba-stat-total-plugins').text(total);
            $('#sba-stat-active-inactive-plugins').text(active + ' ' + sba__('Active', 'Active') + ' • ' + inactive + ' ' + sba__('Inactive', 'Inactive'));
            $('#sba-stat-usage-detected').text(detected);
            $('#sba-stat-active-unused').text(activeUnused);
            $('#sba-stat-usage-unknown').text(usageUnknown);
            $('#sba-stat-inactive-plugins').text(inactive);
            $('#sba-stat-plugins-total-size').text(totalSize.toFixed(2) + ' MB');

            // Filter Tabs Counts
            $('#sba-plugin-count-all').text(total);
            $('#sba-plugin-count-detected').text(detected);
            $('#sba-plugin-count-active-unused').text(activeUnused);
            $('#sba-plugin-count-unknown').text(usageUnknown);
            $('#sba-plugin-count-inactive').text(inactive);
            $('#sba-plugin-count-unverified').text(unverified);
            $('#sba-plugin-count-dependencies').text(dependencies);

            // Security Risk Warning Banner
            if (unverified > 0) {
                var securityMsg = unverified + ' ' + sba__('plugin(s) were not found in the official WordPress.org repository. These represent potential security or maintenance risks if not from a verified trusted vendor.', 'plugin(s) were not found in the official WordPress.org repository. These represent potential security or maintenance risks if not from a verified trusted vendor.');
                $('#sba-plugin-security-text').text(securityMsg);
                $('#sba-plugin-security-banner').show();
            } else {
                $('#sba-plugin-security-banner').hide();
            }
        }

        // Run Plugin Audit
        $('#sba-run-plugin-scan').on('click', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $icon = $btn.find('.dashicons');
            var $label = $btn.find('.sba-btn-label');
            var originalText = $label.text();

            $btn.addClass('is-loading is-scanning').prop('disabled', true);
            $icon.addClass('sba-rotating');
            $label.text(sba__('Verifying with WordPress.org...', 'Verifying with WordPress.org...'));

            $.post(sbaAjaxUrl, {
                action: 'sba_scan_plugins_audit',
                _ajax_nonce: sbaNonce
            }, function (response) {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(sba__('Re-run Plugin Audit', 'Re-run Plugin Audit'));

                if (response.success && response.data) {
                    var d = response.data;
                    allPlugins = d.plugins || [];

                    updatePluginCounts(d);
                    pluginCurrentPage = 1;
                    renderPlugins();

                    showNotice(sba__('Plugin audit complete. Results verified with WordPress.org API.', 'Plugin audit complete. Results verified with WordPress.org API.'), false);
                } else {
                    showNotice(response.data || sba__('Failed to scan plugins.', 'Failed to scan plugins.'), true);
                }
            }).fail(function () {
                $btn.removeClass('is-loading is-scanning').prop('disabled', false);
                $icon.removeClass('sba-rotating');
                $label.text(originalText);
                showNotice(sba__('Server communication error while scanning plugins.', 'Server communication error while scanning plugins.'), true);
            });
        });

        // Plugin Tabs Filter
        $('.sba-plugin-tab-btn').on('click', function (e) {
            e.preventDefault();
            $('.sba-plugin-tab-btn').removeClass('active');
            $(this).addClass('active');
            currentPluginFilter = $(this).data('tab');
            pluginCurrentPage = 1;
            renderPlugins();
        });

        // Plugin Search
        $('#sba-plugin-search').on('input', function () {
            pluginCurrentPage = 1;
            renderPlugins();
        });

        // =====================================================================
        // PHASE 1: DUPLICATES, ACCESSIBILITY, AND DISCREPANCY VIEWS
        // =====================================================================

        function renderDuplicatesView($tbody, searchQuery) {
            var renderedCount = 0;
            $.each(duplicatesData, function (gIdx, group) {
                var groupHashRaw = group.hash || '';
                var matchingItems = (group.items || []).filter(function (item) {
                    if (!searchQuery) {
                        return true;
                    }
                    var hay = [item.name || '', item.file_path || '', item.relative_path || '', groupHashRaw].join(' ').toLowerCase();
                    return hay.indexOf(searchQuery) !== -1;
                });

                if (matchingItems.length === 0) {
                    return;
                }

                var groupHash = sbaEscapeHtml(groupHashRaw.substring(0, 16)) + '...';
                var groupCount = group.count;
                var groupSavings = sbaEscapeHtml(group.potential_savings_formatted);
                var groupRec = sbaEscapeHtml(group.recommendation);

                var headerRowHtml = '<tr class="sba-duplicate-group-header">' +
                    '<td colspan="6">' +
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

                $.each(matchingItems, function (iIdx, item) {
                    var safeId = parseInt(item.id, 10);
                    var safeName = sbaEscapeHtml(item.name);
                    var safePath = sbaEscapeHtml(item.file_path || ('wp-content/uploads/' + (item.relative_path || '')));
                    var safeSize = sbaEscapeHtml(item.file_size_formatted || '0 B');
                    var safePreview = sbaEscapeHtml(item.preview_url || '');
                    var isUsed = item.is_in_use;
                    var editUrl = 'post.php?post=' + safeId + '&action=edit';
                    var rowSelectedClass = (selectedMediaIds.indexOf(safeId) !== -1) ? 'sba-row-selected' : '';
                    var cbHtml = buildMediaRowCheckboxHtml(safeId, safeName, false);

                    var previewHtml = safePreview ? '<img src="' + safePreview + '" class="sba-media-thumb" alt="' + safeName + '" />' : '<div class="sba-media-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>';
                    var pathHtml = '<div class="sba-table-source sba-media-file-path" title="' + sba__('Relative path in uploads directory', 'Relative path in uploads directory') + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>';

                    var usageHtml = isUsed ?
                        '<span class="sba-usage-tag sba-usage-tag-content"><span class="dashicons dashicons-yes"></span> ' + (item.usage_count || 1) + ' Reference(s)</span>' :
                        '<span class="sba-usage-tag sba-usage-tag-unused"><span class="dashicons dashicons-no-alt"></span> 0 References (Review Required)</span>';

                    var actionHtml = '<div style="display: flex; gap: 6px; justify-content: flex-end;">' +
                        (isUsed ? '' : '<button type="button" class="button button-small sba-btn-quarantine-item" data-id="' + safeId + '" data-name="' + safeName + '"><span class="dashicons dashicons-shield"></span> Move to Quarantine</button>') +
                        '<a href="' + editUrl + '" target="_blank" class="button button-small sba-btn-edit-wp"><span class="dashicons dashicons-edit"></span> Edit in WP</a>' +
                        '</div>';

                    var keeperTag = item.is_keeper ?
                        '<span class="sba-badge-sub" style="background: #e0f2fe; color: #0369a1; margin-left: 6px; font-size: 10px; padding: 2px 6px; border-radius: 3px; font-weight: 600;"><span class="dashicons dashicons-star-filled" style="font-size: 11px; width: 11px; height: 11px; line-height: 1; vertical-align: text-top;"></span> Primary Copy</span>' : '';

                    var itemRowHtml = '<tr class="' + rowSelectedClass + '">' +
                        cbHtml +
                        '<td style="text-align: center;">' + previewHtml + '</td>' +
                        '<td><strong class="sba-table-name">' + safeName + '</strong>' + keeperTag + pathHtml + '</td>' +
                        '<td>' + usageHtml + '</td>' +
                        '<td><strong>' + safeSize + '</strong></td>' +
                        '<td style="text-align: right;">' + actionHtml + '</td>' +
                        '</tr>';
                    $tbody.append(itemRowHtml);
                    renderedCount++;
                });
            });

            if (renderedCount === 0 && searchQuery) {
                $tbody.html('<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 30px;">' + sba__('No media items match your search filter.', 'No media items match your search filter.') + '</td></tr>');
            }
        }

        function renderAccessibilityView($tbody, searchQuery) {
            var renderedCount = 0;
            $.each(accessibilityData.items, function (idx, item) {
                var safeId = parseInt(item.id, 10);
                var rawName = item.name || '';
                var rawPath = item.file_path || '';
                var rawAlt = item.alt_text || '';
                if (searchQuery) {
                    var hay = [rawName, rawPath, rawAlt, (item.suggestions || []).join(' ')].join(' ').toLowerCase();
                    if (hay.indexOf(searchQuery) === -1) {
                        return;
                    }
                }

                var safeName = sbaEscapeHtml(rawName);
                var safePath = sbaEscapeHtml(rawPath);
                var safeDim = sbaEscapeHtml(item.dimensions || 'N/A');
                var safeSize = sbaEscapeHtml(item.file_size || '0 B');
                var altText = sbaEscapeHtml(rawAlt);
                var editUrl = sbaEscapeHtml(item.edit_url || ('post.php?post=' + safeId + '&action=edit'));
                var rowSelectedClass = (selectedMediaIds.indexOf(safeId) !== -1) ? 'sba-row-selected' : '';
                var cbHtml = buildMediaRowCheckboxHtml(safeId, safeName, false);

                var issuesBadges = '';
                if (item.issues && item.issues.indexOf('missing_alt_attr') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-red" style="margin-right: 4px;">' + sba__('Missing Alt Attribute', 'Missing Alt Attribute') + '</span>';
                }
                if (item.issues && item.issues.indexOf('empty_alt') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-orange" style="margin-right: 4px;">' + sba__('Empty Alt Text (Decorative?)', 'Empty Alt Text (Decorative?)') + '</span>';
                }
                if (item.issues && item.issues.indexOf('unhelpful_alt') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-orange" style="margin-right: 4px;">' + sba__('Repetitive / Generic Alt', 'Repetitive / Generic Alt') + '</span>';
                }
                if (item.issues && item.issues.indexOf('undescriptive_filename') !== -1) {
                    issuesBadges += '<span class="sba-badge-sub sba-badge-gray" style="margin-right: 4px;">' + sba__('Generic Filename', 'Generic Filename') + '</span>';
                }

                var suggestionsHtml = '';
                if (item.suggestions && item.suggestions.length > 0) {
                    suggestionsHtml = '<div style="font-size: 11px; color: #475569; margin-top: 4px;">' +
                        '<strong>Suggestion:</strong> ' + sbaEscapeHtml(item.suggestions.join(' ')) +
                        '</div>';
                }

                var altDisplay = altText ? '<code>"' + altText + '"</code>' : '<em style="color: #94a3b8;">None</em>';

                var rowHtml = '<tr class="' + rowSelectedClass + '">' +
                    cbHtml +
                    '<td style="text-align: center;"><div class="sba-media-thumb-placeholder"><span class="dashicons dashicons-universal-access"></span></div></td>' +
                    '<td>' +
                    '<strong class="sba-table-name">' + safeName + '</strong>' +
                    (safePath ? '<div class="sba-table-source sba-media-file-path" title="' + sba__('Relative path in uploads directory', 'Relative path in uploads directory') + '"><span class="dashicons dashicons-media-document" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 4px; color: #64748b;"></span><code>' + safePath + '</code></div>' : '') +
                    '<div style="margin-top: 4px;">' + issuesBadges + '</div>' +
                    suggestionsHtml +
                    '</td>' +
                    '<td><div style="font-size: 12px;"><strong>Alt:</strong> ' + altDisplay + '</div></td>' +
                    '<td><strong>' + safeSize + '</strong><div class="sba-table-source">' + safeDim + '</div></td>' +
                    '<td style="text-align: right;">' +
                    '<a href="' + editUrl + '" target="_blank" class="button button-small sba-btn-edit-wp"><span class="dashicons dashicons-edit"></span> ' + sba__('Add Alt in WP', 'Add Alt in WP') + '</a>' +
                    '</td>' +
                    '</tr>';
                $tbody.append(rowHtml);
                renderedCount++;
            });

            if (renderedCount === 0 && searchQuery) {
                $tbody.html('<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 30px;">' + sba__('No media items match your search filter.', 'No media items match your search filter.') + '</td></tr>');
            }
        }

        function renderDiscrepanciesView($tbody, searchQuery) {
            var renderedCount = 0;

            // Render missing originals
            if (discrepanciesData.missing_originals && discrepanciesData.missing_originals.length > 0) {
                $.each(discrepanciesData.missing_originals, function (i, item) {
                    var safeId = parseInt(item.attachment_id, 10);
                    var rawTitle = item.title || ('Attachment #' + safeId);
                    var rawPath = item.expected_path || item.relative_path || '';
                    if (searchQuery) {
                        var hayOrig = [rawTitle, rawPath, 'attachment #' + safeId, 'missing original'].join(' ').toLowerCase();
                        if (hayOrig.indexOf(searchQuery) === -1) {
                            return;
                        }
                    }

                    var safeTitle = sbaEscapeHtml(rawTitle);
                    var safePath = sbaEscapeHtml(rawPath);
                    var rowSelectedClass = (safeId > 0 && selectedMediaIds.indexOf(safeId) !== -1) ? 'sba-row-selected' : '';
                    var cbHtml = buildMediaRowCheckboxHtml(safeId, safeTitle, false);

                    var rowHtml = '<tr class="' + rowSelectedClass + '">' +
                        cbHtml +
                        '<td style="text-align: center;"><div class="sba-media-thumb-placeholder" style="background: #fef2f2; color: #dc2626;"><span class="dashicons dashicons-warning"></span></div></td>' +
                        '<td>' +
                        '<strong class="sba-table-name" style="color: #dc2626;">' + safeTitle + '</strong>' +
                        '<div class="sba-table-source sba-media-file-path">Expected: <code>' + safePath + '</code></div>' +
                        '</td>' +
                        '<td><span class="sba-badge-sub sba-badge-red">' + sba__('Missing Original File', 'Missing Original File') + '</span></td>' +
                        '<td><span style="color: #94a3b8;">0 B (File absent)</span></td>' +
                        '<td style="text-align: right;"><a href="post.php?post=' + safeId + '&action=edit" target="_blank" class="button button-small"><span class="dashicons dashicons-edit"></span> Edit Record</a></td>' +
                        '</tr>';
                    $tbody.append(rowHtml);
                    renderedCount++;
                });
            }

            // Render missing subsizes
            if (discrepanciesData.missing_subsizes && discrepanciesData.missing_subsizes.length > 0) {
                $.each(discrepanciesData.missing_subsizes, function (i, item) {
                    var safeId = parseInt(item.attachment_id, 10);
                    var rawKey = item.size_key || '';
                    var rawFile = item.subfile || '';
                    if (searchQuery) {
                        var haySub = [rawKey, rawFile, 'attachment #' + safeId, 'missing subsize'].join(' ').toLowerCase();
                        if (haySub.indexOf(searchQuery) === -1) {
                            return;
                        }
                    }

                    var safeKey = sbaEscapeHtml(rawKey);
                    var safeFile = sbaEscapeHtml(rawFile);
                    var rowSelectedClass = (safeId > 0 && selectedMediaIds.indexOf(safeId) !== -1) ? 'sba-row-selected' : '';
                    var cbHtml = buildMediaRowCheckboxHtml(safeId, safeFile || ('Attachment #' + safeId), false);

                    var rowHtml = '<tr class="' + rowSelectedClass + '">' +
                        cbHtml +
                        '<td style="text-align: center;"><div class="sba-media-thumb-placeholder" style="background: #fffbeb; color: #d97706;"><span class="dashicons dashicons-warning"></span></div></td>' +
                        '<td>' +
                        '<strong class="sba-table-name">' + safeFile + '</strong>' +
                        '<div class="sba-table-source">Attachment #' + safeId + ' &bull; Subsize key: <code>' + safeKey + '</code></div>' +
                        '</td>' +
                        '<td><span class="sba-badge-sub sba-badge-orange">' + sba__('Missing Thumbnail Sub-size', 'Missing Thumbnail Sub-size') + '</span></td>' +
                        '<td><span style="color: #94a3b8;">Missing</span></td>' +
                        '<td style="text-align: right;"><a href="post.php?post=' + safeId + '&action=edit" target="_blank" class="button button-small"><span class="dashicons dashicons-edit"></span> View</a></td>' +
                        '</tr>';
                    $tbody.append(rowHtml);
                    renderedCount++;
                });
            }

            if (renderedCount === 0 && searchQuery) {
                $tbody.html('<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 30px;">' + sba__('No media items match your search filter.', 'No media items match your search filter.') + '</td></tr>');
            }
        }

        // 1-Click Optimize Image Handler
        $(document).on('click', '.sba-btn-optimize-item', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);
            if (!attId) {
                return;
            }

            // Task 5: Prevent duplicate optimization requests while in progress
            if (activeOptimizingIds[attId]) {
                return;
            }
            activeOptimizingIds[attId] = true;

            var targetW = parseInt($btn.data('w'), 10) || 1920;
            var targetH = parseInt($btn.data('h'), 10) || 1080;

            // Set loading state on button
            $btn.addClass('is-loading').prop('disabled', true).html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Optimizing...', 'Optimizing...'));

            var $row = $('#sba-media-row-' + attId);

            $.ajax({
                url: sbaAjaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 60000,
                data: {
                    action: 'sba_optimize_image',
                    _ajax_nonce: sbaNonce,
                    attachment_id: attId,
                    max_width: targetW,
                    max_height: targetH,
                    quality: 82
                }
            }).done(function (response) {
                if (response && response.success && response.data) {
                    var data = response.data;
                    showNotice(data.message || sba__('Image optimized successfully! Original backup preserved.', 'Image optimized successfully! Original backup preserved.'), false);

                    // Task 6: Update media row in-place using actual returned data without full-page refresh or network rescan
                    var optSizeFormatted = data.optimized_size_fmt || data.file_size_formatted || '0 B';
                    var freedFormatted = data.freed_bytes_fmt || '0 B';
                    var newDimensions = data.new_dimensions || (data.width ? (data.width + ' × ' + data.height + ' px') : 'N/A');
                    var safeEditUrl = '';

                    // 1. Update in-memory data structures
                    var foundItem = null;
                    var isWebPOpt = (data.output_format === 'webp' || data.is_webp);
                    var badgeLabel = isWebPOpt ? sba__('Web Optimized (WebP)', 'Web Optimized (WebP)') : sba__('Web Optimized', 'Web Optimized');

                    if (allMedia && Array.isArray(allMedia)) {
                        for (var i = 0; i < allMedia.length; i++) {
                            if (parseInt(allMedia[i].id, 10) === attId) {
                                allMedia[i].is_oversized = false;
                                allMedia[i].has_backup = true;
                                allMedia[i].has_webp = true;
                                allMedia[i].target_reached = true;
                                allMedia[i].category = 'optimized';
                                allMedia[i].category_label = sba__('Optimized', 'Optimized');
                                allMedia[i].recommendation_badge = badgeLabel;
                                allMedia[i].recommendation_text = sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.');
                                allMedia[i].badge_class = 'sba-pill-active';
                                allMedia[i].file_size_formatted = optSizeFormatted;
                                if (data.optimized_size_bytes) {
                                    allMedia[i].file_size_bytes = data.optimized_size_bytes;
                                }
                                if (data.original_size_bytes) {
                                    allMedia[i].original_size_bytes = data.original_size_bytes;
                                    allMedia[i].original_size_formatted = data.original_size_fmt;
                                }
                                if (data.freed_bytes || data.saved_bytes) {
                                    allMedia[i].saved_bytes = data.freed_bytes || data.saved_bytes;
                                    allMedia[i].saved_bytes_formatted = freedFormatted;
                                    allMedia[i].savings_percent = data.savings_percent;
                                }
                                if (data.original_dimensions && data.original_dimensions !== 'N/A') {
                                    allMedia[i].original_dimensions = data.original_dimensions;
                                }
                                allMedia[i].current_dimensions = newDimensions;
                                allMedia[i].is_resized = (allMedia[i].original_dimensions && allMedia[i].original_dimensions !== newDimensions && allMedia[i].original_dimensions !== 'Original dimensions unavailable');
                                allMedia[i].potential_savings_bytes = 0;
                                allMedia[i].potential_savings_formatted = '0 B';
                                foundItem = allMedia[i];
                                safeEditUrl = (foundItem.edit_url && typeof foundItem.edit_url === 'string') ?
                                    foundItem.edit_url.replace(/&#038;/g, '&').replace(/&amp;/g, '&') : '';
                                break;
                            }
                        }
                    }

                    // Move from oversizedMedia to optimizedMedia
                    if (oversizedMedia && Array.isArray(oversizedMedia)) {
                        var oIdx = oversizedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                        if (oIdx !== -1) {
                            var movedItem = oversizedMedia.splice(oIdx, 1)[0];
                            var freedB = data.freed_bytes || (movedItem.potential_savings_bytes || 0);
                            potentialSavingsMb = Math.max(0, potentialSavingsMb - (freedB / (1024 * 1024)));
                        }
                    }
                    if (optimizedMedia && Array.isArray(optimizedMedia)) {
                        var alreadyOpt = optimizedMedia.some(function (m) { return parseInt(m.id, 10) === attId; });
                        if (!alreadyOpt && foundItem) {
                            optimizedMedia.unshift(foundItem);
                        }
                    }

                    // Update summary stats & filter tab count badges
                    $('#sba-stat-media-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-media-count-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-media-count-optimized').text(formatNumber(optimizedMedia.length));
                    $('#sba-stat-media-savings').text(potentialSavingsMb.toFixed(2) + ' MB');
                    $('#sba-stat-media-savings-meta').text(
                        unusedMedia.length + ' ' + sba__('unused', 'unused') + ' + ' +
                        oversizedMedia.length + ' ' + sba__('resize opps', 'resize opps')
                    );

                    // 2. Update the row directly in the DOM
                    if ($row.length) {
                        // A. Dimensions inside .sba-col-info
                        var $dimContainer = $row.find('.sba-dim-row');
                        var safeOrigDim = sbaEscapeHtml(foundItem.original_dimensions || 'N/A');
                        var safeCurrDim = sbaEscapeHtml(newDimensions);
                        var isResized = !!foundItem.is_resized;
                        var isOrigDimUnavailable = (foundItem.original_dimensions === 'Original dimensions unavailable');

                        var newDimHtml = '';
                        if (isResized) {
                            var origPill = '';
                            if (isOrigDimUnavailable) {
                                origPill = '<span class="sba-dim-pill sba-dim-warning" style="background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c;" title="' + sba__('Original dimensions could not be verified from backup', 'Original dimensions could not be verified from backup') + '">' +
                                    '<span class="dashicons dashicons-warning" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Original dimensions unavailable', 'Original dimensions unavailable') +
                                    '</span>';
                            } else {
                                origPill = '<span class="sba-dim-pill sba-dim-original" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #475569;" title="' + sba__('Verified original resolution prior to optimization', 'Verified original resolution prior to optimization') + '">' +
                                    '<span class="dashicons dashicons-backup" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Original: ', 'Original: ') + '<strong>' + safeOrigDim + '</strong>' +
                                    '</span>';
                            }
                            newDimHtml = origPill +
                                '<span class="sba-dim-arrow">→</span>' +
                                '<span class="sba-dim-pill sba-dim-current" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534;" title="' + sba__('Current resolution on disk', 'Current resolution on disk') + '">' +
                                '<span class="dashicons dashicons-yes-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeCurrDim + '</strong>' +
                                '</span>';
                        } else {
                            newDimHtml = '<span class="sba-dim-pill sba-dim-ok" style="background: #f0fdf4; border: 1px solid #86efac; color: #166534;">' +
                                '<span class="dashicons dashicons-yes-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeCurrDim + '</strong>' +
                                '</span>';
                        }
                        if ($dimContainer.length) {
                            $dimContainer.html(newDimHtml);
                        } else {
                            $row.find('.sba-col-info').append('<div class="sba-dim-row">' + newDimHtml + '</div>');
                        }

                        // B. File Size & Savings inside .sba-col-size
                        var safeOrigSize = sbaEscapeHtml(foundItem.original_size_formatted || (data.original_size_fmt || ''));
                        var savedPct = foundItem.savings_percent || data.savings_percent || 0;
                        var webpTagHtml = '<div class="sba-webp-tag" style="margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px;" title="' + sba__('WebP companion image generated and active', 'WebP companion image generated and active') + '">' +
                            '<span class="dashicons dashicons-yes" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                            sba__('WebP Available', 'WebP Available') +
                            '</div>';

                        var newSizeHtml = '<div style="font-size: 13px;">' +
                            '<span style="text-decoration: line-through; color: #94a3b8; font-weight: 500;">' + safeOrigSize + '</span> ' +
                            '<span style="color: #94a3b8; margin: 0 2px;">→</span> ' +
                            '<span style="font-weight: 700; color: #0f172a;">' + sbaEscapeHtml(optSizeFormatted) + '</span>' +
                            '</div>' +
                            '<div class="sba-savings-tag" style="margin-top: 4px; color: #16a34a; font-weight: 600;">' +
                            '<span class="dashicons dashicons-arrow-down-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                            sba__('Saved ', 'Saved ') + sbaEscapeHtml(freedFormatted) + ' (' + savedPct + '%)' +
                            '</div>' +
                            webpTagHtml;
                        $row.find('.sba-col-size').html(newSizeHtml);

                        // C. Recommendation & Action inside .sba-col-actions
                        var rollbackBtnHtml = '<button type="button" class="button button-small sba-btn-rollback-item" data-id="' + attId + '" title="' + sba__('Rollback to original uncompressed image', 'Rollback to original uncompressed image') + '">' +
                            '<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>' +
                            '</button>';

                        var editBtnHtml = '';
                        if (safeEditUrl) {
                            editBtnHtml = '<a href="' + sbaEscapeHtml(safeEditUrl) + '" target="_blank" class="button button-small sba-btn-edit-wp">' +
                                '<span class="dashicons dashicons-edit"></span> <span>' + sba__('Edit in WP', 'Edit in WP') + '</span>' +
                                '</a>';
                        } else {
                            editBtnHtml = '<button type="button" class="button button-small sba-btn-edit-wp" disabled style="opacity: 0.5; cursor: not-allowed;">' +
                                '<span class="dashicons dashicons-edit"></span> <span>' + sba__('Edit in WP', 'Edit in WP') + '</span>' +
                                '</button>';
                        }

                        var targetBadgeText = sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.');
                        var newActionHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-active" title="' + sbaEscapeHtml(targetBadgeText) + '"><span class="dashicons dashicons-yes"></span> ' + sbaEscapeHtml(targetBadgeText) + '</span></div>' +
                            '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                            rollbackBtnHtml +
                            editBtnHtml +
                            '</div>';
                        $row.find('.sba-col-actions').html(newActionHtml);

                        // Subtle green highlight animation to give immediate visual feedback
                        $row.css('transition', 'background-color 0.4s ease').css('background-color', '#ecfdf5');
                        setTimeout(function () {
                            $row.css('background-color', '');
                        }, 1800);
                    }
                } else {
                    // Task 7: WordPress AJAX error
                    var errMsg = (response && response.data) ?
                        (typeof response.data === 'string' ? response.data : (response.data.message || JSON.stringify(response.data))) :
                        sba__('Optimization failed on server.', 'Optimization failed on server.');
                    showNotice(errMsg, true);

                    // Restore button state
                    $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Optimize & WebP', 'Optimize & WebP') + '</span>');
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                // Task 3: Handle timeout, malformed JSON, network failure, HTTP error
                var msg = '';
                if (textStatus === 'timeout') {
                    msg = sba__('Optimization request timed out after 60 seconds.', 'Optimization request timed out after 60 seconds.');
                } else if (textStatus === 'parsererror') {
                    msg = sba__('Server returned malformed response during optimization.', 'Server returned malformed response during optimization.');
                } else {
                    var statusDetail = errorThrown || (jqXHR ? jqXHR.statusText : '');
                    msg = sba__('Server error while optimizing image.', 'Server error while optimizing image.') + (statusDetail ? ' (' + statusDetail + ')' : '');
                }
                showNotice(msg, true);

                // Restore button state
                $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Optimize & WebP', 'Optimize & WebP') + '</span>');
            }).always(function () {
                // Task 4: Guaranteed finalization path
                delete activeOptimizingIds[attId];
                if ($btn.length && $btn.hasClass('is-loading')) {
                    $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Optimize & WebP', 'Optimize & WebP') + '</span>');
                }
            });
        });

        // 1-Click Rollback Optimization Handler
        $(document).on('click', '.sba-btn-rollback-item', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var attId = parseInt($btn.data('id'), 10);
            if (!attId) {
                return;
            }

            // Task 5: Prevent duplicate rollback requests while in progress
            if (activeRollbackIds[attId]) {
                return;
            }
            activeRollbackIds[attId] = true;

            $btn.addClass('is-loading').prop('disabled', true).html('<span class="dashicons dashicons-update sba-rotating"></span> ' + sba__('Restoring...', 'Restoring...'));

            var $row = $('#sba-media-row-' + attId);

            $.ajax({
                url: sbaAjaxUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 60000,
                data: {
                    action: 'sba_rollback_image_optimization',
                    _ajax_nonce: sbaNonce,
                    attachment_id: attId
                }
            }).done(function (response) {
                if (response && response.success && response.data) {
                    var data = response.data;
                    showNotice(data.message || sba__('Original image restored successfully!', 'Original image restored successfully!'), false);

                    var restoredSizeFormatted = data.restored_size || data.file_size_formatted || '0 B';
                    var restoredDim = data.restored_dimensions || data.original_dimensions || 'N/A';
                    var isOversizedAgain = !!data.is_oversized;
                    var safeEditUrl = '';

                    // 1. Update in-memory data
                    var foundItem = null;
                    if (allMedia && Array.isArray(allMedia)) {
                        for (var i = 0; i < allMedia.length; i++) {
                            if (parseInt(allMedia[i].id, 10) === attId) {
                                allMedia[i].is_oversized = isOversizedAgain;
                                allMedia[i].has_backup = false;
                                allMedia[i].has_webp = false;
                                allMedia[i].is_resized = false;
                                allMedia[i].target_reached = !isOversizedAgain;
                                allMedia[i].file_size_formatted = restoredSizeFormatted;
                                if (data.restored_size_bytes) {
                                    allMedia[i].file_size_bytes = data.restored_size_bytes;
                                }
                                if (restoredDim && restoredDim !== 'N/A') {
                                    allMedia[i].original_dimensions = restoredDim;
                                    allMedia[i].current_dimensions = restoredDim;
                                }
                                allMedia[i].saved_bytes = 0;
                                allMedia[i].saved_bytes_formatted = '0 B';
                                allMedia[i].savings_percent = 0;
                                if (isOversizedAgain) {
                                    allMedia[i].category = 'oversized';
                                    allMedia[i].category_label = sba__('Needs Resize', 'Needs Resize');
                                    allMedia[i].recommendation_text = data.recommendation_text || sba__('Scale down', 'Scale down');
                                    allMedia[i].recommendation_badge = data.recommendation_badge || sba__('Scale down', 'Scale down');
                                    allMedia[i].badge_class = 'sba-pill-warning';
                                    if (data.recommended_dimensions) {
                                        allMedia[i].recommended_dimensions = data.recommended_dimensions;
                                    }
                                } else {
                                    allMedia[i].category = 'optimized';
                                    allMedia[i].category_label = sba__('Optimized', 'Optimized');
                                    allMedia[i].recommendation_text = sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.');
                                    allMedia[i].recommendation_badge = sba__('Web Optimized', 'Web Optimized');
                                    allMedia[i].badge_class = 'sba-pill-active';
                                }
                                foundItem = allMedia[i];
                                safeEditUrl = (foundItem.edit_url && typeof foundItem.edit_url === 'string') ?
                                    foundItem.edit_url.replace(/&#038;/g, '&').replace(/&amp;/g, '&') : '';
                                break;
                            }
                        }
                    }

                    // Move from optimizedMedia back to oversizedMedia if oversized
                    if (optimizedMedia && Array.isArray(optimizedMedia)) {
                        var optIdx = optimizedMedia.findIndex(function (m) { return parseInt(m.id, 10) === attId; });
                        if (optIdx !== -1) {
                            optimizedMedia.splice(optIdx, 1);
                        }
                    }
                    if (isOversizedAgain && oversizedMedia && Array.isArray(oversizedMedia)) {
                        var alreadyOver = oversizedMedia.some(function (m) { return parseInt(m.id, 10) === attId; });
                        if (!alreadyOver && foundItem) {
                            oversizedMedia.unshift(foundItem);
                        }
                    }

                    // Update stats
                    $('#sba-stat-media-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-media-count-oversized').text(formatNumber(oversizedMedia.length));
                    $('#sba-media-count-optimized').text(formatNumber(optimizedMedia.length));

                    // 2. Update the row directly in the DOM
                    if ($row.length) {
                        // A. Dimensions
                        var $dimContainer = $row.find('.sba-dim-row');
                        var safeDim = sbaEscapeHtml(restoredDim);
                        var safeRecDim = sbaEscapeHtml(foundItem && foundItem.recommended_dimensions ? foundItem.recommended_dimensions : (data.recommended_dimensions || 'N/A'));
                        var dimContent = '';
                        if (isOversizedAgain) {
                            dimContent = '<span class="sba-dim-pill sba-dim-oversized" title="' + sba__('Resolution exceeds web display benchmark', 'Resolution exceeds web display benchmark') + '">' +
                                '<span class="dashicons dashicons-warning"></span> ' + sba__('Original: ', 'Original: ') + '<strong>' + safeDim + '</strong>' +
                                '</span>' +
                                '<span class="sba-dim-arrow">→</span>' +
                                '<span class="sba-dim-pill sba-dim-recommended">' +
                                sba__('Rec: ', 'Rec: ') + '<strong>' + safeRecDim + '</strong>' +
                                '</span>';
                        } else {
                            dimContent = '<span class="sba-dim-pill sba-dim-ok">' +
                                '<span class="dashicons dashicons-yes-alt"></span> ' + sba__('Current: ', 'Current: ') + '<strong>' + safeDim + '</strong>' +
                                '</span>';
                        }
                        if ($dimContainer.length) {
                            $dimContainer.html(dimContent);
                        } else {
                            $row.find('.sba-col-info').append('<div class="sba-dim-row">' + dimContent + '</div>');
                        }

                        // B. File Size
                        var sizeContent = '<div style="font-weight: 700; color: #0f172a; font-size: 13px;">' + sbaEscapeHtml(restoredSizeFormatted) + '</div>';
                        if (isOversizedAgain) {
                            sizeContent += '<div class="sba-savings-tag" style="margin-top: 4px;">' +
                                '<span class="dashicons dashicons-arrow-down-alt" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span> ' +
                                sba__('Optimization available', 'Optimization available') +
                                '</div>';
                        } else {
                            sizeContent += '<div class="sba-table-source" style="color: #16a34a; font-weight: 600; margin-top: 4px;">' +
                                '<span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px; vertical-align: middle;"></span> ' +
                                sba__('Web Optimized', 'Web Optimized') +
                                '</div>';
                        }
                        $row.find('.sba-col-size').html(sizeContent);

                        // C. Recommendation & Action
                        var optimizeBtnHtml = '';
                        var badgeHtml = '';
                        if (isOversizedAgain) {
                            var recBadgeText = data.recommendation_text || sba__('Scale down', 'Scale down');
                            badgeHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-warning"><span class="dashicons dashicons-warning"></span> ' + sbaEscapeHtml(recBadgeText) + '</span></div>';
                            optimizeBtnHtml = '<button type="button" class="button button-small button-primary sba-btn-optimize-item" data-id="' + attId + '" data-w="' + (foundItem ? foundItem.recommended_width : 1920) + '" data-h="' + (foundItem ? foundItem.recommended_height : 1080) + '" title="' + sba__('Resize and optimize this image safely', 'Resize and optimize this image safely') + '">' +
                                '<span class="dashicons dashicons-image-rotate"></span> <span>' + sba__('Optimize & WebP', 'Optimize & WebP') + '</span>' +
                                '</button>';
                        } else {
                            badgeHtml = '<div style="margin-bottom: 6px;"><span class="sba-badge-pill sba-pill-active"><span class="dashicons dashicons-yes"></span> ' + sba__('Target dimensions reached — no further resizing needed.', 'Target dimensions reached — no further resizing needed.') + '</span></div>';
                        }

                        var editBtnHtml = '';
                        if (safeEditUrl) {
                            editBtnHtml = '<a href="' + sbaEscapeHtml(safeEditUrl) + '" target="_blank" class="button button-small sba-btn-edit-wp">' +
                                '<span class="dashicons dashicons-edit"></span> <span>' + sba__('Edit in WP', 'Edit in WP') + '</span>' +
                                '</a>';
                        } else {
                            editBtnHtml = '<button type="button" class="button button-small sba-btn-edit-wp" disabled style="opacity: 0.5; cursor: not-allowed;">' +
                                '<span class="dashicons dashicons-edit"></span> <span>' + sba__('Edit in WP', 'Edit in WP') + '</span>' +
                                '</button>';
                        }

                        var rollbackActionHtml = badgeHtml +
                            '<div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center; flex-wrap: wrap;">' +
                            optimizeBtnHtml +
                            editBtnHtml +
                            '</div>';
                        $row.find('.sba-col-actions').html(rollbackActionHtml);

                        // Highlight amber
                        $row.css('transition', 'background-color 0.4s ease').css('background-color', '#fef3c7');
                        setTimeout(function () {
                            $row.css('background-color', '');
                        }, 1800);
                    }
                } else {
                    var errMsg = (response && response.data) ?
                        (typeof response.data === 'string' ? response.data : (response.data.message || JSON.stringify(response.data))) :
                        sba__('Rollback failed on server.', 'Rollback failed on server.');
                    showNotice(errMsg, true);

                    $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>');
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                var msg = '';
                if (textStatus === 'timeout') {
                    msg = sba__('Rollback request timed out after 60 seconds.', 'Rollback request timed out after 60 seconds.');
                } else if (textStatus === 'parsererror') {
                    msg = sba__('Server returned malformed response during rollback.', 'Server returned malformed response during rollback.');
                } else {
                    var statusDetail = errorThrown || (jqXHR ? jqXHR.statusText : '');
                    msg = sba__('Server error while rolling back image.', 'Server error while rolling back image.') + (statusDetail ? ' (' + statusDetail + ')' : '');
                }
                showNotice(msg, true);

                $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>');
            }).always(function () {
                delete activeRollbackIds[attId];
                if ($btn.length && $btn.hasClass('is-loading')) {
                    $btn.removeClass('is-loading').prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> <span>' + sba__('Rollback', 'Rollback') + '</span>');
                }
            });
        });
    });
})(jQuery);
