<?php
/**
 * Media Inventory Engine for Site Bloat & Storage Auditor.
 *
 * Scans media attachments and the physical uploads filesystem to build an
 * accurate, verified media inventory and discover disk discrepancies.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Media_Inventory {

    /**
     * Map of registered attachments indexed by ID.
     *
     * @var array
     */
    private $attachments_inventory = [];

    /**
     * Map of known physical file paths belonging to attachments (originals + subsizes).
     *
     * @var array
     */
    private $registered_filepaths = [];

    /**
     * Filesystem discrepancies (unattached files, missing originals, missing subsizes).
     *
     * @var array
     */
    private $discrepancies = [
        'missing_originals'   => [],
        'missing_subsizes'    => [],
        'unattached_files'    => [],
        'metadata_issues'     => [],
    ];

    /**
     * Scan all WordPress attachments and compile full inventory.
     *
     * @param array $args Optional query filters.
     * @return array Array of attachment inventory records.
     */
    public function scan_attachments( array $args = [] ) {
        global $wpdb;

        $upload_dir_info = wp_get_upload_dir();
        $basedir         = wp_normalize_path( $upload_dir_info['basedir'] );

        $batch_size      = isset( $args['batch_size'] ) ? max( 50, absint( $args['batch_size'] ) ) : 250;
        $max_limit       = isset( $args['posts_per_page'] ) && $args['posts_per_page'] > 0 ? (int) $args['posts_per_page'] : 0;
        $paged           = 1;
        $quarantined_map = (array) get_option( 'sba_quarantined_files', [] );
        $inventory       = [];

        do {
            $chunk_args = [
                'post_type'              => 'attachment',
                'post_status'            => [ 'inherit', 'publish', 'private' ],
                'posts_per_page'         => $batch_size,
                'paged'                  => $paged,
                'orderby'                => 'ID',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
            ];

            $posts = get_posts( $chunk_args );
            if ( empty( $posts ) ) {
                break;
            }

            foreach ( $posts as $att ) {
            $att_id = (int) $att->ID;

            $file_path = get_attached_file( $att_id );
            $meta      = wp_get_attachment_metadata( $att_id );
            $alt_text  = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
            $attached_rel = (string) get_post_meta( $att_id, '_wp_attached_file', true );

            // Determine relative upload path
            if ( ! empty( $attached_rel ) ) {
                $rel_path = str_replace( '\\', '/', $attached_rel );
            } elseif ( $file_path ) {
                $rel_path = str_replace( '\\', '/', _wp_relative_upload_path( $file_path ) );
            } else {
                $rel_path = $att->post_name ?: 'attachment-' . $att_id;
            }
            $rel_path = ltrim( $rel_path, '/' );

            // Validate file existence and readability
            $original_status = 'missing';
            $file_size       = 0;
            $mod_time        = 0;
            $sha256          = '';
            $norm_path       = $file_path ? wp_normalize_path( $file_path ) : '';

            if ( $file_path && file_exists( $file_path ) ) {
                if ( function_exists( 'sba_is_readable' ) ? sba_is_readable( $file_path ) : @is_readable( $file_path ) ) {
                    $original_status = 'exists';
                    $file_size       = (int) @filesize( $file_path );
                    $mod_time        = (int) @filemtime( $file_path );
                    // Compute hash for files <= 25MB to avoid high memory usage
                    if ( $file_size <= 26214400 ) {
                        $sha256 = @hash_file( 'sha256', $file_path ) ?: '';
                    }
                    $this->registered_filepaths[ strtolower( $norm_path ) ] = $att_id;

                    // Also register .sba-orig backup and companion WebP file so scan_unattached_files does not falsely flag them
                    $orig_backup = $file_path . '.sba-orig';
                    if ( file_exists( $orig_backup ) ) {
                        $this->registered_filepaths[ strtolower( wp_normalize_path( $orig_backup ) ) ] = $att_id;
                    }

                    $webp_path = ! empty( $meta['sba_webp']['path'] ) ? $meta['sba_webp']['path'] : preg_replace( '/\.[^.]+$/', '.webp', $file_path );
                    if ( $webp_path !== $file_path && file_exists( $webp_path ) ) {
                        $this->registered_filepaths[ strtolower( wp_normalize_path( $webp_path ) ) ] = $att_id;
                    }
                } else {
                    $original_status = 'unreadable';
                }
            } else {
                $this->discrepancies['missing_originals'][] = [
                    'attachment_id' => $att_id,
                    'title'         => $att->post_title,
                    'relative_path' => $rel_path,
                    'expected_path' => $file_path,
                ];
            }

            // Inspect metadata integrity
            $meta_integrity = 'valid';
            if ( empty( $meta ) || ! is_array( $meta ) ) {
                $meta_integrity = 'missing_meta';
                $this->discrepancies['metadata_issues'][] = [
                    'attachment_id' => $att_id,
                    'issue'         => 'missing_or_corrupt_metadata',
                ];
            } elseif ( empty( $meta['width'] ) && strpos( $att->post_mime_type, 'image/' ) === 0 ) {
                $meta_integrity = 'dimensions_missing';
                $this->discrepancies['metadata_issues'][] = [
                    'attachment_id' => $att_id,
                    'issue'         => 'missing_image_dimensions',
                ];
            }

            // Inspect thumbnails / subsizes
            $subsizes       = [];
            $subsize_count  = 0;
            $subsize_bytes  = 0;
            $file_dir       = $file_path ? dirname( $file_path ) : '';

            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $file_dir ) {
                foreach ( $meta['sizes'] as $size_key => $size_info ) {
                    if ( empty( $size_info['file'] ) ) {
                        continue;
                    }

                    $sub_path   = $file_dir . DIRECTORY_SEPARATOR . $size_info['file'];
                    $norm_sub   = wp_normalize_path( $sub_path );
                    $sub_exists = file_exists( $sub_path );
                    $sub_bytes  = $sub_exists ? (int) @filesize( $sub_path ) : 0;
                    $sub_w      = (int) ( $size_info['width'] ?? 0 );
                    $sub_h      = (int) ( $size_info['height'] ?? 0 );

                    if ( $sub_exists ) {
                        $subsize_count++;
                        $subsize_bytes += $sub_bytes;
                        $this->registered_filepaths[ strtolower( $norm_sub ) ] = $att_id;
                    } else {
                        $this->discrepancies['missing_subsizes'][] = [
                            'attachment_id' => $att_id,
                            'size_key'      => $size_key,
                            'subfile'       => $size_info['file'],
                            'expected_path' => $sub_path,
                        ];
                    }

                    $subsizes[] = [
                        'size_key'            => sanitize_key( $size_key ),
                        'file'                => sanitize_file_name( $size_info['file'] ),
                        'width'               => $sub_w,
                        'height'              => $sub_h,
                        'dimensions'          => ( $sub_w > 0 && $sub_h > 0 ) ? "{$sub_w} × {$sub_h} px" : 'N/A',
                        'file_size_bytes'     => $sub_bytes,
                        'file_size_formatted' => size_format( $sub_bytes, 2 ),
                        'file_exists'         => $sub_exists,
                    ];
                }
            }

            $total_item_bytes = $file_size + $subsize_bytes;
            $is_quarantined   = isset( $quarantined_map[ $att_id ] );

            // Dimensions
            $width  = (int) ( $meta['width'] ?? 0 );
            $height = (int) ( $meta['height'] ?? 0 );
            $dim_str = ( $width > 0 && $height > 0 ) ? "{$width} × {$height} px" : 'N/A';

            $item = [
                'id'                   => $att_id,
                'name'                 => $file_path ? wp_basename( $file_path ) : ( $att->post_name ?: 'attachment-' . $att_id ),
                'title'                => $att->post_title ?: ( $file_path ? wp_basename( $file_path ) : 'attachment-' . $att_id ),
                'relative_path'        => $rel_path,
                'file_path'            => 'wp-content/uploads/' . $rel_path,
                'absolute_path'        => $norm_path,
                'mime_type'            => $att->post_mime_type,
                'file_ext'             => $file_path ? strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) : '',
                'file_size_bytes'      => $file_size,
                'file_size_formatted'  => size_format( $file_size, 2 ),
                'subsize_count'        => $subsize_count,
                'subsize_bytes'        => $subsize_bytes,
                'subsizes'             => $subsizes,
                'total_item_bytes'     => $total_item_bytes,
                'total_size_formatted' => size_format( $total_item_bytes, 2 ),
                'width'                => $width,
                'height'               => $height,
                'dimensions'           => $dim_str,
                'alt_text'             => $alt_text,
                'post_parent'          => (int) $att->post_parent,
                'original_status'      => $original_status,
                'metadata_integrity'   => $meta_integrity,
                'sha256'               => $sha256,
                'upload_date'          => get_the_date( 'Y-m-d', $att ),
                'modified_time'        => $mod_time,
                'is_quarantined'       => $is_quarantined,
            ];

            $this->attachments_inventory[ $att_id ] = $item;
            $inventory[] = $item;
        }

            // Memory hygiene: clean cache of processed posts in this batch
            if ( function_exists( 'clean_post_cache' ) ) {
                foreach ( $posts as $att ) {
                    clean_post_cache( $att->ID );
                }
            }

            // Stop if batch was partially filled or max limit reached
            if ( count( $posts ) < $batch_size || ( $max_limit > 0 && count( $inventory ) >= $max_limit ) ) {
                break;
            }

            $paged++;
        } while ( true );

        return $inventory;
    }

    /**
     * Scan the physical uploads directory to discover unattached (orphaned disk) files.
     *
     * @param int $max_depth Directory traversal depth limit (default 6).
     * @return array Array of unattached disk files.
     */
    public function scan_unattached_files( $max_depth = 6 ) {
        $upload_dir_info = wp_get_upload_dir();
        $basedir         = wp_normalize_path( $upload_dir_info['basedir'] );

        $is_readable = function_exists( 'sba_is_readable' ) ? sba_is_readable( $basedir ) : @is_readable( $basedir );
        if ( ! is_dir( $basedir ) || ! $is_readable ) {
            return [];
        }

        $skip_directories = [
            'sba-quarantine',
            'sba-backups',
            '.tmb',
            'cache',
            'elementor/css',
            'elementor/thumbs',
            'wphb-cache',
            'autoptimize',
        ];

        $unattached = [];
        $total_disk_bytes = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $basedir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );
            $iterator->setMaxDepth( $max_depth );

            foreach ( $iterator as $item ) {
                if ( ! $item->isFile() ) {
                    continue;
                }

                $raw_path  = $item->getPathname();
                $norm_path = wp_normalize_path( $raw_path );

                // Check relative subpath against skipped folders
                $rel_sub = str_replace( $basedir . '/', '', $norm_path );
                $parts   = explode( '/', $rel_sub );

                $skip = false;
                foreach ( $skip_directories as $skip_prefix ) {
                    if ( 0 === strpos( $rel_sub, $skip_prefix ) ) {
                        $skip = true;
                        break;
                    }
                }
                if ( $skip || substr( $norm_path, -9 ) === '.sba-orig' ) {
                    continue;
                }

                $file_bytes = (int) $item->getSize();
                $total_disk_bytes += $file_bytes;

                $path_key = strtolower( $norm_path );
                if ( ! isset( $this->registered_filepaths[ $path_key ] ) ) {
                    $unattached[] = [
                        'filename'            => $item->getFilename(),
                        'relative_path'       => $rel_sub,
                        'file_path'           => 'wp-content/uploads/' . $rel_sub,
                        'absolute_path'       => $norm_path,
                        'file_size_bytes'     => $file_bytes,
                        'file_size_formatted' => size_format( $file_bytes, 2 ),
                        'extension'           => strtolower( $item->getExtension() ),
                        'modified_time'       => $item->getMTime(),
                        'status'              => 'unattached_disk_file',
                    ];
                }
            }
        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SBA Inventory Scanner Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        $this->discrepancies['unattached_files'] = $unattached;
        return $unattached;
    }

    /**
     * Get compiled discrepancies report.
     *
     * @return array Discrepancy report map.
     */
    public function get_discrepancies() {
        return $this->discrepancies;
    }

    /**
     * Get full inventory records.
     *
     * @return array Map of attachment records.
     */
    public function get_inventory() {
        return $this->attachments_inventory;
    }
}

