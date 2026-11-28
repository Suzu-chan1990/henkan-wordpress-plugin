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
// (the most compatible format becomes the main file; browser delivery uses AVIF > WebP > JXL)
function henkan_get_primary_format( $generated ) {
    if ( in_array( 'webp', $generated, true ) ) return 'webp';
    if ( in_array( 'avif', $generated, true ) ) return 'avif';
    if ( in_array( 'jxl', $generated, true ) ) return 'jxl';
    return '';
}

/**
 * Converts new uploads. Uses the same pipeline as bulk conversion (henkan_convert_file), so
 * Max Output Width, Quality by Image Size and the size check also apply to uploads.
 * The returned $metadata is what WordPress saves, so all main file / size changes are mirrored here.
 */
function henkan_handle_upload( $metadata, $attachment_id ) {
    $settings = henkan_get_settings();
    if ( empty( henkan_target_formats( $settings ) ) ) return $metadata;

    $file = get_attached_file( $attachment_id );
    if ( ! $file || ! file_exists( $file ) ) return $metadata;

    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return $metadata;
    if ( henkan_is_file_excluded( $file ) ) return $metadata;

    $base_dir = dirname( $file );
    $res = henkan_convert_file( $file, $attachment_id, 'original' );

    if ( ! empty( $res['success'] ) && empty( $settings['keep_original'] ) && ! empty( $metadata['file'] ) ) {
        // henkan_convert_file() already switched _wp_attached_file to the new main file
        $rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $rel ) $metadata['file'] = $rel;
    }

    if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size_name => $data ) {
            if ( empty( $data['file'] ) ) continue;
            $thumb_file = $base_dir . '/' . $data['file'];
            if ( ! file_exists( $thumb_file ) ) continue;
            $r = henkan_convert_file( $thumb_file, $attachment_id, $size_name );
            if ( ! empty( $r['success'] ) && empty( $settings['keep_original'] ) ) {
                $primary = henkan_get_primary_format( $r['generated'] );
                if ( $primary !== '' ) {
                    $metadata['sizes'][ $size_name ]['file']      = pathinfo( $data['file'], PATHINFO_FILENAME ) . '.' . $primary;
                    $metadata['sizes'][ $size_name ]['mime-type'] = henkan_target_mime( $primary );
                }
            }
        }
    }

    // List the converted files for offload plugins (uploaded with the attachment right after this)
    $metadata = henkan_sources_meta( $metadata, (int) $attachment_id );

    do_action( 'henkan_attachment_converted', (int) $attachment_id, $res );
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

// ============================================================
// External converters: run without a shell (proc_open array form)
// ============================================================

function henkan_proc_available() {
    return function_exists( 'proc_open' ) && is_callable( 'proc_open' );
}

function henkan_exec_available() {
    return function_exists( 'exec' ) && is_callable( 'exec' );
}

// True if command line converters can be started at all (proc_open, or exec as fallback)
function henkan_can_run_binaries() {
    return henkan_proc_available() || henkan_exec_available();
}

/**
 * Runs a converter binary.
 *
 * With proc_open() the arguments are passed as an array: no shell is involved, so no quoting or
 * injection issues, and a hanging converter is killed after $timeout seconds (max_execution_time
 * does not count time spent waiting for child processes). exec() is only a fallback for hosts that
 * disable proc_open but allow exec (no timeout there).
 *
 * @param string[] $cmd     Binary followed by its arguments.
 * @param int      $timeout Seconds; 0 = "exec_timeout" setting.
 * @param string   $stderr  Receives the (shortened) error output.
 * @return int Exit code, -1 on start failure or timeout.
 */
