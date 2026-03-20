<?php
/**
 * Plugin Name: Henkan - WebP & AVIF Converter
 * Description: Professional Image Optimization: Smart-Scan, WP-CLI, Lazy-Loading and Cache Clearing.
 * Version: 2.0.2
 * Author: すずちゃん
 * Text Domain: henkan-webp-avif-converter
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'HENKAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'HENKAN_URL', plugin_dir_url( __FILE__ ) );

function henkan_default_settings() {
    return [
        'enable_webp'            => 1,
        'enable_avif'            => 0,
        'webp_converter'         => 'cwebp',
        'avif_converter'         => 'avifenc',
        'keep_original'          => 1,
        'quality'                => 82,
        'debug'                  => 0,
        'batch_size'             => 20,
        'bulk_only_unconverted'  => 1,
        'picture_filter_enabled' => 1,
        'enable_lazy_loading'    => 1,
        'auto_clear_cache'       => 1,
        'scan_uploads_dir'       => 1,
        'scan_theme_dir'         => 0,
        'custom_folders'         => '',
    ];
}

function henkan_get_settings() {
    $defaults = henkan_default_settings();
    $opt = get_option( 'henkan_settings', [] );
    return wp_parse_args( $opt, $defaults );
}

function henkan_log( $msg ) {
    $s = henkan_get_settings();
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $s['debug'] ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
        error_log( '[Henkan] ' . ( is_scalar( $msg ) ? $msg : print_r( $msg, true ) ) );
    }
}

add_action( 'plugins_loaded', 'henkan_init_plugin' );
function henkan_init_plugin() {
    require_once HENKAN_PATH . 'includes/process.php';
    require_once HENKAN_PATH . 'includes/admin.php';
    
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        require_once HENKAN_PATH . 'includes/cli.php';
    }

    if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        require_once HENKAN_PATH . 'includes/frontend.php';
    }
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        require_once HENKAN_PATH . 'includes/ajax.php';
    }
}

add_action( 'admin_enqueue_scripts', 'henkan_enqueue_admin' );
function henkan_enqueue_admin( $hook ) {
    if ( $hook !== 'settings_page_henkan-settings' && $hook !== 'upload.php' ) {
        return;
    }

    wp_enqueue_style( 'henkan-admin-style', HENKAN_URL . 'admin-style.css', [], '2.0.1' );
    wp_enqueue_script( 'henkan-admin-script', HENKAN_URL . 'admin-script.js', [ 'jquery' ], '2.0.1', true );

    wp_localize_script( 'henkan-admin-script', 'henkan_ajax', [
        'ajax_url'      => admin_url( 'admin-ajax.php' ),
        'nonce_scan'    => wp_create_nonce( 'henkan_scan_nonce' ),
        'nonce_convert' => wp_create_nonce( 'henkan_convert_nonce' ),
        'batch_size'    => henkan_get_settings()['batch_size'],
        'i18n'          => [
            'ready_to_resume' => __( 'Bereit zum Fortsetzen…', 'henkan-webp-avif-converter' ),
            'all_done'        => __( 'Fertig! Alle Prozesse abgeschlossen.', 'henkan-webp-avif-converter' ),
            'starting'   => __( 'Starte...', 'henkan-webp-avif-converter' ),
            /* translators: %s: Error message from server */
            'error'      => __( 'Fehler: %s', 'henkan-webp-avif-converter' ),
            'done'       => __( 'Fertig!', 'henkan-webp-avif-converter' ),
            /* translators: 1: Number of processed items, 2: Total items */
            'processing' => __( 'Verarbeite %1$s von %2$s...', 'henkan-webp-avif-converter' )
        ]
    ] );
}

// --- GitHub Updater (Plugin Update Checker) ---
add_action('init', function () {
    // Nur im Admin wirklich nötig, aber init ist ok
    if ( ! is_admin() ) {
        return;
    }

    $puc_path = HENKAN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists($puc_path) ) {
        return; // Library fehlt
    }

    require_once $puc_path;

    // Build checker
    $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/Suzu-chan1990/henkan-wordpress-plugin',
        __FILE__,
        'henkan' // Plugin-Slug (sollte stabil sein)
    );

    // Wenn du Releases/Tags nutzt:
    $updateChecker->getVcsApi()->enableReleaseAssets();

    // Falls du (statt Releases) direkt den main-Branch “auslieferst”:
    // $updateChecker->setBranch('main');

    // Optional: falls dein Hauptplugin nicht im Repo-Root läge (bei dir liegt es im Root -> passt)
    // $updateChecker->setPluginFileName('henkan.php');
});

