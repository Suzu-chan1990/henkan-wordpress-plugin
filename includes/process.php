<?php
defined( 'ABSPATH' ) || exit;

add_filter( 'wp_generate_attachment_metadata', 'henkan_handle_upload', 10, 2 );

// CORRECT GENERATION ORDER
function henkan_target_formats( $settings ) {
    $fmts = [];
    if ( ! empty( $settings['enable_webp'] ) ) $fmts[] = 'webp';
    if ( ! empty( $settings['enable_avif'] ) ) $fmts[] = 'avif';
    if ( ! empty( $settings['enable_jxl'] ) ) $fmts[] = 'jxl';
    return $fmts;
}

function henkan_target_mime( $fmt ) {
    if ( $fmt === 'jxl' ) return 'image/jxl';
    if ( $fmt === 'avif' ) return 'image/avif';
    return 'image/webp';
}

function henkan_set_state( $attachment_id, $state, $error = '' ) {
    if ( $attachment_id <= 0 ) return;
    update_post_meta( $attachment_id, '_henkan_state', $state );
    if ( $error !== '' ) update_post_meta( $attachment_id, 'henkan_last_error', $error );
    else delete_post_meta( $attachment_id, 'henkan_last_error' );
}

// STRICT PRIORITY WHEN ORIGINALS ARE DELETED: WEBP > AVIF > JXL
function henkan_get_primary_format( $generated ) {
    if ( in_array( 'webp', $generated, true ) ) return 'webp';
    if ( in_array( 'avif', $generated, true ) ) return 'avif';
    if ( in_array( 'jxl', $generated, true ) ) return 'jxl';
    return '';
}

function henkan_handle_upload( $metadata, $attachment_id ) {
    $settings = henkan_get_settings();
    $fmts = henkan_target_formats( $settings );
    if ( empty( $fmts ) ) return $metadata;

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) return $metadata;

    $path_info = pathinfo( $file );
    $ext = strtolower( $path_info['extension'] );
    if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return $metadata;

    if ( function_exists('henkan_is_file_excluded') && henkan_is_file_excluded( $file ) ) return $metadata;

    $any_success = false;
    $generated = [];
    $henkan_meta = henkan_get_data( $attachment_id ) ?: [];
    if ( ! is_array( $henkan_meta ) ) $henkan_meta = [];
    if ( ! isset( $henkan_meta['original'] ) ) $henkan_meta['original'] = [];

    foreach ( $fmts as $fmt ) {
        $target_path = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $fmt;
        $success = henkan_create_image( $file, $target_path, $settings['quality'], $ext, $settings );
        if ( $success ) {
            $henkan_meta['original'][$fmt] = basename( $target_path );
            $any_success = true;
            $generated[] = $fmt;
        }
    }

    if ( $any_success ) {
        henkan_update_data( $attachment_id, $henkan_meta );
        henkan_set_state( $attachment_id, 'ok', '' );

        if ( empty( $settings['keep_original'] ) ) {
            $primary_fmt = henkan_get_primary_format( $generated );
            $rel_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
            
            if ( $rel_path && $primary_fmt !== '' ) {
                $new_rel_path = preg_replace( '/\.(jpg|jpeg|png)$/i', '.' . $primary_fmt, $rel_path );
                update_post_meta( $attachment_id, '_wp_attached_file', $new_rel_path );
            }
            if ( $primary_fmt !== '' ) {
                wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => henkan_target_mime( $primary_fmt ) ] );
            }
            wp_delete_file( $file );
        }
    } else {
        $err = isset( $GLOBALS['henkan_last_error'] ) ? (string) $GLOBALS['henkan_last_error'] : '';
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

            $thumb_any_success = false;
            $thumb_generated = [];

            if ( ! isset( $henkan_meta[$size_name] ) ) $henkan_meta[$size_name] = [];

            foreach ( $fmts as $fmt ) {
                $target_path = $base_dir . '/' . $thumb_info['filename'] . '.' . $fmt;
                $success = henkan_create_image( $thumb_file, $target_path, $settings['quality'], $thumb_ext, $settings );
                if ( $success ) {
                    $henkan_meta[$size_name][$fmt] = basename( $target_path );
                    $thumb_any_success = true;
                    $thumb_generated[] = $fmt;
                }
            }

            if ( $thumb_any_success ) {
                henkan_update_data( $attachment_id, $henkan_meta );
                if ( empty( $settings['keep_original'] ) ) {
                    $thumb_primary_fmt = henkan_get_primary_format( $thumb_generated );
                    if ( $thumb_primary_fmt !== '' ) {
                        $metadata['sizes'][ $size_name ]['file'] = basename( $base_dir . '/' . $thumb_info['filename'] . '.' . $thumb_primary_fmt );
                        $metadata['sizes'][ $size_name ]['mime-type'] = henkan_target_mime( $thumb_primary_fmt );
                    }
                    wp_delete_file( $thumb_file );
                }
            }
        }
    }

    if ( ! empty( $settings['auto_clear_cache'] ) ) henkan_trigger_cache_clear();

    return $metadata;
}

