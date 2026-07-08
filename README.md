=== Henkan - WebP & AVIF Converter ===
Contributors: Saguya
Tags: webp, avif, image optimization, pagespeed, converter
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 2.3.2
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
* **Smart Fallbacks:** Uses ultra-fast `exec()` binaries when available, falling back to GD or ImageMagick seamlessly if needed.
* **Cache Auto-Clear:** Fully compatible with WP Rocket, W3 Total Cache, Autoptimize, and LiteSpeed Cache. Conversions automatically trigger a cache purge.
* **Frontend Delivery:** Automatically serves the optimized images to compatible browsers using modern HTML `<picture>` tags or Nginx/Apache rewrites.
* **WP-CLI Power:** Built-in commands (`wp henkan convert`) for sysadmins to process massive libraries directly from the terminal without PHP timeouts.
* **Global Ready:** 100% translation-ready. Shipped with English, German (`de_DE`), and Japanese (`ja`) out of the box.

### 🛠️ System Requirements

To unleash the full speed of Henkan, we recommend:
* **WordPress:** 6.9 or higher
* **PHP:** 8.4 or higher
* **For WebP:** `cwebp` installed on the server (recommended) OR PHP `GD` extension with WebP support.
* **For AVIF:** `avifenc` or `ImageMagick` (magick/convert) installed OR PHP `GD` extension with AVIF support.

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
* Force convert everything: `wp henkan convert --force`

== Screenshots ==

1. The beautiful, modern Henkan Dashboard showing your optimization stats.
2. Bulk conversion in progress with a sleek progress circle.
3. Seamless integration into the WordPress Media Library.

== Changelog ==

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