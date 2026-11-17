# Media audit behaviour — fixture scenario

Generated 2026-11-14 05:06 UTC against plugin 1.1.0 (`7466a0f`).

Checks: **66 passed, 34 failed**. Expected = target behaviour; Actual = current plugin output (usage / filter tab that shows the item).

| | Fixture | File | Expected | Actual | Classification | Reported usage locations |
|---|---|---|---|---|---|---|
| ❌ | gold-loan | `sba-test-fixtures/gold-loan.jpg` | unused / within_benchmark | used / optimized | likely_referenced | [SBA TEST] Gold Loan Scheme [Content; medium] |
| ❌ | gold-loans | `sba-test-fixtures/gold-loans.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Gold Loan Scheme [Content; high] |
| ❌ | elementor-hero | `sba-test-fixtures/elementor-hero.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Elementor Landing [Page Builder; high]<br>[SBA TEST] Elementor Landing [Content; high] |
| ❌ | elementor-bg | `sba-test-fixtures/elementor-bg.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Elementor Landing [Page Builder; high]<br>[SBA TEST] Elementor Landing [Content; high] |
| ❌ | elementor-old | `sba-test-fixtures/elementor-old.jpg` | unused / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Elementor Landing [Page Builder; high]<br>[SBA TEST] Elementor Landing [Content; high] |
| ❌ | classic-inline | `sba-test-fixtures/classic-inline.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; high] |
| ❌ | thumb-only | `sba-test-fixtures/thumb-only.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; medium]<br>[SBA TEST] Classic Content [Content; high] |
| ❌ | bg-pattern | `sba-test-fixtures/bg-pattern.png` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; medium]<br>[SBA TEST] Classic Content [Content; high] |
| ❌ | webp-native | `sba-test-fixtures/webp-native.webp` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; medium]<br>[SBA TEST] Classic Content [Content; high] |
| ❌ | dup-original | `sba-test-fixtures/dup-original.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; medium]<br>[SBA TEST] Classic Content [Content; high] |
| ✅ | dup-copy | `sba-test-fixtures/dup-copy.jpg` | unused / within_benchmark | unused / unused | no_references_found | — |
| ❌ | featured | `sba-test-fixtures/featured.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Featured Post [Featured; high] |
| ❌ | banner | `sba-test-fixtures/banner.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Similar Names [Content; high] |
| ❌ | banner-2 | `sba-test-fixtures/banner-2.jpg` | unused / within_benchmark | used / optimized | likely_referenced | [SBA TEST] Similar Names [Content; medium] |
| ❌ | logo | `sba-test-fixtures/logo.png` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Similar Names [Content; high] |
| ❌ | logo-old | `sba-test-fixtures/logo-old.png` | unused / within_benchmark | used / optimized | likely_referenced | [SBA TEST] Similar Names [Content; medium] |
| ❌ | team | `sba-test-fixtures/team.jpg` | unused / within_benchmark | used / optimized | likely_referenced | [SBA TEST] Similar Names [Content; medium] |
| ❌ | gallery-1 | `sba-test-fixtures/gallery-1.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Gallery Page [Content; high] |
| ❌ | gallery-2 | `sba-test-fixtures/gallery-2.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Gallery Page [Content; high] |
| ✅ | draft-only | `sba-test-fixtures/draft-only.jpg` | review / within_benchmark | review / optimized | requires_manual_review | [SBA TEST] Draft Only [Content; high] |
| ❌ | trash-only | `sba-test-fixtures/trash-only.jpg` | review / within_benchmark | unused / unused | no_references_found | — |
| ❌ | acf-hero | `sba-test-fixtures/acf-hero.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] ACF Fields [Custom Field; high] |
| ❌ | acf-url | `sba-test-fixtures/acf-url.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] ACF Fields [Content; high] |
| ❌ | collision-unused | `sba-test-fixtures/collision-unused.jpg` | review / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Numeric Meta Collision [Custom Field; high] |
| ❌ | optimized-real | `sba-test-fixtures/optimized-real-scaled.jpg` | used / optimized | used / oversized | confirmed_referenced | [SBA TEST] Size Page [Content; high]<br>WordPress Option "sba_optimized_images" [Content; high] |
| ✅ | oversized-used | `sba-test-fixtures/oversized-used-scaled.jpg` | used / oversized | used / oversized | confirmed_referenced | [SBA TEST] Size Page [Content; high] |
| ❌ | oversized-unused | `sba-test-fixtures/oversized-unused.jpg` | unused / oversized | unused / unused | no_references_found | — |
| ❌ | heavy-noise | `sba-test-fixtures/heavy-noise.jpg` | used / oversized | used / optimized | confirmed_referenced | [SBA TEST] Size Page [Content; high] |
| ❌ | no-alt | `sba-test-fixtures/no-alt.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Accessibility Page [Content; high] |
| ❌ | empty-alt | `sba-test-fixtures/empty-alt.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Accessibility Page [Content; high] |
| ❌ | alt-equals-filename | `sba-test-fixtures/alt-equals-filename.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Accessibility Page [Content; high] |
| ❌ | img-generic | `sba-test-fixtures/IMG_0001.jpg` | used / within_benchmark | used / optimized | confirmed_referenced | [SBA TEST] Accessibility Page [Content; high] |
| ✅ | missing-original | `sba-test-fixtures/missing-original.jpg` | unused / n/a | unused / unused | no_references_found | — |
| ❌ | document-used | `sba-test-fixtures/document-used.pdf` | used / n/a | used / optimized | likely_referenced | [SBA TEST] Classic Content [Content; medium] |
| ✅ | document-unused | `sba-test-fixtures/document-unused.pdf` | unused / n/a | unused / unused | no_references_found | — |
| ❌ | audio-used | `sba-test-fixtures/audio-used.mp3` | used / n/a | used / optimized | confirmed_referenced | [SBA TEST] Classic Content [Content; high] |
| ✅ | video-unused | `sba-test-fixtures/video-unused.mp4` | unused / n/a | unused / unused | no_references_found | — |

## Failed checks

- gold-loan: usage = unused — actual: used via [SBA TEST] Gold Loan Scheme [Content; medium]
- gold-loans: condition = within_benchmark — actual: optimized
- elementor-hero: condition = within_benchmark — actual: optimized
- elementor-bg: condition = within_benchmark — actual: optimized
- elementor-old: usage = unused — actual: used via [SBA TEST] Elementor Landing [Page Builder; high] \| [SBA TEST] Elementor Landing [Content; high]
- classic-inline: condition = within_benchmark — actual: optimized
- thumb-only: condition = within_benchmark — actual: optimized
- bg-pattern: condition = within_benchmark — actual: optimized
- webp-native: condition = within_benchmark — actual: optimized
- dup-original: condition = within_benchmark — actual: optimized
- featured: condition = within_benchmark — actual: optimized
- banner: condition = within_benchmark — actual: optimized
- banner-2: usage = unused — actual: used via [SBA TEST] Similar Names [Content; medium]
- logo: condition = within_benchmark — actual: optimized
- logo-old: usage = unused — actual: used via [SBA TEST] Similar Names [Content; medium]
- team: usage = unused — actual: used via [SBA TEST] Similar Names [Content; medium]
- gallery-1: condition = within_benchmark — actual: optimized
- gallery-2: condition = within_benchmark — actual: optimized
- trash-only: usage = review — actual: unused
- acf-hero: condition = within_benchmark — actual: optimized
- acf-url: condition = within_benchmark — actual: optimized
- collision-unused: usage = review — actual: used via [SBA TEST] Numeric Meta Collision [Custom Field; high]
- optimized-real: condition = optimized — actual: oversized
- oversized-unused: condition = oversized — actual: unused
- heavy-noise: condition = oversized — actual: optimized
- no-alt: condition = within_benchmark — actual: optimized
- empty-alt: condition = within_benchmark — actual: optimized
- alt-equals-filename: condition = within_benchmark — actual: optimized
- img-generic: condition = within_benchmark — actual: optimized
- document-used: non-image is not classified as optimized/oversized — actual: optimized
- audio-used: non-image is not classified as optimized/oversized — actual: optimized
- Potentially Reclaimable > 0 MB when unreferenced fixtures exist — potentially_reclaimable_mb = 0 (10 fixtures expected unused)
- "Optimized" filter contains only items with verified optimization records — 31 of 32 optimized_items have no record in sba_optimized_images
- "Optimized" filter contains no PDFs / audio / video — audio-used.mp3, document-used.pdf

## Metrics reported

- `total_media_size_mb`: 20.760000000000002
- `potentially_reclaimable_mb`: 0
- `estimated_optimization_savings_mb`: 0.59999999999999998
- `backup_storage_consumed_mb`: 4.0300000000000002
- `actual_reclaimed_mb`: 6.25
- `total_scanned`: 40
- `unused_count`: 6
- `oversized_count`: 2
- `optimized_count`: 32
- `duplicates_count`: 1
