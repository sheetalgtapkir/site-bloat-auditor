<?php
/**
 * Plugin Name:       Site Bloat & Storage Auditor
 * Plugin URI:        https://github.com/sheetalgtapkir/site-bloat-auditor
 * Description:       Audit orphaned database tables, unused media, and plugin storage bloat with safe backups and quarantine isolation.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            sheetal2311
 * Author URI:        https://profiles.wordpress.org/sheetal2311/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       site-bloat-auditor
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'SBA_VERSION', '1.1.0' );
define( 'SBA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Load Modular Phase 1 Audit Engines
require_once SBA_PLUGIN_DIR . 'includes/class-sba-usage-detector.php';
require_once SBA_PLUGIN_DIR . 'includes/class-sba-media-inventory.php';
require_once SBA_PLUGIN_DIR . 'includes/class-sba-duplicate-detector.php';
require_once SBA_PLUGIN_DIR . 'includes/class-sba-accessibility-auditor.php';
require_once SBA_PLUGIN_DIR . 'includes/class-sba-image-optimizer.php';

/**
 * Load plugin textdomain for internationalization.
 */
add_action( 'init', 'sba_load_textdomain' );
function sba_load_textdomain() {
    load_plugin_textdomain( 'site-bloat-auditor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/**
 * 1. Register the Admin Menu Page
 */
add_action( 'admin_menu', 'sba_register_admin_menu' );
function sba_register_admin_menu() {
    add_management_page(
        __( 'Site Bloat Auditor', 'site-bloat-auditor' ),
        __( 'Site Bloat Auditor', 'site-bloat-auditor' ),
        'manage_options',
        'site-bloat-auditor',
        'sba_render_admin_dashboard'
    );
}

/**
 * 2. Enqueue Admin Scripts and Styles
 *
 * Enqueues CSS and JavaScript strictly on the plugin's tools page per WP.org Guideline #7.
 *
 * @param string $hook_suffix The current admin page hook.
 */
add_action( 'admin_enqueue_scripts', 'sba_admin_enqueue_scripts' );
function sba_admin_enqueue_scripts( $hook_suffix ) {
    // Only load assets on our plugin's Tools page (tools_page_site-bloat-auditor).
    if ( 'tools_page_site-bloat-auditor' !== $hook_suffix ) {
        return;
    }

    // Enqueue Admin Stylesheet
    wp_enqueue_style(
        'sba-admin-css',
        SBA_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        SBA_VERSION,
        'all'
    );

    // Enqueue Admin JavaScript
    wp_enqueue_script(
        'sba-admin-js',
        SBA_PLUGIN_URL . 'assets/js/admin.js',
        array( 'jquery' ),
        SBA_VERSION,
        true // Load in footer
    );

    // Dynamic Server Data & Localization Dictionary
    $localized_data = array(
        'ajax_url'          => admin_url( 'admin-ajax.php' ),
        'nonce'             => wp_create_nonce( 'sba_nonce' ),
        'admin_plugins_url' => admin_url( 'plugins.php' ),
        'quarantined_files' => array_values( (array) get_option( 'sba_quarantined_files', array() ) ),
        'i18n'              => sba_get_admin_js_translations(),
    );

    wp_localize_script( 'sba-admin-js', 'sbaData', $localized_data );
}

/**
 * Localized UI String Translations for Admin JavaScript
 *
 * @return array Key-value map of translated strings for JS consumption.
 */
function sba_get_admin_js_translations() {
    return array(
        'Unknown / Custom Table'                                                                           => __( 'Unknown / Custom Table', 'site-bloat-auditor' ),
        'CORE'                                                                                             => __( 'CORE', 'site-bloat-auditor' ),
        'WordPress Core Table'                                                                             => __( 'WordPress Core Table', 'site-bloat-auditor' ),
        'Protected'                                                                                        => __( 'Protected', 'site-bloat-auditor' ),
        'ACTIVE PLUGIN'                                                                                    => __( 'ACTIVE PLUGIN', 'site-bloat-auditor' ),
        'Belongs to: '                                                                                     => __( 'Belongs to: ', 'site-bloat-auditor' ),
        'Plugin is currently active'                                                                       => __( 'Plugin is currently active', 'site-bloat-auditor' ),
        'Active'                                                                                           => __( 'Active', 'site-bloat-auditor' ),
        'ORPHANED'                                                                                         => __( 'ORPHANED', 'site-bloat-auditor' ),
        'Plugin is deactivated or uninstalled from this site'                                              => __( 'Plugin is deactivated or uninstalled from this site', 'site-bloat-auditor' ),
        'Plugin is deleted/inactive'                                                                       => __( 'Plugin is deleted/inactive', 'site-bloat-auditor' ),
        'Drop Table'                                                                                       => __( 'Drop Table', 'site-bloat-auditor' ),
        'Auditing Database...'                                                                             => __( 'Auditing Database...', 'site-bloat-auditor' ),
        'Re-run DB Audit'                                                                                  => __( 'Re-run DB Audit', 'site-bloat-auditor' ),
        'Run Quick DB Audit'                                                                               => __( 'Run Quick DB Audit', 'site-bloat-auditor' ),
        'autoload rows'                                                                                    => __( 'autoload rows', 'site-bloat-auditor' ),
        'Scan failed.'                                                                                     => __( 'Scan failed.', 'site-bloat-auditor' ),
        'Server communication error while scanning.'                                                       => __( 'Server communication error while scanning.', 'site-bloat-auditor' ),
        'Clearing...'                                                                                      => __( 'Clearing...', 'site-bloat-auditor' ),
        'Expired transients purged successfully.'                                                          => __( 'Expired transients purged successfully.', 'site-bloat-auditor' ),
        'Failed to purge transients.'                                                                      => __( 'Failed to purge transients.', 'site-bloat-auditor' ),
        'Server error while purging expired transients.'                                                   => __( 'Server error while purging expired transients.', 'site-bloat-auditor' ),
        'Safely clean post revisions older than the last 3 edits per post? Recent 3 revisions will be preserved.' => __( 'Safely clean post revisions older than the last 3 edits per post? Recent 3 revisions will be preserved.', 'site-bloat-auditor' ),
        'Cleaning...'                                                                                      => __( 'Cleaning...', 'site-bloat-auditor' ),
        'Post revisions cleaned successfully.'                                                             => __( 'Post revisions cleaned successfully.', 'site-bloat-auditor' ),
        'Failed to clean revisions.'                                                                       => __( 'Failed to clean revisions.', 'site-bloat-auditor' ),
        'Server error while cleaning post revisions.'                                                      => __( 'Server error while cleaning post revisions.', 'site-bloat-auditor' ),
        'Loading revisions...'                                                                             => __( 'Loading revisions...', 'site-bloat-auditor' ),
        'Failed to load revisions.'                                                                        => __( 'Failed to load revisions.', 'site-bloat-auditor' ),
        'Server error while loading revisions.'                                                            => __( 'Server error while loading revisions.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete this revision? This action cannot be undone.'          => __( 'Are you sure you want to permanently delete this revision? This action cannot be undone.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete the %d selected revisions? This action cannot be undone.' => __( 'Are you sure you want to permanently delete the %d selected revisions? This action cannot be undone.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete ALL post revisions? This action cannot be undone.'    => __( 'Are you sure you want to permanently delete ALL post revisions? This action cannot be undone.', 'site-bloat-auditor' ),
        'Revision deleted successfully.'                                                                   => __( 'Revision deleted successfully.', 'site-bloat-auditor' ),
        'Failed to delete revision.'                                                                       => __( 'Failed to delete revision.', 'site-bloat-auditor' ),
        'Server error while deleting revision.'                                                            => __( 'Server error while deleting revision.', 'site-bloat-auditor' ),
        'Revisions deleted successfully.'                                                                  => __( 'Revisions deleted successfully.', 'site-bloat-auditor' ),
        'Failed to delete revisions.'                                                                      => __( 'Failed to delete revisions.', 'site-bloat-auditor' ),
        'Server error while deleting revisions.'                                                           => __( 'Server error while deleting revisions.', 'site-bloat-auditor' ),
        'revisions'                                                                                        => __( 'revisions', 'site-bloat-auditor' ),
        'revision'                                                                                         => __( 'revision', 'site-bloat-auditor' ),
        'bytes'                                                                                            => __( 'bytes', 'site-bloat-auditor' ),
        'Compressing Backup...'                                                                            => __( 'Compressing Backup...', 'site-bloat-auditor' ),
        'rows'                                                                                             => __( 'rows', 'site-bloat-auditor' ),
        'Permanently Drop Table'                                                                           => __( 'Permanently Drop Table', 'site-bloat-auditor' ),
        'Permanently Dropping...'                                                                          => __( 'Permanently Dropping...', 'site-bloat-auditor' ),
        'Downloading Backup (.zip)...'                                                                    => __( 'Downloading Backup (.zip)...', 'site-bloat-auditor' ),
        'Backup downloaded successfully: '                                                                 => __( 'Backup downloaded successfully: ', 'site-bloat-auditor' ),
        'Failed to export table backup.'                                                                   => __( 'Failed to export table backup.', 'site-bloat-auditor' ),
        'Server communication error while exporting backup.'                                               => __( 'Server communication error while exporting backup.', 'site-bloat-auditor' ),
        'Table dropped successfully: '                                                                     => __( 'Table dropped successfully: ', 'site-bloat-auditor' ),
        'Failed to drop table.'                                                                            => __( 'Failed to drop table.', 'site-bloat-auditor' ),
        'Server communication error while dropping table.'                                                 => __( 'Server communication error while dropping table.', 'site-bloat-auditor' ),
        'Scanning Media Library...'                                                                        => __( 'Scanning Media Library...', 'site-bloat-auditor' ),
        'Scan Media Files'                                                                                 => __( 'Scan Media Files', 'site-bloat-auditor' ),
        'Re-scan Media'                                                                                    => __( 'Re-scan Media', 'site-bloat-auditor' ),
        'Failed to scan media.'                                                                            => __( 'Failed to scan media.', 'site-bloat-auditor' ),
        'Server communication error while scanning media.'                                                 => __( 'Server communication error while scanning media.', 'site-bloat-auditor' ),
        'Quarantining...'                                                                                  => __( 'Quarantining...', 'site-bloat-auditor' ),
        'Restoring...'                                                                                     => __( 'Restoring...', 'site-bloat-auditor' ),
        'Moving unlinked media and all thumbnail sizes to quarantine directory...'                         => __( 'Moving unlinked media and all thumbnail sizes to quarantine directory...', 'site-bloat-auditor' ),
        'Emptying Quarantine Trash...'                                                                     => __( 'Emptying Quarantine Trash...', 'site-bloat-auditor' ),
        'Failed to empty quarantine.'                                                                      => __( 'Failed to empty quarantine.', 'site-bloat-auditor' ),
        'Failed to quarantine file.'                                                                       => __( 'Failed to quarantine file.', 'site-bloat-auditor' ),
        'Failed to restore file.'                                                                          => __( 'Failed to restore file.', 'site-bloat-auditor' ),
        'Delete sub-sizes'                                                                                 => __( 'Delete sub-sizes', 'site-bloat-auditor' ),
        'Deleting...'                                                                                      => __( 'Deleting...', 'site-bloat-auditor' ),
        'Scanning Plugins...'                                                                              => __( 'Scanning Plugins...', 'site-bloat-auditor' ),
        'Run Plugin Audit'                                                                                 => __( 'Run Plugin Audit', 'site-bloat-auditor' ),
        'Re-run Plugin Audit'                                                                              => __( 'Re-run Plugin Audit', 'site-bloat-auditor' ),
        'Failed to scan plugins.'                                                                          => __( 'Failed to scan plugins.', 'site-bloat-auditor' ),
        'Server communication error while scanning plugins.'                                               => __( 'Server communication error while scanning plugins.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.' => __( 'Are you sure you want to permanently delete all quarantined media files? This action cannot be undone.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete the %d selected quarantined media file(s)? This action cannot be undone.' => __( 'Are you sure you want to permanently delete the %d selected quarantined media file(s)? This action cannot be undone.', 'site-bloat-auditor' ),
        'Are you sure you want to permanently delete "%s" from quarantine? This action cannot be undone.' => __( 'Are you sure you want to permanently delete "%s" from quarantine? This action cannot be undone.', 'site-bloat-auditor' ),
        'Are you sure you want to restore the %d selected media file(s) back to active Media Library?'     => __( 'Are you sure you want to restore the %d selected media file(s) back to active Media Library?', 'site-bloat-auditor' ),
        'Delete Selected Permanently'                                                                      => __( 'Delete Selected Permanently', 'site-bloat-auditor' ),
        'Empty Quarantine Permanently'                                                                     => __( 'Empty Quarantine Permanently', 'site-bloat-auditor' ),
        'Empty All Quarantine'                                                                             => __( 'Empty All Quarantine', 'site-bloat-auditor' ),
        'Restore Selected'                                                                                 => __( 'Restore Selected', 'site-bloat-auditor' ),
        'Selected items successfully restored to Media Library.'                                           => __( 'Selected items successfully restored to Media Library.', 'site-bloat-auditor' ),
        'Failed to restore selected items.'                                                                => __( 'Failed to restore selected items.', 'site-bloat-auditor' ),
        'Quarantined file deleted permanently.'                                                            => __( 'Quarantined file deleted permanently.', 'site-bloat-auditor' ),
        'Failed to delete quarantined item.'                                                               => __( 'Failed to delete quarantined item.', 'site-bloat-auditor' ),
        'Quarantine trash updated successfully.'                                                           => __( 'Quarantine trash updated successfully.', 'site-bloat-auditor' ),
        'Server error while deleting quarantined files.'                                                   => __( 'Server error while deleting quarantined files.', 'site-bloat-auditor' ),
        'Select All'                                                                                       => __( 'Select All', 'site-bloat-auditor' ),
        'Your Media Library is clean! No unlinked images detected.'                                        => __( 'Your Media Library is clean! No unlinked images detected.', 'site-bloat-auditor' ),
        'All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.' => __( 'All scanned attachments are actively referenced in published posts, pages, featured images, or theme logos.', 'site-bloat-auditor' ),
        'All images meet display benchmarks! No oversized images found.'                                   => __( 'All images meet display benchmarks! No oversized images found.', 'site-bloat-auditor' ),
        'None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.' => __( 'None of your active images exceed recommended widths (1920px hero / 1200px content) or 500 KB.', 'site-bloat-auditor' ),
        'No optimized images found.'                                                                       => __( 'No optimized images found.', 'site-bloat-auditor' ),
        'Active images require downscaling/compression or are currently unused.'                           => __( 'Active images require downscaling/compression or are currently unused.', 'site-bloat-auditor' ),
        'Active Auditor (Self)'                                                                            => __( 'Active Auditor (Self)', 'site-bloat-auditor' ),
        'Local Plugin (This Auditor)'                                                                      => __( 'Local Plugin (This Auditor)', 'site-bloat-auditor' ),
        'Local Active'                                                                                     => __( 'Local Active', 'site-bloat-auditor' ),
        'Missing Dependency'                                                                               => __( 'Missing Dependency', 'site-bloat-auditor' ),
        'Dependency Inactive'                                                                              => __( 'Dependency Inactive', 'site-bloat-auditor' ),
        'Local Plugin / Development'                                                                       => __( 'Local Plugin / Development', 'site-bloat-auditor' ),
        'Table'                                                                                            => __( 'Table', 'site-bloat-auditor' ),
        'Tables'                                                                                           => __( 'Tables', 'site-bloat-auditor' ),
        'Dismiss this notice.'                                                                             => __( 'Dismiss this notice.', 'site-bloat-auditor' ),
    );
}

/**
 * 3. Render Admin Dashboard HTML
 */
function sba_render_admin_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'site-bloat-auditor' ) );
    }
    include SBA_PLUGIN_DIR . 'templates/dashboard.php';
}

/**
 * Helper: Standard WordPress Core Table List (without prefix)
 *
 * @return array Whitelist of WordPress core table names without prefix.
 */
function sba_get_core_table_names() {
    global $wpdb;

    $core_tables = [
        'commentmeta',
        'comments',
        'links',
        'options',
        'postmeta',
        'posts',
        'term_relationships',
        'term_taxonomy',
        'termmeta',
        'terms',
        'usermeta',
        'users',
        // Multisite Core Tables
        'blogs',
        'blogmeta',
        'blog_versions',
        'registration_log',
        'signups',
        'site',
        'sitemeta',
    ];

    if ( ! empty( $wpdb->tables ) && is_array( $wpdb->tables ) ) {
        $core_tables = array_merge( $core_tables, array_values( $wpdb->tables ) );
    }
    if ( ! empty( $wpdb->ms_global_tables ) && is_array( $wpdb->ms_global_tables ) ) {
        $core_tables = array_merge( $core_tables, array_values( $wpdb->ms_global_tables ) );
    }

    return array_unique( $core_tables );
}

/**
 * Helper: Signature/prefix mapping dictionary for popular WordPress plugins.
 *
 * @return array Map of table prefixes to plugin names, sorted by prefix length descending.
 */
function sba_get_popular_plugin_signatures() {
    $signatures = [
        // WPML Multilingual CMS
        'icl_'             => [
            'name'      => 'WPML Multilingual CMS',
            'plugins'   => [ 'sitepress-multilingual-cms/sitepress.php', 'wpml-string-translation/plugin.php', 'sitepress', 'wpml' ],
            'classes'   => [ 'SitePress', 'WPML_String_Translation', 'WPML_Translation_Management' ],
            'constants' => [ 'ICL_SITEPRESS_VERSION', 'WPML_ST_VERSION' ],
        ],
        'wpml_'            => [
            'name'      => 'WPML Multilingual CMS',
            'plugins'   => [ 'sitepress-multilingual-cms/sitepress.php', 'wpml-string-translation/plugin.php', 'sitepress', 'wpml' ],
            'classes'   => [ 'SitePress', 'WPML_String_Translation', 'WPML_Translation_Management' ],
            'constants' => [ 'ICL_SITEPRESS_VERSION', 'WPML_ST_VERSION' ],
        ],

        // Contact Form 7
        'cf7_'             => [
            'name'      => 'Contact Form 7',
            'plugins'   => [ 'contact-form-7/wp-contact-form-7.php', 'contact-form-7', 'wpcf7' ],
            'classes'   => [ 'WPCF7', 'WPCF7_ContactForm' ],
            'constants' => [ 'WPCF7_VERSION' ],
        ],
        'wpcf7_'           => [
            'name'      => 'Contact Form 7',
            'plugins'   => [ 'contact-form-7/wp-contact-form-7.php', 'contact-form-7', 'wpcf7' ],
            'classes'   => [ 'WPCF7', 'WPCF7_ContactForm' ],
            'constants' => [ 'WPCF7_VERSION' ],
        ],
        'contact_form_7_'  => [
            'name'      => 'Contact Form 7',
            'plugins'   => [ 'contact-form-7/wp-contact-form-7.php', 'contact-form-7', 'wpcf7' ],
            'classes'   => [ 'WPCF7', 'WPCF7_ContactForm' ],
            'constants' => [ 'WPCF7_VERSION' ],
        ],
        'contact_form_7'   => [
            'name'      => 'Contact Form 7',
            'plugins'   => [ 'contact-form-7/wp-contact-form-7.php', 'contact-form-7', 'wpcf7' ],
            'classes'   => [ 'WPCF7', 'WPCF7_ContactForm' ],
            'constants' => [ 'WPCF7_VERSION' ],
        ],

        // UpdraftPlus
        'updraft_'         => [
            'name'      => 'UpdraftPlus Backup',
            'plugins'   => [ 'updraftplus/updraftplus.php', 'updraftplus' ],
            'classes'   => [ 'UpdraftPlus', 'UpdraftPlus_Backup' ],
            'constants' => [ 'UPDRAFTPLUS_DIR' ],
        ],
        'updraftplus_'     => [
            'name'      => 'UpdraftPlus Backup',
            'plugins'   => [ 'updraftplus/updraftplus.php', 'updraftplus' ],
            'classes'   => [ 'UpdraftPlus', 'UpdraftPlus_Backup' ],
            'constants' => [ 'UPDRAFTPLUS_DIR' ],
        ],

        // WPBakery Page Builder / Visual Composer
        'vc_'              => [
            'name'      => 'WPBakery Page Builder',
            'plugins'   => [ 'js_composer/js_composer.php', 'js_composer', 'js-composer', 'wpbakery' ],
            'classes'   => [ 'Vc_Manager' ],
            'constants' => [ 'WPB_VC_VERSION' ],
        ],
        'wpbakery_'        => [
            'name'      => 'WPBakery Page Builder',
            'plugins'   => [ 'js_composer/js_composer.php', 'js_composer', 'js-composer', 'wpbakery' ],
            'classes'   => [ 'Vc_Manager' ],
            'constants' => [ 'WPB_VC_VERSION' ],
        ],
        'js_composer_'     => [
            'name'      => 'WPBakery Page Builder',
            'plugins'   => [ 'js_composer/js_composer.php', 'js_composer', 'js-composer', 'wpbakery' ],
            'classes'   => [ 'Vc_Manager' ],
            'constants' => [ 'WPB_VC_VERSION' ],
        ],

        // WooCommerce & Action Scheduler
        'woocommerce_'     => [
            'name'      => 'WooCommerce',
            'plugins'   => [ 'woocommerce/woocommerce.php', 'woocommerce' ],
            'classes'   => [ 'WooCommerce' ],
            'constants' => [ 'WC_VERSION' ],
        ],
        'wc_'              => [
            'name'      => 'WooCommerce',
            'plugins'   => [ 'woocommerce/woocommerce.php', 'woocommerce' ],
            'classes'   => [ 'WooCommerce' ],
            'constants' => [ 'WC_VERSION' ],
        ],
        'actionscheduler_' => [
            'name'      => 'Action Scheduler (WooCommerce)',
            'plugins'   => [ 'woocommerce/woocommerce.php', 'woocommerce', 'action-scheduler' ],
            'classes'   => [ 'WooCommerce', 'ActionScheduler' ],
            'constants' => [ 'WC_VERSION' ],
        ],

        // Yoast SEO
        'yoast_'           => [
            'name'      => 'Yoast SEO',
            'plugins'   => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo', 'yoast' ],
            'classes'   => [ 'WPSEO_Options' ],
            'constants' => [ 'WPSEO_VERSION' ],
        ],
        'yoast_seo_'       => [
            'name'      => 'Yoast SEO',
            'plugins'   => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo', 'yoast' ],
            'classes'   => [ 'WPSEO_Options' ],
            'constants' => [ 'WPSEO_VERSION' ],
        ],
        'wpseo_'           => [
            'name'      => 'Yoast SEO',
            'plugins'   => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo', 'yoast' ],
            'classes'   => [ 'WPSEO_Options' ],
            'constants' => [ 'WPSEO_VERSION' ],
        ],

        // Rank Math SEO
        'rank_math_'       => [
            'name'      => 'Rank Math SEO',
            'plugins'   => [ 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math', 'rank-math' ],
            'classes'   => [ 'RankMath' ],
            'constants' => [ 'RANK_MATH_VERSION' ],
        ],
        'rankmath_'        => [
            'name'      => 'Rank Math SEO',
            'plugins'   => [ 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math', 'rank-math' ],
            'classes'   => [ 'RankMath' ],
            'constants' => [ 'RANK_MATH_VERSION' ],
        ],

        // Redirection
        'redirection_'     => [
            'name'      => 'Redirection Plugin',
            'plugins'   => [ 'redirection/redirection.php', 'redirection' ],
            'classes'   => [ 'Redirection_Plugin' ],
            'constants' => [ 'REDIRECTION_VERSION' ],
        ],

        // Wordfence Security
        'wordfence_'       => [
            'name'      => 'Wordfence Security',
            'plugins'   => [ 'wordfence/wordfence.php', 'wordfence' ],
            'classes'   => [ 'wordfence' ],
            'constants' => [ 'WORDFENCE_VERSION' ],
        ],
        'wf'               => [
            'name'      => 'Wordfence Security',
            'plugins'   => [ 'wordfence/wordfence.php', 'wordfence' ],
            'classes'   => [ 'wordfence' ],
            'constants' => [ 'WORDFENCE_VERSION' ],
        ],

        // Elementor
        'elementor_'       => [
            'name'      => 'Elementor',
            'plugins'   => [ 'elementor/elementor.php', 'elementor', 'elementor-pro' ],
            'classes'   => [ '\Elementor\Plugin' ],
            'constants' => [ 'ELEMENTOR_VERSION' ],
        ],
        'e_'               => [
            'name'      => 'Elementor',
            'plugins'   => [ 'elementor/elementor.php', 'elementor', 'elementor-pro' ],
            'classes'   => [ '\Elementor\Plugin' ],
            'constants' => [ 'ELEMENTOR_VERSION' ],
        ],

        // Gravity Forms
        'gf_'              => [
            'name'    => 'Gravity Forms',
            'plugins' => [ 'gravityforms/gravityforms.php', 'gravityforms' ],
            'classes' => [ 'GFForms' ],
        ],
        'rg_'              => [
            'name'    => 'Gravity Forms',
            'plugins' => [ 'gravityforms/gravityforms.php', 'gravityforms' ],
            'classes' => [ 'GFForms' ],
        ],

        // WPForms
        'wpforms_'         => [
            'name'    => 'WPForms',
            'plugins' => [ 'wpforms/wpforms.php', 'wpforms-lite/wpforms.php', 'wpforms', 'wpforms-lite' ],
            'classes' => [ 'WPForms' ],
        ],

        // Smart Slider 3
        'nextend2_'        => [
            'name'      => 'Smart Slider 3',
            'plugins'   => [ 'smart-slider-3/smart-slider-3.php', 'smart-slider-3' ],
            'classes'   => [ 'N2SS3' ],
            'constants' => [ 'NEXTEND_SMARTSLIDER_3' ],
        ],

        // Slider Revolution
        'revslider_'       => [
            'name'    => 'Slider Revolution',
            'plugins' => [ 'revslider/revslider.php', 'revslider' ],
            'classes' => [ 'RevSliderFront', 'RevSlider' ],
        ],

        // Solid Security / iThemes
        'itsec_'           => [
            'name'    => 'Solid Security / iThemes',
            'plugins' => [ 'better-wp-security/better-wp-security.php', 'better-wp-security', 'ithemes-security-pro' ],
            'classes' => [ 'ITSEC_Core' ],
        ],

        // All in One SEO
        'aioseo_'          => [
            'name'    => 'All in One SEO',
            'plugins' => [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack' ],
        ],

        // WP Mail SMTP
        'wpmailsmtp_'      => [
            'name'    => 'WP Mail SMTP',
            'plugins' => [ 'wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp' ],
            'classes' => [ 'WPMailSMTP\Core' ],
        ],

        // Mailchimp for WordPress
        'mailchimp_'       => [
            'name'    => 'Mailchimp for WordPress',
            'plugins' => [ 'mailchimp-for-wp/mailchimp-for-wp.php', 'mailchimp-for-wp' ],
            'classes' => [ 'MC4WP_Container' ],
        ],
        'mc4wp_'           => [
            'name'    => 'Mailchimp for WordPress',
            'plugins' => [ 'mailchimp-for-wp/mailchimp-for-wp.php', 'mailchimp-for-wp' ],
            'classes' => [ 'MC4WP_Container' ],
        ],

        // WP-Optimize
        'tm_epo_'          => [
            'name'      => 'WP-Optimize',
            'plugins'   => [ 'wp-optimize/wp-optimize.php', 'wp-optimize' ],
            'classes'   => [ 'WP_Optimize' ],
            'constants' => [ 'WPO_VERSION' ],
        ],
        'wp_optimize_'     => [
            'name'      => 'WP-Optimize',
            'plugins'   => [ 'wp-optimize/wp-optimize.php', 'wp-optimize' ],
            'classes'   => [ 'WP_Optimize' ],
            'constants' => [ 'WPO_VERSION' ],
        ],

        // LearnDash
        'learndash_'       => [
            'name'    => 'LearnDash',
            'plugins' => [ 'sfwd-lms/sfwd_lms.php', 'learndash', 'sfwd-lms' ],
        ],

        // LifterLMS
        'llms_'            => [
            'name'    => 'LifterLMS',
            'plugins' => [ 'lifterlms/lifterlms.php', 'lifterlms' ],
        ],

        // MemberPress
        'mepr_'            => [
            'name'    => 'MemberPress',
            'plugins' => [ 'memberpress/memberpress.php', 'memberpress' ],
        ],

        // Easy Digital Downloads
        'edd_'             => [
            'name'    => 'Easy Digital Downloads',
            'plugins' => [ 'easy-digital-downloads/easy-digital-downloads.php', 'easy-digital-downloads' ],
        ],

        // LearnPress
        'learnpress_'      => [
            'name'    => 'LearnPress',
            'plugins' => [ 'learnpress/learnpress.php', 'learnpress' ],
        ],

        // Site Bloat Auditor
        'sba_'             => [
            'name'    => 'Site Bloat Auditor',
            'plugins' => [ 'site-bloat-auditor/site-bloat-auditor.php', 'site-bloat-auditor' ],
        ],
        'site_bloat_'      => [
            'name'    => 'Site Bloat Auditor',
            'plugins' => [ 'site-bloat-auditor/site-bloat-auditor.php', 'site-bloat-auditor' ],
        ],

        // WP All Export
        'pmxe_'            => [
            'name'      => 'WP All Export',
            'plugins'   => [ 'wp-all-export/wp-all-export.php', 'wp-all-export-pro/wp-all-export-pro.php', 'wp-all-export', 'wp-all-export-pro' ],
            'classes'   => [ 'PMXE_Plugin' ],
        ],

        // WP All Import
        'pmxi_'            => [
            'name'      => 'WP All Import',
            'plugins'   => [ 'wp-all-import/wp-all-import.php', 'wp-all-import-pro/wp-all-import-pro.php', 'wp-all-import', 'wp-all-import-pro' ],
            'classes'   => [ 'PMXI_Plugin' ],
        ],

        // Formidable Forms
        'frm_'             => [
            'name'      => 'Formidable Forms',
            'plugins'   => [ 'formidable/formidable.php', 'formidable' ],
            'classes'   => [ 'FrmAppHelper' ],
        ],

        // Ninja Forms
        'nf3_'             => [
            'name'      => 'Ninja Forms',
            'plugins'   => [ 'ninja-forms/ninja-forms.php', 'ninja-forms' ],
            'classes'   => [ 'Ninja_Forms' ],
        ],

        // Fluent Forms
        'fluentform_'      => [
            'name'      => 'Fluent Forms',
            'plugins'   => [ 'fluentform/fluentform.php', 'fluentform' ],
        ],

        // TranslatePress
        'trp_'             => [
            'name'      => 'TranslatePress',
            'plugins'   => [ 'translatepress-multilingual/index.php', 'translatepress-multilingual', 'translatepress' ],
            'classes'   => [ 'TRP_Translate_Press' ],
        ],

        // All In One WP Security
        'aiowps_'          => [
            'name'      => 'All In One WP Security',
            'plugins'   => [ 'all-in-one-wp-security-and-firewall/wp-security.php', 'all-in-one-wp-security-and-firewall' ],
            'classes'   => [ 'AIOWPSecurity_Framework' ],
        ],

        // WP Statistics
        'statistics_'      => [
            'name'      => 'WP Statistics',
            'plugins'   => [ 'wp-statistics/wp-statistics.php', 'wp-statistics' ],
            'classes'   => [ 'WP_Statistics' ],
        ],

        // MailPoet
        'mailpoet_'        => [
            'name'      => 'MailPoet',
            'plugins'   => [ 'mailpoet/mailpoet.php', 'mailpoet' ],
        ],

        // Tutor LMS
        'tutor_'           => [
            'name'      => 'Tutor LMS',
            'plugins'   => [ 'tutor/tutor.php', 'tutor' ],
        ],

        // Pretty Links
        'prli_'            => [
            'name'      => 'Pretty Links',
            'plugins'   => [ 'pretty-link/pretty-link.php', 'pretty-link' ],
        ],

        // Duplicator
        'duplicator_'      => [
            'name'      => 'Duplicator',
            'plugins'   => [ 'duplicator/duplicator.php', 'duplicator-pro/duplicator-pro.php', 'duplicator', 'duplicator-pro' ],
        ],
    ];

    uksort( $signatures, function( $a, $b ) {
        return strlen( $b ) - strlen( $a );
    } );

    return $signatures;
}

/**
 * Helper: Retrieve all active plugin files (including network activated and runtime loaded).
 *
 * @param array $all_plugins Array of all installed plugins from get_plugins().
 * @return array List of active plugin file identifiers.
 */
function sba_get_active_plugin_files( $all_plugins = [] ) {
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $raw_active_plugins = (array) apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
    if ( is_multisite() ) {
        $sitewide_plugins   = (array) get_site_option( 'active_sitewide_plugins', [] );
        $raw_active_plugins = array_merge( $raw_active_plugins, array_keys( $sitewide_plugins ) );
    }

    $active_plugin_files = (array) $raw_active_plugins;

    if ( ! empty( $all_plugins ) && function_exists( 'is_plugin_active' ) ) {
        foreach ( array_keys( $all_plugins ) as $plugin_file ) {
            if ( is_plugin_active( $plugin_file ) || ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) ) ) {
                $active_plugin_files[] = $plugin_file;
            }
        }
    }

    // Runtime checks for active plugins in memory (WPML, CF7, UpdraftPlus, WPBakery, WooCommerce, etc.)
    $runtime_active_signatures = [
        'sitepress-multilingual-cms/sitepress.php' => class_exists( 'SitePress' ) || defined( 'ICL_SITEPRESS_VERSION' ),
        'wpml-string-translation/plugin.php'       => class_exists( 'WPML_String_Translation' ) || defined( 'WPML_ST_VERSION' ),
        'contact-form-7/wp-contact-form-7.php'     => class_exists( 'WPCF7' ) || defined( 'WPCF7_VERSION' ),
        'updraftplus/updraftplus.php'               => class_exists( 'UpdraftPlus' ) || class_exists( 'UpdraftPlus_Backup' ) || defined( 'UPDRAFTPLUS_DIR' ),
        'js_composer/js_composer.php'              => class_exists( 'Vc_Manager' ) || defined( 'WPB_VC_VERSION' ),
        'woocommerce/woocommerce.php'             => class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ),
        'wordpress-seo/wp-seo.php'                 => defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ),
        'seo-by-rank-math/rank-math.php'           => class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ),
        'redirection/redirection.php'              => class_exists( 'Redirection_Plugin' ) || defined( 'REDIRECTION_VERSION' ),
        'wordfence/wordfence.php'                  => class_exists( 'wordfence' ) || defined( 'WORDFENCE_VERSION' ),
        'elementor/elementor.php'                  => class_exists( '\Elementor\Plugin' ) || defined( 'ELEMENTOR_VERSION' ),
        'wpforms-lite/wpforms.php'                 => class_exists( 'WPForms' ) || function_exists( 'wpforms' ),
        'wp-all-export/wp-all-export.php'          => class_exists( 'PMXE_Plugin' ),
        'wp-all-export-pro/wp-all-export-pro.php'  => class_exists( 'PMXE_Plugin' ),
        'wp-all-import/wp-all-import.php'          => class_exists( 'PMXI_Plugin' ),
        'wp-all-import-pro/wp-all-import-pro.php'  => class_exists( 'PMXI_Plugin' ),
        'formidable/formidable.php'                => class_exists( 'FrmAppHelper' ),
        'ninja-forms/ninja-forms.php'              => class_exists( 'Ninja_Forms' ),
        'translatepress-multilingual/index.php'    => class_exists( 'TRP_Translate_Press' ),
        'wp-statistics/wp-statistics.php'          => class_exists( 'WP_Statistics' ),
    ];

    foreach ( $runtime_active_signatures as $rt_slug => $is_rt_active ) {
        if ( $is_rt_active && ! in_array( $rt_slug, $active_plugin_files, true ) ) {
            $active_plugin_files[] = $rt_slug;
        }
    }

    return array_values( array_unique( array_filter( $active_plugin_files ) ) );
}

/**
 * Helper: Extract identifying slugs and prefixes for a list of plugins.
 *
 * @param array $plugin_files Array of plugin file paths relative to plugins dir.
 * @param array $all_plugins  Array of plugin data returned by get_plugins().
 * @return array Matcher definitions with slug, name, and prefixes.
 */
function sba_extract_plugin_matchers( $plugin_files, $all_plugins ) {
    $known_prefix_map = [
        'sitepress-multilingual-cms' => [ 'icl_', 'wpml_' ],
        'sitepress'                  => [ 'icl_', 'wpml_' ],
        'wpml-string-translation'    => [ 'icl_', 'wpml_' ],
        'wpml-translation-management'=> [ 'icl_', 'wpml_' ],
        'wpml-media-translation'     => [ 'icl_', 'wpml_' ],
        'wpml'                       => [ 'icl_', 'wpml_' ],
        'contact-form-7'             => [ 'cf7_', 'wpcf7_', 'contact_form_7_', 'contact_form_7' ],
        'wp-contact-form-7'          => [ 'cf7_', 'wpcf7_', 'contact_form_7_', 'contact_form_7' ],
        'updraftplus'                => [ 'updraft_', 'updraftplus_' ],
        'js_composer'                => [ 'vc_', 'wpbakery_', 'js_composer_' ],
        'js-composer'                => [ 'vc_', 'wpbakery_', 'js_composer_' ],
        'wpbakery'                   => [ 'vc_', 'wpbakery_', 'js_composer_' ],
        'woocommerce'                => [ 'wc_', 'woocommerce_', 'actionscheduler_' ],
        'action-scheduler'           => [ 'actionscheduler_' ],
        'wordpress-seo'              => [ 'yoast_', 'yoast_seo_', 'wpseo_' ],
        'wordpress-seo-premium'      => [ 'yoast_', 'yoast_seo_', 'wpseo_' ],
        'yoast'                      => [ 'yoast_', 'yoast_seo_', 'wpseo_' ],
        'rank-math'                  => [ 'rank_math_', 'rankmath_' ],
        'seo-by-rank-math'           => [ 'rank_math_', 'rankmath_' ],
        'redirection'                => [ 'redirection_' ],
        'wordfence'                  => [ 'wf', 'wordfence_' ],
        'gravityforms'               => [ 'gf_', 'rg_' ],
        'elementor'                  => [ 'e_', 'elementor_' ],
        'elementor-pro'              => [ 'e_', 'elementor_' ],
        'wpforms'                    => [ 'wpforms_' ],
        'wpforms-lite'               => [ 'wpforms_' ],
        'all-in-one-seo-pack'        => [ 'aioseo_' ],
        'better-wp-security'         => [ 'itsec_' ],
        'ithemes-security-pro'       => [ 'itsec_' ],
        'revslider'                  => [ 'revslider_' ],
        'wp-mail-smtp'               => [ 'wpmailsmtp_' ],
        'smart-slider-3'             => [ 'nextend2_' ],
        'wp-optimize'                => [ 'tm_epo_', 'wp_optimize_' ],
        'learndash'                  => [ 'learndash_' ],
        'sfwd-lms'                   => [ 'learndash_' ],
        'lifterlms'                  => [ 'llms_' ],
        'memberpress'                => [ 'mepr_' ],
        'easy-digital-downloads'     => [ 'edd_' ],
        'learnpress'                 => [ 'learnpress_' ],
        'mailchimp-for-wp'           => [ 'mailchimp_', 'mc4wp_' ],
        'site-bloat-auditor'         => [ 'sba_', 'site_bloat_auditor_', 'site_bloat_' ],
        'wp-all-export'              => [ 'pmxe_' ],
        'wp-all-export-pro'          => [ 'pmxe_' ],
        'wp-all-import'              => [ 'pmxi_' ],
        'wp-all-import-pro'          => [ 'pmxi_' ],
        'formidable'                 => [ 'frm_' ],
        'ninja-forms'                => [ 'nf3_' ],
        'fluentform'                 => [ 'fluentform_' ],
        'translatepress-multilingual'=> [ 'trp_' ],
        'translatepress'             => [ 'trp_' ],
        'all-in-one-wp-security-and-firewall' => [ 'aiowps_' ],
        'all-in-one-wp-security-firewall'     => [ 'aiowps_' ],
        'wp-statistics'              => [ 'statistics_' ],
        'mailpoet'                   => [ 'mailpoet_' ],
        'tutor'                      => [ 'tutor_' ],
        'pretty-link'                => [ 'prli_' ],
        'duplicator'                 => [ 'duplicator_' ],
        'duplicator-pro'             => [ 'duplicator_' ],
    ];

    $matchers = [];

    foreach ( $plugin_files as $plugin_file ) {
        $parts = explode( '/', $plugin_file );
        $slug  = sanitize_title( $parts[0] );
        $base  = sanitize_title( basename( $plugin_file, '.php' ) );

        $prefixes = [
            $slug . '_',
            str_replace( '-', '_', $slug ) . '_',
            str_replace( '-', '', $slug ) . '_',
            $base . '_',
            str_replace( '-', '_', $base ) . '_',
            $slug,
            str_replace( '-', '_', $slug ),
        ];

        foreach ( [ $slug, $base ] as $k ) {
            if ( isset( $known_prefix_map[ $k ] ) ) {
                $prefixes = array_merge( $prefixes, $known_prefix_map[ $k ] );
            }
        }

        $plugin_name = ! empty( $all_plugins[ $plugin_file ]['Name'] )
            ? $all_plugins[ $plugin_file ]['Name']
            : ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );

        $matchers[] = [
            'file'     => $plugin_file,
            'name'     => $plugin_name,
            'slug'     => $slug,
            'prefixes' => array_unique( array_filter( $prefixes ) ),
        ];
    }

    return $matchers;
}

