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
        if ( null === $meta && $att_id ) {
            $meta = wp_get_attachment_metadata( $att_id );
        }

        $file_path = $att_id ? get_attached_file( $att_id ) : '';
        if ( $file_path ) {
            $file_path = wp_normalize_path( $file_path );
        }

        // Determine actual current dimensions on disk
        $curr_w = 0;
        $curr_h = 0;
        if ( $file_path && file_exists( $file_path ) && $this->is_file_readable( $file_path ) ) {
            $curr_info = @getimagesize( $file_path );
            if ( false !== $curr_info && ! empty( $curr_info[0] ) && ! empty( $curr_info[1] ) ) {
                $curr_w = (int) $curr_info[0];
                $curr_h = (int) $curr_info[1];
            }
        }
        if ( $curr_w <= 0 || $curr_h <= 0 ) {
            $curr_w = (int) ( $meta['width'] ?? 0 );
            $curr_h = (int) ( $meta['height'] ?? 0 );
        }

        $filesize = ( $file_path && file_exists( $file_path ) ) ? (int) @filesize( $file_path ) : 0;

        // Retrieve verified original dimensions from backup record or .sba-orig backup file if backed up
        $backups               = (array) get_option( self::BACKUP_OPTION_KEY, [] );
        $has_backup            = ( $att_id && isset( $backups[ $att_id ] ) );
        $backup_record         = $has_backup ? $backups[ $att_id ] : null;
        $orig_w                = 0;
        $orig_h                = 0;
        $has_verified_orig_dim = false;

        if ( $has_backup && $backup_record ) {
            $b_meta = $backup_record['original_meta'] ?? [];
            $orig_w = (int) ( $b_meta['width'] ?? 0 );
            $orig_h = (int) ( $b_meta['height'] ?? 0 );
            if ( $orig_w <= 0 || $orig_h <= 0 ) {
                $b_path = $backup_record['backup_path'] ?? ( $file_path . '.sba-orig' );
                if ( $b_path && file_exists( $b_path ) && $this->is_file_readable( $b_path ) ) {
                    $b_info = @getimagesize( $b_path );
                    if ( false !== $b_info && ! empty( $b_info[0] ) && ! empty( $b_info[1] ) ) {
                        $orig_w = (int) $b_info[0];
                        $orig_h = (int) $b_info[1];
                    }
                }
            }
            if ( $orig_w > 0 && $orig_h > 0 ) {
                $orig_dim_str          = "{$orig_w} × {$orig_h} px";
                $has_verified_orig_dim = true;
            } else {
                $orig_dim_str          = esc_html__( 'Original dimensions unavailable', 'site-bloat-auditor' );
                $has_verified_orig_dim = false;
            }
        } else {
            // Not backed up: current file on disk is the original
            $orig_w                = $curr_w;
            $orig_h                = $curr_h;
            $orig_dim_str          = ( $orig_w > 0 && $orig_h > 0 ) ? "{$orig_w} × {$orig_h} px" : 'N/A';
            $has_verified_orig_dim = ( $orig_w > 0 && $orig_h > 0 );
        }

        $curr_dim_str = ( $curr_w > 0 && $curr_h > 0 ) ? "{$curr_w} × {$curr_h} px" : 'N/A';
        $is_resized   = ( $has_backup && $has_verified_orig_dim && $curr_w > 0 && $curr_h > 0 && ( $curr_w !== $orig_w || $curr_h !== $orig_h ) );

        // Benchmarks: 1920px max for hero/featured, 1200px max for content
        $benchmark_w        = $is_hero ? 1920 : 1200;
        $is_oversized_dim   = ( $curr_w > $benchmark_w );
        $is_oversized_bytes = ( $filesize > 512000 ); // > 500 KB

        if ( $is_oversized_dim && $curr_w > 0 && $curr_h > 0 ) {
            $rec_w               = $benchmark_w;
            $rec_h               = (int) round( ( $benchmark_w / $curr_w ) * $curr_h );
            $rec_dim_str         = "{$rec_w} × {$rec_h} px";
            $target_reached      = false;
            $recommendation_text = sprintf(
                /* translators: %s: recommended resolution */
                esc_html__( 'Scale down to %s', 'site-bloat-auditor' ),
                $rec_dim_str
            );
        } else {
            $rec_w               = $curr_w;
            $rec_h               = $curr_h;
            $rec_dim_str         = $curr_dim_str;
            $target_reached      = true;
            $recommendation_text = esc_html__( 'Target dimensions reached — no further resizing needed.', 'site-bloat-auditor' );
        }

        // Check WebP status separately
        $webp_path = $file_path ? preg_replace( '/\.[^.]+$/', '.webp', $file_path ) : '';
        $has_webp  = ( $file_path && strtolower( substr( $file_path, -5 ) ) === '.webp' ) ||
                     ( ! empty( $meta['sba_webp']['path'] ) && file_exists( $meta['sba_webp']['path'] ) ) ||
                     ( $webp_path && file_exists( $webp_path ) && (int) @filesize( $webp_path ) > 0 );

        // Estimate optimized size
        $estimated_bytes = $filesize;
        $savings_bytes   = 0;

        if ( $is_oversized_dim || $is_oversized_bytes ) {
            if ( $is_oversized_dim && $curr_w > 0 ) {
                $scale_factor    = ( $rec_w * $rec_h ) / ( $curr_w * $curr_h );
                $estimated_bytes = (int) round( $filesize * $scale_factor * 0.85 );
            } else {
                $estimated_bytes = (int) round( $filesize * 0.70 ); // compression savings estimate
            }
            $savings_bytes = max( 0, $filesize - $estimated_bytes );
        }

        $savings_percent = ( $filesize > 0 && $savings_bytes > 0 ) ? round( ( $savings_bytes / $filesize ) * 100, 1 ) : 0;

        return [
            'is_oversized'           => $is_oversized_dim,
            'is_oversized_dim'       => $is_oversized_dim,
            'is_oversized_bytes'     => $is_oversized_bytes,
            'original_width'         => $orig_w,
            'original_height'        => $orig_h,
            'original_dim'           => $orig_dim_str,
            'original_dimensions'    => $orig_dim_str,
            'has_verified_orig_dim'  => $has_verified_orig_dim,
            'current_width'          => $curr_w,
            'current_height'         => $curr_h,
            'current_dim'            => $curr_dim_str,
            'current_dimensions'     => $curr_dim_str,
            'is_resized'             => $is_resized,
            'target_reached'         => $target_reached,
            'recommendation_text'    => $recommendation_text,
            'recommended_width'      => $rec_w,
            'recommended_height'     => $rec_h,
            'recommended_dim'        => $rec_dim_str,
            'recommended_dimensions' => $rec_dim_str,
            'has_webp'               => $has_webp,
            'has_backup'             => $has_backup,
            'current_size_bytes'     => $filesize,
            'current_size_fmt'       => size_format( $filesize, 2 ),
            'estimated_size_bytes'   => $estimated_bytes,
            'estimated_size_fmt'     => size_format( $estimated_bytes, 2 ),
            'savings_bytes'          => $savings_bytes,
            'savings_fmt'            => size_format( $savings_bytes, 2 ),
            'savings_percent'        => $savings_percent,
            'assumptions'            => esc_html__( 'Assumes 82% quality compression and proportional downscaling to display benchmark.', 'site-bloat-auditor' ),
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
        if ( $file_path ) {
            $file_path = wp_normalize_path( $file_path );
        }
        if ( ! $file_path || ! file_exists( $file_path ) || ! $this->is_file_readable( $file_path ) ) {
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

        // Capture original SHA-256 hash and pristine original metadata before modifying anything
        $orig_sha256 = hash_file( 'sha256', $file_path );
        if ( empty( $orig_sha256 ) ) {
            return new WP_Error( 'hash_failed', esc_html__( 'Failed to compute SHA-256 hash of original file.', 'site-bloat-auditor' ) );
        }

        $orig_meta   = wp_get_attachment_metadata( $att_id );
        $orig_width  = (int) ( $orig_meta['width'] ?? 0 );
        $orig_height = (int) ( $orig_meta['height'] ?? 0 );

        // Determine target dimensions
        $max_w   = (int) ( $options['max_width'] ?? ( $orig_width > 1920 ? 1920 : $orig_width ) );
        $max_h   = (int) ( $options['max_height'] ?? ( $orig_height > 1080 ? 1080 : $orig_height ) );
        $quality = (int) ( $options['quality'] ?? 82 );
        $quality = max( 40, min( 95, $quality ) );

        // WebP Support Verification
        $env_support       = $this->check_environment_support();
        $force_unsupported = ! empty( $options['force_webp_unsupported'] ) || apply_filters( 'sba_force_webp_unsupported', false );
        if ( $force_unsupported || empty( $env_support['supports_webp'] ) ) {
            return new WP_Error(
                'webp_unsupported',
                esc_html__( 'WebP conversion is unsupported: PHP GD imagewebp or Imagick WebP encoder is not available on this server.', 'site-bloat-auditor' )
            );
        }

        // Step 1: Stage a safe backup of the original file (never touch or replace existing backup until staging verified)
        $backup_path     = $file_path . '.sba-orig';
        $old_backup_hold = '';
        $stage_backup    = '';
        $existing_valid  = false;

        if ( file_exists( $backup_path ) && $this->is_file_readable( $backup_path ) ) {
            $existing_hash = hash_file( 'sha256', $backup_path );
            if ( $existing_hash === $orig_sha256 ) {
                $existing_valid = true;
            }
        }

        if ( ! $existing_valid ) {
            $stage_backup = $backup_path . '.stage-' . wp_generate_password( 8, false, false );
            if ( ! @copy( $file_path, $stage_backup ) ) {
                return new WP_Error( 'backup_failed', esc_html__( 'Failed to stage safety backup of the original file.', 'site-bloat-auditor' ) );
            }

            $staged_hash = hash_file( 'sha256', $stage_backup );
            if ( $staged_hash !== $orig_sha256 ) {
                @unlink( $stage_backup );
                return new WP_Error( 'backup_corrupt', esc_html__( 'Staged safety backup did not match the original file SHA-256 hash.', 'site-bloat-auditor' ) );
            }
        }

        // Step 2: Initialize WordPress image editor
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            if ( $stage_backup && file_exists( $stage_backup ) ) {
                @unlink( $stage_backup );
            }
            return $editor;
        }

        if ( ! $editor->supports_mime_type( 'image/webp' ) ) {
            if ( $stage_backup && file_exists( $stage_backup ) ) {
                @unlink( $stage_backup );
            }
            return new WP_Error(
                'webp_unsupported',
                esc_html__( 'The selected WordPress image editor does not support WebP generation.', 'site-bloat-auditor' )
            );
        }

        // Step 3: Resize if target dimension is smaller than original
        if ( $max_w > 0 && $orig_width > $max_w ) {
            $resized = $editor->resize( $max_w, $max_h, false );
            if ( is_wp_error( $resized ) ) {
                if ( $stage_backup && file_exists( $stage_backup ) ) {
                    @unlink( $stage_backup );
                }
                return $resized;
            }
        }

        // Set compression quality
        $editor->set_quality( $quality );

        // Step 4: Generate genuine WebP version to temporary staging file
        $webp_path     = preg_replace( '/\.[^.]+$/', '.webp', $file_path );
        $temp_webp     = dirname( $file_path ) . '/sba-opt-tmp-' . wp_generate_password( 8, false, false ) . '.webp';
        $save_webp_res = $editor->save( $temp_webp, 'image/webp' );

        $saved_webp = ( is_array( $save_webp_res ) && ! empty( $save_webp_res['path'] ) && file_exists( $save_webp_res['path'] ) )
            ? $save_webp_res['path']
            : ( file_exists( $temp_webp ) ? $temp_webp : false );

        if ( is_wp_error( $save_webp_res ) || ! $saved_webp ) {
            if ( file_exists( $temp_webp ) ) {
                @unlink( $temp_webp );
            }
            if ( $stage_backup && file_exists( $stage_backup ) ) {
                @unlink( $stage_backup );
            }
            return is_wp_error( $save_webp_res ) ? $save_webp_res : new WP_Error( 'webp_failed', esc_html__( 'Failed to generate WebP image.', 'site-bloat-auditor' ) );
        }

        // Step 5: Full validation of WebP output
        $webp_valid = $this->validate_webp_file( $saved_webp );
        if ( is_wp_error( $webp_valid ) ) {
            @unlink( $saved_webp );
            if ( $stage_backup && file_exists( $stage_backup ) ) {
                @unlink( $stage_backup );
            }
            return $webp_valid;
        }
        $webp_size = (int) filesize( $saved_webp );

        // Step 5b: Handle original-format optimization for compatibility
        $is_already_webp = ( strtolower( $webp_path ) === strtolower( $file_path ) );
        $saved_file      = '';
        $new_size        = 0;
        $temp_dest       = '';

        if ( $is_already_webp ) {
            $saved_file = $saved_webp;
            $new_size   = $webp_size;
        } else {
            // Save downscaled/compressed version preserving original format so existing URLs never break
            $ext         = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
            $temp_dest   = dirname( $file_path ) . '/sba-opt-tmp-' . wp_generate_password( 8, false, false ) . '.' . $ext;
            $save_result = $editor->save( $temp_dest );

            $saved_file = ( is_array( $save_result ) && ! empty( $save_result['path'] ) && file_exists( $save_result['path'] ) )
                ? $save_result['path']
                : ( file_exists( $temp_dest ) ? $temp_dest : false );

            if ( is_wp_error( $save_result ) || ! $saved_file ) {
                @unlink( $saved_webp );
                if ( file_exists( $temp_dest ) ) {
                    @unlink( $temp_dest );
                }
                if ( $stage_backup && file_exists( $stage_backup ) ) {
                    @unlink( $stage_backup );
                }
                return is_wp_error( $save_result ) ? $save_result : new WP_Error( 'save_failed', esc_html__( 'Failed to save optimized image.', 'site-bloat-auditor' ) );
            }

            $new_size = (int) filesize( $saved_file );

            // Image decoding validation on original format file
            $img_info = @getimagesize( $saved_file );
            if ( false === $img_info || empty( $img_info[0] ) || empty( $img_info[1] ) ) {
                @unlink( $saved_file );
                @unlink( $saved_webp );
                if ( $stage_backup && file_exists( $stage_backup ) ) {
                    @unlink( $stage_backup );
                }
                return new WP_Error( 'corrupt_output', esc_html__( 'Optimized image failed image decoding verification.', 'site-bloat-auditor' ) );
            }

            // MIME type validation
            $detected_mime     = $img_info['mime'] ?? '';
            $valid_image_mimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ];
            if ( ! in_array( $detected_mime, $valid_image_mimes, true ) ) {
                @unlink( $saved_file );
                @unlink( $saved_webp );
                if ( $stage_backup && file_exists( $stage_backup ) ) {
                    @unlink( $stage_backup );
                }
                return new WP_Error( 'invalid_mime', sprintf( esc_html__( 'Optimized file produced invalid MIME type "%s".', 'site-bloat-auditor' ), esc_html( $detected_mime ) ) );
            }
        }

        // Verify that optimization actually reduced file size
        if ( $new_size >= $orig_size && $webp_size >= $orig_size ) {
            if ( $saved_file && file_exists( $saved_file ) ) {
                @unlink( $saved_file );
            }
            if ( $saved_webp && file_exists( $saved_webp ) && $saved_webp !== $saved_file ) {
                @unlink( $saved_webp );
            }
            if ( $stage_backup && file_exists( $stage_backup ) ) {
                @unlink( $stage_backup );
            }
            return new WP_Error( 'no_savings', sprintf(
                esc_html__( 'Optimization skipped: The image is already well compressed (%1$s vs %2$s). Original file preserved.', 'site-bloat-auditor' ),
                size_format( $orig_size, 2 ),
                size_format( min( $new_size, $webp_size ), 2 )
            ) );
        }

        // Step 6: Commit safety backup and perform recoverable file placement
        if ( ! $existing_valid && $stage_backup && file_exists( $stage_backup ) ) {
            if ( file_exists( $backup_path ) ) {
                $old_backup_hold = $backup_path . '.old-hold-' . wp_generate_password( 8, false, false );
                if ( ! @rename( $backup_path, $old_backup_hold ) ) {
                    @copy( $backup_path, $old_backup_hold );
                }
            }

            $committed = @rename( $stage_backup, $backup_path );
            if ( ! $committed ) {
                $committed = @copy( $stage_backup, $backup_path );
                if ( $committed ) {
                    @unlink( $stage_backup );
                }
            }

            clearstatcache( true, $backup_path );
            $final_backup_hash = file_exists( $backup_path ) ? hash_file( 'sha256', $backup_path ) : '';
            if ( $final_backup_hash !== $orig_sha256 ) {
                @unlink( $stage_backup );
                if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
                    @rename( $old_backup_hold, $backup_path );
                }
                @unlink( $saved_file );
                if ( ! $is_already_webp && file_exists( $saved_webp ) ) {
                    @unlink( $saved_webp );
                }
                return new WP_Error( 'backup_commit_failed', esc_html__( 'Final safety backup on disk failed SHA-256 verification.', 'site-bloat-auditor' ) );
            }
        }

        // Verify safety backup exists and matches original hash before touching original file
        clearstatcache( true, $backup_path );
        $verify_backup_hash = file_exists( $backup_path ) ? hash_file( 'sha256', $backup_path ) : '';
        if ( $verify_backup_hash !== $orig_sha256 ) {
            @unlink( $saved_file );
            if ( ! $is_already_webp && file_exists( $saved_webp ) ) {
                @unlink( $saved_webp );
            }
            if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
                @rename( $old_backup_hold, $backup_path );
            }
            return new WP_Error( 'backup_verification_failed', esc_html__( 'Replacement aborted: Safety backup file failed integrity check. Original file was preserved untouched.', 'site-bloat-auditor' ) );
        }

        // Commit WebP companion file if not already webp
        if ( ! $is_already_webp ) {
            $webp_committed = @rename( $saved_webp, $webp_path );
            if ( ! $webp_committed ) {
                $webp_committed = @copy( $saved_webp, $webp_path );
                if ( $webp_committed ) {
                    @unlink( $saved_webp );
                }
            }
            clearstatcache( true, $webp_path );
            $verify_webp_commit = $this->validate_webp_file( $webp_path );
            if ( is_wp_error( $verify_webp_commit ) ) {
                if ( file_exists( $webp_path ) ) {
                    @unlink( $webp_path );
                }
                @unlink( $saved_file );
                if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
                    @rename( $old_backup_hold, $backup_path );
                }
                return $verify_webp_commit;
            }
        }

        // Stage original file hold in the same directory prior to replacement
        $orig_hold = $file_path . '.orig-hold-' . wp_generate_password( 8, false, false );
        if ( ! @rename( $file_path, $orig_hold ) ) {
            if ( ! @copy( $file_path, $orig_hold ) ) {
                @unlink( $saved_file );
                if ( ! $is_already_webp && file_exists( $webp_path ) ) {
                    @unlink( $webp_path );
                }
                if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
                    @rename( $old_backup_hold, $backup_path );
                }
                return new WP_Error( 'hold_failed', esc_html__( 'Failed to stage original image hold prior to replacement. Original file was preserved untouched.', 'site-bloat-auditor' ) );
            }
        }

        // Move optimized file to destination
        $replace_success = @rename( $saved_file, $file_path );
        if ( ! $replace_success ) {
            $replace_success = @copy( $saved_file, $file_path );
            if ( $replace_success ) {
                @unlink( $saved_file );
            }
        }

        // Verify the replaced file on disk before finalizing
        clearstatcache( true, $file_path );
        $verify_replaced = false;
        if ( $replace_success && file_exists( $file_path ) && filesize( $file_path ) === $new_size ) {
            $check_replaced = @getimagesize( $file_path );
            if ( false !== $check_replaced && ! empty( $check_replaced[0] ) && ! empty( $check_replaced[1] ) ) {
                $verify_replaced = true;
            }
        }

        if ( ! $verify_replaced ) {
            // RECOVERY PLAN: Restore the original file from hold or backup
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
            if ( file_exists( $orig_hold ) ) {
                @rename( $orig_hold, $file_path );
            }
            if ( ! file_exists( $file_path ) && file_exists( $backup_path ) ) {
                @copy( $backup_path, $file_path );
            }
            if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
                @rename( $old_backup_hold, $backup_path );
            }
            if ( ! $is_already_webp && file_exists( $webp_path ) ) {
                @unlink( $webp_path );
            }
            @unlink( $saved_file );
            if ( file_exists( $orig_hold ) ) {
                @unlink( $orig_hold );
            }
            return new WP_Error( 'replacement_failed', esc_html__( 'Optimized image replacement failed filesystem verification. Original file was safely restored.', 'site-bloat-auditor' ) );
        }

        // Replacement fully verified on disk! Safely remove the hold file
        @unlink( $orig_hold );
        @unlink( $saved_file );
        if ( $old_backup_hold && file_exists( $old_backup_hold ) ) {
            @unlink( $old_backup_hold );
        }

        clearstatcache( true, $file_path );
        clearstatcache( true, $webp_path );
        $final_size  = (int) filesize( $file_path );
        $final_webp  = (int) filesize( $webp_path );
        $freed_bytes = max( 0, $orig_size - $final_size );

        // Step 7: Update attachment metadata using a modified copy
        $new_meta = is_array( $orig_meta ) ? $orig_meta : [];
        $new_dimensions = $editor->get_size();
        if ( ! empty( $new_dimensions['width'] ) && ! empty( $new_dimensions['height'] ) ) {
            $new_meta['width']  = (int) $new_dimensions['width'];
            $new_meta['height'] = (int) $new_dimensions['height'];
        }
        $new_meta['filesize'] = $final_size;
        $new_meta['sba_webp'] = [
            'file'      => wp_basename( $webp_path ),
            'path'      => $webp_path,
            'filesize'  => $final_webp,
            'mime_type' => 'image/webp',
            'width'     => (int) ( $new_dimensions['width'] ?? $orig_width ),
            'height'    => (int) ( $new_dimensions['height'] ?? $orig_height ),
        ];
        wp_update_attachment_metadata( $att_id, $new_meta );

        // Step 8: Store backup record with original hash and pristine original metadata
        $backups[ $att_id ] = [
            'attachment_id'   => $att_id,
            'file_path'       => $file_path,
            'backup_path'     => $backup_path,
            'webp_path'       => $webp_path,
            'original_size'   => $orig_size,
            'original_sha256' => $orig_sha256,
            'optimized_size'  => $final_size,
            'webp_size'       => $final_webp,
            'freed_bytes'     => $freed_bytes,
            'original_meta'   => $orig_meta, // Preserves untouched original metadata for flawless rollback
            'optimized_time'  => time(),
            'output_format'   => 'webp',
        ];
        update_option( self::BACKUP_OPTION_KEY, $backups, false );

        $new_w = (int) ( $new_meta['width'] ?? 0 );
        $new_h = (int) ( $new_meta['height'] ?? 0 );
        $new_dim_str = ( $new_w > 0 && $new_h > 0 ) ? "{$new_w} × {$new_h} px" : 'N/A';
        $orig_dim_str = ( $orig_width > 0 && $orig_height > 0 ) ? "{$orig_width} × {$orig_height} px" : 'N/A';
        $is_resized   = ( $orig_width > 0 && $new_w > 0 && ( $orig_width !== $new_w || $orig_height !== $new_h ) );

        return [
            'attachment_id'        => $att_id,
            'file_path'            => $file_path,
            'output_path'          => $webp_path,
            'webp_path'            => $webp_path,
            'output_format'        => 'webp',
            'is_webp'              => true,
            'has_webp'             => true,
            'conversion_status'    => 'converted',
            'original_size_bytes'  => $orig_size,
            'original_size_fmt'    => size_format( $orig_size, 2 ),
            'optimized_size_bytes' => $final_size,
            'optimized_size_fmt'   => size_format( $final_size, 2 ),
            'webp_size_bytes'      => $final_webp,
            'webp_size_fmt'        => size_format( $final_webp, 2 ),
            'file_size_formatted'  => size_format( $final_size, 2 ),
            'freed_bytes'          => $freed_bytes,
            'freed_bytes_fmt'      => size_format( $freed_bytes, 2 ),
            'saved_bytes'          => $freed_bytes,
            'saved_bytes_fmt'      => size_format( $freed_bytes, 2 ),
            'savings_percent'      => ( $orig_size > 0 ) ? round( ( $freed_bytes / $orig_size ) * 100, 1 ) : 0,
            'original_width'       => $orig_width,
            'original_height'      => $orig_height,
            'original_dimensions'  => $orig_dim_str,
            'has_verified_orig_dim'=> ( $orig_width > 0 && $orig_height > 0 ),
            'current_width'        => $new_w,
            'current_height'       => $new_h,
            'current_dimensions'   => $new_dim_str,
            'width'                => $new_w,
            'height'               => $new_h,
            'new_dimensions'       => $new_dim_str,
            'dimensions'           => $new_dim_str,
            'is_resized'           => $is_resized,
            'target_reached'       => true,
            'recommendation_text'  => esc_html__( 'Target dimensions reached — no further resizing needed.', 'site-bloat-auditor' ),
            'recommended_width'    => $new_w,
            'recommended_height'   => $new_h,
            'recommended_dim'      => $new_dim_str,
            'recommended_dimensions' => $new_dim_str,
            'has_backup'           => true,
            'original_sha256'      => $orig_sha256,
            'backup_path'          => $backup_path,
            'is_oversized'         => false,
            'category'             => 'optimized',
            'category_label'       => esc_html__( 'Optimized', 'site-bloat-auditor' ),
            'recommendation_badge' => esc_html__( 'Web Optimized (WebP)', 'site-bloat-auditor' ),
            'badge_class'          => 'sba-pill-active',
            'message'              => sprintf(
                esc_html__( 'Image optimized to WebP successfully! Saved %1$s (%2$s%%). Original backup preserved.', 'site-bloat-auditor' ),
                size_format( $freed_bytes, 2 ),
                ( $orig_size > 0 ) ? round( ( $freed_bytes / $orig_size ) * 100, 1 ) : 0
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
        $file_path   = ! empty( $record['file_path'] ) ? wp_normalize_path( $record['file_path'] ) : '';
        $backup_path = ! empty( $record['backup_path'] ) ? wp_normalize_path( $record['backup_path'] ) : '';

        // Step 1: Pre-flight validation of the backup file BEFORE touching current image
        if ( ! file_exists( $backup_path ) || ! $this->is_file_readable( $backup_path ) ) {
            return new WP_Error( 'backup_missing', esc_html__( 'Safety backup file (.sba-orig) is missing or unreadable on disk. Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        $expected_sha256 = $record['original_sha256'] ?? '';
        if ( empty( $expected_sha256 ) ) {
            return new WP_Error( 'missing_expected_hash', esc_html__( 'Rollback aborted: No expected SHA-256 hash in backup record. Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        $backup_sha256 = hash_file( 'sha256', $backup_path );
        if ( $backup_sha256 !== $expected_sha256 ) {
            return new WP_Error( 'backup_tampered', esc_html__( 'Rollback aborted: Safety backup file failed SHA-256 integrity verification (tampered or corrupted). Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        // Validate image readability of backup file
        $backup_img_info = @getimagesize( $backup_path );
        if ( false === $backup_img_info || empty( $backup_img_info[0] ) || empty( $backup_img_info[1] ) ) {
            return new WP_Error( 'backup_corrupt', esc_html__( 'Rollback aborted: Safety backup file is not a valid decoded image. Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        // Step 2: Restore through temporary staging file in the same directory and verify restored content
        $ext           = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $restore_stage = dirname( $file_path ) . '/sba-restore-stage-' . wp_generate_password( 8, false, false ) . '.' . $ext;

        if ( ! @copy( $backup_path, $restore_stage ) ) {
            return new WP_Error( 'staging_failed', esc_html__( 'Failed to stage restoration file from backup. Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        $staged_hash = hash_file( 'sha256', $restore_stage );
        if ( $staged_hash !== $expected_sha256 ) {
            @unlink( $restore_stage );
            return new WP_Error( 'staged_hash_mismatch', esc_html__( 'Staged restoration file did not match original SHA-256 hash. Current image was preserved untouched.', 'site-bloat-auditor' ) );
        }

        // Step 3: Atomic swap with hold of current file
        $curr_hold = $file_path . '.curr-hold-' . wp_generate_password( 8, false, false );
        if ( file_exists( $file_path ) ) {
            if ( ! @rename( $file_path, $curr_hold ) ) {
                if ( ! @copy( $file_path, $curr_hold ) ) {
                    @unlink( $restore_stage );
                    return new WP_Error( 'hold_failed', esc_html__( 'Failed to stage current image hold. Rollback aborted and current image preserved.', 'site-bloat-auditor' ) );
                }
            }
        }

        // Commit restored file to destination
        $commit_restore = @rename( $restore_stage, $file_path );
        if ( ! $commit_restore ) {
            $commit_restore = @copy( $restore_stage, $file_path );
            if ( $commit_restore ) {
                @unlink( $restore_stage );
            }
        }

        // Verify final restored content on disk
        clearstatcache( true, $file_path );
        $restored_hash = file_exists( $file_path ) ? hash_file( 'sha256', $file_path ) : '';
        if ( $restored_hash !== $expected_sha256 ) {
            // Restore failure recovery: reinstate current file from hold
            if ( file_exists( $file_path ) ) {
                @unlink( $file_path );
            }
            if ( file_exists( $curr_hold ) ) {
                @rename( $curr_hold, $file_path );
            }
            @unlink( $restore_stage );
            return new WP_Error( 'restore_verification_failed', esc_html__( 'Restored file failed hash verification on disk. Current image was restored from hold.', 'site-bloat-auditor' ) );
        }

        // Final restoration succeeded; clean up hold and temporary stage
        if ( file_exists( $curr_hold ) ) {
            @unlink( $curr_hold );
        }
        if ( file_exists( $restore_stage ) ) {
            @unlink( $restore_stage );
        }

        // Step 4: Restore original attachment metadata only AFTER file restoration succeeds
        if ( ! empty( $record['original_meta'] ) && is_array( $record['original_meta'] ) ) {
            wp_update_attachment_metadata( $att_id, $record['original_meta'] );
        }

        // Clean up companion WebP file if generated
        $webp_file = ! empty( $record['webp_path'] ) ? $record['webp_path'] : preg_replace( '/\.[^.]+$/', '.webp', $file_path );
        if ( $webp_file !== $file_path && file_exists( $webp_file ) ) {
            @unlink( $webp_file );
        }

        // Step 5: Do not remove backup record and backup file until file restoration and metadata are confirmed
        @unlink( $backup_path );
        unset( $backups[ $att_id ] );
        update_option( self::BACKUP_OPTION_KEY, $backups, false );

        $orig_meta = $record['original_meta'] ?? [];
        $orig_w    = (int) ( $orig_meta['width'] ?? 0 );
        $orig_h    = (int) ( $orig_meta['height'] ?? 0 );
        if ( ( $orig_w <= 0 || $orig_h <= 0 ) && file_exists( $file_path ) ) {
            $restored_info = @getimagesize( $file_path );
            if ( false !== $restored_info && ! empty( $restored_info[0] ) && ! empty( $restored_info[1] ) ) {
                $orig_w = (int) $restored_info[0];
                $orig_h = (int) $restored_info[1];
            }
        }
        $orig_size          = (int) $record['original_size'];
        $benchmark_w        = 1200;
        $is_oversized_again = ( $orig_w > $benchmark_w );
        $orig_dim_str       = ( $orig_w > 0 && $orig_h > 0 ) ? "{$orig_w} × {$orig_h} px" : 'N/A';

        if ( $is_oversized_again && $orig_w > 0 ) {
            $rec_w       = $benchmark_w;
            $rec_h       = (int) round( ( $benchmark_w / $orig_w ) * $orig_h );
            $rec_dim_str = "{$rec_w} × {$rec_h} px";
            $rec_text    = sprintf(
                /* translators: %s: recommended resolution */
                esc_html__( 'Scale down to %s', 'site-bloat-auditor' ),
                $rec_dim_str
            );
            $badge_text  = $rec_text;
            $badge_class = 'sba-pill-warning';
            $category    = 'oversized';
            $cat_label   = esc_html__( 'Needs Resize', 'site-bloat-auditor' );
        } else {
            $rec_w       = $orig_w;
            $rec_h       = $orig_h;
            $rec_dim_str = $orig_dim_str;
            $rec_text    = esc_html__( 'Target dimensions reached — no further resizing needed.', 'site-bloat-auditor' );
            $badge_text  = esc_html__( 'Web Optimized', 'site-bloat-auditor' );
            $badge_class = 'sba-pill-active';
            $category    = 'optimized';
            $cat_label   = esc_html__( 'Optimized', 'site-bloat-auditor' );
        }

        return [
            'attachment_id'          => $att_id,
            'restored_size'          => size_format( $orig_size, 2 ),
            'restored_size_bytes'    => $orig_size,
            'file_size_formatted'    => size_format( $orig_size, 2 ),
            'file_size_bytes'        => $orig_size,
            'width'                  => $orig_w,
            'height'                 => $orig_h,
            'current_width'          => $orig_w,
            'current_height'         => $orig_h,
            'current_dimensions'     => $orig_dim_str,
            'restored_dimensions'    => $orig_dim_str,
            'original_width'         => $orig_w,
            'original_height'        => $orig_h,
            'original_dimensions'    => $orig_dim_str,
            'has_verified_orig_dim'  => ( $orig_w > 0 && $orig_h > 0 ),
            'is_resized'             => false,
            'target_reached'         => ! $is_oversized_again,
            'recommendation_text'    => $rec_text,
            'recommended_width'      => $rec_w,
            'recommended_height'     => $rec_h,
            'recommended_dim'        => $rec_dim_str,
            'recommended_dimensions' => $rec_dim_str,
            'has_backup'             => false,
            'has_webp'               => false,
            'is_oversized'           => $is_oversized_again,
            'category'               => $category,
            'category_label'         => $cat_label,
            'recommendation_badge'   => $badge_text,
            'badge_class'            => $badge_class,
            'message'                => esc_html__( 'Original image and metadata restored successfully.', 'site-bloat-auditor' ),
        ];
    }

    /**
     * Validate that a file on disk is a genuine, readable, and decodable WebP image.
     * Verifies file existence, non-empty size, RIFF/WEBP magic bytes, MIME type, and image decodability.
     *
     * @param string $path Path to the WebP file.
     * @return true|WP_Error True if valid WebP, WP_Error otherwise.
     */
    public function validate_webp_file( $path ) {
        if ( empty( $path ) || ! file_exists( $path ) || ! $this->is_file_readable( $path ) ) {
            return new WP_Error( 'missing_webp', esc_html__( 'WebP file does not exist or is unreadable on disk.', 'site-bloat-auditor' ) );
        }

        $size = (int) @filesize( $path );
        if ( $size <= 0 ) {
            return new WP_Error( 'empty_webp', esc_html__( 'WebP file is 0 bytes.', 'site-bloat-auditor' ) );
        }

        // 1. Inspect magic bytes: bytes 0..3 must be "RIFF" and bytes 8..11 must be "WEBP"
        $fh = @fopen( $path, 'rb' );
        if ( ! $fh ) {
            return new WP_Error( 'unreadable_webp', esc_html__( 'Failed to open WebP file for header inspection.', 'site-bloat-auditor' ) );
        }
        $header = fread( $fh, 12 );
        fclose( $fh );

        if ( strlen( $header ) < 12 ) {
            return new WP_Error( 'invalid_webp_header', esc_html__( 'WebP file header is too short.', 'site-bloat-auditor' ) );
        }

        $riff = substr( $header, 0, 4 );
        $webp = substr( $header, 8, 4 );
        if ( 'RIFF' !== $riff || 'WEBP' !== $webp ) {
            return new WP_Error( 'not_a_webp', esc_html__( 'File header does not match WebP RIFF/WEBP magic bytes (file is not genuine WebP).', 'site-bloat-auditor' ) );
        }

        // 2. MIME type & dimension validation
        $img_info = @getimagesize( $path );
        if ( false === $img_info || empty( $img_info[0] ) || empty( $img_info[1] ) ) {
            return new WP_Error( 'corrupt_webp', esc_html__( 'WebP image failed decoding verification (invalid dimensions).', 'site-bloat-auditor' ) );
        }

        $detected_mime = $img_info['mime'] ?? '';
        if ( 'image/webp' !== $detected_mime ) {
            return new WP_Error( 'invalid_webp_mime', sprintf(
                /* translators: %s: detected MIME type */
                esc_html__( 'Optimized file produced MIME type "%s", expected "image/webp".', 'site-bloat-auditor' ),
                esc_html( $detected_mime )
            ) );
        }

        // 3. Decoding verification via GD or Imagick
        if ( function_exists( 'imagecreatefromwebp' ) ) {
            $gd = @imagecreatefromwebp( $path );
            if ( false === $gd ) {
                return new WP_Error( 'webp_decode_failed', esc_html__( 'WebP image could not be decoded by GD imagecreatefromwebp.', 'site-bloat-auditor' ) );
            }
            imagedestroy( $gd );
        } elseif ( class_exists( 'Imagick' ) ) {
            try {
                $im = new Imagick();
                $im->pingImage( $path );
                if ( strtoupper( $im->getImageFormat() ) !== 'WEBP' ) {
                    $im->clear();
                    $im->destroy();
                    return new WP_Error( 'webp_decode_failed', esc_html__( 'WebP image could not be decoded by Imagick.', 'site-bloat-auditor' ) );
                }
                $im->clear();
                $im->destroy();
            } catch ( Exception $e ) {
                return new WP_Error( 'webp_decode_failed', sprintf(
                    /* translators: %s: error message */
                    esc_html__( 'WebP image decoding failed: %s', 'site-bloat-auditor' ),
                    esc_html( $e->getMessage() )
                ) );
            }
        }

        return true;
    }

    /**
     * Cross-platform file readability check.
     * Handles Windows PHP is_readable ACL quirks via stream fallback.
     *
     * @param string $path Path to check.
     * @return bool True if readable.
     */
    private function is_file_readable( $path ) {
        if ( function_exists( 'sba_is_readable' ) ) {
            return sba_is_readable( $path );
        }
        if ( empty( $path ) || ! file_exists( $path ) ) {
            return false;
        }
        if ( @is_readable( $path ) ) {
            return true;
        }
        $fh = @fopen( $path, 'rb' );
        if ( false !== $fh ) {
            fclose( $fh );
            return true;
        }
        return false;
    }
}

