=== Henkan - WebP & AVIF Converter ===
Contributors: Saguya
Tags: webp, avif, image optimization, pagespeed, converter
Requires at least: 7.1
Tested up to: 7.2
Stable tag: 2.6.3
Requires PHP: 8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Henkan is a high-performance plugin that automatically converts your media library images to next-generation formats like WebP and AVIF.

== Description ==

Welcome to **Henkan** (Japanese for "conversion") — your lightweight, high-performance solution for next-generation image optimization in WordPress! 

Slow loading times and heavy images are a thing of the past. Henkan seamlessly integrates into your WordPress dashboard, silently converting your standard JPEGs and PNGs into highly optimized **WebP** or **AVIF** files. Boost your Core Web Vitals, improve your SEO rankings, and save massive amounts of server bandwidth without sacrificing an ounce of image quality.

### ✨ Why choose Henkan?

Most image optimizers rely on slow PHP processing or expensive cloud APIs with monthly limits. Henkan is built differently: It prioritizes **native OS binaries** (`cwebp`, `avifenc`) to process images blazingly fast directly on your own server, completely free and without any restrictions.

### 🚀 Powerful Features

* **Next-Gen Formats:** Instantly convert images to WebP and AVIF formats.
* **Zero-Config Auto-Conversion:** New images are optimized silently in the background the moment you upload them.
* **1-Click Bulk Optimization:** Got a massive existing media library? Process thousands of images with a single click in our beautiful, modern dashboard.
* **Smart Fallbacks:** Uses fast command line converters (`cwebp`, `avifenc`, `cjxl`, ImageMagick) started without a shell and with a timeout, falling back to GD or the Imagick PHP extension if needed.
* **Size Check:** Converted files that are not smaller than the original are dropped automatically.
* **Undo:** Revert single images, a selection or everything – originals are never touched.
* **Site Health:** Shows which converter works for each format and whether browsers really receive the optimized images.
* **Cache Auto-Clear:** Works with WP Rocket, W3 Total Cache, WP Super Cache, Autoptimize, LiteSpeed Cache, Kyasshu, WP Fastest Cache, Cache Enabler, Breeze, SiteGround Speed Optimizer, Hummingbird, Comet Cache, Swift Performance, WP-Optimize, Nginx Helper and WP Engine. Conversions automatically trigger a cache purge.
* **Frontend Delivery:** Automatically serves the optimized images to compatible browsers using modern HTML `<picture>` tags (post content and featured images) or Nginx/Apache rewrites.
* **Media Offloading:** Works with Advanced Media Offloader (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO …): the WebP/AVIF files are offloaded with the image and delivered from the bucket with `<picture>`.
* **WP-CLI Power:** Built-in commands (`wp henkan convert`, `revert`, `stats`, `repair`) for sysadmins to process massive libraries directly from the terminal without PHP timeouts.
* **Global Ready:** 100% translation-ready. Shipped with English, German (`de_DE`), and Japanese (`ja`) out of the box.

### 🛠️ System Requirements

To unleash the full speed of Henkan, we recommend:
* **WordPress:** 7.1 or higher
* **PHP:** 8.4 or higher
* **For WebP:** `cwebp` installed on the server (recommended) OR PHP `GD` extension with WebP support.
* **For AVIF:** `avifenc` (1.0 or newer) or `ImageMagick` (magick) installed OR PHP `GD` extension with AVIF support OR the PHP `Imagick` extension with AVIF support.
* Command line converters need `proc_open()` (or `exec()`) to be enabled. Without them, GD or Imagick is used.

== Installation ==

1. Download the latest plugin zip from the repository.
2. Go to your WordPress Dashboard: **Plugins > Add New > Upload Plugin**.
3. Upload the zip file and click **Install Now**.
4. Click **Activate Plugin**.
5. Navigate to **Settings > Henkan** in your dashboard.
6. Choose your preferred format (WebP or AVIF), set the quality (we recommend 82), and click **Start Scan** to optimize your existing library!

== Frequently Asked Questions ==

= Does it delete my original images? =
By default, no! Henkan keeps your original JPEGs and PNGs safely stored. You can easily toggle this behavior in the settings if you want to aggressively save disk space. 
**Note: If you choose to delete the originals, the newly generated optimized file (e.g., WebP or AVIF) will officially replace the old JPEG/PNG as the primary attachment in your WordPress database.**

