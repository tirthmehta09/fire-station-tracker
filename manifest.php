<?php
/** Web app manifest: lets phones "Add to Home screen" / install this as an app. */
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name'             => setting('event_name'),
    'short_name'       => 'Fire Track',
    'description'      => setting('event_subtitle'),
    'start_url'        => app_url('index.php?app=1'),
    'scope'            => app_base_path(),
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#fbf7f0',
    'theme_color'      => '#d97a22',
    'icons'            => [
        ['src' => app_url('assets/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => app_url('assets/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => app_url('assets/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Live map', 'url' => app_url('index.php')],
        ['name' => 'Team leader', 'url' => app_url('team.php')],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
