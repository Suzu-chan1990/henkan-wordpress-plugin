<?php
defined('ABSPATH') || exit;

add_filter('the_content', 'henkan_picture_replace', 99);

function henkan_picture_replace($content) {
    $settings = henkan_get_settings();
    if (empty($settings['picture_filter_enabled'])) return $content;
    // Existing <picture> blocks are left alone (no nested <picture>); every other <img> is checked.
    return preg_replace_callback('/<picture\b.*?<\/picture>|<img\b[^>]+>/is', function ($m) {
        if (stripos($m[0], '<picture') === 0) return $m[0];
        return henkan_img_callback($m);
    }, $content);
}

/** Value of an HTML attribute (double or single quotes), or '' if missing. */
function henkan_img_attr($img, $name) {
    if (preg_match('/\s' . preg_quote($name, '/') . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $img, $m)) {
        return html_entity_decode(isset($m[3]) ? $m[3] : $m[2], ENT_QUOTES);
    }
    return '';
}

function henkan_img_callback($matches) {
    $img = $matches[0];
    if (strpos($img, 'data-no-optimize') !== false) return $img;

    $src = henkan_img_attr($img, 'src');
    if ($src === '') return $img;
    $src = strtok($src, '?#');
    if (!preg_match('/\.(jpe?g|png)$/i', $src)) return $img;

    $upload = wp_get_upload_dir();
    if (empty($upload['baseurl']) || strpos($src, $upload['baseurl']) !== 0) return $img;

    // Only media-library images. WordPress inserts sized URLs (image-1024x576.jpg), which
    // attachment_url_to_postid() does not know, so use the wp-image-ID class or the full-size URL.
    $id = 0;
    if (preg_match('/\bwp-image-(\d+)\b/', henkan_img_attr($img, 'class'), $cm)) $id = (int) $cm[1];
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

    // Priority: AVIF > WebP > JXL (Experimental)
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

    if (!empty($settings['enable_lazy_loading']) && strpos($img, 'loading=') === false) {
        $img = preg_replace('/^<img\s/i', '<img loading="lazy" ', $img);
    }

    return "<picture>$sources$img</picture>";
}
