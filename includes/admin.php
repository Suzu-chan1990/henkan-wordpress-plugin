<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function() {
    add_options_page(
        __( 'Henkan Bild-Optimierung', 'henkan' ), 
        __( 'Henkan', 'henkan' ), 
        'manage_options', 
        'henkan-settings', 
        'henkan_admin_page'
    );
});

add_action( 'admin_init', function() {
    register_setting( 'henkan_settings_group', 'henkan_settings', 'henkan_sanitize_settings' );
});

add_filter( 'manage_media_columns', 'henkan_add_media_column' );
function henkan_add_media_column( $columns ) {
    $columns['henkan_status'] = __( 'Henkan', 'henkan' );
    return $columns;
}

add_action( 'manage_media_custom_column', 'henkan_media_custom_column_content', 10, 2 );
function henkan_media_custom_column_content( $column_name, $id ) {
    if ( 'henkan_status' !== $column_name ) return;

    $mime = get_post_mime_type( $id );
    
    // Nativ WebP/AVIF
    if ( in_array( $mime, [ 'image/webp', 'image/avif' ] ) ) {
        echo '<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <span style="color:#46b450; font-weight:bold;">' . esc_html__( 'Nativ', 'henkan' ) . '</span>';
        return;
    }

    if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ] ) ) {
        echo '<span style="color:#ccc;">—</span>';
        return;
    }

    $meta = get_post_meta( $id, '_henkan_converted_files', true );
    if ( ! empty( $meta ) ) {
        echo '<span class="dashicons dashicons-yes" style="color:#46b450;"></span> <span style="color:#46b450; font-weight:bold;">OK</span>';
    } else {
        echo '<button type="button" class="button button-small henkan-quick-convert" data-id="' . esc_attr( $id ) . '">' . esc_html__( 'Optimieren', 'henkan' ) . '</button>';
        echo '<span class="henkan-spinner spinner" style="float:none; margin-top:0;"></span>';
    }
}

add_action( 'wp_dashboard_setup', 'henkan_add_dashboard_widgets' );
function henkan_add_dashboard_widgets() {
    wp_add_dashboard_widget( 'henkan_dashboard_widget', __( 'Henkan Status', 'henkan' ), 'henkan_dashboard_widget_content' );
}

function henkan_dashboard_widget_content() {
    $stats = henkan_get_stats();
    echo '<div class="henkan-dashboard-widget" style="display:flex; gap:20px; align-items:center;">';
    echo '<div style="text-align:center;"><span class="dashicons dashicons-format-image" style="font-size:32px; height:32px; width:32px; color:#2271b1;"></span></div>';
    echo '<div>';
    echo '<p style="margin:0 0 5px;"><strong>' . esc_html( $stats['percent'] ) . '%</strong> ' . esc_html__( 'Optimiert', 'henkan' ) . '</p>';
    echo '<div style="background:#eee; border-radius:5px; width:150px; height:10px; overflow:hidden;"><div style="background:#46b450; height:100%; width:' . esc_attr( $stats['percent'] ) . '%"></div></div>';
    echo '<p style="margin:5px 0 0; font-size:11px; color:#666;">';
    printf( esc_html__( '%s von %s Bildern optimiert.', 'henkan' ), '<strong>' . intval( $stats['converted'] ) . '</strong>', intval( $stats['total'] ) );
    echo '</p></div></div>';
    echo '<p style="text-align:right; margin-top:10px;"><a href="' . esc_url( admin_url( 'options-general.php?page=henkan-settings' ) ) . '" class="button button-small">' . esc_html__( 'Zum Konverter', 'henkan' ) . '</a></p>';
}

