<?php
/**
 * Universal Image Reference Index & Usage Detection Engine.
 *
 * Scans and indexes image references across WordPress database sources:
 * - wp_posts (post_content across Gutenberg, Classic HTML, CPTs, synced patterns/reusable blocks)
 * - wp_postmeta (Elementor _elementor_data, ACF image fields, custom CSS)
 * - wp_options (widgets, theme mods, site identity, header image)
 * - HTML attributes: src, srcset, data-src, data-lazy-src, data-original, data-srcset, data-background, data-bg, poster
 * - CSS references: background-image, background, url() inline styles and style tags
 * - Page builders and shortcodes: Gutenberg, Elementor, WPBakery, rendered shortcodes
 * - Secondary verification: Rendered frontend output / in-process shortcode execution
 *
 * Provides centralized protection checks before displaying or executing thumbnail sub-size deletion.
 *
 * @package Site_Bloat_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBA_Image_Reference_Index {

    /**
     * Transient cache key for image reference index.
     */
    const TRANSIENT_KEY = 'sba_image_reference_index';

    /**
     * Cache TTL in seconds (10 minutes).
     */
    const CACHE_TTL = 600;

    /**
     * Singleton instance.
     *
     * @var SBA_Image_Reference_Index|null
     */
    private static $instance = null;

    /**
     * In-memory index data structure.
     *
     * @var array|null
     */
    private $index = null;

    /**
     * Whether the index scan completed successfully without fatal errors.
     *
     * @var bool
     */
    private $is_complete = false;

    /**
     * Get singleton instance.
     *
     * @return SBA_Image_Reference_Index
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {
        // Ready for index building on demand.
    }

    /**
     * Invalidate transient and memory cache.
     */
    public static function invalidate_cache() {
        delete_transient( self::TRANSIENT_KEY );
        if ( null !== self::$instance ) {
            self::$instance->index       = null;
            self::$instance->is_complete = false;
        }
    }

    /**
     * Get or build the reference index.
     *
     * @param bool $force_refresh Force re-scan of database and content.
     * @return array
     */
    public function get_index( $force_refresh = false ) {
        if ( ! $force_refresh && null !== $this->index ) {
            return $this->index;
        }

        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( is_array( $cached ) && isset( $cached['is_complete'] ) ) {
                $this->index       = $cached;
                $this->is_complete = ! empty( $cached['is_complete'] );
                return $this->index;
            }
        }

        $this->build_index();
        return $this->index;
    }

    /**
     * Build the global reference index across all supported database and content sources.
     */
    public function build_index() {
        global $wpdb;

        $index = [
            'filenames'        => [], // [ 'solar-150x150.jpg' => [ ref1, ref2 ] ]
            'relative_paths'   => [], // [ '2026/10/solar-150x150.jpg' => [ ref1 ] ]
            'urls'             => [], // [ 'http://.../solar-150x150.jpg' => [ ref1 ] ]
            'attachment_sizes' => [], // [ '559_thumbnail' => [ ref1 ] ]
            'attachment_ids'   => [], // [ 559 => [ ref1 ] ]
            'is_complete'      => false,
            'indexed_at'       => time(),
            'counts'           => [
                'posts'    => 0,
                'postmeta' => 0,
                'options'  => 0,
                'rendered' => 0,
            ],
        ];

        try {
            // 1. Scan Posts & Pages (post_content across public, private, drafts, CPTs, synced patterns/reusable blocks)
            $this->scan_posts_content( $index );

            // 2. Scan Postmeta (Elementor, ACF, page builders, custom CSS)
            $this->scan_postmeta_sources( $index );

            // 3. Scan Options (Widgets, Theme mods, Site Identity)
            $this->scan_options_sources( $index );

            // 4. Secondary Verification: Rendered Frontend / Shortcode Output
            $this->scan_rendered_shortcodes( $index );

            $index['is_complete'] = true;
            $this->is_complete    = true;
            $this->index          = $index;

            // Cache transient
            set_transient( self::TRANSIENT_KEY, $index, self::CACHE_TTL );

        } catch ( \Throwable $e ) {
            $index['is_complete'] = false;
            $this->is_complete    = false;
            $this->index          = $index;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'SBA_Image_Reference_Index build error: ' . $e->getMessage() );
            }
        }

        return $this->index;
    }

    /**
     * Check if a thumbnail file variation is referenced anywhere on the site.
     *
     * @param string $filename     e.g. 'solar-150x150.jpg'
     * @param string $rel_path     e.g. '2026/10/solar-150x150.jpg'
     * @param string $full_url     e.g. 'http://example.com/wp-content/uploads/2026/10/solar-150x150.jpg'
     * @param int    $att_id       Attachment ID e.g. 559
     * @param string $size_key     Subsize key e.g. 'thumbnail'
     * @return array [ 'found' => bool, 'references' => array, 'primary' => array|null, 'is_incomplete' => bool ]
     */
    public function check_thumbnail_usage( $filename, $rel_path = '', $full_url = '', $att_id = 0, $size_key = '' ) {
        $index = $this->get_index();

        if ( empty( $index ) || empty( $index['is_complete'] ) ) {
            return [
                'found'         => false,
                'references'    => [],
                'primary'       => null,
                'is_incomplete' => true,
            ];
        }

        $matched_refs = [];
        $filename_key = strtolower( wp_basename( $filename ) );

        // 1. Check full URL first (highest precision)
        if ( ! empty( $full_url ) ) {
            $clean_url = strtolower( $this->normalize_url( $full_url ) );
            if ( isset( $index['urls'][ $clean_url ] ) ) {
                foreach ( $index['urls'][ $clean_url ] as $ref ) {
                    $matched_refs[] = array_merge( $ref, [
                        'match_type' => 'Exact image URL',
                    ] );
                }
            }
        }

        // 2. Check relative upload path
        if ( ! empty( $rel_path ) ) {
            $clean_rel = strtolower( ltrim( wp_normalize_path( $rel_path ), '/' ) );
            if ( isset( $index['relative_paths'][ $clean_rel ] ) ) {
                foreach ( $index['relative_paths'][ $clean_rel ] as $ref ) {
                    $matched_refs[] = array_merge( $ref, [
                        'match_type' => 'Uploads relative path',
                    ] );
                }
            }
        }

        // 3. Check exact filename
        if ( ! empty( $filename_key ) && isset( $index['filenames'][ $filename_key ] ) ) {
            foreach ( $index['filenames'][ $filename_key ] as $ref ) {
                $matched_refs[] = array_merge( $ref, [
                    'match_type' => ! empty( $ref['match_type'] ) ? $ref['match_type'] : 'Image filename match',
                ] );
            }
        }

        // 4. Check URL-decoded filename variant
        $decoded_fn = strtolower( rawurldecode( $filename_key ) );
        if ( $decoded_fn !== $filename_key && isset( $index['filenames'][ $decoded_fn ] ) ) {
            foreach ( $index['filenames'][ $decoded_fn ] as $ref ) {
                $matched_refs[] = array_merge( $ref, [
                    'match_type' => 'URL-decoded filename match',
                ] );
            }
        }

        // 5. Check attachment ID + size key combination (e.g. Gutenberg/WPBakery block attributes)
        if ( $att_id > 0 && ! empty( $size_key ) ) {
            $combo_key = $att_id . '_' . strtolower( $size_key );
            if ( isset( $index['attachment_sizes'][ $combo_key ] ) ) {
                foreach ( $index['attachment_sizes'][ $combo_key ] as $ref ) {
                    $matched_refs[] = array_merge( $ref, [
                        'match_type' => 'Block / shortcode size attribute',
                    ] );
                }
            }
        }

        // Deduplicate references by source_type + source_id + detection_method
        $unique_refs = [];
        $seen        = [];
        foreach ( $matched_refs as $r ) {
            $k = ( $r['source_type'] ?? '' ) . ':' . ( $r['source_id'] ?? 0 ) . ':' . ( $r['detection_method'] ?? '' );
            if ( ! isset( $seen[ $k ] ) ) {
                $seen[ $k ]    = true;
                $unique_refs[] = $r;
            }
        }

        if ( ! empty( $unique_refs ) ) {
            // Sort to prefer published / exact matches
            usort( $unique_refs, function( $a, $b ) {
                $a_pub = ( 'publish' === ( $a['source_status'] ?? '' ) ) ? 1 : 0;
                $b_pub = ( 'publish' === ( $b['source_status'] ?? '' ) ) ? 1 : 0;
                if ( $a_pub !== $b_pub ) {
                    return $b_pub - $a_pub;
                }
                return 0;
            } );

            return [
                'found'         => true,
                'references'    => $unique_refs,
                'primary'       => $unique_refs[0],
                'is_incomplete' => false,
            ];
        }

        return [
            'found'         => false,
            'references'    => [],
            'primary'       => null,
            'is_incomplete' => false,
        ];
    }

    /**
     * 1. Scan Posts & Pages content.
     */
    private function scan_posts_content( array &$index ) {
        global $wpdb;

        // Query posts across publish, draft, pending, future, private, and inherit (for reusable blocks)
        $posts = $wpdb->get_results( $wpdb->prepare( "
            SELECT ID, post_title, post_name, post_content, post_type, post_status
            FROM {$wpdb->posts}
            WHERE post_type NOT IN (%s, %s)
              AND post_status IN (%s, %s, %s, %s, %s, %s)
              AND post_content != %s
            ORDER BY ID ASC
        ", 'attachment', 'revision', 'publish', 'draft', 'pending', 'future', 'private', 'inherit', '' ) );

        if ( empty( $posts ) ) {
            return;
        }

        foreach ( $posts as $p ) {
            $index['counts']['posts']++;
            $source_info = [
                'source_id'     => (int) $p->ID,
                'source_title'  => ! empty( $p->post_title ) ? $p->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $p->ID ),
                'source_type'   => $p->post_type,
                'source_status' => $p->post_status,
            ];

            // Parse HTML, Gutenberg blocks, CSS, and shortcodes from post_content
            $this->parse_content_for_images( $p->post_content, $source_info, $index );
        }
    }

    /**
     * 2. Scan Postmeta sources (Elementor, ACF, page builders, custom CSS).
     */
    private function scan_postmeta_sources( array &$index ) {
        global $wpdb;

        // A. Elementor JSON data (_elementor_data)
        $elementor_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT m.post_id, m.meta_value, p.post_title, p.post_type, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_key = %s
              AND p.post_status != %s
              AND p.post_type != %s
        ", '_elementor_data', 'trash', 'revision' ) ); // D5: revisions hold replaced layouts, not live usage.

        foreach ( $elementor_rows as $row ) {
            $index['counts']['postmeta']++;
            $source_info = [
                'source_id'        => (int) $row->post_id,
                'source_title'     => ! empty( $row->post_title ) ? $row->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $row->post_id ),
                'source_type'      => $row->post_type,
                'source_status'    => $row->post_status,
                'detection_method' => 'Elementor page builder data',
            ];

            $content_str = (string) $row->meta_value;
            // Unescape JSON forward slashes
            $content_str = str_replace( '\/', '/', $content_str );
            $this->parse_content_for_images( $content_str, $source_info, $index, 'Elementor page builder data' );

            // Also check for Elementor image settings: "id":123 and "size":"thumbnail" or "image_size":"thumbnail"
            if ( preg_match_all( '/"id":\s*(\d+)[^}]*?"(?:size|image_size)":\s*"([^"]+)"/', $content_str, $elem_matches, PREG_SET_ORDER ) ) {
                foreach ( $elem_matches as $m ) {
                    $att_id   = (int) $m[1];
                    $size_slug = sanitize_key( $m[2] );
                    if ( $att_id > 0 && ! empty( $size_slug ) ) {
                        $combo_key = $att_id . '_' . strtolower( $size_slug );
                        $index['attachment_sizes'][ $combo_key ][] = array_merge( $source_info, [
                            'detection_method' => 'Elementor page builder data',
                            'match_type'       => 'Block / shortcode size attribute',
                            'matched_token'    => "id:{$att_id}, size:{$size_slug}",
                        ] );
                    }
                }
            }
        }

        // B. Custom fields & ACF image fields (where meta_value contains upload path or image extension).
        // _elementor_element_cache is Elementor's derived render cache: it can hold markup of
        // images already removed from _elementor_data (D5), which is scanned above.
        $meta_rows = $wpdb->get_results( "
            SELECT m.post_id, m.meta_key, m.meta_value, p.post_title, p.post_type, p.post_status
            FROM {$wpdb->postmeta} m
            INNER JOIN {$wpdb->posts} p ON m.post_id = p.ID
            WHERE m.meta_key NOT IN ('_elementor_data', '_elementor_css', '_elementor_element_cache', '_thumbnail_id', '_sba_quarantined', '_wp_attached_file')
              AND p.post_type NOT IN ('attachment', 'revision')
              AND p.post_status != 'trash'
              AND (
                  m.meta_value LIKE '%wp-content/uploads%'
                  OR m.meta_value LIKE '%.jpg%'
                  OR m.meta_value LIKE '%.jpeg%'
                  OR m.meta_value LIKE '%.png%'
                  OR m.meta_value LIKE '%.webp%'
                  OR m.meta_value LIKE '%.gif%'
              )
            LIMIT 1000
        " );

        foreach ( $meta_rows as $row ) {
            $index['counts']['postmeta']++;
            $source_info = [
                'source_id'        => (int) $row->post_id,
                'source_title'     => ! empty( $row->post_title ) ? $row->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $row->post_id ),
                'source_type'      => $row->post_type,
                'source_status'    => $row->post_status,
                'detection_method' => sprintf( 'ACF / Custom Field meta (%s)', sanitize_key( $row->meta_key ) ),
            ];

            $val = $row->meta_value;
            // Safely handle serialized strings
            if ( is_serialized( $val ) ) {
                $unserialized = @maybe_unserialize( $val );
                if ( is_array( $unserialized ) ) {
                    $val = wp_json_encode( $unserialized );
                }
            }
            $this->parse_content_for_images( (string) $val, $source_info, $index, $source_info['detection_method'] );
        }
    }

    /**
     * 3. Scan Options sources (Widgets, Theme mods, Site Identity).
     */
    private function scan_options_sources( array &$index ) {
        global $wpdb;

        // Query widget options, theme mods, and site identity. This plugin's own bookkeeping
        // options (sba_*: optimization records, quarantine journal, cached index) and transients
        // are not usage (D12): an optimization record must not keep its own image "in use".
        $option_rows = $wpdb->get_results( "
            SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE ( option_name LIKE 'widget\_%'
               OR option_name LIKE 'theme\_mods\_%'
               OR option_name IN ('site_icon', 'custom_logo', 'header_image_data', 'header_image')
               OR option_value LIKE '%wp-content/uploads%' )
              AND option_name NOT LIKE 'sba\_%'
              AND option_name NOT LIKE '\_transient\_%'
              AND option_name NOT LIKE '\_site\_transient\_%'
            LIMIT 500
        " );

        foreach ( $option_rows as $row ) {
            $index['counts']['options']++;
            $opt_name    = sanitize_text_field( $row->option_name );
            $source_info = [
                'source_id'        => 0,
                'source_title'     => sprintf( 'WordPress Option "%s"', $opt_name ),
                'source_type'      => 'option',
                'source_status'    => 'publish',
                'detection_method' => 'Theme option / widget setting',
            ];

            $val = maybe_unserialize( $row->option_value );
            if ( is_array( $val ) || is_object( $val ) ) {
                $str = wp_json_encode( $val );
            } else {
                $str = (string) $val;
            }

            $this->parse_content_for_images( $str, $source_info, $index, 'Theme option / widget setting' );
        }
    }

    /**
     * 4. Secondary verification: Rendered Frontend / Shortcode Output.
     * Executes shortcodes in memory on published pages to detect dynamic image output.
     */
    private function scan_rendered_shortcodes( array &$index ) {
        global $wpdb;

        // Query published pages and posts containing shortcode brackets '['
        $shortcode_posts = $wpdb->get_results( $wpdb->prepare( "
            SELECT ID, post_title, post_content, post_type, post_status
            FROM {$wpdb->posts}
            WHERE post_type IN (%s, %s)
              AND post_status = %s
              AND post_content LIKE %s
            LIMIT 25
        ", 'page', 'post', 'publish', '%[%' ) );

        if ( empty( $shortcode_posts ) ) {
            return;
        }

        foreach ( $shortcode_posts as $sp ) {
            $index['counts']['rendered']++;
            $source_info = [
                'source_id'        => (int) $sp->ID,
                'source_title'     => ! empty( $sp->post_title ) ? $sp->post_title : sprintf( esc_html__( 'Post #%d', 'site-bloat-auditor' ), (int) $sp->ID ),
                'source_type'      => $sp->post_type,
                'source_status'    => $sp->post_status,
                'detection_method' => 'Rendered shortcode frontend output',
            ];

            try {
                // Execute shortcodes in-process safely
                $rendered_html = do_shortcode( $sp->post_content );
                if ( ! empty( $rendered_html ) && $rendered_html !== $sp->post_content ) {
                    $this->parse_content_for_images( $rendered_html, $source_info, $index, 'Rendered shortcode frontend output' );
                }
            } catch ( \Throwable $e ) {
                // Continue scanning remaining posts if shortcode execution encounters an issue
            }
        }
    }

    /**
     * Universal Parser: Extracts image URLs, filenames, and block attributes from content text.
     *
     * @param string $content Raw content string.
     * @param array  $source_info Metadata about the source post/option.
     * @param array  &$index Master index reference.
     * @param string $override_method Optional detection method label.
     */
    public function parse_content_for_images( $content, array $source_info, array &$index, $override_method = '' ) {
        if ( empty( $content ) || ! is_string( $content ) ) {
            return;
        }

        // Unescape JSON forward slashes if present
        if ( false !== strpos( $content, '\/' ) ) {
            $content = str_replace( '\/', '/', $content );
        }

        // A. HTML Tag Attributes: src, data-src, data-lazy-src, data-original, data-background, data-bg, poster
        if ( preg_match_all( '/(?:src|data-src|data-lazy-src|data-original|data-background|data-bg|poster)\s*=\s*["\']?([^"\'\s>]+)["\']?/i', $content, $attr_matches, PREG_SET_ORDER ) ) {
            foreach ( $attr_matches as $m ) {
                $raw_url = trim( $m[1] );
                if ( $this->is_image_path_or_url( $raw_url ) ) {
                    $method = ! empty( $override_method ) ? $override_method : 'Direct HTML <img> reference';
                    $this->add_token_to_index( $raw_url, $source_info, $method, $index );
                }
            }
        }

        // B. HTML srcset and data-srcset attributes (contains multiple comma-separated image URLs with width descriptors)
        if ( preg_match_all( '/(?:srcset|data-srcset)\s*=\s*["\']([^"\']+)["\']/i', $content, $srcset_matches ) ) {
            foreach ( $srcset_matches[1] as $srcset_str ) {
                $parts = explode( ',', $srcset_str );
                foreach ( $parts as $part ) {
                    $part = trim( $part );
                    if ( preg_match( '/^([^\s,]+)/', $part, $url_m ) ) {
                        $raw_url = trim( $url_m[1] );
                        if ( $this->is_image_path_or_url( $raw_url ) ) {
                            $method = ! empty( $override_method ) ? $override_method : 'Responsive srcset attribute';
                            $this->add_token_to_index( $raw_url, $source_info, $method, $index );
                        }
                    }
                }
            }
        }

        // C. CSS url(...) references (background-image, inline styles, <style> tags)
        if ( preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $content, $css_matches ) ) {
            foreach ( $css_matches[1] as $raw_url ) {
                $raw_url = trim( $raw_url );
                if ( $this->is_image_path_or_url( $raw_url ) ) {
                    $method = ! empty( $override_method ) ? $override_method : 'CSS background-image / style';
                    $this->add_token_to_index( $raw_url, $source_info, $method, $index );
                }
            }
        }

        // D. Gutenberg Block Comments: <!-- wp:image {"id":123,"sizeSlug":"thumbnail"} -->
        if ( preg_match_all( '/<!--\s*wp:(?:image|cover|media-text)\s+({.*?})\s*-->/s', $content, $block_matches ) ) {
            foreach ( $block_matches[1] as $json_str ) {
                $parsed = json_decode( $json_str, true );
                if ( is_array( $parsed ) ) {
                    $att_id    = (int) ( $parsed['id'] ?? ( $parsed['mediaId'] ?? 0 ) );
                    $size_slug = sanitize_key( $parsed['sizeSlug'] ?? '' );
                    if ( $att_id > 0 ) {
                        $index['attachment_ids'][ $att_id ][] = array_merge( $source_info, [
                            'detection_method' => ! empty( $override_method ) ? $override_method : 'Gutenberg block markup',
                            'match_type'       => 'Attachment ID match',
                            'matched_token'    => (string) $att_id,
                        ] );

                        if ( ! empty( $size_slug ) ) {
                            $combo_key = $att_id . '_' . strtolower( $size_slug );
                            $index['attachment_sizes'][ $combo_key ][] = array_merge( $source_info, [
                                'detection_method' => ! empty( $override_method ) ? $override_method : 'Gutenberg block markup',
                                'match_type'       => 'Block / shortcode size attribute',
                                'matched_token'    => "id:{$att_id}, size:{$size_slug}",
                            ] );
                        }
                    }
                }
            }
        }

        // E. WPBakery Shortcode Attributes: [vc_single_image image="123" img_size="thumbnail"].
        // Attributes in any order; img_size is optional — WPBakery omits it when the element
        // uses its default size "thumbnail" (D11: the old pattern required img_size).
        if ( preg_match_all( '/\[vc_single_image\b([^\]]*)\]/i', $content, $wpb_tags ) ) {
            $wpb_matches = [];
            foreach ( $wpb_tags[1] as $attr_str ) {
                $atts = shortcode_parse_atts( $attr_str );
                if ( is_array( $atts ) && ! empty( $atts['image'] ) ) {
                    $wpb_matches[] = [ '', (string) $atts['image'], (string) ( $atts['img_size'] ?? 'thumbnail' ) ];
                }
            }
            foreach ( $wpb_matches as $m ) {
                $img_val   = $m[1];
                $size_slug = sanitize_key( $m[2] );
                if ( is_numeric( $img_val ) ) {
                    $att_id    = (int) $img_val;
                    $combo_key = $att_id . '_' . strtolower( $size_slug );
                    $index['attachment_sizes'][ $combo_key ][] = array_merge( $source_info, [
                        'detection_method' => ! empty( $override_method ) ? $override_method : 'WPBakery shortcode',
                        'match_type'       => 'Block / shortcode size attribute',
                        'matched_token'    => "id:{$att_id}, size:{$size_slug}",
                    ] );
                } elseif ( $this->is_image_path_or_url( $img_val ) ) {
                    $this->add_token_to_index( $img_val, $source_info, 'WPBakery shortcode', $index );
                }
            }
        }

        // F. General upload path matching fallback for custom HTML / unquoted references
        if ( preg_match_all( '/(?:https?:)?\/\/[^\s"\'<>\(\)]+?\.(?:jpe?g|png|webp|gif|svg|avif)(?:\?[^\s"\'<>\(\)]*)?/i', $content, $gen_url_matches ) ) {
            foreach ( $gen_url_matches[0] as $raw_url ) {
                $raw_url = trim( $raw_url );
                $method  = ! empty( $override_method ) ? $override_method : 'Direct HTML / content reference';
                $this->add_token_to_index( $raw_url, $source_info, $method, $index );
            }
        }

        // G. Relative uploads path fallback (e.g. /wp-content/uploads/2026/10/solar-150x150.jpg)
        if ( preg_match_all( '/\/(?:wp-content\/uploads\/[^\s"\'<>\(\)]+?\.(?:jpe?g|png|webp|gif|svg|avif))(?:\?[^\s"\'<>\(\)]*)?/i', $content, $gen_rel_matches ) ) {
            foreach ( $gen_rel_matches[0] as $raw_rel ) {
                $raw_rel = trim( $raw_rel );
                $method  = ! empty( $override_method ) ? $override_method : 'Direct HTML / content reference';
                $this->add_token_to_index( $raw_rel, $source_info, $method, $index );
            }
        }
    }

    /**
     * Check if a raw token represents an image URL or filename.
     *
     * @param string $token
     * @return bool
     */
    private function is_image_path_or_url( $token ) {
        if ( empty( $token ) || ! is_string( $token ) ) {
            return false;
        }

        // Strip query string and fragment
        $clean = explode( '?', $token )[0];
        $clean = explode( '#', $clean )[0];

        return (bool) preg_match( '/\.(?:jpe?g|png|webp|gif|svg|avif)$/i', $clean );
    }

    /**
     * Add normalized token to all relevant index buckets.
     *
     * @param string $raw_token
     * @param array  $source_info
     * @param string $detection_method
     * @param array  &$index
     */
    private function add_token_to_index( $raw_token, array $source_info, $detection_method, array &$index ) {
        // Strip escaped slashes, decode HTML entities, strip query strings and fragments
        $token = stripslashes( $raw_token );
        $token = html_entity_decode( $token, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $token = explode( '?', $token )[0];
        $token = explode( '#', $token )[0];
        $token = trim( $token, " \t\n\r\0\x0B\"'<>(),;" );

        if ( empty( $token ) ) {
            return;
        }

        $basename = wp_basename( $token );
        if ( empty( $basename ) || ! preg_match( '/\.(?:jpe?g|png|webp|gif|svg|avif)$/i', $basename ) ) {
            return;
        }

        $base_lower = strtolower( $basename );
        $ref_item   = array_merge( $source_info, [
            'detection_method' => $detection_method,
            'match_type'       => 'Exact image URL',
            'matched_token'    => $raw_token,
        ] );

        // 1. Index by filename
        $index['filenames'][ $base_lower ][] = array_merge( $ref_item, [
            'match_type' => 'Image filename match',
        ] );

        // Also URL-decoded filename variant
        $decoded_base = strtolower( rawurldecode( $base_lower ) );
        if ( $decoded_base !== $base_lower ) {
            $index['filenames'][ $decoded_base ][] = array_merge( $ref_item, [
                'match_type' => 'URL-decoded filename match',
            ] );
        }

        // 2. Index by relative uploads path if present
        $norm_token = wp_normalize_path( $token );
        $uploads_pos = stripos( $norm_token, 'wp-content/uploads/' );
        if ( false !== $uploads_pos ) {
            $rel_path = substr( $norm_token, $uploads_pos + strlen( 'wp-content/uploads/' ) );
            $rel_path = strtolower( ltrim( $rel_path, '/' ) );
            if ( ! empty( $rel_path ) ) {
                $index['relative_paths'][ $rel_path ][] = array_merge( $ref_item, [
                    'match_type' => 'Uploads relative path',
                ] );
            }
        }

        // 3. Index by normalized full URL if absolute
        if ( preg_match( '/^https?:\/\//i', $token ) || 0 === strpos( $token, '//' ) ) {
            $norm_url = strtolower( $this->normalize_url( $token ) );
            $index['urls'][ $norm_url ][] = array_merge( $ref_item, [
                'match_type' => 'Exact image URL',
            ] );
        }
    }

    /**
     * Normalize URL for exact lookup comparison.
     *
     * @param string $url
     * @return string
     */
    public function normalize_url( $url ) {
        $url = trim( $url );
        $url = explode( '?', $url )[0];
        $url = explode( '#', $url )[0];
        $url = wp_normalize_path( $url );
        return rtrim( $url, '/' );
    }
}
