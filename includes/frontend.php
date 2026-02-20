<?php
defined('ABSPATH') || exit;
add_filter('the_content', 'henkan_picture_replace', 99);
function henkan_picture_replace($content) {
    $settings = henkan_get_settings();
    if (empty($settings['picture_filter_enabled'])) return $content;
    return preg_replace_callback('/<img[^>]+>/', 'henkan_img_callback', $content);
}
function henkan_img_callback($matches) {
    $img = $matches[0];
    if (strpos($img, 'data-no-optimize') !== false) return $img;
    if (!preg_match('/src="([^"]+)"/', $img, $src_m)) return $img;
    $src = $src_m[1];
    $id = attachment_url_to_postid($src);
    if (!$id) return $img;
    
    $settings = henkan_get_settings();
    $path_info = pathinfo($src);
    $base_url = $path_info['dirname'] . '/' . $path_info['filename'];
    $sources = '';
    
    if ($settings['enable_avif']) $sources .= '<source srcset="' . $base_url . '.avif" type="image/avif">';
    if ($settings['enable_webp']) $sources .= '<source srcset="' . $base_url . '.webp" type="image/webp">';
    if (empty($sources)) return $img;
    
    if (!empty($settings['enable_lazy_loading']) && strpos($img, 'loading=') === false) {
        $img = str_replace('<img ', '<img loading="lazy" ', $img);
    }

    return "<picture>$sources$img</picture>";
}