/**
 * Helper: Calculate folder or file size in MB.
 *
 * @param string $plugin_file Plugin file path relative to WP_PLUGIN_DIR.
 * @return float Size in MB.
 */
function sba_get_plugin_disk_size_mb( $plugin_file ) {
    $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
    if ( strpos( $plugin_file, '/' ) !== false ) {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
        if ( is_dir( $plugin_dir ) ) {
            return sba_get_directory_size_mb( $plugin_dir );
        }
    }

    if ( file_exists( $plugin_path ) ) {
        $bytes = @filesize( $plugin_path );
        return round( ( (float) $bytes ) / ( 1024 * 1024 ), 2 );
    }

    return 0.0;
}

/**
 * Cross-platform file/directory readability check.
 * Handles Windows PHP is_readable ACL quirks via stream fallback.
 *
 * @param string $path Path to check.
 * @return bool True if readable.
 */
function sba_is_readable( $path ) {
    if ( empty( $path ) || ! file_exists( $path ) ) {
        return false;
    }
    if ( @is_readable( $path ) ) {
        return true;
    }
    if ( is_dir( $path ) ) {
        $dh = @opendir( $path );
        if ( false !== $dh ) {
            closedir( $dh );
            return true;
        }
        return false;
    }
    $fh = @fopen( $path, 'rb' );
    if ( false !== $fh ) {
        fclose( $fh );
        return true;
    }
    return false;
}

/**
 * Helper: Recursively calculate directory size in MB.
 *
 * @param string $dir_path Absolute directory path.
 * @return float Size in MB.
 */
function sba_get_directory_size_mb( $dir_path ) {
    if ( ! is_dir( $dir_path ) || ! sba_is_readable( $dir_path ) ) {
        return 0.0;
    }

    $total_bytes = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir_path, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $total_bytes += $file->getSize();
            }
        }
    } catch ( Exception $e ) {
        // Fallback gracefully on permission or file system error
    }

    return round( ( (float) $total_bytes ) / ( 1024 * 1024 ), 2 );
}

/**
 * Helper: Check if an active plugin has 0 usage based on registered CPTs, shortcodes/blocks, and tables.
 *
 * @param string $plugin_file Relative plugin file path.
 * @param array  $plugin_data Plugin metadata from get_plugins().
 * @param array  $tables_data Scanned database tables data.
 * @param array  $registered_cpt_counts Associative array of custom post_type => row_count in wp_posts.
 * @return array Array with 'is_unused' bool and 'reason' string.
 */
