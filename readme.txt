=== Site Bloat & Storage Auditor ===
Contributors: sheetal2311
Tags: database cleanup, media audit, storage optimizer, orphaned tables, bloat cleaner
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit orphaned database tables, unused media, and plugin storage bloat with safe backups and quarantine isolation.

== Description ==

**Site Bloat & Storage Auditor** is an all-in-one site health clinic and storage hygiene suite for WordPress. It identifies leftover orphaned database tables from deleted plugins, isolates unlinked media attachments into a safe quarantine trash with 1-click restoration, inspects thumbnail sub-size overhead against display standards, links database storage directly to installed plugins, and detects inactive or missing plugin dependencies.

Designed with strict relational safety and disaster recovery principles, the plugin permanently protects WordPress Core tables, locks active plugin tables, and provides streaming compressed `.zip` database backups prior to performing any maintenance.

### Key Capabilities

#### 🗄️ 1. Database Audit & Optimization
* **Orphaned Table Detector**: Identifies lingering database tables from uninstalled plugins using signature recognition across 30+ popular WordPress plugin ecosystems.
* **Autoload Size Analysis**: Calculates total autoloaded options weight and flags bloated options slowing server response times (TTFB).
* **1-Click Transients & Revisions Cleaner**: Safely purge expired transient cache entries and trim historic post revisions beyond the last 3 edits.
* **Compressed Backup Generation**: Stream raw SQL or compressed `.zip` exports of full database dumps or individual tables directly to your browser prior to any destructive operation.
* **Permanent Core Protection**: WordPress Core tables (`wp_posts`, `wp_options`, `wp_users`, etc.) and tables belonging to active plugins are permanently locked against dropping.

#### 🖼️ 2. Media Files & Safe Quarantine System
* **Contextual Placement Scanner**: Cross-references every media attachment against published posts, pages, custom post types, `wp-image-{id}` markup, featured images (`_thumbnail_id`), WooCommerce galleries, site icons, and custom logos.
* **Thumbnail Sub-Size Accounting**: Computes the exact on-disk footprint of original uploaded images along with all registered thumbnail variations (`-150x150`, `-300x300`, `-1024x1024`, etc.).
* **Safe Quarantine Isolation**: Moves unlinked media and all associated thumbnails to `/wp-content/uploads/sba-quarantine/` with execution blocked (`.htaccess` and `index.php`). Quarantined files are safely hidden from the WordPress Media Library without permanent deletion.
* **Selective Batch Operations**: Select specific quarantined items using checkboxes to restore them back to the Media Library or permanently delete them to reclaim disk storage.
* **Dimension & Overhead Benchmark**: Compares image dimensions against responsive container benchmarks (1920px for hero images, 1200px for content) and estimates WebP compression savings.

#### 🔌 3. Plugin Storage & Hygiene Audit
* **Disk & Database Linked Footprint**: Shows on-disk folder size alongside the exact database footprint (e.g. `Disk: 15.83 MB | DB: 4 Tables (340 KB)`) for every installed plugin.
* **WordPress.org Directory Verification**: Queries the official WordPress.org Plugin API to verify plugin status, active support, and last updated timestamps (cached for 7 days via WordPress transients).
* **Missing Dependency Detection**: Identifies plugins missing required parent plugins (e.g. WooCommerce add-ons when WooCommerce is deactivated) and flags them as `Dependency Inactive` to prevent silent memory overhead.
* **Self-Plugin Safety Recognition**: Recognizes Site Bloat & Storage Auditor as a safe, local development tool (`Local Plugin (This Auditor) / Local Active`) without false-positive security warnings.
* **Security Risk Scoring**: Flags unverified, commercial, or abandoned plugins (2+ years without an update) posing maintenance or security vulnerabilities.

#### 🛡️ 4. WordPress.org Security & Compliance
* **Strict Permission Checks**: Every scan, cleanup, export, and quarantine action requires `manage_options` administrative capabilities.
* **Cryptographic Nonces**: All AJAX endpoints enforce strict `check_ajax_referer('sba_nonce')` verification.
* **Zero Leftover Clutter**: Full `uninstall.php` routine removes all transients, options, and temporary quarantine directories upon plugin deletion.

== Installation ==

1. Upload the `site-bloat-auditor` directory to your `/wp-content/plugins/` directory, or install the plugin zip directly via **Plugins > Add New > Upload Plugin** in the WordPress admin.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Tools > Site Bloat Auditor** to run your first database, media, or plugin audit.

== Frequently Asked Questions ==

