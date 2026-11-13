<?php
/**
 * Site Bloat & Storage Auditor v1.1.0
 * Comprehensive Verification Suite: False Positive Plugin Usage Detection Fix
 *
 * Verifies all 10 mandatory regression scenarios from Section 8:
 *  1. Plugin active, only dedicated tables exist -> Database Footprint Only (NEVER Functional Usage)
 *  2. Plugin inactive, only dedicated tables exist -> Database Footprint Only
 *  3. Plugin with settings in wp_options but no functional integration -> Configuration Only
 *  4. Plugin with historical content (inactive with shortcodes/blocks) -> Historical Data Only
 *  5. Plugin with embedded shortcode -> Functional Usage Detected
 *  6. Plugin with published Gutenberg block -> Functional Usage Detected
 *  7. Plugin with Elementor widget -> Functional Usage Detected
 *  8. Plugin with frontend scripts and styles -> Functional Usage Detected
 *  9. Plugin provides automatic sitewide functionality -> Operational Activity Detected
 * 10. Plugin has dependencies from another active plugin -> is_deletable = false, protected
 *
 * Plus Live Environment Verifications:
 * 11. Smart Slider 3 live classification -> Database Footprint Only (336 KB, 6 tables, no embeds)
 * 12. Yoast SEO live classification -> Operational Activity Detected (304 KB, 6 tables, indexable records)
 * 13. Production User Media (Attachment ID 6) Invariance Verification
 */

if ( ! defined( 'DOING_AJAX' ) ) {
    define( 'DOING_AJAX', true );
}

require_once 'c:/Users/itadm/Local Sites/auditortest/app/public/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once SBA_PLUGIN_DIR . 'includes/class-sba-plugin-usage-detector.php';

// Filter ajax die handler
class SBA_Footprint_Test_Exception extends Exception {}
add_filter( 'wp_die_ajax_handler', function() {
    return function( $message ) {
        throw new SBA_Footprint_Test_Exception( is_scalar( $message ) ? (string) $message : 'wp_die' );
    };
} );
add_filter( 'wp_die_handler', function() {
    return function( $message ) {
        throw new SBA_Footprint_Test_Exception( is_scalar( $message ) ? (string) $message : 'wp_die' );
    };
} );

$detector = SBA_Plugin_Usage_Detector::get_instance();
$total_tests = 0;
$passed_tests = 0;

function run_test( $name, $condition, $details = '' ) {
    global $total_tests, $passed_tests;
    $total_tests++;
    if ( $condition ) {
        $passed_tests++;
        echo "[PASS] Scenario {$total_tests}: {$name}\n";
        if ( $details ) {
            echo "       -> {$details}\n";
        }
    } else {
        echo "[FAIL] Scenario {$total_tests}: {$name}\n";
        echo "       -> FAILED: {$details}\n";
    }
}

// Helpers
function make_temp_plugin( $slug, $name = 'Test Plugin', $desc = 'Test plugin description' ) {
    $dir = WP_PLUGIN_DIR . '/' . $slug;
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $file = $dir . '/' . $slug . '.php';
    $code = "<?php\n/**\n * Plugin Name: {$name}\n * Description: {$desc}\n * Version: 1.0.0\n */\n";
    file_put_contents( $file, $code );
    wp_cache_delete( 'plugins', 'plugins' );
    clearstatcache();
    return $slug . '/' . $slug . '.php';
}

function remove_temp_plugin( $slug ) {
    $dir = WP_PLUGIN_DIR . '/' . $slug;
    if ( is_dir( $dir ) ) {
        $files = scandir( $dir );
        foreach ( $files as $f ) {
            if ( $f !== '.' && $f !== '..' ) {
                @unlink( $dir . '/' . $f );
            }
        }
        @rmdir( $dir );
    }
    wp_cache_delete( 'plugins', 'plugins' );
    clearstatcache();
}

