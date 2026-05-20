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
    echo '<p style="text-align:right; margin-top:10px;"><a href="' . esc_url( admin_url( 'options-general.php?page=henkan-settings' ) ) . '" class="button button-small">' . esc_html__( 'To Converter', 'henkan-webp-avif-converter' ) . '</a></p>';
}

add_action( 'admin_bar_menu', 'henkan_admin_bar_menu', 99 );
function henkan_admin_bar_menu( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $wp_admin_bar->add_node( [ 'id' => 'henkan_menu', 'title' => '<span class="ab-icon dashicons dashicons-format-image"></span> Henkan', 'href' => admin_url( 'options-general.php?page=henkan-settings' ) ] );
    $wp_admin_bar->add_node( [ 'id' => 'henkan_settings', 'title' => __( 'Settings', 'henkan-webp-avif-converter' ), 'parent' => 'henkan_menu', 'href' => admin_url( 'options-general.php?page=henkan-settings' ) ] );
}

function henkan_sanitize_settings( $input ) {
    if ( ! is_array( $input ) ) $input = [];
    
    $clean = [];
    $defaults = henkan_default_settings();
    
    $bool_keys = [ 'enable_webp', 'enable_avif', 'enable_jxl', 'keep_original', 'picture_filter_enabled', 'enable_lazy_loading', 'debug', 'auto_clear_cache', 'scan_uploads_dir', 'scan_theme_dir', 'bulk_only_unconverted', 'enable_bg_queue' ];
    foreach ( $bool_keys as $key ) {
        $clean[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
    }

    $quality = isset( $input['quality'] ) ? absint( $input['quality'] ) : $defaults['quality'];
    $clean['quality'] = max( 1, min( 100, $quality ) );
    $clean['batch_size'] = isset( $input['batch_size'] ) ? absint( $input['batch_size'] ) : $defaults['batch_size'];
    $clean['custom_folders'] = isset( $input['custom_folders'] ) ? sanitize_textarea_field( wp_unslash( $input['custom_folders'] ) ) : '';
    $clean['exclusions'] = isset( $input['exclusions'] ) ? sanitize_textarea_field( wp_unslash( $input['exclusions'] ) ) : '';

    $webp_allowed = [ 'cwebp', 'gd' ];
    $avif_allowed = [ 'avifenc', 'imagick', 'gd' ];
    $jxl_allowed  = [ 'cjxl', 'imagick' ];

    $clean['webp_converter'] = ( isset( $input['webp_converter'] ) && in_array( $input['webp_converter'], $webp_allowed, true ) ) ? $input['webp_converter'] : 'cwebp';
    $clean['avif_converter'] = ( isset( $input['avif_converter'] ) && in_array( $input['avif_converter'], $avif_allowed, true ) ) ? $input['avif_converter'] : 'avifenc';
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
    
    $badge_webp = $webp_supported ? '<span class="henkan-badge yes">OK</span>' : '<span class="henkan-badge no">' . esc_html__( 'Missing', 'henkan-webp-avif-converter' ) . '</span>';
    $badge_avif = $avif_supported ? '<span class="henkan-badge yes">OK</span>' : '<span class="henkan-badge no">' . esc_html__( 'Missing', 'henkan-webp-avif-converter' ) . '</span>';
    $badge_jxl  = '<span class="henkan-badge" style="background:#8a2be2;">' . esc_html__( 'Experimental', 'henkan-webp-avif-converter' ) . '</span>';
    ?>
    <div class="wrap henkan-wrap">
        <div class="henkan-header">
            <div class="header-title"><h1><?php esc_html_e( 'Henkan', 'henkan-webp-avif-converter' ); ?> <span class="version">v2.2.0</span></h1></div>
            <div class="header-branding"><span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px; color:#ccc;"></span></div>
        </div>
        <div class="henkan-grid top-stats">
            <div class="henkan-stat-card"><span class="dashicons dashicons-format-gallery"></span><div class="stat-data"><strong><?php echo esc_html( $stats['total'] ); ?></strong><small><?php esc_html_e( 'DB Images', 'henkan-webp-avif-converter' ); ?></small></div></div>
            <div class="henkan-stat-card success"><span class="dashicons dashicons-yes-alt"></span><div class="stat-data"><strong><?php echo esc_html( $stats['converted'] ); ?></strong><small><?php esc_html_e( 'Optimized', 'henkan-webp-avif-converter' ); ?></small></div></div>
            <div class="henkan-stat-card info"><span class="dashicons dashicons-admin-network"></span><div class="stat-data"><strong><?php echo esc_html( ucfirst( $server_type ) ); ?></strong><small><?php esc_html_e( 'Server', 'henkan-webp-avif-converter' ); ?></small></div></div>
            <div class="henkan-stat-card warning"><span class="dashicons dashicons-update"></span><div class="stat-data"><strong><?php echo $cache_plugin ? esc_html__( 'Active', 'henkan-webp-avif-converter' ) : esc_html__( 'Inactive', 'henkan-webp-avif-converter' ); ?></strong><small><?php echo $cache_plugin ? esc_html( $cache_plugin ) : esc_html__( 'No Cache', 'henkan-webp-avif-converter' ); ?></small></div></div>
        </div>
        
        <div class="henkan-grid main-content">
            <div class="henkan-col-left">
                <div class="henkan-card" style="padding: 0; overflow: hidden;">
                    
                    <div class="henkan-main-tabs" style="display: flex; flex-wrap: wrap; background: #f0f0f1; border-bottom: 1px solid #c3c4c7;">
                        <button type="button" class="main-tab-btn active" data-target="general" style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid #2271b1; color: #2271b1; transition: all 0.2s;"><?php esc_html_e( 'General', 'henkan-webp-avif-converter' ); ?></button>
                        <button type="button" class="main-tab-btn" data-target="folders" style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s;"><?php esc_html_e( 'Folders', 'henkan-webp-avif-converter' ); ?></button>
                        <button type="button" class="main-tab-btn" data-target="advanced" style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s;"><?php esc_html_e( 'Advanced', 'henkan-webp-avif-converter' ); ?></button>
                        <button type="button" class="main-tab-btn" data-target="rewrites" style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s;"><?php esc_html_e( 'Server Rewrites', 'henkan-webp-avif-converter' ); ?></button>
                        <button type="button" class="main-tab-btn" data-target="cli" style="flex: 1; padding: 15px; border: none; background: none; cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.2s;"><?php esc_html_e( 'WP-CLI', 'henkan-webp-avif-converter' ); ?></button>
                    </div>

                    <div style="padding: 20px;">
                        <form method="post" action="options.php">
                            <?php settings_fields( 'henkan_settings_group' ); ?>
                            
                            <div id="main-tab-general" class="main-tab-content" style="display: block;">
                                <div class="henkan-section">
                                    <label class="henkan-toggle"><input type="checkbox" name="henkan_settings[enable_webp]" value="1" <?php checked( 1, $s['enable_webp'] ); ?>><span class="slider"></span><span class="label-text"><?php esc_html_e( 'Enable WebP', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_webp ); ?></span></label>
                                    <label class="henkan-toggle"><input type="checkbox" name="henkan_settings[enable_avif]" value="1" <?php checked( 1, $s['enable_avif'] ); ?>><span class="slider"></span><span class="label-text"><?php esc_html_e( 'Enable AVIF', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_avif ); ?></span></label>
                                    <label class="henkan-toggle"><input type="checkbox" name="henkan_settings[enable_jxl]" value="1" <?php checked( 1, $s['enable_jxl'] ); ?>><span class="slider"></span><span class="label-text"><?php esc_html_e( 'Enable JPEG XL', 'henkan-webp-avif-converter' ); ?> <?php echo wp_kses_post( $badge_jxl ); ?></span></label>
                                    
                                    <p style="font-size: 11px; color: #666; margin-top: 10px; margin-bottom: 15px;">
                                        <?php esc_html_e( 'Note: Multiple formats can be generated simultaneously. Primary format priority (if originals are deleted): WebP > AVIF > JXL.', 'henkan-webp-avif-converter' ); ?>
                                    </p>

                                    <div style="display:grid; grid-template-columns: 180px 1fr; gap:10px; align-items:center; background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #eee;">
                                        <label style="font-weight:600;"><?php esc_html_e( 'WebP Converter', 'henkan-webp-avif-converter' ); ?></label>
                                        <select name="henkan_settings[webp_converter]">
                                            <option value="cwebp" <?php selected( 'cwebp', $s['webp_converter'] ?? 'cwebp' ); ?>>cwebp (exec)</option>
                                            <option value="gd" <?php selected( 'gd', $s['webp_converter'] ?? 'cwebp' ); ?>>GD (imagewebp)</option>
                                        </select>

                                        <label style="font-weight:600;"><?php esc_html_e( 'AVIF Converter', 'henkan-webp-avif-converter' ); ?></label>
                                        <select name="henkan_settings[avif_converter]">
                                            <option value="avifenc" <?php selected( 'avifenc', $s['avif_converter'] ?? 'avifenc' ); ?>>avifenc (exec)</option>
                                            <option value="imagick" <?php selected( 'imagick', $s['avif_converter'] ?? 'avifenc' ); ?>>ImageMagick (exec)</option>
                                            <option value="gd" <?php selected( 'gd', $s['avif_converter'] ?? 'avifenc' ); ?>>GD (imageavif)</option>
                                        </select>
                                        
                                        <label style="font-weight:600;"><?php esc_html_e( 'JXL Converter', 'henkan-webp-avif-converter' ); ?></label>
                                        <select name="henkan_settings[jxl_converter]">
                                            <option value="cjxl" <?php selected( 'cjxl', $s['jxl_converter'] ?? 'cjxl' ); ?>>cjxl (exec)</option>
                                            <option value="imagick" <?php selected( 'imagick', $s['jxl_converter'] ?? 'cjxl' ); ?>>ImageMagick (exec)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="henkan-section" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
                                    <label><?php esc_html_e( 'Quality (1-100)', 'henkan-webp-avif-converter' ); ?></label>
                                    <input type="number" name="henkan_settings[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" min="1" max="100" class="small-text">
                                </div>
                                <div style="margin-top: 20px;">
                                    <?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ) ); ?>
                                </div>
                            </div>

                            <div id="main-tab-folders" class="main-tab-content" style="display: none;">
                                <div class="henkan-section" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
                                    <strong style="display:block; margin-bottom:10px;"><?php esc_html_e( 'Scan Folders', 'henkan-webp-avif-converter' ); ?></strong>
                                    <label class="henkan-check-box-card"><input type="checkbox" name="henkan_settings[scan_uploads_dir]" value="1" <?php checked( 1, $s['scan_uploads_dir'] ); ?>><div><strong><?php esc_html_e( 'Uploads Directory', 'henkan-webp-avif-converter' ); ?></strong><code><?php echo esc_html( $uploads_path ); ?></code></div></label>
                                    <label class="henkan-check-box-card"><input type="checkbox" name="henkan_settings[scan_theme_dir]" value="1" <?php checked( 1, $s['scan_theme_dir'] ); ?>><div><strong><?php esc_html_e( 'Theme Directory', 'henkan-webp-avif-converter' ); ?></strong><code><?php echo esc_html( $theme_path ); ?></code></div></label>
                                    <label style="display:block; margin-top:10px; font-size:0.9em;"><?php esc_html_e( 'Additional Paths:', 'henkan-webp-avif-converter' ); ?></label>
                                    <textarea name="henkan_settings[custom_folders]" class="widefat code" rows="2" placeholder="/var/www/html/extra"><?php echo esc_textarea( $s['custom_folders'] ); ?></textarea>
                                </div>
                                <div style="margin-top: 20px;">
                                    <?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ) ); ?>
                                </div>
                            </div>

                            <div id="main-tab-advanced" class="main-tab-content" style="display: none;">
                                <div class="henkan-section">
                                    <strong style="display:block; margin-bottom:10px;"><?php esc_html_e( 'Frontend', 'henkan-webp-avif-converter' ); ?></strong>
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[picture_filter_enabled]" value="1" <?php checked( 1, $s['picture_filter_enabled'] ); ?>> <?php esc_html_e( 'Frontend <picture>', 'henkan-webp-avif-converter' ); ?></label>
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[enable_lazy_loading]" value="1" <?php checked( 1, $s['enable_lazy_loading'] ); ?>> <?php esc_html_e( 'Native Lazy-Loading', 'henkan-webp-avif-converter' ); ?></label>
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[auto_clear_cache]" value="1" <?php checked( 1, $s['auto_clear_cache'] ); ?>> <?php esc_html_e( 'Auto-Cache-Clear', 'henkan-webp-avif-converter' ); ?></label>
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[enable_bg_queue]" value="1" <?php checked( 1, $s['enable_bg_queue'] ); ?>> <?php esc_html_e( 'Enable Background Queue (Bulk Only)', 'henkan-webp-avif-converter' ); ?></label>
                                </div>
                                <div class="henkan-section" style="border-bottom:none; margin-bottom:0; padding-bottom:0;">
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[keep_original]" value="1" <?php checked( 1, $s['keep_original'] ); ?>> <?php esc_html_e( 'Keep Originals', 'henkan-webp-avif-converter' ); ?></label>
                                    <label class="henkan-check"><input type="checkbox" name="henkan_settings[debug]" value="1" <?php checked( 1, $s['debug'] ); ?>> <?php esc_html_e( 'Debug Logging', 'henkan-webp-avif-converter' ); ?></label>
                                </div>
                                <div class="henkan-section" style="border-bottom:none; margin-top:15px; padding-top:15px; border-top:1px solid #eee;">
                                    <strong style="display:block; margin-bottom:5px;"><?php esc_html_e( 'Exclusions', 'henkan-webp-avif-converter' ); ?></strong>
                                    <p style="font-size: 11px; color: #666; margin-top: 0; margin-bottom: 10px;">Ein Keyword oder Pfad-Teil pro Zeile (z.B. <code>logo</code>). Regex möglich (z.B. <code>/.*_raw\.png$/</code>).</p>
                                    <textarea name="henkan_settings[exclusions]" class="widefat code" rows="3" placeholder="logo&#10;/banner-\d+\.jpg$/"><?php echo esc_textarea( $s['exclusions'] ?? '' ); ?></textarea>
                                </div>
                                <div style="margin-top: 20px;">
                                    <?php submit_button( __( 'Save', 'henkan-webp-avif-converter' ) ); ?>
                                </div>
                            </div>
                        </form>

                        <div id="main-tab-rewrites" class="main-tab-content" style="display: none;">
                            <h2 style="margin-top:0; border-bottom:none; padding-bottom:0; font-size:1.2em;"><?php esc_html_e( 'Server Rewrites', 'henkan-webp-avif-converter' ); ?></h2>
                            <p style="color:#666; font-size:13px; margin-bottom:15px;"><?php esc_html_e( 'Copy these rules to your server configuration to serve optimized images directly.', 'henkan-webp-avif-converter' ); ?></p>
                            
                            <div class="henkan-inner-tabs-container">
                                <div class="henkan-tabs">
                                    <button type="button" class="henkan-tab-btn <?php echo ! $is_nginx ? 'active' : ''; ?>" data-target="apache">Apache</button>
                                    <button type="button" class="henkan-tab-btn <?php echo $is_nginx ? 'active' : ''; ?>" data-target="nginx">Nginx</button>
                                </div>
                                <div id="tab-apache" class="henkan-tab-content" style="display: <?php echo ! $is_nginx ? 'block' : 'none'; ?>;">
