<?php
defined( 'ABSPATH' ) || exit;

/**
 * Media offloading (Advanced Media Offloader and similar plugins).
 *
 * Offload plugins copy an attachment's files to S3-compatible storage and rewrite its URLs to the
 * bucket/CDN. Server rewrite rules cannot run there, so Henkan's files have to be offloaded too
 * and delivered with <picture>.
 *
 * How it works:
 * - Henkan lists its WebP/AVIF/JXL files in the attachment metadata under "sources" (the format
 *   of the Modern Image Formats plugin). Advanced Media Offloader uploads every file listed there
 *   with the attachment (upload, bulk offload, WP-CLI) and deletes them from the bucket again
 *   when the attachment is deleted. No change in the offload plugin is needed.
 * - Every entry written by Henkan carries 'henkan' => 1, so Henkan only ever replaces or removes
 *   its own entries.
 * - New uploads are converted before the offload plugin uploads them (Henkan runs on
 *   wp_generate_attachment_metadata priority 10, Advanced Media Offloader on 99).
 * - Attachments that are already offloaded are not converted again: their new files would not be
 *   in the bucket. Convert first, then offload.
 * - <picture> for offloaded images: the existence of a converted file is read from the Henkan
 *   data (the local files may be gone), the URL is the bucket/CDN URL with the new extension.
 */

/** True if a supported offload plugin is active. */
function henkan_offloader_active() {
    /**
     * Filters whether a media offload plugin is active.
     *
     * @param bool $active Default: Advanced Media Offloader is loaded.
     */
    return (bool) apply_filters( 'henkan_offloader_active', defined( 'ADVMO_VERSION' ) );
}

/** True if the attachment's files were copied to cloud storage by the offload plugin. */
function henkan_is_offloaded( $id ) {
    $id = (int) $id;
    $offloaded = $id > 0 && henkan_offloader_active() && (bool) get_post_meta( $id, 'advmo_offloaded', true );
    /**
     * Filters whether an attachment is offloaded to cloud storage.
     *
     * @param bool $offloaded
     * @param int  $id Attachment ID.
     */
    return (bool) apply_filters( 'henkan_is_offloaded', $offloaded, $id );
}

/**
 * True if Henkan writes its files into the "sources" metadata. Off while the Modern Image Formats
 * plugin is active: it manages the same keys itself.
 */
function henkan_sources_enabled() {
    $mif = defined( 'WEBP_UPLOADS_VERSION' ) || function_exists( 'webp_uploads_get_upload_image_mime_transforms' );
    /**
     * Filters whether Henkan lists its converted files in the attachment metadata ("sources").
     *
     * @param bool $enabled
     */
    return (bool) apply_filters( 'henkan_write_sources', ! $mif );
}

/** Removes the "sources" entries written by Henkan; entries of other plugins are kept. */
function henkan_strip_sources( $metadata ) {
    if ( ! is_array( $metadata ) ) return $metadata;
    $strip = static function ( $sources ) {
        if ( ! is_array( $sources ) ) return $sources;
        foreach ( $sources as $mime => $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['henkan'] ) ) unset( $sources[ $mime ] );
        }
        return $sources;
    };
    if ( isset( $metadata['sources'] ) ) {
        $metadata['sources'] = $strip( $metadata['sources'] );
        if ( empty( $metadata['sources'] ) ) unset( $metadata['sources'] );
    }
    if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
        foreach ( $metadata['sizes'] as $size => $data ) {
            if ( ! is_array( $data ) || ! isset( $data['sources'] ) ) continue;
            $metadata['sizes'][ $size ]['sources'] = $strip( $data['sources'] );
            if ( empty( $metadata['sizes'][ $size ]['sources'] ) ) unset( $metadata['sizes'][ $size ]['sources'] );
        }
    }
    return $metadata;
}

/**
 * Lists the converted files of an attachment in its metadata ("sources"): the main file at the
 * root, each image size in its own entry. The file that already is the main file / size file
 * (Keep Originals off) is not listed twice.
 *
 * @param array $metadata Attachment metadata.
 * @param int   $id       Attachment ID.
 * @return array Metadata with Henkan's current entries.
 */
function henkan_apply_sources( $metadata, $id ) {
    if ( ! is_array( $metadata ) || ! henkan_sources_enabled() ) return $metadata;
    $metadata = henkan_strip_sources( $metadata );

    $data = henkan_get_data( (int) $id );
    if ( ! is_array( $data ) ) return $metadata;

    $file = get_attached_file( (int) $id );
    $dir  = $file ? dirname( $file ) : '';

    foreach ( $data as $size => $formats ) {
        if ( ! is_array( $formats ) ) continue;
        if ( $size === 'original' ) {
            $current = isset( $metadata['file'] ) ? basename( (string) $metadata['file'] ) : '';
        } elseif ( isset( $metadata['sizes'][ $size ] ) && is_array( $metadata['sizes'][ $size ] ) ) {
            $current = isset( $metadata['sizes'][ $size ]['file'] ) ? basename( (string) $metadata['sizes'][ $size ]['file'] ) : '';
        } else {
            continue; // size no longer exists (or "custom")
        }

        foreach ( [ 'webp', 'avif', 'jxl' ] as $fmt ) {
            if ( empty( $formats[ $fmt ] ) || ! is_string( $formats[ $fmt ] ) ) continue;
            $name = basename( $formats[ $fmt ] );
            if ( $name === '' || $name === $current ) continue;

            $entry = [ 'file' => $name, 'henkan' => 1 ];
            if ( $dir !== '' && file_exists( $dir . '/' . $name ) ) $entry['filesize'] = (int) filesize( $dir . '/' . $name );

            if ( $size === 'original' ) {
                $metadata['sources'][ henkan_target_mime( $fmt ) ] = $entry;
            } else {
                $metadata['sizes'][ $size ]['sources'][ henkan_target_mime( $fmt ) ] = $entry;
            }
        }
    }
    return $metadata;
}