echo "====================================================================\n";
echo "SBA FALSE POSITIVE PLUGIN USAGE DETECTION FIX: VERIFICATION SUITE\n";
echo "WordPress: " . home_url() . " (PHP " . PHP_VERSION . ")\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// Scenario 1: Plugin active, only dedicated tables exist
// -------------------------------------------------------------------------
$p1 = make_temp_plugin( 'sba-scen1-tables-active', 'SBA Scen1 Tables Active' );
$ctx1 = [];
$res1 = $detector->detect( $p1, [ 'Name' => 'SBA Scen1 Tables Active' ], true, false, [ 'count' => 3, 'size_formatted' => '128.00 KB' ], $ctx1 );
$pass1 = ( $res1['status'] === 'database_footprint_only' ) &&
         ( $res1['status'] !== 'functional_usage' ) &&
         ( $res1['status'] !== 'usage_detected' ) &&
         ( count( $res1['evidence_db'] ) > 0 ) &&
         ( count( $res1['evidence_functional'] ) === 0 );
run_test(
    "Plugin installed and active, only dedicated tables exist -> Database Footprint Only (NEVER Functional Usage)",
    $pass1,
    "Status: {$res1['status']}, Label: {$res1['label']}, DB Evidence: " . implode( ', ', $res1['evidence_db'] )
);
remove_temp_plugin( 'sba-scen1-tables-active' );

// -------------------------------------------------------------------------
// Scenario 2: Plugin inactive, only dedicated tables exist
// -------------------------------------------------------------------------
$p2 = make_temp_plugin( 'sba-scen2-tables-inactive', 'SBA Scen2 Tables Inactive' );
$ctx2 = [];
$res2 = $detector->detect( $p2, [ 'Name' => 'SBA Scen2 Tables Inactive' ], false, false, [ 'count' => 4, 'size_formatted' => '256.00 KB' ], $ctx2 );
$pass2 = ( $res2['status'] === 'database_footprint_only' ) &&
         ( count( $res2['evidence_db'] ) > 0 ) &&
         ( count( $res2['evidence_functional'] ) === 0 );
run_test(
    "Plugin installed and inactive, only dedicated tables exist -> Database Footprint Only",
    $pass2,
    "Status: {$res2['status']}, Label: {$res2['label']}, DB Warning: " . $res2['db_tables_warning']
);
remove_temp_plugin( 'sba-scen2-tables-inactive' );

// -------------------------------------------------------------------------
// Scenario 3: Plugin has settings in wp_options but no functional integration
// -------------------------------------------------------------------------
$p3 = make_temp_plugin( 'sba-scen3-config', 'SBA Scen3 Config Plugin' );
update_option( 'sba_scen3_config_api_key', 'xyz123test' );
update_option( 'sba_scen3_config_settings', [ 'enabled' => true, 'mode' => 'expert' ] );
$ctx3 = [];
$res3 = $detector->detect( $p3, [ 'Name' => 'SBA Scen3 Config Plugin' ], true, false, [ 'count' => 0, 'size_formatted' => '0 KB' ], $ctx3 );
delete_option( 'sba_scen3_config_api_key' );
delete_option( 'sba_scen3_config_settings' );
$pass3 = ( $res3['status'] === 'configuration_only' ) &&
         ( count( $res3['evidence_config'] ) > 0 ) &&
         ( count( $res3['evidence_functional'] ) === 0 );
run_test(
    "Plugin has settings but no functional integration -> Configuration Only",
    $pass3,
    "Status: {$res3['status']}, Label: {$res3['label']}, Config Evidence: " . implode( ', ', $res3['evidence_config'] )
);
remove_temp_plugin( 'sba-scen3-config' );

// -------------------------------------------------------------------------
// Scenario 4: Plugin has historical content but no current frontend usage (inactive)
// -------------------------------------------------------------------------
$p4 = make_temp_plugin( 'sba-scen4-historical', 'SBA Scen4 Historical Plugin' );
$post4_id = wp_insert_post( [
    'post_title'   => 'Historical Shortcode Post',
    'post_content' => 'Old content with [sba-scen4-historical id="99"] from past.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
] );
$ctx4 = [];
$res4 = $detector->detect( $p4, [ 'Name' => 'SBA Scen4 Historical Plugin' ], false, false, [], $ctx4 );
wp_delete_post( $post4_id, true );
$pass4 = ( $res4['status'] === 'historical_data_only' ) &&
         ( strpos( $res4['label'], 'Historical Data Only' ) !== false );
