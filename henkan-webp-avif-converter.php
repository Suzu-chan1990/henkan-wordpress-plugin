<?php
/**
 * Plugin Name: Henkan - WebP & AVIF Converter
 * Description: Professional Image Optimization: Smart-Scan, WP-CLI, Lazy-Loading and Cache Clearing.
 * Version: 2.5.1
 * Author: Saguya
 * Text Domain: henkan-webp-avif-converter
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 7.1
 * Requires PHP: 8.4
 */

defined( 'ABSPATH' ) || exit;

define( 'HENKAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'HENKAN_URL', plugin_dir_url( __FILE__ ) );
define( 'HENKAN_VERSION', '2.5.1' );

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
        'scan_uploads_dir'         => 1,
        'scan_theme_dir'           => 0,
        'custom_folders'           => '',
        'exclusions'               => '',
        // Smart Options - all disabled by default
        'smart_quality_enabled'    => 0,
        'smart_quality_thumb'      => 70,
        'integrity_check_enabled'  => 0,
        'max_width_enabled'        => 0,
        'max_width_px'             => 2000,
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

// ============================================================
// Smart Options: Integrity Check Cron
// ============================================================
add_action( 'henkan_integrity_check_event', 'henkan_run_integrity_check' );

function henkan_run_integrity_check() {
    $s = henkan_get_settings();
    if ( empty( $s['integrity_check_enabled'] ) ) return;

    global $wpdb;
    $table   = $wpdb->prefix . 'henkan_data';
    $missing = 0;

    $lock = 'henkan_integrity_lock';
    if ( ! add_option( $lock, [ 'started' => time() ], '', false ) ) {
        $existing = get_option( $lock, [] );
        if ( ! empty( $existing['started'] ) && ( time() - (int) $existing['started'] ) < 7200 ) return;
        delete_option( $lock );
        if ( ! add_option( $lock, [ 'started' => time() ], '', false ) ) return;
    }

    try {
    $last_id = 0; $chunk_size = 250;
    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT attachment_id, data FROM %i WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d',
            $table, $last_id, $chunk_size
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $last_id = (int) $row['attachment_id'];
        $data = henkan_decode_data( $row['data'] );
        if ( ! is_array( $data ) ) continue;

        $all_missing = true;
        foreach ( $data as $size => $formats ) {
            if ( ! is_array( $formats ) ) continue;
            foreach ( $formats as $fmt => $filename ) {
                $file = get_attached_file( $row['attachment_id'] );
                if ( ! $file ) continue;
                $path = dirname( $file ) . '/' . $filename;
                if ( file_exists( $path ) ) {
                    $all_missing = false;
                    break 2;
                }
            }
        }

        if ( $all_missing ) {
            // Delete the record -> the image is converted again on the next scan
            henkan_update_data( $row['attachment_id'], [] );
            $missing++;
        }
    }

    } while ( count( $rows ) === $chunk_size );

    update_option( 'henkan_integrity_last_run', time() );
    update_option( 'henkan_integrity_missing_count', $missing );
    } finally {
        delete_option( $lock );
    }
}

// Cron aktivieren/deaktivieren je nach Option
add_action( 'update_option_henkan_settings', 'henkan_update_integrity_cron', 10, 2 );
function henkan_update_integrity_cron( $old, $new ) {
    if ( ! empty( $new['integrity_check_enabled'] ) ) {
        if ( ! wp_next_scheduled( 'henkan_integrity_check_event' ) ) {
            wp_schedule_event( time(), 'daily', 'henkan_integrity_check_event' );
        }
    } else {
        wp_clear_scheduled_hook( 'henkan_integrity_check_event' );
    }
}

// Bounded WP-Cron background queue.
add_action( 'henkan_background_worker_event', 'henkan_background_worker' );

