<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_henkan_scan', 'henkan_scan' );
add_action( 'wp_ajax_henkan_convert', 'henkan_convert' );
add_action( 'wp_ajax_henkan_clear_cache', 'henkan_ajax_clear_cache' );

function henkan_scan() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    }

    check_ajax_referer( 'henkan_scan_nonce', 'nonce' );
    
    $force        = ! empty( $_POST['rescan_all'] );
    $only_missing = ! empty( $_POST['bulk_only_unconverted'] );
    
    $settings      = henkan_get_settings();
    $todo          = [];
    $total_scanned = 0;
    
    // 1. Mediathek Scan (Mit File Check)
    $ids = get_posts( [
        'post_type'      => 'attachment', 
        'post_mime_type' => [ 'image/jpeg', 'image/png' ], 
        'posts_per_page' => -1, 
        'fields'         => 'ids'
    ] );
    
    $total_scanned += count( $ids );

    foreach ( $ids as $id ) {
        $needs_work = true;

        if ( ! $force && $only_missing ) {
            $has_meta = get_post_meta( $id, '_henkan_converted_files', true );
            if ( $has_meta ) {
                $needs_work = false;
            } else {
                $file = get_attached_file( $id );
                if ( $file ) {
                    $path_no_ext = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
                    
                    $want_webp = (int) $settings['enable_webp'];
                    $want_avif = (int) $settings['enable_avif'];

                    $webp_exists = file_exists( $path_no_ext . '.webp' );
                    $avif_exists = file_exists( $path_no_ext . '.avif' );

                    $webp_ok = ( ! $want_webp ) || $webp_exists;
                    $avif_ok = ( ! $want_avif ) || $avif_exists;

                    if ( $webp_ok && $avif_ok ) {
                        $needs_work = false;
                    }
                } else {
                    $needs_work = false;
                }
            }
        }
        
        if ( $needs_work ) {
            $todo[] = $id;
        }
    }

    // 2. Custom Folders Scan
    $scan_paths = [];
    if ( ! empty( $settings['scan_uploads_dir'] ) ) { 
        $u = wp_get_upload_dir(); 
        $scan_paths[] = $u['basedir']; 
    }
    if ( ! empty( $settings['scan_theme_dir'] ) ) { 
        $scan_paths[] = get_stylesheet_directory(); 
    }
    if ( ! empty( $settings['custom_folders'] ) ) {
        $custom = explode( "\n", $settings['custom_folders'] );
        foreach ( $custom as $c ) { 
            if ( trim( $c ) ) $scan_paths[] = trim( $c ); 
        }
    }

    foreach ( $scan_paths as $path ) {
        if ( empty( $path ) || ! is_dir( $path ) ) continue;
        try {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path ) );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( in_array( $ext, [ 'jpg', 'jpeg', 'png' ] ) ) {
                    $full_path = $file->getPathname();
                    $total_scanned++;
                    
                    $needs_conv = true;
                    if ( $only_missing && ! $force ) {
                        $path_no_ext = dirname( $full_path ) . '/' . pathinfo( $full_path, PATHINFO_FILENAME );
                        $webp = file_exists( $path_no_ext . '.webp' );
                        $avif = file_exists( $path_no_ext . '.avif' );
                        
                        if ( ( $settings['enable_webp'] && $webp ) || ( $settings['enable_avif'] && $avif ) ) {
                            $needs_conv = false;
                        }
                    }
                    if ( $needs_conv ) $todo[] = $full_path;
                }
            }
        } catch ( Exception $e ) { continue; }
    }
    
    wp_send_json_success( [ 'total_scanned' => $total_scanned, 'items' => array_values( $todo ) ] );
}

function henkan_convert() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    }

    check_ajax_referer( 'henkan_convert_nonce', 'nonce' );
    
    // Fix: wp_unslash before sanitize (Plugin Check Compliance)
    $item = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : null;
    
    if ( ! $item ) wp_send_json_error( [ 'msg' => 'Kein Item' ] );

    if ( is_numeric( $item ) ) {
        $id = intval( $item );
        $file = get_attached_file( $id );
        if ( $file ) {
            $res = henkan_convert_file( $file, $id, 'original' );
            $meta = wp_get_attachment_metadata( $id );
            if ( ! empty( $meta['sizes'] ) ) {
                $base = dirname( $file );
                foreach ( $meta['sizes'] as $size => $data ) { 
                    henkan_convert_file( $base.'/'.$data['file'], $id, $size ); 
                }
            }
            $msg = $res ? "ID $id optimiert" : "ID $id geprueft";
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            wp_send_json_error( [ 'msg' => "Datei fehlt ID $id" ] );
        }
    } else {
        $path = wp_normalize_path( $item );
        if ( file_exists( $path ) ) {
            $res = henkan_convert_file( $path, 0, 'custom' );
            $msg = $res ? basename( $path )." optimiert" : basename( $path )." geprueft";
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            wp_send_json_error( [ 'msg' => "Nicht gefunden: " . basename( $path ) ] );
        }
    }
}

function henkan_ajax_clear_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    }
    
    $settings = henkan_get_settings();
    if ( empty( $settings['auto_clear_cache'] ) ) {
        wp_send_json_success( [ 'msg' => '' ] );
    }
    
    henkan_trigger_cache_clear();
    wp_send_json_success( [ 'msg' => 'Cache erfolgreich geleert.' ] );
}
