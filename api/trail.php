<?php
/** Path driven by one car (v=car12) or phone (v=dev5) since its team started, thinned to at most ~600 points. */
require __DIR__ . '/../inc/bootstrap.php';

start_session(true);
$role = role();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
if (!$role && (setting('public_enabled') !== '1' || setting('public_trails') !== '1')) {
    json_out(['error' => 'Not available'], 403);
}

$key = (string) ($_GET['v'] ?? '');
if (preg_match('/^car(\d+)$/', $key, $m)) {
    $deviceIds = array_map('intval', array_column(rows('SELECT id FROM devices WHERE car_id = ? AND approved = 1', [(int) $m[1]]), 'id'));
    $teamId = val('SELECT team_id FROM cars WHERE id = ?', [(int) $m[1]]);
} elseif (preg_match('/^dev(\d+)$/', $key, $m)) {
    $deviceIds = [(int) $m[1]];
    $teamId = val('SELECT team_id FROM devices WHERE id = ? AND approved = 1', [(int) $m[1]]);
} else {
    json_out(['error' => 'Bad request'], 400);
}
if (!$deviceIds) {
    json_out(['points' => []]);
}

$since = $teamId ? (int) val('SELECT started_at FROM teams WHERE id = ?', [$teamId]) : 0;
$since = max($since - 600, time() - 14 * 3600);
$in = implode(',', array_fill(0, count($deviceIds), '?'));
$pts = rows("SELECT lat, lng FROM positions WHERE device_id IN ($in) AND fix_at >= ? AND (accuracy IS NULL OR accuracy <= 100) ORDER BY fix_at", array_merge($deviceIds, [$since]));

$step = max(1, (int) ceil(count($pts) / 600));
$out = [];
foreach ($pts as $i => $p) {
    if ($i % $step === 0 || $i === count($pts) - 1) {
        $out[] = [round((float) $p['lat'], 6), round((float) $p['lng'], 6)];
    }
}
json_out(['points' => $out]);