run_test(
    "Plugin has historical content but no current frontend usage -> Historical Data Only",
    $pass4,
    "Status: {$res4['status']}, Label: {$res4['label']}, Functional Count: " . count( $res4['evidence_functional'] )
);
remove_temp_plugin( 'sba-scen4-historical' );

// -------------------------------------------------------------------------
// Scenario 5: Plugin has an embedded shortcode (active)
// -------------------------------------------------------------------------
$p5 = make_temp_plugin( 'sba-scen5-shortcode', 'SBA Scen5 Shortcode Plugin' );
$post5_id = wp_insert_post( [
    'post_title'   => 'Active Shortcode Post',
    'post_content' => 'Current content with [sba-scen5-shortcode foo="bar"] embedded.',
    'post_status'  => 'publish',
    'post_type'    => 'post',
] );
$ctx5 = [];
$res5 = $detector->detect( $p5, [ 'Name' => 'SBA Scen5 Shortcode Plugin' ], true, false, [], $ctx5 );
wp_delete_post( $post5_id, true );
$pass5 = ( $res5['status'] === 'functional_usage' ) &&
         ( count( $res5['evidence_functional'] ) > 0 );
run_test(
    "Plugin has an embedded shortcode -> Functional Usage Detected",
    $pass5,
    "Status: {$res5['status']}, Label: {$res5['label']}, Evidence: " . implode( ', ', $res5['evidence_functional'] )
);
remove_temp_plugin( 'sba-scen5-shortcode' );

// -------------------------------------------------------------------------
// Scenario 6: Plugin has a published Gutenberg block
// -------------------------------------------------------------------------
$p6 = make_temp_plugin( 'sba-scen6-block', 'SBA Scen6 Block Plugin' );
$post6_id = wp_insert_post( [
    'post_title'   => 'Active Gutenberg Block Post',
    'post_content' => '<!-- wp:sba-scen6-block {"version":"2"} --><p>Block output</p><!-- /wp:sba-scen6-block -->',
    'post_status'  => 'publish',
    'post_type'    => 'post',
] );
$ctx6 = [];
$res6 = $detector->detect( $p6, [ 'Name' => 'SBA Scen6 Block Plugin' ], true, false, [], $ctx6 );
wp_delete_post( $post6_id, true );
$pass6 = ( $res6['status'] === 'functional_usage' ) &&
         ( count( $res6['evidence_functional'] ) > 0 );
run_test(
    "Plugin has a published Gutenberg block -> Functional Usage Detected",
    $pass6,
    "Status: {$res6['status']}, Label: {$res6['label']}, Evidence: " . implode( ', ', $res6['evidence_functional'] )
);
remove_temp_plugin( 'sba-scen6-block' );

// -------------------------------------------------------------------------
// Scenario 7: Plugin has an Elementor widget
// -------------------------------------------------------------------------
$p7 = make_temp_plugin( 'sba-scen7-elementor', 'SBA Scen7 Elementor Plugin' );
$post7_id = wp_insert_post( [
    'post_title'   => 'Active Elementor Page',
    'post_content' => '',
    'post_status'  => 'publish',
    'post_type'    => 'page',
] );
update_post_meta( $post7_id, '_elementor_data', json_encode( [
    [
        'id' => 'elem1',
        'elType' => 'widget',
        'widgetType' => 'sba-scen7-elementor',
        'settings' => [ 'title' => 'Sample Widget' ],
    ]
] ) );
$ctx7 = [];
$res7 = $detector->detect( $p7, [ 'Name' => 'SBA Scen7 Elementor Plugin' ], true, false, [], $ctx7 );
wp_delete_post( $post7_id, true );
$pass7 = ( $res7['status'] === 'functional_usage' ) &&
         ( count( $res7['evidence_functional'] ) > 0 );