= Do I need to pay for an API or subscription? =
Absolutely not. Henkan processes everything locally on your own server. No cloud limits, no monthly fees, completely free and open-source.

= How do I use the WP-CLI commands? =
For large media libraries, using the terminal is highly recommended. 
* Scan and convert all missing images: `wp henkan convert`
* Force convert everything: `wp henkan convert --force --limit=-1`
* Only some images or one format: `wp henkan convert --ids=12,34 --format=avif`
* Undo: `wp henkan revert --ids=12,34` or `wp henkan revert --all --yes`
* Statistics: `wp henkan stats`

= Can I undo the conversion? =
Yes, as long as the original JPEG/PNG exists: use "Revert" in the media library (list view), the bulk action, "Revert all conversions" in the Advanced tab or `wp henkan revert`. If "Keep Originals" was off, the converted file is the only copy of the image and is never deleted.

= Why was an image not converted ("Original kept")? =
The converted file was not smaller than the original (this happens with small or already heavily compressed images). Henkan then keeps serving the original. You can turn this check off in General > Encoder.

= Does Henkan work with media offloading (S3, R2, Backblaze B2)? =
Yes, with Advanced Media Offloader. Henkan lists its WebP/AVIF files in the image metadata, and the offloader uploads and deletes them together with the image. Turn on "Frontend <picture>" in Advanced: rewrite rules cannot run on cloud storage. Convert your library with Henkan **before** offloading it; images that are already offloaded are not converted again.

= Which hooks are available for developers? =
Actions: `henkan_converted` ($id, $size_name, $file, $format, $source), `henkan_attachment_converted` ($id, $result), `henkan_reverted` ($id, $removed_files), `henkan_cache_cleared`.
Filters: `henkan_quality` ($quality, $format, $size_name, $id, $source), `henkan_encoder_args` ($args, $method, $format, $source, $dest, $quality), `henkan_settings`, `henkan_picture_enabled`, `henkan_max_pixels`, `henkan_allow_custom_root`, `henkan_offloader_active`, `henkan_is_offloaded` ($offloaded, $id), `henkan_write_sources`.

== Screenshots ==

1. The beautiful, modern Henkan Dashboard showing your optimization stats.
2. Bulk conversion in progress with a sleek progress circle.
3. Seamless integration into the WordPress Media Library.

== Changelog ==

= 2.6.3 =
* **Media offloading (Advanced Media Offloader):** Henkan now lists its WebP/AVIF/JXL files in the attachment metadata (`sources`, the format of Modern Image Formats). Advanced Media Offloader uploads them to the bucket together with the image (upload, bulk offload, WP-CLI) and deletes them again with "Mirror Delete". No change in the offload plugin is needed.
* **`<picture>` for offloaded images:** Images served from the bucket/CDN get AVIF/WebP `<source>` elements with the bucket URL. Only files that were uploaded together with the image are listed, so an image offloaded before 2.6.3 keeps its JPEG/PNG instead of a broken link. Server rewrite rules cannot run on cloud storage: turn on "Frontend <picture>" (Henkan and Site Health show a notice).
* **Convert first, then offload:** Images that are already offloaded are no longer converted or reverted, because the new files would only exist on the server. Convert your library with Henkan first, then run the bulk offload.
* **Older conversions:** A one-time background job adds the `sources` entries to images converted by earlier versions (not yet offloaded ones), so a later bulk offload takes their WebP/AVIF files along.
* **Integrity check:** No longer deletes the Henkan data of offloaded images whose local files were removed by the offload plugin.
* Henkan does not write `sources` while Modern Image Formats is active (both would manage the same entries). New filters: `henkan_offloader_active`, `henkan_is_offloaded`, `henkan_write_sources`.

= 2.6.2 =
* **More cache plugins:** "Auto clear cache" now also detects and purges Kyasshu, WP Fastest Cache, Cache Enabler, Breeze, SiteGround Speed Optimizer, Hummingbird, Comet Cache, Swift Performance, WP-Optimize, Nginx Helper and WP Engine (besides WP Rocket, W3 Total Cache, WP Super Cache, Autoptimize and LiteSpeed Cache). Each one is purged through its own public function or hook.
* The dashboard shows every active cache plugin Henkan purges, not only the first one.
* A cache plugin whose purge fails no longer stops the purge of the others (the error is written to the debug log when WP_DEBUG_LOG is on).