function henkan_run_cmd( array $cmd, $timeout = 0, &$stderr = null ) {
    $stderr = '';
    $cmd = array_values( array_map( 'strval', $cmd ) );
    if ( empty( $cmd ) || $cmd[0] === '' ) return -1;
    if ( $timeout <= 0 ) {
        $s = henkan_get_settings();
        $timeout = max( 10, (int) ( $s['exec_timeout'] ?? 120 ) );
    }

    if ( henkan_proc_available() ) {
        $spec  = [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
        $pipes = [];
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- image converter binaries, array form without shell
        $proc = @proc_open( $cmd, $spec, $pipes );
        if ( ! is_resource( $proc ) ) {
            $stderr = 'could not start ' . $cmd[0];
            return -1;
        }
        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );

        $start = microtime( true );
        $exit  = -1;
        while ( true ) {
            $read = [ $pipes[1], $pipes[2] ];
            $w = null;
            $e = null;
            // Wait up to 0.2 s for output instead of busy polling; drain stdout so the child never blocks
            if ( @stream_select( $read, $w, $e, 0, 200000 ) ) {
                foreach ( $read as $r ) {
                    $chunk = (string) fread( $r, 8192 );
                    if ( $r === $pipes[2] && strlen( $stderr ) < 4096 ) $stderr .= $chunk;
                }
            }
            $status = proc_get_status( $proc );
            if ( ! $status['running'] ) {
                // The exit code is only reported by the first call after the process ended
                $exit = (int) $status['exitcode'];
                break;
            }
            if ( ( microtime( true ) - $start ) > $timeout ) {
                proc_terminate( $proc, 9 );
                $stderr .= ' [killed after ' . $timeout . 's timeout]';
                $exit = -1;
                break;
            }
        }
        if ( strlen( $stderr ) < 4096 ) $stderr .= (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        proc_close( $proc );
        $stderr = henkan_short_error( $stderr );
        return $exit;
    }

    if ( henkan_exec_available() ) {
        $out  = [];
        $rv   = -1;
        $line = implode( ' ', array_map( 'escapeshellarg', $cmd ) ) . ' 2>&1';
        // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- fallback when proc_open is disabled; every argument is escaped
        @exec( $line, $out, $rv );
        $stderr = henkan_short_error( implode( "\n", array_slice( $out, -5 ) ) );
        return (int) $rv;
    }

    $stderr = 'proc_open/exec disabled';
    return -1;
}

// One line of plain text, max. 200 characters (stored in post meta and shown in the admin log)
function henkan_short_error( $text ) {
    $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
    return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
}

// Checks once per request whether a command line converter is installed (instead of per image)
function henkan_binary_available( $bin, $version_arg = '--version' ) {
    static $cache = [];
    if ( isset( $cache[ $bin ] ) ) return $cache[ $bin ];
    if ( ! henkan_can_run_binaries() ) return $cache[ $bin ] = false;
    $err = '';
    return $cache[ $bin ] = ( henkan_run_cmd( [ $bin, $version_arg ], 10, $err ) === 0 );
}

// Version argument of each supported binary
function henkan_binary_version_arg( $bin ) {
    return in_array( $bin, [ 'cwebp', 'magick' ], true ) ? '-version' : '--version';
}

// True if the PHP Imagick extension can write the given format (webp/avif/jxl)
function henkan_imagick_supports( $fmt ) {
    static $cache = [];
    if ( isset( $cache[ $fmt ] ) ) return $cache[ $fmt ];
    if ( ! class_exists( 'Imagick' ) ) return $cache[ $fmt ] = false;
    try {
        $list = Imagick::queryFormats( strtoupper( $fmt ) );
    } catch ( Exception $e ) {
        $list = [];
    }
    return $cache[ $fmt ] = in_array( strtoupper( $fmt ), (array) $list, true );
}

// Converter methods per target format: the user's choice first, then the fallbacks
function henkan_engine_chain( $fmt, $settings ) {
    $allowed = [
        'webp' => [ 'cwebp', 'gd', 'imagick_ext' ],
        'avif' => [ 'avifenc', 'imagick', 'imagick_ext', 'gd' ],
        'jxl'  => [ 'cjxl', 'imagick', 'imagick_ext' ],
    ];
    $fallback = [
        'webp' => [ 'gd', 'cwebp', 'imagick_ext' ],
        'avif' => [ 'gd', 'avifenc', 'imagick_ext', 'imagick' ],
        'jxl'  => [ 'cjxl', 'imagick_ext', 'imagick' ],
    ];
    if ( ! isset( $allowed[ $fmt ] ) ) return [];
    $pref = (string) ( $settings[ $fmt . '_converter' ] ?? '' );
    $chain = in_array( $pref, $allowed[ $fmt ], true ) ? array_merge( [ $pref ], $fallback[ $fmt ] ) : $fallback[ $fmt ];
    return array_values( array_unique( $chain ) );
}

// True if a converter method can be used on this server right now
function henkan_engine_available( $method, $fmt ) {
    switch ( $method ) {
        case 'cwebp':
        case 'avifenc':
        case 'cjxl':
            return henkan_binary_available( $method, henkan_binary_version_arg( $method ) );
        case 'imagick':
            return henkan_binary_available( 'magick', '-version' );
        case 'imagick_ext':
            return henkan_imagick_supports( $fmt );
        case 'gd':
            if ( $fmt === 'webp' ) return function_exists( 'imagewebp' );
            if ( $fmt === 'avif' ) return function_exists( 'imageavif' );
            return false;
    }
    return false;
}

/**
 * Encodes $source into $dest with one converter method.
 *
 * @return string '' on success, otherwise a short error.
 */
function henkan_encode( $method, $fmt, $source, $dest, $quality, $src_ext, $settings ) {
    $strip    = empty( $settings['keep_metadata'] );
    $lossless = ! empty( $settings['png_lossless'] ) && $src_ext === 'png';
    $speed    = max( 0, min( 10, (int) ( $settings['avif_speed'] ?? 6 ) ) );
    $threads  = max( 1, min( 16, (int) ( $settings['encoder_threads'] ?? 1 ) ) );
    $webp_m    = max( 0, min( 6, (int) ( $settings['webp_method'] ?? 2 ) ) );
    $sharp_yuv = ! empty( $settings['webp_sharp_yuv'] );

    if ( in_array( $method, [ 'cwebp', 'avifenc', 'cjxl', 'imagick' ], true ) ) {
        $bin = $method === 'imagick' ? 'magick' : $method;
        if ( ! henkan_can_run_binaries() ) return 'proc_open/exec disabled';
        if ( ! henkan_binary_available( $bin, henkan_binary_version_arg( $bin ) ) ) return $bin . ' missing';

        switch ( $method ) {
            case 'cwebp':
                $args = [ 'cwebp' ];
                if ( $lossless ) $args[] = '-lossless';
                $args = array_merge( $args, [ '-q', (string) $quality, '-m', (string) $webp_m, '-metadata', $strip ? 'none' : 'all' ] );
                if ( $sharp_yuv && ! $lossless ) $args[] = '-sharp_yuv';
                if ( $threads > 1 ) $args[] = '-mt';
                $args = array_merge( $args, [ $source, '-o', $dest, '-quiet' ] );
                break;
            case 'avifenc':
                // avifenc 1.0+: -q is the quality 0-100 (100 = lossless), the same scale as Henkan
                $args = [ 'avifenc', '-q', (string) $quality, '-s', (string) $speed, '-j', (string) $threads ];
                if ( $strip ) $args = array_merge( $args, [ '--ignore-exif', '--ignore-xmp' ] );
                $args = array_merge( $args, [ $source, $dest ] );
                break;
            case 'cjxl':
                $args = [ 'cjxl', $source, $dest, '-q', (string) ( $lossless ? 100 : $quality ) ];
                if ( $threads > 1 ) $args[] = '--num_threads=' . $threads;
                break;
            default: // ImageMagick command line
                $args = [ 'magick', $source ];
                if ( $strip ) $args[] = '-strip';
                $args = array_merge( $args, [ '-quality', (string) $quality ] );
                if ( $fmt === 'avif' ) $args = array_merge( $args, [ '-define', 'heic:speed=' . $speed ] );
                $args[] = $dest;
        }

        /**
         * Filters the converter command line (binary first, one argument per array entry).
         * Runs without a shell, so arguments need no quoting.
         */
        $filtered = apply_filters( 'henkan_encoder_args', $args, $method, $fmt, $source, $dest, $quality );
        if ( is_array( $filtered ) && ! empty( $filtered ) ) $args = $filtered;

        $err  = '';
        $code = henkan_run_cmd( $args, 0, $err );
        if ( $code === 0 ) return '';
        return $bin . ' failed (exit ' . $code . ( $err !== '' ? ': ' . $err : '' ) . ')';
    }

    if ( $method === 'imagick_ext' ) {
        if ( ! henkan_imagick_supports( $fmt ) ) return 'Imagick extension without ' . strtoupper( $fmt );
        try {
            $im = new Imagick();
            $im->readImage( $source );
            if ( $strip ) $im->stripImage();
            $im->setImageFormat( strtoupper( $fmt ) );
            if ( $fmt === 'webp' && $lossless ) $im->setOption( 'webp:lossless', 'true' );
            if ( $fmt === 'webp' ) {
                $im->setOption( 'webp:method', (string) $webp_m );
                if ( $sharp_yuv && ! $lossless ) $im->setOption( 'webp:use-sharp-yuv', 'true' );
            }
            if ( $fmt === 'avif' ) $im->setOption( 'heic:speed', (string) $speed );
            $im->setImageCompressionQuality( ( $fmt === 'jxl' && $lossless ) ? 100 : (int) $quality );
            $ok = $im->writeImage( $dest );
            $im->clear();
            return $ok ? '' : 'Imagick write failed';
        } catch ( Exception $e ) {
            return 'Imagick: ' . henkan_short_error( $e->getMessage() );
        }
    }

    if ( $method === 'gd' ) {
        if ( $fmt === 'webp' && ! function_exists( 'imagewebp' ) ) return 'gd missing';
        if ( $fmt === 'avif' && ! function_exists( 'imageavif' ) ) return 'PHP 8.1+ imageavif() missing';
        if ( ! in_array( $fmt, [ 'webp', 'avif' ], true ) ) return 'gd cannot write ' . strtoupper( $fmt );

        $content = @file_get_contents( $source );
        if ( ! $content ) return 'read failed';
        $img = @imagecreatefromstring( $content );
        if ( ! $img ) return 'gd decode failed';
        if ( $src_ext === 'png' ) {
            imagepalettetotruecolor( $img );
            imagealphablending( $img, true );
            imagesavealpha( $img, true );
        }
        if ( $fmt === 'webp' ) {
            $q  = ( $lossless && defined( 'IMG_WEBP_LOSSLESS' ) ) ? IMG_WEBP_LOSSLESS : (int) $quality;
            $ok = @imagewebp( $img, $dest, $q );
        } else {
            $ok = @imageavif( $img, $dest, (int) $quality, $speed );
        }
        @imagedestroy( $img );
        return $ok ? '' : 'gd write failed';
    }

    return 'unknown converter ' . $method;
}

/**
 * ROBUST GENERATION: tries all converters of the format until one works.
 *
 * @param string $compare_path File the result must be smaller than (the original, also when
 *                             $source is a resized temp copy). '' = $source.
 * @return bool True if $dest exists. On false, $GLOBALS['henkan_last_error'] holds the reason and
 *              $GLOBALS['henkan_last_skipped'] is true if the result was dropped for being larger.
 */
function henkan_create_image( $source, $dest, $quality, $src_ext, $settings = null, $compare_path = '' ) {
    $GLOBALS['henkan_last_error']   = '';
    $GLOBALS['henkan_last_skipped'] = false;
    if ( file_exists( $dest ) && filesize( $dest ) > 0 ) return true;

    // Strict source validation (MIME type + pixel limit)
    $invalid = henkan_validate_source( $source );
    if ( $invalid !== '' ) {
        $GLOBALS['henkan_last_error'] = $invalid;
        return false;
    }

    $target_ext = strtolower( pathinfo( $dest, PATHINFO_EXTENSION ) );
    $src_ext    = strtolower( (string) $src_ext );
    $quality    = max( 1, min( 100, intval( $quality ) ) );
    $settings   = is_array( $settings ) ? $settings : henkan_get_settings();

    $chain = henkan_engine_chain( $target_ext, $settings );
    if ( empty( $chain ) ) {
        $GLOBALS['henkan_last_error'] = 'Unknown target format';
        return false;
    }

    $errors = [];
    foreach ( $chain as $method ) {
        $err = henkan_encode( $method, $target_ext, $source, $dest, $quality, $src_ext, $settings );
        clearstatcache( true, $dest );
        if ( $err === '' && file_exists( $dest ) && filesize( $dest ) > 0 ) {
            // Size check: a "next-gen" file that is not smaller than the original is useless
            $compare = $compare_path !== '' ? $compare_path : $source;
            if ( ! empty( $settings['skip_larger'] ) && file_exists( $compare ) && filesize( $dest ) >= filesize( $compare ) ) {
                wp_delete_file( $dest );
                $GLOBALS['henkan_last_skipped'] = true;
                $GLOBALS['henkan_last_error']   = strtoupper( $target_ext ) . ' not smaller than original (original kept)';
                return false;
            }
            return true;
        }
        $errors[] = $err !== '' ? $err : $method . ' produced no output';
        // Never leave a broken partial file behind (it would count as "converted" next time)
        if ( file_exists( $dest ) ) wp_delete_file( $dest );
    }

    $GLOBALS['henkan_last_error'] = implode( ' | ', array_unique( $errors ) );
    return false;
}

/**
 * Smart Option: Max Output Width
 * Scales the source down to $max_width pixels before conversion if it is wider.
 * Returns the path of the (temporary) source to convert; the original is never changed.
 *
 * @param string $source    Original file.
 * @param int    $max_width Maximum width in pixels.
 * @param string $src_ext   File extension (jpg/jpeg/png).
 * @return string Path of the scaled temp file or of the original.
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

    if ( $orig_w <= $max_width ) return $source; // already narrow enough

    // Keep the aspect ratio
    $new_w = $max_width;
    $new_h = (int) round( $orig_h * ( $max_width / $orig_w ) );

    // Unguessable temp file name (avoids symlink attacks in shared temp dirs)
    if ( ! function_exists( 'wp_tempnam' ) ) require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp_base = wp_tempnam( 'henkan_resize' );
    $tmp = $tmp_base . '.' . $src_ext;
    wp_delete_file( $tmp_base );

    $resized = imagecreatetruecolor( $new_w, $new_h );
    if ( ! $resized ) return $source;

    // Keep PNG transparency
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
/**
 * Converts one file (original or one image size) into all enabled formats.
 *
 * @param int $quality_override Quality for this run only (media library "Re-convert with quality"); 0 = settings.
 * @return array{success:bool,generated:string[],failed:string[],skipped:string[]}
 */
function henkan_convert_file( $path, $id, $size_name, $update_db = true, $quality_override = 0 ) {
    $empty = [ 'success' => false, 'generated' => [], 'failed' => [], 'skipped' => [] ];
    $lock_token = henkan_acquire_lock( $path, $id, $size_name );
    if ( ! $lock_token ) return array_merge( $empty, [ 'failed' => [ 'locked' ] ] );

    try {
    $settings = henkan_get_settings();
    $fmts = henkan_target_formats( $settings );
    if ( empty( $fmts ) ) return $empty;

    $info = pathinfo( $path );
    $src_ext = strtolower( $info['extension'] ?? '' );
    if ( ! in_array( $src_ext, [ 'jpg', 'jpeg', 'png' ], true ) ) return $empty;

    if ( henkan_is_file_excluded( $path ) ) return array_merge( $empty, [ 'failed' => [ 'excluded' ] ] );

    // Smart Option: Max Output Width
    $actual_source = $path;
    if ( ! empty( $settings['max_width_enabled'] ) && ! empty( $settings['max_width_px'] ) ) {
        $actual_source = henkan_maybe_resize_for_conversion( $path, (int) $settings['max_width_px'], $src_ext );
    }

    // Smart Option: Quality by Image Size
    // Registered WordPress thumbnail sizes get a lower quality, but only when the option is enabled.
    $quality = (int) $settings['quality'];
    if (
        ! empty( $settings['smart_quality_enabled'] ) &&
        ! empty( $settings['smart_quality_thumb'] ) &&
        $size_name !== 'original' &&
        $size_name !== 'custom'
    ) {
        $registered_sizes = wp_get_registered_image_subsizes();
        if ( isset( $registered_sizes[ $size_name ] ) ) {
            $quality = (int) $settings['smart_quality_thumb'];
        }
    }
    if ( (int) $quality_override > 0 ) $quality = max( 1, min( 100, (int) $quality_override ) );

    $any_success = false;
    $generated = [];
    $failed = [];
    $skipped = [];
    $new_sizes = []; // byte sizes of files created in this run (for the savings counter)
    $orig_size = file_exists( $path ) ? (int) filesize( $path ) : 0;

    $meta = [];
    if ( $id > 0 ) {
        $meta = henkan_get_data( $id ) ?: [];
        if ( ! is_array( $meta ) ) $meta = [];
    }

    foreach ( $fmts as $fmt ) {
        $target_path = $info['dirname'] . '/' . $info['filename'] . '.' . $fmt;
        $existed = file_exists( $target_path ) && filesize( $target_path ) > 0;

        /**
         * Filters the quality for one output file.
         *
         * @param int    $quality   1-100.
         * @param string $fmt       webp, avif or jxl.
         * @param string $size_name original, custom or an image size name.
         * @param int    $id        Attachment ID (0 for files from the folder scan).
         * @param string $path      Source file.
         */
        $q = (int) apply_filters( 'henkan_quality', $quality, $fmt, $size_name, (int) $id, $path );

        $success = henkan_create_image( $actual_source, $target_path, $q, $src_ext, $settings, $path );
        if ( $success ) {
            if ( ! isset( $meta[ $size_name ] ) ) $meta[ $size_name ] = [];
            $meta[ $size_name ][ $fmt ] = basename( $target_path );
            $any_success = true;
            $generated[] = $fmt;
            henkan_clear_skipped( (int) $id, $size_name, $fmt );
            if ( ! $existed ) {
                clearstatcache( true, $target_path );
                $new_sizes[] = (int) filesize( $target_path );
                /**
                 * Fires after a converted file was written.
                 *
                 * @param int    $id          Attachment ID (0 for files from the folder scan).
                 * @param string $size_name   original, custom or an image size name.
                 * @param string $target_path Converted file.
                 * @param string $fmt         webp, avif or jxl.
                 * @param string $path        Source file.
                 */
                do_action( 'henkan_converted', (int) $id, $size_name, $target_path, $fmt, $path );
            }
        } elseif ( ! empty( $GLOBALS['henkan_last_skipped'] ) ) {
            $skipped[] = $fmt;
            henkan_mark_skipped( (int) $id, $size_name, $fmt );
        } else {
            $err_reason = isset( $GLOBALS['henkan_last_error'] ) ? $GLOBALS['henkan_last_error'] : 'error';
            $failed[] = $fmt . ' (' . $err_reason . ')';
        }
    }

    // Savings: original size minus the smallest new file (several formats are alternatives, not additive)
    if ( ! empty( $new_sizes ) && $orig_size > 0 ) henkan_add_savings( max( 0, $orig_size - min( $new_sizes ) ) );

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
            if ( ! empty( $skipped ) && empty( $failed ) ) {
                // Every format was larger than the original: not an error, the original is served
                henkan_set_state( $id, 'skipped', implode( ', ', array_map( 'strtoupper', $skipped ) ) . ' not smaller than original' );
            } else {
                $err = isset( $GLOBALS['henkan_last_error'] ) ? (string) $GLOBALS['henkan_last_error'] : '';
                henkan_set_state( $id, 'failed', $err );
            }
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
    // Remove the temporary resize copy, if one was created
    if ( $actual_source !== $path && file_exists( $actual_source ) ) {
        wp_delete_file( $actual_source );
    }

    return [ 'success' => $any_success, 'generated' => $generated, 'failed' => $failed, 'skipped' => $skipped ];
    } finally {
        henkan_release_lock( $path, $id, $size_name, $lock_token );
    }
}

/**
 * Converts a media-library attachment: main file and all image sizes.
 * Shared by the bulk optimizer, background queue, media library actions and WP-CLI.
 *
 * @param array $args force (bool): delete existing converted files first and encode again;
 *                    quality (int): quality for this run only, 0 = settings.
 * @return array Result of the main file (see henkan_convert_file()).
 */
function henkan_convert_attachment( $id, $args = [] ) {
    $args = wp_parse_args( $args, [ 'force' => false, 'quality' => 0 ] );
    $id   = (int) $id;
    if ( henkan_is_offloaded( $id ) ) {
        // New files would only exist locally, while the image is delivered from the bucket
        return [ 'success' => false, 'generated' => [], 'failed' => [ 'already offloaded to cloud storage, convert before offloading' ], 'skipped' => [] ];
    }
    $file = get_attached_file( $id );
    if ( ! $file || ! file_exists( $file ) ) {
        return [ 'success' => false, 'generated' => [], 'failed' => [ 'file missing' ], 'skipped' => [] ];
    }

    if ( $args['force'] || (int) $args['quality'] > 0 ) {
        // Re-encoding needs the original JPEG/PNG; never touch the only remaining copy of an image
        if ( ! henkan_attachment_has_original( $id ) ) {
            return [ 'success' => false, 'generated' => [], 'failed' => [ 'original deleted, cannot re-convert' ], 'skipped' => [] ];
        }
        henkan_remove_converted_files( $id );
        henkan_clear_skipped( $id );
    }

    $res  = henkan_convert_file( $file, $id, 'original', true, (int) $args['quality'] );
    $meta = wp_get_attachment_metadata( $id );
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        $base = dirname( $file );
        foreach ( $meta['sizes'] as $size => $data ) {
            if ( empty( $data['file'] ) || ! file_exists( $base . '/' . $data['file'] ) ) continue;
            henkan_convert_file( $base . '/' . $data['file'], $id, $size, true, (int) $args['quality'] );
        }
    }
    henkan_sync_sources( $id );

    /**
     * Fires after an attachment (main file and sizes) was converted.
     *
     * @param int   $id  Attachment ID.
     * @param array $res Result of the main file.
     */
    do_action( 'henkan_attachment_converted', $id, $res );
    return $res;
}

