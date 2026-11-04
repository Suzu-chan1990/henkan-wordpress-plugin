<?php
/**
 * Henkan uninstall: removes the plugin's own table, options, post meta and cron events.
 * Converted image files (WebP/AVIF/JXL) are NOT deleted: posts may link to them, and with
 * "Keep original" off they are the only copy of an image.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function henkan_uninstall_site() {
    global $wpdb;

    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}henkan_data" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    foreach ( [
        'henkan_settings', 'henkan_db_version', 'henkan_db_reset_v211', 'henkan_background_queue',
        'henkan_background_worker_lock', 'henkan_integrity_lock', 'henkan_integrity_last_run',
        'henkan_integrity_missing_count', 'henkan_metadata_repair',
    ] as $opt ) {
        delete_option( $opt );
    }
    // Per-file conversion locks (henkan_lock_<md5>)
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'henkan\\_lock\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

    delete_post_meta_by_key( '_henkan_state' );
    delete_post_meta_by_key( 'henkan_last_error' );

    foreach ( [ 'henkan_integrity_check_event', 'henkan_background_worker_event', 'henkan_repair_metadata_event' ] as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $henkan_site_id ) {
        switch_to_blog( $henkan_site_id );
        henkan_uninstall_site();
        restore_current_blog();
    }
} else {
    henkan_uninstall_site();
}