run_test(
    "Plugin has an Elementor widget -> Functional Usage Detected",
    $pass7,
    "Status: {$res7['status']}, Label: {$res7['label']}, Evidence: " . implode( ', ', $res7['evidence_functional'] )
);
remove_temp_plugin( 'sba-scen7-elementor' );

// -------------------------------------------------------------------------
// Scenario 8: Plugin has frontend scripts and styles
// -------------------------------------------------------------------------
$p8 = make_temp_plugin( 'sba-scen8-assets', 'SBA Scen8 Assets Plugin' );
global $wp_scripts;
if ( ! ( $wp_scripts instanceof WP_Scripts ) ) {
    $wp_scripts = new WP_Scripts();
}
wp_register_script( 'sba-scen8-script', plugins_url( 'assets/frontend.js', WP_PLUGIN_DIR . '/' . $p8 ), [], '1.0' );
$ctx8 = [];
$res8 = $detector->detect( $p8, [ 'Name' => 'SBA Scen8 Assets Plugin' ], true, false, [], $ctx8 );
$pass8 = ( $res8['status'] === 'functional_usage' ) &&
         ( count( $res8['evidence_functional'] ) > 0 );
run_test(
    "Plugin has frontend scripts and styles -> Functional Usage Detected",
    $pass8,
    "Status: {$res8['status']}, Label: {$res8['label']}, Evidence: " . implode( ', ', $res8['evidence_functional'] )
);
remove_temp_plugin( 'sba-scen8-assets' );

// -------------------------------------------------------------------------
// Scenario 9: Plugin provides automatic sitewide functionality (caching/firewall/cron)
// -------------------------------------------------------------------------
$p9 = make_temp_plugin( 'sba-scen9-cache', 'SBA Fast Cache Accelerator', 'Automated page and object caching engine' );
$ctx9 = [];
$res9 = $detector->detect( $p9, [ 'Name' => 'SBA Fast Cache Accelerator', 'Description' => 'Automated page and object caching engine' ], true, false, [], $ctx9 );
$pass9 = ( $res9['status'] === 'operational_activity' ) &&
         ( count( $res9['evidence_operational'] ) > 0 );
run_test(
    "Plugin provides automatic sitewide functionality -> Operational Activity Detected",
    $pass9,
    "Status: {$res9['status']}, Label: {$res9['label']}, Operational: " . implode( ', ', $res9['evidence_operational'] )
);
remove_temp_plugin( 'sba-scen9-cache' );

// -------------------------------------------------------------------------
// Scenario 10: Plugin has dependencies from another active plugin
// -------------------------------------------------------------------------
$p10 = make_temp_plugin( 'sba-scen10-parent', 'SBA Scen10 Parent Library' );
$ctx10 = [
    'active_plugins' => [ $p10, 'sba-scen10-child/child.php' ],
    'all_plugins'    => [
        $p10 => [ 'Name' => 'SBA Scen10 Parent Library' ],
        'sba-scen10-child/child.php' => [
            'Name' => 'SBA Child Plugin',
            'RequiresPlugins' => 'sba-scen10-parent',
        ],
    ],
];
$res10 = $detector->detect( $p10, [ 'Name' => 'SBA Scen10 Parent Library' ], true, false, [], $ctx10 );
$pass10 = ( $res10['is_deletable'] === false ) &&
          ( ! empty( $res10['protect_reason'] ) ) &&
          ( false !== strpos( $res10['protect_reason'], 'Required by active plugin' ) );
run_test(
    "Plugin has dependencies from another active plugin -> Protected (is_deletable = false)",
    $pass10,
    "Deletable: " . ($res10['is_deletable'] ? 'yes' : 'no') . ", Reason: {$res10['protect_reason']}"
);
remove_temp_plugin( 'sba-scen10-parent' );

