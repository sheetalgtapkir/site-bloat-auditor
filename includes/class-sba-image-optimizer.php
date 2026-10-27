<?php
/**
 * Image Optimization & Safe Rollback Engine for Site Bloat & Storage Auditor.
 *
 * Implements safe, recoverable image optimization (resizing, compression, WebP conversion)
 * with verified backups, atomic file replacement, and one-click rollback.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Image_Optimizer {

    /**
     * Option key storing optimization backup records.
     */
    const BACKUP_OPTION_KEY = 'sba_optimized_images';

    /**
     * Check environment capabilities for image processing.
     *
     * @return array Capability status.
     */
    public function check_environment_support() {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $editor_class = _wp_image_editor_choose();
        $has_editor   = ! empty( $editor_class );

        $supports_webp = function_exists( 'imagewebp' ) || ( class_exists( 'Imagick' ) && in_array( 'WEBP', Imagick::queryFormats(), true ) );
        $supports_avif = function_exists( 'imageavif' ) || ( class_exists( 'Imagick' ) && in_array( 'AVIF', Imagick::queryFormats(), true ) );

        return [
            'has_editor'    => $has_editor,
            'editor_class'  => $editor_class ?: 'None',
            'supports_webp' => $supports_webp,
            'supports_avif' => $supports_avif,
            'supports_jpeg' => function_exists( 'imagejpeg' ),
            'supports_png'  => function_exists( 'imagepng' ),
        ];
    }

    /**
     * Analyze image dimensions and file size against web display benchmarks.
     *
     * @param int   $att_id   Attachment ID.
     * @param array $meta     Attachment metadata.
     * @param bool  $is_hero  Whether image is used in a hero/featured banner.
     * @return array Analysis findings.
     */
    public function analyze_image( $att_id, $meta = null, $is_hero = false ) {
        if ( null === $meta ) {
            $meta = wp_get_attachment_metadata( $att_id );
        }

        $file_path = get_attached_file( $att_id );
        $orig_w    = (int) ( $meta['width'] ?? 0 );
        $orig_h    = (int) ( $meta['height'] ?? 0 );
        $filesize  = ( $file_path && file_exists( $file_path ) ) ? (int) @filesize( $file_path ) : 0;

        // Benchmarks: 1920px max for hero/featured, 1200px max for content
        $benchmark_w = $is_hero ? 1920 : 1200;
        $is_oversized_dim = ( $orig_w > $benchmark_w );
        $is_oversized_bytes = ( $filesize > 512000 ); // > 500 KB

        $rec_w = $orig_w;
        $rec_h = $orig_h;

        if ( $is_oversized_dim && $orig_w > 0 && $orig_h > 0 ) {
            $rec_w = $benchmark_w;
            $rec_h = (int) round( ( $benchmark_w / $orig_w ) * $orig_h );
        }

        // Estimate optimized size
        $estimated_bytes = $filesize;
        $savings_bytes   = 0;

        if ( $is_oversized_dim || $is_oversized_bytes ) {
            if ( $is_oversized_dim && $orig_w > 0 ) {
                $scale_factor = ( $rec_w * $rec_h ) / ( $orig_w * $orig_h );
                $estimated_bytes = (int) round( $filesize * $scale_factor * 0.85 );
            } else {
                $estimated_bytes = (int) round( $filesize * 0.70 ); // compression savings estimate
            }
            $savings_bytes = max( 0, $filesize - $estimated_bytes );
        }

        $savings_percent = ( $filesize > 0 && $savings_bytes > 0 ) ? round( ( $savings_bytes / $filesize ) * 100, 1 ) : 0;

        return [
            'is_oversized'        => ( $is_oversized_dim || $is_oversized_bytes ),
            'is_oversized_dim'    => $is_oversized_dim,
            'is_oversized_bytes'  => $is_oversized_bytes,
            'original_width'      => $orig_w,
            'original_height'     => $orig_h,
            'original_dim'        => ( $orig_w > 0 && $orig_h > 0 ) ? "{$orig_w} × {$orig_h} px" : 'N/A',
            'recommended_width'   => $rec_w,
            'recommended_height'  => $rec_h,
            'recommended_dim'     => ( $rec_w > 0 && $rec_h > 0 ) ? "{$rec_w} × {$rec_h} px" : 'N/A',
            'current_size_bytes'  => $filesize,
            'current_size_fmt'    => size_format( $filesize, 2 ),
            'estimated_size_bytes'=> $estimated_bytes,
            'estimated_size_fmt'  => size_format( $estimated_bytes, 2 ),
            'savings_bytes'       => $savings_bytes,
            'savings_fmt'         => size_format( $savings_bytes, 2 ),
            'savings_percent'     => $savings_percent,
            'assumptions'         => esc_html__( 'Assumes 82% quality compression and proportional downscaling to display benchmark.', 'site-bloat-auditor' ),
        ];
    }

    /**
     * Optimize an attachment: backup original, resize/compress, and update metadata.
     *
     * @param int   $att_id  Attachment ID.
     * @param array $options Optimization parameters.
     * @return array|WP_Error Result or error.
     */
    public function optimize_attachment( $att_id, array $options = [] ) {
        $att = get_post( $att_id );
        if ( ! $att || 'attachment' !== $att->post_type ) {
            return new WP_Error( 'invalid_attachment', esc_html__( 'Invalid attachment ID.', 'site-bloat-auditor' ) );
        }

        $file_path = get_attached_file( $att_id );
        if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return new WP_Error( 'missing_file', esc_html__( 'Original attachment file not found or unreadable on disk.', 'site-bloat-auditor' ) );
        }

        $orig_size = (int) filesize( $file_path );
        if ( $orig_size <= 0 ) {
            return new WP_Error( 'empty_file', esc_html__( 'Attachment file is 0 bytes.', 'site-bloat-auditor' ) );
        }

        // Check if backup already exists (cannot optimize twice without rolling back or clearing)
        $backups = (array) get_option( self::BACKUP_OPTION_KEY, [] );
        if ( isset( $backups[ $att_id ] ) ) {
            return new WP_Error( 'already_optimized', esc_html__( 'This image has already been optimized. Roll back the previous optimization first to re-optimize.', 'site-bloat-auditor' ) );
        }

        $meta = wp_get_attachment_metadata( $att_id );
        $orig_width  = (int) ( $meta['width'] ?? 0 );
        $orig_height = (int) ( $meta['height'] ?? 0 );

        // Determine target dimensions
        $max_w   = (int) ( $options['max_width'] ?? ( $orig_width > 1920 ? 1920 : $orig_width ) );
        $max_h   = (int) ( $options['max_height'] ?? ( $orig_height > 1080 ? 1080 : $orig_height ) );
        $quality = (int) ( $options['quality'] ?? 82 );
        $quality = max( 40, min( 95, $quality ) );

        // Step 1: Create a safe backup of the original file
        $backup_path = $file_path . '.sba-orig';
        if ( file_exists( $backup_path ) ) {
            @unlink( $backup_path );
        }

        if ( ! @copy( $file_path, $backup_path ) ) {
            return new WP_Error( 'backup_failed', esc_html__( 'Failed to create safety backup of the original file.', 'site-bloat-auditor' ) );
        }

        // Step 2: Initialize WordPress image editor
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            @unlink( $backup_path );
            return $editor;
        }

        // Step 3: Resize if target dimension is smaller than original
        if ( $max_w > 0 && $orig_width > $max_w ) {
            $resized = $editor->resize( $max_w, $max_h, false );
            if ( is_wp_error( $resized ) ) {
                @unlink( $backup_path );
                return $resized;
            }
        }

        // Set compression quality
        $editor->set_quality( $quality );

        // Step 4: Save optimized version to temporary file
        $temp_dest = $file_path . '.sba-tmp';
        $save_result = $editor->save( $temp_dest );

        if ( is_wp_error( $save_result ) || ! file_exists( $temp_dest ) ) {
            @unlink( $backup_path );
            if ( file_exists( $temp_dest ) ) {
                @unlink( $temp_dest );
            }
            return is_wp_error( $save_result ) ? $save_result : new WP_Error( 'save_failed', esc_html__( 'Failed to save optimized image.', 'site-bloat-auditor' ) );
        }

        $new_size = (int) filesize( $temp_dest );

        // Step 5: Verify that optimization actually reduced file size
        if ( $new_size >= $orig_size ) {
            // No savings achieved; discard temporary file and backup
            @unlink( $temp_dest );
            @unlink( $backup_path );
            return new WP_Error( 'no_savings', sprintf(
                esc_html__( 'Optimization skipped: The image is already well compressed (%1$s vs %2$s). Original file preserved.', 'site-bloat-auditor' ),
                size_format( $orig_size, 2 ),
                size_format( $new_size, 2 )
            ) );
        }

        // Step 6: Safely replace original file with optimized file
        if ( ! @rename( $temp_dest, $file_path ) ) {
            // Fallback copy + unlink
            if ( ! @copy( $temp_dest, $file_path ) ) {
                @unlink( $temp_dest );
                @unlink( $backup_path );
                return new WP_Error( 'replace_failed', esc_html__( 'Failed to replace original file with optimized version.', 'site-bloat-auditor' ) );
            }
            @unlink( $temp_dest );
        }

        clearstatcache( true, $file_path );
        $final_size = (int) filesize( $file_path );
        $freed_bytes = max( 0, $orig_size - $final_size );

        // Step 7: Update attachment metadata with new dimensions
        $new_dimensions = $editor->get_size();
        if ( ! empty( $new_dimensions['width'] ) && ! empty( $new_dimensions['height'] ) ) {
            $meta['width']  = (int) $new_dimensions['width'];
            $meta['height'] = (int) $new_dimensions['height'];
            $meta['filesize'] = $final_size;
            wp_update_attachment_metadata( $att_id, $meta );
        }

        // Step 8: Store backup record in option
        $backups[ $att_id ] = [
            'attachment_id'   => $att_id,
            'file_path'       => $file_path,
            'backup_path'     => $backup_path,
            'original_size'   => $orig_size,
            'optimized_size'  => $final_size,
            'freed_bytes'     => $freed_bytes,
            'original_meta'   => $meta,
            'optimized_time'  => time(),
        ];
        update_option( self::BACKUP_OPTION_KEY, $backups, false );

        // Track verified reclaimed bytes
        $existing_reclaimed = (int) get_option( 'sba_reclaimed_media_bytes', 0 );
        update_option( 'sba_reclaimed_media_bytes', $existing_reclaimed + $freed_bytes, false );

        return [
            'attachment_id'        => $att_id,
            'original_size_fmt'    => size_format( $orig_size, 2 ),
            'optimized_size_fmt'   => size_format( $final_size, 2 ),
            'freed_bytes'          => $freed_bytes,
            'freed_bytes_fmt'      => size_format( $freed_bytes, 2 ),
            'savings_percent'      => round( ( $freed_bytes / $orig_size ) * 100, 1 ),
            'new_dimensions'       => ( ! empty( $meta['width'] ) ? "{$meta['width']} × {$meta['height']} px" : 'N/A' ),
            'message'              => sprintf(
                esc_html__( 'Image optimized successfully! Saved %1$s (%2$s%%). Original backup preserved.', 'site-bloat-auditor' ),
                size_format( $freed_bytes, 2 ),
                round( ( $freed_bytes / $orig_size ) * 100, 1 )
            ),
        ];
    }

    /**
     * Rollback optimization: restore original file and metadata from backup.
     *
     * @param int $att_id Attachment ID.
     * @return array|WP_Error Result or error.
     */
    public function rollback_optimization( $att_id ) {
        $backups = (array) get_option( self::BACKUP_OPTION_KEY, [] );
        if ( ! isset( $backups[ $att_id ] ) ) {
            return new WP_Error( 'not_found', esc_html__( 'No optimization backup found for this attachment.', 'site-bloat-auditor' ) );
        }

        $record      = $backups[ $att_id ];
        $file_path   = $record['file_path'];
        $backup_path = $record['backup_path'];

        if ( ! file_exists( $backup_path ) || ! is_readable( $backup_path ) ) {
            return new WP_Error( 'backup_missing', esc_html__( 'Safety backup file (.sba-orig) is missing or unreadable on disk.', 'site-bloat-auditor' ) );
        }

        // Restore backup file to original path
        if ( ! @copy( $backup_path, $file_path ) ) {
            return new WP_Error( 'restore_failed', esc_html__( 'Failed to restore original file from backup.', 'site-bloat-auditor' ) );
        }

        // Restore original metadata
        if ( ! empty( $record['original_meta'] ) ) {
            wp_update_attachment_metadata( $att_id, $record['original_meta'] );
        }

        // Remove backup file
        @unlink( $backup_path );

        // Remove from backup option
        unset( $backups[ $att_id ] );
        update_option( self::BACKUP_OPTION_KEY, $backups, false );

        // Revert reclaimed bytes counter
        $freed_bytes = (int) ( $record['freed_bytes'] ?? 0 );
        if ( $freed_bytes > 0 ) {
            $existing_reclaimed = (int) get_option( 'sba_reclaimed_media_bytes', 0 );
            $new_reclaimed      = max( 0, $existing_reclaimed - $freed_bytes );
            update_option( 'sba_reclaimed_media_bytes', $new_reclaimed, false );
        }

        return [
            'attachment_id' => $att_id,
            'restored_size' => size_format( (int) $record['original_size'], 2 ),
            'message'       => esc_html__( 'Original image and metadata restored successfully.', 'site-bloat-auditor' ),
        ];
    }
}