= 2.6.1 =
* **WebP quality settings:** New "WebP effort" (cwebp `-m`, 0-6) and "sharp colored edges" (cwebp `-sharp_yuv`) in General > Encoder. Effort 6 gives better quality at the same file size; sharp_yuv keeps colored lines and text crisp. Both make the conversion slower. Defaults (effort 2, sharp_yuv off) keep the previous output.
* **Bulk Optimizer:** With "Keep Originals" off, the folder scan also listed JPEGs of media-library images that the same run converted and deleted moments before. These ended with "Error: Path not allowed"; they are now reported as "already converted".

= 2.6.0 =
### Fixes
* **AVIF quality:** avifenc 1.0+ reads `-q` as quality 0-100, but Henkan converted the quality to the old 0-63 quantizer scale first. Quality 82 became `-q 11`, so AVIF files made with avifenc were heavily over-compressed. The quality is now passed unchanged.
* **Nginx rules:** The old rules looked for `image.jpg.webp`, but Henkan writes `image.webp`, so they never delivered a converted file. New rules with one `map` per format; a missing AVIF now falls back to WebP. Replace the old rules on your server.
* **One delivery order everywhere:** `<picture>`, Apache and Nginx rules now all prefer AVIF > WebP > JPEG XL (the rules used JXL first, the changelog said WebP first).
* **Lazy-Loading:** Henkan no longer adds `loading="lazy"` on top of WordPress core. It only adds it when core lazy-loading is turned off, and then skips the first images and `fetchpriority="high"` images, so the LCP image is never delayed.
* **Featured images:** `<picture>` is now also used for featured images and every `wp_get_attachment_image()` output (themes, blocks, widgets). Can be turned off in Advanced. Feeds are left alone.
* **Uploads:** New uploads now use the same pipeline as bulk conversion, so Max Output Width and Quality by Image Size also apply to uploads.
* **Bulk "Force":** Re-encodes images that are already converted (before, existing files were kept and "Force" did nothing). Only images whose original JPEG/PNG still exists are re-encoded.
* **Space saved:** The statistic in Smart Options was never updated. Henkan now counts the saved bytes per converted file; `wp henkan stats --recalculate` measures existing files.
* **`wp henkan stats`:** The command was documented but missing; it is now available.
* **LiteSpeed Cache:** Purges through the current `litespeed_purge_all` hook (the old API class only as fallback).
* Failed converters no longer leave partial output files behind.

### Converter Engine
* **No shell:** Command line converters are started with `proc_open()` and an argument array instead of `exec()`. `exec()` is only used as a fallback when a host disables `proc_open()`.
* **Timeout:** A hanging converter is killed after the "Converter timeout" (default 120 s) and the next converter is tried.
* **Real error messages:** The converter's own error output is stored and shown in the log and the media library.
* **Imagick extension:** New converter "Imagick (PHP extension)" for WebP, AVIF and JPEG XL, without starting a process. "ImageMagick (CLI)" is the former "ImageMagick (exec)" option.
* **Size check:** If a converted file is not smaller than the original, it is dropped and the original is served (setting, on by default). These images get the status "Original kept" and are not scanned again.
* **Encoder options:** Lossless WebP/JPEG XL for PNG, keep EXIF/XMP, AVIF speed (0-10), encoder threads.

### Media Library
* **Row actions:** "Henkan: Re-convert", "Re-convert with quality…" and "Revert" in the list view.
* **Bulk actions:** "Henkan: Convert", "Henkan: Re-convert (force)" and "Henkan: Revert".
* **Status column:** Shows the created formats, "Failed" with the error as tooltip, and "Original kept".

### Revert
* **Revert all** in Advanced deletes the WebP/AVIF/JXL files of all images whose original JPEG/PNG still exists. A converted file is only ever deleted when its original is next to it, so images converted with "Keep Originals" off are never touched.

### Site Health
* New tests "Henkan image converters" (which converter works per format) and "Henkan image delivery" (checks `<picture>` or makes a test request to see whether the server rewrite rules work).
* New "Henkan" section in Site Health > Info with converters, binaries, GD/Imagick support and settings.

