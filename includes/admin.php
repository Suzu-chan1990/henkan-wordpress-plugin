<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function() {
    add_options_page(
        __( 'Henkan Image Optimization', 'henkan-webp-avif-converter' ),
        __( 'Henkan', 'henkan-webp-avif-converter' ),
        'manage_options',
        'henkan-settings',
        'henkan_admin_page'
    );
});

/**
 * URL of the Henkan admin page.
 */
function henkan_admin_url() {
    return admin_url( 'options-general.php?page=henkan-settings' );
}

add_action( 'admin_init', function() {
    register_setting( 
        'henkan_settings_group', 
        'henkan_settings', 
        [
            'type'              => 'array',
            'sanitize_callback' => 'henkan_sanitize_settings',
            'default'           => henkan_default_settings()
        ] 
    );
});

add_filter( 'manage_media_columns', 'henkan_add_media_column' );
function henkan_add_media_column( $columns ) {
    $columns['henkan_status'] = __( 'Henkan', 'henkan-webp-avif-converter' );
    return $columns;
}

add_action( 'manage_media_custom_column', 'henkan_media_custom_column_content', 10, 2 );
function henkan_media_custom_column_content( $column_name, $id ) {
    if ( 'henkan_status' !== $column_name ) return;

    $mime = get_post_mime_type( $id );
    
    if ( in_array( $mime, [ 'image/webp', 'image/avif', 'image/jxl' ] ) ) {
        echo '<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <span style="color:#46b450; font-weight:bold;">' . esc_html__( 'Native', 'henkan-webp-avif-converter' ) . '</span>';
        return;
    }

    if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ] ) ) {
        echo '<span style="color:#ccc;">—</span>';
        return;
    }

    $meta = henkan_get_data( $id );
    if ( ! empty( $meta ) ) {
        echo '<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <span style="color:#46b450; font-weight:bold;">OK</span>';
    } else {
        echo '<button type="button" class="button button-small henkan-quick-convert" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Optimize', 'henkan-webp-avif-converter' ) . '</button>';
        echo '<span class="henkan-spinner spinner" style="float:none; margin-top:0;"></span>';
    }
}

add_action( 'wp_dashboard_setup', 'henkan_add_dashboard_widgets' );
function henkan_add_dashboard_widgets() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    wp_add_dashboard_widget( 'henkan_dashboard_widget', __( 'Henkan Status', 'henkan-webp-avif-converter' ), 'henkan_dashboard_widget_content' );
}

function henkan_dashboard_widget_content() {
    $stats = henkan_get_stats();
    echo '<div class="henkan-dashboard-widget" style="display:flex; gap:20px; align-items:center;">';
    echo '<div style="text-align:center;"><span class="dashicons dashicons-format-image" style="font-size:32px; height:32px; width:32px; color:#2271b1;"></span></div>';
    echo '<div>';
    echo '<p style="margin:0 0 5px;"><strong>' . esc_html( $stats['percent'] ) . '%</strong> ' . esc_html__( 'Optimized', 'henkan-webp-avif-converter' ) . '</p>';
    echo '<div style="background:#eee; border-radius:5px; width:150px; height:10px; overflow:hidden;"><div style="background:#46b450; height:100%; width:' . esc_attr( $stats['percent'] ) . '%"></div></div>';
    echo '<p style="margin:5px 0 0; font-size:11px; color:#666;">';
    /* translators: 1: Number of converted images, 2: Total number of images */
    printf( esc_html__( '%1$s of %2$s images optimized.', 'henkan-webp-avif-converter' ), '<strong>' . intval( $stats['converted'] ) . '</strong>', intval( $stats['total'] ) );
    echo '</p></div></div>';
    echo '<p style="text-align:right; margin-top:10px;"><a href="' . esc_url( henkan_admin_url() ) . '" class="button button-small">' . esc_html__( 'To Converter', 'henkan-webp-avif-converter' ) . '</a></p>';
}

add_action( 'admin_bar_menu', 'henkan_admin_bar_menu', 99 );
function henkan_admin_bar_menu( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $wp_admin_bar->add_node( [ 'id' => 'henkan_menu', 'title' => '<span class="ab-icon dashicons dashicons-format-image"></span> Henkan', 'href' => henkan_admin_url() ] );
    $wp_admin_bar->add_node( [ 'id' => 'henkan_settings', 'title' => __( 'Settings', 'henkan-webp-avif-converter' ), 'parent' => 'henkan_menu', 'href' => henkan_admin_url() ] );
}

