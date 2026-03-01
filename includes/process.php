<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'wp_generate_attachment_metadata', 'henkan_handle_upload', 10, 2 );



function henkan_target_format( $settings ) {
    if ( ! empty( $settings['enable_avif'] ) ) return 'avif';
    if ( ! empty( $settings['enable_webp'] ) ) return 'webp';
    return '';
}
function henkan_target_mime( $fmt ) {
    return ( $fmt === 'avif' ) ? 'image/avif' : 'image/webp';
}
function henkan_set_state( $attachment_id, $state, $error = '' ) {
    if ( $attachment_id <= 0 ) return;
    update_post_meta( $attachment_id, '_henkan_state', $state );
    if ( $error !== '' ) update_post_meta( $attachment_id, '_henkan_last_error', $error );
    else delete_post_meta( $attachment_id, '_henkan_last_error' );
}
function henkan_handle_upload( $metadata, $attachment_id ) {
    $settings = henkan_get_settings();
    $fmt = henkan_target_format( $settings );
    if ( $fmt === '' ) return $metadata;

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) return $metadata;

    $path_info = pathinfo( $file );
    $ext = strtolower( $path_info['extension'] );
    if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return $metadata;

    $target_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $fmt;

    $success = henkan_create_image( $file, $target_path, $settings['quality'], $ext, $settings );

    if ( $success ) {
        $rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $rel_path ) {
            $new_rel_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.' . $fmt, $rel_path );
            update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );
        }

        wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => henkan_target_mime( $fmt ) ] );

        if ( isset( $metadata['file'] ) ) {
            $metadata['file'] = preg_replace( '/\.(jpg|jpeg|png)$/i', '.' . $fmt, $metadata['file'] );
        }

        $henkan_meta = [ 'original' => [ $fmt => basename( $target_path ) ] ];
        update_post_meta( $attachment_id, '_henkan_converted_files', $henkan_meta );

        henkan_set_state( $attachment_id, 'ok', '' );

        if ( empty( $settings['keep_original'] ) ) wp_delete_file( $file );
    } else {
        $err = isset( $GLOBALS['_henkan_last_error'] ) ? (string) $GLOBALS['_henkan_last_error'] : '';
        henkan_set_state( $attachment_id, 'failed', $err );
    }

    if ( ! empty( $metadata['sizes'] ) ) {
        $base_dir = $path_info['dirname'];
        foreach ( $metadata['sizes'] as $size_name => $data ) {
            if ( empty( $data['file'] ) ) continue;

            $thumb_file = $base_dir . '/' . $data['file'];
            if ( ! file_exists( $thumb_file ) ) continue;

            $thumb_info = pathinfo( $thumb_file );
            $thumb_ext  = strtolower( $thumb_info['extension'] );

            if ( ! in_array( $thumb_ext, [ 'jpg', 'jpeg', 'png' ], true ) ) continue;

            $thumb_target = $base_dir . '/' . $thumb_info['filename'] . '.' . $fmt;
            $thumb_success = henkan_create_image( $thumb_file, $thumb_target, $settings['quality'], $thumb_ext, $settings );

            if ( $thumb_success ) {
                $metadata['sizes'][ $size_name ]['file'] = basename( $thumb_target );
                $metadata['sizes'][ $size_name ]['mime-type'] = henkan_target_mime( $fmt );

                $meta = get_post_meta( $attachment_id, '_henkan_converted_files', true ) ?: [];
                $meta[ $size_name ][ $fmt ] = basename( $thumb_target );
                update_post_meta( $attachment_id, '_henkan_converted_files', $meta );

                if ( empty( $settings['keep_original'] ) ) wp_delete_file( $thumb_file );
            }
        }
    }

    if ( ! empty( $settings['auto_clear_cache'] ) ) henkan_trigger_cache_clear();

    return $metadata;
}

