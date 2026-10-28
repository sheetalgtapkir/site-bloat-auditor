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

        // 1. Group attachments by file size first to avoid hashing files with unique sizes
        $size_groups = [];
        foreach ( $inventory as $item ) {
            $size = (int) ( $item['file_size_bytes'] ?? 0 );
            $id   = (int) ( $item['id'] ?? 0 );
            if ( $size > 0 && $id > 0 ) {
                $size_groups[ $size ][] = $item;
            }
        }

        // 2. Only compute cryptographic SHA-256 for attachments sharing identical file sizes
        $hash_groups = [];
        foreach ( $size_groups as $size => $size_candidates ) {
            if ( count( $size_candidates ) < 2 ) {
                continue; // Cannot be duplicate if file size is strictly unique
            }

            foreach ( $size_candidates as $item ) {
                $sha256    = $item['sha256'] ?? '';
                $id        = (int) ( $item['id'] ?? 0 );
                $file_path = $item['absolute_path'] ?? $item['file_path'] ?? '';

                // If hash was not precomputed and file exists, compute it now
                if ( empty( $sha256 ) && ! empty( $file_path ) && file_exists( $file_path ) ) {
                    if ( $size <= 26214400 ) { // 25 MB safety limit
                        $sha256 = @hash_file( 'sha256', $file_path ) ?: '';
                    }
                }

                if ( ! empty( $sha256 ) ) {
                    $hash_groups[ $sha256 ][] = array_merge( $item, [ 'sha256' => $sha256 ] );
                }
            }
        }

        // 3. Filter to groups that have 2 or more attachments with verified identical hashes
        $duplicate_groups = [];

        foreach ( $hash_groups as $hash => $items ) {
            if ( count( $items ) < 2 ) {
                continue;
            }

            $count               = count( $items );
            $items_with_analysis = [];
            $has_used_item       = false;
            $all_used            = true;
            $keeper_index        = 0;

            // First pass: identify usage references across all items in group
            foreach ( $items as $idx => $item ) {
                $aid        = (int) $item['id'];
                $references = $usage_map[ $aid ]['references'] ?? $item['usage_places'] ?? [];
                $is_used    = ! empty( $references );

                if ( $is_used ) {
                    $has_used_item = true;
                    // Prefer keeping an active referenced copy
                    if ( 0 === $keeper_index && empty( $items[0]['usage_places'] ) && empty( $usage_map[ (int) $items[0]['id'] ]['references'] ) ) {
                        $keeper_index = $idx;
                    }
                } else {
                    $all_used = false;
                }
            }

            // Priority 3: Calculate actual candidate files' measured sizes on disk (never estimate via first file)
            $actual_candidate_savings_bytes = 0;
            foreach ( $items as $idx => $item ) {
                $aid        = (int) $item['id'];
                $references = $usage_map[ $aid ]['references'] ?? $item['usage_places'] ?? [];
                $is_used    = ! empty( $references );
                $is_keeper  = ( $idx === $keeper_index );
                $item_bytes = (int) ( $item['total_item_bytes'] ?? $item['file_size_bytes'] ?? 0 );

                if ( ! $is_keeper ) {
                    $actual_candidate_savings_bytes += $item_bytes;
                }

                $items_with_analysis[] = array_merge( $item, [
                    'references'           => $references,
                    'is_in_use'            => $is_used,
                    'is_keeper'            => $is_keeper,
                    'usage_count'          => count( $references ),
                    'uncertain_usage'      => ( ! $is_used ),
                    'candidate_for_review' => ( ! $is_keeper && ! $is_used ),
                ] );
            }

            // Determine Group Strategy Recommendation (evidence-based, requiring manual review)
            if ( $all_used ) {
                $recommendation = esc_html__( 'All duplicate copies have detected references in scanned content. Re-link active content to a single copy before removing redundant media.', 'site-bloat-auditor' );
                $action_type    = 'relink_required';
            } elseif ( $has_used_item ) {
                $recommendation = esc_html__( 'One copy has detected references, while other copies have no references in scanned content. Manual review required: inspect unreferenced copies before quarantine.', 'site-bloat-auditor' );
                $action_type    = 'review_unreferenced_copies';
            } else {
                $recommendation = esc_html__( 'No detected references found in scanned sources for any copy in this duplicate group. Uncertain usage: manual review required before any cleanup.', 'site-bloat-auditor' );
                $action_type    = 'review_all';
            }

            $sample_file_size = (int) ( $items[0]['total_item_bytes'] ?? $items[0]['file_size_bytes'] ?? 0 );

            $duplicate_groups[] = [
                'hash'                        => $hash,
                'count'                       => $count,
                'keeper_id'                   => (int) $items[ $keeper_index ]['id'],
                'sample_size_bytes'           => $sample_file_size,
                'sample_size_formatted'       => size_format( $sample_file_size, 2 ),
                'potential_savings_bytes'     => $actual_candidate_savings_bytes,
                'potential_savings_formatted' => size_format( $actual_candidate_savings_bytes, 2 ),
                'recommendation'              => $recommendation,
                'action_type'                 => $action_type,
                'requires_manual_review'      => true,
                'items'                       => $items_with_analysis,
            ];
        }

        // Sort duplicate groups by potential storage savings descending
        usort( $duplicate_groups, function( $a, $b ) {
            return $b['potential_savings_bytes'] <=> $a['potential_savings_bytes'];
        } );

        return $duplicate_groups;
    }
}

