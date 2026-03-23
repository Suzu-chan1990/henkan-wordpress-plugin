=== Henkan - WebP & AVIF Converter ===
Contributors: suzuchan
Tags: webp, avif, image optimization, pagespeed, converter
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 2.0.3
Requires PHP: 7.4
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
* **WordPress:** 6.0 or higher
* **PHP:** 7.4 or higher
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

= 2.0.4 =
* Bumped minimum requirements to PHP 7.4 and WordPress 6.0 for better security and native binary performance.
* Improved Readme layout and added FAQ section.

= 2.0.3 =
* Initial release on the official WordPress.org Plugin Directory.
* Full i18n Overhaul & WP.org Compliance.
* Strict settings sanitization and security patches applied.