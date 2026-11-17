<?php
/**
 * Fixture definitions + TARGET expectations for the media audit test scenario.
 *
 * Every fixture lives under uploads/sba-test-fixtures/ and every fixture post carries
 * post meta _sba_test_fixture = 1, so the whole scenario can be removed by
 * cleanup-fixtures.php without touching real content.
 *
 * Expectation vocabulary (what the plugin SHOULD report after a fresh complete scan):
 *   usage      used | unused | review   (review = only draft/trash/ambiguous evidence:
 *                                        never "safe to delete", never "actively used")
 *   source     fixture post key that must appear as the usage location (when used)
 *   kind       image | document | video | audio
 *   condition  optimized | within_benchmark | oversized | n/a   (n/a = not an image)
 *   duplicate  true when part of an exact SHA-256 duplicate group
 *   a11y       missing_alt | empty_alt | unhelpful_alt | undescriptive_filename
 *   missing    true when the original file is absent on disk
 *
 * Content placeholders: {id:key} {url:key} {url:key:size} {file:key}
 *
 * @package Site_Bloat_Auditor\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

const SBA_FIXTURE_DIR      = 'sba-test-fixtures';
const SBA_FIXTURE_META_KEY = '_sba_test_fixture';
const SBA_FIXTURE_TITLE    = '[SBA TEST] ';

function sba_fixture_attachments() {
	// spec: [ width, height, style ] style: plain|noise ; format from extension.
	return [
		// --- Replacement scenario (gold-loan) --------------------------------------
		'gold-loan'            => [ 'file' => 'gold-loan.jpg', 'spec' => [ 800, 500 ], 'parent' => 'gold_page',
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'Replaced by gold-loans.jpg; only the revision, the upload parent and slug-substring text still mention it.' ],
		'gold-loans'           => [ 'file' => 'gold-loans.jpg', 'spec' => [ 800, 500 ],
			'expect' => [ 'usage' => 'used', 'source' => 'gold_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],

		// --- Elementor ---------------------------------------------------------------
		'elementor-hero'       => [ 'file' => 'elementor-hero.jpg', 'spec' => [ 1100, 600 ],
			'expect' => [ 'usage' => 'used', 'source' => 'elementor_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'elementor-bg'         => [ 'file' => 'elementor-bg.jpg', 'spec' => [ 1000, 400 ],
			'expect' => [ 'usage' => 'used', 'source' => 'elementor_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'elementor-old'        => [ 'file' => 'elementor-old.jpg', 'spec' => [ 1000, 600 ],
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'Only referenced by an Elementor revision and a stale _elementor_element_cache entry.' ],

		// --- Classic / HTML / CSS ----------------------------------------------------
		'classic-inline'       => [ 'file' => 'classic-inline.jpg', 'spec' => [ 900, 600 ],
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'thumb-only'           => [ 'file' => 'thumb-only.jpg', 'spec' => [ 1200, 800 ],
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'Content references only the generated 300x200 thumbnail.' ],
		'bg-pattern'           => [ 'file' => 'bg-pattern.png', 'spec' => [ 400, 400 ],
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'Inline CSS background-image url().' ],
		'webp-native'          => [ 'file' => 'webp-native.webp', 'spec' => [ 800, 450 ],
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'dup-original'         => [ 'file' => 'dup-original.jpg', 'spec' => [ 640, 480 ],
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'image', 'condition' => 'within_benchmark', 'duplicate' => true ] ],
		'dup-copy'             => [ 'file' => 'dup-copy.jpg', 'copy_of' => 'dup-original',
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark', 'duplicate' => true ] ],

		// --- Featured image ----------------------------------------------------------
		'featured'             => [ 'file' => 'featured.jpg', 'spec' => [ 1200, 630 ],
			'expect' => [ 'usage' => 'used', 'source' => 'featured_post', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],

		// --- Similar filenames / slug substrings --------------------------------------
		'banner'               => [ 'file' => 'banner.jpg', 'spec' => [ 1000, 300 ],
			'expect' => [ 'usage' => 'used', 'source' => 'similar_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'banner-2'             => [ 'file' => 'banner-2.jpg', 'spec' => [ 1000, 300 ],
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'logo'                 => [ 'file' => 'logo.png', 'spec' => [ 300, 100 ],
			'expect' => [ 'usage' => 'used', 'source' => 'similar_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'logo-old'             => [ 'file' => 'logo-old.png', 'spec' => [ 300, 100 ],
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'team'                 => [ 'file' => 'team.jpg', 'spec' => [ 800, 533 ],
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'Page links to /our-team/ — slug "team" must not count as usage.' ],

		// --- Gallery shortcode (rendered output) ---------------------------------------
		'gallery-1'            => [ 'file' => 'gallery-1.jpg', 'spec' => [ 800, 600 ],
			'expect' => [ 'usage' => 'used', 'source' => 'gallery_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'gallery-2'            => [ 'file' => 'gallery-2.jpg', 'spec' => [ 800, 600 ],
			'expect' => [ 'usage' => 'used', 'source' => 'gallery_page', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],

		// --- Draft / trash only (historical, review required) ---------------------------
		'draft-only'           => [ 'file' => 'draft-only.jpg', 'spec' => [ 800, 500 ],
			'expect' => [ 'usage' => 'review', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'trash-only'           => [ 'file' => 'trash-only.jpg', 'spec' => [ 800, 500 ],
			'expect' => [ 'usage' => 'review', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],

		// --- Custom fields (ACF-style) ---------------------------------------------------
		'acf-hero'             => [ 'file' => 'acf-hero.jpg', 'spec' => [ 1000, 500 ],
			'expect' => [ 'usage' => 'used', 'source' => 'acf_post', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'ACF image field stored as ID with its _field_key companion.' ],
		'acf-url'              => [ 'file' => 'acf-url.jpg', 'spec' => [ 1000, 500 ],
			'expect' => [ 'usage' => 'used', 'source' => 'acf_post', 'kind' => 'image', 'condition' => 'within_benchmark' ] ],
		'collision-unused'     => [ 'file' => 'collision-unused.jpg', 'spec' => [ 600, 400 ],
			'expect' => [ 'usage' => 'review', 'kind' => 'image', 'condition' => 'within_benchmark' ],
			'why'    => 'A non-image numeric meta value happens to equal its ID: weak evidence, must not be "Actively In Use".' ],

		// --- Size / optimization ---------------------------------------------------------
		'optimized-real'       => [ 'file' => 'optimized-real.jpg', 'spec' => [ 2600, 1500, 'noise' ], 'optimize' => true,
			'expect' => [ 'usage' => 'used', 'source' => 'size_page', 'kind' => 'image', 'condition' => 'optimized' ],
			'why'    => 'Optimized with the plugin\'s real optimizer during fixture creation (verified record + .sba-orig + .webp).' ],
		'oversized-used'       => [ 'file' => 'oversized-used.jpg', 'spec' => [ 2600, 1400 ],
			'expect' => [ 'usage' => 'used', 'source' => 'size_page', 'kind' => 'image', 'condition' => 'oversized' ] ],
		'oversized-unused'     => [ 'file' => 'oversized-unused.jpg', 'spec' => [ 2400, 1600 ],
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'oversized' ] ],
		'heavy-noise'          => [ 'file' => 'heavy-noise.jpg', 'spec' => [ 1100, 800, 'noise' ], 'quality' => 100,
			'expect' => [ 'usage' => 'used', 'source' => 'size_page', 'kind' => 'image', 'condition' => 'oversized' ],
			'why'    => 'Within dimension benchmark but > 500 KB on disk.' ],

		// --- Accessibility ---------------------------------------------------------------
		'no-alt'               => [ 'file' => 'no-alt.jpg', 'spec' => [ 600, 400 ], 'alt' => null,
			'expect' => [ 'usage' => 'used', 'source' => 'a11y_page', 'kind' => 'image', 'condition' => 'within_benchmark', 'a11y' => 'missing_alt' ] ],
		'empty-alt'            => [ 'file' => 'empty-alt.jpg', 'spec' => [ 600, 400 ], 'alt' => '',
			'expect' => [ 'usage' => 'used', 'source' => 'a11y_page', 'kind' => 'image', 'condition' => 'within_benchmark', 'a11y' => 'empty_alt' ] ],
		'alt-equals-filename'  => [ 'file' => 'alt-equals-filename.jpg', 'spec' => [ 600, 400 ], 'alt' => 'alt-equals-filename',
			'expect' => [ 'usage' => 'used', 'source' => 'a11y_page', 'kind' => 'image', 'condition' => 'within_benchmark', 'a11y' => 'unhelpful_alt' ] ],
		'img-generic'          => [ 'file' => 'IMG_0001.jpg', 'spec' => [ 600, 400 ],
			'expect' => [ 'usage' => 'used', 'source' => 'a11y_page', 'kind' => 'image', 'condition' => 'within_benchmark', 'a11y' => 'undescriptive_filename' ] ],

		// --- Missing file ----------------------------------------------------------------
		'missing-original'     => [ 'file' => 'missing-original.jpg', 'spec' => [ 600, 400 ], 'remove_file' => true,
			'expect' => [ 'usage' => 'unused', 'kind' => 'image', 'condition' => 'n/a', 'missing' => true ] ],

		// --- Non-image attachments -------------------------------------------------------
		'document-used'        => [ 'file' => 'document-used.pdf', 'raw' => 'pdf',
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'document', 'condition' => 'n/a' ] ],
		'document-unused'      => [ 'file' => 'document-unused.pdf', 'raw' => 'pdf',
			'expect' => [ 'usage' => 'unused', 'kind' => 'document', 'condition' => 'n/a' ] ],
		'audio-used'           => [ 'file' => 'audio-used.mp3', 'raw' => 'placeholder',
			'expect' => [ 'usage' => 'used', 'source' => 'classic_post', 'kind' => 'audio', 'condition' => 'n/a' ] ],
		'video-unused'         => [ 'file' => 'video-unused.mp4', 'raw' => 'placeholder',
			'expect' => [ 'usage' => 'unused', 'kind' => 'video', 'condition' => 'n/a' ] ],
	];
}

/**
 * Fixture posts. 'content' may be a list of [ initial, updated ] to create a revision.
 */