### WP-CLI
* `wp henkan convert`: new `--ids`, `--format`, `--quality` and `--dry-run`; `--limit=-1` for all; summary with converted / kept / failed.
* `wp henkan revert --ids=… | --all [--yes] [--dry-run]`
* `wp henkan stats [--recalculate]`

### For Developers
* Actions: `henkan_converted`, `henkan_attachment_converted`, `henkan_reverted`, `henkan_cache_cleared`.
* Filters: `henkan_quality`, `henkan_encoder_args`, `henkan_settings`, `henkan_picture_enabled`.

= 2.5.1 =
### Fixes
* **Plugin images:** The 2.5.0 download contained an extra `header-banner.png` and the old banner and icon. The plugin now ships exactly two images, `assets/banner-772x250.png` (new banner) and `assets/icon-256x256.png` (new icon), and the settings page header shows `banner-772x250.png`.
* **Smart Options / WP-CLI:** The command boxes use the same light blue-to-mint background as the header instead of black.

= 2.5.0 =
### New Admin Design
* **New look:** The settings page has a new design in the style of the other Saguya plugins: light blue-to-mint header with the Henkan banner and version, stat cards, tab bar, cards for every section and toggles, buttons and progress ring in the Henkan colors (blue to emerald).
* **Bulk optimization:** Shows "x of y images optimized" below the progress ring; the log is shown as a console.
* **Server rewrites and WP-CLI:** Rules and commands are shown in dark code boxes; the Nginx rules are labeled with the block they belong in (`http` / `server`). `wp henkan repair` is listed in the WP-CLI tab.
* **Notices:** "Settings saved" and other notices are shown below the header.
* **Text:** The exclusions hint (now translatable) and the scan/queue status messages were German and are now English; all code comments are English.
* The version in the page header comes from the plugin version (it showed 2.4.1).
* The settings, their names and the conversion itself are unchanged.

### Compatibility
* **Requires at least:** WordPress 7.1 (plugin header, readme and requirements).
* **Tested up to:** WordPress 7.2.

= 2.4.2 =
### Fixes
* **Broken thumbnails with "Keep original" off:** Bulk conversion (media library, WP-CLI, background queue) deleted the JPEG/PNG sizes but left them in the attachment metadata, so WordPress linked deleted files (404). The metadata now points to the converted files. Sites already affected are repaired automatically in the background after the update (or with `wp henkan repair`).
* **`<picture>` for all image sizes:** Images inserted in a size other than "full" (WordPress inserts "large" by default) were not wrapped. The `<source>` now also carries the full responsive `srcset` and `sizes` of the image instead of a single file, so mobile devices no longer load the largest image. Existing `<picture>` elements are left alone and single-quoted attributes are supported.
* **Settings:** Backslashes in exclusions and folder paths were removed on save (regex `/\.min\./` became `/.min./`).
* **WP-CLI:** `wp henkan scan` reported at most one image.
* **Large upload folders:** The folder scan now runs in time-boxed steps instead of one request, so it no longer hits PHP timeouts.
* Files listed by the folder scan that were already converted via their media-library entry are no longer reported as "Not found".
* Converter binaries are checked once per request instead of once per image.
* Invalid AVIF converter values fall back to GD, the same default as on a fresh install.

### Security / Hardening
* Custom scan folders must be inside the WordPress installation, `wp-content` or the uploads folder (filter `henkan_allow_custom_root` for other layouts). Ignored folders are shown on the settings page.
* JPEG XL uploads are only allowed when JXL is enabled in Henkan.
* Deleting an attachment only removes plain file names from the Henkan data (no paths).
* New `uninstall.php` removes the Henkan table, options, post meta and cron events. Converted images are kept on purpose.

### Code Quality (Plugin Check)
* `unlink()` replaced with `wp_delete_file()`.
* Table names in SQL queries are passed with the `%i` identifier placeholder of `$wpdb->prepare()`, including the one-time post meta migration.
* Necessary direct queries on the plugin's own table and the attachment paging are documented with `phpcs:ignore` comments.
* Consistent CRLF line endings in all PHP files; prefixed global variable in `uninstall.php`.

