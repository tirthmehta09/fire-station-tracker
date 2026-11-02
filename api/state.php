<?php
/** Live state for the map screens. Public viewers get a 5-second shared cache so 100+ volunteers don't load the database. */
require __DIR__ . '/../inc/bootstrap.php';
require APP_DIR . '/inc/logic.php';

start_session(true);
$role = role() ?? 'public';
$teamId = session_team_id() ?? member_team_id();
if ($role === 'member') {
    $role = 'team';   // members see their team's private view (read only; buttons are checked in action.php)
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($role === 'public') {
    if (setting('public_enabled') !== '1') {
        json_out(['error' => 'The public map is turned off.'], 403);
    }
    $cache = APP_DIR . '/data/cache_public.json';
    if (is_file($cache) && filemtime($cache) >= time() - 5) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        readfile($cache);
        exit;
    }
    $json = json_encode(build_state('public'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    @file_put_contents($cache . '.tmp', $json);
    @rename($cache . '.tmp', $cache);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

json_out(build_state($role, $teamId));