<textarea class="henkan-code" readonly onclick="this.select()">
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
                                <div id="tab-nginx" class="henkan-tab-content" style="display: <?php echo $is_nginx ? 'block' : 'none'; ?>;">
<textarea class="henkan-code small" readonly onclick="this.select()">
map $http_accept $webp_avif_jxl_suffix {
    default "";
    "~*jxl" ".jxl";
    "~*avif" ".avif";
    "~*webp" ".webp";
}
</textarea>
<textarea class="henkan-code small" readonly onclick="this.select()">
location ~* ^.+\.(png|jpe?g)$ {
    add_header Vary Accept;
    try_files $uri$webp_avif_jxl_suffix $uri =404;
}
</textarea>
                                </div>
                            </div>
                        </div>

                        <div id="main-tab-cli" class="main-tab-content" style="display: none;">
                            <h2 style="margin-top:0; border-bottom:none; padding-bottom:0; font-size:1.2em;"><?php esc_html_e( 'WP-CLI Commands', 'henkan-webp-avif-converter' ); ?></h2>
                            <p style="color:#666; font-size:13px; margin-bottom:15px;"><?php esc_html_e( 'Manage your image optimization directly from the terminal.', 'henkan-webp-avif-converter' ); ?></p>
                            
                            <div class="henkan-cli-box">
                                <p><strong><?php esc_html_e( 'Status:', 'henkan-webp-avif-converter' ); ?></strong><br><code>wp henkan scan</code></p>
                                <p><strong><?php esc_html_e( 'Convert (missing):', 'henkan-webp-avif-converter' ); ?></strong><br><code>wp henkan convert</code></p>
                                <p><strong><?php esc_html_e( 'Force all:', 'henkan-webp-avif-converter' ); ?></strong><br><code>wp henkan convert --force</code></p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            
            <div class="henkan-col-right">
                <div class="henkan-card bulk-card">
                    <h2><?php esc_html_e( 'Bulk Optimization', 'henkan-webp-avif-converter' ); ?></h2>
                    <div class="henkan-progress-circle" data-percent="<?php echo esc_attr( $stats['percent'] ); ?>">
                        <svg viewBox="0 0 36 36">
                            <path class="bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="meter" stroke-dasharray="<?php echo esc_attr( $stats['percent'] ); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="percent-text"><?php echo esc_html( $stats['percent'] ); ?>%</div>
                    </div>
                    <div class="henkan-bulk-opts">
                        <label><input type="checkbox" id="henkan_bulk_only_unconverted" <?php checked( 1, $s['bulk_only_unconverted'] ); ?>> <?php esc_html_e( 'Only missing (File-Check)', 'henkan-webp-avif-converter' ); ?></label><br>
                        <label><input type="checkbox" id="henkan_bulk_only_failed"> <?php esc_html_e( 'Only failed', 'henkan-webp-avif-converter' ); ?></label><br>
                        <label><input type="checkbox" id="henkan_bulk_rescan_all"> <?php esc_html_e( 'Force', 'henkan-webp-avif-converter' ); ?></label>
                    </div>
                    <button id="henkan_start_scan" class="button button-primary full-width"><?php esc_html_e( 'Start Scan', 'henkan-webp-avif-converter' ); ?></button>
                    <div id="henkan_scan_results" style="display:none; margin-top:15px; text-align:center;">
                        <p><?php esc_html_e( 'Found:', 'henkan-webp-avif-converter' ); ?> <strong id="henkan_total_found">0</strong><br><?php esc_html_e( 'To do:', 'henkan-webp-avif-converter' ); ?> <strong id="henkan_to_convert">0</strong></p>
                        <button id="henkan_start_convert" class="button button-hero full-width" style="background:#46b450; color:#fff;"><?php esc_html_e( 'Start Optimization', 'henkan-webp-avif-converter' ); ?></button>
                        <button id="henkan_resume_convert" class="button button-secondary full-width" style="display:none; margin-top:10px;">
                            <?php esc_html_e( 'Resume', 'henkan-webp-avif-converter' ); ?>
                        </button>
                    </div>
                    <div id="henkan_progress_ui" style="display:none; margin-top:15px;">
                        <div class="henkan-progress-bar"><div class="fill" style="width:0%"></div></div>
                        <p id="henkan_status_text" style="text-align:center; font-size:11px;"><?php esc_html_e( 'Initializing...', 'henkan-webp-avif-converter' ); ?></p>
                    </div>
                    <ul id="henkan_log_list" class="henkan-log-box"></ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}
