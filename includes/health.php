<?php
defined( 'ABSPATH' ) || exit;

// Site Health (Tools > Site Health): converter status, delivery check and debug information

add_filter( 'site_status_tests', 'henkan_site_status_tests' );
function henkan_site_status_tests( $tests ) {
    $tests['direct']['henkan_converters'] = [
        'label' => __( 'Henkan image converters', 'henkan-webp-avif-converter' ),
        'test'  => 'henkan_health_test_converters',
    ];
    $tests['direct']['henkan_delivery'] = [
        'label' => __( 'Henkan image delivery', 'henkan-webp-avif-converter' ),
        'test'  => 'henkan_health_test_delivery',
    ];
    return $tests;
}

// First working converter for a format, or '' if none works
function henkan_health_working_engine( $fmt, $settings ) {
    foreach ( henkan_engine_chain( $fmt, $settings ) as $m ) {
        if ( henkan_engine_available( $m, $fmt ) ) return $m;
    }
    return '';
}

function henkan_health_test_converters() {
    $s     = henkan_get_settings();
    $fmts  = henkan_target_formats( $s );
    $badge = [ 'label' => __( 'Performance', 'henkan-webp-avif-converter' ), 'color' => 'blue' ];
    $result = [
        'label'       => __( 'Henkan can convert images', 'henkan-webp-avif-converter' ),
        'status'      => 'good',
        'badge'       => $badge,
        'description' => '',
        'actions'     => '<p><a href="' . esc_url( henkan_admin_url() ) . '">' . esc_html__( 'Henkan settings', 'henkan-webp-avif-converter' ) . '</a></p>',
        'test'        => 'henkan_converters',
    ];

    if ( empty( $fmts ) ) {
        $result['status']      = 'recommended';
        $result['label']       = __( 'Henkan has no output format enabled', 'henkan-webp-avif-converter' );
        $result['description'] = '<p>' . esc_html__( 'Enable WebP, AVIF or JPEG XL in the Henkan settings, otherwise no images are converted.', 'henkan-webp-avif-converter' ) . '</p>';
        return $result;
    }

    $lines   = [];
    $missing = [];
    foreach ( $fmts as $fmt ) {
        $engine = henkan_health_working_engine( $fmt, $s );
        if ( $engine === '' ) $missing[] = strtoupper( $fmt );
        /* translators: 1: Format, 2: Converter name or "none" */
        $lines[] = sprintf( __( '%1$s: %2$s', 'henkan-webp-avif-converter' ), strtoupper( $fmt ), $engine !== '' ? $engine : __( 'none', 'henkan-webp-avif-converter' ) );
    }
    $desc = '<p>' . esc_html( implode( ' · ', $lines ) ) . '</p>';
    if ( ! henkan_can_run_binaries() ) {
        $desc .= '<p>' . esc_html__( 'proc_open() and exec() are disabled, so command line converters (cwebp, avifenc, cjxl, magick) cannot be used. GD or the Imagick extension are used instead.', 'henkan-webp-avif-converter' ) . '</p>';
    }

    if ( ! empty( $missing ) ) {
        $result['status'] = 'recommended';
        /* translators: %s: List of formats */
        $result['label']  = sprintf( __( 'Henkan cannot create %s on this server', 'henkan-webp-avif-converter' ), implode( ', ', $missing ) );
        $desc .= '<p>' . esc_html__( 'Install a converter for these formats (e.g. cwebp, avifenc, cjxl or ImageMagick) or disable the formats in the Henkan settings.', 'henkan-webp-avif-converter' ) . '</p>';
    }
    $result['description'] = $desc;
    return $result;
}

/**
 * Checks whether browsers actually receive the converted images: via <picture>, or via the
 * server rewrite rules (loopback request for a converted JPEG/PNG with an AVIF/WebP Accept header).
 */
function henkan_health_test_delivery() {
    $s = henkan_get_settings();
    $result = [
        'label'       => __( 'Converted images are delivered', 'henkan-webp-avif-converter' ),
        'status'      => 'good',
        'badge'       => [ 'label' => __( 'Performance', 'henkan-webp-avif-converter' ), 'color' => 'blue' ],
        'description' => '',
        'actions'     => '<p><a href="' . esc_url( henkan_admin_url() ) . '">' . esc_html__( 'Henkan settings', 'henkan-webp-avif-converter' ) . '</a></p>',
        'test'        => 'henkan_delivery',
    ];

    $rewrite = henkan_health_rewrite_probe();

    if ( ! empty( $s['picture_filter_enabled'] ) ) {
        $result['description'] = '<p>' . esc_html__( 'Images are delivered with <picture> elements (post content and, if enabled, featured images).', 'henkan-webp-avif-converter' ) . '</p>';
        if ( $rewrite === true ) {
            $result['description'] .= '<p>' . esc_html__( 'The server rewrite rules are active as well.', 'henkan-webp-avif-converter' ) . '</p>';
        }
        return $result;
    }

    if ( $rewrite === null ) {
        $result['description'] = '<p>' . esc_html__( 'No converted media-library image found to test the server rewrite rules with.', 'henkan-webp-avif-converter' ) . '</p>';
        return $result;
    }
    if ( $rewrite === true ) {
        $result['description'] = '<p>' . esc_html__( 'The server rewrite rules deliver AVIF/WebP to browsers that support them.', 'henkan-webp-avif-converter' ) . '</p>';
        return $result;
    }

    $result['status']      = 'recommended';
    $result['label']       = __( 'Converted images are not delivered to browsers', 'henkan-webp-avif-converter' );
    $result['description'] = '<p>' . esc_html__( 'The <picture> output is turned off and a test request for a converted JPEG/PNG still returned the original. Enable "Frontend <picture>" or add the server rewrite rules from the Henkan settings.', 'henkan-webp-avif-converter' ) . '</p>';
    return $result;
}

