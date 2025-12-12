=== Henkan - WebP & AVIF Converter ===
Contributors: suzuchan
Tags: webp, avif, image optimization, converter, performance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 1.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Henkan is a professional, lightweight image optimization plugin that converts images to WebP and AVIF.

== Description ==

Henkan is a professional, lightweight image optimization plugin for WordPress. It automatically converts your images to modern formats (**WebP** and **AVIF**) to improve page load speeds and Core Web Vitals scores.

Unlike simple converters, Henkan offers a robust set of tools including a smart bulk scanner, WP-CLI integration for power users, frontend `<picture>` tag replacement, and automatic cache clearing.

**Key Features**

* **Next-Gen Formats:** Convert JPG/PNG images to **WebP** and **AVIF**.
* **Dual Conversion Engine:** Uses System Binary (cwebp) if available, falls back to GD Library.
* **Smart Frontend Delivery:** Automatically rewrites `<img>` tags to `<picture>` tags.
* **Native Lazy Loading:** Adds `loading="lazy"` attributes automatically.
* **Bulk Optimization:** Scans Media Library and custom folders.
* **WP-CLI Integration:** Full support for command-line management.
* **Cache Integration:** Automatically flushes caches (WP Rocket, W3TC, Autoptimize, etc.).

== Installation ==

1.  Upload the plugin files to the `/wp-content/plugins/henkan` directory, or install the plugin through the WordPress plugins screen.
2.  Activate the plugin through the 'Plugins' screen in WordPress.
3.  Navigate to **Settings > Henkan** to configure your compression quality and enabled formats.
4.  Run a "Scan" from the settings page or use WP-CLI to generate your initial WebP/AVIF files.

== Screenshots ==

1.  **Dashboard:** The main settings and statistics overview.
2.  **Bulk Tool:** The bulk conversion progress interface.

== Changelog ==

= 1.8 =
* New Feature: Added Dashboard Widget with optimization stats.
* New Feature: Added Admin Bar menu for quick access.

= 1.7 =
* Final compliance fixes for WordPress.org standards.
* Improved GitHub Updater integration (Release Assets support).

= 1.6 =
* Added WP-CLI support.
* Added AVIF support.
* Improved bulk scanning logic.
* Added support for custom folder scanning.
* Added automatic cache clearing for popular caching plugins.

= 1.0 =
* Initial release.