// True if the attachment's main file is still the JPEG/PNG original
function henkan_attachment_has_original( $id ) {
    $file = get_attached_file( $id );
    return $file && file_exists( $file ) && preg_match( '/\.(jpe?g|png)$/i', $file );
}

/**
 * Deletes the converted WebP/AVIF/JXL files of an attachment. A converted file is only deleted
 * when the JPEG/PNG it was made from still exists next to it, so this can never remove the
 * only copy of an image ("Keep original" off).
 *
 * @return array{removed:int,bytes_saved:int} Number of deleted files and the space they had saved.
 */
function henkan_remove_converted_files( $id ) {
    $file = get_attached_file( $id );
    if ( ! $file || ! henkan_attachment_has_original( $id ) ) return [ 'removed' => 0, 'bytes_saved' => 0 ];
    $dir = dirname( $file );

    // Source files: main file, all sizes and every name stored in the Henkan data
    $sources = [ basename( $file ) ];
    $meta = wp_get_attachment_metadata( $id );
    if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        foreach ( $meta['sizes'] as $data ) {
            if ( ! empty( $data['file'] ) ) $sources[] = basename( $data['file'] );
        }
    }
    $stems = [];
    foreach ( $sources as $name ) {
        if ( preg_match( '/\.(jpe?g|png)$/i', $name ) && file_exists( $dir . '/' . $name ) ) {
            $stems[ pathinfo( $name, PATHINFO_FILENAME ) ] = $dir . '/' . $name;
        }
    }
    $data = henkan_get_data( $id );
    if ( is_array( $data ) ) {
        foreach ( $data as $formats ) {
            if ( ! is_array( $formats ) ) continue;
            foreach ( $formats as $name ) {
                if ( ! is_string( $name ) || $name === '' ) continue;
                $stem = pathinfo( basename( $name ), PATHINFO_FILENAME );
                if ( isset( $stems[ $stem ] ) ) continue;
                foreach ( [ 'jpg', 'jpeg', 'png', 'JPG', 'JPEG', 'PNG' ] as $e ) {
                    if ( file_exists( $dir . '/' . $stem . '.' . $e ) ) { $stems[ $stem ] = $dir . '/' . $stem . '.' . $e; break; }
                }
            }
        }
    }

    $removed = 0;
    $saved   = 0;
    foreach ( $stems as $stem => $source ) {
        $sizes = [];
        foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
            $p = $dir . '/' . $stem . '.' . $fmt;
            if ( ! file_exists( $p ) ) continue;
            $sizes[] = (int) filesize( $p );
            wp_delete_file( $p );
            if ( ! file_exists( $p ) ) $removed++;
        }
        if ( ! empty( $sizes ) ) $saved += max( 0, (int) filesize( $source ) - min( $sizes ) );
    }
    return [ 'removed' => $removed, 'bytes_saved' => $saved ];
}