/**
 * Loopback request for a converted image.
 *
 * @return bool|null True if AVIF/WebP/JXL came back, false if the original came back, null if nothing to test.
 */
function henkan_health_rewrite_probe() {
    global $wpdb;
    $table = $wpdb->prefix . 'henkan_data';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, small bounded sample
    $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i ORDER BY attachment_id DESC LIMIT %d', $table, 20 ) );
    foreach ( $ids as $id ) {
        $file = get_attached_file( (int) $id );
        if ( ! $file || ! file_exists( $file ) || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) continue;
        $stem = dirname( $file ) . '/' . pathinfo( $file, PATHINFO_FILENAME );
        if ( ! file_exists( $stem . '.avif' ) && ! file_exists( $stem . '.webp' ) && ! file_exists( $stem . '.jxl' ) ) continue;

        $url = wp_get_attachment_url( (int) $id );
        if ( ! $url ) continue;
        $res = wp_remote_get( $url, [
            'timeout'     => 5,
            'redirection' => 2,
            'headers'     => [ 'Accept' => 'image/avif,image/webp,image/jxl,*/*;q=0.8' ],
            'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
            'limit_response_size' => 1024,
        ] );
        if ( is_wp_error( $res ) ) return false;
        $type = strtolower( (string) wp_remote_retrieve_header( $res, 'content-type' ) );
        return (bool) preg_match( '#image/(avif|webp|jxl)#', $type );
    }
    return null;
}

add_filter( 'debug_information', 'henkan_debug_information' );
function henkan_debug_information( $info ) {
    $s   = henkan_get_settings();
    $yes = __( 'Yes', 'henkan-webp-avif-converter' );
    $no  = __( 'No', 'henkan-webp-avif-converter' );

    $fields = [
        'version' => [ 'label' => __( 'Version', 'henkan-webp-avif-converter' ), 'value' => HENKAN_VERSION ],
        'formats' => [ 'label' => __( 'Enabled formats', 'henkan-webp-avif-converter' ), 'value' => implode( ', ', array_map( 'strtoupper', henkan_target_formats( $s ) ) ) ?: __( 'none', 'henkan-webp-avif-converter' ) ],
        'proc_open' => [ 'label' => 'proc_open()', 'value' => henkan_proc_available() ? $yes : $no ],
        'exec'      => [ 'label' => 'exec()', 'value' => henkan_exec_available() ? $yes : $no ],
    ];
    foreach ( [ 'cwebp', 'avifenc', 'cjxl', 'magick' ] as $bin ) {
        $fields[ 'bin_' . $bin ] = [ 'label' => $bin, 'value' => henkan_binary_available( $bin, henkan_binary_version_arg( $bin ) ) ? $yes : $no ];
    }
    $fields['gd_webp'] = [ 'label' => 'GD WebP', 'value' => function_exists( 'imagewebp' ) ? $yes : $no ];
    $fields['gd_avif'] = [ 'label' => 'GD AVIF', 'value' => function_exists( 'imageavif' ) ? $yes : $no ];
    $im = [];
    foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
        if ( henkan_imagick_supports( $fmt ) ) $im[] = strtoupper( $fmt );
    }
    $fields['imagick'] = [ 'label' => __( 'Imagick extension formats', 'henkan-webp-avif-converter' ), 'value' => class_exists( 'Imagick' ) ? ( implode( ', ', $im ) ?: __( 'none', 'henkan-webp-avif-converter' ) ) : $no ];
    foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
        $fields[ 'engine_' . $fmt ] = [
            /* translators: %s: Format */
            'label' => sprintf( __( 'Converter used for %s', 'henkan-webp-avif-converter' ), strtoupper( $fmt ) ),
            'value' => henkan_health_working_engine( $fmt, $s ) ?: __( 'none', 'henkan-webp-avif-converter' ),
        ];
    }
    $fields['keep_original'] = [ 'label' => __( 'Keep originals', 'henkan-webp-avif-converter' ), 'value' => ! empty( $s['keep_original'] ) ? $yes : $no ];
    $fields['picture']       = [ 'label' => __( 'Frontend <picture>', 'henkan-webp-avif-converter' ), 'value' => ! empty( $s['picture_filter_enabled'] ) ? $yes : $no ];
    $fields['savings']       = [ 'label' => __( 'Space saved', 'henkan-webp-avif-converter' ), 'value' => size_format( (int) get_option( 'henkan_total_savings_bytes', 0 ) ) ?: '0 B' ];

    $info['henkan'] = [
        'label'  => __( 'Henkan - WebP & AVIF Converter', 'henkan-webp-avif-converter' ),
        'fields' => $fields,
    ];
    return $info;
}