/** Writes the current "sources" entries of an attachment to its stored metadata. */
function henkan_sync_sources( $id ) {
    $id   = (int) $id;
    $meta = wp_get_attachment_metadata( $id );
    if ( ! is_array( $meta ) ) return;
    $new = henkan_sources_meta( $meta, $id );
    if ( $new !== $meta ) wp_update_attachment_metadata( $id, $new );
}

/** Applies Henkan's "sources" entries, or strips them when writing them is turned off. */
function henkan_sources_meta( $meta, $id ) {
    return henkan_sources_enabled() ? henkan_apply_sources( $meta, $id ) : henkan_strip_sources( $meta );
}

/**
 * Converted file names of an offloaded attachment, for <picture>. Only files listed in the
 * "sources" metadata count: those were uploaded together with the image. Images that were
 * offloaded before Henkan listed its files have none, and keep getting the JPEG/PNG instead of a
 * broken AVIF/WebP link.
 *
 * @return array<string,true> File name => true.
 */
function henkan_converted_names( $id ) {
    static $cache = [];
    $id = (int) $id;
    if ( isset( $cache[ $id ] ) ) return $cache[ $id ];
    $names = [];
    $meta  = wp_get_attachment_metadata( $id );
    if ( is_array( $meta ) ) {
        $lists = [ $meta['sources'] ?? [] ];
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
            if ( is_array( $size ) ) $lists[] = $size['sources'] ?? [];
        }
        foreach ( $lists as $sources ) {
            if ( ! is_array( $sources ) ) continue;
            foreach ( $sources as $entry ) {
                if ( is_array( $entry ) && ! empty( $entry['henkan'] ) && ! empty( $entry['file'] ) ) {
                    $names[ basename( (string) $entry['file'] ) ] = true;
                }
            }
        }
        // Keep Originals off: the main file / size files themselves are converted files
        if ( ! empty( $meta['file'] ) ) $names[ basename( (string) $meta['file'] ) ] = true;
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
            if ( is_array( $size ) && ! empty( $size['file'] ) ) $names[ basename( (string) $size['file'] ) ] = true;
        }
    }
    return $cache[ $id ] = $names;
}

/**
 * One-time background job after updating to 2.6.3: lists the files of images converted by older
 * versions in their metadata, so a later (bulk) offload takes them along. Images that are already
 * offloaded are skipped: their converted files are not in the bucket yet.
 */
add_action( 'henkan_sources_backfill_event', 'henkan_sources_backfill_batch' );
function henkan_sources_backfill_batch() {
    global $wpdb;
    $state = get_option( 'henkan_sources_backfill', [ 'last_id' => 0, 'updated' => 0 ] );
    if ( ! is_array( $state ) || ! empty( $state['done'] ) ) return;
    if ( ! henkan_sources_enabled() ) return; // retried on a later request once Modern Image Formats is gone
    $table = $wpdb->prefix . 'henkan_data';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, keyset paging, one-time background job
    $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT attachment_id FROM %i WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT %d', $table, (int) $state['last_id'], 200 ) );
    foreach ( $ids as $id ) {
        $id = (int) $id;
        $state['last_id'] = $id;
        if ( henkan_is_offloaded( $id ) ) continue;
        $before = wp_get_attachment_metadata( $id );
        henkan_sync_sources( $id );
        if ( wp_get_attachment_metadata( $id ) !== $before ) $state['updated']++;
    }
    if ( count( $ids ) === 200 ) {
        wp_schedule_single_event( time() + 10, 'henkan_sources_backfill_event' );
    } else {
        $state['done'] = time();
    }
    update_option( 'henkan_sources_backfill', $state, false );
}

/** Admin notice on the Henkan page when an offload plugin is active. */
function henkan_offload_admin_notice() {
    if ( ! henkan_offloader_active() ) return;
    $s = henkan_get_settings();
    $class = empty( $s['picture_filter_enabled'] ) ? 'notice-warning' : 'notice-info';
    echo '<div class="notice ' . esc_attr( $class ) . '"><p><strong>' . esc_html__( 'Advanced Media Offloader detected.', 'henkan-webp-avif-converter' ) . '</strong> ';
    echo esc_html__( 'Converted files are offloaded together with the image. Server rewrite rules do not work for offloaded images, so they are delivered with <picture>.', 'henkan-webp-avif-converter' ) . ' ';
    if ( empty( $s['picture_filter_enabled'] ) ) {
        echo '<strong>' . esc_html__( 'Enable "Frontend <picture>" in Advanced, otherwise visitors only get the JPEG/PNG.', 'henkan-webp-avif-converter' ) . '</strong> ';
    }
    echo esc_html__( 'Images that are already offloaded are not converted: convert first, then offload.', 'henkan-webp-avif-converter' );
    if ( ! henkan_sources_enabled() ) {
        echo ' ' . esc_html__( 'Modern Image Formats is active as well, so Henkan does not list its files for the offload plugin. Use only one of the two.', 'henkan-webp-avif-converter' );
    }
    echo '</p></div>';
}