function henkan_sanitize_settings( $input ) {
    if ( ! is_array( $input ) ) $input = [];
    
    $clean = [];
    $defaults = henkan_default_settings();
    
    $bool_keys = [
        'enable_webp', 'enable_avif', 'enable_jxl', 'keep_original',
        'picture_filter_enabled', 'enable_lazy_loading', 'debug',
        'auto_clear_cache', 'scan_uploads_dir', 'scan_theme_dir',
        'bulk_only_unconverted', 'enable_bg_queue',
        // Smart Options
        'smart_quality_enabled', 'integrity_check_enabled', 'max_width_enabled',
    ];
    foreach ( $bool_keys as $key ) {
        $clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    $quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : $defaults['quality'];
    $clean['quality'] = max( 1, min( 100, $quality ) );
    $clean['batch_size'] = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
    // options.php already unslashed the input once; a second wp_unslash() removed backslashes
    // from regex exclusions (/\.min\./ became /.min./) and Windows paths.
    $clean['custom_folders'] = isset( $input['custom_folders'] ) ? sanitize_textarea_field( $input['custom_folders'] ) : '';
    $clean['exclusions']     = isset( $input['exclusions'] ) ? sanitize_textarea_field( $input['exclusions'] ) : '';

    // Smart Options - numeric values
    $clean['smart_quality_thumb'] = isset( $input['smart_quality_thumb'] ) ? max( 1, min( 100, absint( $input['smart_quality_thumb'] ) ) ) : 70;
    $clean['max_width_px']        = isset( $input['max_width_px'] ) ? max( 100, min( 10000, absint( $input['max_width_px'] ) ) ) : 2000;

    // Preserve Smart Options when saving a tab that does not render them
    $old = henkan_get_settings();
    if ( empty( $input['_smart_tab'] ) ) {
        foreach ( [ 'smart_quality_enabled', 'smart_quality_thumb', 'integrity_check_enabled', 'max_width_enabled', 'max_width_px' ] as $k ) {
            $clean[ $k ] = $old[ $k ];
        }
    }
    // On multisite only super admins may change scan paths
    if ( is_multisite() && ! is_super_admin() ) {
        $clean['custom_folders'] = $old['custom_folders'];
    }

    $webp_allowed = [ 'cwebp', 'gd' ];
    $avif_allowed = [ 'avifenc', 'imagick', 'gd' ];
    $jxl_allowed  = [ 'cjxl', 'imagick' ];

    $clean['webp_converter'] = ( isset( $input['webp_converter'] ) && in_array( $input['webp_converter'], $webp_allowed, true ) ) ? $input['webp_converter'] : 'cwebp';
    $clean['avif_converter'] = ( isset( $input['avif_converter'] ) && in_array( $input['avif_converter'], $avif_allowed, true ) ) ? $input['avif_converter'] : 'gd'; // same default as henkan_default_settings()
    $clean['jxl_converter']  = ( isset( $input['jxl_converter'] ) && in_array( $input['jxl_converter'], $jxl_allowed, true ) ) ? $input['jxl_converter'] : 'cjxl';

    return $clean;
}

function henkan_get_stats() {
    global $wpdb;
    $cache_key_total = 'henkan_stats_total_v21';
    $total = wp_cache_get( $cache_key_total, 'henkan' );
    
    if ( false === $total ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jxl')" );
        wp_cache_set( $cache_key_total, $total, 'henkan', 300 );
    }

    $cache_key_conv = 'henkan_stats_converted_v21';
    $converted = wp_cache_get( $cache_key_conv, 'henkan' );
    
    if ( false === $converted ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $converted = (int) $wpdb->get_var( "
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}henkan_data h ON p.ID = h.attachment_id
            WHERE p.post_type='attachment' 
            AND (
                p.post_mime_type IN ('image/webp', 'image/avif', 'image/jxl')
                OR h.attachment_id IS NOT NULL
            )
        " );
        wp_cache_set( $cache_key_conv, $converted, 'henkan', 300 );
    }

    $percent = ($total > 0) ? round(($converted / $total) * 100) : 0;
    if ($percent > 100) $percent = 100;
    
    return [ 'total' => $total, 'converted' => $converted, 'remaining' => max( 0, $total - $converted ), 'percent' => $percent ];
}

function henkan_detect_cache_plugin() {
    if ( function_exists( 'rocket_clean_domain' ) ) return 'WP Rocket';
    if ( function_exists( 'w3tc_flush_all' ) ) return 'W3 Total Cache';
    if ( function_exists( 'wp_cache_clear_cache' ) ) return 'WP Super Cache';
    if ( class_exists( 'autoptimizeCache' ) ) return 'Autoptimize';
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) return 'LiteSpeed Cache';
    return false;
}

function henkan_get_server_info() {
    $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
    $server_software = strtolower( $server_software );
    if ( strpos( $server_software, 'nginx' ) !== false ) return 'nginx';
    if ( strpos( $server_software, 'litespeed' ) !== false ) return 'litespeed';
    return 'apache';
}

