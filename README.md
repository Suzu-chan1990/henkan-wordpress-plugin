# 🖼️ Henkan - WebP & AVIF Converter

[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-8A2BE2.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-2.0.2-orange.svg)]()

**Henkan** (Japanese for "conversion") is a lightweight, high-performance WordPress plugin that automatically converts your media library images to next-generation formats like **WebP** and **AVIF**.

Reduce your page load times, improve your Core Web Vitals, and save server bandwidth without sacrificing image quality!

---

## ✨ Key Features

* 🚀 **Next-Gen Formats:** Convert standard JPEGs and PNGs into highly optimized WebP or AVIF files.
* ⚙️ **Smart Converters:** Uses native OS binaries (`cwebp`, `avifenc`) for maximum performance, with automatic fallbacks to `Imagick` or `GD` if CLI tools are unavailable.
* 🔄 **Auto-Conversion:** Automatically optimizes new images silently upon upload.
* 📦 **Bulk Optimization:** Process your entire existing Media Library with a single click via the WordPress Admin dashboard.
* 💻 **WP-CLI Support:** Built-in commands for developers and sysadmins to convert images directly from the terminal without PHP timeouts.
* 🧹 **Cache Auto-Clear:** Seamlessly integrates with popular caching plugins (WP Rocket, W3 Total Cache, Autoptimize, LiteSpeed) to clear the cache after conversions.
* 🌍 **Fully Localized:** 100% translation-ready. Includes English, German (`de_DE`), and Japanese (`ja`) out of the box.

## 🛠️ System Requirements

To get the best performance out of Henkan, your server should meet the following minimum requirements:
* **WordPress:** 6.0 or higher
* **PHP:** 7.4 or higher
* **For WebP:** `cwebp` (recommended) OR PHP `GD` extension with WebP support.
* **For AVIF:** `avifenc`, `ImageMagick` (magick/convert), OR PHP `GD` extension with AVIF support.

## 📥 Installation

1. Download the latest `henkan-webp-avif-converter.zip` from the [Releases](../../releases) page.
2. Go to your WordPress Dashboard: **Plugins > Add New > Upload Plugin**.
3. Upload the zip file and click **Install Now**.
4. Click **Activate Plugin**.
5. Navigate to **Settings > Henkan** to configure your preferred image formats and quality.

## 💻 WP-CLI Usage

For large media libraries, using the terminal is highly recommended to process images blazingly fast in the background.

**Scan and convert all missing images:**
`wp henkan process`

**Available Options:**
* `--format=webp` or `--format=avif` (Override plugin settings)
* `--quality=80` (Set custom compression quality)
* `--force` (Re-convert already optimized images)

*Example:*
`wp henkan process --format=avif --quality=75 --force`

## 🤝 Contributing

Pull requests and issue reports are always welcome! If you find a bug or want to suggest a new feature, please open an issue on GitHub.

## 📄 License

This project is licensed under the GPLv2 License - see the [LICENSE](LICENSE) file for details.