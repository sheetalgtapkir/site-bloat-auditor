<?php
/**
 * Dashboard template for Site Bloat & Storage Auditor.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sba_nonce = wp_create_nonce( 'sba_nonce' );
$sba_initial_quarantined = array_values( (array) get_option( 'sba_quarantined_files', [] ) );
$sba_initial_quarantined_count = count( $sba_initial_quarantined );

// Pre-calculate initial database storage metrics
$sba_initial_db_bytes          = function_exists( 'sba_get_total_database_bytes' ) ? sba_get_total_database_bytes() : 0;
$sba_initial_db_fmt            = size_format( $sba_initial_db_bytes, 2 );

// Pre-calculate initial expired temporary data
$sba_initial_transients        = function_exists( 'sba_get_expired_transients_stats' ) ? sba_get_expired_transients_stats() : [ 'count' => 0, 'bytes' => 0, 'kb' => 0, 'fmt' => '0 B' ];
$sba_initial_trans_count       = (int) ( $sba_initial_transients['count'] ?? 0 );
$sba_initial_trans_bytes       = (int) ( $sba_initial_transients['bytes'] ?? 0 );
$sba_initial_trans_fmt         = $sba_initial_transients['fmt'] ?? '0 B';

// Pre-calculate initial autoload options
$sba_initial_autoload          = function_exists( 'sba_get_autoload_stats' ) ? sba_get_autoload_stats() : [ 'count' => 0, 'bytes' => 0, 'kb' => 0, 'fmt' => '0 B' ];

// Pre-calculate initial revisions statistics
$sba_initial_revisions         = function_exists( 'sba_get_revisions_storage_stats' ) ? sba_get_revisions_storage_stats() : [
    'total_count'      => 0,
    'total_bytes'      => 0,
    'total_kb'         => 0,
    'releasable_bytes' => 0,
    'releasable_kb'    => 0,
    'cleanable_count'  => 0,
    'cleanable_bytes'  => 0,
    'cleanable_kb'     => 0,
];
$sba_initial_rev_count         = (int) ( $sba_initial_revisions['total_count'] ?? 0 );
$sba_initial_rev_cleanable_cnt = (int) ( $sba_initial_revisions['cleanable_count'] ?? 0 );
$sba_initial_rev_cleanable_b   = (int) ( $sba_initial_revisions['cleanable_bytes'] ?? 0 );
$sba_initial_rev_bytes         = (int) ( $sba_initial_revisions['total_bytes'] ?? 0 );
$sba_initial_rev_releasable    = (int) ( $sba_initial_revisions['releasable_bytes'] ?? $sba_initial_rev_bytes );
$sba_initial_rev_size_fmt      = size_format( $sba_initial_rev_bytes, 2 ) . ' (' . number_format_i18n( $sba_initial_rev_bytes ) . ' bytes)';
$sba_initial_rev_release_fmt   = size_format( $sba_initial_rev_releasable, 2 ) . ' (' . number_format_i18n( $sba_initial_rev_releasable ) . ' bytes)';

// Conservative initial recoverable space & eligible items
$sba_initial_recoverable_bytes = $sba_initial_rev_cleanable_b + $sba_initial_trans_bytes;
$sba_initial_recoverable_fmt   = size_format( $sba_initial_recoverable_bytes, 2 );
$sba_initial_eligible_items    = $sba_initial_rev_cleanable_cnt + $sba_initial_trans_count;
$sba_initial_eligible_meta     = sprintf(
    /* translators: 1: revisions count, 2: temporary data count */
    __( '%1$d drafts • %2$d temporary • scan for tables', 'site-bloat-auditor' ),
    $sba_initial_rev_cleanable_cnt,
    $sba_initial_trans_count
);

global $wpdb;
$sba_initial_tables_count      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s", $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
$sba_initial_tables_meta       = sprintf( __( '%d tables in database', 'site-bloat-auditor' ), $sba_initial_tables_count );
$sba_last_db_scan              = get_option( 'sba_last_db_scan', [] );
?>