function henkan_background_worker() {
    $q = get_option( 'henkan_background_queue', [] ); if ( empty( $q['active'] ) ) return;
    $settings = henkan_get_settings();
    if ( empty( $settings['enable_bg_queue'] ) ) { $q['active']=0; update_option( 'henkan_background_queue', $q, false ); return; }
    $worker_lock = 'henkan_background_worker_lock';
    // Acquire the worker lock; recover it if a previous worker died (15 min TTL)
    if ( ! add_option( $worker_lock, [ 'started'=>time(), 'token'=>wp_generate_uuid4() ], '', false ) ) {
        $existing_lock = get_option( $worker_lock, [] );
        if ( ! empty( $existing_lock['started'] ) && ( time() - (int) $existing_lock['started'] ) < 900 ) return;
        delete_option( $worker_lock );
        if ( ! add_option( $worker_lock, [ 'started'=>time(), 'token'=>wp_generate_uuid4() ], '', false ) ) return;
    }
    try {
        global $wpdb; $last=max(0,(int)($q['last_id']??0)); $limit=20;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments, bounded batch
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') AND post_status='inherit' ORDER BY ID ASC LIMIT %d",$last,$limit));
        foreach($ids as $id){
            $id=(int)$id; $q['last_id']=$id; $file=get_attached_file($id);
            if(!$file || !file_exists($file) || henkan_is_file_excluded($file)){ $q['skipped']=(int)($q['skipped']??0)+1; continue; }
            if(!empty($q['only_failed']) && get_post_meta($id,'_henkan_state',true)!=='failed') continue;
            if(!empty($q['only_missing']) && empty($q['force']) && !henkan_attachment_needs_conversion($id,$settings)){ $q['skipped']=(int)($q['skipped']??0)+1; continue; }
            $res=henkan_convert_file($file,$id,'original'); $meta=wp_get_attachment_metadata($id);
            if(!empty($meta['sizes']) && is_array($meta['sizes'])){ $base=dirname($file); foreach($meta['sizes'] as $size=>$data){ if(!empty($data['file'])) henkan_convert_file($base.'/'.$data['file'],$id,$size); } }
            $q['processed']=(int)($q['processed']??0)+1; $q['heartbeat']=time(); $q['last_result']=is_array($res)?$res['success']:(bool)$res;
        }
        if(count($ids)===$limit && !empty($q['active'])){ update_option('henkan_background_queue',$q,false); wp_schedule_single_event(time()+3,'henkan_background_worker_event'); } else { $q['active']=0; $q['finished']=time(); update_option('henkan_background_queue',$q,false); if(!empty($settings['auto_clear_cache'])) henkan_trigger_cache_clear(); }
    } finally { delete_option($worker_lock); }
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

    wp_enqueue_style( 'henkan-admin-style', HENKAN_URL . 'admin-style.css', [], HENKAN_VERSION );
    wp_enqueue_script( 'henkan-admin-script', HENKAN_URL . 'admin-script.js', [ 'jquery' ], HENKAN_VERSION, true );

    /* translators: %s: Error message from server */
    $i18n_error = __( 'Error: %s', 'henkan-webp-avif-converter' );
    /* translators: 1: Number of processed items, 2: Total items */
    $i18n_processing = __( 'Processing %1$s of %2$s...', 'henkan-webp-avif-converter' );

    wp_localize_script( 'henkan-admin-script', 'henkan_ajax', [
        'ajax_url'      => admin_url( 'admin-ajax.php' ),
        'nonce_scan'    => wp_create_nonce( 'henkan_scan_nonce' ),
        'nonce_convert' => wp_create_nonce( 'henkan_convert_nonce' ),
        'batch_size'    => henkan_get_settings()['batch_size'],
        'enable_bg_queue' => ! empty( henkan_get_settings()['enable_bg_queue'] ),
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

// 1. Allow uploads and register the mime types
add_filter( 'upload_mimes', 'henkan_add_mimes' );
function henkan_add_mimes( $mimes ) {
    if ( ! isset( $mimes['avif'] ) ) $mimes['avif'] = 'image/avif';
    // JPEG XL uploads only when the JXL format is enabled in Henkan
    $s = henkan_get_settings();
    if ( ! empty( $s['enable_jxl'] ) && ! isset( $mimes['jxl'] ) ) $mimes['jxl'] = 'image/jxl';
    return $mimes;
}

// 2. Group them as images (so they show up in the "Images" filter of the media library)
add_filter( 'ext2type', 'henkan_add_ext2type' );
function henkan_add_ext2type( $ext2type ) {
    if ( ! in_array( 'avif', $ext2type['image'] ) ) $ext2type['image'][] = 'avif';
    if ( ! in_array( 'jxl', $ext2type['image'] ) )  $ext2type['image'][] = 'jxl';
    return $ext2type;
}

// 3. Make WP map the file extensions correctly during the file type check
add_filter( 'wp_check_filetype_and_ext', 'henkan_fix_mime_checks', 10, 4 );
function henkan_fix_mime_checks( $data, $file, $filename, $mimes ) {
    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'avif', 'jxl' ], true ) || ! is_readable( $file ) ) return $data;

    // Never trust the extension alone: verify the file signature (magic bytes)
    $head = (string) file_get_contents( $file, false, null, 0, 16 );
    if ( $ext === 'avif' ) {
        $ok = substr( $head, 4, 4 ) === 'ftyp' && in_array( substr( $head, 8, 4 ), [ 'avif', 'avis' ], true );
    } else {
        // JPEG XL: raw codestream (FF 0A) or ISO-BMFF container signature
        $ok = strncmp( $head, "\xFF\x0A", 2 ) === 0 || strncmp( $head, "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A", 12 ) === 0;
    }
    if ( $ok ) {
        $data['ext']  = $ext;
        $data['type'] = 'image/' . $ext;
    }
    return $data;
}