function sba_fixture_posts() {
	$img = function ( $key, $size = 'large' ) {
		return '<!-- wp:image {"id":{id:' . $key . '},"sizeSlug":"' . $size . '"} --><figure class="wp-block-image size-' . $size . '"><img src="{url:' . $key . '}" alt="" class="wp-image-{id:' . $key . '}"/></figure><!-- /wp:image -->';
	};

	return [
		'gold_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'gold-loan-scheme', 'title' => 'Gold Loan Scheme',
			'content'   => [
				$img( 'gold-loan' ) . '<!-- wp:paragraph --><p>Apply for our gold-loan scheme today.</p><!-- /wp:paragraph -->',
				$img( 'gold-loans' ) . '<!-- wp:paragraph --><p>Apply for our gold-loan scheme today. <a href="/gold-loan-scheme/apply/">Start your application</a>.</p><!-- /wp:paragraph -->',
			],
		],
		'elementor_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'elementor-landing', 'title' => 'Elementor Landing',
			'content'   => '',
			'elementor' => [
				'data'          => '[{"id":"a1b2c3","elType":"section","settings":{"background_background":"classic","background_image":{"url":"{url:elementor-bg}","id":{id:elementor-bg}}},"elements":[{"id":"d4e5f6","elType":"widget","widgetType":"image","settings":{"image":{"url":"{url:elementor-hero}","id":{id:elementor-hero}},"image_size":"large"}}]}]',
				'revision_data' => '[{"id":"a1b2c3","elType":"section","settings":[],"elements":[{"id":"d4e5f6","elType":"widget","widgetType":"image","settings":{"image":{"url":"{url:elementor-old}","id":{id:elementor-old}},"image_size":"large"}}]}]',
				'stale_cache'   => '<div class="elementor-widget-image"><img src="{url:elementor-old}" class="attachment-large size-large wp-image-{id:elementor-old}" alt=""></div>',
			],
		],
		'classic_post' => [
			'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'classic-content', 'title' => 'Classic Content',
			'content'   =>
				'<p><img class="alignnone size-large wp-image-{id:classic-inline}" src="{url:classic-inline}" alt="Classic" width="900" height="600" /></p>' .
				'<p><img src="{url:thumb-only:medium}" alt="Thumb" width="300" height="200" /></p>' .
				'<div style="background-image: url(\'{url:bg-pattern}\'); height: 100px;">Pattern</div>' .
				'<p><img src="{url:webp-native}" alt="WebP" /></p>' .
				'<p><img src="{url:dup-original}" alt="Duplicate original" /></p>' .
				'<p><a href="{url:document-used}">Download brochure (PDF)</a></p>' .
				'<!-- wp:audio {"id":{id:audio-used}} --><figure class="wp-block-audio"><audio controls src="{url:audio-used}"></audio></figure><!-- /wp:audio -->',
		],
		'featured_post' => [
			'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'featured-post', 'title' => 'Featured Post',
			'content'   => '<p>Post with only a featured image.</p>',
			'thumbnail' => 'featured',
		],
		'similar_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'similar-names', 'title' => 'Similar Names',
			'content'   => $img( 'banner' ) . $img( 'logo', 'full' ) . '<p>Meet the <a href="/our-team/">team</a>. Older banner-2 artwork was retired; logo-old is archived offline.</p>',
		],
		'gallery_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'gallery-page', 'title' => 'Gallery Page',
			'content'   => '[gallery ids="{id:gallery-1},{id:gallery-2}"]',
		],
		'draft_post' => [
			'post_type' => 'post', 'post_status' => 'draft', 'post_name' => 'draft-only-post', 'title' => 'Draft Only',
			'content'   => $img( 'draft-only' ),
		],
		'trash_post' => [
			'post_type' => 'post', 'post_status' => 'trash', 'post_name' => 'trash-only-post', 'title' => 'Trashed Post',
			'content'   => $img( 'trash-only' ),
		],
		'acf_post' => [
			'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'acf-fields', 'title' => 'ACF Fields',
			'content'   => '<p>Hero rendered by theme template from custom fields.</p>',
			'meta'      => [
				'hero_image'  => '{id:acf-hero}',
				'_hero_image' => 'field_5f1a2b3c4d5e6',
				'banner_url'  => '{url:acf-url}',
			],
		],
		'collision_post' => [
			'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'collision-post', 'title' => 'Numeric Meta Collision',
			'content'   => '<p>Unrelated numeric meta value.</p>',
			'meta'      => [ 'sba_test_rating' => '{id:collision-unused}' ],
		],
		'size_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'size-page', 'title' => 'Size Page',
			'content'   => $img( 'optimized-real' ) . $img( 'oversized-used' ) . $img( 'heavy-noise' ),
		],
		'a11y_page' => [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'a11y-page', 'title' => 'Accessibility Page',
			'content'   => $img( 'no-alt' ) . $img( 'empty-alt' ) . $img( 'alt-equals-filename' ) . $img( 'img-generic' ),
		],
	];
}
