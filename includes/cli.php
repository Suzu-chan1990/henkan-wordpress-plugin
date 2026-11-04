<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

class Henkan_CLI {

    public function scan($args, $assoc_args) {
        $settings = henkan_get_settings();
        $only_missing = $settings['bulk_only_unconverted'];
        WP_CLI::line( __( 'Starting scan...', 'henkan-webp-avif-converter' ) );
        $items = $this->get_items_to_process(!$only_missing, -1, 0);
        /* translators: %d: Number of images found */
        WP_CLI::success( sprintf( __( 'Found: %d images.', 'henkan-webp-avif-converter' ), count($items) ) );
    }

    public function convert($args, $assoc_args) {
        $force = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $limit = \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 100);
        $after_id = \WP_CLI\Utils\get_flag_value($assoc_args, 'after-id', 0);

        $settings = henkan_get_settings();
        $process_all = $force ? true : !$settings['bulk_only_unconverted'];

        $items = $this->get_items_to_process($process_all, $limit, $after_id);
        $total = count($items);

        if ($total === 0) {
            WP_CLI::success( __( 'No images to process.', 'henkan-webp-avif-converter' ) );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( __( 'Processing images...', 'henkan-webp-avif-converter' ), $total );
        
        foreach ($items as $item) {
            if (is_numeric($item)) {
                $id = intval($item);
                $file = get_attached_file($id);
                if ($file && file_exists($file)) {
                    henkan_convert_file($file, $id, 'original');
                    $meta = wp_get_attachment_metadata($id);
                    if (!empty($meta['sizes'])) {
                        $base = dirname($file);
                        foreach ($meta['sizes'] as $size => $data) {
                            henkan_convert_file($base . '/' . $data['file'], $id, $size);
                        }
                    }
                }
            } else if (file_exists($item)) {
                henkan_convert_file($item, 0, 'custom');
            }
            $progress->tick();
        }
        $progress->finish();
        
        if(!empty($settings['auto_clear_cache'])) {
            henkan_trigger_cache_clear();
            WP_CLI::line( __( 'Cache cleared.', 'henkan-webp-avif-converter' ) );
        }
        WP_CLI::success( __( 'Done.', 'henkan-webp-avif-converter' ) );
    }

    /**
     * Repairs thumbnails broken by bulk conversions of older versions ("Keep original" off).
     *
     * ## EXAMPLES
     *     wp henkan repair
     */
    public function repair($args, $assoc_args) {
        global $wpdb;
        $last = 0; $fixed = 0; $checked = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments (CLI)
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type='attachment' AND post_mime_type IN ('image/webp','image/avif','image/jxl') ORDER BY ID ASC LIMIT 500", $last));
            foreach ($ids as $id) { $last = (int) $id; $checked++; if (henkan_repair_attachment_metadata($last)) $fixed++; }
        } while (count($ids) === 500);
        /* translators: 1: Number of repaired attachments, 2: Number of checked attachments */
        WP_CLI::success( sprintf( __( 'Repaired %1$d of %2$d converted attachments.', 'henkan-webp-avif-converter' ), $fixed, $checked ) );
    }

    /**
     * @param int $limit Max. attachments; -1 = all (used by "scan", which counts everything).
     */
    private function get_items_to_process($process_all = false, $limit = 100, $after_id = 0) {
        $todo = []; $after_id = max(0, (int)$after_id);
        $all = ((int) $limit) < 0;
        $limit = $all ? 500 : min(500, max(1, (int)$limit));
        global $wpdb;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments (CLI)
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') AND post_status='inherit' ORDER BY ID ASC LIMIT %d",$after_id,$limit));
            foreach($ids as $id){ $id=(int)$id; $after_id=$id; if($process_all || !henkan_get_data($id)) $todo[]=$id; }
        } while ($all && count($ids) === $limit);
        return $todo;
    }
}
WP_CLI::add_command('henkan', 'Henkan_CLI');
