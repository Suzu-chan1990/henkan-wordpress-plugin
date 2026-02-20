<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'wp_generate_attachment_metadata', 'henkan_handle_upload', 10, 2 );

function henkan_handle_upload( $metadata, $attachment_id ) {
    $settings = henkan_get_settings();
    
    // Abbruch, wenn weder WebP noch AVIF aktiv ist
    if ( empty( $settings['enable_webp'] ) && empty( $settings['enable_avif'] ) ) return $metadata;
    
    // Dynamische Format-Wahl: AVIF hat Priorität, falls aktiviert
    $target_ext  = !empty( $settings['enable_avif'] ) ? 'avif' : 'webp';
    $target_mime = 'image/' . $target_ext;
    
    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) return $metadata;

    $path_info = pathinfo( $file );
    $ext = strtolower( $path_info['extension'] );
    
    if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ] ) ) return $metadata;

    // Hauptbild
    $target_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $target_ext;
    $success = henkan_create_image( $file, $target_path, $settings['quality'], $ext );

    if ( $success ) {
        // DB Updates (Dynamisch für WebP oder AVIF)
        $rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $rel_path ) {
            $new_rel_path = preg_replace( '/\.(jpe?g|png)$/i', '.' . $target_ext, $rel_path );
            update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );
        }

        wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => $target_mime ] );

        if ( isset( $metadata['file'] ) ) {
            $metadata['file'] = preg_replace( '/\.(jpe?g|png)$/i', '.' . $target_ext, $metadata['file'] );
        }

        $henkan_meta = [ 'original' => [ $target_ext => basename( $target_path ) ] ];
        update_post_meta( $attachment_id, '_henkan_converted_files', $henkan_meta );
        
        if ( empty( $settings['keep_original'] ) ) wp_delete_file( $file );
    }

    // Thumbnails
    if ( ! empty( $metadata['sizes'] ) ) {
        $base_dir = $path_info['dirname'];
        foreach ( $metadata['sizes'] as $size_name => $data ) {
            $thumb_file = $base_dir . '/' . $data['file'];
            $thumb_info = pathinfo( $thumb_file );
            
            // Ziel Pfad
            $thumb_target = $base_dir . '/' . $thumb_info['filename'] . '.' . $target_ext;
            
            if ( file_exists( $thumb_file ) ) {
                $thumb_success = henkan_create_image( $thumb_file, $thumb_target, $settings['quality'], $thumb_info['extension'] );
                
                if ( $thumb_success ) {
                    $metadata['sizes'][ $size_name ]['file'] = basename( $thumb_target );
                    $metadata['sizes'][ $size_name ]['mime-type'] = $target_mime;
                    if ( empty( $settings['keep_original'] ) ) wp_delete_file( $thumb_file );
                }
            }
        }
    }

    if ( ! empty( $settings['auto_clear_cache'] ) ) henkan_trigger_cache_clear();

    return $metadata;
}

function henkan_create_image( $source, $dest, $quality, $ext ) {
    if ( file_exists( $dest ) ) return true;
    
    // VERSUCH 1: cwebp (Systembefehl)
    if ( function_exists( 'exec' ) ) {
        $command_check = 'cwebp -version'; 
        @exec( $command_check, $output, $return_var );
        
        if ( $return_var === 0 ) {
            $cmd = sprintf( 'cwebp -q %d -m 2 %s -o %s -quiet', 
                intval( $quality ), 
                escapeshellarg( $source ), 
                escapeshellarg( $dest )
            );
            
            @exec( $cmd, $out, $ret );
            if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) {
                return true;
            }
        }
    }

    // FALLBACK: GD Library
    $content = @file_get_contents( $source );
    if ( ! $content ) return false;
    
    $img = @imagecreatefromstring( $content );
    if ( ! $img ) return false;

    if ( $ext === 'png' ) {
        imagepalettetotruecolor( $img );
        imagealphablending( $img, true );
        imagesavealpha( $img, true );
    }

    $result = false;
    if ( function_exists( 'imagewebp' ) ) {
        $result = imagewebp( $img, $dest, $quality );
    }
    
    @imagedestroy( $img );
    return $result && file_exists( $dest );
}

function henkan_convert_file( $path, $id, $size_name, $update_db = true ) {
    $settings = henkan_get_settings();
    $target_ext  = !empty( $settings['enable_avif'] ) ? 'avif' : 'webp';
    $target_mime = 'image/' . $target_ext;

    $info     = pathinfo( $path );
    $target_path = $info['dirname'] . '/' . $info['filename'] . '.' . $target_ext;
    
    $success = henkan_create_image( $path, $target_path, $settings['quality'], $info['extension'] );
    
    if ( $success && $id > 0 && $update_db ) {
        if ( $size_name === 'original' ) {
            $rel = get_post_meta( $id, '_wp_attached_file', true );
            $new = preg_replace( '/\.(jpe?g|png)$/i', '.' . $target_ext, $rel );
            update_post_meta( $id, '_wp_attached_file', $new );
            wp_update_post( [ 'ID' => $id, 'post_mime_type' => $target_mime ] );
        }
        
        $meta = get_post_meta( $id, '_henkan_converted_files', true ) ?: [];
        $meta[ $size_name ][$target_ext] = basename( $target_path );
        update_post_meta( $id, '_henkan_converted_files', $meta );
        
        if ( empty( $settings['keep_original'] ) ) wp_delete_file( $path );
    }
    return $success;
}

add_action( 'delete_post', function( $pid ) {
    $meta = get_post_meta( $pid, '_henkan_converted_files', true );
    if ( $meta ) {
        $file = get_attached_file( $pid );
        if ( $file ) {
            $base = dirname( $file );
            foreach ( $meta as $files ) {
                if ( isset( $files['webp'] ) ) wp_delete_file( $base . '/' . $files['webp'] );
                if ( isset( $files['avif'] ) ) wp_delete_file( $base . '/' . $files['avif'] );
            }
        }
    }
});

function henkan_trigger_cache_clear() {
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
    if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
    if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
    if ( class_exists( 'autoptimizeCache' ) ) autoptimizeCache::clearall();
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) LiteSpeed_Cache_API::purge_all();
}
