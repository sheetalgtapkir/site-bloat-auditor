<?php
/**
 * Media Accessibility & Metadata Quality Auditor for Site Bloat & Storage Auditor.
 *
 * Audits alt text attributes, descriptive filenames, missing sub-sizes, and
 * broken filesystem relationships according to WCAG 2.1 guidelines.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Accessibility_Auditor {

    /**
     * Regex pattern for generic, undescriptive filenames.
     */
    const GENERIC_FILENAME_PATTERN = '/^(img|image|dsc|screenshot|scan|photo|pic|picture|untitled|banner|file|upload|asset|media)[_-]?[0-9]*$/i';

    /**
     * Unhelpful alt text phrases.
     */
    private $unhelpful_alt_phrases = [
        'image',
        'picture',
        'photo',
        'screenshot',
        'banner',
        'untitled',
        'graphic',
        'icon',
    ];

    /**
     * Audit an inventory of attachments for accessibility and metadata hygiene.
     *
     * @param array $inventory Array of attachment items from SBA_Media_Inventory.
     * @return array Compiled accessibility findings.
     */
    public function audit_accessibility( array $inventory ) {
        $findings = [
            'summary' => [
                'total_images_audited'      => 0,
                'with_descriptive_alt'      => 0,
                'missing_alt_attribute'     => 0,
                'empty_alt_decorative'      => 0,
                'unhelpful_repetitive_alt'  => 0,
                'undescriptive_filenames'   => 0,
                'broken_files'              => 0,
                'accessibility_score'       => 100,
            ],
            'items' => [],
        ];

        foreach ( $inventory as $item ) {
            $mime = $item['mime_type'] ?? '';
            if ( 0 !== strpos( $mime, 'image/' ) ) {
                continue; // Focus accessibility audit on images
            }

            $findings['summary']['total_images_audited']++;

            $att_id   = (int) $item['id'];
            $alt_text = trim( (string) ( $item['alt_text'] ?? '' ) );
            $filename = (string) ( $item['name'] ?? '' );
            $raw_name = pathinfo( $filename, PATHINFO_FILENAME );

            $issues      = [];
            $suggestions = [];

            // 1. Check for physical file existence
            if ( 'missing' === ( $item['original_status'] ?? '' ) ) {
                $issues[] = 'broken_file';
                $suggestions[] = esc_html__( 'Original image file is missing from uploads directory.', 'site-bloat-auditor' );
                $findings['summary']['broken_files']++;
            }

            // 2. Check Alt Text status
            $alt_exists_in_meta = metadata_exists( 'post', $att_id, '_wp_attachment_image_alt' );

            if ( ! $alt_exists_in_meta ) {
                $issues[] = 'missing_alt_attr';
                $suggestions[] = esc_html__( 'No alt text attribute is defined. Add concise descriptive text describing the image content.', 'site-bloat-auditor' );
                $findings['summary']['missing_alt_attribute']++;
            } elseif ( '' === $alt_text ) {
                // Empty string: WCAG allows empty alt="" for purely decorative images
                $issues[] = 'empty_alt';
                $suggestions[] = esc_html__( 'Alt text is currently empty. Valid if image is decorative; if informative, add descriptive alt text.', 'site-bloat-auditor' );
                $findings['summary']['empty_alt_decorative']++;
            } else {
                // Check if alt text is repetitive or unhelpful
                $lower_alt = strtolower( $alt_text );
                $is_unhelpful = false;

                // A. Alt text matches raw filename (e.g. "IMG_1234" or "photo-banner")
                if ( $lower_alt === strtolower( $raw_name ) || $lower_alt === strtolower( $filename ) ) {
                    $is_unhelpful = true;
                    $suggestions[] = esc_html__( 'Alt text is identical to the filename. Screen readers need descriptive human-readable context.', 'site-bloat-auditor' );
                }

                // B. Generic words alone
                if ( in_array( $lower_alt, $this->unhelpful_alt_phrases, true ) ) {
                    $is_unhelpful = true;
                    $suggestions[] = esc_html__( 'Alt text contains only a generic placeholder word (e.g. "image", "photo"). Describe what is actually depicted.', 'site-bloat-auditor' );
                }

                if ( $is_unhelpful ) {
                    $issues[] = 'unhelpful_alt';
                    $findings['summary']['unhelpful_repetitive_alt']++;
                } else {
                    $findings['summary']['with_descriptive_alt']++;
                }
            }

            // 3. Check for Undescriptive Filenames
            if ( preg_match( self::GENERIC_FILENAME_PATTERN, $raw_name ) ) {
                $issues[] = 'undescriptive_filename';
                $suggestions[] = esc_html__( 'Filename is generic (e.g. "IMG_001" or "Screenshot"). Descriptive filenames improve SEO and image discoverability.', 'site-bloat-auditor' );
                $findings['summary']['undescriptive_filenames']++;
            }

            // If any accessibility or metadata issue found, record item
            if ( ! empty( $issues ) ) {
                $findings['items'][] = [
                    'id'            => $att_id,
                    'name'          => $filename,
                    'title'         => $item['title'] ?? $filename,
                    'file_path'     => $item['file_path'] ?? '',
                    'relative_path' => $item['relative_path'] ?? '',
                    'dimensions'    => $item['dimensions'] ?? 'N/A',
                    'file_size'     => $item['file_size_formatted'] ?? '0 B',
                    'alt_text'      => $alt_text,
                    'issues'        => $issues,
                    'suggestions'   => $suggestions,
                    'edit_url'      => admin_url( 'post.php?post=' . $att_id . '&action=edit' ),
                ];
            }
        }

        // Calculate score
        $total = $findings['summary']['total_images_audited'];
        if ( $total > 0 ) {
            $deductions = ( $findings['summary']['missing_alt_attribute'] * 1.5 )
                        + ( $findings['summary']['unhelpful_repetitive_alt'] * 1.0 )
                        + ( $findings['summary']['broken_files'] * 3.0 );
            $score = max( 0, min( 100, round( 100 - ( $deductions / $total * 100 ) ) ) );
            $findings['summary']['accessibility_score'] = (int) $score;
        }

        return $findings;
    }
}

