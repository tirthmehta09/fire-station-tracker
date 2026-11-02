<?php
/**
 * Event logic: storing locations, auto-detecting arrivals, station/team status,
 * return-time estimates, route ordering and the live state sent to every screen.
 */
defined('APP_DIR') || exit;

const STATUS_LABELS = ['pending' => 'Pending', 'reached' => 'Reached', 'done' => 'Delivered'];
const TEAM_STATUS_LABELS = ['not_started' => 'Not started', 'on_route' => 'On the way', 'returned' => 'Back at base'];

function log_event(string $type, ?int $teamId, ?int $stationId, ?int $deviceId, string $message, ?int $at = null): void
{
    q('INSERT INTO events (type, team_id, station_id, device_id, message, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$type, $teamId, $stationId, $deviceId, mb_substr($message, 0, 255), $at ?? time()]);
}

/* ---------------------------------------------------------------- stops */
// A stop is one team's visit to one station. Most stations belong to one team, so the stop is the station row itself.
// A station every team goes to (shared = 'first' or 'last') keeps each team's progress in station_visits instead.

const SHARED_LABELS = ['first' => 'First stop for every team', 'last' => 'Last stop for every team'];
const STOP_FIELDS = ['status' => 'pending', 'reached_at' => null, 'reached_by' => null, 'done_at' => null, 'done_by' => null,
    'near_since' => null, 'near_device_id' => null, 'hold_until' => null];

/** Every team's progress at the shared stations: [station_id][team_id] => station_visits row. */
function shared_visits(): array
{
    $out = [];
    foreach (rows('SELECT * FROM station_visits') as $v) {
        $out[(int) $v['station_id']][(int) $v['team_id']] = $v;
    }
    return $out;
}

/** A shared station as one team sees it: the station with that team's status and times. */
function as_team_stop(array $st, int $teamId, ?array $visit): array
{
    $st['team_id'] = $teamId;
    foreach (STOP_FIELDS as $k => $empty) {
        $st[$k] = $visit ? $visit[$k] : $empty;
    }
    return $st;
}

/**
 * A team's stops in route order: first stop for every team, the team's own stations (suggested order),
 * last stop for every team. Shared stops the team skips are left out. $visits comes from shared_visits().
 */
function team_stops(int $teamId, array $stations, array $visits): array
{
    $first = $own = $last = [];
    foreach ($stations as $s) {
        if (!$s['active']) {
            continue;
        }
        if ($s['shared'] !== '') {
            $v = $visits[(int) $s['id']][$teamId] ?? null;
            if ($v && $v['skip']) {
                continue;
            }
            if ($s['shared'] === 'first') {
                $first[] = as_team_stop($s, $teamId, $v);
            } else {
                $last[] = as_team_stop($s, $teamId, $v);
            }
        } elseif ((int) $s['team_id'] === $teamId) {
            $own[] = $s;
        }
    }
    usort($own, fn($a, $b) => [$a['suggested_order'] ?? PHP_INT_MAX, (int) $a['id']] <=> [$b['suggested_order'] ?? PHP_INT_MAX, (int) $b['id']]);
    return array_merge($first, $own, $last);
}

function load_team_stops(int $teamId): array
{
    return team_stops($teamId, rows('SELECT * FROM stations WHERE active = 1 ORDER BY id'), shared_visits());
}

/** One stop: the station itself, or for a shared station that team's visit to it. */
function load_stop(array $st, int $teamId): array
{
    if ($st['shared'] === '') {
        return $st;
    }
    return as_team_stop($st, $teamId, row('SELECT * FROM station_visits WHERE station_id = ? AND team_id = ?', [$st['id'], $teamId]));
}

/** Save fields of a stop: on the station itself, or on the team's visit row for a shared station. */
function save_stop(array $st, array $fields): void
{
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($fields)));
    if ($st['shared'] === '') {
        q("UPDATE stations SET $set WHERE id = ?", array_merge(array_values($fields), [$st['id']]));
        return;
    }
    q('INSERT IGNORE INTO station_visits (station_id, team_id, updated_at) VALUES (?, ?, ?)', [$st['id'], $st['team_id'], time()]);
    q("UPDATE station_visits SET $set WHERE station_id = ? AND team_id = ?", array_merge(array_values($fields), [$st['id'], $st['team_id']]));
}

