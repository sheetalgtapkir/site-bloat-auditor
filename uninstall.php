<?php
/**
 * Uninstallation cleanup script for Site Bloat & Storage Auditor.
 *
 * This file is automatically executed by WordPress when the plugin is deleted
 * through the WordPress Admin dashboard (Plugins > Delete).
 *
 * DATA SAFETY POLICY:
 * User media files and quarantine recovery records are NEVER permanently deleted
 * during uninstallation. If any attachments remain in quarantine, they are safely
 * restored back to their active media library location.
 *
 * @package Site_Bloat_Auditor
 * @link    https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/
 */

// Prevent direct script execution. Must be called via WordPress uninstallation context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Safely clean up plugin data for a single site while preserving user media.
 */
function sba_uninstall_cleanup_site() {
    global $wpdb;

    // 1. Retrieve any existing quarantined files record
    $quarantined = (array) get_option( 'sba_quarantined_files', [] );

    // 2. Safe Restoration of Quarantined Media
    // Rather than permanently destroying user media, safely move files back to their original upload locations.
    $unresolved_quarantine = [];
    if ( ! empty( $quarantined ) ) {
        foreach ( $quarantined as $att_id => $record ) {
            $att_id       = (int) $att_id;
            $all_restored = true;
            $stranded     = [];

            if ( ! empty( $record['moved_files'] ) && is_array( $record['moved_files'] ) ) {
                foreach ( $record['moved_files'] as $mf ) {
                    $src = $mf['quarantine_path'] ?? '';
                    $dst = $mf['original_path'] ?? '';

                    if ( ! empty( $src ) && file_exists( $src ) && ! empty( $dst ) ) {
                        wp_mkdir_p( dirname( $dst ) );

                        // Attempt restoration move without clobbering existing destinations
                        if ( ! file_exists( $dst ) ) {
                            $moved = @rename( $src, $dst );
                            if ( ! $moved && @copy( $src, $dst ) && file_exists( $dst ) && filesize( $dst ) === filesize( $src ) ) {
                                @unlink( $src );
                                $moved = true;
                            }
                            if ( ! $moved ) {
                                $all_restored = false;
                                $stranded[]   = array_merge( $mf, [ 'reason' => 'Filesystem move failed' ] );
                            }
                        } else {
                            // Destination already exists! Compare size
                            if ( (int) filesize( $dst ) === (int) filesize( $src ) ) {
                                @unlink( $src ); // Safe: identical file is already at destination
                            } else {
                                $all_restored = false;
                                $stranded[]   = array_merge( $mf, [ 'reason' => 'Destination file collision (preserved in quarantine)' ] );
                            }
                        }
                    }
                }
            }

            if ( $all_restored ) {
                $wpdb->update( $wpdb->posts, [ 'post_status' => 'inherit' ], [ 'ID' => $att_id ] );
                clean_post_cache( $att_id );
                delete_post_meta( $att_id, '_sba_quarantined' );
            } else {
                $record['unresolved_files'] = $stranded;
                $unresolved_quarantine[ $att_id ] = $record;
            }
        }
    }

    // Also check for any attachment posts that might still have status 'sba-quarantine'
    // but only restore those not in unresolved quarantine
    $quarantined_post_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
        'attachment',
        'sba-quarantine'
    ) );

    if ( ! empty( $quarantined_post_ids ) ) {
        foreach ( $quarantined_post_ids as $post_id ) {
            $pid = (int) $post_id;
            if ( ! isset( $unresolved_quarantine[ $pid ] ) ) {
                $wpdb->update( $wpdb->posts, [ 'post_status' => 'inherit' ], [ 'ID' => $pid ] );
                clean_post_cache( $pid );
                delete_post_meta( $pid, '_sba_quarantined' );
            }
        }
    }

    // 3. Clean up temporary files (sba-temp only, NEVER sba-quarantine if files remain)
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['basedir'] ) ) {
        $basedir            = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $sba_temp_dir       = $basedir . 'sba-temp';
        $sba_quarantine_dir = $basedir . 'sba-quarantine';

        // Remove temporary working export/import archives
        sba_uninstall_remove_dir_recursive( $sba_temp_dir, $basedir );

        // If quarantine dir is completely empty, remove it; if files still remain for any reason, preserve them!
        if ( is_dir( $sba_quarantine_dir ) ) {
            $remaining_items = array_diff( scandir( $sba_quarantine_dir ) ?: [], [ '.', '..', '.htaccess', 'index.php', 'README_SBA_PRESERVED_MEDIA.txt', 'quarantine_recovery_manifest.json' ] );
            if ( empty( $remaining_items ) && empty( $unresolved_quarantine ) ) {
                @unlink( $sba_quarantine_dir . '/.htaccess' );
                @unlink( $sba_quarantine_dir . '/index.php' );
                @unlink( $sba_quarantine_dir . '/README_SBA_PRESERVED_MEDIA.txt' );
                @unlink( $sba_quarantine_dir . '/quarantine_recovery_manifest.json' );
                @rmdir( $sba_quarantine_dir );
                delete_option( 'sba_quarantined_files' );
            } else {
                // Files remain — write manifest and safety notice so site owner knows their files are intact
                @file_put_contents(
                    $sba_quarantine_dir . '/quarantine_recovery_manifest.json',
                    wp_json_encode( $unresolved_quarantine, JSON_PRETTY_PRINT )
                );
                @file_put_contents(
                    $sba_quarantine_dir . '/README_SBA_PRESERVED_MEDIA.txt',
                    "Site Bloat Auditor Uninstall Data Preservation Notice:\n" .
                    "====================================================\n" .
                    "The files in this directory were quarantined when the plugin was uninstalled.\n" .
                    "To prevent accidental data loss, these files have been safely PRESERVED.\n" .
                    "A complete recovery manifest is available in quarantine_recovery_manifest.json.\n" .
                    "Unresolved attachment items: " . count( $unresolved_quarantine ) . "\n" .
                    "Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n"
                );
                update_option( 'sba_quarantined_files', $unresolved_quarantine, false );
            }
        } else {
            delete_option( 'sba_quarantined_files' );
        }
    } else {
        delete_option( 'sba_quarantined_files' );
    }

    // 4. Plugin state options (no user data). Optimization records (sba_optimized_images,
    // sba_optimized_images_backup) are deliberately kept: they map each optimized image to its
    // preserved .sba-orig original, which is never deleted on uninstall.
    foreach ( [ 'sba_destructive_media_lock', 'sba_media_operation_mutex', 'sba_last_db_scan', 'sba_reclaimed_media_bytes' ] as $sba_option ) {
        delete_option( $sba_option );
    }

    // 5. Clean up plugin transients from wp_options using prepared SQL
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_sba_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_sba_' ) . '%'
    ) );
}