= Is it safe to drop orphaned tables? =
Yes. Orphaned tables belong to plugins that have already been deactivated or deleted from your site. Furthermore, WordPress Core tables and active plugin tables are permanently locked against dropping. For maximum safety, you can download an individual compressed `.zip` backup of the table directly from the confirmation modal before dropping it.

= How does the safe media quarantine system work? =
Instead of permanently deleting files immediately, Site Bloat Auditor moves unlinked images and all generated thumbnail variations into a protected directory (`/wp-content/uploads/sba-quarantine/`) and assigns them a custom `sba-quarantine` post status. This isolates the files from public view and Media Library queries while keeping them completely intact on disk.

= Can I restore quarantined files? =
Yes! From the Media Files Audit tab, switch to the "Quarantined" filter, select any files, and click "Restore Selected" or click the row-level "Restore" button. The plugin moves the original file and all thumbnail variations back to their exact upload folders and restores the attachment status.

= What does the missing dependency check do? =
It checks plugin headers (such as the WordPress 6.5+ `Requires Plugins` header) and known extension signatures (like WooCommerce or Elementor add-ons). If a plugin is active or installed but its parent plugin is deactivated, it warns you that the plugin is consuming server memory without functioning.

= How do I take a full database backup? =
Click the "Export Compressed (.zip)" button in the Database Safety & Full Backup card at the top of the Database tab. The plugin generates a compressed `.zip` archive containing the full SQL dump and streams it directly to your browser without leaving temporary files on your server.

= What happens when I purge expired transients? =
Transients that have passed their expiration timestamp are safely deleted from `wp_options`. Active transients and non-transient options remain untouched.

= What does the post revision cleaner do? =
It removes older revisions while preserving the 3 most recent edits for each post and page, ensuring you retain undo history while freeing up database rows.

= Are any external services or tracking scripts included? =
No. Site Bloat Auditor contains zero telemetry, zero analytics, and zero external tracking. The only outbound request occurs during the Plugin Audit to check the official `api.wordpress.org/plugins/info/1.2/` endpoint, and responses are cached locally for 7 days.

== Screenshots ==

1. **Database Audit**: Summary KPI cards (Autoload Size, Post Revisions, Expired Transients, Tables Count), 1-click cleaners, and full compressed database backup.
2. **Database Table Health & Orphaned Detector**: Filterable table list categorized by Core, Active Plugin, and Orphaned status with plugin signature detection.
3. **Media Files Audit**: Dimension benchmark analysis, multi-placement tracking, and thumbnail sub-size storage calculations.
4. **Safe Media Quarantine**: Quarantined items overview with batch selection checkboxes, 1-click restoration, and permanent trash emptying.
5. **Plugin Storage & Authenticity Audit**: Linked file and database footprints, official WordPress.org repository verification, and missing dependency detection.

== Changelog ==

= 1.1.0 =
* Media Optimization Engine: Added recoverable image optimization with verified backups (.sba-orig), image decode verification, and atomic rollback.
* Duplicate Media Detection: File-size pre-filtering before hashing, unreferenced duplicate candidate classification, and keeper copy protection.
* Strict Storage Accounting: Separated measured media storage, potentially reclaimable storage, estimated optimization savings, backup storage consumed, and verified reclaimed space.
* Physical Path Deduplication: Normalized physical file path deduplication to prevent double-counting shared files across unreferenced and duplicate lists.
* Post Revisions Analysis: Detailed storage usage breakdown (bytes and KB) with interactive modal for individual post revision review and selective deletion.
* Accessibility Audit: Comprehensive alt text auditing differentiating empty decorative attributes from missing or repetitive descriptions.
* Windows PHP Compatibility: Resilient cross-platform stream readability checks resolving Windows ACL false-negatives.

= 1.0.0 =
* Initial public release on WordPress.org.
* Database Audit Engine: Orphaned table detector with 30+ plugin signatures, autoload size calculator, expired transient purger, and post revision cleaner.
* Relational Protection: Strict WordPress Core and active plugin table guardrails.
* Database Backups: Streaming compressed `.zip` exports for full database and individual tables.
* Media Storage Audit Engine: Placement tracking across posts, pages, featured images, and theme branding.
* Safe Media Quarantine System: Isolated storage in `/wp-content/uploads/sba-quarantine/` with batch checkboxes, restoration, and permanent deletion.
* Image Dimension & Overhead Analysis: Proportional resizing recommendations and WebP compression savings estimator.
* Plugin Hygiene Engine: Linked Disk & DB footprint analysis, WordPress.org verification, missing dependency checks, and self-plugin recognition.
* Complete `uninstall.php` cleanup script ensuring zero leftover database or filesystem clutter.
* Full localization support with strict WordPress.org security compliance.