function henkan_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $s = henkan_get_settings();
    $stats = henkan_get_stats();
    $server_type = henkan_get_server_info();
    $is_nginx = ( $server_type === 'nginx' );
    $cache_plugin = henkan_detect_cache_plugin();

    $webp_supported = function_exists( 'imagewebp' );
    $avif_supported = function_exists( 'imageavif' );

    $upload_dir_info = wp_get_upload_dir();
    $uploads_path = $upload_dir_info['basedir'];
    $theme_path = get_stylesheet_directory();

    $badge_webp = $webp_supported ? '<span class="hk-pill hk-pill-ok">OK</span>' : '<span class="hk-pill hk-pill-bad">' . esc_html__( 'Missing', 'henkan-webp-avif-converter' ) . '</span>';
    $badge_avif = $avif_supported ? '<span class="hk-pill hk-pill-ok">OK</span>' : '<span class="hk-pill hk-pill-bad">' . esc_html__( 'Missing', 'henkan-webp-avif-converter' ) . '</span>';
    $badge_jxl  = '<span class="hk-pill hk-pill-exp">' . esc_html__( 'Experimental', 'henkan-webp-avif-converter' ) . '</span>';

    $tabs = [
        'general'  => __( 'General', 'henkan-webp-avif-converter' ),
        'folders'  => __( 'Folders', 'henkan-webp-avif-converter' ),
        'advanced' => __( 'Advanced', 'henkan-webp-avif-converter' ),
        'smart'    => __( 'Smart Options', 'henkan-webp-avif-converter' ),
        'rewrites' => __( 'Server Rewrites', 'henkan-webp-avif-converter' ),
        'cli'      => __( 'WP-CLI', 'henkan-webp-avif-converter' ),
    ];
    ?>
    <div class="wrap henkan-wrap">
        <div class="hk-header">
            <div class="hk-title">
                <img class="hk-banner" src="<?php echo esc_url( HENKAN_URL . 'assets/banner-772x250.png' ); ?>" width="772" height="250" alt="<?php esc_attr_e( 'Henkan', 'henkan-webp-avif-converter' ); ?>">
                <span class="hk-badge">v<?php echo esc_html( HENKAN_VERSION ); ?></span>
            </div>
            <div class="hk-header-sub"><?php esc_html_e( 'Image optimization', 'henkan-webp-avif-converter' ); ?> · WebP · AVIF · JPEG XL</div>
        </div>

        <?php // Admin notices are moved below the first h1 by WordPress; keep it below the header ?>
        <h1 class="hk-screen-title"><?php esc_html_e( 'Henkan', 'henkan-webp-avif-converter' ); ?></h1>

        <div class="hk-stats">
            <div class="hk-stat">
                <span class="hk-stat-icon hk-c-blue"><span class="dashicons dashicons-format-gallery"></span></span>
                <div><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong><small><?php esc_html_e( 'DB Images', 'henkan-webp-avif-converter' ); ?></small></div>
            </div>
            <div class="hk-stat">
                <span class="hk-stat-icon hk-c-green"><span class="dashicons dashicons-yes-alt"></span></span>
                <div><strong><?php echo esc_html( number_format_i18n( $stats['converted'] ) ); ?></strong><small><?php esc_html_e( 'Optimized', 'henkan-webp-avif-converter' ); ?></small></div>
            </div>
            <div class="hk-stat">
                <span class="hk-stat-icon hk-c-violet"><span class="dashicons dashicons-admin-network"></span></span>
                <div><strong><?php echo esc_html( ucfirst( $server_type ) ); ?></strong><small><?php esc_html_e( 'Server', 'henkan-webp-avif-converter' ); ?></small></div>
            </div>
            <div class="hk-stat">
                <span class="hk-stat-icon hk-c-amber"><span class="dashicons dashicons-update"></span></span>
                <div><strong><?php echo $cache_plugin ? esc_html__( 'Active', 'henkan-webp-avif-converter' ) : esc_html__( 'Inactive', 'henkan-webp-avif-converter' ); ?></strong><small><?php echo $cache_plugin ? esc_html( $cache_plugin ) : esc_html__( 'No Cache', 'henkan-webp-avif-converter' ); ?></small></div>
            </div>
        </div>

        <nav class="hk-nav">
            <?php $henkan_first = true; foreach ( $tabs as $henkan_key => $henkan_label ) : ?>
                <button type="button" class="hk-tab main-tab-btn<?php echo $henkan_first ? ' active' : ''; ?>" data-target="<?php echo esc_attr( $henkan_key ); ?>"><?php echo esc_html( $henkan_label ); ?></button>
            <?php $henkan_first = false; endforeach; ?>
        </nav>

        <div class="hk-layout">
            <div class="hk-main">
                <form method="post" action="options.php">
                    <?php settings_fields( 'henkan_settings_group' ); ?>

                    <div id="main-tab-general" class="main-tab-content">
                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Formats', 'henkan-webp-avif-converter' ); ?></h3>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[enable_webp]" value="1" <?php checked( 1, $s['enable_webp'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Enable WebP', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_webp ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[enable_avif]" value="1" <?php checked( 1, $s['enable_avif'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Enable AVIF', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_avif ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[enable_jxl]" value="1" <?php checked( 1, $s['enable_jxl'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Enable JPEG XL', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_jxl ); ?></span></label>
                            <p class="hk-desc"><?php esc_html_e( 'Note: Multiple formats can be generated simultaneously. Primary format priority (if originals are deleted): WebP > AVIF > JXL.', 'henkan-webp-avif-converter' ); ?></p>
                        </div>

                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Converters', 'henkan-webp-avif-converter' ); ?></h3>
                            <div class="hk-form-grid">
                                <label class="hk-label" for="henkan-webp-converter"><?php esc_html_e( 'WebP Converter', 'henkan-webp-avif-converter' ); ?></label>
                                <select id="henkan-webp-converter" class="hk-input" name="henkan_settings[webp_converter]">
                                    <option value="cwebp" <?php selected( 'cwebp', $s['webp_converter'] ?? 'cwebp' ); ?>>cwebp (exec)</option>
                                    <option value="gd" <?php selected( 'gd', $s['webp_converter'] ?? 'cwebp' ); ?>>GD (imagewebp)</option>
                                </select>

                                <label class="hk-label" for="henkan-avif-converter"><?php esc_html_e( 'AVIF Converter', 'henkan-webp-avif-converter' ); ?></label>
                                <select id="henkan-avif-converter" class="hk-input" name="henkan_settings[avif_converter]">
                                    <option value="avifenc" <?php selected( 'avifenc', $s['avif_converter'] ?? 'avifenc' ); ?>>avifenc (exec)</option>
                                    <option value="imagick" <?php selected( 'imagick', $s['avif_converter'] ?? 'avifenc' ); ?>>ImageMagick (exec)</option>
                                    <option value="gd" <?php selected( 'gd', $s['avif_converter'] ?? 'avifenc' ); ?>>GD (imageavif)</option>
                                </select>

                                <label class="hk-label" for="henkan-jxl-converter"><?php esc_html_e( 'JXL Converter', 'henkan-webp-avif-converter' ); ?></label>
                                <select id="henkan-jxl-converter" class="hk-input" name="henkan_settings[jxl_converter]">
                                    <option value="cjxl" <?php selected( 'cjxl', $s['jxl_converter'] ?? 'cjxl' ); ?>>cjxl (exec)</option>
                                    <option value="imagick" <?php selected( 'imagick', $s['jxl_converter'] ?? 'cjxl' ); ?>>ImageMagick (exec)</option>
                                </select>

                                <label class="hk-label" for="henkan-quality"><?php esc_html_e( 'Quality (1-100)', 'henkan-webp-avif-converter' ); ?></label>
                                <input id="henkan-quality" type="number" name="henkan_settings[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" min="1" max="100" class="hk-input hk-input-small">
                            </div>
                        </div>
                        <div class="hk-actions"><?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ), 'primary', 'submit', false ); ?></div>
                    </div>

                    <div id="main-tab-folders" class="main-tab-content" hidden>
                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Scan Folders', 'henkan-webp-avif-converter' ); ?></h3>
                            <label class="hk-check-card"><input type="checkbox" name="henkan_settings[scan_uploads_dir]" value="1" <?php checked( 1, $s['scan_uploads_dir'] ); ?>><div><strong><?php esc_html_e( 'Uploads Directory', 'henkan-webp-avif-converter' ); ?></strong><code><?php echo esc_html( $uploads_path ); ?></code></div></label>
                            <label class="hk-check-card"><input type="checkbox" name="henkan_settings[scan_theme_dir]" value="1" <?php checked( 1, $s['scan_theme_dir'] ); ?>><div><strong><?php esc_html_e( 'Theme Directory', 'henkan-webp-avif-converter' ); ?></strong><code><?php echo esc_html( $theme_path ); ?></code></div></label>
                            <label class="hk-label" for="henkan-custom-folders"><?php esc_html_e( 'Additional Paths:', 'henkan-webp-avif-converter' ); ?></label>
                            <textarea id="henkan-custom-folders" name="henkan_settings[custom_folders]" class="hk-input hk-textarea" rows="3" placeholder="/var/www/html/extra"><?php echo esc_textarea( $s['custom_folders'] ); ?></textarea>
                            <?php
                            // Show folders that are ignored (missing or outside the WordPress installation)
                            $henkan_allowed_roots = array_map( 'wp_normalize_path', henkan_custom_roots( $s ) );
                            foreach ( preg_split( '/\R/', (string) $s['custom_folders'] ) as $henkan_c ) {
                                $henkan_c = trim( $henkan_c );
                                if ( $henkan_c === '' ) continue;
                                $henkan_real = realpath( $henkan_c );
                                if ( $henkan_real && in_array( wp_normalize_path( $henkan_real ), $henkan_allowed_roots, true ) ) continue;
                                echo '<p class="hk-error-text">' . esc_html(
                                    /* translators: %s: Folder path */
                                    sprintf( __( 'Ignored: %s (not found or outside the WordPress installation)', 'henkan-webp-avif-converter' ), $henkan_c )
                                ) . '</p>';
                            }
                            ?>
                        </div>
                        <div class="hk-actions"><?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ), 'primary', 'submit', false ); ?></div>
                    </div>

                    <div id="main-tab-advanced" class="main-tab-content" hidden>
                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Frontend', 'henkan-webp-avif-converter' ); ?></h3>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[picture_filter_enabled]" value="1" <?php checked( 1, $s['picture_filter_enabled'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Frontend <picture>', 'henkan-webp-avif-converter' ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[enable_lazy_loading]" value="1" <?php checked( 1, $s['enable_lazy_loading'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Native Lazy-Loading', 'henkan-webp-avif-converter' ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[auto_clear_cache]" value="1" <?php checked( 1, $s['auto_clear_cache'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Auto-Cache-Clear', 'henkan-webp-avif-converter' ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[enable_bg_queue]" value="1" <?php checked( 1, $s['enable_bg_queue'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Enable Background Queue (Bulk Only)', 'henkan-webp-avif-converter' ); ?></span></label>
                        </div>
                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Files', 'henkan-webp-avif-converter' ); ?></h3>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[keep_original]" value="1" <?php checked( 1, $s['keep_original'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Keep Originals', 'henkan-webp-avif-converter' ); ?></span></label>
                            <label class="hk-toggle"><input type="checkbox" name="henkan_settings[debug]" value="1" <?php checked( 1, $s['debug'] ); ?>><span class="hk-slider"></span><span><?php esc_html_e( 'Debug Logging', 'henkan-webp-avif-converter' ); ?></span></label>
                        </div>
                        <div class="hk-card">
                            <h3><?php esc_html_e( 'Exclusions', 'henkan-webp-avif-converter' ); ?></h3>
                            <p class="hk-desc">
                                <?php
                                echo wp_kses(
                                    __( 'One keyword or path part per line (e.g. <code>logo</code>). Regex is possible (e.g. <code>/.*_raw\.png$/</code>).', 'henkan-webp-avif-converter' ),
                                    [ 'code' => [] ]
                                );
                                ?>
                            </p>
                            <textarea name="henkan_settings[exclusions]" class="hk-input hk-textarea" rows="4" placeholder="logo&#10;/banner-\d+\.jpg$/"><?php echo esc_textarea( $s['exclusions'] ?? '' ); ?></textarea>
                        </div>
                        <div class="hk-actions"><?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ), 'primary', 'submit', false ); ?></div>
                    </div>
                </form>

                <?php // Smart Options: optional features, all disabled by default (no effect on existing setups) ?>
                <div id="main-tab-smart" class="main-tab-content" hidden>
                    <p class="hk-intro"><?php esc_html_e( 'Optional features for advanced use cases. All options are disabled by default and have no effect on existing setups unless explicitly activated.', 'henkan-webp-avif-converter' ); ?></p>

                    <form method="post" action="options.php">
                        <?php settings_fields( 'henkan_settings_group' ); ?>
                        <?php
                        // Send all other settings as hidden fields so they are not lost when this tab is saved
                        $hidden_keys = [
                            'enable_webp', 'enable_avif', 'enable_jxl',
                            'webp_converter', 'avif_converter', 'jxl_converter',
                            'keep_original', 'quality', 'debug', 'batch_size',
                            'bulk_only_unconverted', 'picture_filter_enabled',
                            'enable_lazy_loading', 'enable_bg_queue', 'auto_clear_cache',
                            'scan_uploads_dir', 'scan_theme_dir', 'custom_folders', 'exclusions',
                        ];
                        echo '<input type="hidden" name="henkan_settings[_smart_tab]" value="1">';
                        foreach ( $hidden_keys as $hk ) {
                            $hv = isset( $s[ $hk ] ) ? $s[ $hk ] : '';
                            echo '<input type="hidden" name="henkan_settings[' . esc_attr( $hk ) . ']" value="' . esc_attr( $hv ) . '">';
                        }
                        ?>

                        <?php // Section 1: quality by image size ?>
                        <div class="hk-card hk-accent-blue">
                            <h3><?php esc_html_e( 'Quality by Image Size', 'henkan-webp-avif-converter' ); ?> <span class="hk-pill hk-pill-blue"><?php esc_html_e( 'Optional', 'henkan-webp-avif-converter' ); ?></span></h3>
                            <p class="hk-desc"><?php esc_html_e( 'Apply a lower quality to WordPress-generated thumbnail sizes (thumbnail, medium, medium_large) to save disk space. Full-size images always use the global quality setting above. Only activates if WordPress thumbnail sizes are actually in use — sites using a single custom resolution are not affected.', 'henkan-webp-avif-converter' ); ?></p>
                            <label class="hk-toggle">
                                <input type="checkbox" name="henkan_settings[smart_quality_enabled]" value="1" <?php checked( 1, $s['smart_quality_enabled'] ?? 0 ); ?>>
                                <span class="hk-slider"></span>
                                <span><strong><?php esc_html_e( 'Enable Quality by Image Size', 'henkan-webp-avif-converter' ); ?></strong></span>
                            </label>
                            <?php if ( ! empty( $s['smart_quality_enabled'] ) ) : ?>
                            <div class="hk-sub">
                                <div class="hk-form-grid">
                                    <label class="hk-label" for="henkan-smart-quality"><?php esc_html_e( 'Quality for WordPress thumbnails', 'henkan-webp-avif-converter' ); ?> <small class="hk-muted">(thumbnail, medium, medium_large)</small></label>
                                    <input id="henkan-smart-quality" type="number" name="henkan_settings[smart_quality_thumb]" value="<?php echo esc_attr( $s['smart_quality_thumb'] ?? 70 ); ?>" min="1" max="100" class="hk-input hk-input-small">
                                </div>
                                <p class="hk-mini"><?php esc_html_e( 'This setting only applies if WordPress actually generates these crop sizes. If you use a single resolution (no cropping), this has no effect regardless.', 'henkan-webp-avif-converter' ); ?></p>
                            </div>
                            <?php else : ?>
                            <p class="hk-mini"><?php esc_html_e( 'Enable to configure thumbnail quality.', 'henkan-webp-avif-converter' ); ?></p>
                            <?php endif; ?>
                        </div>

                        <?php // Section 2: integrity check ?>
                        <div class="hk-card hk-accent-green">
                            <h3><?php esc_html_e( 'Integrity Check', 'henkan-webp-avif-converter' ); ?> <span class="hk-pill hk-pill-ok"><?php esc_html_e( 'Optional', 'henkan-webp-avif-converter' ); ?></span></h3>
                            <p class="hk-desc"><?php esc_html_e( 'Runs a background check (WP-Cron) that verifies whether converted files recorded in the Henkan database still physically exist on disk. Missing files are automatically re-queued for conversion. Useful after server migrations or accidental file deletions.', 'henkan-webp-avif-converter' ); ?></p>
                            <label class="hk-toggle">
                                <input type="checkbox" name="henkan_settings[integrity_check_enabled]" value="1" <?php checked( 1, $s['integrity_check_enabled'] ?? 0 ); ?>>
                                <span class="hk-slider"></span>
                                <span><strong><?php esc_html_e( 'Enable Automatic Integrity Check', 'henkan-webp-avif-converter' ); ?></strong></span>
                            </label>
                            <?php if ( ! empty( $s['integrity_check_enabled'] ) ) :
                                $last_run = get_option( 'henkan_integrity_last_run', 0 );
                                $missing  = (int) get_option( 'henkan_integrity_missing_count', 0 );
                            ?>
                            <div class="hk-sub hk-kv">
                                <span class="k"><?php esc_html_e( 'Last run:', 'henkan-webp-avif-converter' ); ?></span>
                                <span class="v"><?php echo $last_run ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) ) : esc_html__( 'Never', 'henkan-webp-avif-converter' ); ?></span>
                                <span class="k"><?php esc_html_e( 'Missing files found:', 'henkan-webp-avif-converter' ); ?></span>
                                <span class="v <?php echo $missing > 0 ? 'hk-bad' : 'hk-ok'; ?>"><?php echo esc_html( $missing ); ?></span>
                            </div>
                            <?php else : ?>
                            <p class="hk-mini"><?php esc_html_e( 'Runs automatically once per day via WP-Cron when enabled.', 'henkan-webp-avif-converter' ); ?></p>
                            <?php endif; ?>
                        </div>

                        <?php // Section 3: maximum output width ?>
                        <div class="hk-card hk-accent-amber">
                            <h3><?php esc_html_e( 'Max Output Width', 'henkan-webp-avif-converter' ); ?> <span class="hk-pill hk-pill-amber"><?php esc_html_e( 'Optional', 'henkan-webp-avif-converter' ); ?></span></h3>
                            <p class="hk-desc"><?php esc_html_e( 'Automatically resize images during conversion if they exceed a defined maximum width. Applies only to the converted output file — originals are always preserved if "Keep Originals" is enabled. Useful when images are uploaded at 4000px+ but never displayed wider than 1200px.', 'henkan-webp-avif-converter' ); ?></p>
                            <label class="hk-toggle">
                                <input type="checkbox" name="henkan_settings[max_width_enabled]" value="1" <?php checked( 1, $s['max_width_enabled'] ?? 0 ); ?>>
                                <span class="hk-slider"></span>
                                <span><strong><?php esc_html_e( 'Enable Max Output Width', 'henkan-webp-avif-converter' ); ?></strong></span>
                            </label>
                            <?php if ( ! empty( $s['max_width_enabled'] ) ) : ?>
                            <div class="hk-sub">
                                <div class="hk-form-grid">
                                    <label class="hk-label" for="henkan-max-width"><?php esc_html_e( 'Maximum width (px)', 'henkan-webp-avif-converter' ); ?></label>
                                    <input id="henkan-max-width" type="number" name="henkan_settings[max_width_px]" value="<?php echo esc_attr( $s['max_width_px'] ?? 2000 ); ?>" min="100" max="10000" step="100" class="hk-input hk-input-small">
                                </div>
                                <p class="hk-mini"><?php esc_html_e( 'Images already narrower than this value are not resized. Aspect ratio is always preserved.', 'henkan-webp-avif-converter' ); ?></p>
                            </div>
                            <?php else : ?>
                            <p class="hk-mini"><?php esc_html_e( 'Enable to set a maximum pixel width for converted output files.', 'henkan-webp-avif-converter' ); ?></p>
                            <?php endif; ?>
                        </div>

                        <?php // Section 4: conversion statistics ?>
                        <div class="hk-card hk-accent-violet">
                            <h3><?php esc_html_e( 'Conversion Statistics', 'henkan-webp-avif-converter' ); ?> <span class="hk-pill hk-pill-exp"><?php esc_html_e( 'Info', 'henkan-webp-avif-converter' ); ?></span></h3>
                            <p class="hk-desc"><?php esc_html_e( 'A summary of space savings achieved through conversion. Available via WP-CLI and shown here as a live overview.', 'henkan-webp-avif-converter' ); ?></p>
                            <?php
                            // Live statistics from the henkan_data table
                            global $wpdb;
                            $henkan_tbl = $wpdb->prefix . 'henkan_data';
                            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                            $total_converted = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $henkan_tbl ) );

                            $savings_bytes = (int) get_option( 'henkan_total_savings_bytes', 0 );
                            $savings_mb    = $savings_bytes > 0 ? round( $savings_bytes / 1048576, 2 ) : 0;
                            ?>
                            <div class="hk-big-stats">
                                <div><strong class="hk-c-blue-text"><?php echo esc_html( number_format_i18n( $total_converted ) ); ?></strong><small><?php esc_html_e( 'Images converted', 'henkan-webp-avif-converter' ); ?></small></div>
                                <div><strong class="hk-c-green-text"><?php echo esc_html( $savings_mb > 0 ? $savings_mb . ' MB' : '—' ); ?></strong><small><?php esc_html_e( 'Space saved', 'henkan-webp-avif-converter' ); ?></small></div>
                            </div>
                            <pre class="hk-console hk-cmd"><span class="hk-comment"># <?php esc_html_e( 'Via WP-CLI:', 'henkan-webp-avif-converter' ); ?></span>
