<?php
/**
 * فهرست برنامه برای نصب روی گوشی.
 * پیش از این فایل ثابت manifest.json بود و نام برنامه در آن سفت‌شده؛
 * حالا از فایل تنظیمات می‌آید تا هر نصبی برند خودش را داشته باشد.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name'             => SITE_NAME,
    'short_name'       => SITE_SHORT_NAME,
    'description'      => (string)cfg('site.tagline', 'پیام‌رسان امن و خصوصی'),
    'start_url'        => 'chat.php',
    'scope'            => './',
    'display'          => 'standalone',
    'background_color' => SITE_THEME_COLOR,
    'theme_color'      => SITE_THEME_COLOR,
    'orientation'      => 'portrait-primary',
    'lang'             => 'fa',
    'dir'              => 'rtl',
    'icons' => [
        [
            'src'     => 'assets/icons/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => 'assets/icons/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'shortcuts' => [
        [
            'name'  => 'گفتگو',
            'url'   => 'chat.php',
            'icons' => [['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192']],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