= 2.4.1 =
### Security
* **XSS fix:** Bulk log messages are now returned as plain text and rendered with `.text()`, so file names can no longer inject HTML/JS into the admin screen.
* **File type validation:** AVIF/JXL uploads are verified by their file signature instead of trusting the extension.
* **Path allowlist:** Single-file conversion requests are restricted to the configured scan folders. On multisite only super admins can change custom scan paths.
* **Safe temp files:** Resize temp files now use unguessable names (`wp_tempnam`).
* **Decompression bomb protection:** Images are validated (MIME type and pixel count, filter `henkan_max_pixels`) before any decoding, including the resize step.
* **CSRF:** The cache-clear AJAX action now requires a nonce.
* **Object injection:** Stored conversion data is unserialized with `allowed_classes => false`.
* **Frontend:** `<picture>` sources are escaped with `esc_url()`, only emitted for JPEG/PNG uploads, and only when the converted file exists.
* **Dashboard widget:** Only visible to users with `manage_options`.

### Fixes
* Smart Options are no longer reset when saving the General, Folders or Advanced tabs.
* The folder scan runs once per bulk scan instead of on every attachment page (no more duplicates).
* The "Resume" button now works and progress is persisted.
* Stale background worker locks are recovered after 15 minutes.
* Cron events are removed on deactivation and scheduled on first settings save.
* Fixed `|` instead of `||` in the WP-CLI guard.
* Aligned the "Requires at least" version (WordPress 7.0) across plugin header, readme and requirements.

= 2.4.0 =
### Security
* **Enhanced image MIME validation:** Added strict server-side image type verification using `mime_content_type()` with `exif_imagetype()` fallback.
* **Fail-closed image processing:** Files are rejected when no reliable image type verification method is available.
* **Conversion locking:** Added atomic per-attachment conversion locks to prevent concurrent conversion of the same image and generated sizes.
* **Stale lock recovery:** Conversion locks that remain active after an unexpected process termination can be safely recovered.
* **Lock ownership validation:** Processes can only release locks that they own.
* **Integrity check locking:** Prevented multiple integrity checks from running concurrently.

### Bulk Optimization
* **Cursor-based media scanning:** Replaced unbounded media-library queries with bounded cursor/keyset pagination.
* **Reduced memory usage:** Large media libraries are no longer loaded into PHP memory in a single request.
* **Improved AJAX bulk processing:** Bulk scans now process images in controlled batches and return a continuation cursor.
* **Improved "Only missing" detection:** Conversion status is now verified against the actual files on disk instead of relying solely on database records.
* **Multi-format verification:** Missing WebP, AVIF, or JPEG XL files are detected independently when multiple output formats are enabled.

### Background Processing
* **Real background queue worker:** Added a bounded WP-Cron worker for background bulk optimization.
* **Queue locking:** Prevented multiple background workers from processing the same queue simultaneously.
* **Cursor-based queue:** The background queue stores a lightweight attachment cursor instead of maintaining a potentially massive list of attachment IDs.
* **Bounded worker execution:** Each worker run processes a limited number of attachments to reduce PHP execution time and memory pressure.
* **Automatic queue continuation:** Remaining work is automatically scheduled for subsequent WP-Cron runs.
* **Safe queue completion:** Queue state is persisted and finalized cleanly after the last batch.
* **Automatic cache cleanup:** Relevant caches are cleared after successful queue completion when enabled.

### WP-CLI
* **Keyset pagination:** Replaced offset-based pagination with ID-based cursor pagination.
* **Improved large-library performance:** WP-CLI bulk processing no longer becomes progressively slower as the attachment offset increases.
* **Bounded processing:** CLI operations now use controlled batch sizes.

### Database & Integrity
* **Chunked integrity checks:** Henkan data is now checked in bounded batches instead of loading the complete `wp_henkan_data` table into memory.
* **Reduced database memory pressure:** Large Henkan installations can perform integrity checks without creating oversized PHP result sets.
* **Improved missing-file detection:** Integrity checks can identify missing converted files more reliably.

### Web Server Delivery
* **Deterministic format priority:** Updated generated Nginx configuration to use the documented format priority:
  `WebP > AVIF > JPEG XL`.
* **Improved multi-format handling:** Format availability is evaluated independently rather than treating the presence of one generated format as sufficient.

= 2.3.3 =
* **Security:** Added strict server-side MIME-type verification (using `mime_content_type` and `exif_imagetype`) to the image creation process. This prevents malicious file uploads disguised as images from reaching the CLI fallback converters, effectively mitigating the CVE-2026-65640 vulnerability (Imagick/Ghostscript RCE).
* **Changed:** Bumped the "Requires at least" WordPress version to 7.0.4 to align with core security standards and ensure safe environment execution.

