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
    $only_failed  = ! empty( $_POST['bulk_only_failed'] );
    
    $settings      = henkan_get_settings();
    $todo          = [];
    $total_scanned = 0;
    
    $ids = get_posts( [
        'post_type'      => 'attachment', 
        'post_mime_type' => [ 'image/jpeg', 'image/png' ], 
        'posts_per_page' => -1, 
        'fields'         => 'ids'
    ] );
    
    $total_scanned += count( $ids );

    foreach ( $ids as $id ) {
        if ( ! empty( $only_failed ) ) {
            $st = get_post_meta( $id, '_henkan_state', true );
            if ( $st === 'failed' ) $todo[] = $id;
            continue;
        }

        $needs_work = true;
        if ( ! $force && $only_missing ) {
            $has_meta = henkan_get_data( $id );
            if ( $has_meta ) {
                $needs_work = false;
            } else {
                $file = get_attached_file( $id );
                if ( $file ) {
                    $path_no_ext = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
                    $want_webp = (int) $settings['enable_webp'];
                    $want_avif = (int) $settings['enable_avif'];
                    $want_jxl  = (int) (!empty($settings['enable_jxl']));

                    $webp_exists = file_exists( $path_no_ext . '.webp' );
                    $avif_exists = file_exists( $path_no_ext . '.avif' );
                    $jxl_exists  = file_exists( $path_no_ext . '.jxl' );

                    $webp_ok = ( ! $want_webp ) || $webp_exists;
                    $avif_ok = ( ! $want_avif ) || $avif_exists;
                    $jxl_ok  = ( ! $want_jxl ) || $jxl_exists;

                    if ( $webp_ok && $avif_ok && $jxl_ok ) {
                        $needs_work = false;
                    }
                } else {
                    $needs_work = false;
                }
            }
        }
        if ( $needs_work ) {
            $chk_file = get_attached_file( $id );
            if ( $chk_file && function_exists('henkan_is_file_excluded') && henkan_is_file_excluded( $chk_file ) ) {
                continue;
            }
            $todo[] = $id;
        }
    }

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
                if ( in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) {
                    $full_path = $file->getPathname();
                    $total_scanned++;
                    
                    $needs_conv = true;
                    if ( $only_missing && ! $force ) {
                        $path_no_ext = dirname( $full_path ) . '/' . pathinfo( $full_path, PATHINFO_FILENAME );
                        $webp = file_exists( $path_no_ext . '.webp' );
                        $avif = file_exists( $path_no_ext . '.avif' );
                        $jxl  = file_exists( $path_no_ext . '.jxl' );
                        
                        if ( (!empty($settings['enable_webp']) && $webp) || (!empty($settings['enable_avif']) && $avif) || (!empty($settings['enable_jxl']) && $jxl) ) {
                            $needs_conv = false;
                        }
                    }
                    if ( $needs_conv ) {
                        if ( function_exists('henkan_is_file_excluded') && henkan_is_file_excluded( $full_path ) ) {
                            continue;
                        }
                        $todo[] = $full_path;
                    }
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
    
    $item = isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : null;
    if ( ! $item ) wp_send_json_error( [ 'msg' => __( 'No item', 'henkan-webp-avif-converter' ) ] );

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
            
            if ( is_array( $res ) ) {
                if ( $res['success'] ) {
                    $gen = strtoupper( implode( ', ', $res['generated'] ) );
                    $fail = empty( $res['failed'] ) ? '' : __( ' | Failed: ', 'henkan-webp-avif-converter' ) . implode( ', ', $res['failed'] );
                    /* translators: 1: ID, 2: Generated formats, 3: Failed formats */
                    $msg = sprintf( esc_html__( 'ID %1$d optimized [%2$s]%3$s', 'henkan-webp-avif-converter' ), $id, $gen, $fail );
                } else {
                    $fail_reasons = empty( $res['failed'] ) ? __( 'Unknown error', 'henkan-webp-avif-converter' ) : implode( ', ', $res['failed'] );
                    /* translators: 1: ID, 2: Error reasons */
                    $msg = sprintf( esc_html__( 'ID %1$d completely failed: %2$s', 'henkan-webp-avif-converter' ), $id, $fail_reasons );
                }
            } else {
                /* translators: %d: ID */
                $msg = $res ? sprintf( esc_html__( 'ID %d optimiert', 'henkan-webp-avif-converter' ), $id ) : sprintf( esc_html__( 'ID %d checked', 'henkan-webp-avif-converter' ), $id );
            }
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            /* translators: %d: Attachment ID */
            $msg_err = sprintf( esc_html__( 'File missing ID %d', 'henkan-webp-avif-converter' ), $id );
            wp_send_json_error( [ 'msg' => $msg_err ] );
        }
    } else {
        $path = wp_normalize_path( $item );
        if ( file_exists( $path ) ) {
            $res = henkan_convert_file( $path, 0, 'custom' );
            if ( is_array( $res ) ) {
                if ( $res['success'] ) {
                    $gen = strtoupper( implode( ', ', $res['generated'] ) );
                    $fail = empty( $res['failed'] ) ? '' : __( ' | Failed: ', 'henkan-webp-avif-converter' ) . implode( ', ', $res['failed'] );
                    /* translators: 1: File name, 2: Generated formats, 3: Failed formats */
                    $msg = sprintf( esc_html__( '%1$s optimized [%2$s]%3$s', 'henkan-webp-avif-converter' ), basename( $path ), $gen, $fail );
                } else {
                    $fail_reasons = empty( $res['failed'] ) ? __( 'Unknown error', 'henkan-webp-avif-converter' ) : implode( ', ', $res['failed'] );
                    /* translators: 1: File name, 2: Error reasons */
                    $msg = sprintf( esc_html__( '%1$s completely failed: %2$s', 'henkan-webp-avif-converter' ), basename( $path ), $fail_reasons );
                }
            } else {
                /* translators: %s: File name */
                $msg = $res ? sprintf( esc_html__( '%s optimiert', 'henkan-webp-avif-converter' ), basename( $path ) ) : sprintf( esc_html__( '%s checked', 'henkan-webp-avif-converter' ), basename( $path ) );
            }
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            /* translators: %s: File name */
            $msg_err = sprintf( esc_html__( 'Not found: %s', 'henkan-webp-avif-converter' ), basename( $path ) );
            wp_send_json_error( [ 'msg' => $msg_err ] );
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
    wp_send_json_success( [ 'msg' => __( 'Cache cleared successfully.', 'henkan-webp-avif-converter' ) ] );
}
