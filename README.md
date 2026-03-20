=== Henkan - WebP & AVIF Converter ===
Contributors: suzuchan
Tags: webp, avif, image optimization, converter, performance
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 2.0.2
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

= 2.0.2 =
# 🚀 Release v2.0.2: Full i18n Overhaul & WP.org Compliance

This major patch focuses on deeply refactoring the plugin's internationalization (i18n) architecture and ensuring 100% strict compliance with the official WordPress.org Plugin Directory guidelines.

## 🌍 Internationalization (i18n) & Localization
* **English Base Language:** Completely refactored the plugin's internal strings to use standard English as the base language (`msgid`) instead of German, complying with WordPress best practices.
* **Hardcoded Strings Removed:** Eliminated all hardcoded strings in backend responses (`ajax.php`) and WP-CLI commands (`cli.php`), wrapping them in proper gettext functions (`__()`, `sprintf()`).
* **JavaScript Localization:** Implemented `wp_localize_script` to pass translatable strings to the frontend admin scripts, removing hardcoded UI texts like progress states and completion messages.
* **Translator Context Added:** Added mandatory `/* translators: ... */` PHP comments directly above translatable strings containing dynamic placeholders (`%s`, `%d`) to aid the polyglot community.
* **Ordered Placeholders:** Fixed unordered placeholder variables in translation strings (e.g., using `%1$s` and `%2$s`) to allow safe restructuring of sentences in different languages.
* **Updated Language Packs:** The `.po` and `.mo` files for German (`de_DE`), English (`en_US`), and Japanese (`ja`) have been completely synchronized with the new English base strings.

## ⚙️ Core & WordPress.org Compliance
* **Text Domain Unification:** Conducted a comprehensive sweep to ensure the `henkan-webp-avif-converter` text domain is used exclusively and consistently across all PHP files to match the official plugin slug.
* **Global Variable Standardization:** Refactored internal global variables to remove leading underscores (e.g., changed `$_henkan_last_error` to `$henkan_last_error`), strictly adhering to WordPress naming conventions.
* **Stable Tag Alignment:** Synchronized the `Stable tag` in `README.md` to ensure it accurately reflects the main plugin file header version.
* **Asset Restructuring:** Removed official WordPress.org directory assets (banners and icons) from the core plugin distribution zip. These will be managed exclusively via the `.org` SVN `assets/` directory going forward.

= 2.0.1 =
* Fixed: Auto Update Path

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