<div class="wrap sba-dashboard-wrap">
    <!-- Header -->
    <div class="sba-header">
        <div class="sba-header-text">
            <h1><?php esc_html_e( 'Site Bloat & Storage Auditor', 'site-bloat-auditor' ); ?></h1>
            <p class="sba-subtitle"><?php esc_html_e( 'All-in-one site health clinic to scan orphaned DB tables, bloated media files, and unverified plugins.', 'site-bloat-auditor' ); ?></p>
        </div>
    </div>

    <!-- Main 3-Tab Horizontal Navigation Bar -->
    <nav class="sba-main-nav" role="tablist">
        <button type="button" class="sba-main-tab-btn active" data-main-tab="database" role="tab" aria-selected="true" aria-controls="sba-tab-pane-database">
            <span class="dashicons dashicons-database"></span>
            <span class="sba-main-tab-label"><?php esc_html_e( 'Database Audit', 'site-bloat-auditor' ); ?></span>
        </button>
        <button type="button" class="sba-main-tab-btn" data-main-tab="media" role="tab" aria-selected="false" aria-controls="sba-tab-pane-media">
            <span class="dashicons dashicons-admin-media"></span>
            <span class="sba-main-tab-label"><?php esc_html_e( 'Media Files Audit', 'site-bloat-auditor' ); ?></span>
        </button>
        <button type="button" class="sba-main-tab-btn" data-main-tab="plugins" role="tab" aria-selected="false" aria-controls="sba-tab-pane-plugins">
            <span class="dashicons dashicons-admin-plugins"></span>
            <span class="sba-main-tab-label"><?php esc_html_e( 'Plugin Audit', 'site-bloat-auditor' ); ?></span>
        </button>
    </nav>

    <!-- Alert / Toast Banner Container -->
    <div id="sba-notice-container" style="display: none; margin-bottom: 20px;"></div>

    <!-- ======================================================================= -->
    <!-- TAB 1: DATABASE AUDIT (Active by default)                               -->
    <!-- ======================================================================= -->
    <div id="sba-tab-pane-database" class="sba-tab-pane active" role="tabpanel">
        <!-- Scoped Tab Notice Container -->
        <div class="sba-tab-notice-container" id="sba-notice-database" style="display: none; margin-bottom: 20px;"></div>

        <!-- Top Safety Banner: Database Safety & Full Backup -->
        <div class="sba-safety-card">
            <div class="sba-safety-card-left">
                <div class="sba-safety-card-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="sba-safety-card-text">
                    <h3><?php esc_html_e( 'Database Safety & Full Backup', 'site-bloat-auditor' ); ?></h3>
                    <p><?php esc_html_e( 'WordPress Core and active plugin tables are permanently protected. Before dropping orphaned tables or performing database maintenance, export a full compressed SQL backup of all database tables.', 'site-bloat-auditor' ); ?></p>
                    <div class="sba-safety-hint">
                        <span class="dashicons dashicons-info"></span>
                        <span><?php esc_html_e( 'Compressed .zip backup reduces download size by up to 90% and ensures safe disaster recovery.', 'site-bloat-auditor' ); ?></span>
                    </div>
                </div>
            </div>
            <div class="sba-safety-card-right">
                <div class="sba-backup-buttons-group">
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=sba_export_full_database&format=zip&_ajax_nonce=' . $sba_nonce ) ); ?>" id="sba-btn-full-backup-zip" class="button button-primary button-hero sba-btn-export-zip">
                        <span class="dashicons dashicons-archive"></span>
                        <span class="sba-full-backup-zip-label"><?php esc_html_e( 'Export Compressed (.zip)', 'site-bloat-auditor' ); ?></span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Database Tab Toolbar / Trigger -->
        <div class="sba-tab-toolbar">
            <div class="sba-tab-toolbar-text">
                <h2><?php esc_html_e( 'Database Table Health & Optimization', 'site-bloat-auditor' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Audit database tables, identify orphaned rows, and optimize wp_options and revisions.', 'site-bloat-auditor' ); ?></p>
            </div>
            <div class="sba-tab-toolbar-actions">
                <button id="sba-run-scan" class="button button-primary button-hero sba-btn-scan">
                    <span class="dashicons dashicons-update"></span>
                    <span class="sba-btn-label"><?php esc_html_e( 'Run Quick DB Audit', 'site-bloat-auditor' ); ?></span>
                </button>
            </div>
        </div>

        <!-- Database Results Container -->
        <div id="sba-results" style="display: none; margin-top: 20px;">
            <!-- Scan State & Completeness Indicator Bar -->
            <div class="sba-scan-status-bar" id="sba-db-scan-status-bar">
                <div class="sba-scan-status-left">
                    <span class="dashicons dashicons-yes-alt sba-scan-status-icon" id="sba-db-scan-status-icon"></span>
                    <span class="sba-scan-status-text" id="sba-db-scan-status-text">
                        <?php
                        if ( ! empty( $sba_last_db_scan['time_fmt'] ) ) {
                            printf(
                                /* translators: 1: total tables, 2: core tables, 3: active plugin tables, 4: orphaned tables */
                                esc_html__( 'Scan Complete — All %1$d tables analyzed (%2$d Core, %3$d Active Plugins, %4$d Orphaned).', 'site-bloat-auditor' ),
                                (int) ( $sba_last_db_scan['total_tables'] ?? 0 ),
                                (int) ( $sba_last_db_scan['core_count'] ?? 0 ),
                                (int) ( $sba_last_db_scan['active_count'] ?? 0 ),
                                (int) ( $sba_last_db_scan['orphaned_count'] ?? 0 )
                            );
                        } else {
                            esc_html_e( 'Scan Complete — All database tables and health metrics analyzed.', 'site-bloat-auditor' );
                        }
                        ?>
                    </span>
                </div>
                <div class="sba-scan-status-right">
                    <span class="sba-scan-timestamp-label"><?php esc_html_e( 'Last Scanned:', 'site-bloat-auditor' ); ?></span>
                    <strong id="sba-db-last-scan-time"><?php echo esc_html( $sba_last_db_scan['time_fmt'] ?? __( 'Just now', 'site-bloat-auditor' ) ); ?></strong>
                </div>
            </div>

            <!-- Section A: Top Database Storage & Recoverable Space Summary -->
            <div class="sba-cards-grid sba-db-summary-grid">
                <!-- Metric 1: Current Database Size -->
                <div class="sba-card sba-card-summary">
                    <div class="sba-card-icon sba-icon-db-size" style="background: #e0f2fe; color: #0284c7;">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Current Database Size', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="db_total_size" aria-label="<?php esc_attr_e( 'Learn about Current Database Size', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sba-card-value" id="sba-stat-db-total-size"><?php echo esc_html( $sba_initial_db_fmt ); ?></div>
                        <div class="sba-card-meta"><?php esc_html_e( 'Total measured size of all database tables', 'site-bloat-auditor' ); ?></div>
                    </div>
                </div>

                <!-- Metric 2: Estimated Recoverable Space -->
                <div class="sba-card sba-card-summary">
                    <div class="sba-card-icon sba-icon-db-recoverable" style="background: #dcfce7; color: #16a34a;">
                        <span class="dashicons dashicons-trash"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Estimated Recoverable Space', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="db_recoverable_space" aria-label="<?php esc_attr_e( 'Learn about Estimated Recoverable Space', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sba-card-value sba-text-release" id="sba-stat-db-recoverable-size"><?php echo esc_html( $sba_initial_recoverable_fmt ); ?></div>
                        <div class="sba-card-meta"><?php esc_html_e( 'Conservative estimate from eligible cleanup candidates', 'site-bloat-auditor' ); ?></div>
                    </div>
                </div>

                <!-- Metric 3: Items Requiring Review / Eligible for Review/Cleanup -->
                <div class="sba-card sba-card-summary">
                    <div class="sba-card-icon sba-icon-db-eligible" style="background: #fef3c7; color: #d97706;">
                        <span class="dashicons dashicons-filter"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Items Requiring Review', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="db_eligible_items" aria-label="<?php esc_attr_e( 'Learn about Items Requiring Review', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sba-card-value" id="sba-stat-db-eligible-items"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $sba_initial_eligible_items, 'site-bloat-auditor' ), $sba_initial_eligible_items ) ); ?></div>
                        <div class="sba-card-meta" id="sba-stat-db-eligible-meta"><?php esc_html_e( 'Eligible for Review/Cleanup: ', 'site-bloat-auditor' ); echo esc_html( $sba_initial_eligible_meta ); ?></div>
                    </div>
                </div>
            </div>

            <!-- Section B: Understandable Health & Optimization Cards -->
            <div class="sba-cards-grid">
                <!-- Card 1: Data Loaded on Every Page (Replaces Autoload Size) -->
                <div class="sba-card sba-card-detailed">
                    <div class="sba-card-icon sba-icon-autoload">
                        <span class="dashicons dashicons-performance"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Data Loaded on Every Page', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="autoload_data" aria-label="<?php esc_attr_e( 'Learn about Data Loaded on Every Page', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sba-card-value" id="sba-stat-autoload-size"><?php echo esc_html( $sba_initial_autoload['fmt'] ); ?></div>
                        <div class="sba-card-meta" id="sba-stat-autoload-count"><?php echo esc_html( number_format_i18n( $sba_initial_autoload['count'] ) . ' ' . __( 'autoload rows', 'site-bloat-auditor' ) ); ?></div>
                        <p class="sba-card-desc"><?php esc_html_e( 'Loaded into PHP memory on every request; affects TTFB and server RAM. Request-time memory load — not disk space.', 'site-bloat-auditor' ); ?></p>
                    </div>
                </div>

                <!-- Card 2: Saved Content Versions (Replaces Post Revisions) -->
                <div class="sba-card sba-card-revisions sba-card-detailed">
                    <div class="sba-card-icon sba-icon-revisions">
                        <span class="dashicons dashicons-backup"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Saved Content Versions', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="revisions" aria-label="<?php esc_attr_e( 'Learn about Saved Content Versions', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                            <button type="button" id="sba-clean-revisions-btn" class="button button-small sba-kpi-btn-action" title="<?php esc_attr_e( 'Safely clean old draft versions beyond the last 3 edits per post', 'site-bloat-auditor' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="sba-action-label"><?php esc_html_e( 'Clean', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-value">
                            <button type="button" class="sba-stat-revisions-clickable" id="sba-stat-revisions-trigger" title="<?php esc_attr_e( 'Click to view all saved versions in Site Bloat Auditor', 'site-bloat-auditor' ); ?>">
                                <span id="sba-stat-revisions"><?php echo esc_html( number_format_i18n( $sba_initial_rev_count ) ); ?></span>
                                <span class="sba-rev-click-hint"><?php esc_html_e( 'versions (view list)', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-meta sba-rev-storage-meta">
                            <span class="sba-rev-size-text"><?php esc_html_e( 'Storage in use:', 'site-bloat-auditor' ); ?> <strong id="sba-stat-revisions-size"><?php echo esc_html( $sba_initial_rev_size_fmt ); ?></strong></span>
                        </div>
                        <div class="sba-card-meta sba-rev-release-meta">
                            <span class="sba-rev-free-text"><?php esc_html_e( 'Reclaimable on clean:', 'site-bloat-auditor' ); ?> <strong id="sba-stat-revisions-releasable" style="color: #16a34a;"><?php echo esc_html( $sba_initial_rev_release_fmt ); ?></strong></span>
                        </div>
                        <p class="sba-card-desc"><?php esc_html_e( 'Old revision drafts beyond the 3 most recent edits per post; safely pruning older drafts.', 'site-bloat-auditor' ); ?></p>
                    </div>
                </div>

                <!-- Card 3: Expired Temporary Data (Replaces Expired Transients) -->
                <div class="sba-card sba-card-detailed">
                    <div class="sba-card-icon sba-icon-transients">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Expired Temporary Data', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="transients" aria-label="<?php esc_attr_e( 'Learn about Expired Temporary Data', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                            <button type="button" id="sba-clean-transients-btn" class="button button-small sba-kpi-btn-action" title="<?php esc_attr_e( 'Purge confirmed expired temporary data from the database', 'site-bloat-auditor' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="sba-action-label"><?php esc_html_e( 'Clear', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-value" id="sba-stat-transients"><?php echo esc_html( number_format_i18n( $sba_initial_trans_count ) ); ?></div>
                        <div class="sba-card-meta"><span id="sba-stat-transients-meta"><?php echo esc_html( number_format_i18n( $sba_initial_trans_count ) . ' ' . __( 'outdated rows', 'site-bloat-auditor' ) . ' (' . $sba_initial_trans_fmt . ')' ); ?></span></div>
                        <p class="sba-card-desc"><?php esc_html_e( 'Cached temporary rows past their expiration date; safe to purge without impacting live visitors.', 'site-bloat-auditor' ); ?></p>
                    </div>
                </div>

                <!-- Card 4: Database Tables Overview (Replaces Total Tables) -->
                <div class="sba-card sba-card-detailed sba-card-clickable" id="sba-card-tables-overview" title="<?php esc_attr_e( 'Click to view tables breakdown below', 'site-bloat-auditor' ); ?>">
                    <div class="sba-card-icon sba-icon-tables">
                        <span class="dashicons dashicons-list-view"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <div class="sba-card-title-wrap">
                                <span class="sba-card-title"><?php esc_html_e( 'Database Tables', 'site-bloat-auditor' ); ?></span>
                                <button type="button" class="sba-info-icon-btn" data-metric="tables_breakdown" aria-label="<?php esc_attr_e( 'Learn about Database Tables', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                    <span class="dashicons dashicons-info-outline"></span>
                                </button>
                            </div>
                        </div>
                        <div class="sba-card-value" id="sba-stat-total-tables"><?php echo esc_html( $sba_initial_tables_count ); ?></div>
                        <div class="sba-card-meta" id="sba-stat-tables-meta"><?php echo esc_html( $sba_initial_tables_meta ); ?></div>
                        <p class="sba-card-desc"><?php esc_html_e( 'Core and active plugin tables are permanently protected; leftover orphaned tables require review.', 'site-bloat-auditor' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Tables Section -->
            <div class="sba-section-panel" id="sba-tables-section-panel">
                <div class="sba-panel-header">
                    <div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <h2><?php esc_html_e( 'Database Table Health & Orphaned Detector', 'site-bloat-auditor' ); ?></h2>
                            <button type="button" class="sba-info-icon-btn" data-metric="tables_breakdown" aria-label="<?php esc_attr_e( 'Learn about Database Table Health & Breakdown', 'site-bloat-auditor' ); ?>" aria-haspopup="dialog" aria-expanded="false">
                                <span class="dashicons dashicons-info-outline"></span>
                            </button>
                        </div>
                        <p class="description"><?php esc_html_e( 'Filter and inspect WordPress Core tables, tables owned by active plugins, and leftover orphaned tables.', 'site-bloat-auditor' ); ?></p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div class="sba-search-box">
                            <input type="text" id="sba-table-search" placeholder="<?php esc_attr_e( 'Search tables...', 'site-bloat-auditor' ); ?>" class="regular-text" />
                        </div>
                        <button type="button" id="sba-toggle-tables-btn" class="button button-secondary sba-toggle-tables-btn" aria-expanded="true" aria-controls="sba-tables-content-wrap">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                            <span class="sba-toggle-tables-text"><?php esc_html_e( 'Collapse Tables', 'site-bloat-auditor' ); ?></span>
                        </button>
                    </div>
                </div>

                <div id="sba-tables-content-wrap">
                    <!-- Filter Badges / Tabs -->
                    <div class="sba-tabs-nav sba-table-tabs" role="tablist">
                        <button type="button" class="sba-tab-btn sba-table-tab-btn active" data-tab="all">
                            <?php esc_html_e( 'All Tables', 'site-bloat-auditor' ); ?>
                            <span class="sba-tab-count sba-badge-gray" id="sba-count-all">0</span>
                        </button>
                        <button type="button" class="sba-tab-btn sba-table-tab-btn" data-tab="core">
                            <span class="dashicons dashicons-wordpress"></span>
                            <?php esc_html_e( 'Core Tables', 'site-bloat-auditor' ); ?>
                            <span class="sba-tab-count sba-badge-blue" id="sba-count-core">0</span>
                        </button>
                        <button type="button" class="sba-tab-btn sba-table-tab-btn" data-tab="active_plugin">
                            <span class="dashicons dashicons-plugins-checked"></span>
                            <?php esc_html_e( 'Active Plugin Tables', 'site-bloat-auditor' ); ?>
                            <span class="sba-tab-count sba-badge-green" id="sba-count-active">0</span>
                        </button>
                        <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-table-tab-btn" data-tab="orphaned">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e( 'Orphaned Tables', 'site-bloat-auditor' ); ?>
                            <span class="sba-tab-count sba-badge-red" id="sba-count-orphaned">0</span>
                        </button>
                    </div>

                    <!-- Orphaned Tables Warning Callout -->
                    <div id="sba-orphaned-warning-banner" class="sba-callout sba-callout-warning" style="display: none;">
                        <div class="sba-callout-icon">
                            <span class="dashicons dashicons-warning"></span>
                        </div>
                        <div class="sba-callout-body">
                            <strong><?php esc_html_e( 'Orphaned Tables Detected', 'site-bloat-auditor' ); ?></strong>
                            <p><?php esc_html_e( 'These tables do not belong to WordPress Core or any currently active plugin. They often remain after plugins are uninstalled or deactivated. Dropping an orphaned table permanently deletes its data. Always ensure you have a fresh database backup before dropping tables.', 'site-bloat-auditor' ); ?></p>
                        </div>
                    </div>

                    <!-- Tables List -->
                    <div class="sba-table-container">
                        <table class="wp-list-table widefat fixed striped sba-data-table">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 32%;"><?php esc_html_e( 'Table Name', 'site-bloat-auditor' ); ?></th>
                                    <th scope="col" style="width: 25%;"><?php esc_html_e( 'Category & Source', 'site-bloat-auditor' ); ?></th>
                                    <th scope="col" style="width: 14%;"><?php esc_html_e( 'Rows', 'site-bloat-auditor' ); ?></th>
                                    <th scope="col" style="width: 14%;"><?php esc_html_e( 'Size', 'site-bloat-auditor' ); ?></th>
                                    <th scope="col" style="width: 15%; text-align: right;"><?php esc_html_e( 'Action', 'site-bloat-auditor' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="sba-tables-body">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                        <div id="sba-empty-state" class="sba-empty-state" style="display: none;">
                            <span class="dashicons dashicons-yes-alt" id="sba-empty-icon"></span>
                            <p class="sba-empty-title" id="sba-empty-title"><?php esc_html_e( 'No tables match the current filter', 'site-bloat-auditor' ); ?></p>
                            <p class="description" id="sba-empty-desc" style="display: none; margin-top: 6px;"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- TAB 2: MEDIA FILES AUDIT                                                -->
    <!-- ======================================================================= -->
    <div id="sba-tab-pane-media" class="sba-tab-pane" role="tabpanel" style="display: none;">
        <!-- Scoped Tab Notice Container -->
        <div class="sba-tab-notice-container" id="sba-notice-media" style="display: none; margin-bottom: 20px;"></div>

        <!-- Media Toolbar / Trigger -->
        <div class="sba-tab-toolbar">
            <div class="sba-tab-toolbar-text">
                <h2><?php esc_html_e( 'Media Files & Storage Audit', 'site-bloat-auditor' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Scan the WordPress media library and upload folders to identify unused media, calculate thumbnail sub-sizes, and quarantine unlinked images safely.', 'site-bloat-auditor' ); ?></p>
            </div>
            <div class="sba-tab-toolbar-actions">
                <button id="sba-run-media-scan" class="button button-primary button-hero sba-btn-scan">
                    <span class="dashicons dashicons-admin-media"></span>
                    <span class="sba-btn-label"><?php esc_html_e( 'Scan Media Files', 'site-bloat-auditor' ); ?></span>
                </button>
            </div>
        </div>

        <!-- 5 Strictly Separated Summary Storage Metrics (Priority 2) -->
        <div class="sba-cards-grid" style="margin-top: 20px;">
            <div class="sba-card sba-card-clickable" id="sba-card-media-total" title="<?php esc_attr_e( 'Click to view all media attachments', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-size">
                    <span class="dashicons dashicons-format-image"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Measured Storage', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-size">0.00 MB</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Actual files on disk in uploads', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-clickable" id="sba-card-media-unused" title="<?php esc_attr_e( 'Click to view media with no detected references', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-unused">
                    <span class="dashicons dashicons-images-alt2"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Potentially Reclaimable', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-reclaimable">0.00 MB</div>
                    <div class="sba-card-meta" id="sba-stat-media-reclaimable-meta"><?php esc_html_e( 'Unreferenced & duplicate candidates', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-clickable" id="sba-card-media-oversized" title="<?php esc_attr_e( 'Click to view oversized images needing resize', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-oversized">
                    <span class="dashicons dashicons-image-rotate"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Optimization Savings', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-opt-savings">0.00 MB</div>
                    <div class="sba-card-meta" id="sba-stat-media-opt-meta"><?php esc_html_e( 'Estimated image resize/WebP savings', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card" id="sba-card-media-backup-storage" title="<?php esc_attr_e( 'Storage occupied by safety backups and quarantined files', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon" style="background: #fef3c7; color: #d97706;">
                    <span class="dashicons dashicons-shield-alt"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Backup Storage', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-backup-storage">0.00 MB</div>
                    <div class="sba-card-meta" id="sba-stat-media-backup-meta"><?php esc_html_e( 'Quarantine & .sba-orig backups', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card" id="sba-card-media-savings" title="<?php esc_attr_e( 'Verified bytes physically deleted from disk', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-savings">
                    <span class="dashicons dashicons-trash"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Physically Reclaimed', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-reclaimed">0.00 MB</div>
                    <div class="sba-card-meta" id="sba-stat-media-reclaimed-meta"><?php esc_html_e( 'Confirmed deleted from disk', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>
        </div>

        <!-- Media Results Section Panel -->
        <div class="sba-section-panel" style="margin-top: 20px;">
            <div class="sba-panel-header">
                <div>
                    <h2><?php esc_html_e( 'Media Library Hygiene & Dimension Audit', 'site-bloat-auditor' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Inspect image resolutions against web display standards, track placements across posts, and safely isolate unlinked media.', 'site-bloat-auditor' ); ?></p>
                </div>
                <div class="sba-search-box">
                    <input type="text" id="sba-media-search" placeholder="<?php esc_attr_e( 'Search by filename, title, or post...', 'site-bloat-auditor' ); ?>" class="regular-text" />
                </div>
            </div>

            <!-- Filter Tabs Bar -->
            <div class="sba-tabs-nav" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;" role="tablist">
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="sba-tab-btn sba-media-filter-btn active" data-filter="all">
                        <span class="dashicons dashicons-admin-media"></span>
                        <?php esc_html_e( 'All Media', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-gray" id="sba-media-count-all">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="unused">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php esc_html_e( 'No References Found', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-orange" id="sba-media-count-unused">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="oversized">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e( 'Oversized / Needs Resize', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-red" id="sba-media-count-oversized">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="optimized">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Optimized', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-green" id="sba-media-count-optimized">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="duplicates">
                        <span class="dashicons dashicons-images-alt"></span>
                        <?php esc_html_e( 'Duplicates', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-orange" id="sba-media-count-duplicates">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="accessibility">
                        <span class="dashicons dashicons-universal-access"></span>
                        <?php esc_html_e( 'Accessibility', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-blue" id="sba-media-count-accessibility">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="discrepancies">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e( 'Missing / Broken', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count sba-badge-red" id="sba-media-count-discrepancies">0</span>
                    </button>
                    <button type="button" class="sba-tab-btn sba-media-filter-btn" data-filter="quarantined">
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e( 'Quarantined', 'site-bloat-auditor' ); ?>
                        <span class="sba-tab-count <?php echo $sba_initial_quarantined_count > 0 ? 'sba-badge-red' : 'sba-badge-gray'; ?>" id="sba-media-count-quarantined"><?php echo (int) $sba_initial_quarantined_count; ?></span>
                    </button>
                </div>
                <div class="sba-quarantine-toolbar-actions" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <button type="button" id="sba-btn-restore-selected" class="button sba-btn-restore-item" style="display: none;">
                        <span class="dashicons dashicons-undo"></span>
                        <span><?php esc_html_e( 'Restore Selected', 'site-bloat-auditor' ); ?> (<span id="sba-quarantine-selected-count-restore">0</span>)</span>
                    </button>
                    <button type="button" id="sba-btn-empty-quarantine" class="button sba-btn-danger" title="<?php esc_attr_e( 'Empty Quarantine Permanently', 'site-bloat-auditor' ); ?>" <?php echo empty( $sba_initial_quarantined_count ) ? 'style="display: none;"' : ''; ?>>
                        <span class="dashicons dashicons-trash"></span>
                        <span id="sba-btn-empty-quarantine-label"><?php esc_html_e( 'Empty Quarantine Permanently', 'site-bloat-auditor' ); ?></span>
                    </button>
                    <button type="button" id="sba-btn-empty-all-quarantine" class="button button-link-delete" style="display: none; font-size: 12px; margin-left: 2px;" title="<?php esc_attr_e( 'Empty all quarantined files regardless of selection', 'site-bloat-auditor' ); ?>">
                        <?php esc_html_e( 'Empty All Quarantine', 'site-bloat-auditor' ); ?>
                    </button>
                </div>
            </div>

            <!-- Oversized Optimization Notice Banner -->
            <div id="sba-media-oversized-banner" class="sba-callout sba-callout-warning" style="display: none; margin-bottom: 18px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-image-rotate"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Resolution Overhead Detected', 'site-bloat-auditor' ); ?></strong>
                    <p><?php esc_html_e( 'These images exceed standard web display benchmarks (1920px container width for hero banners, 1200px for content, or file sizes > 500 KB). Downscaling dimensions and converting them to modern WebP will significantly reduce server bandwidth and accelerate Largest Contentful Paint (LCP).', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Duplicates Callout Banner -->
            <div id="sba-media-duplicates-banner" class="sba-callout sba-callout-warning" style="display: none; margin-bottom: 18px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-images-alt"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Exact Duplicate Files Detected (SHA-256)', 'site-bloat-auditor' ); ?></strong>
                    <p><?php esc_html_e( 'Identical media files discovered across separate attachment records. Consolidating duplicate media into a single asset and re-linking post references will free disk storage safely.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Accessibility Callout Banner -->
            <div id="sba-media-accessibility-banner" class="sba-callout sba-callout-info" style="display: none; margin-bottom: 18px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-universal-access"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Image Accessibility & WCAG Compliance Audit', 'site-bloat-auditor' ); ?></strong>
                    <p><?php esc_html_e( 'Reviews alternative text (alt text) hygiene, checks for non-descriptive generic filenames, and verifies screen-reader friendliness. Purely decorative images with empty alt="" are distinguished from missing attributes.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Discrepancies Callout Banner -->
            <div id="sba-media-discrepancies-banner" class="sba-callout sba-callout-warning" style="display: none; margin-bottom: 18px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Filesystem & Database Discrepancies', 'site-bloat-auditor' ); ?></strong>
                    <p><?php esc_html_e( 'Identifies broken database records pointing to missing disk files, missing intermediate thumbnails, and unattached filesystem files in /wp-content/uploads/ that lack WordPress attachment records.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Quarantined Explanation Callout -->
            <div id="sba-media-quarantine-banner" class="sba-callout sba-callout-warning" style="display: none; margin-bottom: 18px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Safe Media Quarantine Active', 'site-bloat-auditor' ); ?></strong>
                    <p><?php esc_html_e( 'Quarantined files and their generated thumbnail variations are relocated to /wp-content/uploads/sba-quarantine/ and hidden from your active Media Library. You can safely restore them anytime or permanently empty the quarantine trash to free up disk storage.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Initial State (Before scan has been triggered) -->
            <div id="sba-media-initial-state" class="sba-empty-state" style="padding: 50px 20px;">
                <span class="dashicons dashicons-format-gallery" style="font-size: 52px; width: 52px; height: 52px; color: #94a3b8; margin-bottom: 16px;"></span>
                <h3 style="margin: 0 0 8px; font-size: 18px; color: #1e293b;"><?php esc_html_e( 'Media Files Audit Ready', 'site-bloat-auditor' ); ?></h3>
                <p style="max-width: 540px; margin: 0 auto 20px; font-size: 14px; color: #64748b; line-height: 1.6;">
                    <?php esc_html_e( 'Click "Scan Media Files" above to cross-reference all attachments with published posts, pages, featured images, and theme logos to identify unlinked files, oversized dimensions, and compression savings.', 'site-bloat-auditor' ); ?>
                </p>
            </div>

            <!-- Clean State Banner (When 0 items match clean conditions) -->
            <div id="sba-media-clean-state" class="sba-clean-state" style="display: none;">
                <span class="dashicons dashicons-yes-alt"></span>
                <h3 class="sba-clean-title" id="sba-media-clean-title"><?php esc_html_e( 'No unreferenced media detected.', 'site-bloat-auditor' ); ?></h3>
                <p class="sba-clean-desc" id="sba-media-clean-desc"><?php esc_html_e( 'All scanned attachments have detected references in posts, pages, featured images, or theme settings.', 'site-bloat-auditor' ); ?></p>
            </div>

            <!-- Empty Quarantined State -->
            <div id="sba-quarantined-empty-state" class="sba-empty-state" style="display: none; padding: 40px 20px;">
                <span class="dashicons dashicons-shield" style="font-size: 48px; width: 48px; height: 48px; color: #94a3b8; margin-bottom: 12px;"></span>
                <h3 style="margin: 0 0 6px; font-size: 16px; color: #1e293b;"><?php esc_html_e( 'Quarantine Trash is Empty', 'site-bloat-auditor' ); ?></h3>
                <p style="margin: 0; font-size: 13px; color: #64748b;"><?php esc_html_e( 'No media files are currently quarantined. Quarantined media will appear here.', 'site-bloat-auditor' ); ?></p>
            </div>

            <!-- Bulk Actions Floating Toolbar (Visible when 1+ media items selected) -->
            <div id="sba-media-bulk-toolbar" class="sba-media-bulk-toolbar" style="display: none;">
                <div class="sba-bulk-toolbar-left">
                    <span class="dashicons dashicons-yes-alt" style="color: #2563eb; font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span>
                    <strong id="sba-bulk-selection-count">0</strong> <span id="sba-bulk-selection-label"><?php esc_html_e( 'images selected', 'site-bloat-auditor' ); ?></span>
                    <button type="button" id="sba-bulk-btn-clear" class="button button-link sba-btn-clear-selection" style="font-size: 12px; margin-left: 8px; color: #64748b; text-decoration: none;">
                        <?php esc_html_e( 'Clear Selection', 'site-bloat-auditor' ); ?>
                    </button>
                </div>
                <div class="sba-bulk-toolbar-actions">
                    <button type="button" id="sba-bulk-btn-optimize" class="button button-secondary sba-bulk-action-btn" data-action="optimize" style="display: none;">
                        <span class="dashicons dashicons-image-rotate"></span>
                        <span><?php esc_html_e( 'Optimize & WebP', 'site-bloat-auditor' ); ?> (<span class="sba-action-count">0</span>)</span>
                    </button>
                    <button type="button" id="sba-bulk-btn-quarantine" class="button button-secondary sba-bulk-action-btn" data-action="quarantine" style="display: none;">
                        <span class="dashicons dashicons-shield"></span>
                        <span><?php esc_html_e( 'Move to Quarantine', 'site-bloat-auditor' ); ?> (<span class="sba-action-count">0</span>)</span>
                    </button>
                    <button type="button" id="sba-bulk-btn-restore" class="button button-secondary sba-bulk-action-btn" data-action="restore" style="display: none;">
                        <span class="dashicons dashicons-undo"></span>
                        <span><?php esc_html_e( 'Restore from Quarantine', 'site-bloat-auditor' ); ?> (<span class="sba-action-count">0</span>)</span>
                    </button>
                    <button type="button" id="sba-bulk-btn-delete" class="button sba-btn-danger sba-bulk-action-btn" data-action="delete" style="display: none;">
                        <span class="dashicons dashicons-trash"></span>
                        <span><?php esc_html_e( 'Delete Permanently', 'site-bloat-auditor' ); ?> (<span class="sba-action-count">0</span>)</span>
                    </button>
                    <button type="button" id="sba-bulk-btn-edit" class="button button-secondary sba-bulk-action-btn" data-action="edit">
                        <span class="dashicons dashicons-edit"></span>
                        <span><?php esc_html_e( 'Edit in WordPress', 'site-bloat-auditor' ); ?></span>
                    </button>
                </div>
            </div>

            <!-- Media Table Container -->
            <div id="sba-media-table-container" class="sba-table-container" style="display: none;">
                <table class="wp-list-table widefat fixed striped sba-data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="sba-media-cb-col" style="width: 38px; text-align: center; vertical-align: middle;">
                                <input type="checkbox" id="sba-media-select-all" aria-label="<?php esc_attr_e( 'Select All Displayed Images', 'site-bloat-auditor' ); ?>" title="<?php esc_attr_e( 'Select All Displayed Images', 'site-bloat-auditor' ); ?>" />
                            </th>
                            <th scope="col" style="width: 65px; text-align: center;"><?php esc_html_e( 'Preview', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 27%;"><?php esc_html_e( 'File & Dimensions', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 27%;"><?php esc_html_e( 'Usage Context', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 22%;"><?php esc_html_e( 'File Size & Savings', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 24%; text-align: right;"><?php esc_html_e( 'Recommendation & Action', 'site-bloat-auditor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sba-media-body">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- TAB 3: PLUGIN AUDIT                                                     -->
    <!-- ======================================================================= -->
    <div id="sba-tab-pane-plugins" class="sba-tab-pane" role="tabpanel" style="display: none;">
        <!-- Scoped Tab Notice Container -->
        <div class="sba-tab-notice-container" id="sba-notice-plugins" style="display: none; margin-bottom: 20px;"></div>

        <!-- Plugin Toolbar / Trigger -->
        <div class="sba-tab-toolbar">
            <div class="sba-tab-toolbar-text">
                <h2><?php esc_html_e( 'Plugin Storage & Security Hygiene', 'site-bloat-auditor' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Analyze all installed plugins for disk footprint and verify authenticity against the official WordPress.org repository.', 'site-bloat-auditor' ); ?></p>
            </div>
            <div class="sba-tab-toolbar-actions">
                <button id="sba-run-plugin-scan" class="button button-primary button-hero sba-btn-scan">
                    <span class="dashicons dashicons-update"></span>
                    <span class="sba-btn-label"><?php esc_html_e( 'Run Plugin Audit', 'site-bloat-auditor' ); ?></span>
                </button>
            </div>
        </div>

        <!-- Summary KPI Metric Cards -->
        <div class="sba-cards-grid sba-plugin-cards-grid" style="margin-top: 20px;">
            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-total">
                    <span class="dashicons dashicons-admin-plugins"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Total Plugins', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-total-plugins">0</div>
                    <div class="sba-card-meta" id="sba-stat-active-inactive-plugins"><?php esc_html_e( '0 Active • 0 Inactive', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-usage-detected">
                <div class="sba-card-icon sba-icon-usage-detected" style="background: #dcfce7; color: #15803d;">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Usage Detected', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" style="color: #15803d;" id="sba-stat-usage-detected">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Positive content or DB evidence', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-active-unused">
                <div class="sba-card-icon sba-icon-active-unused" style="background: #fef3c7; color: #b45309;">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Active · No Usage Detected', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" style="color: #b45309;" id="sba-stat-active-unused">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Potentially unused • Review required', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-usage-unknown">
                <div class="sba-card-icon sba-icon-usage-unknown" style="background: #f1f5f9; color: #475569;">
                    <span class="dashicons dashicons-editor-help"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Usage Unknown', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" style="color: #475569;" id="sba-stat-usage-unknown">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Security, cache, mail, or admin tool', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-inactive-bloat">
                <div class="sba-card-icon sba-icon-inactive-bloat" style="background: #fee2e2; color: #b91c1c;">
                    <span class="dashicons dashicons-dismiss"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Inactive Plugins', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-inactive-plugins">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Installed but not running', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-size">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Total Disk Footprint', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-plugins-total-size">0.00 MB</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Disk usage across all plugins', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>
        </div>

        <!-- Security Risk Warning Banner -->
        <div id="sba-plugin-security-banner" class="sba-callout sba-callout-warning" style="display: none; margin-top: 20px;">
            <div class="sba-callout-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="sba-callout-body">
                <strong><?php esc_html_e( 'Unverified Plugins Detected (Security & Maintenance Warning)', 'site-bloat-auditor' ); ?></strong>
                <p id="sba-plugin-security-text"><?php esc_html_e( 'One or more plugins on this site are not found in the official WordPress.org repository. These may be custom in-house code, commercial extensions, or unverified/abandoned plugins. Review them carefully to ensure they receive security updates.', 'site-bloat-auditor' ); ?></p>
            </div>
        </div>

        <!-- Plugin Results Table Panel -->
        <div class="sba-section-panel" style="margin-top: 20px;">
            <div class="sba-panel-header">
                <div>
                    <h2><?php esc_html_e( 'Installed Plugin Directory & Authenticity', 'site-bloat-auditor' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Review status, on-disk folder size, repository verification, usage evidence, and risk assessment for every plugin.', 'site-bloat-auditor' ); ?></p>
                </div>
                <div class="sba-search-box">
                    <input type="text" id="sba-plugin-search" placeholder="<?php esc_attr_e( 'Search plugins...', 'site-bloat-auditor' ); ?>" class="regular-text" />
                </div>
            </div>

            <!-- Filter Badges / Tabs for Plugins -->
            <div class="sba-tabs-nav sba-plugin-tabs" role="tablist">
                <button type="button" class="sba-tab-btn sba-plugin-tab-btn active" data-tab="all">
                    <?php esc_html_e( 'All Plugins', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-gray" id="sba-plugin-count-all">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-plugin-tab-btn" data-tab="usage_detected">
                    <span class="dashicons dashicons-yes-alt" style="color: #16a34a;"></span>
                    <?php esc_html_e( 'Usage Detected', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-green" id="sba-plugin-count-detected">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-plugin-tab-btn" data-tab="active_unused">
                    <span class="dashicons dashicons-warning" style="color: #b45309;"></span>
                    <?php esc_html_e( 'Active · No Usage Detected', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-orange" id="sba-plugin-count-active-unused">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-plugin-tab-btn" data-tab="usage_unknown">
                    <span class="dashicons dashicons-editor-help" style="color: #475569;"></span>
                    <?php esc_html_e( 'Usage Unknown', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-gray" id="sba-plugin-count-unknown">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-inactive sba-plugin-tab-btn" data-tab="inactive">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e( 'Inactive Plugins', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-gray" id="sba-plugin-count-inactive">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-plugin-tab-btn" data-tab="unverified">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'High Risk / Unverified', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-red" id="sba-plugin-count-unverified">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-plugin-tab-btn" data-tab="dependencies">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Missing Dependencies', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-orange" id="sba-plugin-count-dependencies">0</span>
                </button>
            </div>

            <!-- Plugin Bulk Selection Toolbar -->
            <div id="sba-plugins-bulk-toolbar" class="sba-bulk-toolbar" style="display: none; margin-top: 14px;">
                <div class="sba-bulk-toolbar-left">
                    <span class="dashicons dashicons-admin-plugins" style="color: #64748b;"></span>
                    <strong id="sba-plugins-selected-count">0</strong>
                    <span id="sba-plugins-selection-label"><?php esc_html_e( 'plugins selected', 'site-bloat-auditor' ); ?></span>
                </div>
                <div class="sba-bulk-toolbar-actions">
                    <select id="sba-plugin-bulk-action-select" class="sba-bulk-action-select">
                        <option value=""><?php esc_html_e( '-- Bulk Actions --', 'site-bloat-auditor' ); ?></option>
                        <option value="deactivate"><?php esc_html_e( 'Deactivate Selected', 'site-bloat-auditor' ); ?></option>
                        <option value="delete"><?php esc_html_e( 'Permanently Delete Selected', 'site-bloat-auditor' ); ?></option>
                    </select>
                    <button type="button" id="sba-plugin-btn-bulk-apply" class="button button-primary">
                        <?php esc_html_e( 'Apply', 'site-bloat-auditor' ); ?>
                    </button>
                    <button type="button" id="sba-plugins-btn-clear" class="button button-secondary">
                        <?php esc_html_e( 'Clear Selection', 'site-bloat-auditor' ); ?>
                    </button>
                </div>
            </div>

            <!-- Plugins List Table -->
            <div class="sba-table-container">
                <table class="wp-list-table widefat fixed striped sba-data-table sba-plugins-table">
                    <thead>
                        <tr>
                            <th scope="col" class="sba-plugin-cb-col" style="width: 38px; text-align: center;">
                                <input type="checkbox" id="sba-plugins-select-all" aria-label="<?php esc_attr_e( 'Select all displayed plugins', 'site-bloat-auditor' ); ?>" />
                            </th>
                            <th scope="col" style="width: 22%;"><?php esc_html_e( 'Plugin & File', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 8%;"><?php esc_html_e( 'Status', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 23%;"><?php esc_html_e( 'Usage Status & Evidence', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 17%;"><?php esc_html_e( 'Repo & Risk', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 14%;"><?php esc_html_e( 'Disk & DB Footprint', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 16%; text-align: right;"><?php esc_html_e( 'Actions', 'site-bloat-auditor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sba-plugins-body">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
                <div id="sba-plugins-empty-state" class="sba-empty-state" style="display: none;">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <p class="sba-empty-title"><?php esc_html_e( 'No plugins match the current filter', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <!-- Plugin Pagination Bar -->
            <div id="sba-plugin-pagination-wrap" class="sba-pagination-wrap" style="display: none; margin-top: 14px; padding: 10px 14px; background: #fff; border: 1px solid #c3c4c7; border-top: none; border-radius: 0 0 4px 4px; display: flex; align-items: center; justify-content: space-between;">
                <div class="sba-pagination-info">
                    <span id="sba-plugin-pagination-info"><?php esc_html_e( 'Showing 1 to 25 of 0 plugins', 'site-bloat-auditor' ); ?></span>
                </div>
                <div class="sba-pagination-controls" style="display: flex; align-items: center; gap: 8px;">
                    <label for="sba-plugin-page-size" style="font-size: 12px; color: #64748b;"><?php esc_html_e( 'Per page:', 'site-bloat-auditor' ); ?></label>
                    <select id="sba-plugin-page-size" class="sba-page-size-select" style="font-size: 12px; height: 28px; min-height: 28px;">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="all"><?php esc_html_e( 'All', 'site-bloat-auditor' ); ?></option>
                    </select>
                    <button type="button" class="button button-small sba-pagination-btn" id="sba-plugin-page-prev" disabled>
                        &laquo; <?php esc_html_e( 'Prev', 'site-bloat-auditor' ); ?>
                    </button>
                    <span id="sba-plugin-page-numbers" class="sba-pagination-numbers" style="display: flex; gap: 4px;"></span>
                    <button type="button" class="button button-small sba-pagination-btn" id="sba-plugin-page-next" disabled>
                        <?php esc_html_e( 'Next', 'site-bloat-auditor' ); ?> &raquo;
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Confirmation for Dropping Orphaned Table -->
<div id="sba-drop-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-drop-modal-title">
    <div class="sba-modal-dialog">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-drop-modal-title">
                <span class="dashicons dashicons-warning sba-modal-icon-danger"></span>
                <?php esc_html_e( 'Drop Orphaned Table', 'site-bloat-auditor' ); ?>
            </div>
            <button type="button" class="sba-modal-close sba-modal-cancel" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <p><?php esc_html_e( 'Are you sure you want to permanently drop the following database table? Review the confirmation details below:', 'site-bloat-auditor' ); ?></p>
            <div class="sba-table-drop-preview">
                <code id="sba-modal-table-name">wp_table_name</code>
                <div class="sba-table-drop-meta" id="sba-modal-table-meta">0 rows &bull; 0 KB</div>
                <div class="sba-table-drop-plugin" id="sba-modal-table-plugin"></div>
            </div>

            <div class="sba-confirm-summary-box">
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Current Database Size:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-modal-drop-current-db-size"><?php echo esc_html( $sba_initial_db_fmt ); ?></strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Eligible Records Selected:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-modal-drop-selected-rows">1 orphaned table</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Estimated Recoverable Space:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value sba-text-release" id="sba-modal-drop-recoverable-space">0 KB</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'What Will Be Deleted:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-modal-drop-what-deleted"><?php esc_html_e( 'The entire orphaned table structure and all stored rows. Unused by Core or active plugins.', 'site-bloat-auditor' ); ?></span>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Backup Availability:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-modal-drop-backup-status">
                        <span class="dashicons dashicons-yes-alt" style="color: #16a34a; vertical-align: middle;"></span>
                        <span><?php esc_html_e( 'Single table backup (.zip) download available below before dropping.', 'site-bloat-auditor' ); ?></span>
                    </span>
                </div>
            </div>

            <!-- Relational Protection Safety Notice -->
            <div class="sba-modal-safety-notice">
                <span class="dashicons dashicons-shield"></span>
                <span><?php esc_html_e( 'WordPress Core and active plugin tables are permanently protected. Only orphaned tables from deleted plugins can be removed.', 'site-bloat-auditor' ); ?></span>
            </div>

            <div class="sba-storage-engine-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e( 'Storage Note: Dropping an entire table completely deletes its table definition and releases its storage tablespace (.ibd file) immediately.', 'site-bloat-auditor' ); ?></span>
            </div>

            <!-- Single Backup Download Feedback -->
            <div id="sba-modal-backup-feedback" class="sba-modal-success-box" style="display: none;"></div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-large sba-modal-cancel">
                <?php esc_html_e( 'Cancel', 'site-bloat-auditor' ); ?>
            </button>
            <button type="button" id="sba-modal-single-backup-btn" class="button button-large button-secondary sba-modal-btn-backup">
                <span class="dashicons dashicons-download"></span>
                <span class="sba-backup-btn-label"><?php esc_html_e( 'Download Table Backup (.zip)', 'site-bloat-auditor' ); ?></span>
            </button>
            <button type="button" id="sba-modal-drop-btn" class="button button-large button-link-delete sba-modal-btn-drop">
                <span class="dashicons dashicons-trash"></span>
                <span class="sba-drop-btn-label"><?php esc_html_e( 'Permanently Drop Table', 'site-bloat-auditor' ); ?></span>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Transparent Database Cleanup Confirmation Summary (Section C) -->
<div id="sba-db-cleanup-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-db-cleanup-modal-title">
    <div class="sba-modal-dialog">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-db-cleanup-modal-title">
                <span class="dashicons dashicons-trash sba-modal-icon-danger"></span>
                <span id="sba-cleanup-modal-title-text"><?php esc_html_e( 'Confirm Database Cleanup', 'site-bloat-auditor' ); ?></span>
            </div>
            <button type="button" class="sba-modal-close sba-cleanup-modal-cancel" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <p class="sba-cleanup-lead-text" id="sba-cleanup-lead-desc"><?php esc_html_e( 'Review the cleanup summary below before proceeding. All actions are safe and verified.', 'site-bloat-auditor' ); ?></p>

            <div class="sba-confirm-summary-box">
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Current Database Size:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-cleanup-current-db-size"><?php echo esc_html( $sba_initial_db_fmt ); ?></strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Eligible Items Selected:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-cleanup-selected-count">0</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Estimated Recoverable Space:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value sba-text-release" id="sba-cleanup-recoverable-space">0 KB</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'What Will Be Deleted:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-cleanup-what-deleted">--</span>
                </div>
                <div class="sba-confirm-row" id="sba-cleanup-skipped-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Protected / Skipped:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-cleanup-skipped-desc">--</span>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Backup Availability:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-cleanup-backup-status">
                        <span class="dashicons dashicons-yes-alt" style="color: #16a34a; vertical-align: middle;"></span>
                        <span><?php esc_html_e( 'Full database backup (.zip) export is recommended and available.', 'site-bloat-auditor' ); ?></span>
                    </span>
                </div>
            </div>

            <div class="sba-storage-engine-notice">
                <span class="dashicons dashicons-info"></span>
                <span><?php esc_html_e( 'Storage Note: Row deletion frees space inside database table pages for immediate reuse by new WordPress data. In MySQL InnoDB, physical file size on disk is only shrunk if the table is defragmented/optimized.', 'site-bloat-auditor' ); ?></span>
            </div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-large sba-cleanup-modal-cancel">
                <?php esc_html_e( 'Cancel', 'site-bloat-auditor' ); ?>
            </button>
            <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=sba_export_full_database&format=zip&_ajax_nonce=' . $sba_nonce ) ); ?>" class="button button-large button-secondary" target="_blank" title="<?php esc_attr_e( 'Download a compressed SQL backup before cleaning', 'site-bloat-auditor' ); ?>">
                <span class="dashicons dashicons-archive"></span>
                <span><?php esc_html_e( 'Export Backup First (.zip)', 'site-bloat-auditor' ); ?></span>
            </a>
            <button type="button" id="sba-confirm-cleanup-execute-btn" class="button button-large button-primary button-link-delete">
                <span class="dashicons dashicons-trash"></span>
                <span id="sba-confirm-cleanup-execute-label"><?php esc_html_e( 'Confirm & Clean', 'site-bloat-auditor' ); ?></span>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Metric Information & Context Dialog (Accessible) -->
<div id="sba-metric-info-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-metric-info-heading" aria-describedby="sba-metric-info-body">
    <div class="sba-modal-dialog sba-modal-dialog-info">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-metric-info-title">
                <span class="dashicons dashicons-info sba-modal-icon-info"></span>
                <span id="sba-metric-info-heading"><?php esc_html_e( 'Metric Information', 'site-bloat-auditor' ); ?></span>
            </div>
            <button type="button" class="sba-modal-close sba-metric-info-close" aria-label="<?php esc_attr_e( 'Close dialog', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body" id="sba-metric-info-body">
            <!-- 5 Structured Context Sections -->
            <div class="sba-info-section">
                <h4 class="sba-info-section-title">
                    <span class="dashicons dashicons-translation"></span>
                    <?php esc_html_e( 'What it means in plain language', 'site-bloat-auditor' ); ?>
                </h4>
                <p id="sba-info-meaning" class="sba-info-section-desc"></p>
            </div>

            <div class="sba-info-section">
                <h4 class="sba-info-section-title">
                    <span class="dashicons dashicons-performance"></span>
                    <?php esc_html_e( 'Why it matters', 'site-bloat-auditor' ); ?>
                </h4>
                <p id="sba-info-importance" class="sba-info-section-desc"></p>
            </div>

            <div class="sba-info-section">
                <h4 class="sba-info-section-title">
                    <span class="dashicons dashicons-calculator"></span>
                    <?php esc_html_e( 'How the value is measured', 'site-bloat-auditor' ); ?>
                </h4>
                <p id="sba-info-measurement" class="sba-info-section-desc"></p>
            </div>

            <div class="sba-info-section sba-info-section-proof">
                <h4 class="sba-info-section-title">
                    <span class="dashicons dashicons-shield"></span>
                    <?php esc_html_e( 'What this value does and does NOT prove', 'site-bloat-auditor' ); ?>
                </h4>
                <div id="sba-info-proof" class="sba-info-section-desc"></div>
            </div>

            <div class="sba-info-section sba-info-section-cleanup">
                <h4 class="sba-info-section-title">
                    <span class="dashicons dashicons-admin-tools"></span>
                    <?php esc_html_e( 'What happens during cleanup', 'site-bloat-auditor' ); ?>
                </h4>
                <p id="sba-info-cleanup" class="sba-info-section-desc"></p>
            </div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-large button-secondary sba-metric-info-close">
                <?php esc_html_e( 'Close', 'site-bloat-auditor' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Post Revisions Manager (Site Bloat Auditor) -->
<div id="sba-revisions-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-revisions-modal-title">
    <div class="sba-modal-dialog sba-modal-dialog-large">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-revisions-modal-title">
                <span class="dashicons dashicons-backup sba-modal-icon-info"></span>
                <span><?php esc_html_e( 'Post Revisions Manager', 'site-bloat-auditor' ); ?></span>
            </div>
            <button type="button" class="sba-modal-close sba-modal-revisions-close" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>

        <div class="sba-modal-body sba-revisions-modal-body">
            <!-- Modal Notice Banner -->
            <div id="sba-revisions-modal-notice" class="notice notice-success inline" style="display: none; margin: 0 0 16px 0;"></div>

            <!-- Top Summary & Storage Metric Cards -->
            <div class="sba-revisions-summary-card">
                <div class="sba-rev-summary-col">
                    <span class="sba-rev-summary-label"><?php esc_html_e( 'Total Revisions', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-rev-summary-val" id="sba-modal-rev-count">0</span>
                </div>
                <div class="sba-rev-summary-divider"></div>
                <div class="sba-rev-summary-col">
                    <span class="sba-rev-summary-label"><?php esc_html_e( 'Memory in Use', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-rev-summary-val sba-text-storage" id="sba-modal-rev-bytes">0 bytes (0 KB)</span>
                </div>
                <div class="sba-rev-summary-divider"></div>
                <div class="sba-rev-summary-col">
                    <span class="sba-rev-summary-label"><?php esc_html_e( 'Memory Freed When Cleaned', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-rev-summary-val sba-text-release" id="sba-modal-rev-releasable">0 bytes (0 KB)</span>
                </div>
            </div>

            <!-- Toolbar with Bulk Actions -->
            <div class="sba-revisions-toolbar">
                <div class="sba-rev-toolbar-left">
                    <label class="sba-select-all-label">
                        <input type="checkbox" id="sba-rev-select-all" />
                        <span><?php esc_html_e( 'Select All', 'site-bloat-auditor' ); ?></span>
                    </label>
                    <button type="button" id="sba-rev-delete-selected-btn" class="button button-secondary button-small sba-btn-danger-outline" disabled>
                        <span class="dashicons dashicons-trash"></span>
                        <span class="sba-btn-text"><?php esc_html_e( 'Delete Selected', 'site-bloat-auditor' ); ?></span>
                    </button>
                </div>
                <div class="sba-rev-toolbar-right">
                    <button type="button" id="sba-rev-delete-all-btn" class="button button-small button-link-delete">
                        <span class="dashicons dashicons-trash"></span>
                        <span><?php esc_html_e( 'Delete All Revisions', 'site-bloat-auditor' ); ?></span>
                    </button>
                </div>
            </div>

            <!-- Loading Spinner -->
            <div id="sba-revisions-loading" class="sba-modal-loading" style="display: none;">
                <span class="dashicons dashicons-update sba-rotating"></span>
                <span><?php esc_html_e( 'Loading post revisions and calculating storage bytes...', 'site-bloat-auditor' ); ?></span>
            </div>

            <!-- Revisions Table Container -->
            <div id="sba-revisions-table-container" class="sba-revisions-table-wrap">
                <table class="wp-list-table widefat fixed striped sba-revisions-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 38px;" class="check-column"></th>
                            <th scope="col" style="width: 34%;"><?php esc_html_e( 'Parent Post & Revision', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 25%;"><?php esc_html_e( 'Modified Date & Author', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 23%;"><?php esc_html_e( 'Memory Used', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 15%; text-align: right;"><?php esc_html_e( 'Action', 'site-bloat-auditor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="sba-revisions-tbody">
                        <!-- Populated dynamically via JavaScript -->
                    </tbody>
                </table>
            </div>

            <!-- Empty State -->
            <div id="sba-revisions-empty" class="sba-empty-state" style="display: none; padding: 36px 20px;">
                <span class="dashicons dashicons-yes-alt" style="font-size: 42px; width: 42px; height: 42px; color: #16a34a;"></span>
                <p class="sba-empty-title" style="font-size: 16px; font-weight: 600; margin-top: 10px;"><?php esc_html_e( 'No post revisions found!', 'site-bloat-auditor' ); ?></p>
                <p class="description"><?php esc_html_e( 'All old post revision bloat has been cleared and database storage is optimal.', 'site-bloat-auditor' ); ?></p>
            </div>
        </div>

        <div class="sba-modal-footer">
            <button type="button" class="button button-large sba-modal-cancel sba-modal-revisions-close">
                <?php esc_html_e( 'Close', 'site-bloat-auditor' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Confirmation & Progress for Bulk Media Actions -->
<div id="sba-media-bulk-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-bulk-modal-title">
    <div class="sba-modal-dialog sba-modal-dialog-large">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-bulk-modal-title">
                <span class="dashicons dashicons-admin-generic sba-modal-icon-info" id="sba-bulk-modal-icon"></span>
                <span id="sba-bulk-modal-action-title"><?php esc_html_e( 'Bulk Action Confirmation', 'site-bloat-auditor' ); ?></span>
            </div>
            <button type="button" class="sba-modal-close sba-bulk-modal-cancel" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>

        <div class="sba-modal-body">
            <!-- Modal Lead Description -->
            <p id="sba-bulk-modal-desc" class="sba-cleanup-lead-text"><?php esc_html_e( 'Review the selected files and details below before proceeding.', 'site-bloat-auditor' ); ?></p>

            <!-- Key Metric Cards Grid -->
            <div class="sba-cleanup-confirm-grid" style="margin-bottom: 18px;">
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Total Selected:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-bulk-modal-selected-count">0 files</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Eligible for Action:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value sba-text-release" id="sba-bulk-modal-eligible-count">0 files</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Skipped / Ineligible:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value" id="sba-bulk-modal-skipped-count" style="color: #64748b;">0 files</strong>
                </div>
                <div class="sba-confirm-row" id="sba-bulk-modal-savings-row">
                    <span class="sba-confirm-label" id="sba-bulk-modal-savings-label"><?php esc_html_e( 'Estimated Savings:', 'site-bloat-auditor' ); ?></span>
                    <strong class="sba-confirm-value sba-text-release" id="sba-bulk-modal-savings-value">0 KB</strong>
                </div>
                <div class="sba-confirm-row">
                    <span class="sba-confirm-label"><?php esc_html_e( 'Safety & Backup:', 'site-bloat-auditor' ); ?></span>
                    <span class="sba-confirm-desc" id="sba-bulk-modal-safety-note"><?php esc_html_e( 'Verified backups will be preserved.', 'site-bloat-auditor' ); ?></span>
                </div>
            </div>

            <!-- Ineligible reasons notice (if any skipped) -->
            <div id="sba-bulk-modal-skipped-notice" class="notice notice-warning inline" style="display: none; margin: 0 0 16px 0; padding: 8px 12px; font-size: 12.5px;">
                <span class="dashicons dashicons-info" style="color: #d97706; vertical-align: middle; margin-right: 4px;"></span>
                <span id="sba-bulk-modal-skipped-reasons"></span>
            </div>

            <!-- Explicit Confirmation Checkbox for Deletion -->
            <div id="sba-bulk-modal-delete-confirm-wrap" class="sba-modal-safety-notice" style="display: none; background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; margin-bottom: 16px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600;">
                    <input type="checkbox" id="sba-bulk-modal-delete-ack" style="margin-top: 2px;" />
                    <span><?php esc_html_e( 'I understand that these files will be permanently deleted from the server and this action cannot be undone.', 'site-bloat-auditor' ); ?></span>
                </label>
            </div>

            <!-- Scrollable File List Container -->
            <div style="margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-weight: 600; font-size: 13px; color: #334155;"><?php esc_html_e( 'Selected Files:', 'site-bloat-auditor' ); ?></span>
                <span id="sba-bulk-modal-file-list-summary" style="font-size: 12px; color: #64748b;"></span>
            </div>
            <div id="sba-bulk-modal-file-list" class="sba-file-list-box" style="max-height: 180px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; background: #f8fafc; padding: 8px 12px; margin-bottom: 16px;">
                <!-- Populated dynamically -->
            </div>

            <!-- Live Progress Bar (Visible during processing) -->
            <div id="sba-bulk-modal-progress" style="display: none; margin-top: 16px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px;">
                    <span id="sba-bulk-progress-status" style="font-weight: 600; color: #1e293b;">
                        <span class="dashicons dashicons-update sba-rotating" style="vertical-align: middle; margin-right: 4px;"></span>
                        <span id="sba-bulk-progress-text"><?php esc_html_e( 'Processing...', 'site-bloat-auditor' ); ?></span>
                    </span>
                    <span id="sba-bulk-progress-percent" style="font-weight: 700; color: #2563eb;">0%</span>
                </div>
                <div class="sba-progress-bar-bg" style="background: #e2e8f0; border-radius: 4px; height: 10px; overflow: hidden;">
                    <div id="sba-bulk-progress-bar-fill" class="sba-progress-bar-fill" style="width: 0%; height: 100%; background: #2563eb; transition: width 0.25s ease;"></div>
                </div>
            </div>

            <!-- Execution Results Banner -->
            <div id="sba-bulk-modal-result-banner" style="display: none; margin-top: 16px;" class="notice notice-success inline">
                <p id="sba-bulk-modal-result-msg" style="margin: 0.5em 0; font-weight: 600;"></p>
                <div id="sba-bulk-modal-result-errors" style="display: none; font-size: 12px; color: #b91c1c; margin-top: 4px;"></div>
            </div>
        </div>

        <div class="sba-modal-footer">
            <button type="button" class="button button-large sba-bulk-modal-cancel" id="sba-bulk-modal-btn-cancel">
                <?php esc_html_e( 'Cancel', 'site-bloat-auditor' ); ?>
            </button>
            <button type="button" class="button button-large button-primary sba-bulk-modal-btn-proceed" id="sba-bulk-modal-btn-proceed">
                <span class="dashicons dashicons-yes-alt" id="sba-bulk-modal-proceed-icon"></span>
                <span id="sba-bulk-modal-proceed-label"><?php esc_html_e( 'Proceed', 'site-bloat-auditor' ); ?></span>
            </button>
            <button type="button" class="button button-large button-secondary sba-bulk-modal-cancel" id="sba-bulk-modal-btn-done" style="display: none;">
                <?php esc_html_e( 'Close', 'site-bloat-auditor' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Confirmation for Safe Plugin Deletion -->
<div id="sba-plugin-delete-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-plugin-delete-modal-title">
    <div class="sba-modal-dialog sba-modal-dialog-large">
        <div class="sba-modal-header sba-modal-header-danger">
            <h3 id="sba-plugin-delete-modal-title" style="margin: 0; display: flex; align-items: center; gap: 8px; color: #b91c1c;">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Confirm Safe Plugin Deletion', 'site-bloat-auditor' ); ?>
            </h3>
            <button type="button" class="sba-modal-close sba-plugin-modal-close" aria-label="<?php esc_attr_e( 'Close dialog', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <div class="sba-callout sba-callout-warning" style="margin-bottom: 14px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Permanent File Removal & Deactivation Notice', 'site-bloat-auditor' ); ?></strong>
                    <p style="margin: 4px 0 0; font-size: 13px; line-height: 1.45;"><?php esc_html_e( 'Deleting a plugin permanently removes its directory and code files from the server. If a plugin is currently active, it will be safely deactivated first. This action cannot be undone.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <div class="sba-callout sba-callout-info" style="margin-bottom: 14px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Database Safety Note', 'site-bloat-auditor' ); ?></strong>
                    <p style="margin: 4px 0 0; font-size: 13px; line-height: 1.45;"><?php esc_html_e( 'To prevent accidental data loss, plugin-specific database tables and settings in wp_options are NOT deleted automatically. Any remaining tables can be reviewed and cleaned separately in the Database Audit tab.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <div class="sba-callout sba-callout-backup" style="margin-bottom: 14px;">
                <div class="sba-callout-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="sba-callout-body">
                    <strong><?php esc_html_e( 'Backup Recommendation', 'site-bloat-auditor' ); ?></strong>
                    <p style="margin: 4px 0 0; font-size: 13px; line-height: 1.45;"><?php esc_html_e( 'We strongly recommend creating a verified site and database backup and confirming a recent restore point before deleting plugins.', 'site-bloat-auditor' ); ?></p>
                </div>
            </div>

            <h4 style="margin: 16px 0 6px; font-size: 13.5px;"><?php esc_html_e( 'Selected Plugins for Deletion:', 'site-bloat-auditor' ); ?></h4>
            <div id="sba-plugin-delete-summary" style="font-size: 12.5px; font-weight: 600; color: #475569; margin-bottom: 8px;"></div>
            <div id="sba-plugin-delete-list" class="sba-delete-plugin-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; background: #f8fafc; margin-bottom: 14px;">
                <!-- Populated dynamically by JavaScript -->
            </div>

            <div class="sba-confirmation-checkbox-wrap" style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px;">
                <label style="display: flex; align-items: flex-start; gap: 8px; font-weight: 600; color: #b91c1c; cursor: pointer;">
                    <input type="checkbox" id="sba-plugin-delete-confirm-cb" style="margin-top: 3px;" />
                    <span><?php esc_html_e( 'I understand this will permanently delete the selected plugin files from the server disk and cannot be undone.', 'site-bloat-auditor' ); ?></span>
                </label>
            </div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-secondary sba-plugin-modal-close"><?php esc_html_e( 'Cancel', 'site-bloat-auditor' ); ?></button>
            <button type="button" id="sba-plugin-btn-confirm-delete" class="button button-danger sba-btn-danger" disabled>
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Permanently Delete Selected Plugins', 'site-bloat-auditor' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Plugin Deletion Progress -->
<div id="sba-plugin-progress-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-plugin-progress-title">
    <div class="sba-modal-dialog">
        <div class="sba-modal-header">
            <h3 id="sba-plugin-progress-title" style="margin: 0; display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-update sba-rotating"></span>
                <span id="sba-plugin-progress-title-text"><?php esc_html_e( 'Processing Plugins...', 'site-bloat-auditor' ); ?></span>
            </h3>
        </div>
        <div class="sba-modal-body">
            <p id="sba-plugin-progress-status" style="font-weight: 600; margin-bottom: 10px;"><?php esc_html_e( 'Preparing operation...', 'site-bloat-auditor' ); ?></p>
            <div class="sba-progress-bar-wrap" style="background: #e2e8f0; border-radius: 6px; height: 12px; overflow: hidden; margin-bottom: 12px;">
                <div id="sba-plugin-progress-bar" style="background: #ef4444; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
            </div>
            <div id="sba-plugin-progress-log" style="font-size: 12px; max-height: 140px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px; font-family: monospace;"></div>
        </div>
    </div>
</div>

<!-- Modal: Plugin Deactivation Confirmation -->
<div id="sba-plugin-deactivate-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-plugin-deactivate-title">
    <div class="sba-modal-dialog">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-plugin-deactivate-title">
                <span class="dashicons dashicons-warning sba-modal-icon-warning"></span>
                <?php esc_html_e( 'Confirm Plugin Deactivation', 'site-bloat-auditor' ); ?>
            </div>
            <button type="button" class="sba-modal-close sba-plugin-deactivate-modal-close" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <p><?php esc_html_e( 'Are you sure you want to deactivate the following plugin(s)? Deactivating stops their execution on your site. Their files and database settings will remain completely intact on disk.', 'site-bloat-auditor' ); ?></p>
            <div id="sba-plugin-deactivate-list" class="sba-modal-plugin-list" style="max-height: 160px; overflow-y: auto; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-bottom: 12px;"></div>
            <div id="sba-plugin-deactivate-summary" style="font-size: 12px; color: #64748b;"></div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-secondary sba-plugin-deactivate-modal-close"><?php esc_html_e( 'Cancel', 'site-bloat-auditor' ); ?></button>
            <button type="button" id="sba-plugin-btn-confirm-deactivate" class="button button-primary">
                <span class="dashicons dashicons-dismiss"></span>
                <?php esc_html_e( 'Deactivate Selected Plugins', 'site-bloat-auditor' ); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Media References — which content uses an attachment (filled by admin.js) -->
<div id="sba-media-refs-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-media-refs-heading">
    <div class="sba-modal-dialog sba-modal-dialog-large sba-media-refs-dialog">
        <div class="sba-modal-header">
            <div class="sba-modal-title">
                <span class="dashicons dashicons-admin-links sba-modal-icon-info" aria-hidden="true"></span>
                <span id="sba-media-refs-heading"><?php esc_html_e( 'Resource References', 'site-bloat-auditor' ); ?></span>
            </div>
            <button type="button" class="sba-modal-close sba-media-refs-close" aria-label="<?php esc_attr_e( 'Close dialog', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body sba-media-refs-body" id="sba-media-refs-body"></div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-large sba-media-refs-close"><?php esc_html_e( 'Close', 'site-bloat-auditor' ); ?></button>
        </div>
    </div>
</div>

<!-- Modal: Plugin Usage Evidence & Inspection Panel -->
<div id="sba-plugin-evidence-modal" class="sba-modal-overlay" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="sba-plugin-evidence-title">
    <div class="sba-modal-dialog sba-modal-dialog-large" style="max-width: 680px; width: 92%;">
        <div class="sba-modal-header">
            <div class="sba-modal-title" id="sba-plugin-evidence-title" style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-search sba-modal-icon-info" style="color: #0284c7;"></span>
                <?php esc_html_e( 'Plugin Usage Audit Details', 'site-bloat-auditor' ); ?>
            </div>
            <button type="button" class="sba-modal-close sba-plugin-evidence-modal-close" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <!-- Header Banner -->
            <div class="sba-evidence-meta-banner" style="display: flex; justify-content: space-between; align-items: flex-start; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 14px; margin-bottom: 14px;">
                <div>
                    <h3 id="sba-evidence-modal-plugin-name" style="margin: 0 0 4px 0; font-size: 16px; color: #0f172a;"></h3>
                    <div style="font-size: 11px; font-family: monospace; color: #64748b;" id="sba-evidence-modal-plugin-file"></div>
                </div>
                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                    <div id="sba-evidence-modal-active-badge"></div>
                    <div id="sba-evidence-modal-usage-badge"></div>
                </div>
            </div>

            <!-- Structured Grid -->
            <div class="sba-evidence-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                <!-- 1. Checks Performed -->
                <div class="sba-evidence-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; display: flex; align-items: center; gap: 6px; color: #1e293b;">
                        <span class="dashicons dashicons-clipboard" style="font-size: 16px; width: 16px; height: 16px; color: #64748b;"></span>
                        <?php esc_html_e( 'Checks Performed', 'site-bloat-auditor' ); ?>
                    </h4>
                    <ul id="sba-evidence-checks-list" style="margin: 0; padding-left: 18px; font-size: 11.5px; color: #475569; line-height: 1.45;"></ul>
                </div>

                <!-- 2. Evidence Found -->
                <div class="sba-evidence-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; display: flex; align-items: center; gap: 6px; color: #16a34a;">
                        <span class="dashicons dashicons-yes-alt" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php esc_html_e( 'Positive Evidence Found', 'site-bloat-auditor' ); ?>
                    </h4>
                    <ul id="sba-evidence-found-list" style="margin: 0; padding-left: 18px; font-size: 11.5px; color: #166534; line-height: 1.45;"></ul>
                </div>

                <!-- 3. Evidence Not Found -->
                <div class="sba-evidence-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; display: flex; align-items: center; gap: 6px; color: #64748b;">
                        <span class="dashicons dashicons-dismiss" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php esc_html_e( 'Evidence Not Found', 'site-bloat-auditor' ); ?>
                    </h4>
                    <ul id="sba-evidence-not-found-list" style="margin: 0; padding-left: 18px; font-size: 11.5px; color: #64748b; line-height: 1.45;"></ul>
                </div>

                <!-- 4. Scan Limitations -->
                <div class="sba-evidence-card" style="background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 13px; display: flex; align-items: center; gap: 6px; color: #0284c7;">
                        <span class="dashicons dashicons-info-outline" style="font-size: 16px; width: 16px; height: 16px;"></span>
                        <?php esc_html_e( 'Scan Limitations', 'site-bloat-auditor' ); ?>
                    </h4>
                    <p id="sba-evidence-limitations-text" style="margin: 0; font-size: 11.5px; color: #475569; line-height: 1.45;"></p>
                </div>
            </div>

            <!-- 5. Confidence & Recommended Action -->
            <div class="sba-evidence-action-box" style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <strong style="color: #0f172a; font-size: 13px;"><?php esc_html_e( 'Recommended Action', 'site-bloat-auditor' ); ?></strong>
                    <span style="font-size: 12px; color: #64748b;">
                        <?php esc_html_e( 'Confidence: ', 'site-bloat-auditor' ); ?>
                        <strong id="sba-evidence-confidence-level" style="color: #0f172a;"></strong>
                    </span>
                </div>
                <p id="sba-evidence-recommended-action" style="margin: 0; font-size: 12.5px; color: #334155; line-height: 1.45;"></p>
            </div>
        </div>
        <div class="sba-modal-footer">
            <button type="button" class="button button-secondary sba-plugin-evidence-modal-close"><?php esc_html_e( 'Close', 'site-bloat-auditor' ); ?></button>
        </div>
    </div>
</div>
