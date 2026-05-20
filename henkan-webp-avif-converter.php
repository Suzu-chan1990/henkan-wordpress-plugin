<?php
/**
 * Plugin Name: Henkan - WebP & AVIF Converter
 * Description: Professional Image Optimization: Smart-Scan, WP-CLI, Lazy-Loading and Cache Clearing.
 * Version: 2.2.0
 * Author: Saguya
 * Text Domain: henkan-webp-avif-converter
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 8.3
 */

defined( 'ABSPATH' ) || exit;

define( 'HENKAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'HENKAN_URL', plugin_dir_url( __FILE__ ) );

// ABSOLUTELY NO FORMAT IS ACTIVE BY DEFAULT
function henkan_default_settings() {
    return [
        'enable_webp'            => 0,
        'enable_avif'            => 0,
        'enable_jxl'             => 0,
        'webp_converter'         => 'cwebp',
        'avif_converter'         => 'gd', // GD is the safest default from PHP 8.1+
        'jxl_converter'          => 'cjxl',
        'keep_original'          => 1,
        'quality'                => 82,
        'debug'                  => 0,
        'batch_size'             => 20,
        'bulk_only_unconverted'  => 1,
        'picture_filter_enabled' => 1,
        'enable_lazy_loading'    => 1,
        'enable_bg_queue'        => 0,
        'auto_clear_cache'       => 1,
        'scan_uploads_dir'       => 1,
        'scan_theme_dir'         => 0,
        'custom_folders'         => '',
        'exclusions'             => '',
    ];
}

// EXCLUSION CHECKER
function henkan_is_file_excluded( $file_path ) {
    $settings = henkan_get_settings();
    if ( empty( $settings['exclusions'] ) ) return false;
    $rules = explode( "\n", $settings['exclusions'] );
    foreach ( $rules as $rule ) {
        $rule = trim( $rule );
        if ( empty( $rule ) ) continue;
        
        // PHP 7.4 compat regex check
        if ( strpos( $rule, '/' ) === 0 && substr( $rule, -1 ) === '/' ) {
            if ( @preg_match( $rule, $file_path ) ) return true;
        } else {
            // Simple string match
            if ( stripos( $file_path, $rule ) !== false ) return true;
        }
    }
    return false;
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
    henkan_install_db();
    // HARD RESET: Clears legacy JXL settings from the WordPress DB once!
    if ( ! get_option( 'henkan_db_reset_v211' ) ) {
        delete_option( 'henkan_settings' );
        update_option( 'henkan_db_reset_v211', 1 );
    }

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

    wp_enqueue_style( 'henkan-admin-style', HENKAN_URL . 'admin-style.css', [], '2.2.0' );
    wp_enqueue_script( 'henkan-admin-script', HENKAN_URL . 'admin-script.js', [ 'jquery' ], '2.2.0', true );

    /* translators: %s: Error message from server */
    $i18n_error = __( 'Error: %s', 'henkan-webp-avif-converter' );
    /* translators: 1: Number of processed items, 2: Total items */
    $i18n_processing = __( 'Processing %1$s of %2$s...', 'henkan-webp-avif-converter' );

    wp_localize_script( 'henkan-admin-script', 'henkan_ajax', [
        'ajax_url'      => admin_url( 'admin-ajax.php' ),
        'nonce_scan'    => wp_create_nonce( 'henkan_scan_nonce' ),
        'nonce_convert' => wp_create_nonce( 'henkan_convert_nonce' ),
        'batch_size'    => henkan_get_settings()['batch_size'],
        'i18n'          => [
            'ready_to_resume' => __( 'Ready to resume...', 'henkan-webp-avif-converter' ),
            'all_done'        => __( 'Done! All processes completed.', 'henkan-webp-avif-converter' ),
            'starting'        => __( 'Starting...', 'henkan-webp-avif-converter' ),
            'error'           => $i18n_error,
            'done'            => __( 'Done!', 'henkan-webp-avif-converter' ),
            'processing'      => $i18n_processing
        ]
    ] );
}


// --- MEDIA LIBRARY SUPPORT FOR AVIF & JXL ---

// 1. Erlaube Uploads und erkenne Mime-Types
add_filter( 'upload_mimes', 'henkan_add_mimes' );
function henkan_add_mimes( $mimes ) {
    if ( ! isset( $mimes['avif'] ) ) $mimes['avif'] = 'image/avif';
    if ( ! isset( $mimes['jxl'] ) )  $mimes['jxl']  = 'image/jxl';
    return $mimes;
}

// 2. Gruppiere sie als Bilder (damit sie im "Bilder"-Filter der Mediathek auftauchen)
add_filter( 'ext2type', 'henkan_add_ext2type' );
function henkan_add_ext2type( $ext2type ) {
    if ( ! in_array( 'avif', $ext2type['image'] ) ) $ext2type['image'][] = 'avif';
    if ( ! in_array( 'jxl', $ext2type['image'] ) )  $ext2type['image'][] = 'jxl';
    return $ext2type;
}

// 3. Zwinge WP dazu, die Dateiendungen bei der Prüfung korrekt zu mappen
add_filter( 'wp_check_filetype_and_ext', 'henkan_fix_mime_checks', 10, 4 );
function henkan_fix_mime_checks( $data, $file, $filename, $mimes ) {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    if ( $ext === 'jxl' ) {
        $data['ext']  = 'jxl';
        $data['type'] = 'image/jxl';
    } elseif ( $ext === 'avif' ) {
        $data['ext']  = 'avif';
        $data['type'] = 'image/avif';
    }
    return $data;
}

// 4. Admin Grid Fallback: Zeige WebP in der Backend-Vorschau, falls der Browser kein JXL kann
add_filter( 'wp_prepare_attachment_for_js', 'henkan_admin_thumbnail_fallback', 10, 3 );
function henkan_admin_thumbnail_fallback( $response, $attachment, $meta ) {
    if ( isset( $response['mime'] ) && ( $response['mime'] === 'image/jxl' || $response['mime'] === 'image/avif' ) ) {
        $converted_meta = henkan_get_data( $attachment->ID );
        
        // Die Reihenfolge, in der wir nach einer darstellbaren Vorschau suchen
        $try_formats = ['webp', 'png', 'jpg', 'jpeg'];
        
        if ( ! empty( $converted_meta ) && is_array( $converted_meta ) ) {
            // URL des Hauptbildes tauschen
            if ( isset( $response['url'] ) && isset( $converted_meta['original'] ) ) {
                foreach( $try_formats as $f ) {
                    if ( isset( $converted_meta['original'][$f] ) ) {
                        $response['url'] = str_replace( basename( $response['url'] ), $converted_meta['original'][$f], $response['url'] );
                        break;
                    }
                }
            }
            // URLs der Thumbnails tauschen
            if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
                foreach ( $response['sizes'] as $size_name => &$size_data ) {
                    if ( isset( $converted_meta[$size_name] ) ) {
                        foreach( $try_formats as $f ) {
                            if ( isset( $converted_meta[$size_name][$f] ) ) {
                                $size_data['url'] = str_replace( basename( $size_data['url'] ), $converted_meta[$size_name][$f], $size_data['url'] );
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    return $response;
}


// --- DATABASE UPGRADE & HELPERS ---
function henkan_install_db() {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    
    // Führe das Upgrade nur einmalig aus
    if ( get_option('henkan_db_version') !== '2.2' ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            attachment_id bigint(20) unsigned NOT NULL,
            data longtext NOT NULL,
            PRIMARY KEY  (attachment_id)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        // Ultraschnelle MariaDB-Massenmigration
        $wpdb->query( "INSERT IGNORE INTO $table (attachment_id, data) SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_henkan_converted_files'" );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_henkan_converted_files'" );
        
        update_option('henkan_db_version', '2.2');
    }
}

function henkan_get_data( $id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    $res = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $table WHERE attachment_id = %d", $id ) );
    return $res ? maybe_unserialize( $res ) : false;
}

function henkan_update_data( $id, $data ) {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    if ( empty($data) ) {
        $wpdb->delete( $table, ['attachment_id' => $id] );
    } else {
        $wpdb->replace( $table, ['attachment_id' => $id, 'data' => maybe_serialize($data)], ['%d', '%s'] );
    }
}
