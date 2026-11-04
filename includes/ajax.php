<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_henkan_scan', 'henkan_scan' );
add_action( 'wp_ajax_henkan_convert', 'henkan_convert' );
add_action( 'wp_ajax_henkan_clear_cache', 'henkan_ajax_clear_cache' );
add_action( 'wp_ajax_henkan_queue_start', 'henkan_queue_start' );
add_action( 'wp_ajax_henkan_queue_status', 'henkan_queue_status_ajax' );
add_action( 'wp_ajax_henkan_queue_cancel', 'henkan_queue_cancel' );

function henkan_queue_start() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    check_ajax_referer( 'henkan_scan_nonce', 'nonce' );
    $settings = henkan_get_settings();
    $current_queue = get_option( 'henkan_background_queue', [] );
    if ( ! empty( $current_queue['active'] ) ) wp_send_json_error( [ 'msg' => __( 'A background queue is already running.', 'henkan-webp-avif-converter' ) ] );
    if ( empty( $settings['enable_bg_queue'] ) ) wp_send_json_error( [ 'msg' => __( 'Background Queue is disabled.', 'henkan-webp-avif-converter' ) ] );
    $state = [ 'active' => 1, 'force' => ! empty( $_POST['rescan_all'] ) ? 1 : 0, 'only_missing' => ! empty( $_POST['bulk_only_unconverted'] ) ? 1 : 0, 'only_failed' => ! empty( $_POST['bulk_only_failed'] ) ? 1 : 0, 'last_id' => 0, 'processed' => 0, 'skipped' => 0, 'started' => time(), 'heartbeat' => time() ];
    update_option( 'henkan_background_queue', $state, false );
    if ( ! wp_next_scheduled( 'henkan_background_worker_event' ) ) wp_schedule_single_event( time() + 2, 'henkan_background_worker_event' );
    wp_send_json_success( [ 'state' => $state ] );
}

function henkan_queue_status_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    check_ajax_referer( 'henkan_scan_nonce', 'nonce' );
    wp_send_json_success( [ 'state' => get_option( 'henkan_background_queue', [ 'active' => 0, 'processed' => 0, 'last_id' => 0 ] ) ] );
}

function henkan_queue_cancel() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    check_ajax_referer( 'henkan_scan_nonce', 'nonce' );
    $state = get_option( 'henkan_background_queue', [] ); $state['active'] = 0; $state['cancelled'] = time();
    update_option( 'henkan_background_queue', $state, false ); wp_clear_scheduled_hook( 'henkan_background_worker_event' );
    wp_send_json_success( [ 'state' => $state ] );
}

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

    // Two phases: 'ids' pages through the media library, 'fs' walks the scan folders in
    // time-boxed steps (fs_cursor = number of image files already looked at), so large
    // upload folders no longer run into a PHP timeout in a single request.
    $phase = ( isset( $_POST['phase'] ) && $_POST['phase'] === 'fs' ) ? 'fs' : 'ids';

    if ( $phase === 'ids' ) {
        $after_id = max( 0, absint( $_POST['after_id'] ?? 0 ) );
        $limit = min( 250, max( 25, absint( $_POST['per_page'] ?? 100 ) ) );
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments, bounded batch
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') AND post_status = 'inherit' ORDER BY ID ASC LIMIT %d",
            $after_id, $limit
        ) );
        $total_scanned = count( $ids );

        foreach ( $ids as $id ) {
            if ( ! empty( $only_failed ) ) {
                $st = get_post_meta( $id, '_henkan_state', true );
                if ( $st === 'failed' ) $todo[] = $id;
                continue;
            }

            $needs_work = true;
            if ( ! $force && $only_missing ) {
                if ( ! henkan_attachment_needs_conversion( $id, $settings ) ) {
                    $needs_work = false;
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

        $more_ids = count( $ids ) === $limit;
        $has_fs   = ! $more_ids && empty( $only_failed ) && ! empty( henkan_scan_paths( $settings ) );
        wp_send_json_success( [
            'total_scanned' => $total_scanned,
            'items'         => array_values( array_unique( $todo ) ),
            'after_id'      => ! empty( $ids ) ? (int) end( $ids ) : $after_id,
            'has_more'      => $more_ids || $has_fs,
            'phase'         => $more_ids ? 'ids' : 'fs',
            'fs_cursor'     => 0,
        ] );
    }

    // Phase 'fs': folder scan, at most ~8 seconds or 5000 image files per request
    $cursor   = max( 0, absint( $_POST['fs_cursor'] ?? 0 ) );
    $deadline = microtime( true ) + 8;
    $seen     = 0;   // image files looked at in this and previous requests
    $checked  = 0;   // image files looked at in this request
    $finished = true;

    foreach ( henkan_scan_paths( $settings ) as $path ) {
        try {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) continue;
                $ext = strtolower( $file->getExtension() );
                if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) continue;
                $seen++;
                if ( $seen <= $cursor ) continue;
                if ( $checked >= 5000 || microtime( true ) > $deadline ) { $finished = false; $seen--; break 2; }
                $checked++;

                $full_path = $file->getPathname();
                $total_scanned++;
                $needs_conv = true;
                if ( $only_missing && ! $force ) {
                    $path_no_ext = dirname( $full_path ) . '/' . pathinfo( $full_path, PATHINFO_FILENAME );
                    $webp_ok = empty( $settings['enable_webp'] ) || file_exists( $path_no_ext . '.webp' );
                    $avif_ok = empty( $settings['enable_avif'] ) || file_exists( $path_no_ext . '.avif' );
                    $jxl_ok  = empty( $settings['enable_jxl'] ) || file_exists( $path_no_ext . '.jxl' );
                    if ( $webp_ok && $avif_ok && $jxl_ok ) $needs_conv = false;
                }
                if ( $needs_conv && ! henkan_is_file_excluded( $full_path ) ) {
                    $todo[] = $full_path;
                }
            }
        } catch ( Exception $e ) { continue; }
    }

    wp_send_json_success( [
        'total_scanned' => $total_scanned,
        'items'         => array_values( array_unique( $todo ) ),
        'after_id'      => max( 0, absint( $_POST['after_id'] ?? 0 ) ),
        'has_more'      => ! $finished,
        'phase'         => 'fs',
        'fs_cursor'     => $seen,
    ] );
}