// 4. Admin grid fallback: show WebP in the backend preview if the browser cannot display JXL
add_filter( 'wp_prepare_attachment_for_js', 'henkan_admin_thumbnail_fallback', 10, 3 );
function henkan_admin_thumbnail_fallback( $response, $attachment, $meta ) {
    if ( isset( $response['mime'] ) && ( $response['mime'] === 'image/jxl' || $response['mime'] === 'image/avif' ) ) {
        $converted_meta = henkan_get_data( $attachment->ID );
        
        // Order in which a displayable preview is searched
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
            // Swap the thumbnail URLs
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
    
    // Run the upgrade only once
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time bulk migration from post meta into the plugin's table
        $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (attachment_id, data) SELECT post_id, meta_value FROM %i WHERE meta_key = %s', $table, $wpdb->postmeta, '_henkan_converted_files' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup of the migrated meta rows
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s', $wpdb->postmeta, '_henkan_converted_files' ) );
        
        update_option('henkan_db_version', '2.2');
    }
}

function henkan_get_data( $id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table (primary key lookup)
    $res = $wpdb->get_var( $wpdb->prepare( 'SELECT data FROM %i WHERE attachment_id = %d', $table, $id ) );
    return $res ? henkan_decode_data( $res ) : false;
}

function henkan_attachment_needs_conversion( $id, $settings = null ) {
    $settings = is_array( $settings ) ? $settings : henkan_get_settings();
    $file = get_attached_file( $id );
    if ( ! $file || ! file_exists( $file ) ) return false;

    $base = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
    $checks = [];
    if ( ! empty( $settings['enable_webp'] ) ) $checks[] = $base . '.webp';
    if ( ! empty( $settings['enable_avif'] ) ) $checks[] = $base . '.avif';
    if ( ! empty( $settings['enable_jxl'] ) ) $checks[] = $base . '.jxl';
    if ( empty( $checks ) ) return false;

    foreach ( $checks as $expected ) {
        if ( ! file_exists( $expected ) || filesize( $expected ) <= 0 ) return true;
    }
    return false;
}