wp henkan stats</pre>
                        </div>

                        <div class="hk-actions"><?php submit_button( __( 'Save Smart Options', 'henkan-webp-avif-converter' ), 'primary', 'submit', false ); ?></div>
                    </form>
                </div>

                <div id="main-tab-rewrites" class="main-tab-content" hidden>
                    <div class="hk-card">
                        <h3><?php esc_html_e( 'Server Rewrites', 'henkan-webp-avif-converter' ); ?></h3>
                        <p class="hk-desc"><?php esc_html_e( 'Copy these rules to your server configuration to serve optimized images directly.', 'henkan-webp-avif-converter' ); ?></p>

                        <div class="henkan-inner-tabs-container">
                            <div class="hk-subtabs">
                                <button type="button" class="hk-subtab henkan-tab-btn <?php echo ! $is_nginx ? 'active' : ''; ?>" data-target="apache">Apache / LiteSpeed</button>
                                <button type="button" class="hk-subtab henkan-tab-btn <?php echo $is_nginx ? 'active' : ''; ?>" data-target="nginx">Nginx</button>
                            </div>
                            <div id="tab-apache" class="henkan-tab-content" <?php echo $is_nginx ? 'hidden' : ''; ?>>
<textarea class="hk-code" rows="15" readonly onclick="this.select()">
&lt;IfModule mod_rewrite.c&gt;
  RewriteEngine On
  RewriteCond %{HTTP_ACCEPT} image/jxl
  RewriteCond %{DOCUMENT_ROOT}/$1.jxl -f
  RewriteRule ^(.+)\.(jpe?g|png)$ $1.jxl [T=image/jxl,E=accept:1,L]
  RewriteCond %{HTTP_ACCEPT} image/avif
  RewriteCond %{DOCUMENT_ROOT}/$1.avif -f
  RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1,L]
  RewriteCond %{HTTP_ACCEPT} image/webp
  RewriteCond %{DOCUMENT_ROOT}/$1.webp -f
  RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1,L]