/**
 * Folders scanned in the 'fs' phase: uploads, theme (if enabled) and allowed custom folders.
 */
function henkan_scan_paths( $settings ) {
    $scan_paths = [];
    if ( ! empty( $settings['scan_uploads_dir'] ) ) {
        $u = wp_get_upload_dir();
        if ( ! empty( $u['basedir'] ) ) $scan_paths[] = $u['basedir'];
    }
    if ( ! empty( $settings['scan_theme_dir'] ) ) {
        $scan_paths[] = get_stylesheet_directory();
    }
    foreach ( henkan_custom_roots( $settings ) as $c ) $scan_paths[] = $c;
    return array_values( array_filter( array_unique( $scan_paths ), 'is_dir' ) );
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
                    $msg = sprintf( __( 'ID %1$d optimized [%2$s]%3$s', 'henkan-webp-avif-converter' ), $id, $gen, $fail );
                } else {
                    $fail_reasons = empty( $res['failed'] ) ? __( 'Unknown error', 'henkan-webp-avif-converter' ) : implode( ', ', $res['failed'] );
                    /* translators: 1: ID, 2: Error reasons */
                    $msg = sprintf( __( 'ID %1$d completely failed: %2$s', 'henkan-webp-avif-converter' ), $id, $fail_reasons );
                }
            } else {
                /* translators: %d: ID */
                $msg = $res ? sprintf( __( 'ID %d optimiert', 'henkan-webp-avif-converter' ), $id ) : sprintf( __( 'ID %d checked', 'henkan-webp-avif-converter' ), $id );
            }
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            /* translators: %d: Attachment ID */
            $msg_err = sprintf( __( 'File missing ID %d', 'henkan-webp-avif-converter' ), $id );
            wp_send_json_error( [ 'msg' => $msg_err ] );
        }
    } else {
        $path = wp_normalize_path( $item );
        // Only allow files inside the configured scan roots
        if ( ! henkan_is_allowed_path( $path ) ) {
            wp_send_json_error( [ 'msg' => __( 'Path not allowed', 'henkan-webp-avif-converter' ) ] );
        }
        if ( file_exists( $path ) ) {
            $res = henkan_convert_file( $path, 0, 'custom' );
            if ( is_array( $res ) ) {
                if ( $res['success'] ) {
                    $gen = strtoupper( implode( ', ', $res['generated'] ) );
                    $fail = empty( $res['failed'] ) ? '' : __( ' | Failed: ', 'henkan-webp-avif-converter' ) . implode( ', ', $res['failed'] );
                    /* translators: 1: File name, 2: Generated formats, 3: Failed formats */
                    $msg = sprintf( __( '%1$s optimized [%2$s]%3$s', 'henkan-webp-avif-converter' ), basename( $path ), $gen, $fail );
                } else {
                    $fail_reasons = empty( $res['failed'] ) ? __( 'Unknown error', 'henkan-webp-avif-converter' ) : implode( ', ', $res['failed'] );
                    /* translators: 1: File name, 2: Error reasons */
                    $msg = sprintf( __( '%1$s completely failed: %2$s', 'henkan-webp-avif-converter' ), basename( $path ), $fail_reasons );
                }
            } else {
                /* translators: %s: File name */
                $msg = $res ? sprintf( __( '%s optimiert', 'henkan-webp-avif-converter' ), basename( $path ) ) : sprintf( __( '%s checked', 'henkan-webp-avif-converter' ), basename( $path ) );
            }
            wp_send_json_success( [ 'msg' => $msg ] );
        } else {
            // The folder scan also lists files of media-library images. If the attachment step
            // already converted the file and deleted the original, this is not an error.
            $stem = dirname( $path ) . '/' . pathinfo( $path, PATHINFO_FILENAME );
            foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
                if ( file_exists( $stem . '.' . $fmt ) ) {
                    /* translators: %s: File name */
                    wp_send_json_success( [ 'msg' => sprintf( __( '%s already converted', 'henkan-webp-avif-converter' ), basename( $path ) ) ] );
                }
            }
            /* translators: %s: File name */
            $msg_err = sprintf( __( 'Not found: %s', 'henkan-webp-avif-converter' ), basename( $path ) );
            wp_send_json_error( [ 'msg' => $msg_err ] );
        }
    }
}

function henkan_ajax_clear_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'Unauthorized' ] );
    }
    $settings = henkan_get_settings();
    check_ajax_referer( 'henkan_scan_nonce', 'nonce' );
    if ( empty( $settings['auto_clear_cache'] ) ) {
        wp_send_json_success( [ 'msg' => '' ] );
    }
    henkan_trigger_cache_clear();
    wp_send_json_success( [ 'msg' => __( 'Cache cleared successfully.', 'henkan-webp-avif-converter' ) ] );
}
