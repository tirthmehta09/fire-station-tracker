<?php
/** Button actions from the live screens (JSON in, JSON out). Admins can do everything; team leaders only for their own team. */
require __DIR__ . '/../inc/bootstrap.php';
require APP_DIR . '/inc/logic.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only'], 405);
}
start_session();
$role = role();
if (!$role) {
    json_out(['error' => 'Please log in again.'], 401);
}
check_csrf(true);
$myTeam = session_team_id();
$memberDevice = role() === 'member' ? (int) ($_SESSION['device_id'] ?? 0) : 0;
session_write_close();

$in = json_input();
$action = (string) ($in['action'] ?? '');
$admin = $role === 'admin';

function need_admin(bool $admin): void
{
    if (!$admin) {
        json_out(['error' => 'Only the admin can do that.'], 403);
    }
}

function load_team(int $id, bool $admin, ?int $myTeam): array
{
    $team = row('SELECT * FROM teams WHERE id = ?', [$id]);
    if (!$team) {
        json_out(['error' => 'Team not found'], 404);
    }
    if (!$admin && (int) $team['id'] !== $myTeam) {
        json_out(['error' => 'That is not your team.'], 403);
    }
    return $team;
}

$by = $admin ? 'admin' : 'team';
@unlink(APP_DIR . '/data/cache_public.json');