&lt;/IfModule&gt;
&lt;IfModule mod_headers.c&gt;
  Header append Vary Accept env=REDIRECT_accept
&lt;/IfModule&gt;
</textarea>
                            </div>
                            <div id="tab-nginx" class="henkan-tab-content" <?php echo $is_nginx ? '' : 'hidden'; ?>>
                                <p class="hk-label"><?php esc_html_e( 'http block:', 'henkan-webp-avif-converter' ); ?></p>
<textarea class="hk-code" rows="7" readonly onclick="this.select()">
map $http_accept $webp_avif_jxl_suffix {
    default "";
    "~*jxl" ".jxl";
    "~*avif" ".avif";
    "~*webp" ".webp";
}
</textarea>
                                <p class="hk-label"><?php esc_html_e( 'server block:', 'henkan-webp-avif-converter' ); ?></p>
<textarea class="hk-code" rows="5" readonly onclick="this.select()">
location ~* ^.+\.(png|jpe?g)$ {
    add_header Vary Accept;
    try_files $uri$webp_avif_jxl_suffix $uri =404;
}
</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="main-tab-cli" class="main-tab-content" hidden>
                    <div class="hk-card">
                        <h3><?php esc_html_e( 'WP-CLI Commands', 'henkan-webp-avif-converter' ); ?></h3>
                        <p class="hk-desc"><?php esc_html_e( 'Manage your image optimization directly from the terminal.', 'henkan-webp-avif-converter' ); ?></p>