add_action( 'admin_bar_menu', 'henkan_admin_bar_menu', 99 );
function henkan_admin_bar_menu( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $wp_admin_bar->add_node( [ 'id' => 'henkan_menu', 'title' => '<span class="ab-icon dashicons dashicons-format-image"></span> Henkan', 'href' => admin_url( 'options-general.php?page=henkan-settings' ) ] );
    $wp_admin_bar->add_node( [ 'id' => 'henkan_settings', 'title' => __( 'Einstellungen', 'henkan' ), 'parent' => 'henkan_menu', 'href' => admin_url( 'options-general.php?page=henkan-settings' ) ] );
}

function henkan_sanitize_settings( $input ) {
    $keys = [ 'enable_webp', 'enable_avif', 'keep_original', 'picture_filter_enabled', 'enable_lazy_loading', 'debug', 'auto_clear_cache', 'scan_uploads_dir', 'scan_theme_dir' ];
    foreach ( $keys as $key ) { $input[ $key ] = isset( $input[ $key ] ) ? 1 : 0; }

    // SINGLE FORMAT: user picks webp OR avif (or both off).
    if ( ! empty( $input['enable_webp'] ) && ! empty( $input['enable_avif'] ) ) {
        // resolve invalid UI state deterministically (no auto-mode): AVIF checkbox disables WebP
        $input['enable_webp'] = 0;
    }

    $input['quality'] = isset( $input['quality'] ) ? intval( $input['quality'] ) : 82;
    $input['custom_folders'] = isset( $input['custom_folders'] ) ? wp_strip_all_tags( $input['custom_folders'] ) : '';

    // Converter allowlists (NO fallback, only validation)
    $webp_allowed = [ 'cwebp', 'gd' ];
    $avif_allowed = [ 'avifenc', 'imagick', 'gd' ];

    $input['webp_converter'] = isset( $input['webp_converter'] ) ? sanitize_text_field( $input['webp_converter'] ) : 'cwebp';
    $input['avif_converter'] = isset( $input['avif_converter'] ) ? sanitize_text_field( $input['avif_converter'] ) : 'avifenc';

    if ( ! in_array( $input['webp_converter'], $webp_allowed, true ) ) $input['webp_converter'] = 'cwebp';
    if ( ! in_array( $input['avif_converter'], $avif_allowed, true ) ) $input['avif_converter'] = 'avifenc';

    return $input;
}

