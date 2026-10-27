<?php
/**
 * Duplicate Media Detection Engine for Site Bloat & Storage Auditor.
 *
 * Discovers exact duplicate files across the WordPress Media Library using
 * SHA-256 cryptographic hashing and usage comparison.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Duplicate_Detector {

    /**
     * Find duplicate media groups from an inventory of attachments.
     *
     * @param array $inventory Array of attachment items (from SBA_Media_Inventory).
     * @param array $usage_map Optional map of attachment ID to usage references.
     * @return array Array of duplicate groups.
     */
    public function detect_duplicates( array $inventory, array $usage_map = [] ) {
        $hash_groups = [];

        // 1. Group attachments by SHA-256 hash
        foreach ( $inventory as $item ) {
            $sha256 = $item['sha256'] ?? '';
            $id     = (int) ( $item['id'] ?? 0 );

            // If hash was not precomputed and file exists, compute it now
            if ( empty( $sha256 ) && ! empty( $item['absolute_path'] ) && file_exists( $item['absolute_path'] ) ) {
                $file_bytes = (int) ( $item['file_size_bytes'] ?? 0 );
                if ( $file_bytes <= 26214400 ) { // 25 MB max limit
                    $sha256 = @hash_file( 'sha256', $item['absolute_path'] ) ?: '';
                }
            }

            if ( ! empty( $sha256 ) && ! empty( $id ) ) {
                $hash_groups[ $sha256 ][] = $item;
            }
        }

        // 2. Filter to groups that have 2 or more attachments
        $duplicate_groups = [];

        foreach ( $hash_groups as $hash => $items ) {
            if ( count( $items ) < 2 ) {
                continue;
            }

            $count            = count( $items );
            $sample_file_size = (int) ( $items[0]['total_item_bytes'] ?? $items[0]['file_size_bytes'] ?? 0 );
            $potential_savings = ( $count - 1 ) * $sample_file_size;

            $items_with_analysis = [];
            $has_used_item       = false;
            $all_used            = true;

            foreach ( $items as $item ) {
                $aid        = (int) $item['id'];
                $references = $usage_map[ $aid ]['references'] ?? $item['usage_places'] ?? [];
                $is_used    = ! empty( $references );

                if ( $is_used ) {
                    $has_used_item = true;
                } else {
                    $all_used = false;
                }

                $items_with_analysis[] = array_merge( $item, [
                    'references'   => $references,
                    'is_in_use'    => $is_used,
                    'usage_count'  => count( $references ),
                ] );
            }

            // Determine Group Strategy Recommendation
            if ( $all_used ) {
                $recommendation = esc_html__( 'All duplicates are actively referenced in content. Re-link posts to a single copy before deleting redundant media.', 'site-bloat-auditor' );
                $action_type    = 'relink_required';
            } elseif ( $has_used_item ) {
                $recommendation = esc_html__( 'One or more copies are active, and others have zero detected references. Unused copies can be safely reviewed for quarantine.', 'site-bloat-auditor' );
                $action_type    = 'safe_to_quarantine_unused';
            } else {
                $recommendation = esc_html__( 'No active references detected for any copy in this duplicate group. Review all before cleaning.', 'site-bloat-auditor' );
                $action_type    = 'review_all';
            }

            $duplicate_groups[] = [
                'hash'                      => $hash,
                'count'                     => $count,
                'sample_size_bytes'         => $sample_file_size,
                'sample_size_formatted'     => size_format( $sample_file_size, 2 ),
                'potential_savings_bytes'   => $potential_savings,
                'potential_savings_formatted' => size_format( $potential_savings, 2 ),
                'recommendation'            => $recommendation,
                'action_type'               => $action_type,
                'items'                     => $items_with_analysis,
            ];
        }

        // Sort duplicate groups by potential storage savings descending
        usort( $duplicate_groups, function( $a, $b ) {
            return $b['potential_savings_bytes'] <=> $a['potential_savings_bytes'];
        } );

        return $duplicate_groups;
    }
}