function henkan_update_data( $id, $data ) {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    if ( empty($data) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table
        $wpdb->delete( $table, ['attachment_id' => $id] );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table
        $wpdb->replace( $table, ['attachment_id' => $id, 'data' => maybe_serialize($data)], ['%d', '%s'] );
    }
}


// --- SECURITY HELPERS (2.4.1, extended in 2.4.2) ---

// Decode stored data without allowing object instantiation (prevents PHP object injection)
function henkan_decode_data( $raw ) {
    if ( is_serialized( $raw ) ) {
        return @unserialize( trim( $raw ), [ 'allowed_classes' => false ] );
    }
    return $raw;
}

// Allowlist check: only files inside the configured scan roots may be converted
function henkan_is_allowed_path( $path ) {
    $real = realpath( $path );
    if ( ! $real ) return false;

    $s      = henkan_get_settings();
    $roots  = [];
    $upload = wp_get_upload_dir();
    if ( ! empty( $upload['basedir'] ) ) $roots[] = $upload['basedir'];
    if ( ! empty( $s['scan_theme_dir'] ) ) $roots[] = get_stylesheet_directory();
    foreach ( henkan_custom_roots( $s ) as $c ) $roots[] = $c;

    $real = trailingslashit( wp_normalize_path( $real ) );
    foreach ( $roots as $root ) {
        $root_real = realpath( $root );
        if ( $root_real && strpos( $real, trailingslashit( wp_normalize_path( $root_real ) ) ) === 0 ) return true;
    }
    return false;
}

/**
 * Custom scan folders that are allowed: only folders inside the WordPress installation,
 * wp-content or the uploads directory. Folders elsewhere on the server (e.g. "/") are ignored.
 * Hosts with a different layout can allow more via the filter "henkan_allow_custom_root".
 *
 * @return string[] Real paths of the allowed custom folders.
 */
function henkan_custom_roots( $settings = null ) {
    $settings = is_array( $settings ) ? $settings : henkan_get_settings();
    $upload   = wp_get_upload_dir();
    $bases    = array_filter( array_map( 'realpath', array_filter( [ ABSPATH, WP_CONTENT_DIR, $upload['basedir'] ?? '' ] ) ) );
    $out      = [];
    foreach ( preg_split( '/\R/', (string) ( $settings['custom_folders'] ?? '' ) ) as $c ) {
        $c = trim( $c );
        if ( $c === '' ) continue;
        $real = realpath( $c );
        if ( ! $real || ! is_dir( $real ) ) continue;
        $real_n = trailingslashit( wp_normalize_path( $real ) );
        $ok = false;
        foreach ( $bases as $b ) {
            if ( strpos( $real_n, trailingslashit( wp_normalize_path( $b ) ) ) === 0 ) { $ok = true; break; }
        }
        if ( apply_filters( 'henkan_allow_custom_root', $ok, $real ) ) $out[] = $real;
    }
    return array_values( array_unique( $out ) );
}

// One-time background repair of thumbnails broken by older bulk conversions (see process.php)
add_action( 'init', function() {
    if ( get_option( 'henkan_metadata_repair' ) === false && ! wp_next_scheduled( 'henkan_repair_metadata_event' ) ) {
        add_option( 'henkan_metadata_repair', [ 'last_id' => 0, 'fixed' => 0 ], '', false );
        wp_schedule_single_event( time() + 30, 'henkan_repair_metadata_event' );
    }
} );

// Schedule the integrity cron also when the option is created for the first time
add_action( 'add_option_henkan_settings', function( $option, $value ) {
    henkan_update_integrity_cron( [], $value );
}, 10, 2 );

// Clean up scheduled events on deactivation
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'henkan_integrity_check_event' );
    wp_clear_scheduled_hook( 'henkan_background_worker_event' );
    wp_clear_scheduled_hook( 'henkan_repair_metadata_event' );
} );