// ROBUST GENERATION: TRIES ALL CONVERTERS IF ONE FAILS
function henkan_create_image( $source, $dest, $quality, $src_ext, $settings = null ) {
    $GLOBALS['henkan_last_error'] = '';
    if ( file_exists( $dest ) && filesize( $dest ) > 0 ) return true;

    $target_ext = strtolower( pathinfo( $dest, PATHINFO_EXTENSION ) );
    $src_ext = strtolower( (string) $src_ext );

    $quality = max( 1, min( 100, intval( $quality ) ) );
    $settings = is_array( $settings ) ? $settings : henkan_get_settings();

    // WEBP ENGINE WITH FALLBACKS
    if ( $target_ext === 'webp' ) {
        $pref = isset( $settings['webp_converter'] ) ? $settings['webp_converter'] : 'cwebp';
        $methods = array_unique( [ $pref, 'gd', 'cwebp' ] );
        $errors = [];
        foreach ( $methods as $method ) {
            if ( $method === 'cwebp' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    @exec( 'cwebp -version', $o, $rv );
                    if ( $rv === 0 ) {
                        $cmd = sprintf( 'cwebp -q %d -m 2 %s -o %s -quiet', $quality, escapeshellarg( $source ), escapeshellarg( $dest ) );
                        @exec( $cmd, $out, $ret );
                        if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'cwebp failed';
                    } else $errors[] = 'cwebp missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'gd' ) {
                if ( function_exists( 'imagewebp' ) ) {
                    $content = @file_get_contents( $source );
                    if ( $content ) {
                        $img = @imagecreatefromstring( $content );
                        if ( $img ) {
                            if ( $src_ext === 'png' ) {
                                imagepalettetotruecolor( $img );
                                imagealphablending( $img, true );
                                imagesavealpha( $img, true );
                            }
                            $ok = @imagewebp( $img, $dest, $quality );
                            @imagedestroy( $img );
                            if ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                            $errors[] = 'gd write failed';
                        } else $errors[] = 'gd decode failed';
                    } else $errors[] = 'read failed';
                } else $errors[] = 'gd missing';
            }
        }
        $GLOBALS['henkan_last_error'] = implode( ' | ', array_unique( $errors ) );
        return false;
    }

    // AVIF ENGINE WITH FALLBACKS
    if ( $target_ext === 'avif' ) {
        $pref = isset( $settings['avif_converter'] ) ? $settings['avif_converter'] : 'gd';
        $methods = array_unique( [ $pref, 'gd', 'avifenc', 'imagick' ] );
        $errors = [];
        foreach ( $methods as $method ) {
            if ( $method === 'avifenc' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    @exec( 'avifenc --version', $o, $rv );
                    if ( $rv === 0 ) {
                        $q = max( 0, min( 63, (int) round( ( 100 - $quality ) / 100 * 63 ) ) );
                        $cmd = sprintf( 'avifenc -q %d %s %s', $q, escapeshellarg( $source ), escapeshellarg( $dest ) );
                        @exec( $cmd, $out, $ret );
                        if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'avifenc failed';
                    } else $errors[] = 'avifenc missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'imagick' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    @exec( 'magick -version', $o2, $rv2 );
                    if ( $rv2 === 0 ) {
                        $cmd = sprintf( 'magick %s -quality %d %s', escapeshellarg( $source ), $quality, escapeshellarg( $dest ) );
                        @exec( $cmd, $out2, $ret2 );
                        if ( $ret2 === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'magick failed';
                    } else $errors[] = 'magick missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'gd' ) {
                if ( function_exists( 'imageavif' ) ) {
                    $content = @file_get_contents( $source );
                    if ( $content ) {
                        $img = @imagecreatefromstring( $content );
                        if ( $img ) {
                            if ( $src_ext === 'png' ) {
                                imagepalettetotruecolor( $img );
                                imagealphablending( $img, true );
                                imagesavealpha( $img, true );
                            }
                            $ok = @imageavif( $img, $dest, $quality );
                            @imagedestroy( $img );
                            if ( $ok && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                            $errors[] = 'gd write failed';
                        } else $errors[] = 'gd decode failed';
                    } else $errors[] = 'read failed';
                } else $errors[] = 'PHP 8.1+ imageavif() missing';
            }
        }
        $GLOBALS['henkan_last_error'] = implode( ' | ', array_unique( $errors ) );
        return false;
    }

    // JXL ENGINE WITH FALLBACKS
    if ( $target_ext === 'jxl' ) {
        $pref = isset( $settings['jxl_converter'] ) ? $settings['jxl_converter'] : 'cjxl';
        $methods = array_unique( [ $pref, 'cjxl', 'imagick' ] );
        $errors = [];
        foreach ( $methods as $method ) {
            if ( $method === 'cjxl' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    @exec( 'cjxl --version', $o, $rv );
                    if ( $rv === 0 ) {
                        $cmd = sprintf( 'cjxl %s %s -q %d', escapeshellarg( $source ), escapeshellarg( $dest ), $quality );
                        @exec( $cmd, $out, $ret );
                        if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'cjxl failed';
                    } else $errors[] = 'cjxl missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'imagick' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    @exec( 'magick -version', $o2, $rv2 );
                    if ( $rv2 === 0 ) {
                        $cmd = sprintf( 'magick %s -quality %d %s', escapeshellarg( $source ), $quality, escapeshellarg( $dest ) );
                        @exec( $cmd, $out2, $ret2 );
                        if ( $ret2 === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'magick failed';
                    } else $errors[] = 'magick missing';
                } else $errors[] = 'exec disabled';
            }
        }
        $GLOBALS['henkan_last_error'] = implode( ' | ', array_unique( $errors ) );
        return false;
    }

    $GLOBALS['henkan_last_error'] = 'Unknown target format';
    return false;
}

/**
 * Smart Option: Max Output Width
 * Skaliert das Quellbild vor der Konversion auf max. $max_width Pixel Breite,
 * falls es breiter ist. Gibt den Pfad zur (ggf. temporären) Quelldatei zurück.
 * Die Originaldatei wird niemals verändert.
 *
 * @param string $source    Pfad zur Originaldatei.
 * @param int    $max_width Maximale Breite in Pixel.
 * @param string $src_ext   Dateiendung (jpg/jpeg/png).
 * @return string Pfad zur skalierten Datei (temporär) oder zur Originaldatei.
 */
function henkan_maybe_resize_for_conversion( $source, $max_width, $src_ext ) {
    if ( $max_width <= 0 ) return $source;

    $img = null;
    if ( in_array( $src_ext, [ 'jpg', 'jpeg' ], true ) && function_exists( 'imagecreatefromjpeg' ) ) {
        $img = @imagecreatefromjpeg( $source );
    } elseif ( $src_ext === 'png' && function_exists( 'imagecreatefrompng' ) ) {
        $img = @imagecreatefrompng( $source );
    }

    if ( ! $img ) return $source;

    $orig_w = imagesx( $img );
    $orig_h = imagesy( $img );
    imagedestroy( $img );

    if ( $orig_w <= $max_width ) return $source; // Bild ist bereits schmal genug

    // Skalierung berechnen (Seitenverhältnis beibehalten)
    $new_w = $max_width;
    $new_h = (int) round( $orig_h * ( $max_width / $orig_w ) );

    // Temporäre Datei anlegen
    $tmp = sys_get_temp_dir() . '/henkan_resize_' . md5( $source ) . '.' . $src_ext;

    $resized = imagecreatetruecolor( $new_w, $new_h );
    if ( ! $resized ) return $source;

    // PNG-Transparenz erhalten
    if ( $src_ext === 'png' ) {
        imagealphablending( $resized, false );
        imagesavealpha( $resized, true );
        $transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
        imagefilledrectangle( $resized, 0, 0, $new_w, $new_h, $transparent );
    }

    if ( in_array( $src_ext, [ 'jpg', 'jpeg' ], true ) ) {
        $orig_img = @imagecreatefromjpeg( $source );
    } else {
        $orig_img = @imagecreatefrompng( $source );
    }

    if ( ! $orig_img ) { imagedestroy( $resized ); return $source; }

    imagecopyresampled( $resized, $orig_img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h );
    imagedestroy( $orig_img );

    if ( in_array( $src_ext, [ 'jpg', 'jpeg' ], true ) ) {
        @imagejpeg( $resized, $tmp, 95 );
    } else {
        @imagepng( $resized, $tmp, 0 );
    }
    imagedestroy( $resized );

    return file_exists( $tmp ) ? $tmp : $source;
}

function henkan_convert_file( $path, $id, $size_name, $update_db = true ) {
    $settings = henkan_get_settings();
    $fmts = henkan_target_formats( $settings );
    if ( empty( $fmts ) ) return ['success' => false, 'generated' => [], 'failed' => []];

    $info = pathinfo( $path );
    $src_ext = strtolower( $info['extension'] );
    if ( ! in_array( $src_ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return ['success' => false, 'generated' => [], 'failed' => []];

    if ( function_exists('henkan_is_file_excluded') && henkan_is_file_excluded( $path ) ) return ['success' => false, 'generated' => [], 'failed' => ['excluded']];

    // Smart Option: Max Output Width
    $actual_source = $path;
    if ( ! empty( $settings['max_width_enabled'] ) && ! empty( $settings['max_width_px'] ) ) {
        $actual_source = henkan_maybe_resize_for_conversion( $path, (int) $settings['max_width_px'], $src_ext );
    }

    // Smart Option: Quality by Image Size
    // Thumbnails (WP-Standard-Größen) bekommen eine niedrigere Qualität,
    // ABER nur wenn die Option aktiviert ist UND es sich um eine bekannte WP-Crop-Größe handelt.
    $quality = (int) $settings['quality'];
    if (
        ! empty( $settings['smart_quality_enabled'] ) &&
        ! empty( $settings['smart_quality_thumb'] ) &&
        $size_name !== 'original' &&
        $size_name !== 'custom'
    ) {
        // Prüfen ob es eine registrierte WP-Thumbnail-Größe ist (nicht eine custom size)
        $registered_sizes = wp_get_registered_image_subsizes();
        if ( isset( $registered_sizes[ $size_name ] ) ) {
            $quality = (int) $settings['smart_quality_thumb'];
        }
        // Falls die Größe NICHT in den registrierten WP-Sizes ist → globale Qualität beibehalten
    }

    $any_success = false;
    $generated = [];
    $failed = [];
    
    $meta = [];
    if ( $id > 0 ) {
        $meta = henkan_get_data( $id ) ?: [];
        if ( ! is_array( $meta ) ) $meta = [];
    }

    foreach ( $fmts as $fmt ) {
        $target_path = $info['dirname'] . '/' . $info['filename'] . '.' . $fmt;
        $success = henkan_create_image( $actual_source, $target_path, $quality, $src_ext, $settings );
        if ( $success ) {
            if ( ! isset( $meta[$size_name] ) ) $meta[$size_name] = [];
            $meta[$size_name][$fmt] = basename( $target_path );
            $any_success = true;
            $generated[] = $fmt;
        } else {
            $err_reason = isset( $GLOBALS['henkan_last_error'] ) ? $GLOBALS['henkan_last_error'] : 'error';
            $failed[] = $fmt . ' (' . $err_reason . ')';
        }
    }

    if ( $id > 0 && $update_db ) {
        if ( $any_success && $size_name === 'original' ) {
            henkan_set_state( $id, 'ok', '' );

            if ( empty( $settings['keep_original'] ) ) {
                $primary_fmt = henkan_get_primary_format( $generated );
                $rel = get_post_meta( $id, '_wp_attached_file', true );
                if ( $rel && $primary_fmt !== '' ) {
                    $new = preg_replace( '/\.(jpg|jpeg|png)$/i', '.' . $primary_fmt, $rel );
                    update_post_meta( $id, '_wp_attached_file', $new );
                }
                if ( $primary_fmt !== '' ) {
                    wp_update_post( [ 'ID' => $id, 'post_mime_type' => henkan_target_mime( $primary_fmt ) ] );
                }
            }
        } elseif ( ! $any_success && $size_name === 'original' ) {
            $err = isset( $GLOBALS['henkan_last_error'] ) ? (string) $GLOBALS['henkan_last_error'] : '';
            henkan_set_state( $id, 'failed', $err );
        }

        if ( $any_success ) {
            henkan_update_data( $id, $meta );
            if ( empty( $settings['keep_original'] ) ) {
                wp_delete_file( $path );
            }
        }
    }
    // Temporäre resize-Datei aufräumen, falls eine erstellt wurde
    if ( $actual_source !== $path && file_exists( $actual_source ) ) {
        @unlink( $actual_source );
    }

    return ['success' => $any_success, 'generated' => $generated, 'failed' => $failed];
}

add_action( 'delete_post', function( $pid ) {
    $meta = function_exists('henkan_get_data') ? henkan_get_data( $pid ) : false;
    if ( $meta && is_array( $meta ) ) {
        $file = get_attached_file( $pid );
        if ( $file ) {
            $base = dirname( $file );
            foreach ( $meta as $files ) {
                if ( is_array( $files ) ) {
                    if ( isset( $files['webp'] ) ) wp_delete_file( $base . '/' . $files['webp'] );
                    if ( isset( $files['avif'] ) ) wp_delete_file( $base . '/' . $files['avif'] );
                    if ( isset( $files['jxl'] ) ) wp_delete_file( $base . '/' . $files['jxl'] );
                }
            }
        }
    }
    global $wpdb;
    $wpdb->delete( $wpdb->prefix . 'henkan_data', ['attachment_id' => $pid] );
});

function henkan_trigger_cache_clear() {
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
    if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
    if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
    if ( class_exists( 'autoptimizeCache' ) ) autoptimizeCache::clearall();
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) LiteSpeed_Cache_API::purge_all();
}
