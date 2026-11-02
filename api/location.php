<?php
/** Location from the browser share page (share.php). Body: {t: token, lat, lng, acc, speed (m/s), heading, ts (ms), battery, charging} */
require __DIR__ . '/../inc/bootstrap.php';
require APP_DIR . '/inc/logic.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
$in = json_input();
$token = (string) ($in['t'] ?? '');
$device = $token !== '' ? row('SELECT * FROM devices WHERE token = ?', [$token]) : null;
if (!$device) {
    json_out(['error' => 'This sharing link is not valid any more. Ask the admin for a new one.'], 404);
}
if (!$device['active']) {
    json_out(['error' => 'This phone has been switched off by the admin.'], 403);
}
$battery = isset($in['battery']) && is_numeric($in['battery']) && $in['battery'] >= 0 && $in['battery'] <= 100 ? round((float) $in['battery']) : null;
$charging = isset($in['charging']) && $in['charging'] !== null ? ($in['charging'] ? 1 : 0) : null;
if (!empty($in['ping'])) {
    // Battery check-in from a phone that hasn't moved (no new GPS position).
    q('UPDATE devices SET last_battery = COALESCE(?, last_battery), last_charging = COALESCE(?, last_charging), last_seen_at = ? WHERE id = ?',
        [$battery, $charging, time(), $device['id']]);
    json_out(['ok' => true, 'at' => time()]);
}
if (!valid_coords($in['lat'] ?? null, $in['lng'] ?? null)) {
    json_out(['error' => 'Bad location'], 400);
}
$speed = isset($in['speed']) && is_numeric($in['speed']) && $in['speed'] >= 0 ? $in['speed'] * 3.6 : null;
ingest_position($device, [
    'lat'      => (float) $in['lat'],
    'lng'      => (float) $in['lng'],
    'ts'       => isset($in['ts']) && is_numeric($in['ts']) ? (int) ($in['ts'] / 1000) : null,
    'speed'    => $speed,
    'bearing'  => isset($in['heading']) && is_numeric($in['heading']) ? (float) $in['heading'] : null,
    'accuracy' => isset($in['acc']) && is_numeric($in['acc']) ? (float) $in['acc'] : null,
    'battery'  => isset($in['battery']) && is_numeric($in['battery']) ? (float) $in['battery'] : null,
    'charging' => isset($in['charging']) ? (bool) $in['charging'] : null,
]);
if ($battery !== null) {
    // keep the newest battery level even if this GPS point arrived late
    q('UPDATE devices SET last_battery = ?, last_charging = COALESCE(?, last_charging) WHERE id = ?', [$battery, $charging, $device['id']]);
}
json_out(['ok' => true, 'at' => time()]);
