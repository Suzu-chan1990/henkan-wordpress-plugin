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
    $ext = strtolower( $path_info['extension'] ?? '' );
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
                // Keep the attachment metadata in sync with the new main file
                if ( ! empty( $metadata['file'] ) ) $metadata['file'] = $new_rel_path;
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

// Validate a source image before any decoding: real MIME type + pixel limit.
// Returns '' if the file is acceptable, otherwise an error string.
function henkan_validate_source( $source ) {
    if ( function_exists( 'mime_content_type' ) ) {
        $real_mime = @mime_content_type( $source );
        if ( ! in_array( $real_mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            return 'Security Reject: Invalid actual mime-type (' . sanitize_text_field( (string) $real_mime ) . ')';
        }
    } elseif ( function_exists( 'exif_imagetype' ) ) {
        $type = @exif_imagetype( $source );
        if ( $type !== IMAGETYPE_JPEG && $type !== IMAGETYPE_PNG ) {
            return 'Security Reject: Invalid exif_imagetype';
        }
    } else {
        return 'Security Reject: No server-side image type verifier available';
    }

    // Reject decompression bombs: small files that expand to gigabytes of pixels
    $dim = @getimagesize( $source );
    $max_pixels = (int) apply_filters( 'henkan_max_pixels', 50000000 );
    if ( ! $dim || empty( $dim[0] ) || empty( $dim[1] ) || ( (int) $dim[0] * (int) $dim[1] ) > $max_pixels ) {
        return 'Security Reject: Image dimensions invalid or too large';
    }
    return '';
}

// Checks once per request whether a command line converter is installed (instead of per image)
function henkan_binary_available( $bin, $version_arg = '--version' ) {
    static $cache = [];
    if ( isset( $cache[ $bin ] ) ) return $cache[ $bin ];
    if ( ! function_exists( 'exec' ) || ! is_callable( 'exec' ) ) return $cache[ $bin ] = false;
    $out = [];
    $rv  = 1;
    @exec( $bin . ' ' . $version_arg . ' 2>&1', $out, $rv );
    return $cache[ $bin ] = ( $rv === 0 );
}

// ROBUST GENERATION: TRIES ALL CONVERTERS IF ONE FAILS
function henkan_create_image( $source, $dest, $quality, $src_ext, $settings = null ) {
    $GLOBALS['henkan_last_error'] = '';
    if ( file_exists( $dest ) && filesize( $dest ) > 0 ) return true;

    // Strict source validation (MIME type + pixel limit)
    $invalid = henkan_validate_source( $source );
    if ( $invalid !== '' ) {
        $GLOBALS['henkan_last_error'] = $invalid;
        return false;
    }

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
                    if ( henkan_binary_available( 'cwebp', '-version' ) ) {
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
                    if ( henkan_binary_available( 'avifenc' ) ) {
                        $q = max( 0, min( 63, (int) round( ( 100 - $quality ) / 100 * 63 ) ) );
                        $cmd = sprintf( 'avifenc -q %d %s %s', $q, escapeshellarg( $source ), escapeshellarg( $dest ) );
                        @exec( $cmd, $out, $ret );
                        if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'avifenc failed';
                    } else $errors[] = 'avifenc missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'imagick' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    if ( henkan_binary_available( 'magick', '-version' ) ) {
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
                    if ( henkan_binary_available( 'cjxl' ) ) {
                        $cmd = sprintf( 'cjxl %s %s -q %d', escapeshellarg( $source ), escapeshellarg( $dest ), $quality );
                        @exec( $cmd, $out, $ret );
                        if ( $ret === 0 && file_exists( $dest ) && filesize( $dest ) > 0 ) return true;
                        $errors[] = 'cjxl failed';
                    } else $errors[] = 'cjxl missing';
                } else $errors[] = 'exec disabled';
            } elseif ( $method === 'imagick' ) {
                if ( function_exists( 'exec' ) && is_callable( 'exec' ) ) {
                    if ( henkan_binary_available( 'magick', '-version' ) ) {
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
    // Validate before decoding; create_image() will report the rejection
    if ( henkan_validate_source( $source ) !== '' ) return $source;

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
    // Unguessable temp file name (avoids symlink attacks in shared temp dirs)
    if ( ! function_exists( 'wp_tempnam' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp_base = wp_tempnam( 'henkan_resize' );
    $tmp = $tmp_base . '.' . $src_ext;
    wp_delete_file( $tmp_base );

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

function henkan_lock_key( $path, $id, $size_name ) {
    return 'henkan_lock_' . md5( wp_normalize_path( $path ) . '|' . (int) $id . '|' . (string) $size_name );
}

function henkan_acquire_lock( $path, $id, $size_name, $ttl = 1800 ) {
    $key = henkan_lock_key( $path, $id, $size_name );
    $token = wp_generate_uuid4();
    $now = time();
    $existing = get_option( $key, false );
    if ( is_array( $existing ) && ! empty( $existing['started'] ) && ( $now - (int) $existing['started'] ) < $ttl ) return false;
    if ( $existing !== false ) delete_option( $key );
    if ( ! add_option( $key, [ 'token' => $token, 'started' => $now ], '', false ) ) return false;
    return $token;
}

function henkan_release_lock( $path, $id, $size_name, $token ) {
    if ( ! $token ) return;
    $key = henkan_lock_key( $path, $id, $size_name );
    $current = get_option( $key, false );
    if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) delete_option( $key );
}
function henkan_convert_file( $path, $id, $size_name, $update_db = true ) {
    $lock_token = henkan_acquire_lock( $path, $id, $size_name );
    if ( ! $lock_token ) return ['success' => false, 'generated' => [], 'failed' => ['locked']];

    try {
    $settings = henkan_get_settings();
    $fmts = henkan_target_formats( $settings );
    if ( empty( $fmts ) ) return ['success' => false, 'generated' => [], 'failed' => []];

    $info = pathinfo( $path );
    $src_ext = strtolower( $info['extension'] ?? '' );
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
                    $att_meta = wp_get_attachment_metadata( $id );
                    if ( is_array( $att_meta ) && ! empty( $att_meta['file'] ) ) {
                        $att_meta['file'] = $new;
                        wp_update_attachment_metadata( $id, $att_meta );
                    }
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
                // Point the size in the attachment metadata to the new file BEFORE the JPEG/PNG
                // is deleted, otherwise WordPress keeps linking the deleted thumbnail (404).
                if ( $size_name !== 'original' && $size_name !== 'custom' ) {
                    $primary_fmt = henkan_get_primary_format( $generated );
                    $att_meta    = wp_get_attachment_metadata( $id );
                    if ( $primary_fmt !== '' && is_array( $att_meta ) && isset( $att_meta['sizes'][ $size_name ] ) ) {
                        $att_meta['sizes'][ $size_name ]['file']      = $info['filename'] . '.' . $primary_fmt;
                        $att_meta['sizes'][ $size_name ]['mime-type'] = henkan_target_mime( $primary_fmt );
                        wp_update_attachment_metadata( $id, $att_meta );
                    }
                }
                wp_delete_file( $path );
            }
        }
    }
    // Temporäre resize-Datei aufräumen, falls eine erstellt wurde
    if ( $actual_source !== $path && file_exists( $actual_source ) ) {
        wp_delete_file( $actual_source );
    }

    return ['success' => $any_success, 'generated' => $generated, 'failed' => $failed];
    } finally {
        henkan_release_lock( $path, $id, $size_name, $lock_token );
    }
}

add_action( 'delete_post', function( $pid ) {
    $meta = function_exists('henkan_get_data') ? henkan_get_data( $pid ) : false;
    if ( $meta && is_array( $meta ) ) {
        $file = get_attached_file( $pid );
        if ( $file ) {
            $base = dirname( $file );
            foreach ( $meta as $files ) {
                if ( is_array( $files ) ) {
                    // basename(): stored names are plain file names; never follow a path from the DB
                    foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
                        if ( isset( $files[ $fmt ] ) && is_string( $files[ $fmt ] ) && $files[ $fmt ] !== '' ) {
                            wp_delete_file( $base . '/' . basename( $files[ $fmt ] ) );
                        }
                    }
                }
            }
        }
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, no core API
    $wpdb->delete( $wpdb->prefix . 'henkan_data', ['attachment_id' => $pid] );
});

function henkan_trigger_cache_clear() {
    if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
    if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
    if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
    if ( class_exists( 'autoptimizeCache' ) ) autoptimizeCache::clearall();
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) LiteSpeed_Cache_API::purge_all();
}

/**
 * Repairs attachment metadata of images converted by older versions with "Keep original" off:
 * the bulk converter deleted the JPEG/PNG sizes but left them in the metadata (broken thumbnails).
 * Only sizes whose file is missing AND whose converted file exists are changed.
 *
 * @return bool True if the metadata was changed.
 */
function henkan_repair_attachment_metadata( $id ) {
    $file = get_attached_file( $id );
    $meta = wp_get_attachment_metadata( $id );
    if ( ! $file || ! is_array( $meta ) ) return false;
    $dir     = dirname( $file );
    $changed = false;

    // Main file entry
    $rel = get_post_meta( $id, '_wp_attached_file', true );
    if ( $rel && ! empty( $meta['file'] ) && $meta['file'] !== $rel && file_exists( $file ) && ! file_exists( $dir . '/' . basename( $meta['file'] ) ) ) {
        $meta['file'] = $rel;
        $changed = true;
    }

    // Sizes
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        foreach ( $meta['sizes'] as $size => $data ) {
            if ( empty( $data['file'] ) || ! preg_match( '/\.(jpe?g|png)$/i', $data['file'] ) ) continue;
            if ( file_exists( $dir . '/' . $data['file'] ) ) continue;
            $stem = pathinfo( $data['file'], PATHINFO_FILENAME );
            foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) { // same priority as henkan_get_primary_format()
                if ( file_exists( $dir . '/' . $stem . '.' . $fmt ) ) {
                    $meta['sizes'][ $size ]['file']      = $stem . '.' . $fmt;
                    $meta['sizes'][ $size ]['mime-type'] = henkan_target_mime( $fmt );
                    $changed = true;
                    break;
                }
            }
        }
    }

    if ( $changed ) wp_update_attachment_metadata( $id, $meta );
    return $changed;
}

/**
 * Background repair run (WP-Cron), once after updating to 2.4.2. Walks converted attachments
 * (WebP/AVIF/JXL main file) in small chunks.
 */
add_action( 'henkan_repair_metadata_event', 'henkan_repair_metadata_batch' );
function henkan_repair_metadata_batch() {
    global $wpdb;
    $state = get_option( 'henkan_metadata_repair', [ 'last_id' => 0, 'fixed' => 0 ] );
    if ( ! empty( $state['done'] ) ) return;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments by MIME type, one-time background job
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'attachment' AND post_mime_type IN ('image/webp','image/avif','image/jxl') ORDER BY ID ASC LIMIT 200",
        (int) $state['last_id']
    ) );
    foreach ( $ids as $id ) {
        if ( henkan_repair_attachment_metadata( (int) $id ) ) $state['fixed']++;
        $state['last_id'] = (int) $id;
    }
    if ( count( $ids ) === 200 ) {
        wp_schedule_single_event( time() + 10, 'henkan_repair_metadata_event' );
    } else {
        $state['done'] = time();
    }
    update_option( 'henkan_metadata_repair', $state, false );
}