/* ------------------------------------------------------------ locations */

/**
 * Delete a phone for good: the phone itself and its stored GPS points (so it also drops out of the report).
 * A phone that is still sharing comes back as a new phone; switching it Off stops it but keeps its history.
 */
function delete_device(int $id): ?array
{
    $d = row('SELECT * FROM devices WHERE id = ?', [$id]);
    if (!$d) {
        return null;
    }
    q('DELETE FROM positions WHERE device_id = ?', [$id]);
    q('UPDATE stations SET near_since = NULL, near_device_id = NULL WHERE near_device_id = ?', [$id]);
    q('UPDATE station_visits SET near_since = NULL, near_device_id = NULL WHERE near_device_id = ?', [$id]);
    q('DELETE FROM devices WHERE id = ?', [$id]);
    @unlink(APP_DIR . '/data/cache_public.json');
    return $d;
}

/**
 * Store one location fix from a phone and run arrival detection.
 * $fix keys: lat, lng, ts, speed (km/h), bearing, accuracy (m), battery (0-100), charging (bool)
 */
function ingest_position(array $device, array $fix): void
{
    $now = time();
    $lat = (float) $fix['lat'];
    $lng = (float) $fix['lng'];
    if (!valid_coords($lat, $lng)) {
        return;
    }
    $ts = (int) ($fix['ts'] ?? 0);
    if ($ts <= 0 || $ts > $now + 300 || $ts < $now - 3 * 86400) {
        $ts = $now;
    }
    $speed    = isset($fix['speed']) && $fix['speed'] !== null && $fix['speed'] >= 0 ? round((float) $fix['speed'], 1) : null;
    $bearing  = isset($fix['bearing']) && $fix['bearing'] !== null && $fix['bearing'] >= 0 ? round((float) $fix['bearing']) : null;
    $accuracy = isset($fix['accuracy']) && $fix['accuracy'] !== null && $fix['accuracy'] >= 0 ? round((float) $fix['accuracy'], 1) : null;
    $battery  = isset($fix['battery']) && $fix['battery'] !== null && $fix['battery'] >= 0 ? round((float) $fix['battery']) : null;
    $charging = isset($fix['charging']) && $fix['charging'] !== null ? ($fix['charging'] ? 1 : 0) : null;

    q('INSERT INTO positions (device_id, lat, lng, speed, bearing, accuracy, battery, fix_at, received_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$device['id'], $lat, $lng, $speed, $bearing, $accuracy, $battery, $ts, $now]);

    $isLatest = $device['last_fix_at'] === null || $ts >= (int) $device['last_fix_at'];
    if ($isLatest) {
        q('UPDATE devices SET last_lat=?, last_lng=?, last_speed=?, last_bearing=?, last_accuracy=?,
                last_battery=COALESCE(?, last_battery), last_charging=COALESCE(?, last_charging), last_fix_at=?, last_seen_at=? WHERE id=?',
            [$lat, $lng, $speed, $bearing, $accuracy, $battery, $charging, $ts, $now, $device['id']]);
    } else {
        // Old buffered point sent after the phone was offline: keep it for the trail only.
        q('UPDATE devices SET last_seen_at=? WHERE id=?', [$now, $device['id']]);
        return;
    }

    $goodFix = $accuracy === null || $accuracy <= 250;
    if ($goodFix && $device['approved'] && $device['active'] && $device['team_id']) {
        run_geofence((int) $device['team_id'], (int) $device['id'], $lat, $lng, $ts, $speed);
    }
}

/** Auto-detect start, arrival at stations, leaving a station and return to base. */
function run_geofence(int $teamId, int $deviceId, float $lat, float $lng, int $t, ?float $speed): void
{
    $team = row('SELECT * FROM teams WHERE id = ?', [$teamId]);
    if (!$team || $team['status'] === 'returned') {
        return;
    }
    $radius     = max(30, (float) setting('arrival_radius'));
    $baseRadius = max(50, (float) setting('base_radius'));
    [$baseLat, $baseLng] = base_point();
    $distBase = haversine($lat, $lng, $baseLat, $baseLng);

    if ($team['status'] === 'not_started' && $distBase > $baseRadius + 200) {
        // Start time = this phone's last point at the start point (if recent), not the point where we noticed it had left.
        $parked = min($baseRadius, 80);   // where the car stood, not the whole start-point circle
        $dLat = $parked / 111320;
        $dLng = $parked / (111320 * cos(deg2rad($baseLat)));
        $leftAt = val('SELECT MAX(fix_at) FROM positions WHERE device_id = ? AND fix_at < ? AND fix_at >= ?
            AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?', [$deviceId, $t, $t - 1800, $baseLat - $dLat, $baseLat + $dLat, $baseLng - $dLng, $baseLng + $dLng]);
        set_team_status($team, 'on_route', 'auto', $leftAt ? (int) $leftAt : $t);
        $team['status'] = 'on_route';
    }

    // Fresh positions of all this team's phones, to know if any car is still at a station.
    $teamDevices = rows('SELECT id, last_lat, last_lng FROM devices
        WHERE team_id = ? AND approved = 1 AND active = 1 AND last_fix_at >= ?', [$teamId, $t - 600]);

    $stops = array_filter(load_team_stops($teamId), fn($s) => $s['status'] !== 'done' && $s['lat'] !== null);
    foreach ($stops as $st) {
        if ($st['hold_until'] !== null && (int) $st['hold_until'] > $t) {
            continue;   // a leader/admin changed this station by hand a moment ago: don't override them
        }
        $d = haversine($lat, $lng, (float) $st['lat'], (float) $st['lng']);
        if ($st['status'] === 'pending') {
            if ($d <= $radius) {
                $slow = $speed !== null && $speed <= 8;
                $since = $st['near_since'] !== null ? (int) $st['near_since'] : null;
                // A car driving past shouldn't count: need it slow, or still nearby 45 s later.
                if ($slow || ($since !== null && $t - $since >= 45)) {
                    set_station_status($st, 'reached', 'auto', $since !== null ? min($since, $t) : $t);
                } elseif ($since === null) {
                    save_stop($st, ['near_since' => $t, 'near_device_id' => $deviceId]);
                }
            } elseif ($st['near_since'] !== null && (int) $st['near_device_id'] === $deviceId && $d > $radius + 50) {
                save_stop($st, ['near_since' => null, 'near_device_id' => null]);
            }
        } elseif ($st['status'] === 'reached' && setting('auto_done') === '1' && $d > $radius + 100) {
            $stayed = $t - (int) $st['reached_at'];
            if ($stayed < (int) setting('min_dwell')) {
                continue;
            }
            $someoneStillThere = false;
            foreach ($teamDevices as $dv) {
                if ((int) $dv['id'] !== $deviceId && $dv['last_lat'] !== null
                    && haversine((float) $dv['last_lat'], (float) $dv['last_lng'], (float) $st['lat'], (float) $st['lng']) <= $radius + 100) {
                    $someoneStillThere = true;
                    break;
                }
            }
            if (!$someoneStillThere) {
                set_station_status($st, 'done', 'auto', $t);
            }
        }
    }

    if ($team['status'] === 'on_route' && $distBase <= $baseRadius) {
        $all = load_team_stops($teamId);
        $left = count(array_filter($all, fn($s) => $s['status'] !== 'done'));
        if ($all && $left === 0) {
            set_team_status($team, 'returned', 'auto', $t);
        }
    }
}

/* --------------------------------------------------------------- status */

/** $st is a stop: a station row, or a shared station as one team sees it (see load_stop). */
function set_station_status(array $st, string $status, string $by, ?int $at = null): void
{
    if (!isset(STATUS_LABELS[$status]) || $st['status'] === $status) {
        return;
    }
    $at = $at ?? time();
    $team = $st['team_id'] ? row('SELECT * FROM teams WHERE id = ?', [$st['team_id']]) : null;
    $teamName = $team ? $team['name'] : 'Someone';

    // A change made by hand pauses automatic detection on this station for 10 minutes,
    // so a car still parked there can't immediately undo the leader's Undo.
    $hold = $by === 'auto' ? null : time() + 600;

    $clear = ['near_since' => null, 'near_device_id' => null, 'hold_until' => $hold, 'updated_at' => time()];
    if ($status === 'pending') {
        save_stop($st, ['status' => 'pending', 'reached_at' => null, 'reached_by' => null, 'done_at' => null, 'done_by' => null] + $clear);
        log_event('undo', $team ? (int) $team['id'] : null, (int) $st['id'], null, "{$st['name']} set back to pending", $at);
        return;
    }
    if ($status === 'reached' && $st['status'] === 'done') {
        // Undo of "Delivered": back one step, keep the original arrival time.
        save_stop($st, ['status' => 'reached', 'reached_at' => $st['reached_at'] ?? $at, 'done_at' => null, 'done_by' => null] + $clear);
        log_event('undo', $team ? (int) $team['id'] : null, (int) $st['id'], null, "Delivery at {$st['name']} undone (back to reached)", $at);
        return;
    }
    if ($status === 'reached') {
        save_stop($st, ['status' => 'reached', 'reached_at' => $at, 'reached_by' => $by, 'done_at' => null, 'done_by' => null] + $clear);
        log_event('reached', $team ? (int) $team['id'] : null, (int) $st['id'], null, "$teamName reached {$st['name']}", $at);
    } else {
        $reachedAt = $st['reached_at'] ?: $at;
        save_stop($st, ['status' => 'done', 'reached_at' => $reachedAt, 'reached_by' => $st['reached_by'] ?? $by, 'done_at' => $at, 'done_by' => $by] + $clear);
        log_event('done', $team ? (int) $team['id'] : null, (int) $st['id'], null, "$teamName delivered sweets at {$st['name']}", $at);
    }
    if ($team && $team['status'] === 'not_started') {
        set_team_status($team, 'on_route', $by, $at);
    }
}

function set_team_status(array $team, string $status, string $by, ?int $at = null): void
{
    if (!isset(TEAM_STATUS_LABELS[$status]) || $team['status'] === $status) {
        return;
    }
    $at = $at ?? time();
    if ($status === 'on_route') {
        q('UPDATE teams SET status = ?, started_at = COALESCE(started_at, ?), returned_at = NULL WHERE id = ?', [$status, $at, $team['id']]);
        log_event('start', (int) $team['id'], null, null, $team['status'] === 'returned' ? "{$team['name']} is back on the road" : "{$team['name']} started the journey", $at);
    } elseif ($status === 'returned') {
        q('UPDATE teams SET status = ?, returned_at = ? WHERE id = ?', [$status, $at, $team['id']]);
        log_event('returned', (int) $team['id'], null, null, "{$team['name']} is back at base", $at);
    } else {
        q('UPDATE teams SET status = ?, started_at = NULL, returned_at = NULL WHERE id = ?', [$status, $team['id']]);
        log_event('undo', (int) $team['id'], null, null, "{$team['name']} set back to not started", $at);
    }
}

/* ------------------------------------------------------- route & estimate */

/** Road distance guess: straight line × 1.35 for Mumbai streets. */
function road_metres(array $a, array $b): float
{
    return haversine($a[0], $a[1], $b[0], $b[1]) * 1.35;
}

/**
 * Seconds needed to finish: the first stop for every team, the remaining stations (nearest first),
 * the last stop for every team, then the drive back to base.
 * $stations: a team's stops (see team_stops) with lat, lng, status, reached_at, shared.
 */
function remaining_seconds(array $stations, array $from, int $now): int
{
    $speed = max(5.0, (float) setting('avg_speed')) / 3.6;   // m/s
    $stop = max(0.0, (float) setting('stop_minutes')) * 60;
    $left = array_values(array_filter($stations, fn($s) => $s['status'] !== 'done' && $s['lat'] !== null));
    // Once the team has been to a later stop, a first stop still open was missed: don't send it back there.
    $movedOn = (bool) array_filter($stations, fn($s) => $s['shared'] !== 'first' && $s['reached_at'] !== null);
    $first = array_filter($left, fn($s) => $s['shared'] === 'first' && !$movedOn);
    $last = array_filter($left, fn($s) => $s['shared'] === 'last');
    $left = array_values(array_filter($left, fn($s) => $s['shared'] === ''));
    $cur = $from;
    $secs = 0.0;
    $go = function (array $s) use (&$cur, &$secs, $speed, $stop, $now): void {
        $secs += road_metres($cur, [(float) $s['lat'], (float) $s['lng']]) / $speed;
        $secs += $s['status'] === 'reached' ? max(0, $stop - ($now - (int) $s['reached_at'])) : $stop;
        $cur = [(float) $s['lat'], (float) $s['lng']];
    };
    array_map($go, $first);
    while ($left) {
        $bestI = 0;
        $bestD = INF;
        foreach ($left as $i => $s) {
            $d = road_metres($cur, [(float) $s['lat'], (float) $s['lng']]);
            if ($d < $bestD) {
                $bestD = $d;
                $bestI = $i;
            }
        }
        $go($left[$bestI]);
        array_splice($left, $bestI, 1);
    }
    array_map($go, $last);
    $secs += road_metres($cur, base_point()) / $speed;
    return (int) round($secs);
}

/**
 * Shortest route through the given stations (nearest-neighbour + 2-opt) from $start to $end (both default to base).
 * Returns station ids in order.
 */
function optimise_route(array $stations, ?array $start = null, ?array $end = null): array
{
    $pts = array_values(array_filter($stations, fn($s) => $s['lat'] !== null));
    $noCoords = array_values(array_filter($stations, fn($s) => $s['lat'] === null));
    $n = count($pts);
    if ($n < 2) {
        return array_merge(array_column($pts, 'id'), array_column($noCoords, 'id'));
    }
    $nodes = array_merge([$start ?? base_point()], array_map(fn($s) => [(float) $s['lat'], (float) $s['lng']], $pts), [$end ?? base_point()]);
    $d = fn($i, $j) => haversine($nodes[$i][0], $nodes[$i][1], $nodes[$j][0], $nodes[$j][1]);

    $tour = [0];
    $todo = range(1, $n);
    while ($todo) {
        $last = end($tour);
        usort($todo, fn($a, $b) => $d($last, $a) <=> $d($last, $b));
        $tour[] = array_shift($todo);
    }
    $tour[] = $n + 1;
    $improved = true;
    for ($pass = 0; $improved && $pass < 50; $pass++) {
        $improved = false;
        for ($i = 1; $i < count($tour) - 2; $i++) {
            for ($k = $i + 1; $k < count($tour) - 1; $k++) {
                $delta = $d($tour[$i - 1], $tour[$k]) + $d($tour[$i], $tour[$k + 1])
                       - $d($tour[$i - 1], $tour[$i]) - $d($tour[$k], $tour[$k + 1]);
                if ($delta < -1) {
                    $tour = array_merge(array_slice($tour, 0, $i), array_reverse(array_slice($tour, $i, $k - $i + 1)), array_slice($tour, $k + 1));
                    $improved = true;
                }
            }
        }
    }
    $ids = [];
    foreach (array_slice($tour, 1, -1) as $idx) {
        $ids[] = (int) $pts[$idx - 1]['id'];
    }
    return array_merge($ids, array_map('intval', array_column($noCoords, 'id')));
}

/* ----------------------------------------------------------- live state */

/**
 * Everything a live screen needs. $role: 'public', 'team' or 'admin'.
 * Public output leaves out phone numbers, device IDs, battery and notes.
 */
function build_state(string $role, ?int $teamId = null): array
{
    $now = time();
    $isAdmin = $role === 'admin';
    $private = $role !== 'public';

    $teams = rows('SELECT * FROM teams ORDER BY id');
    $stations = rows("SELECT * FROM stations WHERE active = 1 OR ? = 1
        ORDER BY shared = 'first' DESC, shared = 'last', team_id IS NULL, team_id, suggested_order IS NULL, suggested_order, lat DESC, id", [$isAdmin ? 1 : 0]);
    $visits = shared_visits();
    $cars = rows('SELECT * FROM cars ORDER BY team_id, id');
    $devices = rows('SELECT * FROM devices WHERE active = 1 ORDER BY team_id, car_id, id');

    $carById = [];
    foreach ($cars as $c) {
        $carById[(int) $c['id']] = $c;
    }

    // One marker per car: the freshest phone in that car wins. Phones without a car get their own marker.
    $vehicles = [];
    foreach ($devices as $d) {
        if (!$d['approved'] || $d['last_fix_at'] === null || !$d['team_id']) {
            continue;
        }
        $key = $d['car_id'] && isset($carById[(int) $d['car_id']]) ? 'car' . $d['car_id'] : 'dev' . $d['id'];
        if (isset($vehicles[$key]) && $vehicles[$key]['fix_at'] >= (int) $d['last_fix_at']) {
            $vehicles[$key]['phones']++;
            continue;
        }
        $car = $d['car_id'] ? ($carById[(int) $d['car_id']] ?? null) : null;
        $v = [
            'key'     => $key,
            'team_id' => (int) $d['team_id'],
            'car_id'  => $car ? (int) $car['id'] : null,
            'label'   => $car ? $car['label'] : ($d['name'] ?: 'Phone'),
            'plate'   => $car ? $car['plate'] : '',
            'lat'     => (float) $d['last_lat'],
            'lng'     => (float) $d['last_lng'],
            'speed'   => $d['last_speed'] !== null ? (float) $d['last_speed'] : null,
            'bearing' => $d['last_bearing'] !== null ? (float) $d['last_bearing'] : null,
            'fix_at'  => (int) $d['last_fix_at'],
            'phones'  => ($vehicles[$key]['phones'] ?? 0) + 1,
        ];
        if ($private) {
            $v['battery'] = $d['last_battery'] !== null ? (float) $d['last_battery'] : null;
            $v['device'] = $d['name'];
            $v['driver'] = $car ? trim($car['driver_name'] . ' ' . $car['driver_phone']) : '';
        }
        $vehicles[$key] = $v;
    }
    $vehicles = array_values($vehicles);

    // Each team's stops in route order, numbered 1, 2, 3... (a shared stop has a number per team).
    $byTeam = [];
    $stopNo = [];
    $sharedNo = [];
    foreach ($teams as $t) {
        $tid = (int) $t['id'];
        $byTeam[$tid] = team_stops($tid, $stations, $visits);
        foreach ($byTeam[$tid] as $i => $s) {
            if ($s['shared'] !== '') {
                $sharedNo[(int) $s['id']][$tid] = $i + 1;
            } else {
                $stopNo[(int) $s['id']] = $i + 1;
            }
        }
    }

    $teamOut = [];
    foreach ($teams as $t) {
        $tid = (int) $t['id'];
        $list = $byTeam[$tid] ?? [];
        $counts = ['total' => count($list), 'done' => 0, 'reached' => 0, 'pending' => 0];
        foreach ($list as $s) {
            $counts[$s['status']]++;
        }
        // Latest position of any car in the team, for the estimate.
        $pos = null;
        $lastFix = null;
        foreach ($vehicles as $v) {
            if ($v['team_id'] === $tid && ($lastFix === null || $v['fix_at'] > $lastFix)) {
                $lastFix = $v['fix_at'];
                $pos = [$v['lat'], $v['lng']];
            }
        }
        $etaSecs = null;
        $eta = null;
        if ($t['status'] !== 'returned' && $list) {
            $from = $t['status'] === 'on_route' && $pos ? $pos : base_point();
            $etaSecs = remaining_seconds($list, $from, $now);
            $eta = $t['status'] === 'on_route' ? $now + $etaSecs : null;
        }
        $next = null;
        foreach ($list as $s) {
            if ($s['status'] !== 'done') {
                $next = $s;
                break;
            }
        }
        $row = [
            'id'                 => $tid,
            'name'               => $t['name'],
            'color'              => $t['color'],
            'description'        => (string) $t['notes'],
            'status'             => $t['status'],
            'started_at'         => $t['started_at'] !== null ? (int) $t['started_at'] : null,
            'returned_at'        => $t['returned_at'] !== null ? (int) $t['returned_at'] : null,
            'expected_return_at' => $t['expected_return_at'] !== null ? (int) $t['expected_return_at'] : null,
            'eta'                => $eta,
            'route_seconds'      => $etaSecs,
            'counts'             => $counts,
            'next_station_id'    => $next ? (int) $next['id'] : null,
            'next_status'        => $next ? $next['status'] : null,
            'last_fix'           => $lastFix,
            'cars'               => count(array_filter($cars, fn($c) => (int) $c['team_id'] === $tid)),
        ];
        if ($private) {
            $row['leader_name'] = $t['leader_name'];
            $row['leader_phone'] = $t['leader_phone'];
        }
        $teamOut[] = $row;
    }

    $stationOut = [];
    foreach ($stations as $s) {
        $shared = null;
        if ($s['shared'] !== '') {
            // Every team's visit, and one overall status: delivered once every team going there has delivered.
            $shared = [];
            foreach ($teams as $t) {
                $tid = (int) $t['id'];
                $raw = $visits[(int) $s['id']][$tid] ?? null;
                $v = as_team_stop($s, $tid, $raw);
                $shared[] = [
                    'team_id' => $tid, 'status' => $v['status'], 'order' => $sharedNo[(int) $s['id']][$tid] ?? null, 'skip' => $raw && $raw['skip'],
                    'reached_at' => $v['reached_at'] !== null ? (int) $v['reached_at'] : null, 'done_at' => $v['done_at'] !== null ? (int) $v['done_at'] : null,
                ];
            }
            $going = array_values(array_filter($shared, fn($v) => !$v['skip']));
            $doneN = count(array_filter($going, fn($v) => $v['status'] === 'done'));
            $reached = array_filter(array_column($going, 'reached_at'));
            $s['status'] = $going && $doneN === count($going) ? 'done' : ($reached ? 'reached' : 'pending');
            $s['reached_at'] = $reached ? min($reached) : null;
            $s['done_at'] = $s['status'] === 'done' ? max(array_column($going, 'done_at')) : null;
            $s['reached_by'] = $s['done_by'] = null;
        }
        $r = [
            'id'              => (int) $s['id'],
            'name'            => $s['name'],
            'area'            => $s['area'],
            'lat'             => $s['lat'] !== null ? (float) $s['lat'] : null,
            'lng'             => $s['lng'] !== null ? (float) $s['lng'] : null,
            'team_id'         => $s['team_id'] !== null ? (int) $s['team_id'] : null,
            'order'           => $stopNo[(int) $s['id']] ?? ($s['suggested_order'] !== null ? (int) $s['suggested_order'] : null),
            'status'          => $s['status'],
            'reached_at'      => $s['reached_at'] !== null ? (int) $s['reached_at'] : null,
            'done_at'         => $s['done_at'] !== null ? (int) $s['done_at'] : null,
            'active'          => (bool) $s['active'],
            'shared'          => $s['shared'],
        ];
        if ($shared !== null) {
            $r['visits'] = $shared;
        }
        if ($private) {
            $r['address'] = $s['address'];
            $r['phone'] = $s['phone'];
            $r['boxes'] = $s['boxes'] !== null ? (int) $s['boxes'] : null;
            $r['notes'] = $s['notes'];
            $r['maps_link'] = $s['maps_link'];
            $r['reached_by'] = $s['reached_by'];
            $r['done_by'] = $s['done_by'];
        }
        $stationOut[] = $r;
    }

    $events = array_map(fn($e) => [
        'id' => (int) $e['id'], 'type' => $e['type'], 'team_id' => $e['team_id'] !== null ? (int) $e['team_id'] : null,
        'station_id' => $e['station_id'] !== null ? (int) $e['station_id'] : null, 'message' => $e['message'], 'at' => (int) $e['created_at'],
    ], rows('SELECT * FROM events ORDER BY created_at DESC, id DESC LIMIT 40'));

    [$baseLat, $baseLng] = base_point();
    $state = [
        'now'      => $now,
        'event'    => [
            'name'     => setting('event_name'),
            'subtitle' => setting('event_subtitle'),
            'base'     => ['name' => setting('base_name'), 'lat' => $baseLat, 'lng' => $baseLng],
            'radius'   => (int) setting('arrival_radius'),
            'trails'   => $private || setting('public_trails') === '1',
        ],
        'teams'    => $teamOut,
        'stations' => $stationOut,
        'vehicles' => $vehicles,
        'events'   => $events,
    ];

    if ($isAdmin) {
        $state['devices'] = array_map(fn($d) => [
            'id' => (int) $d['id'], 'identifier' => $d['identifier'], 'name' => $d['name'], 'source' => $d['source'],
            'team_id' => $d['team_id'] !== null ? (int) $d['team_id'] : null, 'car_id' => $d['car_id'] !== null ? (int) $d['car_id'] : null,
            'approved' => (bool) $d['approved'], 'battery' => $d['last_battery'] !== null ? (float) $d['last_battery'] : null,
            'role' => (string) $d['role'], 'person_phone' => (string) $d['person_phone'], 'app_seen_at' => $d['app_seen_at'] !== null ? (int) $d['app_seen_at'] : null,
            'charging' => $d['last_charging'] !== null ? (bool) $d['last_charging'] : null,
            'accuracy' => $d['last_accuracy'] !== null ? (float) $d['last_accuracy'] : null,
            'fix_at' => $d['last_fix_at'] !== null ? (int) $d['last_fix_at'] : null, 'seen_at' => $d['last_seen_at'] !== null ? (int) $d['last_seen_at'] : null,
        ], $devices);
        $state['cars'] = array_map(fn($c) => ['id' => (int) $c['id'], 'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null, 'label' => $c['label'], 'plate' => $c['plate']], $cars);
    }
    if ($role === 'team') {
        $state['my_team_id'] = $teamId;
        $state['my_cars'] = array_values(array_map(fn($c) => ['id' => (int) $c['id'], 'label' => $c['label'], 'plate' => $c['plate']],
            array_filter($cars, fn($c) => (int) $c['team_id'] === $teamId)));
        $state['my_devices'] = array_values(array_map(fn($d) => [
            'id' => (int) $d['id'], 'name' => $d['name'], 'source' => $d['source'], 'car_id' => $d['car_id'] !== null ? (int) $d['car_id'] : null,
            'role' => (string) $d['role'], 'person_phone' => (string) $d['person_phone'], 'app_seen_at' => $d['app_seen_at'] !== null ? (int) $d['app_seen_at'] : null, 'identifier' => $d['identifier'],
            'car' => $d['car_id'] && isset($carById[(int) $d['car_id']]) ? $carById[(int) $d['car_id']]['label'] : '',
            'battery' => $d['last_battery'] !== null ? (float) $d['last_battery'] : null,
            'fix_at' => $d['last_fix_at'] !== null ? (int) $d['last_fix_at'] : null,
        ], array_filter($devices, fn($d) => (int) $d['team_id'] === $teamId && $d['approved'])));
    }
    return $state;
}
