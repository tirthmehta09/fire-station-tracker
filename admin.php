<?php
/** Admin live dashboard. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

require_admin();
page_head('Live', 'admin', ['map' => true]);
include APP_DIR . '/inc/live_panel.php';
page_foot(['live.js']);