<pre class="hk-console hk-cmd"><span class="hk-comment"># <?php esc_html_e( 'Status:', 'henkan-webp-avif-converter' ); ?></span>
wp henkan scan

<span class="hk-comment"># <?php esc_html_e( 'Convert (missing):', 'henkan-webp-avif-converter' ); ?></span>
wp henkan convert

<span class="hk-comment"># <?php esc_html_e( 'Force all:', 'henkan-webp-avif-converter' ); ?></span>
wp henkan convert --force

<span class="hk-comment"># <?php esc_html_e( 'Repair thumbnails (older bulk conversions with "Keep original" off):', 'henkan-webp-avif-converter' ); ?></span>
wp henkan repair</pre>
                    </div>
                </div>
            </div>

            <div class="hk-side">
                <div class="hk-card bulk-card">
                    <h3><?php esc_html_e( 'Bulk Optimization', 'henkan-webp-avif-converter' ); ?></h3>
                    <div class="henkan-progress-circle<?php echo $stats['percent'] < 1 ? ' is-empty' : ''; ?>" data-percent="<?php echo esc_attr( $stats['percent'] ); ?>">
                        <svg viewBox="0 0 36 36">
                            <defs>
                                <linearGradient id="henkan-ring" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0%" stop-color="#2563eb"/>
                                    <stop offset="100%" stop-color="#10b981"/>
                                </linearGradient>
                            </defs>
                            <path class="bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="meter" stroke-dasharray="<?php echo esc_attr( $stats['percent'] ); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="percent-text"><?php echo esc_html( $stats['percent'] ); ?>%</div>
                    </div>
                    <p class="hk-ring-caption">
                        <?php
                        /* translators: 1: Number of converted images, 2: Total number of images */
                        printf( esc_html__( '%1$s of %2$s images optimized.', 'henkan-webp-avif-converter' ), '<strong>' . esc_html( number_format_i18n( $stats['converted'] ) ) . '</strong>', esc_html( number_format_i18n( $stats['total'] ) ) );
                        ?>
                    </p>
                    <div class="hk-bulk-opts">
                        <label class="hk-check"><input type="checkbox" id="henkan_bulk_only_unconverted" <?php checked( 1, $s['bulk_only_unconverted'] ); ?>> <?php esc_html_e( 'Only missing (File-Check)', 'henkan-webp-avif-converter' ); ?></label>
                        <label class="hk-check"><input type="checkbox" id="henkan_bulk_only_failed"> <?php esc_html_e( 'Only failed', 'henkan-webp-avif-converter' ); ?></label>
                        <label class="hk-check"><input type="checkbox" id="henkan_bulk_rescan_all"> <?php esc_html_e( 'Force', 'henkan-webp-avif-converter' ); ?></label>
                    </div>
                    <button id="henkan_start_scan" type="button" class="button button-primary hk-full"><?php esc_html_e( 'Start Scan', 'henkan-webp-avif-converter' ); ?></button>
                    <div id="henkan_scan_results" class="hk-scan-results" style="display:none;">
                        <div class="hk-kv">
                            <span class="k"><?php esc_html_e( 'Found:', 'henkan-webp-avif-converter' ); ?></span><strong class="v" id="henkan_total_found">0</strong>
                            <span class="k"><?php esc_html_e( 'To do:', 'henkan-webp-avif-converter' ); ?></span><strong class="v" id="henkan_to_convert">0</strong>
                        </div>
                        <button id="henkan_start_convert" type="button" class="button button-primary hk-full hk-go"><?php esc_html_e( 'Start Optimization', 'henkan-webp-avif-converter' ); ?></button>
                        <button id="henkan_resume_convert" type="button" class="button button-secondary hk-full" style="display:none;"><?php esc_html_e( 'Resume', 'henkan-webp-avif-converter' ); ?></button>
                    </div>
                    <div id="henkan_progress_ui" style="display:none;">
                        <div class="henkan-progress-bar"><div class="fill" style="width:0%"></div></div>
                        <p id="henkan_status_text" class="hk-status-text"><?php esc_html_e( 'Initializing...', 'henkan-webp-avif-converter' ); ?></p>
                    </div>
                    <ul id="henkan_log_list" class="hk-log"></ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}