function sba_check_active_plugin_usage( $plugin_file, $plugin_data, $tables_data, $registered_cpt_counts ) {
    global $wpdb, $shortcode_tags;

    $plugin_slug = dirname( $plugin_file );
    if ( '.' === $plugin_slug || empty( $plugin_slug ) ) {
        $plugin_slug = sanitize_title( pathinfo( $plugin_file, PATHINFO_FILENAME ) );
    }

    // 1. Whitelisted Utility / Systemic Plugins that do not store content in wp_posts or have obvious shortcodes
    $utility_slugs = [
        'site-bloat-auditor',
        'wordfence',
        'better-wp-security',
        'sucuri-scanner',
        'limit-login-attempts-reloaded',
        'two-factor',
        'litespeed-cache',
        'wp-super-cache',
        'w3-total-cache',
        'wp-optimize',
        'autoptimize',
        'redis-cache',
        'wordpress-seo',
        'seo-by-rank-math',
        'all-in-one-seo-pack',
        'wp-seopress',
        'updraftplus',
        'backwpup',
        'duplicator',
        'wp-mail-smtp',
        'post-smtp',
        'fluent-smtp',
        'google-site-kit',
        'google-analytics-for-wordpress',
        'pixelyoursite',
        'query-monitor',
        'wp-crontrol',
        'code-snippets',
        'sitepress-multilingual-cms',
        'polylang',
        'loco-translate',
        'classic-editor',
        'really-simple-ssl',
        'wp-smushit',
        'imagify',
        'shortpixel-image-optimiser',
        'regenerate-thumbnails',
        'advanced-custom-fields',
        'advanced-custom-fields-pro',
        'acf',
    ];

    foreach ( $utility_slugs as $util ) {
        if ( strpos( $plugin_slug, $util ) !== false ) {
            return [
                'is_unused' => false,
                'reason'    => __( 'Systemic utility / infrastructure plugin', 'site-bloat-auditor' ),
            ];
        }
    }

    // 2. Check if the plugin has dedicated custom database tables with active rows
    $slug_clean = str_replace( '-', '_', $plugin_slug );
    foreach ( $tables_data as $tbl ) {
        if ( 'active_plugin' === $tbl['status'] || 'orphaned' !== $tbl['status'] ) {
            if ( false !== stripos( $tbl['likely_plugin'], $plugin_data['Name'] ) || false !== stripos( $tbl['name'], $slug_clean ) ) {
                if ( ! empty( $tbl['row_count'] ) && (int) $tbl['row_count'] > 0 ) {
                    return [
                        'is_unused' => false,
                        'reason'    => sprintf( __( 'Active database table data found (%s)', 'site-bloat-auditor' ), $tbl['name'] ),
                    ];
                }
            }
        }
    }

    // 3. Check registered Custom Post Types associated with this plugin
    $has_cpt_checked = false;
    foreach ( $registered_cpt_counts as $post_type => $count ) {
        $slug_token = strtok( $plugin_slug, '-_' );
        if ( ( strlen( $slug_token ) >= 3 && strpos( $post_type, $slug_token ) !== false ) || strpos( $post_type, $slug_clean ) !== false ) {
            if ( $count > 0 ) {
                return [
                    'is_unused' => false,
                    'reason'    => sprintf( __( 'Custom post type "%s" has %d records', 'site-bloat-auditor' ), $post_type, $count ),
                ];
            }
            $has_cpt_checked = true;
        }
    }

    // 4. Check if common shortcodes or block names associated with the plugin appear in post_content
    $shortcode_candidates = [];

    if ( ! empty( $shortcode_tags ) && is_array( $shortcode_tags ) ) {
        $slug_part = strtok( $plugin_slug, '-_' );
        foreach ( array_keys( $shortcode_tags ) as $tag ) {
            if ( ( strlen( $slug_part ) >= 3 && strpos( $tag, $slug_part ) !== false ) || strpos( $tag, $slug_clean ) !== false ) {
                $shortcode_candidates[] = '[' . $tag;
            }
        }
    }

    $known_shortcodes = [
        'contact-form-7'   => [ '[contact-form-7', 'wp:contact-form-7' ],
        'wpforms'          => [ '[wpforms', 'wp:wpforms' ],
        'wpforms-lite'     => [ '[wpforms', 'wp:wpforms' ],
        'elementor'        => [ 'elementor-template', '<!-- wp:elementor' ],
        'smart-slider-3'   => [ '[smartslider3', 'wp:smartslider3' ],
        'revslider'        => [ '[rev_slider', 'wp:revslider' ],
        'gravityforms'     => [ '[gravityform', 'wp:gravityforms' ],
        'mailchimp-for-wp' => [ '[mc4wp_form', 'wp:mc4wp' ],
        'ninja-forms'      => [ '[ninja_form', 'wp:ninja-forms' ],
        'formidable'       => [ '[formidable', 'wp:formidable' ],
    ];

    if ( isset( $known_shortcodes[ $plugin_slug ] ) ) {
        $shortcode_candidates = array_merge( $shortcode_candidates, $known_shortcodes[ $plugin_slug ] );
    }

    $shortcode_candidates = array_unique( $shortcode_candidates );

    $found_content_usage = false;
    foreach ( $shortcode_candidates as $needle ) {
        $found = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish', 'draft', 'pending', 'future', 'private') LIMIT 1",
            '%' . $wpdb->esc_like( $needle ) . '%'
        ) );
        if ( $found > 0 ) {
            $found_content_usage = true;
            return [
                'is_unused' => false,
                'reason'    => sprintf( __( 'Found active usage in post content (%s)', 'site-bloat-auditor' ), $needle ),
            ];
        }
    }

    // If it has registered CPTs that all have 0 rows, and 0 shortcodes in content -> active_unused!
    if ( $has_cpt_checked ) {
        return [
            'is_unused' => true,
            'reason'    => __( 'Registered custom post types have 0 records and no shortcodes found in content', 'site-bloat-auditor' ),
        ];
    }

    // If it is a known content/form/builder plugin with 0 shortcodes and 0 posts -> active_unused!
    if ( isset( $known_shortcodes[ $plugin_slug ] ) && ! $found_content_usage ) {
        return [
            'is_unused' => true,
            'reason'    => __( '0 forms or shortcodes placed in post or page content', 'site-bloat-auditor' ),
        ];
    }

    // Default: If no evidence of unused, consider it active
    return [
        'is_unused' => false,
        'reason'    => __( 'Active and operational', 'site-bloat-auditor' ),
    ];
}

/**
 * Helper: Verify if a plugin is officially listed and active in the WordPress.org Plugin Directory.
 *
 * @param string $plugin_slug The plugin slug or folder name.
 * @return array Array containing repo_status ('verified'|'unverified'), repo_status_label, risk_level, and risk_explanation.
 */
function sba_verify_wporg_plugin( $plugin_slug ) {
    $plugin_slug = sanitize_title( $plugin_slug );
    if ( empty( $plugin_slug ) ) {
        return [
            'repo_status'       => 'unverified',
            'repo_status_label' => __( 'Unofficial / Not in Directory', 'site-bloat-auditor' ),
            'risk_level'        => 'High Risk',
            'risk_explanation'  => __( 'Missing or invalid plugin identifier.', 'site-bloat-auditor' ),
        ];
    }

    $transient_key = 'sba_wp_' . md5( $plugin_slug );
    $cached        = get_transient( $transient_key );

    if ( false !== $cached && is_array( $cached ) && isset( $cached['repo_status'] ) ) {
        return $cached;
    }

    // Query official WordPress.org Plugin Information API v1.2
    $api_url = add_query_arg( [
        'action'        => 'plugin_information',
        'request[slug]' => $plugin_slug,
    ], 'https://api.wordpress.org/plugins/info/1.2/' );

    $response = wp_remote_get( esc_url_raw( $api_url ), [
        'timeout'    => 8,
        'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
    ] );

    $is_verified = false;

    if ( ! is_wp_error( $response ) ) {
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 === $code ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! empty( $data ) && is_array( $data ) && empty( $data['error'] ) && ! empty( $data['slug'] ) ) {
                $is_verified = true;
            }
        }
    }

    if ( $is_verified ) {
        $result = [
            'repo_status'       => 'verified',
            'repo_status_label' => __( 'Verified on WP.org', 'site-bloat-auditor' ),
            'risk_level'        => 'Low',
            'risk_explanation'  => __( 'Officially listed and verified in the WordPress.org Plugin Directory.', 'site-bloat-auditor' ),
        ];
    } else {
        $result = [
            'repo_status'       => 'unverified',
            'repo_status_label' => __( 'Unofficial / Not in Directory', 'site-bloat-auditor' ),
            'risk_level'        => 'High Risk',
            'risk_explanation'  => __( 'Not found in official WordPress.org directory. Custom, abandoned, commercial, or unverified code.', 'site-bloat-auditor' ),
        ];
    }

    // Cache transient for 7 days
    set_transient( $transient_key, $result, 7 * DAY_IN_SECONDS );

    return $result;
}

/**
 * Helper: Calculate storage statistics for post revisions.
 *
 * Measures content length of all revision rows in wp_posts as well as associated wp_postmeta rows.
 *
 * @return array Revisions storage metrics including count, bytes, KB, and releasable size.
 */
function sba_get_revisions_storage_stats() {
    global $wpdb;

    // 1. Get revisions count and posts table bytes
    $posts_stats = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(*) AS total_count,
            COALESCE(SUM(
                COALESCE(LENGTH(post_content), 0) + 
                COALESCE(LENGTH(post_title), 0) + 
                COALESCE(LENGTH(post_excerpt), 0) + 
                COALESCE(LENGTH(post_name), 0) +
                COALESCE(LENGTH(to_ping), 0) +
                COALESCE(LENGTH(pinged), 0) +
                COALESCE(LENGTH(post_content_filtered), 0) +
                COALESCE(LENGTH(guid), 0)
            ), 0) AS posts_bytes
        FROM {$wpdb->posts}
        WHERE post_type = %s
    ", 'revision' ) );

    $total_count = (int) ( $posts_stats->total_count ?? 0 );
    $posts_bytes = (int) ( $posts_stats->posts_bytes ?? 0 );

    // 2. Associated postmeta bytes for revisions
    $meta_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COALESCE(SUM(LENGTH(pm.meta_key) + LENGTH(pm.meta_value)), 0)
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = %s
    ", 'revision' ) );

    $total_bytes = $posts_bytes + $meta_bytes;
    $total_kb    = round( $total_bytes / 1024, 2 );

    // 3. Calculate cleanable excess bytes (older than 3 per parent + orphaned)
    $cleanable_count = 0;
    $cleanable_bytes = 0;

    if ( $total_count > 0 ) {
        $parent_posts = $wpdb->get_col( $wpdb->prepare( "
            SELECT DISTINCT post_parent 
            FROM {$wpdb->posts} 
            WHERE post_type = %s AND post_parent > %d
        ", 'revision', 0 ) );

        $excess_ids = [];
        $keep_count = 3;

        foreach ( $parent_posts as $parent_id ) {
            $rev_ids = $wpdb->get_col( $wpdb->prepare( "
                SELECT ID 
                FROM {$wpdb->posts} 
                WHERE post_type = 'revision' AND post_parent = %d 
                ORDER BY ID DESC
            ", (int) $parent_id ) );

            if ( count( $rev_ids ) > $keep_count ) {
                $excess_ids = array_merge( $excess_ids, array_slice( $rev_ids, $keep_count ) );
            }
        }

        $orphaned_ids = $wpdb->get_col( $wpdb->prepare( "
            SELECT r.ID 
            FROM {$wpdb->posts} r 
            LEFT JOIN {$wpdb->posts} p ON r.post_parent = p.ID 
            WHERE r.post_type = %s AND (r.post_parent = %d OR p.ID IS NULL)
        ", 'revision', 0 ) );

        $excess_ids = array_unique( array_merge( $excess_ids, $orphaned_ids ) );
        $cleanable_count = count( $excess_ids );

        if ( ! empty( $excess_ids ) ) {
            $chunks = array_chunk( $excess_ids, 100 );
            foreach ( $chunks as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

                $chunk_posts_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
                    SELECT COALESCE(SUM(
                        COALESCE(LENGTH(post_content), 0) + 
                        COALESCE(LENGTH(post_title), 0) + 
                        COALESCE(LENGTH(post_excerpt), 0) + 
                        COALESCE(LENGTH(post_name), 0) +
                        COALESCE(LENGTH(to_ping), 0) +
                        COALESCE(LENGTH(pinged), 0) +
                        COALESCE(LENGTH(post_content_filtered), 0) +
                        COALESCE(LENGTH(guid), 0)
                    ), 0)
                    FROM {$wpdb->posts}
                    WHERE ID IN ($placeholders)
                ", ...$chunk ) );

                $chunk_meta_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
                    SELECT COALESCE(SUM(LENGTH(meta_key) + LENGTH(meta_value)), 0)
                    FROM {$wpdb->postmeta}
                    WHERE post_id IN ($placeholders)
                ", ...$chunk ) );

                $cleanable_bytes += ( $chunk_posts_bytes + $chunk_meta_bytes );
            }
        }
    }

    return [
        'total_count'      => $total_count,
        'total_bytes'      => $total_bytes,
        'total_kb'         => $total_kb,
        'releasable_bytes' => $total_bytes, // deleting all revisions releases 100% of their bytes
        'releasable_kb'    => $total_kb,
        'cleanable_count'  => $cleanable_count,
        'cleanable_bytes'  => $cleanable_bytes,
        'cleanable_kb'     => round( $cleanable_bytes / 1024, 2 ),
    ];
}

/**
 * Helper: Calculate exact storage bytes used by a single revision.
 *
 * @param int $revision_id Revision post ID.
 * @return int Total bytes used by post row and postmeta.
 */
function sba_get_single_revision_bytes( $revision_id ) {
    global $wpdb;

    $revision_id = (int) $revision_id;
    if ( $revision_id <= 0 ) {
        return 0;
    }

    $post_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COALESCE(
            COALESCE(LENGTH(post_content), 0) + 
            COALESCE(LENGTH(post_title), 0) + 
            COALESCE(LENGTH(post_excerpt), 0) + 
            COALESCE(LENGTH(post_name), 0) +
            COALESCE(LENGTH(to_ping), 0) +
            COALESCE(LENGTH(pinged), 0) +
            COALESCE(LENGTH(post_content_filtered), 0) +
            COALESCE(LENGTH(guid), 0),
            0
        )
        FROM {$wpdb->posts}
        WHERE ID = %d
    ", $revision_id ) );

    $meta_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COALESCE(SUM(LENGTH(meta_key) + LENGTH(meta_value)), 0)
        FROM {$wpdb->postmeta}
        WHERE post_id = %d
    ", $revision_id ) );

    return $post_bytes + $meta_bytes;
}

/**
 * 3. Database Scan AJAX Endpoint
 */
add_action( 'wp_ajax_sba_scan_database', 'sba_ajax_scan_database' );
function sba_ajax_scan_database() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    global $wpdb;

    // 1. Calculate Autoload Options Size
    $autoload_query = $wpdb->get_row( $wpdb->prepare( "
        SELECT 
            COUNT(*) as row_count, 
            SUM(LENGTH(option_value)) as total_bytes 
        FROM {$wpdb->options} 
        WHERE autoload IN (%s, %s)
    ", 'yes', 'on' ) );
    $autoload_size_kb = round( ( $autoload_query->total_bytes ?? 0 ) / 1024, 2 );

    // 2. Calculate Post Revisions Storage Bloat
    $revisions_stats = sba_get_revisions_storage_stats();
    $revisions_count = $revisions_stats['total_count'];

    // 3. Count Expired Transients
    $now = time();
    $transients_count = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT COUNT(*) FROM {$wpdb->options}
        WHERE option_name LIKE %s AND option_value < %d
    ", '_transient_timeout_%', $now ) );

    // 4. Inspect Tables & Classify: Core, Active Plugin, Orphaned
    $all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : [];

    // Comprehensive Active Plugin Detection
    $active_plugin_files   = sba_get_active_plugin_files( $all_plugins );
    $inactive_plugin_files = array_diff( array_keys( $all_plugins ), $active_plugin_files );

    $active_matchers   = sba_extract_plugin_matchers( $active_plugin_files, $all_plugins );
    $inactive_matchers = sba_extract_plugin_matchers( $inactive_plugin_files, $all_plugins );

    $core_tables = sba_get_core_table_names();
    $signatures  = sba_get_popular_plugin_signatures();

    $raw_tables = $wpdb->get_results( $wpdb->prepare( "
        SELECT table_name AS `name`,
               TABLE_ROWS AS `row_count`,
               round(((data_length + index_length) / 1024), 2) AS `size_kb`
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
        AND table_name LIKE %s
        ORDER BY (data_length + index_length) DESC
    ", $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

    $tables_data = [];

    foreach ( $raw_tables as $table ) {
        $table_name = sanitize_key( $table->name );

        // Strip WP table prefix (case-insensitive) and multisite subsite prefix (e.g., wp_2_posts -> posts)
        $prefix      = $wpdb->prefix;
        $base_prefix = ! empty( $wpdb->base_prefix ) ? $wpdb->base_prefix : $prefix;

        $table_base = $table_name;
        if ( ! empty( $prefix ) && stripos( $table_base, $prefix ) === 0 ) {
            $table_base = substr( $table_base, strlen( $prefix ) );
        } elseif ( ! empty( $base_prefix ) && stripos( $table_base, $base_prefix ) === 0 ) {
            $table_base = substr( $table_base, strlen( $base_prefix ) );
        }

        $unprefixed = preg_replace( '/^(\d+_)/', '', $table_base );
        if ( stripos( $unprefixed, 'wp_' ) === 0 ) {
            $unprefixed = substr( $unprefixed, 3 );
        }

        $table_base_lower = strtolower( $table_base );
        $unprefixed_lower = strtolower( $unprefixed );

        $status        = 'orphaned';
        $likely_plugin = __( 'Unknown / Custom Table', 'site-bloat-auditor' );
        $plugin_state  = 'unknown'; // 'core', 'active', 'inactive', 'deleted', 'unknown'
        $source        = __( 'Unknown / Leftover', 'site-bloat-auditor' );

        // 1) WordPress Core table check
        $is_core = false;
        foreach ( $core_tables as $ct ) {
            $ct_lower = strtolower( $ct );
            if ( $table_base_lower === $ct_lower || $unprefixed_lower === $ct_lower ) {
                $is_core = true;
                break;
            }
        }

        if ( $is_core ) {
            $status        = 'core';
            $likely_plugin = __( 'WordPress Core', 'site-bloat-auditor' );
            $plugin_state  = 'core';
            $source        = __( 'WordPress Core', 'site-bloat-auditor' );
        } else {
            // 2) Active plugin check - Primary: Matchers from installed active plugins
            $matched_active = false;
            foreach ( $active_matchers as $matcher ) {
                foreach ( $matcher['prefixes'] as $p ) {
                    $p_lower = strtolower( $p );
                    if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                        $status         = 'active_plugin';
                        $likely_plugin  = $matcher['name'];
                        $plugin_state   = 'active';
                        $source         = $matcher['name'];
                        $matched_active = true;
                        break 2;
                    }
                }
            }

            // 2b) Active plugin check - Secondary: Signature dictionary cross-checked against active plugins & runtime
            if ( ! $matched_active ) {
                foreach ( $signatures as $prefix_key => $sig_info ) {
                    $p_lower       = strtolower( $prefix_key );
                    $sig_name      = is_array( $sig_info ) ? $sig_info['name'] : $sig_info;
                    $sig_keywords  = is_array( $sig_info ) ? (array) $sig_info['plugins'] : [];
                    $sig_classes   = is_array( $sig_info ) && ! empty( $sig_info['classes'] ) ? (array) $sig_info['classes'] : [];
                    $sig_constants = is_array( $sig_info ) && ! empty( $sig_info['constants'] ) ? (array) $sig_info['constants'] : [];

                    if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                        $is_active_match     = false;
                        $matched_plugin_name = $sig_name;

                        // Check 1: Runtime classes
                        foreach ( $sig_classes as $cls ) {
                            if ( class_exists( $cls ) ) {
                                $is_active_match = true;
                                break;
                            }
                        }

                        // Check 2: Runtime constants
                        if ( ! $is_active_match ) {
                            foreach ( $sig_constants as $cst ) {
                                if ( defined( $cst ) ) {
                                    $is_active_match = true;
                                    break;
                                }
                            }
                        }

                        // Check 3: Active plugin files / keywords
                        if ( ! $is_active_match ) {
                            foreach ( $active_plugin_files as $active_file ) {
                                $active_file_lower = strtolower( $active_file );
                                foreach ( $sig_keywords as $kw ) {
                                    if ( strpos( $active_file_lower, strtolower( $kw ) ) !== false ) {
                                        $is_active_match = true;
                                        if ( ! empty( $all_plugins[ $active_file ]['Name'] ) ) {
                                            $matched_plugin_name = $all_plugins[ $active_file ]['Name'];
                                        }
                                        break 2;
                                    }
                                }
                            }
                        }

                        // Check 4: is_plugin_active() direct check
                        if ( ! $is_active_match && function_exists( 'is_plugin_active' ) ) {
                            foreach ( $sig_keywords as $kw ) {
                                if ( strpos( $kw, '.php' ) !== false ) {
                                    if ( is_plugin_active( $kw ) || ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $kw ) ) ) {
                                        $is_active_match = true;
                                        if ( ! empty( $all_plugins[ $kw ]['Name'] ) ) {
                                            $matched_plugin_name = $all_plugins[ $kw ]['Name'];
                                        }
                                        break;
                                    }
                                }
                            }
                        }

                        if ( $is_active_match ) {
                            $status         = 'active_plugin';
                            $likely_plugin  = $matched_plugin_name;
                            $plugin_state   = 'active';
                            $source         = $matched_plugin_name;
                            $matched_active = true;
                            break;
                        }
                    }
                }
            }

            // 3) Inactive installed plugin check
            if ( ! $matched_active ) {
                $matched_inactive = false;
                foreach ( $inactive_matchers as $matcher ) {
                    foreach ( $matcher['prefixes'] as $p ) {
                        $p_lower = strtolower( $p );
                        if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                            $status           = 'orphaned';
                            $likely_plugin    = $matcher['name'];
                            $plugin_state     = 'inactive';
                            $source           = sprintf( __( '%s (Inactive)', 'site-bloat-auditor' ), $matcher['name'] );
                            $matched_inactive = true;
                            break 2;
                        }
                    }
                }

                // 3b) Inactive check via signature dictionary against inactive plugins
                if ( ! $matched_inactive ) {
                    foreach ( $signatures as $prefix_key => $sig_info ) {
                        $p_lower      = strtolower( $prefix_key );
                        $sig_name     = is_array( $sig_info ) ? $sig_info['name'] : $sig_info;
                        $sig_keywords = is_array( $sig_info ) ? (array) $sig_info['plugins'] : [];

                        if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                            foreach ( $inactive_plugin_files as $inactive_file ) {
                                $inactive_file_lower = strtolower( $inactive_file );
                                foreach ( $sig_keywords as $kw ) {
                                    if ( strpos( $inactive_file_lower, strtolower( $kw ) ) !== false ) {
                                        $status           = 'orphaned';
                                        $likely_plugin    = ! empty( $all_plugins[ $inactive_file ]['Name'] ) ? $all_plugins[ $inactive_file ]['Name'] : $sig_name;
                                        $plugin_state     = 'inactive';
                                        $source           = sprintf( __( '%s (Inactive)', 'site-bloat-auditor' ), $likely_plugin );
                                        $matched_inactive = true;
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }

                // 4) Uninstalled / Deleted plugin check (signature matches, but plugin is not installed at all)
                if ( ! $matched_inactive ) {
                    foreach ( $signatures as $prefix_key => $sig_info ) {
                        $p_lower  = strtolower( $prefix_key );
                        $sig_name = is_array( $sig_info ) ? $sig_info['name'] : $sig_info;

                        if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                            $status        = 'orphaned';
                            $likely_plugin = $sig_name;
                            $plugin_state  = 'deleted';
                            $source        = $sig_name;
                            break;
                        }
                    }
                }
            }
        }

        $tables_data[] = [
            'name'          => $table_name,
            'size_kb'       => (float) $table->size_kb,
            'row_count'     => (int) ( $table->row_count ?? 0 ),
            'status'        => $status, // 'core', 'active_plugin', 'orphaned'
            'source'        => $source,
            'likely_plugin' => $likely_plugin,
            'plugin_state'  => $plugin_state, // 'core', 'active', 'inactive', 'deleted', 'unknown'
        ];
    }

    $core_count          = count( array_filter( $tables_data, fn( $t ) => $t['status'] === 'core' ) );
    $active_plugin_count = count( array_filter( $tables_data, fn( $t ) => $t['status'] === 'active_plugin' ) );
    $orphaned_count      = count( array_filter( $tables_data, fn( $t ) => $t['status'] === 'orphaned' ) );

    // 5. Plugin Hygiene & Unused Analysis
    $custom_post_types     = get_post_types( [ '_builtin' => false ], 'names' );
    $registered_cpt_counts = [];
    if ( ! empty( $custom_post_types ) ) {
        $cpt_placeholders = implode( ',', array_fill( 0, count( $custom_post_types ), '%s' ) );
        $cpt_results      = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_type, COUNT(*) as cnt FROM {$wpdb->posts} WHERE post_type IN ($cpt_placeholders) GROUP BY post_type",
            ...array_values( $custom_post_types )
        ) );
        foreach ( $custom_post_types as $cpt ) {
            $registered_cpt_counts[ $cpt ] = 0;
        }
        if ( ! empty( $cpt_results ) ) {
            foreach ( $cpt_results as $cpt_row ) {
                $registered_cpt_counts[ $cpt_row->post_type ] = (int) $cpt_row->cnt;
            }
        }
    }

    $plugins_data = [];

    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
        $is_active = in_array( $plugin_file, $active_plugin_files, true );
        $size_mb   = sba_get_plugin_disk_size_mb( $plugin_file );

        if ( ! $is_active ) {
            $status = 'inactive';
            $reason = __( 'Plugin is installed but deactivated', 'site-bloat-auditor' );
        } else {
            $usage_check = sba_check_active_plugin_usage( $plugin_file, $plugin_data, $tables_data, $registered_cpt_counts );
            if ( ! empty( $usage_check['is_unused'] ) ) {
                $status = 'active_unused';
                $reason = $usage_check['reason'];
            } else {
                $status = 'active';
                $reason = $usage_check['reason'];
            }
        }

        $plugins_data[] = [
            'name'        => sanitize_text_field( $plugin_data['Name'] ),
            'version'     => sanitize_text_field( $plugin_data['Version'] ?? '1.0.0' ),
            'author'      => sanitize_text_field( wp_strip_all_tags( $plugin_data['Author'] ?? '' ) ),
            'plugin_file' => sanitize_text_field( $plugin_file ),
            'slug'        => sanitize_title( dirname( $plugin_file ) !== '.' ? dirname( $plugin_file ) : pathinfo( $plugin_file, PATHINFO_FILENAME ) ),
            'status'      => $status, // 'active', 'inactive', 'active_unused'
            'size_mb'     => (float) $size_mb,
            'reason'      => $reason,
        ];
    }

    // Sort plugins: active_unused first, then inactive, then active; within status by size descending
    usort( $plugins_data, function( $a, $b ) {
        $status_order = [ 'active_unused' => 1, 'inactive' => 2, 'active' => 3 ];
        $order_a = $status_order[ $a['status'] ] ?? 4;
        $order_b = $status_order[ $b['status'] ] ?? 4;
        if ( $order_a !== $order_b ) {
            return $order_a - $order_b;
        }
        return $b['size_mb'] <=> $a['size_mb'];
    } );

    $plugins_total            = count( $plugins_data );
    $plugins_active_count     = count( array_filter( $plugins_data, fn( $p ) => $p['status'] === 'active' ) );
    $plugins_inactive_count   = count( array_filter( $plugins_data, fn( $p ) => $p['status'] === 'inactive' ) );
    $plugins_unused_count     = count( array_filter( $plugins_data, fn( $p ) => $p['status'] === 'active_unused' ) );
    $plugins_inactive_size_mb = round( array_sum( array_column( array_filter( $plugins_data, fn( $p ) => $p['status'] === 'inactive' ), 'size_mb' ) ), 2 );
    $plugins_total_size_mb    = round( array_sum( array_column( $plugins_data, 'size_mb' ) ), 2 );

    wp_send_json_success( [
        'autoload_size_kb'           => $autoload_size_kb,
        'autoload_count'             => (int) $autoload_query->row_count,
        'revisions_count'            => $revisions_count,
        'revisions_bytes'            => $revisions_stats['total_bytes'],
        'revisions_size_kb'          => $revisions_stats['total_kb'],
        'revisions_releasable_bytes' => $revisions_stats['releasable_bytes'],
        'revisions_releasable_kb'    => $revisions_stats['releasable_kb'],
        'revisions_cleanable_count'  => $revisions_stats['cleanable_count'],
        'revisions_cleanable_bytes'  => $revisions_stats['cleanable_bytes'],
        'revisions_cleanable_kb'     => $revisions_stats['cleanable_kb'],
        'transients_count'           => $transients_count,
        'total_tables'             => count( $tables_data ),
        'core_count'               => $core_count,
        'active_plugin_count'      => $active_plugin_count,
        'orphaned_count'           => $orphaned_count,
        'tables'                   => $tables_data,
        'plugins'                  => $plugins_data,
        'plugins_total'            => $plugins_total,
        'plugins_active_count'     => $plugins_active_count,
        'plugins_inactive_count'   => $plugins_inactive_count,
        'plugins_unused_count'     => $plugins_unused_count,
        'plugins_inactive_size_mb' => $plugins_inactive_size_mb,
        'plugins_total_size_mb'    => $plugins_total_size_mb,
    ] );
}

/**
 * Helper: Check if a table belongs to WordPress Core or an active plugin.
 *
 * @param string $table_name Sanitized full table name with prefix.
 * @return bool True if protected (Core or Active plugin), false otherwise.
 */
function sba_is_table_protected( $table_name ) {
    global $wpdb;

    // Strip WP table prefix (case-insensitive) and multisite subsite prefix
    $prefix      = $wpdb->prefix;
    $base_prefix = ! empty( $wpdb->base_prefix ) ? $wpdb->base_prefix : $prefix;

    $table_base = $table_name;
    if ( ! empty( $prefix ) && stripos( $table_base, $prefix ) === 0 ) {
        $table_base = substr( $table_base, strlen( $prefix ) );
    } elseif ( ! empty( $base_prefix ) && stripos( $table_base, $base_prefix ) === 0 ) {
        $table_base = substr( $table_base, strlen( $base_prefix ) );
    }

    $unprefixed = preg_replace( '/^(\d+_)/', '', $table_base );
    if ( stripos( $unprefixed, 'wp_' ) === 0 ) {
        $unprefixed = substr( $unprefixed, 3 );
    }

    $table_base_lower = strtolower( $table_base );
    $unprefixed_lower = strtolower( $unprefixed );

    // 1. WordPress Core table check
    $core_tables = sba_get_core_table_names();
    foreach ( $core_tables as $ct ) {
        $ct_lower = strtolower( $ct );
        if ( $table_base_lower === $ct_lower || $unprefixed_lower === $ct_lower ) {
            return true;
        }
    }

    // 2. Active plugins check
    $all_plugins         = function_exists( 'get_plugins' ) ? get_plugins() : [];
    $active_plugin_files = sba_get_active_plugin_files( $all_plugins );

    // Method A: Dynamic matchers from installed active plugins
    $active_matchers = sba_extract_plugin_matchers( $active_plugin_files, $all_plugins );

    foreach ( $active_matchers as $matcher ) {
        foreach ( $matcher['prefixes'] as $p ) {
            $p_lower = strtolower( $p );
            if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
                return true;
            }
        }
    }

    // Method B: Signature dictionary cross-checked against active plugins & runtime
    $signatures = sba_get_popular_plugin_signatures();
    foreach ( $signatures as $prefix_key => $sig_info ) {
        $p_lower       = strtolower( $prefix_key );
        $sig_keywords  = is_array( $sig_info ) ? (array) $sig_info['plugins'] : [];
        $sig_classes   = is_array( $sig_info ) && ! empty( $sig_info['classes'] ) ? (array) $sig_info['classes'] : [];
        $sig_constants = is_array( $sig_info ) && ! empty( $sig_info['constants'] ) ? (array) $sig_info['constants'] : [];

        if ( strpos( $table_base_lower, $p_lower ) === 0 || strpos( $unprefixed_lower, $p_lower ) === 0 ) {
            // Check runtime classes
            foreach ( $sig_classes as $cls ) {
                if ( class_exists( $cls ) ) {
                    return true;
                }
            }
            // Check runtime constants
            foreach ( $sig_constants as $cst ) {
                if ( defined( $cst ) ) {
                    return true;
                }
            }
            // Check active plugin files / keywords
            foreach ( $active_plugin_files as $active_file ) {
                $active_file_lower = strtolower( $active_file );
                foreach ( $sig_keywords as $kw ) {
                    if ( strpos( $active_file_lower, strtolower( $kw ) ) !== false ) {
                        return true;
                    }
                }
            }
            // Direct is_plugin_active check
            if ( function_exists( 'is_plugin_active' ) ) {
                foreach ( $sig_keywords as $kw ) {
                    if ( strpos( $kw, '.php' ) !== false ) {
                        if ( is_plugin_active( $kw ) || ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $kw ) ) ) {
                            return true;
                        }
                    }
                }
            }
        }
    }

    return false;
}

/**
 * 4. Safe Drop Orphaned Table AJAX Endpoint
 */
add_action( 'wp_ajax_sba_drop_table', 'sba_ajax_drop_table' );
add_action( 'wp_ajax_sba_drop_orphaned_table', 'sba_ajax_drop_table' );
function sba_ajax_drop_table() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    // Strict sanitization with sanitize_key
    $table_name = isset( $_POST['table_name'] ) ? sanitize_key( wp_unslash( $_POST['table_name'] ) ) : '';

    if ( empty( $table_name ) ) {
        wp_send_json_error( esc_html__( 'No table name provided.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    // Security: Must match the current WordPress installation prefix
    if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
        wp_send_json_error( esc_html__( 'Security violation: Table does not belong to this WordPress site prefix.', 'site-bloat-auditor' ) );
    }

    // Whitelist verification against existing database tables
    $table_whitelist = $wpdb->get_col( $wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $table_name
    ) );

    if ( empty( $table_whitelist ) || ! in_array( $table_name, $table_whitelist, true ) ) {
        wp_send_json_error( esc_html__( 'Table does not exist or has already been dropped.', 'site-bloat-auditor' ) );
    }

    $safe_table = $table_whitelist[0];

    // Relational Protection: Block dropping WordPress core or active plugin tables
    if ( sba_is_table_protected( $safe_table ) ) {
        wp_send_json_error( esc_html__( 'Cannot drop table: This table is protected.', 'site-bloat-auditor' ) );
    }

    // Safely execute DROP TABLE using whitelisted identifier
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is whitelisted against information_schema.TABLES.
    $dropped = $wpdb->query( "DROP TABLE `{$safe_table}`" );

    if ( false === $dropped ) {
        wp_send_json_error( esc_html__( 'Failed to drop table. Please check database user privileges.', 'site-bloat-auditor' ) );
    }

    wp_send_json_success( [
        'message'    => sprintf( esc_html__( 'Table `%s` was successfully dropped.', 'site-bloat-auditor' ), esc_html( $safe_table ) ),
        'table_name' => $safe_table,
    ] );
}

/**
 * Helper: Retrieve or initialize private SBA temporary directory for backups.
 *
 * @return string Absolute directory path.
 */
function sba_get_temp_dir() {
    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'sba-temp';

    if ( ! file_exists( $temp_dir ) ) {
        wp_mkdir_p( $temp_dir );
        @file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden.' );
        @file_put_contents( $temp_dir . '/.htaccess', 'deny from all' );
    }

    return $temp_dir;
}

/**
 * Helper: Register a temporary file path to be cleaned up on script termination.
 *
 * @param string $file_path Absolute file path.
 */
function sba_register_temp_file_for_cleanup( $file_path ) {
    global $sba_cleanup_files;
    if ( ! is_array( $sba_cleanup_files ) ) {
        $sba_cleanup_files = [];
    }
    $sba_cleanup_files[] = $file_path;

    static $registered = false;
    if ( ! $registered ) {
        $registered = true;
        register_shutdown_function( function() {
            global $sba_cleanup_files;
            if ( ! empty( $sba_cleanup_files ) && is_array( $sba_cleanup_files ) ) {
                foreach ( $sba_cleanup_files as $file ) {
                    if ( ! empty( $file ) && file_exists( $file ) ) {
                        @unlink( $file );
                    }
                }
            }
        } );
    }
}

/**
 * 5. Single Table Automated SQL Backup AJAX Endpoint (Supports SQL and ZIP/GZ)
 */
add_action( 'wp_ajax_sba_backup_table', 'sba_ajax_backup_table' );
function sba_ajax_backup_table() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    // Strict sanitization with sanitize_key
    $table_name = isset( $_POST['table_name'] ) ? sanitize_key( wp_unslash( $_POST['table_name'] ) ) : ( isset( $_GET['table_name'] ) ? sanitize_key( wp_unslash( $_GET['table_name'] ) ) : '' );
    $format     = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'sql';

    if ( empty( $table_name ) ) {
        wp_send_json_error( esc_html__( 'No table name provided.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    // Security: Must match the current WordPress installation prefix
    if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
        wp_send_json_error( esc_html__( 'Security violation: Table does not belong to this WordPress site prefix.', 'site-bloat-auditor' ) );
    }

    // Whitelist verification against existing database tables
    $table_whitelist = $wpdb->get_col( $wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
        $table_name
    ) );

    if ( empty( $table_whitelist ) || ! in_array( $table_name, $table_whitelist, true ) ) {
        wp_send_json_error( esc_html__( 'Table does not exist in the database.', 'site-bloat-auditor' ) );
    }

    $safe_table = $table_whitelist[0];

    // 1. Get SHOW CREATE TABLE schema
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is whitelisted against information_schema.TABLES.
    $create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$safe_table}`", ARRAY_N );
    if ( empty( $create_row ) || ! isset( $create_row[1] ) ) {
        wp_send_json_error( esc_html__( 'Unable to extract schema for this table.', 'site-bloat-auditor' ) );
    }
    $create_table_sql = $create_row[1];

    // 2. Fetch all rows in batched reads using $wpdb->prepare()
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is whitelisted against information_schema.TABLES.
    $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" );

    // 3. Construct complete standalone SQL dump
    $timestamp    = gmdate( 'Y-m-d_His' );
    $base_slug    = sprintf( 'backup_%s_%s', $safe_table, $timestamp );
    $sql_filename = $base_slug . '.sql';

    $sql  = "-- ============================================================\n";
    $sql .= "-- Site Bloat Auditor - Automated Single Table Backup\n";
    $sql .= "-- Table: {$safe_table}\n";
    $sql .= "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
    $sql .= "-- Site URL: " . home_url() . "\n";
    $sql .= "-- WordPress DB Prefix: {$wpdb->prefix}\n";
    $sql .= "-- ============================================================\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
    $sql .= "DROP TABLE IF EXISTS `{$safe_table}`;\n\n";
    $sql .= $create_table_sql . ";\n\n";

    if ( $total_rows > 0 ) {
        $sql .= "-- ------------------------------------------------------------\n";
        $sql .= "-- Data dump for table `{$safe_table}` ({$total_rows} rows)\n";
        $sql .= "-- ------------------------------------------------------------\n";

        $batch_size = 100;
        for ( $offset = 0; $offset < $total_rows; $offset += $batch_size ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$safe_table}` LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            ), ARRAY_A );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $escaped = [];
                foreach ( $row as $val ) {
                    if ( is_null( $val ) ) {
                        $escaped[] = 'NULL';
                    } else {
                        $escaped[] = "'" . esc_sql( $val ) . "'";
                    }
                }
                $sql .= "INSERT INTO `{$safe_table}` VALUES (" . implode( ', ', $escaped ) . ");\n";
            }
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Always generate compressed .zip archive (with gz fallback)
    $temp_dir  = sba_get_temp_dir();
    $unique_id = wp_generate_password( 10, false );

    // 1. Try ZipArchive
    if ( class_exists( 'ZipArchive' ) ) {
        $archive_filename = $base_slug . '.zip';
        $archive_path     = $temp_dir . '/sba_single_' . $unique_id . '.zip';
        sba_register_temp_file_for_cleanup( $archive_path );

        $zip = new ZipArchive();
        if ( true === $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $zip->addFromString( $sql_filename, $sql );
            $zip->close();

            if ( file_exists( $archive_path ) && filesize( $archive_path ) > 0 ) {
                $zip_data = file_get_contents( $archive_path );
                @unlink( $archive_path );

                wp_send_json_success( [
                    'filename'   => $archive_filename,
                    'base64'     => base64_encode( $zip_data ),
                    'mime'       => 'application/zip',
                    'is_zip'     => true,
                    'table_name' => $safe_table,
                    'row_count'  => count( $rows ),
                    'size_bytes' => strlen( $zip_data ),
                ] );
            }
        }
    }

    // 2. Fallback to gzencode
    if ( function_exists( 'gzencode' ) ) {
        $archive_filename = $base_slug . '.sql.gz';
        $gz_data          = gzencode( $sql, 9 );

        if ( false !== $gz_data ) {
            wp_send_json_success( [
                'filename'   => $archive_filename,
                'base64'     => base64_encode( $gz_data ),
                'mime'       => 'application/gzip',
                'is_zip'     => true,
                'table_name' => $safe_table,
                'row_count'  => count( $rows ),
                'size_bytes' => strlen( $gz_data ),
            ] );
        }
    }

    wp_send_json_error( esc_html__( 'Failed to generate compressed table backup archive.', 'site-bloat-auditor' ) );
}