function henkan_get_stats() {
    global $wpdb;
    $cache_key_total = 'henkan_stats_total_v2';
    $total = wp_cache_get( $cache_key_total, 'henkan' );
    
    if ( false === $total ) {
        // FIX: Zähle ALLE unterstützten Formate
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'image/avif')" );
        wp_cache_set( $cache_key_total, $total, 'henkan', 300 );
    }

    $cache_key_conv = 'henkan_stats_converted_v2';
    $converted = wp_cache_get( $cache_key_conv, 'henkan' );
    
    if ( false === $converted ) {
        // FIX: Zähle konvertierte ODER native WebP/AVIF
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $converted = (int) $wpdb->get_var( "
            SELECT COUNT(ID) FROM {$wpdb->posts} 
            WHERE post_type='attachment' 
            AND (
                post_mime_type IN ('image/webp', 'image/avif')
                OR 
                ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_henkan_converted_files')
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
    $badge_webp = $webp_supported ? '<span class="henkan-badge yes">OK</span>' : '<span class="henkan-badge no">' . esc_html__( 'Fehlt', 'henkan' ) . '</span>';
    $badge_avif = $avif_supported ? '<span class="henkan-badge yes">OK</span>' : '<span class="henkan-badge no">' . esc_html__( 'Fehlt', 'henkan' ) . '</span>';
    ?>
    <div class="wrap henkan-wrap">
        <div class="henkan-header">
            <div class="header-title"><h1><?php esc_html_e( 'Henkan', 'henkan' ); ?> <span class="version">v2.0.1</span></h1></div>
            <div class="header-branding"><span class="dashicons dashicons-format-image" style="font-size:40px; width:40px; height:40px; color:#ccc;"></span></div>
        </div>
        <div class="henkan-grid top-stats">
            <div class="henkan-stat-card"><span class="dashicons dashicons-format-gallery"></span><div class="stat-data"><strong><?php echo esc_html( $stats['total'] ); ?></strong><small><?php esc_html_e( 'DB Bilder', 'henkan' ); ?></small></div></div>
            <div class="henkan-stat-card success"><span class="dashicons dashicons-yes-alt"></span><div class="stat-data"><strong><?php echo esc_html( $stats['converted'] ); ?></strong><small><?php esc_html_e( 'Optimiert', 'henkan' ); ?></small></div></div>
            <div class="henkan-stat-card info"><span class="dashicons dashicons-admin-network"></span><div class="stat-data"><strong><?php echo esc_html( ucfirst( $server_type ) ); ?></strong><small><?php esc_html_e( 'Server', 'henkan' ); ?></small></div></div>
            <div class="henkan-stat-card warning"><span class="dashicons dashicons-update"></span><div class="stat-data"><strong><?php echo $cache_plugin ? esc_html__( 'Aktiv', 'henkan' ) : esc_html__( 'Inaktiv', 'henkan' ); ?></strong><small><?php echo $cache_plugin ? esc_html( $cache_plugin ) : esc_html__( 'Kein Cache', 'henkan' ); ?></small></div></div>
        </div>
        <div class="henkan-grid main-content">
            <div class="henkan-col-left">
                <div class="henkan-card">
                    <h2><?php esc_html_e( 'Einstellungen', 'henkan' ); ?></h2>
                    <form method="post" action="options.php">
                        <?php settings_fields( 'henkan_settings_group' ); ?>
                        <div class="henkan-section">
                            <label class="henkan-toggle"><input type="checkbox" name="henkan_settings[enable_webp]" value="1" <?php checked( 1, $s['enable_webp'] ); ?>><span class="slider"></span><span class="label-text"><?php esc_html_e( 'WebP aktivieren', 'henkan' ); ?> <?php echo wp_kses_post( $badge_webp ); ?></span></label>
                            <label class="henkan-toggle"><input type="checkbox" name="henkan_settings[enable_avif]" value="1" <?php checked( 1, $s['enable_avif'] ); ?>><span class="slider"></span><span class="label-text"><?php esc_html_e( 'AVIF aktivieren', 'henkan' ); ?> <?php echo wp_kses_post( $badge_avif ); ?></span></label>
        <div class="henkan-section" style="margin-top:10px;">
            <div style="display:grid; grid-template-columns: 180px 1fr; gap:10px; align-items:center;">
                <label style="font-weight:600;"><?php esc_html_e( 'WebP Converter', 'henkan' ); ?></label>
                <select name="henkan_settings[webp_converter]">
                    <option value="cwebp" <?php selected( 'cwebp', $s['webp_converter'] ?? 'cwebp' ); ?>>cwebp (exec)</option>
                    <option value="gd" <?php selected( 'gd', $s['webp_converter'] ?? 'cwebp' ); ?>>GD (imagewebp)</option>
                </select>

                <label style="font-weight:600;"><?php esc_html_e( 'AVIF Converter', 'henkan' ); ?></label>
                <select name="henkan_settings[avif_converter]">
                    <option value="avifenc" <?php selected( 'avifenc', $s['avif_converter'] ?? 'avifenc' ); ?>>avifenc (exec)</option>
                    <option value="imagick" <?php selected( 'imagick', $s['avif_converter'] ?? 'avifenc' ); ?>>ImageMagick (exec)</option>
                    <option value="gd" <?php selected( 'gd', $s['avif_converter'] ?? 'avifenc' ); ?>>GD (imageavif)</option>
                </select>
            </div>
            <p style="margin:8px 0 0; color:#666; font-size:12px;">
                <?php esc_html_e( 'Der gewählte Converter wird strikt verwendet. Kein Auto-Fallback.', 'henkan' ); ?>
            </p>
        </div>

                        </div>
                        <div class="henkan-section">
                            <label><?php esc_html_e( 'Qualität (1-100)', 'henkan' ); ?></label>
                            <input type="number" name="henkan_settings[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" min="1" max="100" class="small-text">
                        </div>
                        <div class="henkan-section">
                            <strong style="display:block; margin-bottom:10px;"><?php esc_html_e( 'Ordner scannen', 'henkan' ); ?></strong>
                            <label class="henkan-check-box-card"><input type="checkbox" name="henkan_settings[scan_uploads_dir]" value="1" <?php checked( 1, $s['scan_uploads_dir'] ); ?>><div><strong><?php esc_html_e( 'Uploads Verzeichnis', 'henkan' ); ?></strong><code><?php echo esc_html( $uploads_path ); ?></code></div></label>
                            <label class="henkan-check-box-card"><input type="checkbox" name="henkan_settings[scan_theme_dir]" value="1" <?php checked( 1, $s['scan_theme_dir'] ); ?>><div><strong><?php esc_html_e( 'Theme Verzeichnis', 'henkan' ); ?></strong><code><?php echo esc_html( $theme_path ); ?></code></div></label>
                            <label style="display:block; margin-top:10px; font-size:0.9em;"><?php esc_html_e( 'Weitere Pfade:', 'henkan' ); ?></label>
                            <textarea name="henkan_settings[custom_folders]" class="widefat code" rows="2" placeholder="/var/www/html/extra"><?php echo esc_textarea( $s['custom_folders'] ); ?></textarea>
                        </div>
                        <div class="henkan-section">
                            <strong style="display:block; margin-bottom:10px;"><?php esc_html_e( 'Frontend', 'henkan' ); ?></strong>
                            <label class="henkan-check"><input type="checkbox" name="henkan_settings[picture_filter_enabled]" value="1" <?php checked( 1, $s['picture_filter_enabled'] ); ?>> <?php esc_html_e( 'Frontend <picture>', 'henkan' ); ?></label>
                            <label class="henkan-check"><input type="checkbox" name="henkan_settings[enable_lazy_loading]" value="1" <?php checked( 1, $s['enable_lazy_loading'] ); ?>> <?php esc_html_e( 'Native Lazy-Loading', 'henkan' ); ?></label>
                            <label class="henkan-check"><input type="checkbox" name="henkan_settings[auto_clear_cache]" value="1" <?php checked( 1, $s['auto_clear_cache'] ); ?>> <?php esc_html_e( 'Auto-Cache-Clear', 'henkan' ); ?></label>
                        </div>
                        <div class="henkan-section">
                            <label class="henkan-check"><input type="checkbox" name="henkan_settings[keep_original]" value="1" <?php checked( 1, $s['keep_original'] ); ?>> <?php esc_html_e( 'Originale behalten', 'henkan' ); ?></label>
                            <label class="henkan-check"><input type="checkbox" name="henkan_settings[debug]" value="1" <?php checked( 1, $s['debug'] ); ?>> <?php esc_html_e( 'Debug Logging', 'henkan' ); ?></label>
                        </div>
                        <?php submit_button( __( 'Speichern', 'henkan' ) ); ?>
                    </form>
                </div>
                <div class="henkan-card">
                    <h2><?php esc_html_e( 'Server Rewrites', 'henkan' ); ?></h2>
                    <div class="henkan-tabs">
                        <button type="button" class="henkan-tab-btn <?php echo ! $is_nginx ? 'active' : ''; ?>" data-target="apache">Apache</button>
                        <button type="button" class="henkan-tab-btn <?php echo $is_nginx ? 'active' : ''; ?>" data-target="nginx">Nginx</button>
                    </div>
                    <div id="tab-apache" class="henkan-tab-content" style="display: <?php echo ! $is_nginx ? 'block' : 'none'; ?>;">
<textarea class="henkan-code" readonly onclick="this.select()">
&lt;IfModule mod_rewrite.c&gt;
  RewriteEngine On
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
map $http_accept $webp_avif_suffix {
    default "";
    "~*avif" ".avif";
    "~*webp" ".webp";
}
</textarea>
<textarea class="henkan-code small" readonly onclick="this.select()">
location ~* ^.+\.(png|jpe?g)$ {
    add_header Vary Accept;
    try_files $uri$webp_avif_suffix $uri =404;
}
</textarea>
                    </div>
                </div>
                <div class="henkan-card">
                    <h2><?php esc_html_e( 'WP-CLI Befehle', 'henkan' ); ?></h2>
                    <div class="henkan-cli-box">
                        <p><strong><?php esc_html_e( 'Status:', 'henkan' ); ?></strong><br><code>wp henkan scan</code></p>
                        <p><strong><?php esc_html_e( 'Konvertieren (fehlende):', 'henkan' ); ?></strong><br><code>wp henkan convert</code></p>
                        <p><strong><?php esc_html_e( 'Alles erzwingen:', 'henkan' ); ?></strong><br><code>wp henkan convert --force</code></p>
                    </div>
                </div>
            </div>
            <div class="henkan-col-right">
                <div class="henkan-card bulk-card">
                    <h2><?php esc_html_e( 'Bulk Konvertierung', 'henkan' ); ?></h2>
                    <div class="henkan-progress-circle" data-percent="<?php echo esc_attr( $stats['percent'] ); ?>">
                        <svg viewBox="0 0 36 36">
                            <path class="bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="meter" stroke-dasharray="<?php echo esc_attr( $stats['percent'] ); ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                        <div class="percent-text"><?php echo esc_html( $stats['percent'] ); ?>%</div>
                    </div>
                    <div class="henkan-bulk-opts">
                        <label><input type="checkbox" id="henkan_bulk_only_unconverted" <?php checked( 1, $s['bulk_only_unconverted'] ); ?>> <?php esc_html_e( 'Nur fehlende (File-Check)', 'henkan' ); ?></label><br>
        <label><input type="checkbox" id="henkan_bulk_only_failed"> <?php esc_html_e( 'Nur fehlgeschlagene', 'henkan' ); ?></label><br>

                        <label><input type="checkbox" id="henkan_bulk_rescan_all"> <?php esc_html_e( 'Erzwingen', 'henkan' ); ?></label>
                    </div>
                    <button id="henkan_start_scan" class="button button-primary full-width"><?php esc_html_e( 'Scan starten', 'henkan' ); ?></button>
                    <div id="henkan_scan_results" style="display:none; margin-top:15px; text-align:center;">
                        <p><?php esc_html_e( 'Gefunden:', 'henkan' ); ?> <strong id="henkan_total_found">0</strong><br><?php esc_html_e( 'Zu tun:', 'henkan' ); ?> <strong id="henkan_to_convert">0</strong></p>
                        <button id="henkan_start_convert" class="button button-hero full-width" style="background:#46b450; color:#fff;"><?php esc_html_e( 'Optimierung Starten', 'henkan' ); ?></button>
        <button id="henkan_resume_convert" class="button button-secondary full-width" style="display:none; margin-top:10px;">
            <?php esc_html_e( 'Fortsetzen (Resume)', 'henkan' ); ?>
        </button>

                    </div>
                    <div id="henkan_progress_ui" style="display:none; margin-top:15px;">
                        <div class="henkan-progress-bar"><div class="fill" style="width:0%"></div></div>
                        <p id="henkan_status_text" style="text-align:center; font-size:11px;"><?php esc_html_e( 'Initialisiere...', 'henkan' ); ?></p>
                    </div>
                    <ul id="henkan_log_list" class="henkan-log-box"></ul>
                </div>
            </div>
        </div>
    </div>
    <?php
}