// -------------------------------------------------------------------------
// Scenario 11: Live Test on Smart Slider 3 (smart-slider-3/smart-slider-3.php)
// -------------------------------------------------------------------------
$all_plugins = get_plugins();
$ss3_file = 'smart-slider-3/smart-slider-3.php';
if ( isset( $all_plugins[ $ss3_file ] ) ) {
    $meta = $all_plugins[ $ss3_file ];
    $is_active = is_plugin_active( $ss3_file );
    $db_entry = [ 'count' => 6, 'size_formatted' => '336.00 KB' ];
    $ctx_ss3 = [];
    $res11 = $detector->detect( $ss3_file, $meta, $is_active, false, $db_entry, $ctx_ss3 );
    $pass11 = ( $res11['status'] === 'database_footprint_only' ) &&
              ( $res11['status'] !== 'functional_usage' ) &&
              ( $res11['status'] !== 'usage_detected' ) &&
              ( count( $res11['evidence_db'] ) > 0 ) &&
              ( count( $res11['evidence_functional'] ) === 0 );
    run_test(
        "Live Smart Slider 3 (Inactive, 6 tables, no content embedding) -> Database Footprint Only",
        $pass11,
        "Status: {$res11['status']}, Label: {$res11['label']}, Confidence: {$res11['confidence']}, DB: " . implode( '; ', $res11['evidence_db'] )
    );
} else {
    run_test( "Live Smart Slider 3", false, "Plugin file not found in WordPress" );
}

// -------------------------------------------------------------------------
// Scenario 12: Live Test on Yoast SEO (wordpress-seo/wp-seo.php)
// -------------------------------------------------------------------------
$yoast_file = 'wordpress-seo/wp-seo.php';
if ( isset( $all_plugins[ $yoast_file ] ) ) {
    $meta = $all_plugins[ $yoast_file ];
    $is_active = is_plugin_active( $yoast_file );
    $db_entry = [ 'count' => 6, 'size_formatted' => '304.00 KB' ];
    $ctx_yoast = [];
    $res12 = $detector->detect( $yoast_file, $meta, $is_active, false, $db_entry, $ctx_yoast );
    $pass12 = ( $res12['status'] === 'operational_activity' ) &&
              ( $res12['status'] !== 'functional_usage' ) &&
              ( count( $res12['evidence_operational'] ) > 0 ) &&
              ( count( $res12['evidence_db'] ) > 0 ) &&
              ( count( $res12['evidence_functional'] ) === 0 );
    run_test(
        "Live Yoast SEO (Active, 6 tables, sitewide SEO output) -> Operational Activity Detected",
        $pass12,
        "Status: {$res12['status']}, Label: {$res12['label']}, Operational: " . implode( '; ', $res12['evidence_operational'] ) . ", DB: " . implode( '; ', $res12['evidence_db'] )
    );
} else {
    run_test( "Live Yoast SEO", false, "Plugin file not found in WordPress" );
}

// -------------------------------------------------------------------------
// Scenario 13: Production User Media Invariance Check (Attachment ID 6)
// -------------------------------------------------------------------------
$att6 = get_post( 6 );
$att6_file = $att6 ? get_attached_file( 6 ) : '';
$att6_exists = $att6_file && file_exists( $att6_file );
$att6_size = $att6_exists ? filesize( $att6_file ) : 0;
$att6_hash = $att6_exists ? hash_file( 'sha256', $att6_file ) : '';
$expected_size = 731817;
$expected_hash = 'bb16c916f7cbbd5e76b3ac25180c86ba264d4387cfed8053263c3a5f33b497b2';

$pass13 = ( $att6 !== null ) && $att6_exists && ( $att6_size === $expected_size ) && ( $att6_hash === $expected_hash );
run_test(
    "Attachment ID 6 Invariance (Untouched original image)",
    $pass13,
    "Size: {$att6_size} (Expected: {$expected_size}), Hash: {$att6_hash}"
);

echo "\n====================================================================\n";
echo "SUMMARY: Total: {$total_tests} | Passed: {$passed_tests} | Failed: " . ( $total_tests - $passed_tests ) . "\n";
echo "====================================================================\n";

if ( $passed_tests === $total_tests ) {
    exit( 0 );
} else {
    exit( 1 );
}

