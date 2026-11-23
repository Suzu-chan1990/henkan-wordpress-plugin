<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

/**
 * Henkan image conversion from the command line.
 */
class Henkan_CLI {

    /**
     * Counts the media-library images that still need converting.
     *
     * ## EXAMPLES
     *     wp henkan scan
     */
    public function scan($args, $assoc_args) {
        $settings = henkan_get_settings();
        $only_missing = $settings['bulk_only_unconverted'];
        WP_CLI::line( __( 'Starting scan...', 'henkan-webp-avif-converter' ) );
        $items = $this->get_items_to_process(!$only_missing, -1, 0);
        /* translators: %d: Number of images found */
        WP_CLI::success( sprintf( __( 'Found: %d images.', 'henkan-webp-avif-converter' ), count($items) ) );
    }

    /**
     * Converts media-library images.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Re-encode images that are already converted (needs the original JPEG/PNG).
     *
     * [--limit=<number>]
     * : Max. number of images (default 100, -1 = all).
     *
     * [--after-id=<id>]
     * : Start after this attachment ID.
     *
     * [--ids=<ids>]
     * : Comma-separated attachment IDs; ignores --limit and --after-id.
     *
     * [--format=<format>]
     * : Only create this format (webp, avif or jxl), even if it is not enabled in the settings.
     *
     * [--quality=<quality>]
     * : Quality for this run (1-100); re-encodes like --force.
     *
     * [--dry-run]
     * : Only list the images that would be converted.
     *
     * ## EXAMPLES
     *     wp henkan convert
     *     wp henkan convert --limit=-1 --force
     *     wp henkan convert --ids=12,34 --format=avif
     */
    public function convert($args, $assoc_args) {
        $force    = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $limit    = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 100);
        $after_id = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'after-id', 0);
        $dry_run  = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $quality  = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'quality', 0);
        $format   = strtolower((string) \WP_CLI\Utils\get_flag_value($assoc_args, 'format', ''));

        if ($quality < 0 || $quality > 100) WP_CLI::error( __( '--quality must be 1-100.', 'henkan-webp-avif-converter' ) );
        if ($format !== '') {
            if (!in_array($format, ['webp', 'avif', 'jxl'], true)) WP_CLI::error( __( '--format must be webp, avif or jxl.', 'henkan-webp-avif-converter' ) );
            // Only this format for this run
            add_filter('henkan_settings', function ($s) use ($format) {
                foreach (['webp', 'avif', 'jxl'] as $f) $s['enable_' . $f] = ($f === $format) ? 1 : 0;
                return $s;
            });
        }

        $settings = henkan_get_settings();
        $process_all = ($force || $quality > 0) ? true : !$settings['bulk_only_unconverted'];

        $ids = $this->parse_ids($assoc_args);
        $items = $ids !== null ? $ids : $this->get_items_to_process($process_all, $limit, $after_id);
        $total = count($items);

        if ($total === 0) {
            WP_CLI::success( __( 'No images to process.', 'henkan-webp-avif-converter' ) );
            return;
        }

        if ($dry_run) {
            foreach ($items as $id) WP_CLI::line($id . "\t" . (string) get_attached_file($id));
            /* translators: %d: Number of images */
            WP_CLI::success( sprintf( __( 'Dry run: %d images would be converted.', 'henkan-webp-avif-converter' ), $total ) );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( __( 'Processing images...', 'henkan-webp-avif-converter' ), $total );
        $ok = 0; $skipped = 0; $failed = 0;
        foreach ($items as $id) {
            $res = henkan_convert_attachment($id, ['force' => $force, 'quality' => $quality]);
            if (!empty($res['success'])) $ok++;
            elseif (!empty($res['skipped']) && empty($res['failed'])) $skipped++;
            else { $failed++; WP_CLI::debug('ID ' . $id . ': ' . implode(', ', $res['failed']), 'henkan'); }
            $progress->tick();
        }
        $progress->finish();

        if(!empty($settings['auto_clear_cache'])) {
            henkan_trigger_cache_clear();
            WP_CLI::line( __( 'Cache cleared.', 'henkan-webp-avif-converter' ) );
        }
        /* translators: 1: Converted, 2: Kept as original, 3: Failed */
        $summary = sprintf( __( 'Done. Converted: %1$d, original kept (not smaller): %2$d, failed: %3$d.', 'henkan-webp-avif-converter' ), $ok, $skipped, $failed );
        $failed ? WP_CLI::warning($summary . ' ' . __( 'Run with --debug=henkan for details.', 'henkan-webp-avif-converter' )) : WP_CLI::success($summary);
    }

    /**
     * Deletes converted WebP/AVIF/JXL files (only where the original JPEG/PNG still exists).
     *
     * ## OPTIONS
     *
     * [--ids=<ids>]
     * : Comma-separated attachment IDs.
     *
     * [--all]
     * : Revert every converted media-library image.
     *
     * [--yes]
     * : Do not ask for confirmation with --all.
     *
     * [--dry-run]
     * : Only list what would be reverted.
     *
     * ## EXAMPLES
     *     wp henkan revert --ids=12,34
     *     wp henkan revert --all --yes
     */
    public function revert($args, $assoc_args) {
        $all     = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'all', false);
        $dry_run = (bool) \WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
        $ids     = $this->parse_ids($assoc_args);
        if ($ids === null && !$all) WP_CLI::error( __( 'Use --ids=<ids> or --all.', 'henkan-webp-avif-converter' ) );

        if ($ids === null) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table (CLI)
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT attachment_id FROM %i ORDER BY attachment_id ASC', $wpdb->prefix . 'henkan_data')));
        }
        if (empty($ids)) { WP_CLI::success( __( 'Nothing to revert.', 'henkan-webp-avif-converter' ) ); return; }

        if ($dry_run) {
            foreach ($ids as $id) WP_CLI::line($id . "\t" . (henkan_attachment_has_original($id) ? 'revert' : 'skip (original deleted)'));
            return;
        }
        if ($all) {
            /* translators: %d: Number of images */
            WP_CLI::confirm( sprintf( __( 'Delete the converted files of %d images?', 'henkan-webp-avif-converter' ), count($ids) ), $assoc_args );
        }

        $done = 0; $skip = 0; $files = 0;
        $progress = \WP_CLI\Utils\make_progress_bar( __( 'Reverting...', 'henkan-webp-avif-converter' ), count($ids) );
        foreach ($ids as $id) {
            $r = henkan_revert_attachment($id);
            if ($r['ok']) { $done++; $files += $r['removed']; } else { $skip++; }
            $progress->tick();
        }
        $progress->finish();
        if (!empty(henkan_get_settings()['auto_clear_cache'])) henkan_trigger_cache_clear();
        /* translators: 1: Reverted images, 2: Deleted files, 3: Skipped images */
        WP_CLI::success( sprintf( __( 'Reverted %1$d images (%2$d files deleted), skipped %3$d (original deleted).', 'henkan-webp-avif-converter' ), $done, $files, $skip ) );
    }

    /**
     * Shows conversion statistics.
     *
     * ## OPTIONS
     *
     * [--recalculate]
     * : Measure the saved space from the files on disk and store the result.
     *
     * ## EXAMPLES
     *     wp henkan stats
     */
    public function stats($args, $assoc_args) {
        global $wpdb;
        $table = $wpdb->prefix . 'henkan_data';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table (CLI)
        $converted = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- count by post meta (CLI)
        $skipped = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_henkan_state', 'skipped'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- count by post meta (CLI)
        $failed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", '_henkan_state', 'failed'));

        if (\WP_CLI\Utils\get_flag_value($assoc_args, 'recalculate', false)) {
            update_option('henkan_total_savings_bytes', $this->measure_savings(), false);
        }

        $rows = [
            ['key' => __( 'Converted images', 'henkan-webp-avif-converter' ), 'value' => $converted],
            ['key' => __( 'Original kept (not smaller)', 'henkan-webp-avif-converter' ), 'value' => $skipped],
            ['key' => __( 'Failed', 'henkan-webp-avif-converter' ), 'value' => $failed],
            ['key' => __( 'Space saved', 'henkan-webp-avif-converter' ), 'value' => size_format((int) get_option('henkan_total_savings_bytes', 0)) ?: '0 B'],
        ];
        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
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

    /** @return int[]|null IDs from --ids, or null if not given. */
    private function parse_ids($assoc_args) {
        $raw = \WP_CLI\Utils\get_flag_value($assoc_args, 'ids', null);
        if ($raw === null || $raw === '') return null;
        return array_values(array_unique(array_filter(array_map('absint', explode(',', (string) $raw)))));
    }

    /**
     * Saved bytes measured on disk: per source file (original and sizes), original size minus
     * the smallest converted file next to it.
     */
    private function measure_savings() {
        global $wpdb;
        $table = $wpdb->prefix . 'henkan_data';
        $total = 0; $last = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, keyset paging (CLI)
            $ids = $wpdb->get_col($wpdb->prepare('SELECT attachment_id FROM %i WHERE attachment_id > %d ORDER BY attachment_id ASC LIMIT 500', $table, $last));
            foreach ($ids as $id) {
                $last = (int) $id;
                $file = get_attached_file($last);
                if (!$file) continue;
                $dir = dirname($file);
                $sources = [basename($file)];
                $meta = wp_get_attachment_metadata($last);
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $d) if (!empty($d['file'])) $sources[] = basename($d['file']);
                }
                foreach (array_unique($sources) as $name) {
                    if (!preg_match('/\.(jpe?g|png)$/i', $name) || !file_exists($dir . '/' . $name)) continue;
                    $stem = $dir . '/' . pathinfo($name, PATHINFO_FILENAME);
                    $conv = [];
                    foreach (['webp', 'avif', 'jxl'] as $f) if (file_exists($stem . '.' . $f)) $conv[] = (int) filesize($stem . '.' . $f);
                    if ($conv) $total += max(0, (int) filesize($dir . '/' . $name) - min($conv));
                }
            }
        } while (count($ids) === 500);
        return $total;
    }

    /**
     * @param int $limit Max. attachments; -1 = all (used by "scan", which counts everything).
     */
    private function get_items_to_process($process_all = false, $limit = 100, $after_id = 0) {
        $todo = []; $after_id = max(0, (int)$after_id);
        $all = ((int) $limit) < 0;
        $batch = $all ? 500 : min(500, max(1, (int)$limit));
        $settings = henkan_get_settings();
        global $wpdb;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- keyset paging over attachments (CLI)
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png') AND post_status='inherit' ORDER BY ID ASC LIMIT %d",$after_id,$batch));
            foreach($ids as $id){
                $id=(int)$id; $after_id=$id;
                $file = get_attached_file($id);
                if ($file && henkan_is_file_excluded($file)) continue;
                // Same file check as the bulk optimizer (missing formats, ignoring "not smaller" skips)
                if($process_all || henkan_attachment_needs_conversion($id, $settings)) $todo[]=$id;
            }
        } while (($all || count($todo) < (int) $limit) && count($ids) === $batch);
        return $all ? $todo : array_slice($todo, 0, max(1, (int) $limit));
    }
}
WP_CLI::add_command('henkan', 'Henkan_CLI');
