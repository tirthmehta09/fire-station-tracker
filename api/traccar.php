<?php
/**
 * Receives locations from the Traccar Client app (Android / iPhone).
 * In the app set "Server URL" to:  https://<your-site>/<folder>/api/traccar.php
 *
 * Accepts both formats the app has used:
 *  - old:  ?id=123&lat=19.1&lon=72.8&timestamp=...&speed=<knots>&bearing=&accuracy=&batt=
 *  - new:  JSON POST {"device_id": "123", "location": {"timestamp": "...", "coords": {...}, "battery": {...}}}
 */
require __DIR__ . '/../inc/bootstrap.php';
require APP_DIR . '/inc/logic.php';

function ts_from($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v)) {
        $n = (float) $v;
        return (int) ($n > 1e12 ? $n / 1000 : $n);
    }
    $t = strtotime((string) $v);
    return $t === false ? null : $t;
}

function num($v): ?float
{
    return is_numeric($v) ? (float) $v : null;
}

$raw = file_get_contents('php://input') ?: '';
$contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
$identifier = null;
$fixes = [];
$batteryNow = null;      // newest battery reading, also taken from check-ins that have no position
$chargingNow = null;
$batteryAt = -1;

$json = (strpos($contentType, 'json') !== false || ($raw !== '' && ($raw[0] === '{' || $raw[0] === '['))) ? json_decode($raw, true) : null;
if (is_array($json)) {
    $identifier = $json['device_id'] ?? $json['id'] ?? $json['deviceid'] ?? null;
    $locations = $json['location'] ?? $json['locations'] ?? [];
    if (isset($locations['coords']) || isset($locations['timestamp'])) {
        $locations = [$locations];
    }
    foreach ((array) $locations as $loc) {
        $lv = num($loc['battery']['level'] ?? null);
        $at = ts_from($loc['timestamp'] ?? null) ?? time();
        if ($lv !== null && $lv >= 0 && $at >= $batteryAt) {
            $batteryNow = $lv <= 1 ? $lv * 100 : $lv;
            $chargingNow = isset($loc['battery']['is_charging']) ? (bool) $loc['battery']['is_charging'] : null;
            $batteryAt = $at;
        }
        $c = $loc['coords'] ?? null;
        if (!is_array($c) || !isset($c['latitude'], $c['longitude'])) {
            continue;
        }
        $speed = num($c['speed'] ?? null);
        $level = num($loc['battery']['level'] ?? null);
        $fixes[] = [
            'lat'      => (float) $c['latitude'],
            'lng'      => (float) $c['longitude'],
            'ts'       => ts_from($loc['timestamp'] ?? null),
            'speed'    => $speed !== null && $speed >= 0 ? $speed * 3.6 : null,           // m/s -> km/h
            'bearing'  => num($c['heading'] ?? null),
            'accuracy' => num($c['accuracy'] ?? null),
            'battery'  => $level !== null && $level >= 0 ? ($level <= 1 ? $level * 100 : $level) : null,
            'charging' => isset($loc['battery']['is_charging']) ? (bool) $loc['battery']['is_charging'] : null,
        ];
    }
} else {
    $p = array_merge($_GET, $_POST);
    $identifier = $p['id'] ?? $p['deviceid'] ?? null;
    if (num($p['batt'] ?? $p['battery'] ?? null) !== null) {
        $batteryNow = num($p['batt'] ?? $p['battery'] ?? null);
        $chargingNow = isset($p['charge']) ? filter_var($p['charge'], FILTER_VALIDATE_BOOLEAN) : null;
    }
    $lat = $p['lat'] ?? null;
    $lng = $p['lon'] ?? $p['lng'] ?? null;
    if (($lat === null || $lng === null) && !empty($p['location']) && strpos((string) $p['location'], ',') !== false) {
        [$lat, $lng] = array_map('trim', explode(',', (string) $p['location'], 2));
    }
    if (is_numeric($lat) && is_numeric($lng)) {
        $speed = num($p['speed'] ?? null);
        $fixes[] = [
            'lat'      => (float) $lat,
            'lng'      => (float) $lng,
            'ts'       => ts_from($p['timestamp'] ?? null),
            'speed'    => $speed !== null && $speed >= 0 ? $speed * 1.852 : null,         // knots -> km/h
            'bearing'  => num($p['bearing'] ?? $p['heading'] ?? null),
            'accuracy' => num($p['accuracy'] ?? null),
            'battery'  => num($p['batt'] ?? $p['battery'] ?? null),
            'charging' => isset($p['charge']) ? filter_var($p['charge'], FILTER_VALIDATE_BOOLEAN) : null,
        ];
    }
}

$identifier = trim((string) $identifier);
if ($identifier === '' || strlen($identifier) > 64) {
    http_response_code(400);
    exit('Missing device id');
}

try {
    $device = row('SELECT * FROM devices WHERE identifier = ?', [$identifier]);
    if (!$device) {
        // Unknown phone: list it for the admin to assign, so volunteers can just switch the app on.
        $count = (int) val('SELECT COUNT(*) FROM devices');
        if (setting('accept_new_devices') !== '1' || $count >= 400) {
            http_response_code(404);
            exit('Unknown device');
        }
        q('INSERT INTO devices (identifier, token, name, source, approved, active, created_at) VALUES (?, ?, ?, ?, 0, 1, ?)',
            [$identifier, random_token(), 'New phone ' . $identifier, 'traccar', time()]);
        $device = row('SELECT * FROM devices WHERE identifier = ?', [$identifier]);
        log_event('device', null, null, (int) $device['id'], "New phone $identifier started sending location");
    }

    q('UPDATE devices SET app_seen_at = ? WHERE id = ?', [time(), $device['id']]);   // this phone has the Traccar app running
    if (!$fixes) {
        q('UPDATE devices SET last_seen_at = ? WHERE id = ?', [time(), $device['id']]);
    }
    usort($fixes, fn($a, $b) => ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0));
    foreach ($fixes as $fix) {
        ingest_position($device, $fix);
        $device = row('SELECT * FROM devices WHERE id = ?', [$device['id']]);
    }
    if ($batteryNow !== null && $batteryNow >= 0 && $batteryNow <= 100) {
        q('UPDATE devices SET last_battery = ?, last_charging = COALESCE(?, last_charging), last_seen_at = ? WHERE id = ?',
            [round($batteryNow), $chargingNow === null ? null : ($chargingNow ? 1 : 0), time(), $device['id']]);
    }
} catch (Throwable $e) {
    error_log('traccar ingest: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}

http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';
