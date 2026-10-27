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

// Pre-calculate initial revisions statistics
$sba_initial_revisions = function_exists( 'sba_get_revisions_storage_stats' ) ? sba_get_revisions_storage_stats() : [
    'total_count'      => 0,
    'total_bytes'      => 0,
    'total_kb'         => 0,
    'releasable_bytes' => 0,
    'releasable_kb'    => 0,
];
$sba_initial_rev_count       = (int) ( $sba_initial_revisions['total_count'] ?? 0 );
$sba_initial_rev_bytes       = (int) ( $sba_initial_revisions['total_bytes'] ?? 0 );
$sba_initial_rev_releasable  = (int) ( $sba_initial_revisions['releasable_bytes'] ?? $sba_initial_rev_bytes );
$sba_initial_rev_size_fmt    = size_format( $sba_initial_rev_bytes, 2 ) . ' (' . number_format_i18n( $sba_initial_rev_bytes ) . ' bytes)';
$sba_initial_rev_release_fmt = size_format( $sba_initial_rev_releasable, 2 ) . ' (' . number_format_i18n( $sba_initial_rev_releasable ) . ' bytes)';
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
            <!-- KPI Cards Grid -->
            <div class="sba-cards-grid">
                <div class="sba-card">
                    <div class="sba-card-icon sba-icon-autoload">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <span class="sba-card-title"><?php esc_html_e( 'Autoload Size', 'site-bloat-auditor' ); ?></span>
                        </div>
                        <div class="sba-card-value" id="sba-stat-autoload-size">0 KB</div>
                        <div class="sba-card-meta" id="sba-stat-autoload-count">0 rows</div>
                    </div>
                </div>

                <div class="sba-card sba-card-revisions">
                    <div class="sba-card-icon sba-icon-revisions">
                        <span class="dashicons dashicons-backup"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <span class="sba-card-title"><?php esc_html_e( 'Post Revisions', 'site-bloat-auditor' ); ?></span>
                            <button type="button" id="sba-clean-revisions-btn" class="button button-small sba-kpi-btn-action" title="<?php esc_attr_e( 'Safely clean old revisions beyond the last 3 edits', 'site-bloat-auditor' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="sba-action-label"><?php esc_html_e( 'Clean', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-value">
                            <button type="button" class="sba-stat-revisions-clickable" id="sba-stat-revisions-trigger" title="<?php esc_attr_e( 'Click to view all revisions in Site Bloat Auditor', 'site-bloat-auditor' ); ?>">
                                <span id="sba-stat-revisions"><?php echo esc_html( number_format_i18n( $sba_initial_rev_count ) ); ?></span>
                                <span class="sba-rev-click-hint"><?php esc_html_e( 'revisions (view list)', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-meta sba-rev-storage-meta">
                            <span class="sba-rev-size-text"><?php esc_html_e( 'Memory in use:', 'site-bloat-auditor' ); ?> <strong id="sba-stat-revisions-size"><?php echo esc_html( $sba_initial_rev_size_fmt ); ?></strong></span>
                        </div>
                        <div class="sba-card-meta sba-rev-release-meta">
                            <span class="sba-rev-free-text"><?php esc_html_e( 'Frees on clean:', 'site-bloat-auditor' ); ?> <strong id="sba-stat-revisions-releasable" style="color: #16a34a;"><?php echo esc_html( $sba_initial_rev_release_fmt ); ?></strong></span>
                        </div>
                    </div>
                </div>

                <div class="sba-card">
                    <div class="sba-card-icon sba-icon-transients">
                        <span class="dashicons dashicons-clock"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <span class="sba-card-title"><?php esc_html_e( 'Expired Transients', 'site-bloat-auditor' ); ?></span>
                            <button type="button" id="sba-clean-transients-btn" class="button button-small sba-kpi-btn-action" title="<?php esc_attr_e( 'Purge all expired transients from the database', 'site-bloat-auditor' ); ?>">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="sba-action-label"><?php esc_html_e( 'Clear', 'site-bloat-auditor' ); ?></span>
                            </button>
                        </div>
                        <div class="sba-card-value" id="sba-stat-transients">0</div>
                        <div class="sba-card-meta"><?php esc_html_e( 'Outdated transient rows', 'site-bloat-auditor' ); ?></div>
                    </div>
                </div>

                <div class="sba-card">
                    <div class="sba-card-icon sba-icon-tables">
                        <span class="dashicons dashicons-list-view"></span>
                    </div>
                    <div class="sba-card-content">
                        <div class="sba-card-header-row">
                            <span class="sba-card-title"><?php esc_html_e( 'Total Tables', 'site-bloat-auditor' ); ?></span>
                        </div>
                        <div class="sba-card-value" id="sba-stat-total-tables">0</div>
                        <div class="sba-card-meta" id="sba-stat-tables-meta">0 Core &bull; 0 Active &bull; 0 Orphaned</div>
                    </div>
                </div>
            </div>

            <!-- Tables Section -->
            <div class="sba-section-panel">
                <div class="sba-panel-header">
                    <div>
                        <h2><?php esc_html_e( 'Database Table Health & Orphaned Detector', 'site-bloat-auditor' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Filter and inspect WordPress Core tables, tables owned by active plugins, and leftover orphaned tables.', 'site-bloat-auditor' ); ?></p>
                    </div>
                    <div class="sba-search-box">
                        <input type="text" id="sba-table-search" placeholder="<?php esc_attr_e( 'Search tables...', 'site-bloat-auditor' ); ?>" class="regular-text" />
                    </div>
                </div>

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
                        <span class="dashicons dashicons-yes-alt"></span>
                        <p class="sba-empty-title"><?php esc_html_e( 'No tables match the current filter', 'site-bloat-auditor' ); ?></p>
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

        <!-- 4 Summary Metric Cards -->
        <div class="sba-cards-grid" style="margin-top: 20px;">
            <div class="sba-card sba-card-clickable" id="sba-card-media-total" title="<?php esc_attr_e( 'Click to view all media attachments', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-size">
                    <span class="dashicons dashicons-format-image"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Total Media Size', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-size">0.00 MB</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'wp-content/uploads directory', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-clickable" id="sba-card-media-unused" title="<?php esc_attr_e( 'Click to view media with no detected references', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-unused">
                    <span class="dashicons dashicons-images-alt2"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Unreferenced Media', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-unused">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'No references found in scanned sources', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card sba-card-clickable" id="sba-card-media-oversized" title="<?php esc_attr_e( 'Click to view oversized images needing resize', 'site-bloat-auditor' ); ?>">
                <div class="sba-card-icon sba-icon-media-oversized">
                    <span class="dashicons dashicons-image-rotate"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Oversized Images', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-oversized">0</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Optimization opportunities', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card" id="sba-card-media-savings">
                <div class="sba-card-icon sba-icon-media-savings">
                    <span class="dashicons dashicons-trash"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Potentially Reclaimable', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-media-savings">0.00 MB</div>
                    <div class="sba-card-meta" id="sba-stat-media-savings-meta"><?php esc_html_e( 'Unreferenced + resize potential', 'site-bloat-auditor' ); ?></div>
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

            <!-- Media Table Container -->
            <div id="sba-media-table-container" class="sba-table-container" style="display: none;">
                <table class="wp-list-table widefat fixed striped sba-data-table">
                    <thead>
                        <tr>
                            <th scope="col" class="sba-quarantine-cb-col" style="width: 38px; text-align: center; vertical-align: middle; display: none;">
                                <input type="checkbox" id="sba-quarantine-select-all" aria-label="<?php esc_attr_e( 'Select All Quarantined Items', 'site-bloat-auditor' ); ?>" title="<?php esc_attr_e( 'Select All Quarantined Items', 'site-bloat-auditor' ); ?>" />
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

        <!-- 4 Summary KPI Metric Cards -->
        <div class="sba-cards-grid" style="margin-top: 20px;">
            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-size">
                    <span class="dashicons dashicons-database"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Total Plugins Size', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-plugins-total-size">0.00 MB</div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Disk usage across all plugins', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-total">
                    <span class="dashicons dashicons-admin-plugins"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Total Plugins Installed', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-total-plugins">0</div>
                    <div class="sba-card-meta" id="sba-stat-active-inactive-plugins"><?php esc_html_e( '0 Active • 0 Inactive', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-unverified">
                    <span class="dashicons dashicons-shield-alt"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Unverified / Third-Party', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" style="display: flex; align-items: center; gap: 6px;">
                        <span id="sba-stat-unverified-plugins">0</span>
                        <span id="sba-stat-unverified-badge" class="sba-badge-pill sba-pill-unverified" style="display: none; font-size: 10px; padding: 2px 6px;"><?php esc_html_e( 'Attention', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-meta"><?php esc_html_e( 'Not in official repository', 'site-bloat-auditor' ); ?></div>
                </div>
            </div>

            <div class="sba-card">
                <div class="sba-card-icon sba-icon-plugins-risks">
                    <span class="dashicons dashicons-flag"></span>
                </div>
                <div class="sba-card-content">
                    <div class="sba-card-header-row">
                        <span class="sba-card-title"><?php esc_html_e( 'Security Risk Flagged', 'site-bloat-auditor' ); ?></span>
                    </div>
                    <div class="sba-card-value" id="sba-stat-risks-total">0</div>
                    <div class="sba-card-meta" id="sba-stat-risks-breakdown"><?php esc_html_e( '0 High • 0 Medium Risk', 'site-bloat-auditor' ); ?></div>
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
                    <p class="description"><?php esc_html_e( 'Review status, on-disk folder size, repository verification, and risk assessment for every plugin.', 'site-bloat-auditor' ); ?></p>
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
                <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-plugin-tab-btn" data-tab="dependencies">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'Missing Dependencies', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-orange" id="sba-plugin-count-dependencies">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-warning sba-plugin-tab-btn" data-tab="unverified">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e( 'High Risk / Unverified', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-red" id="sba-plugin-count-unverified">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-plugin-tab-btn" data-tab="outdated">
                    <span class="dashicons dashicons-clock"></span>
                    <?php esc_html_e( 'Outdated (2+ years)', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-orange" id="sba-plugin-count-outdated">0</span>
                </button>
                <button type="button" class="sba-tab-btn sba-tab-btn-inactive sba-plugin-tab-btn" data-tab="inactive">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e( 'Inactive Bloat', 'site-bloat-auditor' ); ?>
                    <span class="sba-tab-count sba-badge-orange" id="sba-plugin-count-inactive">0</span>
                </button>
            </div>

            <!-- Plugins List Table -->
            <div class="sba-table-container">
                <table class="wp-list-table widefat fixed striped sba-data-table">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 26%;"><?php esc_html_e( 'Plugin & Author', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 10%;"><?php esc_html_e( 'Status', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 24%;"><?php esc_html_e( 'Repo Verification', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 16%;"><?php esc_html_e( 'Disk & DB Footprint', 'site-bloat-auditor' ); ?></th>
                            <th scope="col" style="width: 24%; text-align: right;"><?php esc_html_e( 'Risk & Action', 'site-bloat-auditor' ); ?></th>
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
        </div>
    </div>
</div>

<!-- Modal: Confirmation for Dropping Orphaned Table -->
<div id="sba-drop-modal" class="sba-modal-overlay" style="display: none;">
    <div class="sba-modal-dialog">
        <div class="sba-modal-header">
            <div class="sba-modal-title">
                <span class="dashicons dashicons-warning sba-modal-icon-danger"></span>
                <?php esc_html_e( 'Drop Orphaned Table', 'site-bloat-auditor' ); ?>
            </div>
            <button type="button" class="sba-modal-close" aria-label="<?php esc_attr_e( 'Close', 'site-bloat-auditor' ); ?>">&times;</button>
        </div>
        <div class="sba-modal-body">
            <p><?php esc_html_e( 'Are you sure you want to permanently drop the following database table?', 'site-bloat-auditor' ); ?></p>
            <div class="sba-table-drop-preview">
                <code id="sba-modal-table-name">wp_table_name</code>
                <div class="sba-table-drop-meta" id="sba-modal-table-meta">0 rows &bull; 0 KB</div>
                <div class="sba-table-drop-plugin" id="sba-modal-table-plugin"></div>
            </div>

            <!-- Relational Protection Safety Notice -->
            <div class="sba-modal-safety-notice">
                <span class="dashicons dashicons-shield"></span>
                <span><?php esc_html_e( 'WordPress Core and active plugin tables are permanently protected. Only orphaned tables from deleted plugins can be removed.', 'site-bloat-auditor' ); ?></span>
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