= 2.3.2 =
* **Fix Readme

= 2.3.0 =
* **New tab: Smart Options. A dedicated "Smart Options" tab has been added to the plugin dashboard. It groups all optional, advanced features behind individual toggles that are disabled by default. Existing setups are completely unaffected unless a feature is explicitly activated.
* **Smart Option: Quality by Image Size. Allows a separate, lower quality value to be applied to WordPress-generated thumbnail crop sizes (e.g. thumbnail, medium, medium_large) while the global quality setting continues to apply to full-size originals. The feature uses wp_get_registered_image_subsizes() to determine whether a given image size is an actual WordPress crop — custom single-resolution setups where WordPress thumbnail generation is not used are not affected even if the toggle is enabled.
* **Smart Option: Integrity Check. Adds a daily WP-Cron job that verifies whether converted files recorded in the wp_henkan_data table still physically exist on disk. Any entry whose files are no longer present is removed from the database, causing the image to be re-queued for conversion on the next bulk scan. The Smart Options tab displays the date of the last run and the number of missing files found. Particularly useful after server migrations or accidental file deletions.
* **Smart Option: Max Output Width. Optionally limits the pixel width of converted output files. If an uploaded image exceeds the configured maximum width, it is scaled down proportionally before conversion. The original file is never modified — only the converted WebP/AVIF/JXL output is resized. Aspect ratio is always preserved. Images already narrower than the configured value are not affected.
* **Conversion Statistics panel. A new read-only statistics panel in the Smart Options tab shows the total number of converted images and the cumulative disk space saved. Also introduces the wp henkan stats WP-CLI command for the same information from the terminal.
* **New WP-CLI command: wp henkan stats. Outputs a summary of total converted images and space saved directly from the terminal.

= 2.2.0 =
* **Tested up to:** WordPress 7.0
* **New:** Full compatibility with WordPress 7.0 architectural changes.
* **New:** High-performance database architecture. Migrated conversion data from `wp_postmeta` to a dedicated custom table (`wp_henkan_data`). This significantly speeds up backend load times and reduces database queries for large media libraries. The migration runs seamlessly and automatically in the background.
* **New:** Asynchronous Background Queue for Bulk Optimization. You can now run massive conversion tasks safely in the background via WP-Cron without keeping the browser tab open. This feature can be toggled in the Advanced Settings.
* **New:** Granular Exclusion Rules. You can now exclude specific files, paths, or naming patterns (e.g., logos, specific folders) from conversion using simple string matching or Regex.
* **Tweak:** Improved resource management and cleanup routines. The plugin now automatically purges the associated database rows in the custom table when an attachment is permanently deleted.

= 2.1.1 =
* **Fix:** Added native WordPress Media Library support for AVIF and JXL formats.
* **Fix:** Added an admin grid fallback to display WebP/JPEG thumbnails if the browser does not support JXL natively.

= 2.1.0 =
* **Feature:** Multi-Format Engine! You can now generate WebP, AVIF, and JXL simultaneously for every uploaded image.
* **Feature:** Added experimental support for JPEG XL (.jxl) via `cjxl` and ImageMagick.
* **UI/UX:** Completely redesigned the Admin Dashboard. All settings, Server Rewrites, and WP-CLI commands are now unified into a clean, single-card tabbed interface.
* **Engine:** Introduced an intelligent Auto-Fallback system. If native OS binaries (`avifenc`, `cwebp`) fail or are blocked by your host, Henkan automatically falls back to PHP GD or ImageMagick.
* **Engine:** Strict format priority (WebP > AVIF > JXL) ensures maximum browser compatibility for the main WordPress media library files.
* **Core:** Added detailed, transparent error logging directly in the bulk optimization UI to help identify missing server binaries.
* **Compliance:** Achieved 100% strict compliance with WordPress Coding Standards (WPCS) and security escaping rules.

= 2.0.4 =
* Bumped minimum requirements to PHP 7.4 and WordPress 6.0 for better security and native binary performance.
* Improved Readme layout and added FAQ section.

= 2.0.3 =
* Initial release on the official WordPress.org Plugin Directory.
* Full i18n Overhaul & WP.org Compliance.
* Strict settings sanitization and security patches applied.