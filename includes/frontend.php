<?php
defined('ABSPATH') || exit;

// Post content (classic and block images)
add_filter('the_content', 'henkan_picture_replace', 99);
// Featured images and every other wp_get_attachment_image() call (themes, blocks, widgets)
add_filter('wp_get_attachment_image', 'henkan_attachment_image_picture', 10, 2);

/** True if <picture> output should run for this request. */
function henkan_picture_active() {
    $settings = henkan_get_settings();
    if (empty($settings['picture_filter_enabled'])) return false;
    // Feed readers do not understand <picture>
    if (is_feed()) return false;
    /**
     * Filters whether Henkan wraps images in <picture> for the current request.
     *
     * @param bool $active
     */
    return (bool) apply_filters('henkan_picture_enabled', true);
}

function henkan_picture_replace($content) {
    if (!henkan_picture_active()) return $content;
    $settings = henkan_get_settings();

    // Lazy-loading: WordPress core already sets loading="lazy" on content images and leaves the
    // first ones (the LCP image) without it. Henkan only adds it when core lazy-loading is turned
    // off, and then also skips the first images and any image marked fetchpriority="high".
    $add_lazy  = !empty($settings['enable_lazy_loading']) && !wp_lazy_loading_enabled('img', 'the_content');
    $threshold = function_exists('wp_omit_loading_attr_threshold') ? (int) wp_omit_loading_attr_threshold() : 3;
    $index     = 0;

    // Existing <picture> blocks are left alone (no nested <picture>); every other <img> is checked.
    return preg_replace_callback('/<picture\b.*?<\/picture>|<img\b[^>]+>/is', function ($m) use ($add_lazy, $threshold, &$index) {
        if (stripos($m[0], '<picture') === 0) return $m[0];
        $index++;
        $img = $m[0];
        if ($add_lazy && $index > $threshold && stripos($img, 'loading=') === false && !preg_match('/fetchpriority\s*=\s*["\']?high/i', $img)) {
            $img = preg_replace('/^<img\s/i', '<img loading="lazy" ', $img);
        }
        return henkan_build_picture($img, 0);
    }, $content);
}

/** Wraps the output of wp_get_attachment_image() (featured images etc.) in <picture>. */
function henkan_attachment_image_picture($html, $attachment_id) {
    if (!is_string($html) || $html === '' || !henkan_picture_active()) return $html;
    $settings = henkan_get_settings();
    if (empty($settings['picture_attachment_images'])) return $html;
    if (stripos($html, '<picture') !== false || !preg_match('/^\s*<img\b[^>]*>\s*$/is', $html)) return $html;
    if (!in_array(get_post_mime_type($attachment_id), ['image/jpeg', 'image/png'], true)) return $html;
    return henkan_build_picture(trim($html), (int) $attachment_id);
}

/** Value of an HTML attribute (double or single quotes), or '' if missing. */
function henkan_img_attr($img, $name) {
    if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $img, $m)) {
        return html_entity_decode(isset($m[3]) ? $m[3] : $m[2], ENT_QUOTES);
    }
    return '';
}

// Kept for backwards compatibility (old callback name)
function henkan_img_callback($matches) {
    return henkan_build_picture($matches[0], 0);
}

/**
 * Builds <picture> with AVIF/WebP/JXL <source> elements for an <img> of the media library.
 * Returns the <img> unchanged if no converted file exists.
 *
 * @param string $img <img> tag.
 * @param int    $id  Attachment ID if known (0 = detect from class / URL).
 */
function henkan_build_picture($img, $id = 0) {
    if (strpos($img, 'data-no-optimize') !== false) return $img;

    $src = henkan_img_attr($img, 'src');
    if ($src === '') return $img;
    $src = strtok($src, '?#');
    if (!preg_match('/\.(jpe?g|png)$/i', $src)) return $img;

    $upload = wp_get_upload_dir();
    if (empty($upload['baseurl']) || strpos($src, $upload['baseurl']) !== 0) return $img;

    // Only media-library images. WordPress inserts sized URLs (image-1024x576.jpg), which
    // attachment_url_to_postid() does not know, so use the wp-image-ID class or the full-size URL.
    $id = (int) $id;
    if (!$id && preg_match('/\bwp-image-(\d+)\b/', henkan_img_attr($img, 'class'), $cm)) $id = (int) $cm[1];
    if (!$id) $id = attachment_url_to_postid($src);
    if (!$id) $id = attachment_url_to_postid(preg_replace('/-\d+x\d+(\.[a-z]+)$/i', '$1', $src));
    if (!$id) return $img;

    // Candidates: the responsive srcset of the <img> (url + descriptor), or just its src
    $candidates = [];
    $srcset = henkan_img_attr($img, 'srcset');
    if ($srcset !== '') {
        foreach (explode(',', $srcset) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $bits = preg_split('/\s+/', $part, 2);
            $candidates[] = [strtok($bits[0], '?#'), isset($bits[1]) ? trim($bits[1]) : ''];
        }
    }
    if (empty($candidates)) $candidates[] = [$src, ''];

    $settings = henkan_get_settings();
    $sizes    = henkan_img_attr($img, 'sizes');
    $sources  = '';

    // Delivery priority (same in <picture>, Apache and Nginx rules): AVIF > WebP > JXL (experimental)
    foreach (['avif' => 'enable_avif', 'webp' => 'enable_webp', 'jxl' => 'enable_jxl'] as $fmt => $opt) {
        if (empty($settings[$opt])) continue;
        $set = [];
        foreach ($candidates as $c) {
            list($url, $descriptor) = $c;
            if (strpos($url, $upload['baseurl']) !== 0 || !preg_match('/\.(jpe?g|png)$/i', $url)) continue;
            $rel  = preg_replace('/\.[^.\/]+$/', '', substr($url, strlen($upload['baseurl'])));
            if (strpos($rel, '..') !== false) continue;
            $path = $upload['basedir'] . $rel . '.' . $fmt;
            // Only list files that really exist (otherwise the browser shows a broken image)
            if (!file_exists($path) || filesize($path) <= 0) continue;
            $set[] = esc_url(preg_replace('/\.[^.\/]+$/', '', $url) . '.' . $fmt) . ($descriptor !== '' ? ' ' . esc_attr($descriptor) : '');
        }
        if (empty($set)) continue;
        $sources .= '<source srcset="' . implode(', ', $set) . '"' . ($sizes !== '' ? ' sizes="' . esc_attr($sizes) . '"' : '') . ' type="image/' . $fmt . '">';
    }

    if (empty($sources)) return $img;
    return "<picture>$sources$img</picture>";
}