/**
 * Recursively remove a temporary directory and its files with strict security boundary checks.
 *
 * Ensures the target path is strictly located within the uploads basedir to prevent
 * accidental deletion of unrelated server files.
 *
 * @param string $target_dir Path to the directory to remove.
 * @param string $basedir    Normalized uploads basedir serving as the security boundary.
 */
function sba_uninstall_remove_dir_recursive( $target_dir, $basedir ) {
    $normalized_target = trailingslashit( wp_normalize_path( $target_dir ) );

    // Security check: Must exist, be a directory, strictly reside inside uploads, and cannot be uploads root.
    if ( ! is_dir( $target_dir ) || 0 !== strpos( $normalized_target, $basedir ) || $normalized_target === $basedir ) {
        return;
    }

    $items = scandir( $target_dir );
    if ( false === $items ) {
        return;
    }

    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }

        $item_path = $target_dir . DIRECTORY_SEPARATOR . $item;

        if ( is_dir( $item_path ) ) {
            sba_uninstall_remove_dir_recursive( $item_path, $basedir );
        } elseif ( file_exists( $item_path ) ) {
            @unlink( $item_path );
        }
    }

    @rmdir( $target_dir );
}

// Execute uninstallation across single site or multisite network
if ( is_multisite() ) {
    global $wpdb;

    $sba_sites = get_sites( [
        'fields' => 'ids',
        'number' => 0,
    ] );

    if ( ! empty( $sba_sites ) ) {
        foreach ( $sba_sites as $sba_site_id ) {
            switch_to_blog( (int) $sba_site_id );
            sba_uninstall_cleanup_site();
            restore_current_blog();
        }
    }

    if ( ! empty( $wpdb->sitemeta ) ) {
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
            $wpdb->esc_like( '_site_transient_sba_' ) . '%',
            $wpdb->esc_like( '_site_transient_timeout_sba_' ) . '%'
        ) );
    }
} else {
    sba_uninstall_cleanup_site();
}