/**
 * Undoes the conversion of an attachment: deletes its WebP/AVIF/JXL files and the Henkan data.
 * Only possible while the original JPEG/PNG exists.
 *
 * @return array{ok:bool,msg:string,removed:int}
 */
function henkan_revert_attachment( $id ) {
    $id = (int) $id;
    if ( henkan_is_offloaded( $id ) ) {
        // The converted files are in the bucket; deleting only the local copies would break <picture>
        return [ 'ok' => false, 'removed' => 0, 'msg' => __( 'Not reverted: the image is offloaded to cloud storage.', 'henkan-webp-avif-converter' ) ];
    }
    if ( ! henkan_attachment_has_original( $id ) ) {
        return [ 'ok' => false, 'removed' => 0, 'msg' => __( 'Not reverted: the original JPEG/PNG no longer exists.', 'henkan-webp-avif-converter' ) ];
    }
    $r = henkan_remove_converted_files( $id );
    henkan_update_data( $id, [] );
    henkan_clear_skipped( $id );
    delete_post_meta( $id, '_henkan_state' );
    delete_post_meta( $id, 'henkan_last_error' );
    henkan_add_savings( -$r['bytes_saved'] );
    henkan_sync_sources( $id ); // removes Henkan's "sources" entries

    /**
     * Fires after the converted files of an attachment were removed.
     *
     * @param int $id      Attachment ID.
     * @param int $removed Number of deleted files.
     */
    do_action( 'henkan_reverted', $id, $r['removed'] );

    return [
        'ok'      => true,
        'removed' => $r['removed'],
        /* translators: 1: Attachment ID, 2: Number of deleted files */
        'msg'     => sprintf( __( 'ID %1$d reverted (%2$d files deleted).', 'henkan-webp-avif-converter' ), $id, $r['removed'] ),
    ];
}

