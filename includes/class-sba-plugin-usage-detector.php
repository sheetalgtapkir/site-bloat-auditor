<?php
/**
 * Modular Plugin Usage & Evidence Detection Engine.
 *
 * Distinguishes true functional usage and operational activity from database
 * footprints, historical data, and saved configurations.
 *
 * Implements 7 distinct usage statuses:
 * 1. functional_usage         - Functional Usage Detected (shortcodes, blocks, widgets, embedded components)
 * 2. operational_activity     - Operational Activity Detected (sitewide SEO, schema, sitemaps, caching, firewalls)
 * 3. configuration_only       - Configuration Only (saved settings/options in wp_options without functional embedding)
 * 4. database_footprint_only  - Database Footprint Only (dedicated tables/metadata exist, no functional usage)
 * 5. historical_data_only     - Historical Data Only (residual tables/metadata for inactive/unembedded plugins)
 * 6. no_usage_detected        - No Usage Evidence Found (active with no usage or inactive with no footprint)
 * 7. usage_unknown            - Usage Unknown / Incomplete Scan (admin tools, archetypes, or scan limitations)
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Plugin_Usage_Detector {

    /**
     * Singleton instance.
     *
     * @var SBA_Plugin_Usage_Detector|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return SBA_Plugin_Usage_Detector
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect usage evidence and classify plugin status.
     *
     * @param string $plugin_file Plugin file path relative to wp-content/plugins.
     * @param array  $plugin_meta Plugin header data from get_plugins().
     * @param bool   $is_active   Whether the plugin is currently active.
     * @param bool   $is_self     Whether this is the Site Bloat & Storage Auditor plugin.
     * @param array  $db_entry    Database table details from storage audit.
     * @param array  $context     Shared scan cache across plugins to avoid repeated DB scans.
     * @return array Complete detection report with status, label, confidence, evidence categories, and deletion safety.
     */
    public function detect( $plugin_file, $plugin_meta, $is_active, $is_self, $db_entry = [], &$context = [] ) {
        global $wpdb;

        $plugin_file_clean = wp_normalize_path( $plugin_file );
        $slug = ( false !== strpos( $plugin_file_clean, '/' ) ) ? dirname( $plugin_file_clean ) : sanitize_title( pathinfo( $plugin_file_clean, PATHINFO_FILENAME ) );
        $slug = sanitize_key( $slug );
        $base = sanitize_title( pathinfo( $plugin_file_clean, PATHINFO_FILENAME ) );

        // 1. Check multisite network protection
        $is_network_active = false;
        if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) ) {
            $is_network_active = true;
        }

        // 2. Check file readability
        $full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
        if ( ! file_exists( $full_path ) ) {
            return [
                'status'             => 'usage_unknown',
                'label'              => __( 'Usage Unknown / Incomplete Scan', 'site-bloat-auditor' ),
                'confidence'         => 'low',
                'checks_performed'   => [ __( 'Verified plugin file presence on disk', 'site-bloat-auditor' ) ],
                'evidence_functional'=> [],
                'evidence_operational'=> [],
                'evidence_config'    => [],
                'evidence_db'        => [],
                'evidence_historical'=> [],
                'evidence_found'     => [],
                'evidence_not_found' => [ __( 'Plugin file not found on server disk.', 'site-bloat-auditor' ) ],
                'scan_limitations'   => __( 'Plugin file is missing or unreadable on disk.', 'site-bloat-auditor' ),
                'recommended_action' => __( 'File is missing. Reinstall or remove entry from WordPress.', 'site-bloat-auditor' ),
                'archetype'          => null,
                'evidence'           => [ __( 'Plugin file is missing from disk or unreadable.', 'site-bloat-auditor' ) ],
                'is_deletable'       => false,
                'protect_reason'     => __( 'Plugin file not found on disk.', 'site-bloat-auditor' ),
                'db_tables_warning'  => '',
            ];
        }

        // 3. Self-protection (Site Bloat & Storage Auditor)
        if ( $is_self ) {
            return [
                'status'             => 'usage_unknown',
                'label'              => __( 'Usage Unknown / Incomplete Scan', 'site-bloat-auditor' ),
                'confidence'         => 'high',
                'checks_performed'   => [
                    __( 'Administrative tool inspection', 'site-bloat-auditor' ),
                    __( 'Self-auditor protection check', 'site-bloat-auditor' ),
                ],
                'evidence_functional'=> [],
                'evidence_operational'=> [
                    __( 'Active Site Bloat & Storage Auditor plugin executing admin dashboard and storage analysis.', 'site-bloat-auditor' ),
                ],
                'evidence_config'    => [],
                'evidence_db'        => [],
                'evidence_historical'=> [],
                'evidence_found'     => [
                    __( 'Active Site Bloat & Storage Auditor plugin executing in WordPress admin.', 'site-bloat-auditor' ),
                ],
                'evidence_not_found' => [
                    __( 'No public frontend shortcodes or blocks registered (administrative tool).', 'site-bloat-auditor' ),
                ],
                'scan_limitations'   => __( 'Administrative audit tools operate exclusively within the WordPress admin dashboard and on-demand AJAX handlers. They do not render public frontend content.', 'site-bloat-auditor' ),
                'recommended_action' => __( 'This plugin provides the current audit interface and storage clinic. It cannot be deactivated or deleted from itself.', 'site-bloat-auditor' ),
                'archetype'          => [
                    'category'    => __( 'Administration & Developer Tooling', 'site-bloat-auditor' ),
                    'explanation' => __( 'Administrative audit utility running in WordPress admin.', 'site-bloat-auditor' ),
                ],
                'evidence'           => [
                    __( 'Active Site Bloat & Storage Auditor plugin executing.', 'site-bloat-auditor' ),
                ],
                'is_deletable'       => false,
                'protect_reason'     => __( 'Site Bloat & Storage Auditor (this active plugin) cannot be deleted or deactivated from itself.', 'site-bloat-auditor' ),
                'db_tables_warning'  => '',
            ];
        }

        $checks_performed     = [];
        $evidence_functional  = [];
        $evidence_operational = [];
        $evidence_config      = [];
        $evidence_db          = [];
        $evidence_historical  = [];
        $evidence_not_found   = [];

        // Warm shared context caches to prevent duplicate queries across multiple plugins
        $this->warm_context_cache( $context );

        // ---------------------------------------------------------------------
        // Check A: Database Footprint (Dedicated Tables & Sizes)
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Audited database for dedicated plugin tables and footprints', 'site-bloat-auditor' );
        $table_count = ! empty( $db_entry['count'] ) ? (int) $db_entry['count'] : 0;
        $table_size  = ! empty( $db_entry['size_formatted'] ) ? $db_entry['size_formatted'] : '0 KB';
        $db_tables_warning = '';

        if ( $table_count > 0 ) {
            $evidence_db[] = sprintf(
                /* translators: 1: table count, 2: formatted size */
                __( 'Maintains %1$d dedicated database table(s) (%2$s).', 'site-bloat-auditor' ),
                $table_count,
                $table_size
            );
            $db_tables_warning = sprintf(
                /* translators: 1: table count, 2: formatted size */
                __( 'Warning: %1$d database table(s) (%2$s) belong to this plugin. Deleting plugin files will not remove database tables unless a plugin uninstaller is executed.', 'site-bloat-auditor' ),
                $table_count,
                $table_size
            );
        } else {
            $evidence_not_found[] = __( 'No dedicated database tables found.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check B: Plugin Configuration in wp_options
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Inspected wp_options for saved configurations and preferences', 'site-bloat-auditor' );
        $config_options = $this->find_plugin_options( $slug, $base, $context );
        if ( ! empty( $config_options ) ) {
            $evidence_config[] = sprintf(
                /* translators: %s: list of options found */
                __( 'Saved plugin configuration settings found in wp_options (%s).', 'site-bloat-auditor' ),
                implode( ', ', array_slice( $config_options, 0, 3 ) ) . ( count( $config_options ) > 3 ? '...' : '' )
            );
        } else {
            $evidence_not_found[] = __( 'No saved configuration options found in wp_options.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check C: Content Shortcodes in Published Content
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Searched published posts for shortcode tags and references', 'site-bloat-auditor' );
        $shortcode_evidence = $this->find_shortcode_references( $slug, $base, $plugin_file );
        if ( ! empty( $shortcode_evidence ) ) {
            $evidence_functional[] = $shortcode_evidence;
        } else {
            $evidence_not_found[] = __( 'No shortcode tags found in published posts or pages.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check D: Gutenberg Blocks in Post Content
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Searched post content for Gutenberg block comments (<!-- wp:...)', 'site-bloat-auditor' );
        $block_evidence = $this->find_block_references( $slug, $base, $plugin_file );
        if ( ! empty( $block_evidence ) ) {
            $evidence_functional[] = $block_evidence;
        } else {
            $evidence_not_found[] = __( 'No Gutenberg block markup detected in post content.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check E: Elementor & Page Builder Widgets
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Inspected Elementor data for builder widgets and elements', 'site-bloat-auditor' );
        $builder_evidence = $this->find_elementor_references( $slug, $base );
        if ( ! empty( $builder_evidence ) ) {
            $evidence_functional[] = $builder_evidence;
        } else {
            $evidence_not_found[] = __( 'No Elementor or page builder widgets detected in published pages.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check F: Active Sidebar Widgets
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Inspected active widgets registered in site sidebars', 'site-bloat-auditor' );
        $widget_evidence = $this->find_active_widgets( $slug, $base, $context );
        if ( ! empty( $widget_evidence ) ) {
            $evidence_functional[] = $widget_evidence;
        } else {
            $evidence_not_found[] = __( 'No active widgets registered in sidebars.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check G: Custom Post Types with Published Content
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Checked for custom post types with published user content', 'site-bloat-auditor' );
        $cpt_evidence = $this->find_cpt_content( $slug, $base, $context );
        if ( ! empty( $cpt_evidence ) ) {
            $evidence_functional[] = $cpt_evidence;
        } else {
            $evidence_not_found[] = __( 'No custom post types with published content found.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check H: Post Metadata (Manual vs Automatic)
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Evaluated post metadata for intentional user configurations', 'site-bloat-auditor' );
        $meta_evidence = $this->find_post_metadata( $slug, $base, $plugin_file );
        if ( ! empty( $meta_evidence['functional'] ) ) {
            $evidence_functional[] = $meta_evidence['functional'];
        }
        if ( ! empty( $meta_evidence['db'] ) ) {
            $evidence_db[] = $meta_evidence['db'];
        }
        if ( empty( $meta_evidence['functional'] ) && empty( $meta_evidence['db'] ) ) {
            $evidence_not_found[] = __( 'No plugin-specific metadata found on published posts.', 'site-bloat-auditor' );
        }

        // ---------------------------------------------------------------------
        // Check I: Frontend Scripts and Styles
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Inspected registered and enqueued frontend scripts and styles', 'site-bloat-auditor' );
        $asset_evidence = $this->find_frontend_assets( $slug, $base, $is_active );
        if ( ! empty( $asset_evidence ) ) {
            $evidence_functional[] = $asset_evidence;
        }

        // ---------------------------------------------------------------------
        // Check J: Automated Sitewide Operational Activity & Background Services
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Checked for automated sitewide services, schema, and background cron', 'site-bloat-auditor' );
        $operational_evidence = $this->find_operational_activity( $slug, $base, $plugin_file, $plugin_meta, $is_active, $context );
        if ( ! empty( $operational_evidence ) ) {
            $evidence_operational = array_merge( $evidence_operational, $operational_evidence );
        }

        // ---------------------------------------------------------------------
        // Check K: Plugin-Specific Evaluators (Smart Slider 3, Yoast SEO, etc.)
        // ---------------------------------------------------------------------
        $this->evaluate_plugin_specific_rules(
            $slug,
            $base,
            $plugin_file,
            $is_active,
            $evidence_functional,
            $evidence_operational,
            $evidence_config,
            $evidence_db,
            $evidence_historical,
            $evidence_not_found
        );

        // ---------------------------------------------------------------------
        // Check L: Plugin & Active Theme Dependencies (Deletion Safety)
        // ---------------------------------------------------------------------
        $checks_performed[] = __( 'Verified dependencies across other active plugins and active theme', 'site-bloat-auditor' );
        $dependency_info = $this->check_plugin_dependencies( $plugin_file, $slug, $context );
        $theme_relation  = self::get_theme_plugin_relation( $plugin_file, $slug, $plugin_meta, $context['theme_context'] ?? null );

        $is_deletable   = true;
        $protect_reasons = [];

        if ( $is_network_active ) {
            $is_deletable      = false;
            $protect_reasons[] = __( 'Network-activated plugin cannot be deleted from single-site context.', 'site-bloat-auditor' );
        }
        if ( ! empty( $dependency_info['is_required'] ) ) {
            $is_deletable = false;
            $dep_reason   = sprintf(
                /* translators: %s: dependent plugin names */
                __( 'Required by active plugin(s): %s.', 'site-bloat-auditor' ),
                implode( ', ', $dependency_info['dependents'] )
            );
            $protect_reasons[]     = $dep_reason;
            $evidence_operational[] = $dep_reason;
        }
        if ( ! empty( $theme_relation['is_required'] ) ) {
            $is_deletable   = false;
            $theme_label    = $theme_relation['theme_name'] ?: __( 'Active Theme', 'site-bloat-auditor' );
            $theme_reason   = sprintf(
                /* translators: %s: active theme name */
                __( 'Required core plugin for active theme (%s).', 'site-bloat-auditor' ),
                $theme_label
            );
            $protect_reasons[] = $theme_reason;
            if ( $is_active ) {
                $evidence_operational[] = sprintf(
                    /* translators: %s: active theme name */
                    __( 'Required core framework plugin actively powering the "%s" theme (theme options, builders, and templates).', 'site-bloat-auditor' ),
                    $theme_label
                );
            }
        }

        $protect_reason = implode( ' ', $protect_reasons );

        // Check background archetype
        $archetype = sba_get_plugin_archetype( $slug, $plugin_file, $plugin_meta );

        // ---------------------------------------------------------------------
        // Determine New Usage Classification Status
        // ---------------------------------------------------------------------
        $status             = 'no_usage_detected';
        $label              = __( 'No Usage Evidence Found', 'site-bloat-auditor' );
        $confidence         = 'medium';
        $scan_limitations   = '';
        $recommended_action = '';

        if ( ! empty( $evidence_functional ) ) {
            if ( $is_active ) {
                $status             = 'functional_usage';
                $label              = __( 'Functional Usage Detected', 'site-bloat-auditor' );
                $confidence         = 'high';
                $scan_limitations   = __( 'Positive functional usage verified in published posts, pages, widgets, or builder layouts.', 'site-bloat-auditor' );
                $recommended_action = __( 'Plugin is actively embedded in site content or frontend layouts. Keep active.', 'site-bloat-auditor' );
            } else {
                $status             = 'historical_data_only';
                $label              = __( 'Historical Data Only (Unembedded / Inactive)', 'site-bloat-auditor' );
                $confidence         = 'high';
                $scan_limitations   = __( 'Plugin is currently inactive, but content contains unrendered shortcodes or historical references.', 'site-bloat-auditor' );
                $recommended_action = __( 'Review published posts to clean up inactive shortcodes or reactivate plugin if content is still needed.', 'site-bloat-auditor' );
            }
        } elseif ( $is_active && ! empty( $evidence_operational ) ) {
            $status             = 'operational_activity';
            $label              = __( 'Operational Activity Detected', 'site-bloat-auditor' );
            $confidence         = 'high';
            $scan_limitations   = __( 'Plugin provides active sitewide automated services (SEO headers, schema, sitemaps, caching, firewalls, or background cron).', 'site-bloat-auditor' );
            $recommended_action = __( 'Plugin runs automated infrastructure or sitewide operational tasks without requiring per-post shortcodes. Keep active.', 'site-bloat-auditor' );
        } elseif ( ! empty( $evidence_config ) && empty( $evidence_db ) ) {
            $status             = 'configuration_only';
            $label              = __( 'Configuration Only', 'site-bloat-auditor' );
            $confidence         = 'high';
            $scan_limitations   = __( 'Saved settings exist in wp_options, but no functional content integration or operational services were detected.', 'site-bloat-auditor' );
            $recommended_action = $is_active
                ? __( 'Plugin is activated with saved configuration, but not embedded in published content. Verify whether administrative features are utilized.', 'site-bloat-auditor' )
                : __( 'Plugin is inactive with saved settings in wp_options. Safe to delete if preferences are no longer needed.', 'site-bloat-auditor' );
        } elseif ( ! empty( $evidence_db ) ) {
            $status             = 'database_footprint_only';
            $label              = __( 'Database Footprint Only', 'site-bloat-auditor' );
            $confidence         = 'high';
            $scan_limitations   = __( 'Dedicated database tables or storage records exist, but no functional embedding or operational services were detected.', 'site-bloat-auditor' );
            $recommended_action = $is_active
                ? __( 'Active plugin with database tables, but no published content integration detected. Check if plugin is used internally or can be deactivated.', 'site-bloat-auditor' )
                : __( 'Inactive plugin maintaining residual database tables. Safe to delete plugin files; tables will remain unless purged with database audit tools.', 'site-bloat-auditor' );
        } elseif ( ! empty( $evidence_historical ) ) {
            $status             = 'historical_data_only';
            $label              = __( 'Historical Data Only', 'site-bloat-auditor' );
            $confidence         = 'medium';
            $scan_limitations   = __( 'Residual data or historical records found in database without active integration.', 'site-bloat-auditor' );
            $recommended_action = __( 'Safe to delete if historical data is no longer required.', 'site-bloat-auditor' );
        } elseif ( $archetype ) {
            $status             = 'usage_unknown';
            $label              = __( 'Usage Unknown / Incomplete Scan', 'site-bloat-auditor' );
            $confidence         = 'medium';
            $scan_limitations   = sprintf(
                /* translators: 1: archetype category, 2: archetype explanation */
                __( 'Infrastructure archetype (%1$s): %2$s', 'site-bloat-auditor' ),
                $archetype['category'],
                $archetype['explanation']
            );
            $recommended_action = __( 'This plugin provides background, infrastructure, security, or developer tooling. Confirm with administrator before deactivating.', 'site-bloat-auditor' );
        } elseif ( $is_active ) {
            $status             = 'no_usage_detected';
            $label              = __( 'Active · No Usage Detected', 'site-bloat-auditor' );
            $confidence         = 'medium';
            $scan_limitations   = __( 'Static database and content analysis found no shortcodes, blocks, widgets, options, or tables.', 'site-bloat-auditor' );
            $recommended_action = __( 'Installed and activated, but no usage evidence was found. Review on staging, then deactivate if not required.', 'site-bloat-auditor' );
        } else {
            $status             = 'no_usage_detected';
            $label              = __( 'Inactive · No Usage Detected', 'site-bloat-auditor' );
            $confidence         = 'high';
            $scan_limitations   = __( 'Plugin is inactive with no detected database footprint, options, or content references.', 'site-bloat-auditor' );
            $recommended_action = __( 'Plugin is inactive with no footprint. Safe to permanently delete.', 'site-bloat-auditor' );
        }

        // Aggregate unified evidence list for modal and quick tooltips
        $all_positive = array_merge( $evidence_functional, $evidence_operational, $evidence_config, $evidence_db, $evidence_historical );

        return [
            'status'               => $status,
            'label'                => $label,
            'confidence'           => $confidence,
            'checks_performed'     => $checks_performed,
            'evidence_functional'  => $evidence_functional,
            'evidence_operational' => $evidence_operational,
            'evidence_config'      => $evidence_config,
            'evidence_db'          => $evidence_db,
            'evidence_historical'  => $evidence_historical,
            'evidence_found'       => $all_positive,
            'evidence_not_found'   => $evidence_not_found,
            'scan_limitations'     => $scan_limitations,
            'recommended_action'   => $recommended_action,
            'archetype'            => $archetype,
            'evidence'             => ! empty( $all_positive ) ? $all_positive : [ $scan_limitations ],
            'is_deletable'         => $is_deletable,
            'protect_reason'       => $protect_reason,
            'db_tables_warning'    => $db_tables_warning,
        ];
    }

    /**
     * Warm shared context caches to avoid redundant queries across plugins.
     */
    private function warm_context_cache( array &$context ) {
        global $wpdb;

        if ( ! isset( $context['active_widgets'] ) ) {
            $sidebars_widgets  = get_option( 'sidebars_widgets', [] );
            $active_widget_ids = [];
            if ( is_array( $sidebars_widgets ) ) {
                foreach ( $sidebars_widgets as $sb_name => $widgets ) {
                    if ( 'wp_inactive_widgets' === $sb_name || 'orphaned_widgets' === $sb_name ) {
                        continue;
                    }
                    if ( is_array( $widgets ) ) {
                        foreach ( $widgets as $w_id ) {
                            $active_widget_ids[] = [ 'id' => (string) $w_id, 'sidebar' => (string) $sb_name ];
                        }
                    }
                }
            }
            $context['active_widgets'] = $active_widget_ids;
        }

        if ( ! isset( $context['custom_post_types'] ) ) {
            $cpt_results = $wpdb->get_results(
                "SELECT post_type, COUNT(*) as cnt FROM {$wpdb->posts}
                 WHERE post_status IN ('publish', 'private')
                   AND post_type NOT IN ('post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation')
                 GROUP BY post_type"
            );
            $cpts = [];
            if ( $cpt_results ) {
                foreach ( $cpt_results as $cpt_row ) {
                    $cpts[ $cpt_row->post_type ] = (int) $cpt_row->cnt;
                }
            }
            $context['custom_post_types'] = $cpts;
        }

        if ( ! isset( $context['cron_hooks'] ) ) {
            $crons = _get_cron_array();
            $cron_hooks = [];
            if ( is_array( $crons ) ) {
                foreach ( $crons as $timestamp => $hooks ) {
                    if ( is_array( $hooks ) ) {
                        foreach ( $hooks as $hook_name => $hook_data ) {
                            $cron_hooks[] = (string) $hook_name;
                        }
                    }
                }
            }
            $context['cron_hooks'] = array_unique( $cron_hooks );
        }

        if ( ! isset( $context['active_plugins'] ) ) {
            $context['active_plugins'] = (array) get_option( 'active_plugins', [] );
        }

        if ( ! isset( $context['all_plugins'] ) && function_exists( 'get_plugins' ) ) {
            $context['all_plugins'] = get_plugins();
        }

        if ( ! isset( $context['theme_context'] ) ) {
            $context['theme_context'] = self::get_active_theme_context();
        }
    }

    /**
     * Search options table for plugin-specific configurations.
     */
    private function find_plugin_options( $slug, $base, array $context ) {
        global $wpdb;

        $slug_clean      = sanitize_key( $slug );
        $slug_underscore = str_replace( '-', '_', $slug_clean );
        $base_clean      = $base ? sanitize_key( $base ) : '';
        $base_underscore = $base_clean ? str_replace( '-', '_', $base_clean ) : '';

        $raw_prefixes = [
            $slug_clean . '_',
            $slug_underscore . '_',
            $base_clean ? ( $base_clean . '_' ) : '',
            $base_underscore ? ( $base_underscore . '_' ) : '',
        ];

        $known_option_prefixes = [
            'us-core'     => [ 'usof_options_', 'us_theme_', 'us_license_', 'us_addons_' ],
            'js_composer' => [ 'wpb_js_' ],
        ];
        if ( isset( $known_option_prefixes[ $slug_clean ] ) ) {
            $raw_prefixes = array_merge( $raw_prefixes, $known_option_prefixes[ $slug_clean ] );
        }

        $prefixes = array_unique( array_filter( $raw_prefixes ) );

        $where_clauses = [];
        $params        = [];
        foreach ( $prefixes as $pfx ) {
            $where_clauses[] = 'option_name LIKE %s';
            $params[]        = $wpdb->esc_like( $pfx ) . '%';
        }

        if ( empty( $where_clauses ) ) {
            return [];
        }

        $sql = "SELECT option_name FROM {$wpdb->options} WHERE (" . implode( ' OR ', $where_clauses ) . ") AND option_name NOT LIKE %s LIMIT 10";
        $params[] = '%_transient%';

        $rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );
        return array_map( 'sanitize_text_field', (array) $rows );
    }

    /**
     * Search for shortcodes in published content.
     */
    private function find_shortcode_references( $slug, $base, $plugin_file ) {
        global $wpdb;

        $shortcode_tags = [ $slug ];
        if ( $base && $base !== $slug ) {
            $shortcode_tags[] = $base;
        }

        // Special shortcode aliases/prefixes for known plugins
        if ( 'contact-form-7' === $slug ) {
            $shortcode_tags[] = 'contact-form-7';
        } elseif ( 'smart-slider-3' === $slug || 'nextend-smart-slider3' === $slug ) {
            $shortcode_tags[] = 'smartslider3';
        } elseif ( 'woocommerce' === $slug ) {
            $shortcode_tags[] = 'woocommerce_checkout';
            $shortcode_tags[] = 'woocommerce_cart';
            $shortcode_tags[] = 'product';
            $shortcode_tags[] = 'products';
        } elseif ( 'us-core' === $slug ) {
            $shortcode_tags[] = 'us_';
        } elseif ( 'js_composer' === $slug ) {
            $shortcode_tags[] = 'vc_row';
            $shortcode_tags[] = 'vc_section';
            $shortcode_tags[] = 'vc_column';
            $shortcode_tags[] = 'vc_column_text';
        }

        foreach ( array_unique( $shortcode_tags ) as $tag ) {
            $like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_status IN ('publish', 'private')
                 LIMIT 1",
                $like
            ) );

            if ( $found ) {
                $matched_tag = $tag;
                if ( '_' === substr( $tag, -1 ) && ! empty( $found->post_content ) ) {
                    if ( preg_match( '/\[(' . preg_quote( $tag, '/' ) . '[a-z0-9_]*)/i', $found->post_content, $matches ) ) {
                        $matched_tag = $matches[1];
                    }
                }
                return sprintf(
                    /* translators: 1: shortcode tag, 2: post title, 3: post ID */
                    __( 'Found shortcode [%1$s] in published post "%2$s" (ID #%3$d).', 'site-bloat-auditor' ),
                    $matched_tag,
                    esc_html( $found->post_title ),
                    (int) $found->ID
                );
            }
        }

        return '';
    }

    /**
     * Search for Gutenberg blocks in published content.
     */
    private function find_block_references( $slug, $base, $plugin_file ) {
        global $wpdb;

        $block_prefixes = [ '<!-- wp:' . $slug ];
        if ( $base && $base !== $slug ) {
            $block_prefixes[] = '<!-- wp:' . $base;
        }

        // Specific block namespaces
        if ( 'smart-slider-3' === $slug ) {
            $block_prefixes[] = '<!-- wp:nextend/smartslider3';
            $block_prefixes[] = '<!-- wp:smartslider3';
        } elseif ( 'wordpress-seo' === $slug ) {
            $block_prefixes[] = '<!-- wp:yoast/faq-block';
            $block_prefixes[] = '<!-- wp:yoast/how-to-block';
        }

        foreach ( $block_prefixes as $prefix ) {
            $like = '%' . $wpdb->esc_like( $prefix ) . '%';
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_content LIKE %s
                   AND post_status IN ('publish', 'private')
                 LIMIT 1",
                $like
            ) );

            if ( $found ) {
                return sprintf(
                    /* translators: 1: block namespace, 2: post title, 3: post ID */
                    __( 'Found Gutenberg block (%1$s) in published post "%2$s" (ID #%3$d).', 'site-bloat-auditor' ),
                    trim( $prefix, '<!- ' ),
                    esc_html( $found->post_title ),
                    (int) $found->ID
                );
            }
        }

        return '';
    }

    /**
     * Search for Elementor widgets in _elementor_data.
     */
    private function find_elementor_references( $slug, $base ) {
        global $wpdb;

        $widget_keys = [ '"widgetType":"' . $slug . '"' ];
        if ( $base && $base !== $slug ) {
            $widget_keys[] = '"widgetType":"' . $base . '"';
        }
        if ( 'smart-slider-3' === $slug ) {
            $widget_keys[] = '"widgetType":"smartslider3"';
            $widget_keys[] = '"widgetType":"smartslider"';
        }

        foreach ( $widget_keys as $key ) {
            $like = '%' . $wpdb->esc_like( $key ) . '%';
            $found = $wpdb->get_row( $wpdb->prepare(
                "SELECT p.ID, p.post_title FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.meta_key = '_elementor_data'
                   AND m.meta_value LIKE %s
                   AND p.post_status IN ('publish', 'private')
                 LIMIT 1",
                $like
            ) );

            if ( $found ) {
                return sprintf(
                    /* translators: 1: post title, 2: post ID */
                    __( 'Found Elementor page builder widget in published page "%1$s" (ID #%2$d).', 'site-bloat-auditor' ),
                    esc_html( $found->post_title ),
                    (int) $found->ID
                );
            }
        }

        return '';
    }

    /**
     * Search for active widgets in sidebars.
     */
    private function find_active_widgets( $slug, $base, array $context ) {
        if ( empty( $context['active_widgets'] ) || ! is_array( $context['active_widgets'] ) ) {
            return '';
        }

        $matching_widgets = [];
        $slug_underscore  = str_replace( '-', '_', $slug );
        $base_underscore  = $base ? str_replace( '-', '_', $base ) : '';

        foreach ( $context['active_widgets'] as $w_info ) {
            $w_id = $w_info['id'];
            $is_match = (
                false !== stripos( $w_id, $slug ) ||
                false !== stripos( $w_id, $slug_underscore ) ||
                ( $base && ( false !== stripos( $w_id, $base ) || false !== stripos( $w_id, $base_underscore ) ) ) ||
                ( 'us-core' === $slug && 0 === stripos( $w_id, 'us_' ) )
            );
            if ( $is_match ) {
                $matching_widgets[] = sprintf( '%s (%s)', $w_id, $w_info['sidebar'] );
            }
        }

        if ( ! empty( $matching_widgets ) ) {
            return sprintf(
                /* translators: %s: widget list */
                __( 'Active widget registered in sidebars: %s.', 'site-bloat-auditor' ),
                implode( ', ', array_slice( $matching_widgets, 0, 3 ) )
            );
        }

        return '';
    }

    /**
     * Search for custom post types with published content.
     */
    private function find_cpt_content( $slug, $base, array $context ) {
        if ( empty( $context['custom_post_types'] ) || ! is_array( $context['custom_post_types'] ) ) {
            return '';
        }

        $known_cpts = [
            'us-core'     => [ 'us_page_block', 'us_content_template', 'us_header', 'us_grid_layout', 'us_portfolio', 'us_testimonial' ],
            'js_composer' => [ 'vc_grid_item' ],
        ];
        $explicit_cpts = $known_cpts[ $slug ] ?? [];

        $matching_cpts = [];
        foreach ( $context['custom_post_types'] as $cpt_name => $cpt_count ) {
            $is_known = in_array( $cpt_name, $explicit_cpts, true );
            if ( $is_known || false !== stripos( $cpt_name, $slug ) || ( $base && false !== stripos( $cpt_name, $base ) ) ) {
                $matching_cpts[] = "{$cpt_name} ({$cpt_count})";
            }
        }

        if ( ! empty( $matching_cpts ) ) {
            return sprintf(
                /* translators: %s: custom post types */
                __( 'Published user content found in custom post type(s): %s.', 'site-bloat-auditor' ),
                implode( ', ', $matching_cpts )
            );
        }

        return '';
    }

    /**
     * Search post metadata, distinguishing manual user configurations from automatic default entries.
     */
    private function find_post_metadata( $slug, $base, $plugin_file ) {
        global $wpdb;

        $results = [ 'functional' => '', 'db' => '' ];

        // Check for intentional manual metadata vs automatic metadata
        if ( 'wordpress-seo' === $slug ) {
            // Check if users actually entered manual custom SEO titles or descriptions on published posts
            $manual_seo_count = (int) $wpdb->get_var(
                "SELECT COUNT(m.meta_id) FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw')
                   AND m.meta_value != ''
                   AND p.post_status IN ('publish', 'private')"
            );

            if ( $manual_seo_count > 0 ) {
                $results['functional'] = sprintf(
                    /* translators: %d: count of customized SEO records */
                    __( 'Custom SEO configurations (titles/descriptions/keywords) authored on %d published post(s).', 'site-bloat-auditor' ),
                    $manual_seo_count
                );
            }
            return $results;
        }

        if ( 'us-core' === $slug ) {
            $us_meta_count = (int) $wpdb->get_var(
                "SELECT COUNT(m.meta_id) FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
                 WHERE m.meta_key IN ('us_header_id', 'us_content_id', 'us_footer_id', 'us_titlebar_id', 'us_sidebar_id', 'us_tile_additional_image')
                   AND m.meta_value != ''
                   AND m.meta_value != '__defaults__'
                   AND p.post_status IN ('publish', 'private')"
            );
            if ( $us_meta_count > 0 ) {
                $results['functional'] = sprintf(
                    /* translators: %d: count of layout override records */
                    __( 'UpSolution Core layout/template overrides configured on %d published post(s).', 'site-bloat-auditor' ),
                    $us_meta_count
                );
                return $results;
            }
        }

        if ( 'js_composer' === $slug ) {
            $wpb_meta_count = (int) $wpdb->get_var(
                "SELECT COUNT(m.meta_id) FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
                 WHERE ((m.meta_key = '_wpb_vc_js_status' AND m.meta_value = 'true')
                     OR (m.meta_key = '_wpb_shortcodes_custom_css' AND m.meta_value != ''))
                   AND p.post_status IN ('publish', 'private')"
            );
            if ( $wpb_meta_count > 0 ) {
                $results['functional'] = sprintf(
                    /* translators: %d: count of WPBakery post layouts */
                    __( 'WPBakery Page Builder layouts configured on %d published post(s).', 'site-bloat-auditor' ),
                    $wpb_meta_count
                );
                return $results;
            }
        }

        // General postmeta check
        $meta_like1 = '_' . $slug . '%';
        $meta_like2 = $slug . '%';
        $meta_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(pm.meta_id) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE (pm.meta_key LIKE %s OR pm.meta_key LIKE %s)
               AND pm.meta_value != ''
               AND p.post_status IN ('publish', 'private')
             LIMIT 5",
            $meta_like1,
            $meta_like2
        ) );

        if ( $meta_count > 0 ) {
            $results['db'] = sprintf(
                /* translators: %d: count of meta records */
                __( 'Post metadata entries associated with plugin on published content (%d records).', 'site-bloat-auditor' ),
                $meta_count
            );
        }

        return $results;
    }

    /**
     * Check if frontend scripts or styles are registered / enqueued by this plugin.
     */
    private function find_frontend_assets( $slug, $base, $is_active ) {
        if ( ! $is_active ) {
            return '';
        }

        global $wp_scripts, $wp_styles;
        $matched_assets = [];

        if ( ! empty( $wp_scripts->registered ) && is_array( $wp_scripts->registered ) ) {
            foreach ( $wp_scripts->registered as $handle => $dep ) {
                $src = is_string( $dep->src ) ? $dep->src : '';
                if ( $src && ( false !== stripos( $src, '/' . $slug . '/' ) || ( $base && false !== stripos( $src, '/' . $base . '/' ) ) ) ) {
                    $matched_assets[] = $handle . ' (script)';
                    break;
                }
            }
        }

        if ( ! empty( $wp_styles->registered ) && is_array( $wp_styles->registered ) ) {
            foreach ( $wp_styles->registered as $handle => $dep ) {
                $src = is_string( $dep->src ) ? $dep->src : '';
                if ( $src && ( false !== stripos( $src, '/' . $slug . '/' ) || ( $base && false !== stripos( $src, '/' . $base . '/' ) ) ) ) {
                    $matched_assets[] = $handle . ' (style)';
                    break;
                }
            }
        }

        if ( ! empty( $matched_assets ) ) {
            return sprintf(
                /* translators: %s: asset list */
                __( 'Registered frontend assets: %s.', 'site-bloat-auditor' ),
                implode( ', ', $matched_assets )
            );
        }

        return '';
    }

    /**
     * Check for automated sitewide operational activity (SEO, schema, sitemaps, caching, firewalls, crons).
     */
    private function find_operational_activity( $slug, $base, $plugin_file, $plugin_meta, $is_active, array $context ) {
        $operational = [];

        if ( ! $is_active ) {
            return $operational;
        }

        // A. Active recurring cron tasks
        if ( ! empty( $context['cron_hooks'] ) ) {
            $matching_crons = [];
            foreach ( $context['cron_hooks'] as $hook ) {
                if ( false !== stripos( $hook, $slug ) || ( $base && false !== stripos( $hook, $base ) ) ) {
                    $matching_crons[] = $hook;
                }
            }
            if ( ! empty( $matching_crons ) ) {
                $operational[] = sprintf(
                    /* translators: %s: cron hook list */
                    __( 'Active scheduled background cron task(s): %s.', 'site-bloat-auditor' ),
                    implode( ', ', array_slice( $matching_crons, 0, 2 ) )
                );
            }
        }

        // B. Sitewide Automated Services Archetypes
        $title = $plugin_meta['Name'] ?? '';
        $desc  = $plugin_meta['Description'] ?? '';
        $text  = strtolower( $title . ' ' . $desc . ' ' . $slug );

        if ( false !== strpos( $text, 'cache' ) || false !== strpos( $text, 'caching' ) ) {
            $operational[] = __( 'Provides automated sitewide page or object caching services.', 'site-bloat-auditor' );
        } elseif ( false !== strpos( $text, 'security' ) || false !== strpos( $text, 'firewall' ) || false !== strpos( $text, 'two-factor' ) ) {
            $operational[] = __( 'Executes automated sitewide request filtering and security protection.', 'site-bloat-auditor' );
        } elseif ( false !== strpos( $text, 'smtp' ) || false !== strpos( $text, 'mail' ) ) {
            $operational[] = __( 'Configured for transactional email delivery and SMTP routing.', 'site-bloat-auditor' );
        } elseif ( false !== strpos( $text, 'backup' ) ) {
            $operational[] = __( 'Automated site backup and disaster recovery service.', 'site-bloat-auditor' );
        }

        return $operational;
    }

    /**
     * Plugin-specific detection rules (Smart Slider 3, Yoast SEO, etc.).
     */
    private function evaluate_plugin_specific_rules(
        $slug,
        $base,
        $plugin_file,
        $is_active,
        array &$evidence_functional,
        array &$evidence_operational,
        array &$evidence_config,
        array &$evidence_db,
        array &$evidence_historical,
        array &$evidence_not_found
    ) {
        global $wpdb;

        // ---------------------------------------------------------------------
        // Rule 1: Smart Slider 3
        // ---------------------------------------------------------------------
        if ( 'smart-slider-3' === $slug || 'nextend-smart-slider3' === $slug ) {
            // Check if any slider records exist in DB
            $sliders_table = $wpdb->prefix . 'nextend2_smartslider3_sliders';
            $table_exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $sliders_table ) );

            $slider_count = 0;
            if ( $table_exists ) {
                $slider_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sliders_table}`" );
            }

            // Check if any sliders are actually embedded in published content
            $has_embed = false;
            foreach ( $evidence_functional as $ev ) {
                if ( false !== stripos( $ev, 'shortcode [smartslider3' ) || false !== stripos( $ev, 'nextend/smartslider' ) || false !== stripos( $ev, 'Elementor page builder widget' ) ) {
                    $has_embed = true;
                    break;
                }
            }

            if ( $slider_count > 0 && ! $has_embed ) {
                $evidence_db[] = sprintf(
                    /* translators: %d: count of saved sliders */
                    __( 'Maintains %d saved slider project(s) in database, but NO sliders are embedded in published pages or posts.', 'site-bloat-auditor' ),
                    $slider_count
                );
            } elseif ( 0 === $slider_count && ! $has_embed ) {
                $evidence_not_found[] = __( 'No slider projects exist in database and no slider embeddings detected.', 'site-bloat-auditor' );
            }

            if ( ! $has_embed ) {
                // Ensure no functional usage is declared solely because tables exist
                $evidence_functional = [];
            }
        }

        // ---------------------------------------------------------------------
        // Rule 2: Yoast SEO
        // ---------------------------------------------------------------------
        if ( 'wordpress-seo' === $slug ) {
            if ( $is_active ) {
                // Yoast when active provides automated sitewide SEO headers, OpenGraph, XML sitemaps, and Schema
                $evidence_operational[] = __( 'Active sitewide automated SEO output: meta titles, descriptions, schema.org graph, and XML sitemaps.', 'site-bloat-auditor' );
            }

            // Inspect indexables table (distinguish automatic population from intentional customization)
            $indexables_table = $wpdb->prefix . 'yoast_indexable';
            $indexables_exist = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $indexables_table ) );
            if ( $indexables_exist ) {
                $idx_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$indexables_table}`" );
                if ( $idx_count > 0 ) {
                    $evidence_db[] = sprintf(
                        /* translators: %d: indexable row count */
                        __( 'Contains %d automatically generated indexable search engine records.', 'site-bloat-auditor' ),
                        $idx_count
                    );
                }
            }
        }
    }

    /**
     * Check if other active plugins depend on this plugin.
     */
    private function check_plugin_dependencies( $plugin_file, $slug, array $context ) {
        $dependents = [];
        $active_plugins = $context['active_plugins'] ?? [];
        $all_plugins    = $context['all_plugins'] ?? [];

        // Known dependency parent-child mappings
        $dependency_map = [
            'woocommerce/woocommerce.php' => [ 'woocommerce', 'wc-' ],
            'advanced-custom-fields/acf.php' => [ 'acf-' ],
            'advanced-custom-fields-pro/acf.php' => [ 'acf-' ],
            'sitepress-multilingual-cms/sitepress.php' => [ 'wpml-', 'woocommerce-multilingual' ],
            'gravityforms/gravityforms.php' => [ 'gravityforms', 'gf-' ],
            'wordpress-seo/wp-seo.php' => [ 'wp-seo-', 'yoast-seo-' ],
        ];

        // Check if current plugin is a known dependency parent
        if ( isset( $dependency_map[ $plugin_file ] ) ) {
            $child_prefixes = $dependency_map[ $plugin_file ];
            foreach ( $active_plugins as $active_file ) {
                if ( $active_file === $plugin_file ) {
                    continue;
                }
                foreach ( $child_prefixes as $pfx ) {
                    if ( 0 === strpos( $active_file, $pfx ) || false !== strpos( $active_file, $pfx ) ) {
                        $p_name = $all_plugins[ $active_file ]['Name'] ?? $active_file;
                        $dependents[] = $p_name;
                        break;
                    }
                }
            }
        }

        // Check WordPress core Requires Plugins header (WP 6.5+)
        foreach ( $active_plugins as $active_file ) {
            if ( $active_file === $plugin_file ) {
                continue;
            }
            $meta = $all_plugins[ $active_file ] ?? [];
            $reqs = $meta['RequiresPlugins'] ?? '';
            if ( $reqs ) {
                $req_slugs = array_map( 'trim', explode( ',', $reqs ) );
                if ( in_array( $slug, $req_slugs, true ) ) {
                    $dependents[] = $meta['Name'] ?? $active_file;
                }
            }
        }

        return [
            'is_required' => ! empty( $dependents ),
            'dependents'  => array_unique( $dependents ),
        ];
    }

    /**
     * Collect active theme (and parent theme) metadata and bundled/required plugin declarations.
     *
     * @return array Normalized active theme context.
     */
    public static function get_active_theme_context() {
        $theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
        if ( ! $theme || ! is_object( $theme ) || ! $theme->exists() ) {
            return [
                'theme_name'     => '',
                'theme_slugs'    => [],
                'authors'        => [],
                'hosts'          => [],
                'dynamic_addons' => [],
                'bundled_zips'   => [],
            ];
        }

        $parent       = $theme->parent();
        $primary      = $parent ? $parent : $theme;
        $display_name = sanitize_text_field( (string) $primary->get( 'Name' ) );

        $theme_objects = $parent ? [ $theme, $parent ] : [ $theme ];
        $theme_slugs   = [];
        $authors       = [];
        $hosts         = [];
        $theme_dirs    = [];

        $ignored_authors = [ 'wordpress', 'wordpress.org', 'the wordpress team', 'automattic', 'admin', 'unknown', 'anonymous' ];
        $ignored_hosts   = [ 'wordpress.org', 'www.wordpress.org', 'github.com', 'themeforest.net', 'codecanyon.net', 'envato.com' ];

        foreach ( $theme_objects as $t_obj ) {
            $candidates = [
                strtolower( (string) $t_obj->get_template() ),
                strtolower( (string) $t_obj->get_stylesheet() ),
                sanitize_title( (string) $t_obj->get( 'Name' ) ),
            ];
            foreach ( $candidates as $cand ) {
                $cand_key = sanitize_key( $cand );
                if ( $cand_key ) {
                    $theme_slugs[] = $cand_key;
                }
            }

            $raw_author = strtolower( trim( wp_strip_all_tags( (string) $t_obj->get( 'Author' ) ) ) );
            if ( strlen( $raw_author ) >= 4 && ! in_array( $raw_author, $ignored_authors, true ) ) {
                $authors[] = $raw_author;
            }

            foreach ( [ $t_obj->get( 'AuthorURI' ), $t_obj->get( 'ThemeURI' ) ] as $uri ) {
                $host = self::extract_normalized_host( (string) $uri );
                if ( $host && ! in_array( $host, $ignored_hosts, true ) ) {
                    $hosts[] = $host;
                }
            }

            $dir = wp_normalize_path( (string) $t_obj->get_stylesheet_directory() );
            if ( $dir && is_dir( $dir ) ) {
                $theme_dirs[] = $dir;
            }
            $tpl_dir = wp_normalize_path( (string) $t_obj->get_template_directory() );
            if ( $tpl_dir && is_dir( $tpl_dir ) ) {
                $theme_dirs[] = $tpl_dir;
            }
        }

        $dynamic_addons = [];

        // 1. UpSolution Framework runtime addon config (Impreza / Zephyr)
        if ( function_exists( 'us_config' ) ) {
            $us_addons = us_config( 'addons', [] );
            if ( is_array( $us_addons ) ) {
                foreach ( $us_addons as $addon ) {
                    if ( ! is_array( $addon ) || empty( $addon['slug'] ) ) {
                        continue;
                    }
                    $a_slug = sanitize_key( $addon['slug'] );
                    $is_req = ( 'us-core' === $a_slug ) || ! empty( $addon['required'] );
                    $dynamic_addons[ $a_slug ] = [
                        'required' => $is_req,
                        'bundled'  => ! $is_req,
                        'source'   => 'theme_config',
                    ];
                }
            }
        }

        // 2. TGM Plugin Activation runtime registry
        if ( isset( $GLOBALS['tgmpa'] ) && is_object( $GLOBALS['tgmpa'] ) && ! empty( $GLOBALS['tgmpa']->plugins ) && is_array( $GLOBALS['tgmpa']->plugins ) ) {
            foreach ( $GLOBALS['tgmpa']->plugins as $tgm_key => $tgm_plugin ) {
                if ( ! is_array( $tgm_plugin ) ) {
                    continue;
                }
                $a_slug = sanitize_key( $tgm_plugin['slug'] ?? $tgm_key );
                if ( empty( $a_slug ) ) {
                    continue;
                }
                $is_req     = ! empty( $tgm_plugin['required'] );
                $has_source = ! empty( $tgm_plugin['source'] );
                if ( $is_req || $has_source ) {
                    $existing = $dynamic_addons[ $a_slug ] ?? [ 'required' => false, 'bundled' => false, 'source' => 'tgmpa' ];
                    $dynamic_addons[ $a_slug ] = [
                        'required' => $existing['required'] || $is_req,
                        'bundled'  => $existing['bundled'] || $has_source,
                        'source'   => 'tgmpa',
                    ];
                }
            }
        }

        // 3. Scan common theme bundled-plugin folders for .zip archives or directories
        $bundled_zips = [];
        $sub_dirs     = [ 'common/plugins', 'plugins', 'inc/plugins', 'includes/plugins', 'framework/plugins', 'lib/plugins', 'admin/plugins', 'vendor/plugins' ];
        foreach ( array_unique( $theme_dirs ) as $t_dir ) {
            foreach ( $sub_dirs as $sub ) {
                $check_dir = $t_dir . '/' . $sub;
                $can_read  = function_exists( 'sba_is_readable' ) ? sba_is_readable( $check_dir ) : @is_readable( $check_dir );
                if ( ! is_dir( $check_dir ) || ! $can_read ) {
                    continue;
                }
                $entries = @scandir( $check_dir );
                if ( ! is_array( $entries ) ) {
                    continue;
                }
                foreach ( $entries as $entry ) {
                    if ( '.' === $entry || '..' === $entry ) {
                        continue;
                    }
                    $full = $check_dir . '/' . $entry;
                    if ( is_file( $full ) && '.zip' === strtolower( substr( $entry, -4 ) ) ) {
                        $bundled_zips[] = sanitize_key( substr( $entry, 0, -4 ) );
                    } elseif ( is_dir( $full ) ) {
                        $bundled_zips[] = sanitize_key( $entry );
                    }
                }
            }
        }

        $ctx = [
            'theme_name'     => $display_name,
            'theme_slugs'    => array_values( array_unique( $theme_slugs ) ),
            'authors'        => array_values( array_unique( $authors ) ),
            'hosts'          => array_values( array_unique( $hosts ) ),
            'dynamic_addons' => $dynamic_addons,
            'bundled_zips'   => array_values( array_unique( array_filter( $bundled_zips ) ) ),
        ];

        return apply_filters( 'sba_active_theme_context', $ctx );
    }

    /**
     * Determine whether a plugin is required by or bundled with the active theme.
     *
     * @param string     $plugin_file   Plugin file path relative to plugins directory.
     * @param string     $slug          Normalized plugin slug.
     * @param array      $plugin_meta   Plugin metadata headers.
     * @param array|null $theme_context Optional pre-built theme context cache.
     * @return array|null Relationship details if linked to the active theme, or null.
     */
    public static function get_theme_plugin_relation( $plugin_file, $slug, $plugin_meta = [], $theme_context = null ) {
        if ( null === $theme_context || ! is_array( $theme_context ) ) {
            $theme_context = self::get_active_theme_context();
        }

        if ( empty( $theme_context['theme_slugs'] ) ) {
            return null;
        }

        $slug_key    = sanitize_key( $slug );
        $theme_name  = $theme_context['theme_name'] ?: ( $theme_context['theme_slugs'][0] ?? '' );
        $theme_slugs = $theme_context['theme_slugs'];

        // Curated registry of active themes and their required core / bundled plugins
        $known_theme_companions = [
            'impreza' => [
                'required' => [ 'us-core' ],
                'bundled'  => [ 'js_composer', 'revslider', 'ultimate_vc_addons' ],
            ],
            'zephyr' => [
                'required' => [ 'us-core' ],
                'bundled'  => [ 'js_composer', 'revslider', 'ultimate_vc_addons' ],
            ],
            'avada' => [
                'required' => [ 'fusion-core', 'fusion-builder' ],
                'bundled'  => [ 'revslider', 'layerslider' ],
            ],
            'salient' => [
                'required' => [ 'salient-core' ],
                'bundled'  => [ 'js_composer', 'salient-demo-importer', 'salient-nectar-slider', 'salient-portfolio', 'salient-social', 'salient-widgets', 'salient-shortcodes', 'salient-home-slider' ],
            ],
            'woodmart' => [
                'required' => [ 'woodmart-core' ],
                'bundled'  => [ 'js_composer', 'revslider' ],
            ],
            'porto' => [
                'required' => [ 'porto-functionality' ],
                'bundled'  => [ 'js_composer', 'revslider' ],
            ],
            'betheme' => [
                'required' => [],
                'bundled'  => [ 'js_composer', 'revslider', 'layerslider' ],
            ],
            'bridge' => [
                'required' => [ 'bridge-core' ],
                'bundled'  => [ 'js_composer', 'revslider', 'layerslider' ],
            ],
            'the7' => [
                'required' => [ 'dt-the7-core' ],
                'bundled'  => [ 'js_composer', 'revslider', 'ultimate_vc_addons' ],
            ],
            'dt-the7' => [
                'required' => [ 'dt-the7-core' ],
                'bundled'  => [ 'js_composer', 'revslider', 'ultimate_vc_addons' ],
            ],
            'jupiterx' => [
                'required' => [ 'jupiterx-core' ],
                'bundled'  => [ 'revslider', 'layerslider', 'advanced-custom-fields-pro' ],
            ],
            'newspaper' => [
                'required' => [ 'td-composer' ],
                'bundled'  => [ 'td-cloud-library', 'td-standard-pack', 'td-mobile-plugin', 'revslider' ],
            ],
            'newsmag' => [
                'required' => [ 'td-composer' ],
                'bundled'  => [ 'td-cloud-library', 'td-standard-pack', 'td-mobile-plugin' ],
            ],
        ];

        $is_required = false;
        $is_bundled  = false;
        $reason      = '';

        // 1. Check curated active theme companion map (also recognize UpSolution vendor themes)
        $matched_theme_keys = $theme_slugs;
        if ( in_array( 'upsolution', $theme_context['authors'] ?? [], true ) || in_array( 'us-themes.com', $theme_context['hosts'] ?? [], true ) ) {
            $matched_theme_keys[] = 'impreza';
        }
        foreach ( array_unique( $matched_theme_keys ) as $t_key ) {
            if ( ! isset( $known_theme_companions[ $t_key ] ) ) {
                continue;
            }
            $entry = $known_theme_companions[ $t_key ];
            if ( in_array( $slug_key, $entry['required'], true ) ) {
                $is_required = true;
                $is_bundled  = true;
                $reason      = 'required_core';
                break;
            }
            if ( in_array( $slug_key, $entry['bundled'], true ) ) {
                $is_bundled = true;
                $reason     = 'theme_bundled_map';
                break;
            }
        }

        // 2. Check dynamic runtime addons (us_config / TGMPA)
        if ( isset( $theme_context['dynamic_addons'][ $slug_key ] ) ) {
            $dyn = $theme_context['dynamic_addons'][ $slug_key ];
            if ( ! empty( $dyn['required'] ) ) {
                $is_required = true;
                $is_bundled  = true;
                $reason      = $reason ?: ( $dyn['source'] ?? 'theme_config' );
            } elseif ( ! empty( $dyn['bundled'] ) ) {
                $is_bundled = true;
                $reason     = $reason ?: ( $dyn['source'] ?? 'theme_config' );
            }
        }

        // 3. Check theme bundled plugin archives/folders on disk
        if ( in_array( $slug_key, $theme_context['bundled_zips'] ?? [], true ) ) {
            $is_bundled = true;
            $reason     = $reason ?: 'theme_archive';
        }

        // 4. Same-vendor Author or URI host match with the active theme
        if ( ! $is_bundled && ! empty( $plugin_meta ) ) {
            $plugin_author = strtolower( trim( wp_strip_all_tags( (string) ( $plugin_meta['Author'] ?? '' ) ) ) );
            $author_match  = $plugin_author && in_array( $plugin_author, $theme_context['authors'] ?? [], true );

            $host_match = false;
            foreach ( [ $plugin_meta['AuthorURI'] ?? '', $plugin_meta['PluginURI'] ?? '' ] as $p_uri ) {
                $p_host = self::extract_normalized_host( (string) $p_uri );
                if ( $p_host && in_array( $p_host, $theme_context['hosts'] ?? [], true ) ) {
                    $host_match = true;
                    break;
                }
            }

            if ( $author_match || $host_match ) {
                $is_bundled = true;
                $reason     = 'vendor_match';
            }
        }

        if ( ! $is_required && ! $is_bundled ) {
            return null;
        }

        return [
            'is_companion' => true,
            'is_required'  => $is_required,
            'is_bundled'   => $is_bundled,
            'theme_name'   => $theme_name,
            'theme_slug'   => $theme_slugs[0] ?? '',
            'reason'       => $reason,
        ];
    }

    /**
     * Extract normalized lowercase hostname (without leading www.) from a URI.
     *
     * @param string $uri Raw URI string.
     * @return string Normalized hostname or empty string.
     */
    private static function extract_normalized_host( $uri ) {
        if ( empty( $uri ) ) {
            return '';
        }
        $host = wp_parse_url( esc_url_raw( $uri ), PHP_URL_HOST );
        if ( empty( $host ) || ! is_string( $host ) ) {
            return '';
        }
        $host = strtolower( trim( $host ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return $host;
    }
}