function henkan_create_image( $source, $dest, $quality, $src_ext, $settings = null ) {
    $GLOBALS['_henkan_last_error'] = '';
    if ( file_exists( $dest ) && filesize( $dest ) > 0 ) return true; // missing-only

    $target_ext = strtolower( pathinfo( $dest, PATHINFO_EXTENSION ) );
    $src_ext = strtolower( (string) $src_ext );

    $quality = intval( $quality );
    if ( $quality < 1 ) $quality = 1;
    if ( $quality > 100 ) $quality = 100;

    $settings = is_array( $settings ) ? $settings : henkan_get_settings();

    if ( $target_ext === 'webp' ) {
        $conv = $settings['webp_converter'] ?? 'cwebp';

        if ( $conv === 'cwebp' ) {
            if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                @exec( 'cwebp -version', $o, $rv );
                if ( $rv === 0 ) {
                    $cmd = sprintf( 'cwebp -q %d -m 2 %s -o %s -quiet',
                        $quality,
                        escapeshellarg( $source ),
                        escapeshellarg( $dest )
                    );
                    @exec( $cmd, $out, $ret );
                    if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                    $GLOBALS['_henkan_last_error'] = 'cwebp failed';
                    return false;
                }
                $GLOBALS['_henkan_last_error'] = 'cwebp not available';
                return false;
            }
            $GLOBALS['_henkan_last_error'] = 'exec not available';
            return false;
        }

        if ( $conv === 'gd' ) {
            if ( ! function_exists( 'imagewebp' ) ) {
                $GLOBALS['_henkan_last_error'] = 'GD imagewebp() not available';
                return false;
            }
            $content = @file_get_contents( $source );
            if ( ! $content ) { $GLOBALS['_henkan_last_error'] = 'read source failed'; return false; }

            $img = @imagecreatefromstring( $content );
            if ( ! $img ) { $GLOBALS['_henkan_last_error'] = 'imagecreatefromstring failed'; return false; }

            if ( $src_ext === 'png' ) {
                imagepalettetotruecolor( $img );
                imagealphablending( $img, true );
                imagesavealpha( $img, true );
            }

            $ok = @imagewebp( $img, $dest, $quality );
            @imagedestroy( $img );

            if ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
            $GLOBALS['_henkan_last_error'] = 'GD imagewebp failed';
            return false;
        }

        $GLOBALS['_henkan_last_error'] = 'Unknown WebP converter';
        return false;
    }

    if ( $target_ext === 'avif' ) {
        $conv = $settings['avif_converter'] ?? 'avifenc';

        if ( $conv === 'avifenc' ) {
            if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                @exec( 'avifenc --version', $o, $rv );
                if ( $rv === 0 ) {
                    $q = (int) round( ( 100 - $quality ) / 100 * 63 );
                    if ( $q < 0 ) $q = 0;
                    if ( $q > 63 ) $q = 63;

                    $cmd = sprintf( 'avifenc -q %d %s %s', $q, escapeshellarg( $source ), escapeshellarg( $dest ) );
                    @exec( $cmd, $out, $ret );
                    if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;

                    $GLOBALS['_henkan_last_error'] = 'avifenc failed';
                    return false;
                }
                $GLOBALS['_henkan_last_error'] = 'avifenc not available';
                return false;
            }
            $GLOBALS['_henkan_last_error'] = 'exec not available';
            return false;
        }

        if ( $conv === 'imagick' ) {
            if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                @exec( 'magick -version', $o2, $rv2 );
                if ( $rv2 === 0 ) {
                    $cmd = sprintf( 'magick %s -quality %d %s', escapeshellarg( $source ), $quality, escapeshellarg( $dest ) );
                    @exec( $cmd, $out2, $ret2 );
                    if ( $ret2 === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                    $GLOBALS['_henkan_last_error'] = 'magick failed';
                    return false;
                }

                @exec( 'convert -version', $o3, $rv3 );
                if ( $rv3 === 0 ) {
                    $cmd = sprintf( 'convert %s -quality %d %s', escapeshellarg( $source ), $quality, escapeshellarg( $dest ) );
                    @exec( $cmd, $out3, $ret3 );
                    if ( $ret3 === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                    $GLOBALS['_henkan_last_error'] = 'convert failed';
                    return false;
                }

                $GLOBALS['_henkan_last_error'] = 'ImageMagick not available';
                return false;
            }
            $GLOBALS['_henkan_last_error'] = 'exec not available';
            return false;
        }

        if ( $conv === 'gd' ) {
            if ( ! function_exists( 'imageavif' ) ) {
                $GLOBALS['_henkan_last_error'] = 'GD imageavif() not available';
                return false;
            }
            $content = @file_get_contents( $source );
            if ( ! $content ) { $GLOBALS['_henkan_last_error'] = 'read source failed'; return false; }

            $img = @imagecreatefromstring( $content );
            if ( ! $img ) { $GLOBALS['_henkan_last_error'] = 'imagecreatefromstring failed'; return false; }

            if ( $src_ext === 'png' ) {
                imagepalettetotruecolor( $img );
                imagealphablending( $img, true );
                imagesavealpha( $img, true );
            }

            $ok = @imageavif( $img, $dest, $quality );
            @imagedestroy( $img );

            if ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
            $GLOBALS['_henkan_last_error'] = 'GD imageavif failed';
            return false;
        }

        $GLOBALS['_henkan_last_error'] = 'Unknown AVIF converter';
        return false;
    }

    $GLOBALS['_henkan_last_error'] = 'Unknown target format';
    return false;
}

function henkan_convert_file( $path, $id, $size_name, $update_db = true ) {
    $settings = henkan_get_settings();
    $fmt = henkan_target_format( $settings );
    if ( $fmt === '' ) return false;

    $info = pathinfo( $path );
    $src_ext = strtolower( $info['extension'] );
    if ( ! in_array( $src_ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return false;

    $target_path = $info['dirname'] . '/' . $info['filename'] . '.' . $fmt;
    $success = henkan_create_image( $path, $target_path, $settings['quality'], $src_ext, $settings );

    if ( $id > 0 && $update_db ) {
        if ( $success && $size_name === 'original' ) {
            $rel = get_post_meta( $id, '_wp_attached_file', true );
            if ( $rel ) {
                $new = preg_replace( '/\.(jpg|jpeg|png)$/i', '.' . $fmt, $rel );
                update_post_meta( $id, '_wp_attached_file', $new );
            }
            wp_update_post( [ 'ID' => $id, 'post_mime_type' => henkan_target_mime( $fmt ) ] );
            henkan_set_state( $id, 'ok', '' );
        } elseif ( ! $success && $size_name === 'original' ) {
            $err = isset( $GLOBALS['_henkan_last_error'] ) ? (string) $GLOBALS['_henkan_last_error'] : '';
            henkan_set_state( $id, 'failed', $err );
        }

        if ( $success ) {
            $meta = get_post_meta( $id, '_henkan_converted_files', true ) ?: [];
            $meta[ $size_name ][ $fmt ] = basename( $target_path );
            update_post_meta( $id, '_henkan_converted_files', $meta );

            if ( empty( $settings['keep_original'] ) ) wp_delete_file( $path );
        }
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
