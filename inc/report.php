<?php
/**
 * Numbers for the report: per-car distance and speeds (from the stored GPS points)
 * and per-team visit timings (time to reach each station, time spent there).
 */
defined('APP_DIR') || exit;

const MAX_PLAUSIBLE_KMH = 160;   // faster than this between two points = a GPS glitch, ignored
const MIN_MOVE_METRES = 20;      // smaller moves are GPS jitter while parked

/**
 * One row per car (phones in the same car are combined; a phone without a car counts as its own vehicle).
 * Keys: key, team_id, label, plate, driver, phones, distance_km, drive_seconds, avg_kmh, max_kmh, max_at, first_at, last_at
 */
function vehicle_stats(): array
{
    $cars = array_column(rows('SELECT * FROM cars'), null, 'id');
    $devices = rows('SELECT id, name, team_id, car_id FROM devices WHERE approved = 1');

    $per = [];
    $last = [];
    $st = db()->query('SELECT device_id, lat, lng, speed, accuracy, fix_at FROM positions ORDER BY device_id, fix_at');
    while ($p = $st->fetch()) {
        $id = (int) $p['device_id'];
        if ($p['accuracy'] !== null && (float) $p['accuracy'] > 100) {
            continue;
        }
        $t = (int) $p['fix_at'];
        $s = $per[$id] ?? ['dist' => 0.0, 'drive' => 0, 'max' => 0.0, 'max_at' => null, 'first' => $t, 'last' => $t];
        $s['last'] = $t;
        $lat = (float) $p['lat'];
        $lng = (float) $p['lng'];
        // Speed the phone itself reported (Traccar sends it; most browsers don't).
        if ($p['speed'] !== null && (float) $p['speed'] <= MAX_PLAUSIBLE_KMH && ($p['accuracy'] === null || (float) $p['accuracy'] <= 50)
            && (float) $p['speed'] > $s['max']) {
            $s['max'] = (float) $p['speed'];
            $s['max_at'] = $t;
        }
        $prev = $last[$id] ?? null;
        if ($prev) {
            $dt = $t - $prev[2];
            if ($dt <= 0) {
                $per[$id] = $s;
                continue;
            }
            $d = haversine($prev[0], $prev[1], $lat, $lng);
            $kmh = $d / $dt * 3.6;
            if ($kmh > MAX_PLAUSIBLE_KMH || $d < MIN_MOVE_METRES) {
                $per[$id] = $s;
                continue;   // glitch, or still parked: keep measuring from the earlier point
            }
            $s['dist'] += $d;
            if ($dt <= 180) {
                $s['drive'] += $dt;
            }
            // Speed worked out between two points (for phones that don't report it).
            if ($dt >= 10 && $d >= 50 && $kmh > $s['max']) {
                $s['max'] = $kmh;
                $s['max_at'] = $t;
            }
        }
        $last[$id] = [$lat, $lng, $t];
        $per[$id] = $s;
    }

    $vehicles = [];
    foreach ($devices as $d) {
        $car = $d['car_id'] ? ($cars[(int) $d['car_id']] ?? null) : null;
        $key = $car ? 'car' . $car['id'] : 'dev' . $d['id'];
        $teamId = $car ? (int) $car['team_id'] : ($d['team_id'] !== null ? (int) $d['team_id'] : null);
        if (!isset($vehicles[$key])) {
            $vehicles[$key] = [
                'key' => $key, 'team_id' => $teamId, 'label' => $car ? $car['label'] : $d['name'], 'plate' => $car ? $car['plate'] : '',
                'driver' => $car ? trim($car['driver_name'] . ($car['driver_phone'] ? ' · ' . $car['driver_phone'] : '')) : '',
                'phones' => [], 'tracks' => [],
            ];
        }
        $vehicles[$key]['phones'][] = $d['name'];
        if (isset($per[(int) $d['id']])) {
            $vehicles[$key]['tracks'][] = $per[(int) $d['id']];
        }
    }
    // Cars without any phone still appear (with no numbers).
    foreach ($cars as $c) {
        if (!isset($vehicles['car' . $c['id']])) {
            $vehicles['car' . $c['id']] = ['key' => 'car' . $c['id'], 'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null, 'label' => $c['label'],
                'plate' => $c['plate'], 'driver' => trim($c['driver_name'] . ($c['driver_phone'] ? ' · ' . $c['driver_phone'] : '')), 'phones' => [], 'tracks' => []];
        }
    }

    $out = [];
    foreach ($vehicles as $v) {
        $tracks = $v['tracks'];
        // Two phones in one car drive the same road: use the phone that recorded the most, not the sum.
        usort($tracks, fn($a, $b) => $b['dist'] <=> $a['dist']);
        $main = $tracks[0] ?? null;
        $max = 0.0;
        $maxAt = null;
        foreach ($tracks as $t) {
            if ($t['max'] > $max) {
                $max = $t['max'];
                $maxAt = $t['max_at'];
            }
        }
        $dist = $main ? $main['dist'] / 1000 : 0.0;
        $drive = $main ? $main['drive'] : 0;
        unset($v['tracks']);
        $out[] = $v + [
            'distance_km'   => round($dist, 1),
            'drive_seconds' => $drive,
            'avg_kmh'       => $drive >= 120 && $dist > 0 ? round($dist / ($drive / 3600), 1) : null,
            'max_kmh'       => $max > 0 ? round($max) : null,
            'max_at'        => $maxAt,
            'first_at'      => $tracks ? min(array_column($tracks, 'first')) : null,
            'last_at'       => $tracks ? max(array_column($tracks, 'last')) : null,
        ];
    }
    usort($out, fn($a, $b) => [$a['team_id'] ?? PHP_INT_MAX, $a['label']] <=> [$b['team_id'] ?? PHP_INT_MAX, $b['label']]);
    return $out;
}