switch ($action) {
    case 'station_status': {
        $st = row('SELECT * FROM stations WHERE id = ?', [(int) ($in['id'] ?? 0)]);
        $status = (string) ($in['status'] ?? '');
        if (!$st || !isset(STATUS_LABELS[$status])) {
            json_out(['error' => 'Station not found'], 404);
        }
        if ($st['shared'] !== '') {
            // Every team stops here: change only this team's visit.
            if (!$admin && !$myTeam) {
                json_out(['error' => 'Only the team leader can mark stations.'], 403);
            }
            if ($admin && empty($in['team_id'])) {
                json_out(['error' => 'Every team stops here. Pick the team on the map or in the team list first.'], 400);
            }
            $team = load_team($admin ? (int) ($in['team_id'] ?? 0) : (int) $myTeam, $admin, $myTeam);
            $st = load_stop($st, (int) $team['id']);
        } elseif (!$admin && (int) $st['team_id'] !== $myTeam) {
            json_out(['error' => 'This station is not in your plan.'], 403);
        }
        set_station_status($st, $status, $by);
        json_out(['ok' => true]);
    }

    case 'team_status': {
        $team = load_team((int) ($in['team_id'] ?? $myTeam ?? 0), $admin, $myTeam);
        $status = (string) ($in['status'] ?? '');
        if (!isset(TEAM_STATUS_LABELS[$status])) {
            json_out(['error' => 'Unknown status'], 400);
        }
        set_team_status($team, $status, $by);
        json_out(['ok' => true]);
    }

    case 'team_eta': {
        $team = load_team((int) ($in['team_id'] ?? $myTeam ?? 0), $admin, $myTeam);
        $time = trim((string) ($in['time'] ?? ''));
        $ts = null;
        if ($time !== '') {
            if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
                json_out(['error' => 'Use a time like 14:30'], 400);
            }
            $ts = mktime((int) $m[1], (int) $m[2], 0);
            if ($ts < time() - 6 * 3600) {
                $ts += 86400;   // a time far in the past means tomorrow
            }
        }
        q('UPDATE teams SET expected_return_at = ? WHERE id = ?', [$ts, $team['id']]);
        if ($ts) {
            log_event('eta', (int) $team['id'], null, null, "{$team['name']} expects to be back by " . fmt_time($ts));
        }
        json_out(['ok' => true, 'expected_return_at' => $ts]);
    }

    case 'assign': {
        need_admin($admin);
        $st = row('SELECT * FROM stations WHERE id = ?', [(int) ($in['station_id'] ?? 0)]);
        if (!$st) {
            json_out(['error' => 'Station not found'], 404);
        }
        // team_id is a team, or 'first' / 'last' for a stop every team makes at the start or end of its route.
        $shared = in_array($in['team_id'] ?? null, ['first', 'last'], true) ? $in['team_id'] : '';
        $teamId = !$shared && !empty($in['team_id']) ? (int) $in['team_id'] : null;
        if ($teamId && !val('SELECT id FROM teams WHERE id = ?', [$teamId])) {
            json_out(['error' => 'Team not found'], 404);
        }
        $order = $teamId ? (int) val('SELECT COALESCE(MAX(suggested_order), 0) + 1 FROM stations WHERE team_id = ? AND active = 1', [$teamId]) : null;
        q('UPDATE stations SET team_id = ?, suggested_order = ?, shared = ?, updated_at = ? WHERE id = ?', [$teamId, $order, $shared, time(), $st['id']]);
        if (!$shared) {
            q('DELETE FROM station_visits WHERE station_id = ?', [$st['id']]);
        }
        if ($st['team_id']) {
            renumber((int) $st['team_id']);
        }
        json_out(['ok' => true]);
    }

    case 'reorder': {
        need_admin($admin);
        $teamId = (int) ($in['team_id'] ?? 0);
        $ids = array_map('intval', (array) ($in['ids'] ?? []));
        foreach ($ids as $i => $id) {
            q('UPDATE stations SET suggested_order = ?, updated_at = ? WHERE id = ? AND team_id = ?', [$i + 1, time(), $id, $teamId]);
        }
        renumber($teamId);
        json_out(['ok' => true]);
    }

    case 'optimise': {
        need_admin($admin);
        $teamId = (int) ($in['team_id'] ?? 0);
        // Between the team's first and last shared stops (if any), otherwise a round trip from base.
        $stops = array_filter(load_team_stops($teamId), fn($s) => $s['lat'] !== null);
        $point = fn(array $list) => $list ? [(float) $list[0]['lat'], (float) $list[0]['lng']] : null;
        $start = $point(array_values(array_filter($stops, fn($s) => $s['shared'] === 'first')));
        $end = $point(array_values(array_reverse(array_filter($stops, fn($s) => $s['shared'] === 'last'))));
        $list = rows("SELECT id, lat, lng FROM stations WHERE team_id = ? AND active = 1 AND shared = ''", [$teamId]);
        foreach (optimise_route($list, $start, $end) as $i => $id) {
            q('UPDATE stations SET suggested_order = ?, updated_at = ? WHERE id = ?', [$i + 1, time(), $id]);
        }
        json_out(['ok' => true]);
    }

    case 'shared_skip': {
        // A team leaves out (or adds back) a stop that every team makes.
        need_admin($admin);
        $st = row("SELECT * FROM stations WHERE id = ? AND shared <> ''", [(int) ($in['station_id'] ?? 0)]);
        $team = load_team((int) ($in['team_id'] ?? 0), $admin, $myTeam);
        if (!$st) {
            json_out(['error' => 'That stop is not for every team any more. Reload the page.'], 404);
        }
        save_stop(load_stop($st, (int) $team['id']), ['skip' => !empty($in['skip']) ? 1 : 0, 'updated_at' => time()]);
        json_out(['ok' => true]);
    }

    case 'station_active': {
        need_admin($admin);
        $id = (int) ($in['id'] ?? 0);
        $active = !empty($in['active']) ? 1 : 0;
        $teamId = val('SELECT team_id FROM stations WHERE id = ?', [$id]);
        q('UPDATE stations SET active = ?, updated_at = ? WHERE id = ?', [$active, time(), $id]);
        if ($teamId) {
            renumber((int) $teamId);
        }
        json_out(['ok' => true]);
    }

    case 'station_coords': {
        need_admin($admin);
        $lat = $in['lat'] ?? null;
        $lng = $in['lng'] ?? null;
        if (!valid_coords($lat, $lng)) {
            json_out(['error' => 'Bad coordinates'], 400);
        }
        q('UPDATE stations SET lat = ?, lng = ?, updated_at = ? WHERE id = ?', [(float) $lat, (float) $lng, time(), (int) ($in['id'] ?? 0)]);
        json_out(['ok' => true]);
    }

    case 'web_device': {
        // A phone that shares location from the browser (no app). Team leaders can create one for their own team.
        $teamId = $admin ? (!empty($in['team_id']) ? (int) $in['team_id'] : null) : $myTeam;
        if ($teamId) {
            load_team($teamId, $admin, $myTeam);
        }
        $carId = !empty($in['car_id']) ? (int) $in['car_id'] : null;
        if ($carId && (int) val('SELECT team_id FROM cars WHERE id = ?', [$carId]) !== $teamId) {
            $carId = null;
        }
        $name = trim((string) ($in['name'] ?? '')) ?: 'Browser phone';
        $token = random_token();
        // A team leader's own phone is stored with the leader's mobile, so the admin can see who carries it.
        $leader = !$admin ? row('SELECT leader_phone FROM teams WHERE id = ?', [$teamId]) : null;
        q('INSERT INTO devices (identifier, token, name, team_id, car_id, source, role, person_phone, approved, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?)',
            ['L' . random_int(100000, 999999), $token, mb_substr($name, 0, 100), $teamId, $carId, 'web', $admin ? '' : 'leader', $leader ? $leader['leader_phone'] : '', time()]);
        json_out(['ok' => true, 'id' => (int) db()->lastInsertId(), 'token' => $token, 'url' => app_url('share.php?t=' . $token)]);
    }

    case 'my_device': {
        // The leader's own phone (shared from the team page): pick which car it is in.
        $dev = row('SELECT * FROM devices WHERE token = ?', [(string) ($in['token'] ?? '')]);
        $allowed = $dev && ($admin || ($myTeam && (int) $dev['team_id'] === $myTeam) || ($memberDevice && (int) $dev['id'] === $memberDevice));
        if (!$allowed) {
            json_out(['error' => 'Phone not found'], 404);
        }
        $carId = !empty($in['car_id']) ? (int) $in['car_id'] : null;
        if ($carId && (int) val('SELECT team_id FROM cars WHERE id = ?', [$carId]) !== (int) $dev['team_id']) {
            json_out(['error' => 'That car is not in your team.'], 400);
        }
        q('UPDATE devices SET car_id = ? WHERE id = ?', [$carId, $dev['id']]);
        json_out(['ok' => true]);
    }

    case 'device_delete': {
        need_admin($admin);
        $dev = delete_device((int) ($in['id'] ?? 0));
        if (!$dev) {
            json_out(['error' => 'Phone not found. It may already be deleted.'], 404);
        }
        json_out(['ok' => true, 'name' => $dev['name']]);
    }

    default:
        json_out(['error' => 'Unknown action'], 400);
}

/** Close gaps so a team's suggested order always reads 1, 2, 3... */
function renumber(int $teamId): void
{
    $ids = array_column(rows('SELECT id FROM stations WHERE team_id = ? AND active = 1 ORDER BY suggested_order IS NULL, suggested_order, id', [$teamId]), 'id');
    foreach ($ids as $i => $id) {
        q('UPDATE stations SET suggested_order = ? WHERE id = ?', [$i + 1, $id]);
    }
    q('UPDATE stations SET suggested_order = NULL WHERE team_id = ? AND active = 0', [$teamId]);
}
