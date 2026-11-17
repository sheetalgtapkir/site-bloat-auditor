<?php
/**
 * Evidence-based media usage detection engine for Site Bloat & Storage Auditor.
 *
 * Scans Gutenberg blocks, classic post content, post metadata, featured images,
 * WooCommerce galleries, Elementor JSON structures, widgets, and theme settings.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Usage_Detector {

    /**
     * Map of attachment ID to usage references.
     *
     * @var array
     */
    private $usage_map = [];

    /**
     * Scanned post cache.
     *
     * @var array
     */
    private $content_posts = [];

    /**
     * Scan references for a list of attachment items.
     *
     * @param array $attachments Array of attachment post objects or records.
     * @return array Map of [ attachment_id => [ 'classification' => ..., 'confidence' => ..., 'references' => [...] ] ]
     */
    public function scan_references( array $attachments ) {
        global $wpdb;

        if ( empty( $attachments ) ) {
            return [];
        }

        $attachment_ids = [];
        $attachment_map = [];
        foreach ( $attachments as $att ) {
            $id = is_object( $att ) ? (int) $att->ID : (int) ( $att['id'] ?? $att['ID'] );
            if ( $id > 0 ) {
                $attachment_ids[]   = $id;
                $attachment_map[ $id ] = $att;
                $this->usage_map[ $id ] = [];
            }
        }

        // 1. Scan Featured Images (_thumbnail_id)
        $this->scan_featured_images( $attachment_ids );

        // 2. Scan WooCommerce Product Galleries (_product_image_gallery)
        $this->scan_woocommerce_galleries( $attachment_ids );

        // 3. Scan Theme Customizer & Site Identity
        $this->scan_theme_and_site_identity( $attachment_ids );

        // 4. Scan Post Content (Gutenberg blocks, HTML images, shortcodes)
        $this->scan_post_contents( $attachment_ids, $attachment_map );

        // 5. Scan Elementor and Page Builder metadata
        $this->scan_page_builders( $attachment_ids );

        // 6. Scan Post Meta custom fields (e.g. ACF image fields), incl. serialized arrays (D10)
        $this->scan_custom_fields( $attachment_ids );
        $this->scan_serialized_custom_fields( $attachment_ids );

        // 7. Scan Widgets & Sidebars
        $this->scan_widgets( $attachment_ids );

        // 8. Parent post associations are NOT usage evidence (D5): "uploaded to" records where a
        // file was first uploaded, not where it is displayed (the former "Attached Parent"
        // fallback marked such files as likely in use).

        // 9. Scan Universal Image Reference Index (covering generated thumbnails, HTML attributes, CSS, page builders, and rendered output)
        $this->scan_universal_reference_index( $attachment_ids, $attachment_map );

        // Compile classifications for each attachment
        $results = [];
        foreach ( $attachment_ids as $aid ) {
            $refs = $this->usage_map[ $aid ] ?? [];
            $results[ $aid ] = $this->classify_attachment_usage( $aid, $refs, $attachment_map[ $aid ] ?? null );
        }

        return $results;
    }

    /**
     * Classify attachment based on gathered evidence.
     */
    private function classify_attachment_usage( $attachment_id, array $references, $att_data ) {
        if ( empty( $references ) ) {
            return [
                'classification' => 'no_references_found',
                'label'          => esc_html__( 'No References Found (Review Required)', 'site-bloat-auditor' ),
                'confidence'     => 'review_required',
                'badge_class'    => 'sba-pill-orphaned',
                'references'     => [],
                'warning'        => esc_html__( 'No references detected in scanned content, theme, widgets, or builders. External CSS, JS, CDNs, or custom code may still reference this file.', 'site-bloat-auditor' ),
            ];
        }

        // Check for confirmed references (high confidence)
        $has_confirmed = false;
        $has_likely    = false;
        $draft_only    = true;

        foreach ( $references as $ref ) {
            if ( 'high' === ( $ref['confidence'] ?? '' ) ) {
                $has_confirmed = true;
            }
            if ( 'medium' === ( $ref['confidence'] ?? '' ) ) {
                $has_likely = true;
            }
            if ( ! in_array( $ref['post_status'] ?? 'publish', [ 'draft', 'trash', 'inherit' ], true ) ) {
                $draft_only = false;
            }
        }

        if ( $draft_only && ! empty( $references ) ) {
            return [
                'classification' => 'requires_manual_review',
                'label'          => esc_html__( 'Draft / Pending Only', 'site-bloat-auditor' ),
                'confidence'     => 'low',
                'badge_class'    => 'sba-pill-warning',
                'references'     => $references,
                'warning'        => esc_html__( 'Referenced only in drafts, revisions, or trashed posts. Verify before deleting.', 'site-bloat-auditor' ),
            ];
        }

        // Only weak evidence (e.g. a number in an unrecognised custom field that happens to
        // equal the attachment ID, D7): never "Actively In Use", never "safe to delete".
        if ( ! $has_confirmed && ! $has_likely ) {
            return [
                'classification' => 'requires_manual_review',
                'label'          => esc_html__( 'Unverified Reference — Review', 'site-bloat-auditor' ),
                'confidence'     => 'low',
                'badge_class'    => 'sba-pill-warning',
                'references'     => $references,
                'warning'        => esc_html__( 'Only unverified evidence was found (for example a custom field value equal to this file\'s ID). Verify before deleting.', 'site-bloat-auditor' ),
            ];
        }

        if ( $has_confirmed ) {
            return [
                'classification' => 'confirmed_referenced',
                'label'          => esc_html__( 'Actively In Use', 'site-bloat-auditor' ),
                'confidence'     => 'high',
                'badge_class'    => 'sba-pill-active',
                'references'     => $references,
                'warning'        => '',
            ];
        }

        return [
            'classification' => 'likely_referenced',
            'label'          => esc_html__( 'Likely Referenced', 'site-bloat-auditor' ),
            'confidence'     => 'medium',
            'badge_class'    => 'sba-pill-active',
            'references'     => $references,
            'warning'        => esc_html__( 'Matched via filename or URL in published content without explicit post ID.', 'site-bloat-auditor' ),
        ];
    }

    /**
     * 1. Featured image usage.
     */
    private function scan_featured_images( array $attachment_ids ) {
        global $wpdb;
        if ( empty( $attachment_ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
        $posts = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.meta_value AS attachment_id, p.ID AS post_id, p.post_title, p.post_type, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_key = '_thumbnail_id'
              AND m.meta_value IN ($placeholders)
              AND p.post_status != 'trash'
        ", ...$attachment_ids ) );

        foreach ( $posts as $p ) {
            $aid = (int) $p->attachment_id;
            $title = ! empty( $p->post_title ) ? $p->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $p->post_id );
            $this->usage_map[ $aid ][] = [
                'source_type'      => 'post',
                'source_id'        => (int) $p->post_id,
                'source_title'     => sanitize_text_field( $title ),
                'detection_method' => 'meta_thumbnail_id',
                'usage_type'       => 'Featured',
                'confidence'       => 'high',
                'post_status'      => sanitize_key( $p->post_status ),
                'evidence'         => sprintf( esc_html__( 'Set as Featured Image for %1$s "%2$s" (ID #%3$d)', 'site-bloat-auditor' ), $p->post_type, $title, (int) $p->post_id ),
            ];
        }
    }

    /**
     * 2. WooCommerce product galleries.
     */
    private function scan_woocommerce_galleries( array $attachment_ids ) {
        global $wpdb;
        $posts = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.meta_value, p.ID AS post_id, p.post_title, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_key = %s AND m.meta_value != %s AND p.post_status != %s
        ", '_product_image_gallery', '', 'trash' ) );

        $lookup = array_flip( $attachment_ids );
        foreach ( $posts as $p ) {
            $gallery_ids = array_filter( array_map( 'intval', explode( ',', (string) $p->meta_value ) ) );
            $title       = ! empty( $p->post_title ) ? $p->post_title : sprintf( esc_html__( 'Product #%d', 'site-bloat-auditor' ), (int) $p->post_id );

            foreach ( $gallery_ids as $gid ) {
                if ( isset( $lookup[ $gid ] ) ) {
                    $this->usage_map[ $gid ][] = [
                        'source_type'      => 'product',
                        'source_id'        => (int) $p->post_id,
                        'source_title'     => sanitize_text_field( $title ),
                        'detection_method' => 'meta_product_gallery',
                        'usage_type'       => 'Product Gallery',
                        'confidence'       => 'high',
                        'post_status'      => sanitize_key( $p->post_status ),
                        'evidence'         => sprintf( esc_html__( 'Included in WooCommerce Product Image Gallery for "%1$s" (ID #%2$d)', 'site-bloat-auditor' ), $title, (int) $p->post_id ),
                    ];
                }
            }
        }
    }

    /**
     * 3. Theme customizer, site icon, custom logo.
     */
    private function scan_theme_and_site_identity( array $attachment_ids ) {
        $site_icon_id   = (int) get_option( 'site_icon' );
        $custom_logo_id = (int) get_theme_mod( 'custom_logo' );
        $header_img_id  = (int) get_theme_mod( 'header_image_data', 0 );

        foreach ( $attachment_ids as $aid ) {
            if ( $aid === $site_icon_id ) {
                $this->usage_map[ $aid ][] = [
                    'source_type'      => 'theme_option',
                    'source_id'        => 0,
                    'source_title'     => esc_html__( 'Site Identity (Favicon)', 'site-bloat-auditor' ),
                    'detection_method' => 'option_site_icon',
                    'usage_type'       => 'Site Icon',
                    'confidence'       => 'high',
                    'post_status'      => 'publish',
                    'evidence'         => esc_html__( 'Configured as WordPress Site Icon / Browser Favicon', 'site-bloat-auditor' ),
                ];
            }

            if ( $aid === $custom_logo_id ) {
                $this->usage_map[ $aid ][] = [
                    'source_type'      => 'theme_mod',
                    'source_id'        => 0,
                    'source_title'     => esc_html__( 'Active Theme Custom Logo', 'site-bloat-auditor' ),
                    'detection_method' => 'theme_mod_custom_logo',
                    'usage_type'       => 'Logo',
                    'confidence'       => 'high',
                    'post_status'      => 'publish',
                    'evidence'         => esc_html__( 'Configured as Primary Theme Header Logo', 'site-bloat-auditor' ),
                ];
            }
        }
    }

    /**
     * 4. Post content: Gutenberg blocks, HTML img, shortcodes.
     */
    private function scan_post_contents( array $attachment_ids, array $attachment_map ) {
        global $wpdb;

        if ( empty( $this->content_posts ) ) {
            // Trashed content is scanned too (D6): a reference that exists only in the trash is
            // weak evidence ("review"), never proof that the file is unused.
            $this->content_posts = $wpdb->get_results( $wpdb->prepare( "
                SELECT ID, post_title, post_content, post_type, post_status
                FROM {$wpdb->posts}
                WHERE post_type NOT IN (%s, %s)
                  AND post_status IN (%s, %s, %s, %s, %s)
                  AND post_content != %s
            ", 'attachment', 'revision', 'publish', 'future', 'private', 'draft', 'trash', '' ) );
        }

        $lookup = array_flip( $attachment_ids );

        // Fallback string check (D5): match the attachment's real FILE NAME with its extension
        // (original, "-scaled" or a generated "-WxH" size), bounded so that "team" does not
        // match "/our-team/" and "gold-loan.jpg" does not match "gold-loans.jpg". The old
        // check searched for the attachment slug as a plain substring.
        $filenames = [];
        foreach ( $attachment_ids as $aid ) {
            $pattern = self::file_name_pattern( (string) get_post_meta( $aid, '_wp_attached_file', true ) );
            if ( '' !== $pattern ) {
                $filenames[ $aid ] = $pattern;
            }
        }

        foreach ( $this->content_posts as $cp ) {
            $post_title = ! empty( $cp->post_title ) ? $cp->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $cp->ID );

            // A. Explicit Gutenberg block attribute match: <!-- wp:image {"id":123 ...}
            if ( preg_match_all( '/<!--\s*wp:(?:image|cover|media-text|file|audio|video)\s+({.*?})\s*-->/s', $cp->post_content, $block_matches ) ) {
                foreach ( $block_matches[1] as $json_str ) {
                    $parsed = json_decode( $json_str, true );
                    $bid    = (int) ( $parsed['id'] ?? ( $parsed['mediaId'] ?? 0 ) );
                    if ( $bid > 0 && isset( $lookup[ $bid ] ) ) {
                        $this->usage_map[ $bid ][] = [
                            'source_type'      => sanitize_key( $cp->post_type ),
                            'source_id'        => (int) $cp->ID,
                            'source_title'     => sanitize_text_field( $post_title ),
                            'detection_method' => 'gutenberg_block',
                            'usage_type'       => 'Content',
                            'confidence'       => 'high',
                            'post_status'      => sanitize_key( $cp->post_status ),
                            'evidence'         => sprintf( esc_html__( 'Gutenberg block in %1$s "%2$s"', 'site-bloat-auditor' ), $cp->post_type, $post_title ),
                        ];
                    }
                }
            }

            // B. Classic content class match: wp-image-{id}
            if ( preg_match_all( '/wp-image-(\d+)/', $cp->post_content, $id_matches ) ) {
                $found_ids = array_unique( array_map( 'intval', $id_matches[1] ) );
                foreach ( $found_ids as $fid ) {
                    if ( isset( $lookup[ $fid ] ) ) {
                        $this->usage_map[ $fid ][] = [
                            'source_type'      => sanitize_key( $cp->post_type ),
                            'source_id'        => (int) $cp->ID,
                            'source_title'     => sanitize_text_field( $post_title ),
                            'detection_method' => 'content_wp_image_class',
                            'usage_type'       => 'Content',
                            'confidence'       => 'high',
                            'post_status'      => sanitize_key( $cp->post_status ),
                            'evidence'         => sprintf( esc_html__( 'Embedded image in %1$s "%2$s" via wp-image-%3$d', 'site-bloat-auditor' ), $cp->post_type, $post_title, $fid ),
                        ];
                    }
                }
            }

            // C. Fallback: Filename check if no explicit ID match was found
            foreach ( $filenames as $aid => $fn_pattern ) {
                if ( empty( $this->usage_map[ $aid ] ) && preg_match( $fn_pattern, $cp->post_content, $fn_match ) ) {
                    $fn = $fn_match[0];
                    $this->usage_map[ $aid ][] = [
                        'source_type'      => sanitize_key( $cp->post_type ),
                        'source_id'        => (int) $cp->ID,
                        'source_title'     => sanitize_text_field( $post_title ),
                        'detection_method' => 'content_filename_match',
                        'usage_type'       => 'Content',
                        'confidence'       => 'medium',
                        'post_status'      => sanitize_key( $cp->post_status ),
                        'evidence'         => sprintf( esc_html__( 'Filename "%1$s" found in %2$s "%3$s"', 'site-bloat-auditor' ), $fn, $cp->post_type, $post_title ),
                    ];
                }
            }
        }
    }

    /**
     * 5. Elementor & page builder metadata.
     */
    private function scan_page_builders( array $attachment_ids ) {
        global $wpdb;
        $elementor_meta = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.post_id, m.meta_value, p.post_title, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_key = %s AND p.post_status != %s AND p.post_type != %s
        ", '_elementor_data', 'trash', 'revision' ) ); // D5: Elementor revisions hold replaced layouts, not live usage.

        $lookup = array_flip( $attachment_ids );
        foreach ( $elementor_meta as $em ) {
            $title = ! empty( $em->post_title ) ? $em->post_title : sprintf( esc_html__( 'Page #%d', 'site-bloat-auditor' ), (int) $em->post_id );
            if ( preg_match_all( '/"id":\s*(\d+)/', (string) $em->meta_value, $matches ) ) {
                $matched_ids = array_unique( array_map( 'intval', $matches[1] ) );
                foreach ( $matched_ids as $mid ) {
                    if ( isset( $lookup[ $mid ] ) ) {
                        $this->usage_map[ $mid ][] = [
                            'source_type'      => 'elementor_page',
                            'source_id'        => (int) $em->post_id,
                            'source_title'     => sanitize_text_field( $title ),
                            'detection_method' => 'elementor_json_data',
                            'usage_type'       => 'Page Builder',
                            'confidence'       => 'high',
                            'post_status'      => sanitize_key( $em->post_status ),
                            'evidence'         => sprintf( esc_html__( 'Used in Elementor layout for "%1$s" (ID #%2$d)', 'site-bloat-auditor' ), $title, (int) $em->post_id ),
                        ];
                    }
                }
            }
        }
    }

    /**
     * 6. Custom fields & ACF image fields.
     */
    private function scan_custom_fields( array $attachment_ids ) {
        global $wpdb;
        if ( empty( $attachment_ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
        $meta_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.meta_key, m.meta_value, p.ID AS post_id, p.post_title, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_value IN ($placeholders)
              AND m.meta_key NOT IN ('_thumbnail_id', '_sba_quarantined')
              AND p.post_type NOT IN ('attachment', 'revision')
              AND p.post_status != 'trash'
            LIMIT 500
        ", ...$attachment_ids ) );

        foreach ( $meta_rows as $row ) {
            $aid   = (int) $row->meta_value;
            $title = ! empty( $row->post_title ) ? $row->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $row->post_id );

            // D7: a bare number equal to an attachment ID is proof of usage only when the field
            // is an image/media field — an ACF field (companion "_key" = "field_…") or a key
            // naming an image, file or media. Otherwise (e.g. a rating or power value) it is
            // recorded as unverified, low-confidence evidence.
            $is_acf      = 0 === strpos( (string) get_post_meta( (int) $row->post_id, '_' . $row->meta_key, true ), 'field_' );
            $is_media    = $is_acf || self::is_media_meta_key( (string) $row->meta_key );
            $this->usage_map[ $aid ][] = [
                'source_type'      => 'custom_field',
                'source_id'        => (int) $row->post_id,
                'source_title'     => sanitize_text_field( $title ),
                'detection_method' => 'postmeta_' . sanitize_key( $row->meta_key ),
                'usage_type'       => 'Custom Field',
                'confidence'       => $is_media ? 'high' : 'low',
                'post_status'      => sanitize_key( $row->post_status ),
                'evidence'         => $is_media
                    ? sprintf( esc_html__( 'Custom field "%1$s" on post "%2$s" (ID #%3$d)', 'site-bloat-auditor' ), $row->meta_key, $title, (int) $row->post_id )
                    : sprintf( esc_html__( 'Unverified: custom field "%1$s" on post "%2$s" (ID #%3$d) holds a number equal to this file\'s ID, but is not an image or media field', 'site-bloat-auditor' ), $row->meta_key, $title, (int) $row->post_id ),
            ];
        }
    }

    /**
     * 6b. Attachment IDs inside serialized custom fields (D10), e.g. an ACF gallery
     * (a:2:{i:0;s:3:"123";i:1;s:3:"124";} with companion "_key" = "field_…") or a plugin
     * setting array ['banner_image_id' => 123]. An ID counts only when it sits under an
     * image/media key, or the whole field is an ACF field or a media-named field; numbers
     * under unrelated keys are ignored.
     *
     * @param array $attachment_ids Attachment IDs being scanned.
     */
    private function scan_serialized_custom_fields( array $attachment_ids ) {
        global $wpdb;
        if ( empty( $attachment_ids ) ) {
            return;
        }
        $lookup = array_flip( $attachment_ids );
        $rows   = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.meta_key, m.meta_value, p.ID AS post_id, p.post_title, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_value LIKE %s
              AND m.meta_key NOT LIKE %s
              AND p.post_type NOT IN ('attachment', 'revision')
              AND p.post_status != 'trash'
            LIMIT 2000
        ", 'a:%', $wpdb->esc_like( '_wp_' ) . '%' ) );

        foreach ( $rows as $row ) {
            $data = is_serialized( $row->meta_value ) ? @unserialize( $row->meta_value, [ 'allowed_classes' => false ] ) : false;
            if ( ! is_array( $data ) ) {
                continue;
            }
            $field_is_media = self::is_media_meta_key( (string) $row->meta_key )
                || 0 === strpos( (string) get_post_meta( (int) $row->post_id, '_' . $row->meta_key, true ), 'field_' );
            $found = [];
            $walk  = function ( $node, $in_media ) use ( &$walk, &$found, $lookup ) {
                foreach ( $node as $k => $v ) {
                    $media_here = $in_media || ( is_string( $k ) && self::is_media_meta_key( $k ) );
                    if ( is_array( $v ) ) {
                        $walk( $v, $media_here );
                    } elseif ( $media_here && ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^\d+$/', $v ) ) ) && isset( $lookup[ (int) $v ] ) ) {
                        $found[ (int) $v ] = true;
                    }
                }
            };
            $walk( $data, $field_is_media );

            $title = ! empty( $row->post_title ) ? $row->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $row->post_id );
            foreach ( array_keys( $found ) as $aid ) {
                $this->usage_map[ $aid ][] = [
                    'source_type'      => 'custom_field',
                    'source_id'        => (int) $row->post_id,
                    'source_title'     => sanitize_text_field( $title ),
                    'detection_method' => 'postmeta_' . sanitize_key( $row->meta_key ),
                    'usage_type'       => 'Custom Field',
                    'confidence'       => 'high',
                    'post_status'      => sanitize_key( $row->post_status ),
                    'evidence'         => sprintf( esc_html__( 'Attachment ID inside serialized custom field "%1$s" on post "%2$s" (ID #%3$d)', 'site-bloat-auditor' ), $row->meta_key, $title, (int) $row->post_id ),
                ];
            }
        }
    }

    /**
     * Whether a meta key names an image / media / file field (D7).
     *
     * Splits the key into words (snake_case, kebab-case, camelCase) and looks for a media
     * word, e.g. "hero_image", "_myplugin_banner_image_id", "logoId", "_tsh_datasheet".
     *
     * @param string $key Meta key.
     * @return bool
     */
    public static function is_media_meta_key( $key ) {
        $words = preg_split( '/[^a-z0-9]+/', strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', (string) $key ) ), -1, PREG_SPLIT_NO_EMPTY );
        $media = [ 'image', 'images', 'img', 'imgs', 'photo', 'photos', 'picture', 'pictures', 'pic', 'logo', 'logos', 'icon', 'icons', 'thumb', 'thumbs', 'thumbnail', 'thumbnails', 'banner', 'banners', 'background', 'bg', 'hero', 'cover', 'poster', 'avatar', 'gallery', 'galleries', 'slide', 'slides', 'slider', 'media', 'attachment', 'attachments', 'file', 'files', 'pdf', 'document', 'documents', 'doc', 'datasheet', 'datasheets', 'brochure', 'download', 'downloads', 'audio', 'video', 'videos' ];
        return (bool) array_intersect( $words, $media );
    }

    /**
     * Regex matching an attachment's file name in text (D5): the original, "-scaled" or a
     * generated "-{W}x{H}" size, with the same extension, not part of a longer file name.
     *
     * @param string $attached_file _wp_attached_file value (e.g. "2026/11/gold-loan.jpg").
     * @return string Regex, or '' when there is no usable file name.
     */
    public static function file_name_pattern( $attached_file ) {
        $base = wp_basename( (string) $attached_file );
        $ext  = strtolower( pathinfo( $base, PATHINFO_EXTENSION ) );
        $name = pathinfo( $base, PATHINFO_FILENAME );
        $name = preg_replace( '/-scaled$/i', '', $name );
        if ( '' === $ext || '' === $name ) {
            return '';
        }
        return '/(?<![A-Za-z0-9_.-])' . preg_quote( $name, '/' ) . '(?:-scaled|-\d+x\d+)?\.' . preg_quote( $ext, '/' ) . '(?![A-Za-z0-9_])/i';
    }

    /**
     * 7. Widgets in wp_options.
     */
    private function scan_widgets( array $attachment_ids ) {
        global $wpdb;
        $widgets = $wpdb->get_results( $wpdb->prepare( "
            SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE %s
        ", $wpdb->esc_like( 'widget_' ) . '%' ) );

        $lookup = array_flip( $attachment_ids );
        foreach ( $widgets as $w ) {
            $val = maybe_unserialize( $w->option_value );
            if ( is_array( $val ) ) {
                $str = wp_json_encode( $val );
                if ( preg_match_all( '/"attachment_id":\s*(\d+)/', (string) $str, $matches ) ) {
                    foreach ( array_map( 'intval', $matches[1] ) as $mid ) {
                        if ( isset( $lookup[ $mid ] ) ) {
                            $this->usage_map[ $mid ][] = [
                                'source_type'      => 'widget',
                                'source_id'        => 0,
                                'source_title'     => sanitize_text_field( $w->option_name ),
                                'detection_method' => 'widget_settings',
                                'usage_type'       => 'Widget',
                                'confidence'       => 'high',
                                'post_status'      => 'publish',
                                'evidence'         => sprintf( esc_html__( 'Configured inside widget "%s"', 'site-bloat-auditor' ), $w->option_name ),
                            ];
                        }
                    }
                }
            }
        }
    }

    /**
     * 9. Scan Universal Image Reference Index (covering generated thumbnails, HTML attributes, CSS, page builders, and rendered output).
     */
    private function scan_universal_reference_index( array $attachment_ids, array $attachment_map ) {
        if ( ! class_exists( 'SBA_Image_Reference_Index' ) ) {
            return;
        }

        $ref_engine = SBA_Image_Reference_Index::get_instance();

        foreach ( $attachment_ids as $aid ) {
            $meta      = wp_get_attachment_metadata( $aid );
            $file_path = get_attached_file( $aid );
            $orig_name = $file_path ? wp_basename( $file_path ) : '';
            $orig_rel  = (string) get_post_meta( $aid, '_wp_attached_file', true );

            // A. Check original file references
            if ( ! empty( $orig_name ) ) {
                $orig_url = wp_get_attachment_url( $aid ) ?: '';
                $check = $ref_engine->check_thumbnail_usage( $orig_name, $orig_rel, $orig_url, $aid, 'full' );
                if ( $check['found'] ) {
                    foreach ( $check['references'] as $ref ) {
                        $this->usage_map[ $aid ][] = [
                            'source_type'      => sanitize_key( $ref['source_type'] ?? 'post' ),
                            'source_id'        => (int) ( $ref['source_id'] ?? 0 ),
                            'source_title'     => sanitize_text_field( $ref['source_title'] ?? '' ),
                            'detection_method' => sanitize_text_field( $ref['detection_method'] ?? 'universal_reference' ),
                            'usage_type'       => 'Content',
                            'confidence'       => 'high',
                            'post_status'      => sanitize_key( $ref['source_status'] ?? 'publish' ),
                            'evidence'         => sprintf( esc_html__( 'Original image referenced in "%1$s" via %2$s (%3$s)', 'site-bloat-auditor' ), $ref['source_title'], $ref['detection_method'], $ref['match_type'] ),
                        ];
                    }
                }
            }

            // B. Check generated thumbnail variation references
            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                $file_dir   = $file_path ? dirname( $file_path ) : '';
                $upload_dir = wp_upload_dir();
                $basedir    = wp_normalize_path( $upload_dir['basedir'] );

                foreach ( $meta['sizes'] as $sz_key => $sz_info ) {
                    if ( empty( $sz_info['file'] ) ) {
                        continue;
                    }

                    $sub_file = $sz_info['file'];
                    $sub_path = $file_dir ? wp_normalize_path( $file_dir . '/' . $sub_file ) : '';
                    $sub_rel  = ( $sub_path && 0 === strpos( $sub_path, $basedir ) ) ? ltrim( substr( $sub_path, strlen( $basedir ) ), '/' ) : '';
                    $sub_url  = wp_get_attachment_image_url( $aid, $sz_key ) ?: '';

                    $sub_check = $ref_engine->check_thumbnail_usage( $sub_file, $sub_rel, $sub_url, $aid, $sz_key );
                    if ( $sub_check['found'] ) {
                        foreach ( $sub_check['references'] as $ref ) {
                            $this->usage_map[ $aid ][] = [
                                'source_type'      => sanitize_key( $ref['source_type'] ?? 'post' ),
                                'source_id'        => (int) ( $ref['source_id'] ?? 0 ),
                                'source_title'     => sanitize_text_field( $ref['source_title'] ?? '' ),
                                'detection_method' => sanitize_text_field( $ref['detection_method'] ?? 'thumbnail_subsize_reference' ),
                                'usage_type'       => 'Content',
                                'confidence'       => 'high',
                                'post_status'      => sanitize_key( $ref['source_status'] ?? 'publish' ),
                                'evidence'         => sprintf( esc_html__( 'Generated variation "%1$s" referenced in "%2$s" via %3$s (%4$s)', 'site-bloat-auditor' ), $sub_file, $ref['source_title'], $ref['detection_method'], $ref['match_type'] ),
                            ];
                        }
                    }
                }
            }
        }
    }
}