/**
 * A team's stations in the order they were actually visited, with
 * travel (time to reach it from the previous stop, or from the start) and stay (time spent there).
 * Returns [visits, notVisited, returnSeconds].
 */
function team_visits(array $team, array $stations): array
{
    $mine = array_values(array_filter($stations, fn($s) => (int) $s['team_id'] === (int) $team['id']));
    $visited = array_values(array_filter($mine, fn($s) => $s['reached_at'] !== null));
    $pending = array_values(array_filter($mine, fn($s) => $s['reached_at'] === null));
    usort($visited, fn($a, $b) => (int) $a['reached_at'] <=> (int) $b['reached_at']);

    $from = $team['started_at'] !== null ? (int) $team['started_at'] : null;
    $out = [];
    foreach ($visited as $i => $s) {
        $reached = (int) $s['reached_at'];
        $done = $s['done_at'] !== null ? (int) $s['done_at'] : null;
        $out[] = $s + [
            'visit_no' => $i + 1,
            'travel'   => $from !== null && $reached >= $from ? $reached - $from : null,
            'stay'     => $done !== null && $done >= $reached ? $done - $reached : null,
        ];
        $from = $done ?? $reached;
    }
    $back = $team['returned_at'] !== null && $from !== null && (int) $team['returned_at'] >= $from ? (int) $team['returned_at'] - $from : null;
    return [$out, $pending, $back];
}

/**
 * Rows for the Locations section: one per station, except a station every team visits,
 * which gets one row per team going there (with that team's times).
 */
function location_rows(array $stations, array $teams, array $visits): array
{
    $out = [];
    foreach ($stations as $s) {
        if ($s['shared'] === '') {
            $out[] = $s;
            continue;
        }
        foreach ($teams as $t) {
            $v = $visits[(int) $s['id']][(int) $t['id']] ?? null;
            if (!$v || !$v['skip']) {
                $out[] = as_team_stop($s, (int) $t['id'], $v);
            }
        }
    }
    return $out;
}

function dur(?int $seconds): string
{
    if ($seconds === null) {
        return '–';
    }
    if ($seconds < 60) {
        return '< 1 min';
    }
    return fmt_duration($seconds);
}

function avg_or_null(array $nums): ?int
{
    $nums = array_values(array_filter($nums, fn($n) => $n !== null));
    return $nums ? (int) round(array_sum($nums) / count($nums)) : null;
}