/**
 * 6. Full Database Backup Streaming Export (Supports SQL, ZIP, and GZ)
 */
add_action( 'wp_ajax_sba_export_full_database', 'sba_export_full_database' );
function sba_export_full_database() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    @ini_set( 'memory_limit', '512M' );
    @set_time_limit( 300 );

    global $wpdb;

    $format = isset( $_REQUEST['format'] ) ? sanitize_key( wp_unslash( $_REQUEST['format'] ) ) : 'sql';

    // Safely iterate through all tables matching {$wpdb->prefix}%
    $tables = $wpdb->get_col( $wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME ASC",
        $wpdb->esc_like( $wpdb->prefix ) . '%'
    ) );

    if ( empty( $tables ) ) {
        wp_die( esc_html__( 'No database tables found matching site prefix.', 'site-bloat-auditor' ) );
    }

    // Calculate total DB size in MB
    $total_db_size_mb = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT round(SUM(data_length + index_length) / (1024 * 1024), 2) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",
        $wpdb->esc_like( $wpdb->prefix ) . '%'
    ) );

    $site_name = sanitize_title( get_bloginfo( 'name' ) );
    if ( empty( $site_name ) ) {
        $site_name = 'site';
    }

    $timestamp     = gmdate( 'Y-m-d_His' );
    $base_filename = sprintf( 'full_db_backup_%s_%s', $site_name, $timestamp );
    $sql_filename  = $base_filename . '.sql';

    // Clean output buffers before streaming
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    // COMPRESSED STREAMING (ZIP or GZ)
    $temp_dir  = sba_get_temp_dir();
    $unique_id = wp_generate_password( 12, false );
    $temp_sql  = $temp_dir . '/sba_dump_' . $unique_id . '.sql';
    sba_register_temp_file_for_cleanup( $temp_sql );

    $handle = fopen( $temp_sql, 'w' );
    if ( ! $handle ) {
        wp_die( esc_html__( 'Unable to create temporary backup file.', 'site-bloat-auditor' ) );
    }

    fwrite( $handle, "-- ============================================================\n" );
    fwrite( $handle, "-- Site Bloat Auditor - Full Database SQL Backup\n" );
    fwrite( $handle, "-- Site Name: " . esc_html( get_bloginfo( 'name' ) ) . "\n" );
    fwrite( $handle, "-- Site URL: " . esc_url( home_url() ) . "\n" );
    fwrite( $handle, "-- WordPress Version: " . esc_html( get_bloginfo( 'version' ) ) . "\n" );
    fwrite( $handle, "-- Table Prefix: " . esc_html( $wpdb->prefix ) . "\n" );
    fwrite( $handle, "-- Generated: " . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . " UTC\n" );
    fwrite( $handle, "-- Total Tables: " . count( $tables ) . "\n" );
    fwrite( $handle, "-- ============================================================\n\n" );
    fwrite( $handle, "SET FOREIGN_KEY_CHECKS=0;\n\n" );

    foreach ( $tables as $table ) {
        $safe_table = sanitize_key( $table );
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            $safe_table
        ) );

        if ( ! $table_exists ) {
            continue;
        }

        fwrite( $handle, "-- ------------------------------------------------------------\n" );
        fwrite( $handle, "-- Table structure for `{$safe_table}`\n" );
        fwrite( $handle, "-- ------------------------------------------------------------\n" );
        fwrite( $handle, "DROP TABLE IF EXISTS `{$safe_table}`;\n" );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is whitelisted against information_schema.TABLES.
        $create_row = $wpdb->get_row( "SHOW CREATE TABLE `{$safe_table}`", ARRAY_N );
        if ( ! empty( $create_row ) && isset( $create_row[1] ) ) {
            fwrite( $handle, $create_row[1] . ";\n\n" );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $safe_table is whitelisted against information_schema.TABLES.
        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe_table}`" );
        if ( $total_rows > 0 ) {
            fwrite( $handle, "-- ------------------------------------------------------------\n" );
            fwrite( $handle, "-- Data dump for table `{$safe_table}` ({$total_rows} rows)\n" );
            fwrite( $handle, "-- ------------------------------------------------------------\n" );

            $batch_size = 100;
            for ( $offset = 0; $offset < $total_rows; $offset += $batch_size ) {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM `{$safe_table}` LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ), ARRAY_A );
                if ( empty( $rows ) ) {
                    break;
                }

                $values_list = [];
                foreach ( $rows as $row ) {
                    $escaped_vals = [];
                    foreach ( $row as $val ) {
                        if ( is_null( $val ) ) {
                            $escaped_vals[] = 'NULL';
                        } else {
                            $escaped_vals[] = "'" . esc_sql( $val ) . "'";
                        }
                    }
                    $values_list[] = '(' . implode( ', ', $escaped_vals ) . ')';
                }

                fwrite( $handle, "INSERT INTO `{$safe_table}` VALUES\n" . implode( ",\n", $values_list ) . ";\n" );
            }
            fwrite( $handle, "\n" );
        }
    }

    fwrite( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
    fwrite( $handle, "-- Dump completed on " . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . " UTC\n" );
    fclose( $handle );

    // 1. Check if ZipArchive is available
    if ( class_exists( 'ZipArchive' ) ) {
        $archive_filename = $base_filename . '.zip';
        $archive_path     = $temp_dir . '/sba_archive_' . $unique_id . '.zip';
        sba_register_temp_file_for_cleanup( $archive_path );

        $zip = new ZipArchive();
        if ( true === $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
            $zip->addFile( $temp_sql, $sql_filename );
            $zip->close();
            @unlink( $temp_sql );

            if ( file_exists( $archive_path ) && filesize( $archive_path ) > 0 ) {
                nocache_headers();
                header( 'Content-Type: application/zip' );
                header( 'Content-Disposition: attachment; filename="' . $archive_filename . '"' );
                header( 'Content-Length: ' . filesize( $archive_path ) );
                header( 'Expires: 0' );
                header( 'Pragma: no-cache' );

                $stream = fopen( $archive_path, 'rb' );
                if ( $stream ) {
                    while ( ! feof( $stream ) ) {
                        echo fread( $stream, 65536 );
                        flush();
                    }
                    fclose( $stream );
                }
                @unlink( $archive_path );
                exit;
            }
        }
    }

    // 2. Fallback to GZIP stream (.sql.gz)
    if ( function_exists( 'gzopen' ) ) {
        $archive_filename = $base_filename . '.sql.gz';
        $archive_path     = $temp_dir . '/sba_archive_' . $unique_id . '.sql.gz';
        sba_register_temp_file_for_cleanup( $archive_path );

        $src_stream = fopen( $temp_sql, 'rb' );
        $gz_stream  = gzopen( $archive_path, 'wb9' );

        if ( $src_stream && $gz_stream ) {
            while ( ! feof( $src_stream ) ) {
                gzwrite( $gz_stream, fread( $src_stream, 65536 ) );
            }
            fclose( $src_stream );
            gzclose( $gz_stream );
            @unlink( $temp_sql );

            if ( file_exists( $archive_path ) && filesize( $archive_path ) > 0 ) {
                nocache_headers();
                header( 'Content-Type: application/gzip' );
                header( 'Content-Disposition: attachment; filename="' . $archive_filename . '"' );
                header( 'Content-Length: ' . filesize( $archive_path ) );
                header( 'Expires: 0' );
                header( 'Pragma: no-cache' );

                $stream = fopen( $archive_path, 'rb' );
                if ( $stream ) {
                    while ( ! feof( $stream ) ) {
                        echo fread( $stream, 65536 );
                        flush();
                    }
                    fclose( $stream );
                }
                @unlink( $archive_path );
                exit;
            }
        }
    }

    // If compression is not supported or failed
    @unlink( $temp_sql );
    wp_die( esc_html__( 'Failed to create compressed database backup archive. Ensure PHP ZipArchive or gzopen is enabled.', 'site-bloat-auditor' ) );
}

/**
 * 7. 1-Click Action Cleaner: Purge Expired Transients
 */
add_action( 'wp_ajax_sba_clean_transients', 'sba_ajax_clean_transients' );
function sba_ajax_clean_transients() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    $now = time();

    // 1. Find all expired transient timeout option names in wp_options
    $expired_timeouts = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        '_transient_timeout_%',
        $now
    ) );

    $deleted_count = 0;

    if ( ! empty( $expired_timeouts ) ) {
        $keys_to_delete = [];
        foreach ( $expired_timeouts as $timeout_name ) {
            $keys_to_delete[] = $timeout_name;
            $keys_to_delete[] = str_replace( '_transient_timeout_', '_transient_', $timeout_name );
        }

        $keys_to_delete = array_unique( $keys_to_delete );
        $chunks         = array_chunk( $keys_to_delete, 100 );

        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $result       = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                ...$chunk
            ) );
            if ( false !== $result ) {
                $deleted_count += $result;
            }
        }
    }

    // 2. Multisite sitemeta expired transients support if applicable
    if ( is_multisite() && ! empty( $wpdb->sitemeta ) ) {
        $expired_site_timeouts = $wpdb->get_col( $wpdb->prepare(
            "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND meta_value < %d",
            '_site_transient_timeout_%',
            $now
        ) );

        if ( ! empty( $expired_site_timeouts ) ) {
            $site_keys_to_delete = [];
            foreach ( $expired_site_timeouts as $timeout_name ) {
                $site_keys_to_delete[] = $timeout_name;
                $site_keys_to_delete[] = str_replace( '_site_transient_timeout_', '_site_transient_', $timeout_name );
            }

            $site_keys_to_delete = array_unique( $site_keys_to_delete );
            $site_chunks         = array_chunk( $site_keys_to_delete, 100 );

            foreach ( $site_chunks as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                $result       = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->sitemeta} WHERE meta_key IN ($placeholders)",
                    ...$chunk
                ) );
                if ( false !== $result ) {
                    $deleted_count += $result;
                }
            }
        }
    }

    // Re-count remaining expired transients
    $remaining_transients = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
        '_transient_timeout_%',
        $now
    ) );

    wp_send_json_success( [
        'deleted_count'        => $deleted_count,
        'remaining_transients' => $remaining_transients,
        'message'              => sprintf(
            /* translators: %d: count of deleted transient records */
            esc_html__( 'Successfully purged %d expired transient rows from the database.', 'site-bloat-auditor' ),
            $deleted_count
        ),
    ] );
}

/**
 * 8. 1-Click Action Cleaner: Safely Clean Post Revisions
 */
add_action( 'wp_ajax_sba_clean_revisions', 'sba_ajax_clean_revisions' );
function sba_ajax_clean_revisions() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    // Optional keep parameter: default keep last 3 edits per parent post
    $keep_count = isset( $_POST['keep'] ) ? max( 0, (int) $_POST['keep'] ) : 3;

    // Get all parent posts that have revisions
    $parent_posts = $wpdb->get_col( $wpdb->prepare( "
        SELECT DISTINCT post_parent 
        FROM {$wpdb->posts} 
        WHERE post_type = %s AND post_parent > %d
    ", 'revision', 0 ) );

    $revision_ids_to_delete = [];

    foreach ( $parent_posts as $parent_id ) {
        $parent_id = (int) $parent_id;
        // Fetch all revision IDs for this post, ordered newest to oldest
        $revisions = $wpdb->get_col( $wpdb->prepare( "
            SELECT ID 
            FROM {$wpdb->posts} 
            WHERE post_type = 'revision' AND post_parent = %d 
            ORDER BY ID DESC
        ", $parent_id ) );

        if ( count( $revisions ) > $keep_count ) {
            // Slice to collect revisions older than the last $keep_count
            $excess = array_slice( $revisions, $keep_count );
            foreach ( $excess as $rev_id ) {
                $revision_ids_to_delete[] = (int) $rev_id;
            }
        }
    }

    // Also pick up any orphaned revisions with post_parent = 0 or non-existent parent
    $orphaned_revisions = $wpdb->get_col( $wpdb->prepare( "
        SELECT r.ID 
        FROM {$wpdb->posts} r 
        LEFT JOIN {$wpdb->posts} p ON r.post_parent = p.ID 
        WHERE r.post_type = %s AND (r.post_parent = %d OR p.ID IS NULL)
    ", 'revision', 0 ) );

    if ( ! empty( $orphaned_revisions ) ) {
        foreach ( $orphaned_revisions as $orph_id ) {
            $revision_ids_to_delete[] = (int) $orph_id;
        }
    }

    $revision_ids_to_delete = array_unique( $revision_ids_to_delete );
    $deleted_count          = 0;

    if ( ! empty( $revision_ids_to_delete ) ) {
        $chunks = array_chunk( $revision_ids_to_delete, 100 );
        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            // 1. Delete associated postmeta
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                ...$chunk
            ) );

            // 2. Delete associated term relationships
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
                ...$chunk
            ) );

            // 3. Delete revision records from wp_posts
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'revision'",
                ...$chunk
            ) );

            if ( false !== $result ) {
                $deleted_count += $result;
            }
        }
    }

    // Recalculate remaining revisions & storage stats
    $updated_stats = sba_get_revisions_storage_stats();

    wp_send_json_success( [
        'deleted_count'       => $deleted_count,
        'remaining_revisions' => $updated_stats['total_count'],
        'stats'               => $updated_stats,
        'message'             => sprintf(
            /* translators: %d: count of deleted revisions */
            esc_html__( 'Successfully cleaned %d post revision rows (preserved last 3 edits per post).', 'site-bloat-auditor' ),
            $deleted_count
        ),
    ] );
}

/**
 * 8b. Fetch All Post Revisions for Site Bloat Auditor
 */
add_action( 'wp_ajax_sba_get_post_revisions', 'sba_ajax_get_post_revisions' );
function sba_ajax_get_post_revisions() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    $raw_revisions = $wpdb->get_results( $wpdb->prepare( "
        SELECT 
            p.ID,
            p.post_parent,
            p.post_title,
            p.post_date,
            p.post_modified,
            p.post_author,
            parent.post_title AS parent_title,
            parent.post_type AS parent_type,
            (
                COALESCE(LENGTH(p.post_content), 0) + 
                COALESCE(LENGTH(p.post_title), 0) + 
                COALESCE(LENGTH(p.post_excerpt), 0) + 
                COALESCE(LENGTH(p.post_name), 0) +
                COALESCE(LENGTH(p.to_ping), 0) +
                COALESCE(LENGTH(p.pinged), 0) +
                COALESCE(LENGTH(p.post_content_filtered), 0) +
                COALESCE(LENGTH(p.guid), 0)
            ) AS post_bytes
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
        WHERE p.post_type = %s
        ORDER BY p.ID DESC
    ", 'revision' ) );

    // Batch-fetch postmeta bytes
    $rev_ids = array_map( fn( $r ) => (int) $r->ID, (array) $raw_revisions );
    $meta_bytes_map = [];

    if ( ! empty( $rev_ids ) ) {
        $chunks = array_chunk( $rev_ids, 200 );
        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $meta_rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT post_id, SUM(LENGTH(meta_key) + LENGTH(meta_value)) AS meta_bytes
                FROM {$wpdb->postmeta}
                WHERE post_id IN ($placeholders)
                GROUP BY post_id
            ", ...$chunk ) );
            foreach ( $meta_rows as $mr ) {
                $meta_bytes_map[ (int) $mr->post_id ] = (int) $mr->meta_bytes;
            }
        }
    }

    $formatted_revisions = [];
    $total_bytes = 0;

    foreach ( $raw_revisions as $rev ) {
        $rev_id = (int) $rev->ID;
        $p_bytes = (int) $rev->post_bytes;
        $m_bytes = $meta_bytes_map[ $rev_id ] ?? 0;
        $rev_total_bytes = $p_bytes + $m_bytes;
        $total_bytes += $rev_total_bytes;

        $author = get_userdata( (int) $rev->post_author );
        $author_name = $author ? $author->display_name : __( 'Unknown', 'site-bloat-auditor' );

        $parent_title = ! empty( $rev->parent_title ) ? $rev->parent_title : '';
        if ( empty( $parent_title ) ) {
            $parent_title = $rev->post_parent > 0 
                ? sprintf( __( 'Parent Post #%d (Untitled)', 'site-bloat-auditor' ), $rev->post_parent ) 
                : __( '(Orphaned / No Parent)', 'site-bloat-auditor' );
        }

        $formatted_revisions[] = [
            'id'           => $rev_id,
            'parent_id'    => (int) $rev->post_parent,
            'parent_title' => $parent_title,
            'parent_type'  => ! empty( $rev->parent_type ) ? $rev->parent_type : 'post',
            'title'        => ! empty( $rev->post_title ) ? $rev->post_title : sprintf( __( 'Revision #%d', 'site-bloat-auditor' ), $rev_id ),
            'date'         => get_date_from_gmt( $rev->post_modified, 'Y-m-d H:i' ),
            'human_date'   => human_time_diff( strtotime( $rev->post_modified ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'site-bloat-auditor' ),
            'author'       => $author_name,
            'total_bytes'  => $rev_total_bytes,
            'size_kb'      => round( $rev_total_bytes / 1024, 2 ),
        ];
    }

    $stats = [
        'total_count'      => count( $formatted_revisions ),
        'total_bytes'      => $total_bytes,
        'total_kb'         => round( $total_bytes / 1024, 2 ),
        'releasable_bytes' => $total_bytes,
        'releasable_kb'    => round( $total_bytes / 1024, 2 ),
    ];

    wp_send_json_success( [
        'revisions' => $formatted_revisions,
        'stats'     => $stats,
    ] );
}

/**
 * 8c. Delete a Single Post Revision from Site Bloat Auditor
 */
add_action( 'wp_ajax_sba_delete_single_revision', 'sba_ajax_delete_single_revision' );
function sba_ajax_delete_single_revision() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    $revision_id = isset( $_POST['revision_id'] ) ? (int) $_POST['revision_id'] : 0;
    if ( $revision_id <= 0 ) {
        wp_send_json_error( esc_html__( 'Invalid revision ID.', 'site-bloat-auditor' ) );
    }

    // Verify it is a revision
    $post_type = $wpdb->get_var( $wpdb->prepare( "
        SELECT post_type FROM {$wpdb->posts} WHERE ID = %d
    ", $revision_id ) );

    if ( 'revision' !== $post_type ) {
        wp_send_json_error( esc_html__( 'Selected record is not a post revision.', 'site-bloat-auditor' ) );
    }

    // Calculate bytes released before deletion
    $bytes_released = sba_get_single_revision_bytes( $revision_id );

    // 1. Delete associated postmeta
    $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $revision_id ], [ '%d' ] );

    // 2. Delete associated term relationships
    $wpdb->delete( $wpdb->term_relationships, [ 'object_id' => $revision_id ], [ '%d' ] );

    // 3. Delete revision post row
    $deleted = $wpdb->delete( $wpdb->posts, [ 'ID' => $revision_id, 'post_type' => 'revision' ], [ '%d', '%s' ] );

    clean_post_cache( $revision_id );

    if ( false === $deleted ) {
        wp_send_json_error( esc_html__( 'Failed to delete revision from database.', 'site-bloat-auditor' ) );
    }

    // Recalculate remaining stats
    $updated_stats = sba_get_revisions_storage_stats();

    wp_send_json_success( [
        'deleted_id'       => $revision_id,
        'bytes_released'   => $bytes_released,
        'size_kb_released' => round( $bytes_released / 1024, 2 ),
        'stats'            => $updated_stats,
        'message'          => sprintf(
            /* translators: 1: revision ID, 2: bytes released, 3: KB released */
            esc_html__( 'Revision #%1$d deleted successfully. Released %2$s bytes (%3$s KB).', 'site-bloat-auditor' ),
            $revision_id,
            number_format( $bytes_released ),
            round( $bytes_released / 1024, 2 )
        ),
    ] );
}

/**
 * 8d. Bulk Delete Post Revisions
 */
add_action( 'wp_ajax_sba_delete_bulk_revisions', 'sba_ajax_delete_bulk_revisions' );
function sba_ajax_delete_bulk_revisions() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    global $wpdb;

    $revision_ids = isset( $_POST['revision_ids'] ) ? array_map( 'intval', (array) $_POST['revision_ids'] ) : [];
    $revision_ids = array_filter( array_unique( $revision_ids ) );

    if ( empty( $revision_ids ) ) {
        wp_send_json_error( esc_html__( 'No revisions selected.', 'site-bloat-auditor' ) );
    }

    $total_bytes_released = 0;
    $deleted_count = 0;

    $chunks = array_chunk( $revision_ids, 100 );
    foreach ( $chunks as $chunk ) {
        $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

        // Calculate bytes released
        $posts_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COALESCE(SUM(
                COALESCE(LENGTH(post_content), 0) + 
                COALESCE(LENGTH(post_title), 0) + 
                COALESCE(LENGTH(post_excerpt), 0) + 
                COALESCE(LENGTH(post_name), 0) +
                COALESCE(LENGTH(to_ping), 0) +
                COALESCE(LENGTH(pinged), 0) +
                COALESCE(LENGTH(post_content_filtered), 0) +
                COALESCE(LENGTH(guid), 0)
            ), 0)
            FROM {$wpdb->posts}
            WHERE ID IN ($placeholders) AND post_type = 'revision'
        ", ...$chunk ) );

        $meta_bytes = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT COALESCE(SUM(LENGTH(meta_key) + LENGTH(meta_value)), 0)
            FROM {$wpdb->postmeta}
            WHERE post_id IN ($placeholders)
        ", ...$chunk ) );

        $total_bytes_released += ( $posts_bytes + $meta_bytes );

        // Delete postmeta
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
            ...$chunk
        ) );

        // Delete term_relationships
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($placeholders)",
            ...$chunk
        ) );

        // Delete posts
        $res = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'revision'",
            ...$chunk
        ) );

        if ( false !== $res ) {
            $deleted_count += $res;
        }

        foreach ( $chunk as $cid ) {
            clean_post_cache( $cid );
        }
    }

    $updated_stats = sba_get_revisions_storage_stats();

    wp_send_json_success( [
        'deleted_count'    => $deleted_count,
        'bytes_released'   => $total_bytes_released,
        'size_kb_released' => round( $total_bytes_released / 1024, 2 ),
        'stats'            => $updated_stats,
        'message'          => sprintf(
            /* translators: 1: count, 2: bytes, 3: KB */
            esc_html__( 'Successfully deleted %1$d revision(s). Released %2$s bytes (%3$s KB).', 'site-bloat-auditor' ),
            $deleted_count,
            number_format( $total_bytes_released ),
            round( $total_bytes_released / 1024, 2 )
        ),
    ] );
}

/**
 * 9. Plugin Audit AJAX Endpoint: Scan all plugins and verify with WordPress.org API
 */
add_action( 'wp_ajax_sba_scan_plugins', 'sba_ajax_scan_plugins' );
function sba_ajax_scan_plugins() {
    sba_ajax_scan_plugins_audit();
}

/**
 * 10. Register Custom Post Status for Quarantined Media
 */
add_action( 'init', 'sba_register_quarantine_post_status' );
function sba_register_quarantine_post_status() {
    register_post_status( 'sba-quarantine', [
        'label'                     => esc_html_x( 'Quarantined', 'post status', 'site-bloat-auditor' ),
        'public'                    => false,
        'exclude_from_search'       => true,
        'show_in_admin_all_list'    => false,
        'show_in_admin_status_list' => false,
        'label_count'               => _n_noop(
            'Quarantined <span class="count">(%s)</span>',
            'Quarantined <span class="count">(%s)</span>',
            'site-bloat-auditor'
        ),
    ] );
}

/**
 * Helper: Retrieve or initialize the quarantine directory path with security hardening.
 *
 * @return string Full path to the quarantine directory.
 */
function sba_get_quarantine_dir() {
    $upload_dir     = wp_upload_dir();
    $quarantine_dir = trailingslashit( $upload_dir['basedir'] ) . 'sba-quarantine';

    if ( ! file_exists( $quarantine_dir ) ) {
        wp_mkdir_p( $quarantine_dir );

        // Security: Block direct PHP script execution inside the quarantine directory
        $htaccess = $quarantine_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $htaccess_content = "# Site Bloat Auditor Quarantine Security Guard\n"
                              . "Options -Indexes\n"
                              . "<FilesMatch \"\.(php|phtml|php3|php4|php5|phps|phar|inc|cgi|pl)\$\">\n"
                              . "Order Deny,Allow\n"
                              . "Deny from all\n"
                              . "</FilesMatch>\n";
            @file_put_contents( $htaccess, $htaccess_content );
        }

        // Security: Silence is golden index fallback
        $index = $quarantine_dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    return wp_normalize_path( $quarantine_dir );
}

/**
 * Helper: Retrieve the quarantine directory base URL.
 *
 * @return string URL to the quarantine directory.
 */
function sba_get_quarantine_url() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['baseurl'] ) . 'sba-quarantine';
}

/**
 * Helper: Recursively calculate disk size of a directory in bytes, excluding specified subfolder.
 *
 * @param string $dir Full path to directory.
 * @param string $exclude_sub Name of subfolder to exclude from computation.
 * @return int Total size in bytes.
 */
function sba_get_directory_size_bytes( $dir, $exclude_sub = 'sba-quarantine' ) {
    $size = 0;
    if ( ! is_dir( $dir ) || ! sba_is_readable( $dir ) ) {
        return 0;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $path = wp_normalize_path( $file->getPathname() );
                if ( $exclude_sub && false !== strpos( $path, '/' . $exclude_sub . '/' ) ) {
                    continue;
                }
                $size += $file->getSize();
            }
        }
    } catch ( Exception $e ) {
        // Fallback gracefully if directory is unreadable
    }

    return $size;
}

/**
 * Security: Strictly validate that a target file path resides inside an allowed base directory.
 *
 * Resolves path traversal (e.g. '../', symlinks) to protect system files.
 *
 * @param string $file_path Target file path.
 * @param string $allowed_base_dir Allowed base directory.
 * @return bool True if strictly contained within base directory.
 */
function sba_validate_file_in_dir( $file_path, $allowed_base_dir ) {
    if ( empty( $file_path ) || empty( $allowed_base_dir ) ) {
        return false;
    }

    // Block null byte injection
    if ( false !== strpos( $file_path, "\0" ) ) {
        return false;
    }

    $real_base = realpath( $allowed_base_dir );
    if ( false === $real_base ) {
        return false;
    }
    $real_base_norm = trailingslashit( wp_normalize_path( $real_base ) );

    // If file exists, check exact canonical path
    if ( file_exists( $file_path ) ) {
        $real_file = realpath( $file_path );
        if ( false === $real_file ) {
            return false;
        }
        $real_file_norm = wp_normalize_path( $real_file );
        return ( 0 === strpos( $real_file_norm, $real_base_norm ) );
    }

    // Path does not exist yet: Resolve dot segments (../ and ./)
    $normalized = wp_normalize_path( $file_path );

    // Check for drive letter on Windows (e.g., C:/)
    $drive = '';
    if ( preg_match( '/^([a-zA-Z]:\/)(.*)$/', $normalized, $matches ) ) {
        $drive      = $matches[1];
        $normalized = $matches[2];
    } elseif ( 0 === strpos( $normalized, '/' ) ) {
        $drive      = '/';
        $normalized = substr( $normalized, 1 );
    }

    $segments = explode( '/', $normalized );
    $resolved = [];
    foreach ( $segments as $seg ) {
        if ( '' === $seg || '.' === $seg ) {
            continue;
        }
        if ( '..' === $seg ) {
            if ( empty( $resolved ) ) {
                return false; // Traversal beyond root
            }
            array_pop( $resolved );
        } else {
            $resolved[] = $seg;
        }
    }
    $resolved_path = $drive . implode( '/', $resolved );

    // Walk up until we find an existing ancestor directory
    $check_dir = $resolved_path;
    while ( ! empty( $check_dir ) && ! file_exists( $check_dir ) ) {
        $parent = dirname( $check_dir );
        if ( $parent === $check_dir ) {
            break;
        }
        $check_dir = $parent;
    }

    if ( empty( $check_dir ) || ! file_exists( $check_dir ) ) {
        return false;
    }

    $real_ancestor = realpath( $check_dir );
    if ( false === $real_ancestor ) {
        return false;
    }
    $real_ancestor_norm = trailingslashit( wp_normalize_path( $real_ancestor ) );

    // Ancestor must be inside or equal to real base
    if ( 0 !== strpos( $real_ancestor_norm, $real_base_norm ) && $real_ancestor_norm !== $real_base_norm ) {
        return false;
    }

    // The full resolved prospective path must strictly start with real_base_norm
    $norm_resolved = trailingslashit( wp_normalize_path( $resolved_path ) );
    return ( 0 === strpos( wp_normalize_path( $resolved_path ), rtrim( $real_base_norm, '/' ) . '/' ) );
}

/**
 * Safe atomic file move with verification and fallback.
 *
 * @param string $src Source file path.
 * @param string $dst Destination file path.
 * @param bool   $allow_overwrite Whether existing destination file can be replaced.
 * @return array Array with ['success' => bool, 'error' => string, 'bytes' => int].
 */
function sba_safe_move_file( $src, $dst, $allow_overwrite = false ) {
    if ( ! file_exists( $src ) || ! sba_is_readable( $src ) ) {
        return [
            'success' => false,
            'error'   => sprintf( esc_html__( 'Source file does not exist or is unreadable: %s', 'site-bloat-auditor' ), basename( $src ) ),
            'bytes'   => 0,
        ];
    }

    if ( ! $allow_overwrite && file_exists( $dst ) ) {
        return [
            'success' => false,
            'error'   => sprintf( esc_html__( 'Destination file already exists (collision detected): %s', 'site-bloat-auditor' ), basename( $dst ) ),
            'bytes'   => 0,
        ];
    }

    $dst_dir = dirname( $dst );
    if ( ! is_dir( $dst_dir ) ) {
        if ( ! wp_mkdir_p( $dst_dir ) ) {
            return [
                'success' => false,
                'error'   => sprintf( esc_html__( 'Failed to create destination directory: %s', 'site-bloat-auditor' ), $dst_dir ),
                'bytes'   => 0,
            ];
        }
    }

    $src_size = (int) filesize( $src );

    // Attempt direct rename
    $moved = @rename( $src, $dst );

    // If rename failed (e.g. across partitions/devices or permission boundary), try copy + unlink
    if ( ! $moved ) {
        if ( @copy( $src, $dst ) ) {
            // Verify destination size before unlinking source
            if ( file_exists( $dst ) && (int) filesize( $dst ) === $src_size ) {
                @unlink( $src );
                $moved = true;
            } else {
                @unlink( $dst ); // Clean up failed partial copy
            }
        }
    }

    // Verify destination exists and is non-empty / matching size
    if ( $moved && file_exists( $dst ) && (int) filesize( $dst ) === $src_size ) {
        return [
            'success' => true,
            'error'   => '',
            'bytes'   => $src_size,
        ];
    }

    return [
        'success' => false,
        'error'   => sprintf( esc_html__( 'Failed to move file to destination: %s', 'site-bloat-auditor' ), basename( $dst ) ),
        'bytes'   => 0,
    ];
}

/**
 * Generate a verified WordPress attachment editor URL.
 *
 * Strictly validates that the attachment ID is a positive integer and that the post
 * type is 'attachment' before generating the link via get_edit_post_link().
 *
 * @param int $attachment_id Attachment post ID.
 * @return string Escaped edit URL, or empty string if invalid or uneditable.
 */
function sba_get_attachment_edit_url( $attachment_id ) {
    if ( ! is_numeric( $attachment_id ) ) {
        return '';
    }
    $attachment_id = (int) $attachment_id;
    if ( $attachment_id <= 0 ) {
        return '';
    }

    // Strictly validate that the post exists and its post_type is 'attachment'
    if ( 'attachment' !== get_post_type( $attachment_id ) ) {
        return '';
    }

    // Prefer WordPress native get_edit_post_link()
    $edit_link = get_edit_post_link( $attachment_id, 'raw' );
    if ( ! empty( $edit_link ) ) {
        $edit_link = html_entity_decode( $edit_link, ENT_QUOTES, 'UTF-8' );
        return esc_url_raw( $edit_link );
    }

    // Fallback: If current user can edit post, construct standard edit URL for this attachment
    if ( current_user_can( 'edit_post', $attachment_id ) ) {
        return esc_url_raw( admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ) );
    }

    return '';
}

/**
 * 11. Media Files Audit AJAX Action: Scan, analyze usage, and compute reclaimable storage.
 */
add_action( 'wp_ajax_sba_scan_media_files', 'sba_ajax_scan_media_files' );
function sba_ajax_scan_media_files() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    @ini_set( 'memory_limit', '512M' );
    @set_time_limit( 300 );

    global $wpdb;

    // 1. Query all active media attachments
    $attachments = $wpdb->get_results( $wpdb->prepare( "
        SELECT ID, post_title, post_name, post_date, post_parent, guid, post_mime_type
        FROM {$wpdb->posts}
        WHERE post_type = %s AND post_status = %s
        ORDER BY ID DESC
    ", 'attachment', 'inherit' ) );

    $upload_dir          = wp_upload_dir();
    $quarantine_dir      = sba_get_quarantine_dir();
    $total_upload_bytes  = sba_get_directory_size_bytes( $upload_dir['basedir'], 'sba-quarantine' );
    $total_media_size_mb = round( $total_upload_bytes / ( 1024 * 1024 ), 2 );
    $quarantine_bytes    = is_dir( $quarantine_dir ) ? sba_get_directory_size_bytes( $quarantine_dir ) : 0;
    $quarantined_size_mb = round( $quarantine_bytes / ( 1024 * 1024 ), 2 );
    $quarantined_items   = array_values( (array) get_option( 'sba_quarantined_files', [] ) );
    $actual_reclaimed_b  = (int) get_option( 'sba_reclaimed_media_bytes', 0 );
    $actual_reclaimed_mb = round( $actual_reclaimed_b / ( 1024 * 1024 ), 2 );

    $optimizer   = new SBA_Image_Optimizer();
    $env_support = $optimizer->check_environment_support();

    if ( empty( $attachments ) ) {
        wp_send_json_success( [
            'total_media_size_mb'      => $total_media_size_mb,
            'unused_count'             => 0,
            'oversized_count'          => 0,
            'optimized_count'          => 0,
            'duplicates_count'         => 0,
            'accessibility_score'      => 100,
            'total_scanned'                     => 0,
            'potentially_reclaimable'           => 0.00,
            'potentially_reclaimable_mb'        => 0.00,
            'reclaimable_space_mb'              => 0.00,
            'potential_savings_mb'              => 0.00,
            'estimated_optimization_savings_mb' => 0.00,
            'backup_storage_consumed_mb'        => $quarantined_size_mb,
            'backup_storage_consumed_formatted' => size_format( $quarantine_bytes, 2 ),
            'quarantined_size_mb'               => $quarantined_size_mb,
            'actual_reclaimed_mb'               => $actual_reclaimed_mb,
            'all_items'                => [],
            'unused_items'             => [],
            'oversized_items'          => [],
            'optimized_items'          => [],
            'duplicates'               => [],
            'accessibility'            => [
                'summary' => [
                    'total_images_audited'     => 0,
                    'with_descriptive_alt'     => 0,
                    'missing_alt_attribute'    => 0,
                    'empty_alt_decorative'     => 0,
                    'unhelpful_repetitive_alt' => 0,
                    'undescriptive_filenames'  => 0,
                    'broken_files'             => 0,
                    'accessibility_score'      => 100,
                ],
                'items' => [],
            ],
            'discrepancies'            => [
                'missing_originals' => [],
                'missing_subsizes'  => [],
                'unattached_files'  => [],
                'metadata_issues'   => [],
            ],
            'quarantined_items'        => $quarantined_items,
            'quarantined_count'        => count( $quarantined_items ),
            'env_support'              => $env_support,
        ] );
    }

    // 2. Run Evidence-Based Usage Detection across Gutenberg, posts, themes, ACF, Elementor, widgets
    $usage_detector = new SBA_Usage_Detector();
    $usage_results  = $usage_detector->scan_references( $attachments );

    // 3. Scan Inventory & Physical Filesystem via SBA_Media_Inventory
    $inventory_engine = new SBA_Media_Inventory();
    $inventory_items  = $inventory_engine->scan_attachments();
    $discrepancies    = $inventory_engine->get_discrepancies();

    // 4. Duplicate Detection via SHA-256 Cryptographic Hashing
    $duplicate_detector = new SBA_Duplicate_Detector();
    $duplicate_groups   = $duplicate_detector->detect_duplicates( $inventory_items, $usage_results );
    $duplicate_hash_map = [];
    foreach ( $duplicate_groups as $dg ) {
        foreach ( $dg['items'] as $ditem ) {
            $duplicate_hash_map[ (int) $ditem['id'] ] = [
                'hash'           => $dg['hash'],
                'group_count'    => $dg['count'],
                'recommendation' => $dg['recommendation'],
            ];
        }
    }

    // 5. Accessibility & Metadata Quality Audit via SBA_Accessibility_Auditor
    $accessibility_auditor    = new SBA_Accessibility_Auditor();
    $accessibility_report     = $accessibility_auditor->audit_accessibility( $inventory_items );
    $accessibility_issues_map = [];
    foreach ( $accessibility_report['items'] as $aitem ) {
        $accessibility_issues_map[ (int) $aitem['id'] ] = $aitem;
    }

    // 6. Compile items with full metrics, paths, and optimization options
    $all_scanned_items = [];
    $quarantined_files = (array) get_option( 'sba_quarantined_files', [] );
    $backups_map       = (array) get_option( SBA_Image_Optimizer::BACKUP_OPTION_KEY, [] );

    foreach ( $inventory_items as $inv_item ) {
        $att_id = (int) $inv_item['id'];
        if ( isset( $quarantined_files[ $att_id ] ) ) {
            continue;
        }

        $att_post = get_post( $att_id );
        if ( ! $att_post ) {
            continue;
        }

        $meta         = wp_get_attachment_metadata( $att_id );
        $filename     = $inv_item['name'];
        $rel_path     = $inv_item['relative_path'];
        $display_path = $inv_item['file_path'];

        $usage_data     = $usage_results[ $att_id ] ?? null;
        $usage_places   = [];
        $classification = 'no_references_found';
        $category_label = esc_html__( 'No References Found (Review Required)', 'site-bloat-auditor' );
        $badge_class    = 'sba-pill-orphaned';
        $badge_text     = esc_html__( 'Review & Quarantine', 'site-bloat-auditor' );

        if ( $usage_data ) {
            $classification = $usage_data['classification'];
            $category_label = $usage_data['label'];
            $badge_class    = $usage_data['badge_class'];

            foreach ( $usage_data['references'] as $ref ) {
                $usage_places[] = [
                    'title'       => $ref['source_title'],
                    'type'        => $ref['usage_type'],
                    'post_id'     => $ref['source_id'],
                    'confidence'  => $ref['confidence'],
                    'post_status' => $ref['post_status'] ?? 'publish',
                    'evidence'    => $ref['evidence'],
                ];
            }
        }

        $is_used = ( 'no_references_found' !== $classification && ! empty( $usage_places ) );
        $is_hero = false;
        foreach ( $usage_places as $up ) {
            if ( 'Featured' === ( $up['type'] ?? '' ) ) {
                $is_hero = true;
                break;
            }
        }

        // Analyze image optimization opportunity
        $opt_analysis = $optimizer->analyze_image( $att_id, $meta, $is_hero );
        $is_oversized = $opt_analysis['is_oversized'];
        $has_backup   = isset( $backups_map[ $att_id ] );

        // Determine item category
        if ( ! $is_used ) {
            $category   = 'unused';
            $badge_text = esc_html__( 'Review & Quarantine', 'site-bloat-auditor' );
        } elseif ( $is_oversized ) {
            $category    = 'oversized';
            $badge_class = 'sba-pill-warning';
            $badge_text  = sprintf(
                /* translators: %s: recommended resolution */
                esc_html__( 'Scale down to %s', 'site-bloat-auditor' ),
                $opt_analysis['recommended_dim']
            );
        } else {
            $category    = 'optimized';
            $badge_class = 'sba-pill-active';
            $badge_text  = esc_html__( 'Web Optimized', 'site-bloat-auditor' );
        }

        // Preview URL
        $preview_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
        if ( ! $preview_url ) {
            $preview_url = wp_get_attachment_url( $att_id );
        }
        if ( ! $preview_url ) {
            $preview_url = includes_url( 'images/media/default.png' );
        }

        // Priority 4: Differentiate thumbnail sub-sizes (responsive, theme registered, core admin, legacy)
        $responsive_keys        = [ 'medium', 'medium_large', 'large' ];
        $core_admin_keys        = [ 'thumbnail', '1536x1536', '2048x2048' ];
        $registered_theme_sizes = function_exists( 'wp_get_registered_image_subsizes' ) ? wp_get_registered_image_subsizes() : [];
        $item_subsizes          = [];

        foreach ( $inv_item['subsizes'] as $sub ) {
            $size_key = $sub['size_key'];
            if ( in_array( $size_key, $responsive_keys, true ) ) {
                if ( $is_used ) {
                    $sub_status = 'protected_responsive';
                    $sub_note   = esc_html__( 'Used by mobile/tablet responsive srcset', 'site-bloat-auditor' );
                } else {
                    $sub_status = 'unreferenced_responsive';
                    $sub_note   = esc_html__( 'Responsive srcset variant. Parent media has no references in scanned content; review before pruning.', 'site-bloat-auditor' );
                }
            } elseif ( in_array( $size_key, $core_admin_keys, true ) ) {
                $sub_status = 'core_standard_size';
                $sub_note   = esc_html__( 'Standard WordPress size (used in admin or media lists); review before pruning.', 'site-bloat-auditor' );
            } elseif ( isset( $registered_theme_sizes[ $size_key ] ) ) {
                $sub_status = 'unverified_template_usage';
                $sub_note   = esc_html__( 'Registered by active theme/plugin. Template usage cannot be verified via post content alone; review before pruning.', 'site-bloat-auditor' );
            } else {
                $sub_status = 'potential_unused';
                $sub_note   = esc_html__( 'Legacy/unregistered image size not defined in current theme or active plugins. Candidate for cleanup (regenerable if needed).', 'site-bloat-auditor' );
            }

            $item_subsizes[] = array_merge( $sub, [
                'status' => $sub_status,
                'note'   => $sub_note,
            ] );
        }

        $all_scanned_items[] = [
            'id'                          => $att_id,
            'name'                        => sanitize_text_field( $filename ),
            'title'                       => sanitize_text_field( $att_post->post_title ?: $filename ),
            'relative_path'               => sanitize_text_field( $rel_path ),
            'file_path'                   => sanitize_text_field( $display_path ),
            'preview_url'                 => esc_url_raw( $preview_url ),
            'category'                    => $category,
            'category_label'              => $category_label,
            'classification'              => $classification,
            'original_width'              => $opt_analysis['original_width'],
            'original_height'             => $opt_analysis['original_height'],
            'original_dimensions'         => $opt_analysis['original_dim'],
            'recommended_width'           => $opt_analysis['recommended_width'],
            'recommended_height'          => $opt_analysis['recommended_height'],
            'recommended_dimensions'      => $opt_analysis['recommended_dim'],
            'is_oversized'                => $is_oversized,
            'has_backup'                  => $has_backup,
            'file_size_bytes'             => $inv_item['total_item_bytes'],
            'file_size_formatted'         => $inv_item['total_size_formatted'],
            'estimated_size_bytes'        => $opt_analysis['estimated_size_bytes'],
            'estimated_size_formatted'    => $opt_analysis['estimated_size_fmt'],
            'potential_savings_bytes'     => $opt_analysis['savings_bytes'],
            'potential_savings_formatted' => $opt_analysis['savings_fmt'],
            'subsize_count'               => $inv_item['subsize_count'],
            'subsizes'                    => $item_subsizes,
            'upload_date'                 => $inv_item['upload_date'],
            'mime_type'                   => sanitize_text_field( $att_post->post_mime_type ),
            'usage_places'                => $usage_places,
            'recommendation_badge'        => $badge_text,
            'badge_class'                 => $badge_class,
            'edit_url'                    => sba_get_attachment_edit_url( $att_id ),
            'is_duplicate'                => isset( $duplicate_hash_map[ $att_id ] ),
            'duplicate_info'              => $duplicate_hash_map[ $att_id ] ?? null,
            'accessibility'               => $accessibility_issues_map[ $att_id ] ?? null,
            'alt_text'                    => $inv_item['alt_text'],
            'original_status'             => $inv_item['original_status'],
        ];
    }

    // 7. Compute category breakdowns and strict separated storage metrics (Priority 2)
    $unused_items    = array_values( array_filter( $all_scanned_items, fn( $i ) => $i['category'] === 'unused' ) );
    $oversized_items = array_values( array_filter( $all_scanned_items, fn( $i ) => $i['category'] === 'oversized' ) );
    $optimized_items = array_values( array_filter( $all_scanned_items, fn( $i ) => $i['category'] === 'optimized' ) );

    // Metric 1: Measured media storage on disk ($total_media_size_mb)

    // Metric 2: Potentially reclaimable storage (deduplicated by normalized physical file path on disk)
    $inv_by_id = [];
    foreach ( $inventory_items as $inv ) {
        $inv_by_id[ (int) $inv['id'] ] = $inv;
    }

    $reclaimable_physical_files = [];

    // A. Collect from unreferenced media items
    foreach ( $unused_items as $u_item ) {
        $aid = (int) $u_item['id'];
        $inv = $inv_by_id[ $aid ] ?? null;
        if ( ! $inv ) {
            continue;
        }

        $orig_path = ! empty( $inv['file_path'] ) ? wp_normalize_path( $inv['file_path'] ) : '';
        if ( $orig_path && file_exists( $orig_path ) ) {
            $norm_key = strtolower( $orig_path );
            if ( ! isset( $reclaimable_physical_files[ $norm_key ] ) ) {
                $reclaimable_physical_files[ $norm_key ] = (int) @filesize( $orig_path );
            }
        }

        if ( ! empty( $inv['subsizes'] ) && is_array( $inv['subsizes'] ) ) {
            foreach ( $inv['subsizes'] as $sub ) {
                $sub_path = ! empty( $sub['path'] ) ? wp_normalize_path( $sub['path'] ) : '';
                if ( $sub_path && file_exists( $sub_path ) ) {
                    $norm_key = strtolower( $sub_path );
                    if ( ! isset( $reclaimable_physical_files[ $norm_key ] ) ) {
                        $reclaimable_physical_files[ $norm_key ] = (int) ( $sub['bytes'] ?? @filesize( $sub_path ) );
                    }
                }
            }
        }
    }

    // B. Collect from candidate duplicate items (excluding keeper copy)
    foreach ( $duplicate_groups as $dg ) {
        foreach ( $dg['items'] as $d_item ) {
            if ( ! empty( $d_item['is_keeper'] ) ) {
                continue; // Keeper copy is preserved
            }

            $aid = (int) $d_item['id'];
            $inv = $inv_by_id[ $aid ] ?? null;
            if ( ! $inv ) {
                continue;
            }

            $orig_path = ! empty( $inv['file_path'] ) ? wp_normalize_path( $inv['file_path'] ) : '';
            if ( $orig_path && file_exists( $orig_path ) ) {
                $norm_key = strtolower( $orig_path );
                // Deduplicate: If file already counted via unused_items or shared attachment, skip
                if ( ! isset( $reclaimable_physical_files[ $norm_key ] ) ) {
                    $reclaimable_physical_files[ $norm_key ] = (int) @filesize( $orig_path );
                }
            }

            if ( ! empty( $inv['subsizes'] ) && is_array( $inv['subsizes'] ) ) {
                foreach ( $inv['subsizes'] as $sub ) {
                    $sub_path = ! empty( $sub['path'] ) ? wp_normalize_path( $sub['path'] ) : '';
                    if ( $sub_path && file_exists( $sub_path ) ) {
                        $norm_key = strtolower( $sub_path );
                        if ( ! isset( $reclaimable_physical_files[ $norm_key ] ) ) {
                            $reclaimable_physical_files[ $norm_key ] = (int) ( $sub['bytes'] ?? @filesize( $sub_path ) );
                        }
                    }
                }
            }
        }
    }

    $potentially_reclaimable_bytes = array_sum( $reclaimable_physical_files );
    $potentially_reclaimable_mb    = round( $potentially_reclaimable_bytes / ( 1024 * 1024 ), 2 );

    // Metric 3: Estimated optimization savings (hypothetical size difference from compression/resizing)
    $total_savings_bytes  = array_sum( array_column( $oversized_items, 'potential_savings_bytes' ) );
    $potential_savings_mb = round( $total_savings_bytes / ( 1024 * 1024 ), 2 );

    // Metric 4: Backup storage consumed (measured ONLY from files that actually exist on disk)
    $opt_backups      = (array) get_option( SBA_Image_Optimizer::BACKUP_OPTION_KEY, [] );
    $opt_backup_bytes = 0;
    foreach ( $opt_backups as $b ) {
        $bp = $b['backup_path'] ?? '';
        if ( $bp && file_exists( $bp ) && sba_is_readable( $bp ) ) {
            $opt_backup_bytes += (int) @filesize( $bp );
        }
    }
    $backup_storage_consumed_bytes = $quarantine_bytes + $opt_backup_bytes;
    $backup_storage_consumed_mb    = round( $backup_storage_consumed_bytes / ( 1024 * 1024 ), 2 );

    // Metric 5: Storage physically reclaimed after confirmed deletion
    $actual_reclaimed_mb = round( $actual_reclaimed_b / ( 1024 * 1024 ), 2 );

    wp_send_json_success( [
        'total_media_size_mb'               => $total_media_size_mb,
        'unused_count'                      => count( $unused_items ),
        'oversized_count'                   => count( $oversized_items ),
        'optimized_count'                   => count( $optimized_items ),
        'duplicates_count'                  => count( $duplicate_groups ),
        'accessibility_score'               => $accessibility_report['summary']['accessibility_score'] ?? 100,
        'total_scanned'                     => count( $all_scanned_items ),
        'potentially_reclaimable'           => $potentially_reclaimable_mb,
        'potentially_reclaimable_mb'        => $potentially_reclaimable_mb,
        'reclaimable_space_mb'              => $potentially_reclaimable_mb, // Backwards compatibility
        'potential_savings_mb'              => $potential_savings_mb,
        'estimated_optimization_savings_mb' => $potential_savings_mb,
        'backup_storage_consumed_mb'        => $backup_storage_consumed_mb,
        'backup_storage_consumed_formatted' => size_format( $backup_storage_consumed_bytes, 2 ),
        'quarantined_size_mb'               => $quarantined_size_mb,
        'actual_reclaimed_mb'               => $actual_reclaimed_mb,
        'all_items'                         => $all_scanned_items,
        'unused_items'                      => $unused_items,
        'oversized_items'                   => $oversized_items,
        'optimized_items'                   => $optimized_items,
        'duplicates'                        => $duplicate_groups,
        'accessibility'                     => $accessibility_report,
        'discrepancies'                     => $discrepancies,
        'quarantined_items'                 => $quarantined_items,
        'quarantined_count'                 => count( $quarantined_items ),
        'env_support'                       => $env_support,
    ] );
}

/**
 * 11b. Image Optimization AJAX Action: Resize/compress image with safe original backup.
 */
add_action( 'wp_ajax_sba_optimize_image', 'sba_ajax_optimize_image' );
function sba_ajax_optimize_image() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
    if ( ! $attachment_id ) {
        wp_send_json_error( esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
    }

    $optimizer = new SBA_Image_Optimizer();
    $max_w     = isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 1920;
    $max_h     = isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 1080;
    $quality   = isset( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 82;

    $res = $optimizer->optimize_attachment( $attachment_id, [
        'max_width'  => $max_w,
        'max_height' => $max_h,
        'quality'    => $quality,
    ] );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    wp_send_json_success( $res );
}

/**
 * 11c. Rollback Image Optimization AJAX Action: Restore original image from backup.
 */
add_action( 'wp_ajax_sba_rollback_image_optimization', 'sba_ajax_rollback_image_optimization' );
function sba_ajax_rollback_image_optimization() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
    if ( ! $attachment_id ) {
        wp_send_json_error( esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
    }

    $optimizer = new SBA_Image_Optimizer();
    $res       = $optimizer->rollback_optimization( $attachment_id );

    if ( is_wp_error( $res ) ) {
        wp_send_json_error( $res->get_error_message() );
    }

    wp_send_json_success( $res );
}

/**
 * 11d. Scan Unattached Filesystem Files AJAX Action.
 */
add_action( 'wp_ajax_sba_scan_unattached_files', 'sba_ajax_scan_unattached_files' );
function sba_ajax_scan_unattached_files() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $inventory  = new SBA_Media_Inventory();
    $inventory->scan_attachments();
    $unattached = $inventory->scan_unattached_files();

    wp_send_json_success( [
        'unattached_files' => $unattached,
        'count'            => count( $unattached ),
        'total_bytes'      => array_sum( array_column( $unattached, 'file_size_bytes' ) ),
    ] );
}

/**
 * 12. Quarantine Media AJAX Action: Safely move unused image and all sub-sizes into /wp-content/uploads/sba-quarantine/
 */
add_action( 'wp_ajax_sba_quarantine_media', 'sba_ajax_quarantine_media' );
function sba_ajax_quarantine_media() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
    if ( ! $attachment_id ) {
        wp_send_json_error( esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
    }

    $post = get_post( $attachment_id );
    if ( ! $post || 'attachment' !== $post->post_type ) {
        wp_send_json_error( esc_html__( 'Attachment record not found.', 'site-bloat-auditor' ) );
    }

    $file_path      = get_attached_file( $attachment_id );
    $meta           = wp_get_attachment_metadata( $attachment_id );
    $upload_dir     = wp_upload_dir();
    $quarantine_dir = sba_get_quarantine_dir();
    $basedir        = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

    if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
        wp_send_json_error( esc_html__( 'Attachment source file does not exist on disk.', 'site-bloat-auditor' ) );
    }

    // Path validation: Source must reside inside uploads directory
    if ( ! sba_validate_file_in_dir( $file_path, $basedir ) ) {
        wp_send_json_error( esc_html__( 'Security error: Media file resides outside the uploads directory.', 'site-bloat-auditor' ) );
    }

    $normalized_file = wp_normalize_path( $file_path );
    $rel_path = '';
    if ( 0 === strpos( $normalized_file, $basedir ) ) {
        $rel_path = substr( $normalized_file, strlen( $basedir ) );
    } else {
        $rel_path = wp_basename( $file_path );
    }
    $rel_path = ltrim( str_replace( '\\', '/', $rel_path ), '/' );

    $quarantine_path = wp_normalize_path( trailingslashit( $quarantine_dir ) . $rel_path );

    // Path validation: Destination must reside inside quarantine directory
    if ( ! sba_validate_file_in_dir( $quarantine_path, $quarantine_dir ) ) {
        wp_send_json_error( esc_html__( 'Security error: Quarantine destination path is invalid.', 'site-bloat-auditor' ) );
    }

    // Collision check on original file
    if ( file_exists( $quarantine_path ) ) {
        wp_send_json_error( sprintf(
            esc_html__( 'Quarantine collision detected: A file already exists at "%s". Rename or clear the existing file before quarantining.', 'site-bloat-auditor' ),
            basename( $quarantine_path )
        ) );
    }

    // Prepare planned moves journal
    $planned_moves = [];
    $planned_moves[] = [
        'type'            => 'original',
        'src'             => $file_path,
        'dst'             => $quarantine_path,
        'original_path'   => $file_path,
        'quarantine_path' => $quarantine_path,
        'size'            => (int) filesize( $file_path ),
    ];

    // Collect thumbnail sub-sizes
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        $file_dir            = dirname( $file_path );
        $quarantine_file_dir = dirname( $quarantine_path );

        foreach ( $meta['sizes'] as $size_key => $size_info ) {
            if ( ! empty( $size_info['file'] ) ) {
                $sub_src = wp_normalize_path( $file_dir . DIRECTORY_SEPARATOR . $size_info['file'] );
                $sub_dst = wp_normalize_path( $quarantine_file_dir . DIRECTORY_SEPARATOR . $size_info['file'] );

                // Security check on sub-size paths
                if ( ! sba_validate_file_in_dir( $sub_src, $basedir ) || ! sba_validate_file_in_dir( $sub_dst, $quarantine_dir ) ) {
                    wp_send_json_error( sprintf( esc_html__( 'Security error: Invalid thumbnail sub-size path for "%s".', 'site-bloat-auditor' ), $size_key ) );
                }

                if ( file_exists( $sub_src ) ) {
                    if ( file_exists( $sub_dst ) ) {
                        wp_send_json_error( sprintf(
                            esc_html__( 'Quarantine collision detected for thumbnail "%s". Please clear the collision first.', 'site-bloat-auditor' ),
                            basename( $sub_dst )
                        ) );
                    }

                    $planned_moves[] = [
                        'type'            => 'subsize',
                        'size_key'        => sanitize_key( $size_key ),
                        'src'             => $sub_src,
                        'dst'             => $sub_dst,
                        'original_path'   => $sub_src,
                        'quarantine_path' => $sub_dst,
                        'size'            => (int) filesize( $sub_src ),
                    ];
                }
            }
        }
    }

    // Execute planned moves with atomic rollback on failure
    $executed_moves = [];
    $total_bytes    = 0;
    $move_failed    = false;
    $failure_error  = '';

    foreach ( $planned_moves as $move ) {
        $res = sba_safe_move_file( $move['src'], $move['dst'], false );
        if ( ! $res['success'] ) {
            $move_failed   = true;
            $failure_error = $res['error'];
            break;
        }

        $executed_moves[] = $move;
        $total_bytes     += (int) $res['bytes'];
    }

    // If any move failed, rollback all executed moves
    if ( $move_failed ) {
        $rollback_failures = [];
        foreach ( $executed_moves as $rollback_move ) {
            $rb_res = sba_safe_move_file( $rollback_move['dst'], $rollback_move['src'], true );
            if ( ! $rb_res['success'] ) {
                $rollback_failures[] = [
                    'file'  => basename( $rollback_move['src'] ),
                    'from'  => $rollback_move['dst'],
                    'to'    => $rollback_move['src'],
                    'error' => $rb_res['error'],
                ];
            }
        }

        // If rollback itself failed to restore some files, save an emergency recovery journal
        if ( ! empty( $rollback_failures ) ) {
            $journal = [
                'attachment_id'     => $attachment_id,
                'status'            => 'failed_rollback',
                'failure_error'     => $failure_error,
                'rollback_failures' => $rollback_failures,
                'executed_moves'    => $executed_moves,
                'time'              => time(),
            ];
            @file_put_contents(
                trailingslashit( $quarantine_dir ) . 'recovery_journal_' . $attachment_id . '.json',
                wp_json_encode( $journal, JSON_PRETTY_PRINT )
            );
            $quarantined = (array) get_option( 'sba_quarantined_files', [] );
            $quarantined[ $attachment_id ] = [
                'id'                => $attachment_id,
                'name'              => wp_basename( $file_path ),
                'status'            => 'partial_failure_unrolled',
                'journal_file'      => 'recovery_journal_' . $attachment_id . '.json',
                'rollback_failures' => $rollback_failures,
                'moved_files'       => $rollback_failures,
            ];
            update_option( 'sba_quarantined_files', $quarantined, false );

            wp_send_json_error( sprintf(
                esc_html__( 'Quarantine move failed (%1$s) and automatic rollback encountered issues on %2$d file(s). A recovery journal was saved to prevent data loss.', 'site-bloat-auditor' ),
                $failure_error,
                count( $rollback_failures )
            ) );
        }

        wp_send_json_error( sprintf(
            esc_html__( 'Quarantine failed: %s. All files have been safely rolled back to active media.', 'site-bloat-auditor' ),
            $failure_error
        ) );
    }

    // Update attachment status directly in posts table so it is hidden from active media library
    global $wpdb;
    $wpdb->update( $wpdb->posts, [ 'post_status' => 'sba-quarantine' ], [ 'ID' => $attachment_id ] );
    clean_post_cache( $attachment_id );
    update_post_meta( $attachment_id, '_sba_quarantined', 1 );

    // Store the original relative path and metadata in option array
    $quarantined        = (array) get_option( 'sba_quarantined_files', [] );
    $quarantine_baseurl = sba_get_quarantine_url();
    $quarantine_url     = trailingslashit( $quarantine_baseurl ) . str_replace( '\\', '/', $rel_path );

    $dimensions = '';
    if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
        $dimensions = $meta['width'] . ' × ' . $meta['height'];
    }

    $item_record = [
        'id'               => $attachment_id,
        'name'             => sanitize_text_field( wp_basename( $file_path ) ),
        'title'            => sanitize_text_field( $post->post_title ?: wp_basename( $file_path ) ),
        'relative_path'    => sanitize_text_field( $rel_path ),
        'file_path'        => sanitize_text_field( 'wp-content/uploads/' . ltrim( str_replace( '\\', '/', $rel_path ), '/' ) ),
        'original_path'    => $file_path,
        'quarantine_path'  => $quarantine_path,
        'preview_url'      => esc_url_raw( $quarantine_url ),
        'moved_files'      => $executed_moves,
        'metadata'         => $meta,
        'total_bytes'      => $total_bytes,
        'size_formatted'   => size_format( $total_bytes, 2 ),
        'dimensions'       => sanitize_text_field( $dimensions ?: esc_html__( 'N/A', 'site-bloat-auditor' ) ),
        'quarantined_time' => time(),
        'quarantined_date' => current_time( 'Y-m-d' ),
    ];

    $quarantined[ $attachment_id ] = $item_record;
    update_option( 'sba_quarantined_files', $quarantined, false );

    wp_send_json_success( [
        'message'           => sprintf(
            /* translators: %s: file name */
            esc_html__( 'Successfully moved "%s" and its generated thumbnail variations to Quarantine.', 'site-bloat-auditor' ),
            $item_record['name']
        ),
        'attachment_id'     => $attachment_id,
        'item'              => $item_record,
        'quarantined_count' => count( $quarantined ),
    ] );
}

/**
 * 13. Restore Media AJAX Action: Move quarantined file and sub-sizes back to their original upload directory paths.
 * Supports single attachment_id or bulk array attachment_ids.
 */
add_action( 'wp_ajax_sba_restore_media', 'sba_ajax_restore_media' );
function sba_ajax_restore_media() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $attachment_ids = [];
    if ( ! empty( $_POST['attachment_ids'] ) ) {
        $attachment_ids = array_filter( array_map( 'absint', (array) $_POST['attachment_ids'] ) );
    } elseif ( ! empty( $_POST['attachment_id'] ) ) {
        $attachment_ids = [ absint( $_POST['attachment_id'] ) ];
    }

    if ( empty( $attachment_ids ) ) {
        wp_send_json_error( esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
    }

    $quarantined    = (array) get_option( 'sba_quarantined_files', [] );
    $upload_dir     = wp_upload_dir();
    $quarantine_dir = sba_get_quarantine_dir();
    $basedir        = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

    $restored_count = 0;
    $restored_names = [];
    $restored_ids   = [];
    $errors         = [];

    foreach ( $attachment_ids as $attachment_id ) {
        if ( ! isset( $quarantined[ $attachment_id ] ) ) {
            continue;
        }

        $record      = $quarantined[ $attachment_id ];
        $item_failed = false;
        $item_error  = '';
        $restored_files_for_item = [];

        // Move files back to original upload directory paths
        if ( ! empty( $record['moved_files'] ) && is_array( $record['moved_files'] ) ) {
            foreach ( $record['moved_files'] as $mf_index => $mf ) {
                $src = $mf['quarantine_path'] ?? '';
                $dst = $mf['original_path'] ?? '';

                if ( empty( $src ) || ! file_exists( $src ) ) {
                    // Quarantined file missing from disk? If destination already exists, we consider it restored
                    if ( ! empty( $dst ) && file_exists( $dst ) ) {
                        continue;
                    }
                    $item_failed = true;
                    $item_error  = sprintf( esc_html__( 'Quarantined file missing on disk: %s', 'site-bloat-auditor' ), basename( $src ) );
                    break;
                }

                // Security path validations
                if ( ! sba_validate_file_in_dir( $src, $quarantine_dir ) || ! sba_validate_file_in_dir( $dst, $basedir ) ) {
                    $item_failed = true;
                    $item_error  = sprintf( esc_html__( 'Security error: Invalid file path boundary for "%s".', 'site-bloat-auditor' ), basename( $src ) );
                    break;
                }

                // Check collision at destination
                if ( file_exists( $dst ) ) {
                    // If destination file exists with identical size, it was already safely restored
                    if ( (int) filesize( $dst ) === (int) filesize( $src ) ) {
                        @unlink( $src );
                        continue;
                    } else {
                        $item_failed = true;
                        $item_error  = sprintf( esc_html__( 'Destination file already exists (collision detected): %s', 'site-bloat-auditor' ), basename( $dst ) );
                        break;
                    }
                }

                $move_res = sba_safe_move_file( $src, $dst, false );
                if ( ! $move_res['success'] ) {
                    $item_failed = true;
                    $item_error  = $move_res['error'];
                    break;
                }

                $restored_files_for_item[] = [
                    'index' => $mf_index,
                    'src'   => $src,
                    'dst'   => $dst,
                ];
            }
        }

        // CRITICAL SAFETY CHECK: NEVER discard recovery record if any file move failed!
        if ( $item_failed ) {
            // Remove already restored files from moved_files so retrying won't collide with existing files
            $restored_indices = array_column( $restored_files_for_item, 'index' );
            if ( ! empty( $record['moved_files'] ) && is_array( $record['moved_files'] ) ) {
                $remaining_files = [];
                foreach ( $record['moved_files'] as $mf_idx => $mf_data ) {
                    if ( ! in_array( $mf_idx, $restored_indices, true ) ) {
                        $remaining_files[] = $mf_data;
                    }
                }
                $record['moved_files'] = $remaining_files;
            }
            $record['last_restore_error'] = $item_error;
            $record['partial_restore_at'] = time();
            $quarantined[ $attachment_id ] = $record;
            $errors[] = sprintf( esc_html__( 'Partial restore on "%s": %s. Remaining stranded files are safely preserved in recovery records.', 'site-bloat-auditor' ), $record['name'] ?? $attachment_id, $item_error );
            continue;
        }

        // Clean up empty directories in quarantine path
        if ( ! empty( $record['quarantine_path'] ) ) {
            $item_dir = dirname( $record['quarantine_path'] );
            if ( is_dir( $item_dir ) && sba_validate_file_in_dir( $item_dir, $quarantine_dir ) && $item_dir !== rtrim( $quarantine_dir, '/\\' ) ) {
                @rmdir( $item_dir );
            }
        }

        // Only restore post status if all files succeeded
        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_status' => 'inherit' ], [ 'ID' => $attachment_id ] );
        clean_post_cache( $attachment_id );
        delete_post_meta( $attachment_id, '_sba_quarantined' );

        // Safely remove item from sba_quarantined_files option
        unset( $quarantined[ $attachment_id ] );
        $restored_names[] = $record['name'] ?? ( 'Attachment #' . $attachment_id );
        $restored_ids[]   = $attachment_id;
        $restored_count++;
    }

    update_option( 'sba_quarantined_files', $quarantined, false );

    if ( $restored_count === 0 && ! empty( $errors ) ) {
        wp_send_json_error( implode( ' ', $errors ) );
    }

    if ( $restored_count === 1 ) {
        $message = sprintf(
            /* translators: %s: file name */
            esc_html__( 'Successfully restored "%s" back to active Media Library.', 'site-bloat-auditor' ),
            $restored_names[0] ?? ''
        );
    } else {
        $message = sprintf(
            /* translators: %d: count */
            esc_html__( 'Successfully restored %d media item(s) back to active Media Library.', 'site-bloat-auditor' ),
            $restored_count
        );
    }

    if ( ! empty( $errors ) ) {
        $message .= ' ' . sprintf( esc_html__( 'Note: %d item(s) failed and remain safely in quarantine.', 'site-bloat-auditor' ), count( $errors ) );
    }

    wp_send_json_success( [
        'message'           => $message,
        'attachment_ids'    => $restored_ids,
        'attachment_id'     => count( $restored_ids ) === 1 ? $restored_ids[0] : 0,
        'restored_count'    => $restored_count,
        'quarantined_count' => count( $quarantined ),
        'errors'            => $errors,
    ] );
}

/**
 * 14. Empty Quarantine Permanently AJAX Action: Permanently delete all or selected quarantined files and attachment records.
 */
add_action( 'wp_ajax_sba_empty_quarantine', 'sba_ajax_empty_quarantine' );
add_action( 'wp_ajax_sba_delete_quarantine_media', 'sba_ajax_empty_quarantine' );
function sba_ajax_empty_quarantine() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $quarantined = (array) get_option( 'sba_quarantined_files', [] );
    if ( empty( $quarantined ) ) {
        wp_send_json_success( [
            'deleted_count'   => 0,
            'freed_bytes'     => 0,
            'freed_formatted' => '0 B',
            'message'         => esc_html__( 'Quarantine trash is already empty.', 'site-bloat-auditor' ),
        ] );
    }

    $attachment_ids = [];
    if ( ! empty( $_POST['attachment_ids'] ) ) {
        $attachment_ids = array_filter( array_map( 'absint', (array) $_POST['attachment_ids'] ) );
    } elseif ( ! empty( $_POST['attachment_id'] ) ) {
        $attachment_ids = [ absint( $_POST['attachment_id'] ) ];
    }

    $is_partial = ! empty( $attachment_ids );
    $targets    = $is_partial ? $attachment_ids : array_keys( $quarantined );

    $quarantine_dir = sba_get_quarantine_dir();
    $deleted_count  = 0;
    $freed_bytes    = 0;
    $deleted_ids    = [];

    foreach ( $targets as $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( ! isset( $quarantined[ $attachment_id ] ) ) {
            continue;
        }

        $record = $quarantined[ $attachment_id ];

        // Delete quarantined files from disk after verifying they reside strictly in quarantine directory
        if ( ! empty( $record['moved_files'] ) && is_array( $record['moved_files'] ) ) {
            foreach ( $record['moved_files'] as $mf ) {
                $q_path = $mf['quarantine_path'] ?? '';
                if ( ! empty( $q_path ) && file_exists( $q_path ) ) {
                    // Security boundary check: Path MUST reside strictly inside quarantine dir
                    if ( sba_validate_file_in_dir( $q_path, $quarantine_dir ) ) {
                        $fsize = (int) filesize( $q_path );
                        if ( @unlink( $q_path ) && ! file_exists( $q_path ) ) {
                            $freed_bytes += $fsize;
                        }
                    }
                }
            }
        }

        // Clean up empty directories in quarantine path
        if ( ! empty( $record['quarantine_path'] ) ) {
            $item_dir = dirname( $record['quarantine_path'] );
            if ( is_dir( $item_dir ) && sba_validate_file_in_dir( $item_dir, $quarantine_dir ) && $item_dir !== rtrim( $quarantine_dir, '/\\' ) ) {
                @rmdir( $item_dir );
            }
        }

        // Permanently delete attachment post record and metadata
        wp_delete_post( $attachment_id, true );
        unset( $quarantined[ $attachment_id ] );
        $deleted_ids[] = $attachment_id;
        $deleted_count++;
    }

    // Accumulate total verified reclaimed bytes in persistent option
    if ( $freed_bytes > 0 ) {
        $prev_reclaimed = (int) get_option( 'sba_reclaimed_media_bytes', 0 );
        update_option( 'sba_reclaimed_media_bytes', $prev_reclaimed + $freed_bytes, false );
    }

    // Update option (reset to empty array if all deleted, or updated array if partial)
    update_option( 'sba_quarantined_files', $quarantined, false );

    wp_send_json_success( [
        'deleted_count'     => $deleted_count,
        'deleted_ids'       => $deleted_ids,
        'remaining_count'   => count( $quarantined ),
        'freed_bytes'       => $freed_bytes,
        'freed_formatted'   => size_format( $freed_bytes, 2 ),
        'message'           => sprintf(
            /* translators: 1: count of deleted attachments, 2: formatted disk space freed */
            esc_html__( 'Permanently purged %1$d quarantined attachment(s), freeing %2$s of disk storage.', 'site-bloat-auditor' ),
            $deleted_count,
            size_format( $freed_bytes, 2 )
        ),
    ] );
}

/**
 * 15. Delete Single Thumbnail Sub-size AJAX Action: Safely prune an intermediate thumbnail variation,
 * delete physical file from disk, and remove it from attachment metadata to prevent 404 broken srcset errors.
 */
add_action( 'wp_ajax_sba_delete_single_thumbnail_subsize', 'sba_ajax_delete_single_thumbnail_subsize' );
function sba_ajax_delete_single_thumbnail_subsize() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    $attachment_id = absint( $_POST['attachment_id'] ?? 0 );
    $size_key      = sanitize_key( $_POST['size_key'] ?? '' );

    if ( ! $attachment_id ) {
        wp_send_json_error( esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
    }

    if ( empty( $size_key ) ) {
        wp_send_json_error( esc_html__( 'Invalid or missing thumbnail size key.', 'site-bloat-auditor' ) );
    }

    $post = get_post( $attachment_id );
    if ( ! $post || 'attachment' !== $post->post_type ) {
        wp_send_json_error( esc_html__( 'Attachment record not found.', 'site-bloat-auditor' ) );
    }

    $meta = wp_get_attachment_metadata( $attachment_id );
    if ( empty( $meta ) || ! is_array( $meta ) || empty( $meta['sizes'] ) || ! isset( $meta['sizes'][ $size_key ] ) ) {
        wp_send_json_error( esc_html__( 'The requested thumbnail size does not exist in metadata.', 'site-bloat-auditor' ) );
    }

    $size_info     = $meta['sizes'][ $size_key ];
    $file_path     = get_attached_file( $attachment_id );
    $file_dir      = $file_path ? dirname( $file_path ) : '';
    $upload_dir    = wp_upload_dir();
    $basedir       = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
    $deleted_bytes = 0;
    $deleted_file  = ! empty( $size_info['file'] ) ? $size_info['file'] : '';

    if ( $file_dir && ! empty( $size_info['file'] ) ) {
        $sub_path = wp_normalize_path( $file_dir . DIRECTORY_SEPARATOR . $size_info['file'] );

        // Security boundary validation: Sub-size must reside inside uploads directory
        if ( ! sba_validate_file_in_dir( $sub_path, $basedir ) ) {
            wp_send_json_error( esc_html__( 'Security error: Thumbnail file path resides outside uploads directory.', 'site-bloat-auditor' ) );
        }

        if ( file_exists( $sub_path ) ) {
            $fsize = (int) filesize( $sub_path );
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $sub_path );
            }
            if ( file_exists( $sub_path ) ) {
                @unlink( $sub_path );
            }

            // Verify physical deletion before touching metadata
            if ( file_exists( $sub_path ) ) {
                wp_send_json_error( sprintf( esc_html__( 'Could not delete thumbnail file "%s" from disk. Check file permissions.', 'site-bloat-auditor' ), basename( $sub_path ) ) );
            }

            $deleted_bytes = $fsize;
        }
    }

    // Update attachment metadata to remove the size key so WordPress does not output it in future srcset
    unset( $meta['sizes'][ $size_key ] );
    wp_update_attachment_metadata( $attachment_id, $meta );

    // Track verified reclaimed bytes
    if ( $deleted_bytes > 0 ) {
        $prev_reclaimed = (int) get_option( 'sba_reclaimed_media_bytes', 0 );
        update_option( 'sba_reclaimed_media_bytes', $prev_reclaimed + $deleted_bytes, false );
    }

    // Recalculate remaining total bytes and sub-size count for this attachment
    $orig_bytes        = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;
    $new_total_bytes   = $orig_bytes;
    $new_subsize_count = 0;
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $file_dir ) {
        foreach ( $meta['sizes'] as $s_info ) {
            if ( ! empty( $s_info['file'] ) ) {
                $sp = $file_dir . DIRECTORY_SEPARATOR . $s_info['file'];
                if ( file_exists( $sp ) ) {
                    $new_total_bytes += (int) filesize( $sp );
                    $new_subsize_count++;
                }
            }
        }
    }

    wp_send_json_success( [
        'attachment_id'       => $attachment_id,
        'size_key'            => $size_key,
        'deleted_filename'    => sanitize_file_name( $deleted_file ),
        'deleted_bytes'       => $deleted_bytes,
        'deleted_formatted'   => size_format( $deleted_bytes, 2 ),
        'new_total_bytes'     => $new_total_bytes,
        'new_total_formatted' => size_format( $new_total_bytes, 2 ),
        'new_subsize_count'   => $new_subsize_count,
        'message'             => sprintf(
            /* translators: 1: size key, 2: file size freed */
            esc_html__( 'Successfully pruned thumbnail variant "%1$s", freeing %2$s.', 'site-bloat-auditor' ),
            $size_key,
            size_format( $deleted_bytes, 2 )
        ),
    ] );
}

/**
 * Helper: Detect if a plugin is missing its parent or required dependencies.
 *
 * Checks WordPress 6.5+ 'Requires Plugins' header as well as known add-on signatures
 * (e.g., WooCommerce, Elementor, Contact Form 7, Gravity Forms).
 *
 * @param string $plugin_file     Relative plugin file path.
 * @param array  $plugin_meta     Plugin metadata header array from get_plugins().
 * @param array  $active_plugins  Array of active plugin file identifiers.
 * @param array  $all_plugins     Array of all installed plugins.
 * @return array|null Array with dependency details if parent is missing, or null if ok.
 */
function sba_check_plugin_dependencies( $plugin_file, $plugin_meta, $active_plugins, $all_plugins ) {
    $self_plugin = plugin_basename( __FILE__ );
    if ( $plugin_file === $self_plugin ) {
        return null;
    }

    // Build list of active plugin slugs (e.g. 'woocommerce', 'contact-form-7', etc.)
    $active_slugs = [];
    foreach ( $active_plugins as $active_file ) {
        $a_slug = dirname( $active_file );
        if ( '.' === $a_slug || empty( $a_slug ) ) {
            $a_slug = sanitize_title( pathinfo( $active_file, PATHINFO_FILENAME ) );
        }
        $active_slugs[] = sanitize_key( $a_slug );
    }
    $active_slugs = array_unique( array_filter( $active_slugs ) );

    // 1. Check WordPress 6.5+ 'Requires Plugins' header
    $requires_plugins_raw = '';
    if ( ! empty( $plugin_meta['RequiresPlugins'] ) ) {
        $requires_plugins_raw = (string) $plugin_meta['RequiresPlugins'];
    } elseif ( ! empty( $plugin_meta['Requires Plugins'] ) ) {
        $requires_plugins_raw = (string) $plugin_meta['Requires Plugins'];
    } else {
        // Fallback: Read directly from main plugin file header if not cached in $plugin_meta
        $full_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( file_exists( $full_plugin_path ) ) {
            $extra_headers = get_file_data( $full_plugin_path, [
                'RequiresPlugins' => 'Requires Plugins',
                'WCRequires'      => 'WC requires at least',
            ] );
            if ( ! empty( $extra_headers['RequiresPlugins'] ) ) {
                $requires_plugins_raw = (string) $extra_headers['RequiresPlugins'];
            }
        }
    }

    if ( ! empty( $requires_plugins_raw ) ) {
        $req_slugs = array_filter( array_map( 'sanitize_key', explode( ',', $requires_plugins_raw ) ) );
        foreach ( $req_slugs as $req_slug ) {
            if ( ! in_array( $req_slug, $active_slugs, true ) ) {
                $parent_name = ucwords( str_replace( [ '-', '_' ], ' ', $req_slug ) );
                return [
                    'dependency_issue' => true,
                    'parent_slug'      => $req_slug,
                    'parent_name'      => $parent_name,
                    'risk_level'       => 'Inactive Dependency',
                    'risk_note'        => sprintf(
                        /* translators: %s: parent plugin name */
                        __( 'Parent plugin missing (%s). This plugin consumes memory without performing any function.', 'site-bloat-auditor' ),
                        $parent_name
                    ),
                ];
            }
        }
    }

    // 2. Known extension / add-on signatures
    $plugin_slug = dirname( $plugin_file );
    if ( '.' === $plugin_slug || empty( $plugin_slug ) ) {
        $plugin_slug = sanitize_title( pathinfo( $plugin_file, PATHINFO_FILENAME ) );
    }
    $plugin_slug = sanitize_key( $plugin_slug );
    $plugin_name = sanitize_text_field( $plugin_meta['Name'] ?? $plugin_slug );

    // WooCommerce Add-ons
    if ( 'woocommerce' !== $plugin_slug && ! in_array( 'woocommerce', $active_slugs, true ) ) {
        $is_woo_addon = (
            strpos( $plugin_slug, 'woocommerce-' ) === 0 ||
            strpos( $plugin_slug, 'wc-' ) === 0 ||
            stripos( $plugin_name, 'WooCommerce ' ) !== false ||
            stripos( $plugin_name, 'for WooCommerce' ) !== false
        );
        if ( $is_woo_addon ) {
            return [
                'dependency_issue' => true,
                'parent_slug'      => 'woocommerce',
                'parent_name'      => 'WooCommerce',
                'risk_level'       => 'Inactive Dependency',
                'risk_note'        => __( 'Parent plugin missing (WooCommerce). This plugin consumes memory without performing any function.', 'site-bloat-auditor' ),
            ];
        }
    }

    // Elementor Add-ons
    if ( 'elementor' !== $plugin_slug && 'elementor-pro' !== $plugin_slug && ! in_array( 'elementor', $active_slugs, true ) ) {
        $is_elementor_addon = (
            strpos( $plugin_slug, 'elementor-' ) === 0 ||
            stripos( $plugin_name, 'for Elementor' ) !== false ||
            stripos( $plugin_name, 'Elementor Addon' ) !== false ||
            stripos( $plugin_name, 'Addons for Elementor' ) !== false
        );
        if ( $is_elementor_addon ) {
            return [
                'dependency_issue' => true,
                'parent_slug'      => 'elementor',
                'parent_name'      => 'Elementor',
                'risk_level'       => 'Inactive Dependency',
                'risk_note'        => __( 'Parent plugin missing (Elementor). This plugin consumes memory without performing any function.', 'site-bloat-auditor' ),
            ];
        }
    }

    // Contact Form 7 Add-ons
    if ( 'contact-form-7' !== $plugin_slug && 'wp-contact-form-7' !== $plugin_slug && ! in_array( 'contact-form-7', $active_slugs, true ) && ! in_array( 'wp-contact-form-7', $active_slugs, true ) ) {
        $is_cf7_addon = (
            strpos( $plugin_slug, 'cf7-' ) === 0 ||
            strpos( $plugin_slug, 'contact-form-7-' ) === 0 ||
            stripos( $plugin_name, 'for Contact Form 7' ) !== false ||
            stripos( $plugin_name, 'CF7 ' ) !== false
        );
        if ( $is_cf7_addon ) {
            return [
                'dependency_issue' => true,
                'parent_slug'      => 'contact-form-7',
                'parent_name'      => 'Contact Form 7',
                'risk_level'       => 'Inactive Dependency',
                'risk_note'        => __( 'Parent plugin missing (Contact Form 7). This plugin consumes memory without performing any function.', 'site-bloat-auditor' ),
            ];
        }
    }

    // Gravity Forms Add-ons
    if ( 'gravityforms' !== $plugin_slug && ! in_array( 'gravityforms', $active_slugs, true ) ) {
        $is_gf_addon = (
            strpos( $plugin_slug, 'gravityforms-' ) === 0 ||
            strpos( $plugin_slug, 'gf-' ) === 0 ||
            stripos( $plugin_name, 'for Gravity Forms' ) !== false
        );
        if ( $is_gf_addon ) {
            return [
                'dependency_issue' => true,
                'parent_slug'      => 'gravityforms',
                'parent_name'      => 'Gravity Forms',
                'risk_level'       => 'Inactive Dependency',
                'risk_note'        => __( 'Parent plugin missing (Gravity Forms). This plugin consumes memory without performing any function.', 'site-bloat-auditor' ),
            ];
        }
    }

    return null;
}

/**
 * Helper: Query database tables and calculate the total table count and size for each installed plugin.
 *
 * @param array $all_plugins Array of all installed plugins from get_plugins().
 * @return array Associative array of [ plugin_file => [ 'count' => int, 'size_bytes' => int, 'size_formatted' => string ] ]
 */
function sba_get_plugins_db_footprint( $all_plugins = [] ) {
    global $wpdb;

    $footprint = [];
    foreach ( array_keys( $all_plugins ) as $plugin_file ) {
        $footprint[ $plugin_file ] = [
            'count'          => 0,
            'size_bytes'     => 0,
            'size_formatted' => '0 KB',
        ];
    }

    $raw_tables = $wpdb->get_results( $wpdb->prepare( "
        SELECT table_name AS `name`,
               (data_length + index_length) AS `size_bytes`
        FROM information_schema.TABLES
        WHERE table_schema = DATABASE()
        AND table_name LIKE %s
    ", $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

    if ( empty( $raw_tables ) ) {
        return $footprint;
    }

    $core_tables = sba_get_core_table_names();
    $prefix      = $wpdb->prefix;
    $base_prefix = ! empty( $wpdb->base_prefix ) ? $wpdb->base_prefix : $prefix;

    // Use our existing plugin matchers extractor
    $matchers = sba_extract_plugin_matchers( array_keys( $all_plugins ), $all_plugins );

    foreach ( $raw_tables as $table ) {
        $table_name = sanitize_key( $table->name );

        // Strip WP table prefix
        $table_base = $table_name;
        if ( ! empty( $prefix ) && stripos( $table_base, $prefix ) === 0 ) {
            $table_base = substr( $table_base, strlen( $prefix ) );
        } elseif ( ! empty( $base_prefix ) && stripos( $table_base, $base_prefix ) === 0 ) {
            $table_base = substr( $table_base, strlen( $base_prefix ) );
        }

        $unprefixed = preg_replace( '/^(\d+_)/', '', $table_base );
        if ( stripos( $unprefixed, 'wp_' ) === 0 ) {
            $unprefixed = substr( $unprefixed, 3 );
        }

        // Skip WordPress core tables
        if ( in_array( $unprefixed, $core_tables, true ) || in_array( $table_name, $core_tables, true ) ) {
            continue;
        }

        // Match table to the best matching plugin
        $matched_plugin_file = null;
        $longest_match_len   = 0;

        foreach ( $matchers as $matcher ) {
            foreach ( $matcher['prefixes'] as $pfx ) {
                $pfx_clean = rtrim( $pfx, '_' );
                if ( empty( $pfx_clean ) ) {
                    continue;
                }
                if ( stripos( $unprefixed, $pfx ) === 0 || stripos( $unprefixed, $pfx_clean . '_' ) === 0 || stripos( $unprefixed, $pfx_clean ) === 0 ) {
                    $len = strlen( $pfx );
                    if ( $len > $longest_match_len ) {
                        $longest_match_len   = $len;
                        $matched_plugin_file = $matcher['file'];
                    }
                }
            }
        }

        if ( $matched_plugin_file && isset( $footprint[ $matched_plugin_file ] ) ) {
            $footprint[ $matched_plugin_file ]['count']++;
            $footprint[ $matched_plugin_file ]['size_bytes'] += (int) ( $table->size_bytes ?? 0 );
        }
    }

    // Format sizes
    foreach ( $footprint as $pf => $data ) {
        $bytes = $data['size_bytes'];
        $footprint[ $pf ]['size_formatted'] = size_format( $bytes, 2 );
    }

    return $footprint;
}

/**
 * 16. Plugin Storage & Security Hygiene Audit AJAX Action:
 * Scans installed plugins, calculates folder sizes on disk, cross-references database footprint,
 * checks plugin dependencies, verifies against WordPress.org API, and evaluates risk levels.
 */
add_action( 'wp_ajax_sba_scan_plugins_audit', 'sba_ajax_scan_plugins_audit' );
add_action( 'wp_ajax_sba_scan_plugins', 'sba_ajax_scan_plugins_audit' ); // Alias for dashboard compatibility
function sba_ajax_scan_plugins_audit() {
    check_ajax_referer( 'sba_nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( esc_html__( 'Unauthorized permission.', 'site-bloat-auditor' ) );
    }

    @ini_set( 'memory_limit', '512M' );
    @set_time_limit( 300 );

    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    global $wp_version;
    $current_wp = preg_replace( '/-.*$/', '', $wp_version );

    $all_plugins    = get_plugins();
    $active_plugins = (array) get_option( 'active_plugins', [] );
    if ( is_multisite() ) {
        $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
        $active_plugins = array_unique( array_merge( $active_plugins, array_keys( $network_active ) ) );
    }

    $self_plugin_file   = plugin_basename( __FILE__ );
    $plugins_db_map     = sba_get_plugins_db_footprint( $all_plugins );

    $plugins_data       = [];
    $total_bytes        = 0;
    $inactive_bytes     = 0;
    $unverified_count   = 0;
    $verified_count     = 0;
    $outdated_count     = 0;
    $high_risk_count    = 0;
    $medium_risk_count  = 0;
    $low_risk_count     = 0;
    $dependencies_count = 0;
    $active_count       = 0;
    $inactive_count     = 0;
    $two_years_ago_ts   = time() - ( 2 * YEAR_IN_SECONDS );

    foreach ( $all_plugins as $plugin_file => $plugin_meta ) {
        // 1. Extract Plugin Slug
        $plugin_file_clean = wp_normalize_path( $plugin_file );
        if ( false !== strpos( $plugin_file_clean, '/' ) ) {
            $slug            = dirname( $plugin_file_clean );
            $plugin_dir_path = WP_PLUGIN_DIR . '/' . $slug;
        } else {
            $slug            = sanitize_title( pathinfo( $plugin_file_clean, PATHINFO_FILENAME ) );
            $plugin_dir_path = WP_PLUGIN_DIR . '/' . $plugin_file_clean;
        }
        $slug = sanitize_key( $slug );

        // 2. Calculate Disk Footprint
        $plugin_bytes = 0;
        if ( is_dir( $plugin_dir_path ) ) {
            $plugin_bytes = sba_get_directory_size_bytes( $plugin_dir_path, '' );
        } elseif ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
            $plugin_bytes = (int) filesize( WP_PLUGIN_DIR . '/' . $plugin_file );
        }
        $total_bytes += $plugin_bytes;
        $size_mb      = round( $plugin_bytes / ( 1024 * 1024 ), 2 );

        // Active status
        $is_active = in_array( $plugin_file, $active_plugins, true );
        if ( $is_active ) {
            $active_count++;
        } else {
            $inactive_count++;
            $inactive_bytes += $plugin_bytes;
        }

        // 3. Database Footprint
        $db_entry             = $plugins_db_map[ $plugin_file ] ?? [ 'count' => 0, 'size_bytes' => 0, 'size_formatted' => '0 KB' ];
        $db_tables_count      = (int) $db_entry['count'];
        $db_tables_size       = $db_entry['size_formatted'];
        $db_tables_size_bytes = (int) $db_entry['size_bytes'];

        // 4. Self-Plugin Recognition (Whitelist)
        $is_self = ( $plugin_file === $self_plugin_file );

        // 5. Missing Dependency Detection
        $dep_check            = sba_check_plugin_dependencies( $plugin_file, $plugin_meta, $active_plugins, $all_plugins );
        $has_dependency_issue = ( null !== $dep_check );
        if ( $has_dependency_issue ) {
            $dependencies_count++;
        }

        // 6. Query WordPress.org Repository API with Transient Caching (skip for self plugin)
        $transient_key = 'sba_plugin_repo_' . substr( $slug, 0, 100 );
        $cached_info   = $is_self ? [ 'found' => false ] : get_transient( $transient_key );

        if ( ! $is_self && ( false === $cached_info || ! is_array( $cached_info ) ) ) {
            $api_response = plugins_api( 'plugin_information', [
                'slug'   => $slug,
                'fields' => [
                    'tested'          => true,
                    'last_updated'    => true,
                    'active_installs' => true,
                ],
            ] );

            if ( is_wp_error( $api_response ) ) {
                $cached_info = [
                    'found' => false,
                    'error' => sanitize_text_field( $api_response->get_error_message() ),
                ];
            } else {
                $cached_info = [
                    'found'           => true,
                    'name'            => sanitize_text_field( $api_response->name ?? '' ),
                    'version'         => sanitize_text_field( $api_response->version ?? '' ),
                    'tested'          => sanitize_text_field( $api_response->tested ?? '' ),
                    'last_updated'    => sanitize_text_field( $api_response->last_updated ?? '' ),
                    'active_installs' => (int) ( $api_response->active_installs ?? 0 ),
                ];
            }
            set_transient( $transient_key, $cached_info, WEEK_IN_SECONDS );
        }

        // 7. Security & Risk Classification
        $is_verified       = ! empty( $cached_info['found'] );
        $tested_version    = $cached_info['tested'] ?? '';
        $last_updated_raw  = $cached_info['last_updated'] ?? '';
        $last_updated_ts   = ! empty( $last_updated_raw ) ? strtotime( $last_updated_raw ) : 0;
        $last_updated_date = $last_updated_ts > 0 ? date( 'Y-m-d', $last_updated_ts ) : '';
        $is_outdated       = false;

        if ( $is_self ) {
            // Whitelist self plugin - do not flag as High Risk
            $repo_status          = 'development';
            $repo_status_label    = __( 'Local Plugin (This Auditor)', 'site-bloat-auditor' );
            $repo_url             = '';
            $classification       = 'self';
            $classification_label = __( 'Local Plugin (This Auditor)', 'site-bloat-auditor' );
            $risk_level           = 'Local Active';
            $risk_explanation     = __( 'Active site bloat auditor and storage maintenance clinic.', 'site-bloat-auditor' );
            $risk_note            = $risk_explanation;
        } elseif ( $has_dependency_issue ) {
            // Missing Dependency detected: elevate to amber Dependency Inactive
            $repo_status          = $is_verified ? 'verified' : 'unverified';
            $repo_status_label    = $is_verified ? __( 'Verified on WP.org', 'site-bloat-auditor' ) : __( 'Unofficial / Not in Directory', 'site-bloat-auditor' );
            $repo_url             = $is_verified ? 'https://wordpress.org/plugins/' . rawurlencode( $slug ) . '/' : '';
            $classification       = 'dependency';
            $classification_label = __( 'Missing Dependency', 'site-bloat-auditor' );
            $risk_level           = 'Dependency Inactive';
            $risk_explanation     = $dep_check['risk_note'];
            $risk_note            = $dep_check['risk_note'];
            $medium_risk_count++;
            if ( $is_verified ) {
                $verified_count++;
            } else {
                $unverified_count++;
            }
        } elseif ( ! $is_verified ) {
            // Unregistered on WP.org: High Risk
            $unverified_count++;
            $high_risk_count++;
            $classification       = 'unverified';
            $classification_label = __( 'Unverified / Third-Party', 'site-bloat-auditor' );
            $risk_level           = 'High';
            $risk_explanation     = __( 'Unregistered in official repository; potential nulled, custom, or abandoned plugin.', 'site-bloat-auditor' );
            $risk_note            = $risk_explanation;
            $repo_status          = 'unverified';
            $repo_url             = '';
        } else {
            $verified_count++;
            $repo_status = 'verified';
            $repo_url    = 'https://wordpress.org/plugins/' . rawurlencode( $slug ) . '/';

            // Check if last updated is older than 2 years from today
            if ( $last_updated_ts > 0 && $last_updated_ts < $two_years_ago_ts ) {
                $is_outdated = true;
                $outdated_count++;
                $medium_risk_count++;
                $classification       = 'outdated';
                $classification_label = __( 'Abandoned / Outdated (2+ years)', 'site-bloat-auditor' );
                $risk_level           = 'Medium';
                $risk_explanation     = sprintf(
                    /* translators: %s: last updated date */
                    __( 'Abandoned: Last updated on %s (over 2 years ago). May lack security patches.', 'site-bloat-auditor' ),
                    $last_updated_date
                );
                $risk_note            = $risk_explanation;
            } else {
                $low_risk_count++;
                $classification       = 'verified';
                $classification_label = __( 'Official & Verified', 'site-bloat-auditor' );
                $risk_level           = 'Low';
                $risk_explanation     = sprintf(
                    /* translators: %s: tested WordPress version */
                    __( 'Official & Verified on WordPress.org (Tested up to WP %s).', 'site-bloat-auditor' ),
                    ! empty( $tested_version ) ? $tested_version : __( 'Recent', 'site-bloat-auditor' )
                );
                $risk_note            = $risk_explanation;
            }
        }

        // Return clean unescaped strings from backend; escaped only at DOM render point to prevent &amp; artifacts
        $plugins_data[] = [
            'plugin_file'          => sanitize_text_field( $plugin_file ),
            'slug'                 => $slug,
            'name'                 => wp_specialchars_decode( sanitize_text_field( $plugin_meta['Name'] ?? $slug ), ENT_QUOTES ),
            'version'              => sanitize_text_field( $plugin_meta['Version'] ?? '1.0.0' ),
            'author'               => wp_specialchars_decode( sanitize_text_field( wp_strip_all_tags( $plugin_meta['Author'] ?? '' ) ), ENT_QUOTES ),
            'author_uri'           => esc_url_raw( $plugin_meta['AuthorURI'] ?? '' ),
            'plugin_uri'           => esc_url_raw( $plugin_meta['PluginURI'] ?? '' ),
            'description'          => wp_specialchars_decode( sanitize_text_field( wp_strip_all_tags( $plugin_meta['Description'] ?? '' ) ), ENT_QUOTES ),
            'is_active'            => $is_active,
            'is_self'              => $is_self,
            'dependency_issue'     => $has_dependency_issue,
            'dependency_parent'    => $has_dependency_issue ? ( $dep_check['parent_name'] ?? '' ) : '',
            'size_bytes'           => $plugin_bytes,
            'size_mb'              => $size_mb,
            'size_formatted'       => size_format( $plugin_bytes, 2 ),
            'db_tables_count'      => $db_tables_count,
            'db_tables_size'       => $db_tables_size,
            'db_tables_size_bytes' => $db_tables_size_bytes,
            'repo_status'          => $repo_status,
            'repo_status_label'    => wp_specialchars_decode( $repo_status_label ?? '', ENT_QUOTES ),
            'repo_url'             => esc_url_raw( $repo_url ),
            'tested_wp'            => sanitize_text_field( $tested_version ),
            'last_updated'         => sanitize_text_field( $last_updated_raw ),
            'last_updated_date'    => sanitize_text_field( $last_updated_date ),
            'is_outdated'          => $is_outdated,
            'active_installs'      => (int) ( $cached_info['active_installs'] ?? 0 ),
            'classification'       => $classification,
            'classification_label' => wp_specialchars_decode( $classification_label, ENT_QUOTES ),
            'risk_level'           => $risk_level,
            'risk_explanation'     => wp_specialchars_decode( $risk_explanation, ENT_QUOTES ),
            'risk_note'            => wp_specialchars_decode( $risk_note, ENT_QUOTES ),
        ];
    }

    // Sort plugin records: High Risk first, then Inactive Dependency, then Medium Risk, then Low Risk, then Local Plugin; secondary sort by size_bytes descending
    usort( $plugins_data, function( $a, $b ) {
        $risk_weights = [
            'High'                => 4,
            'Dependency Inactive' => 3,
            'Inactive Dependency' => 3,
            'Medium'              => 2,
            'Low'                 => 1,
            'Local Active'        => 0,
            'Local Plugin'        => 0,
        ];
        $weight_a = $risk_weights[ $a['risk_level'] ] ?? 0;
        $weight_b = $risk_weights[ $b['risk_level'] ] ?? 0;

        if ( $weight_a !== $weight_b ) {
            return $weight_b <=> $weight_a;
        }

        return ( $b['size_bytes'] <=> $a['size_bytes'] );
    } );

    $total_size_mb    = round( $total_bytes / ( 1024 * 1024 ), 2 );
    $inactive_size_mb = round( $inactive_bytes / ( 1024 * 1024 ), 2 );

    wp_send_json_success( [
        'total_count'          => count( $all_plugins ),
        'active_count'         => $active_count,
        'inactive_count'       => $inactive_count,
        'unverified_count'     => $unverified_count,
        'verified_count'       => $verified_count,
        'outdated_count'       => $outdated_count,
        'high_risk_count'      => $high_risk_count,
        'medium_risk_count'    => $medium_risk_count,
        'low_risk_count'       => $low_risk_count,
        'dependencies_count'   => $dependencies_count,
        'total_risks_count'    => ( $high_risk_count + $medium_risk_count + $dependencies_count ),
        'total_size_mb'        => $total_size_mb,
        'total_size_bytes'     => $total_bytes,
        'total_size_formatted' => size_format( $total_bytes, 2 ),
        'inactive_size_mb'     => $inactive_size_mb,
        'plugins'              => $plugins_data,
    ] );
}