<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if (!defined('WP_CLI') | !WP_CLI) return;

class Henkan_CLI {

    public function scan($args, $assoc_args) {
        $settings = henkan_get_settings();
        $only_missing = $settings['bulk_only_unconverted'];
        WP_CLI::line( __( 'Starte Scan...', 'henkan-webp-avif-converter' ) );
        $items = $this->get_items_to_process(!$only_missing, -1, 0);
        /* translators: %d: Number of images found */
        WP_CLI::success( sprintf( __( 'Gefunden: %d Bilder.', 'henkan-webp-avif-converter' ), count($items) ) );
    }

    public function convert($args, $assoc_args) {
        $force = \WP_CLI\Utils\get_flag_value($assoc_args, 'force', false);
        $limit = \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', -1);
        $offset = \WP_CLI\Utils\get_flag_value($assoc_args, 'offset', 0);

        $settings = henkan_get_settings();
        $process_all = $force ? true : !$settings['bulk_only_unconverted'];

        $items = $this->get_items_to_process($process_all, $limit, $offset);
        $total = count($items);

        if ($total === 0) {
            WP_CLI::success( __( 'Keine Bilder zu verarbeiten.', 'henkan-webp-avif-converter' ) );
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar( __( 'Optimiere (cwebp)', 'henkan-webp-avif-converter' ), $total );
        
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
            WP_CLI::line( __( 'Cache geleert.', 'henkan-webp-avif-converter' ) );
        }
        WP_CLI::success( __( 'Fertig.', 'henkan-webp-avif-converter' ) );
    }

    private function get_items_to_process($process_all = false, $limit = -1, $offset = 0) {
        $settings = henkan_get_settings();
        $todo = [];
        
        $ids = get_posts([
            'post_type' => 'attachment', 
            'post_mime_type' => ['image/jpeg', 'image/png'], 
            'posts_per_page' => $limit, 
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC'
        ]);
        
        foreach ($ids as $id) {
            if ($process_all) {
                $todo[] = $id; 
                continue;
            }
            if (!get_post_meta($id, '_henkan_converted_files', true)) {
                $todo[] = $id;
            }
        }
        return $todo;
    }
}
WP_CLI::add_command('henkan', 'Henkan_CLI');