/**
 * Reverts a batch of converted attachments (keyset paging over the Henkan table).
 *
 * @return array{last_id:int,reverted:int,skipped:int,more:bool}
 */
function henkan_revert_batch( $after_id, $limit = 50 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, bounded batch
    $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d', $table, (int) $after_id, (int) $limit ) );
    $out = [ 'last_id' => (int) $after_id, 'reverted' => 0, 'skipped' => 0, 'more' => count( $ids ) === (int) $limit ];
    foreach ( $ids as $id ) {
        $out['last_id'] = (int) $id;
        $r = henkan_revert_attachment( (int) $id );
        if ( $r['ok'] ) $out['reverted']++;
        else $out['skipped']++;
    }
    return $out;
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

/**
 * Known page cache plugins: name => [ detect callback, purge callback ].
 * Every entry only uses the public purge function or hook of that plugin.
 */
function henkan_cache_plugins() {
    return [
        'WP Rocket'           => [
            static fn() => function_exists( 'rocket_clean_domain' ),
            static function () { rocket_clean_domain(); },
        ],
        'W3 Total Cache'      => [
            static fn() => function_exists( 'w3tc_flush_all' ),
            static function () { w3tc_flush_all(); },
        ],
        'WP Super Cache'      => [
            static fn() => function_exists( 'wp_cache_clear_cache' ),
            static function () { wp_cache_clear_cache(); },
        ],
        'Autoptimize'         => [
            static fn() => class_exists( 'autoptimizeCache' ),
            static function () { autoptimizeCache::clearall(); },
        ],
        // LiteSpeed Cache 3+: public purge hook; the old LiteSpeed_Cache_API class only for old versions
        'LiteSpeed Cache'     => [
            static fn() => has_action( 'litespeed_purge_all' ) || class_exists( 'LiteSpeed_Cache_API' ),
            static function () {
                if ( has_action( 'litespeed_purge_all' ) ) do_action( 'litespeed_purge_all' );
                elseif ( method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) LiteSpeed_Cache_API::purge_all();
            },
        ],
        'Kyasshu'             => [
            static fn() => function_exists( 'kyasshu_purge_all' ),
            static function () { do_action( 'kyasshu_flush' ); },
        ],
        'WP Fastest Cache'    => [
            static fn() => function_exists( 'wpfc_clear_all_cache' ),
            static function () { wpfc_clear_all_cache( true ); },
        ],
        'Cache Enabler'       => [
            static fn() => class_exists( 'Cache_Enabler' ),
            static function () { do_action( 'cache_enabler_clear_complete_cache' ); },
        ],
        'Breeze'              => [
            static fn() => defined( 'BREEZE_VERSION' ),
            static function () { do_action( 'breeze_clear_all_cache' ); },
        ],
        'SiteGround Speed Optimizer' => [
            static fn() => function_exists( 'sg_cachepress_purge_cache' ),
            static function () { sg_cachepress_purge_cache(); },
        ],
        'Hummingbird'         => [
            static fn() => defined( 'WPHB_VERSION' ),
            static function () { do_action( 'wphb_clear_page_cache' ); },
        ],
        'Comet Cache'         => [
            static fn() => class_exists( 'comet_cache' ) && method_exists( 'comet_cache', 'clear' ),
            static function () { comet_cache::clear(); },
        ],
        'Swift Performance'   => [
            static fn() => class_exists( 'Swift_Performance_Cache' ) && method_exists( 'Swift_Performance_Cache', 'clear_all_cache' ),
            static function () { Swift_Performance_Cache::clear_all_cache(); },
        ],
        'WP-Optimize'         => [
            static fn() => function_exists( 'wpo_cache_flush' ),
            static function () { wpo_cache_flush(); },
        ],
        'Nginx Helper'        => [
            static fn() => has_action( 'rt_nginx_helper_purge_all' ),
            static function () { do_action( 'rt_nginx_helper_purge_all' ); },
        ],
        'WP Engine'           => [
            static fn() => class_exists( 'WpeCommon' ),
            static function () {
                if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) WpeCommon::purge_memcached();
                if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) WpeCommon::purge_varnish_cache();
            },
        ],
    ];
}

// Names of the active cache plugins Henkan can purge.
function henkan_active_cache_plugins() {
    $active = [];
    foreach ( henkan_cache_plugins() as $name => $plugin ) {
        if ( ( $plugin[0] )() ) $active[] = $name;
    }
    return $active;
}

function henkan_trigger_cache_clear() {
    foreach ( henkan_cache_plugins() as $name => $plugin ) {
        if ( ! ( $plugin[0] )() ) continue;
        try {
            ( $plugin[1] )();
        } catch ( \Throwable $e ) {
            // One failing cache plugin must not stop the others
            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) error_log( 'Henkan: cache purge failed for ' . $name . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug log only
        }
    }
    /** Fires after Henkan purged the page caches (for other cache plugins / CDNs). */
    do_action( 'henkan_cache_cleared' );
}

/**
 * Repairs attachment metadata of images converted by older versions with "Keep original" off:
 * the bulk converter deleted the JPEG/PNG sizes but left them in the metadata (broken thumbnails).
 * Only sizes whose file is missing AND whose converted file exists are changed.
 *
 * @return bool True if the metadata was changed.
 */
function henkan_repair_attachment_metadata( $id ) {
    if ( henkan_is_offloaded( $id ) ) return false; // local files may be gone on purpose
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
