=== Henkan - WebP & AVIF Converter ===
Contributors: suzuchan
Tags: webp, avif, image optimization, converter, performance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Henkan is a professional, lightweight image optimization plugin that converts images to WebP and AVIF.

== Description ==

Henkan is a professional, lightweight image optimization plugin for WordPress. It converts your images to modern formats (**WebP** or **AVIF**) to improve page load speeds and Core Web Vitals scores.

Unlike simple converters, Henkan offers a robust set of tools including a smart bulk scanner, WP-CLI integration for power users, frontend `<picture>` tag replacement, and automatic cache clearing.

**Key Features**

* **User-driven format choice:** Choose **WebP** *or* **AVIF** as your target format (no automatic switching).
* **Optional original handling:** Keep original JPG/PNG files, or delete them after conversion.
* **Converter selection (strict):** Pick the encoder you want to use (if available on your system). No hidden fallbacks.
* **Missing-only bulk mode:** Convert only files that are not yet converted to the chosen format.
* **Resumeable bulk jobs:** Continue bulk conversions after a page reload (no lost progress).
* **Conversion state tracking:** Clear status per attachment (OK/Failed) with an error reason for troubleshooting.
* **Smart Frontend Delivery:** Optionally rewrites `<img>` tags to `<picture>` tags for best browser support.
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

== Upgrade Notice ==

= 2.0.0 =
This is a major update. Review your format and converter settings after upgrading.

== Changelog ==

= 2.0.0 =
* New: Strict single-format workflow — user chooses WebP **or** AVIF (no auto-mode, no dual-output).
* New: Converter selection per format (when available on the system). No hidden fallbacks.
* New: Missing-only bulk conversions (skip already-converted targets).
* New: Resumeable bulk conversions in admin (continue after page reload).
* New: Conversion state tracking (OK/Failed) with stored error reason.
* Improved: Bulk scan options incl. “only failed” for targeted retries.

= 1.9.2 =
* Fixed: Percentage calculation for libraries with pre-existing WebP/AVIF images.
* Added: Professional branding assets.

= 1.9.1 =
* Fixed: Percentage calculation bug in dashboard (showing >100%).
* Improved: Full compliance with WordPress.org coding standards.

= 1.9 =
* New Feature: Added Status Column to the Media Library.
* New Feature: Quick "Optimize" button for individual images in the Media Library list view.

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
