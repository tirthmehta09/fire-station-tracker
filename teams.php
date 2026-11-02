<?php
/** Admin: teams, their cars and the phones that share location. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

require_admin();

function clean(string $key, int $max = 100): string
{
    return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = $_POST['do'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'team_save') {
        $name = clean('name');
        if ($name === '') {
            flash('Give the team a name.', 'error');
            redirect('teams.php');
        }
        $color = preg_match('/^#[0-9a-f]{6}$/i', $_POST['color'] ?? '') ? $_POST['color'] : TEAM_COLORS[0];
        $pin = trim((string) ($_POST['pin'] ?? ''));
        $mpin = trim((string) ($_POST['member_pin'] ?? ''));
        if (($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) || ($mpin !== '' && !preg_match('/^\d{4,8}$/', $mpin))) {
            flash('The PIN should be 4 to 8 digits.', 'error');
            redirect('teams.php' . ($id ? "?edit=$id#team-$id" : '?add=1'));
        }
        if ($id) {
            q('UPDATE teams SET name=?, color=?, leader_name=?, leader_phone=?, notes=? WHERE id=?',
                [$name, $color, clean('leader_name'), clean('leader_phone', 30), clean('notes', 1000), $id]);
        } else {
            q('INSERT INTO teams (name, color, leader_name, leader_phone, notes, created_at) VALUES (?,?,?,?,?,?)',
                [$name, $color, clean('leader_name'), clean('leader_phone', 30), clean('notes', 1000), time()]);
            $id = (int) db()->lastInsertId();
        }
        if ($pin !== '') {
            q('UPDATE teams SET pin_hash = ? WHERE id = ?', [password_hash($pin, PASSWORD_DEFAULT), $id]);
        }
        if ($mpin !== '') {
            q('UPDATE teams SET member_pin_hash = ? WHERE id = ?', [password_hash($mpin, PASSWORD_DEFAULT), $id]);
        }
        flash("Saved $name." . ($pin !== '' ? " Leader PIN set to $pin." : '') . ($mpin !== '' ? " Member PIN set to $mpin." : '') . ($pin !== '' || $mpin !== '' ? ' Note them down: they are not shown again.' : ''));
        redirect("teams.php#team-$id");
    }

    if ($do === 'team_delete' && $id) {
        $t = row('SELECT * FROM teams WHERE id = ?', [$id]);
        if ($t) {
            q('UPDATE stations SET team_id = NULL, suggested_order = NULL WHERE team_id = ?', [$id]);
            q('DELETE FROM station_visits WHERE team_id = ?', [$id]);
            q('UPDATE devices SET team_id = NULL, car_id = NULL WHERE team_id = ?', [$id]);
            q('DELETE FROM cars WHERE team_id = ?', [$id]);
            q('DELETE FROM teams WHERE id = ?', [$id]);
            flash("Deleted {$t['name']}. Its stations are now unassigned.");
        }
        redirect('teams.php');
    }

    if ($do === 'car_save') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $label = clean('label', 60) ?: 'Car ' . ((int) val('SELECT COUNT(*) FROM cars WHERE team_id = ?', [$teamId]) + 1);
        if ($id) {
            q('UPDATE cars SET label=?, plate=?, driver_name=?, driver_phone=? WHERE id=?', [$label, clean('plate', 30), clean('driver_name'), clean('driver_phone', 30), $id]);
        } else {
            q('INSERT INTO cars (team_id, label, plate, driver_name, driver_phone, created_at) VALUES (?,?,?,?,?,?)',
                [$teamId, $label, clean('plate', 30), clean('driver_name'), clean('driver_phone', 30), time()]);
        }
        flash("Saved $label.");
        redirect("teams.php#team-$teamId");
    }

    if ($do === 'car_delete' && $id) {
        $teamId = (int) val('SELECT team_id FROM cars WHERE id = ?', [$id]);
        q('UPDATE devices SET car_id = NULL WHERE car_id = ?', [$id]);
        q('DELETE FROM cars WHERE id = ?', [$id]);
        flash('Car removed.');
        redirect("teams.php#team-$teamId");
    }

    if ($do === 'device_save') {
        $teamId = !empty($_POST['team_id']) ? (int) $_POST['team_id'] : null;
        $carId = !empty($_POST['car_id']) ? (int) $_POST['car_id'] : null;
        if ($carId && (int) val('SELECT team_id FROM cars WHERE id = ?', [$carId]) !== $teamId) {
            $carId = null;
        }
        $identifier = preg_replace('/\s+/', '', clean('identifier', 64));
        $name = clean('name');
        if ($id) {
            $dev = row('SELECT * FROM devices WHERE id = ?', [$id]);
            if ($identifier !== '' && $identifier !== $dev['identifier'] && val('SELECT id FROM devices WHERE identifier = ? AND id <> ?', [$identifier, $id])) {
                flash("Another phone already uses the ID $identifier.", 'error');
                redirect("teams.php#team-$teamId");
            }
            q('UPDATE devices SET identifier=?, name=?, team_id=?, car_id=?, approved=1, active=? WHERE id=?',
                [$identifier ?: $dev['identifier'], $name ?: $dev['name'], $teamId, $carId, isset($_POST['active']) ? (int) $_POST['active'] : 1, $id]);
            if (isset($_POST['person_phone'])) {
                q('UPDATE devices SET person_phone = ? WHERE id = ?', [clean('person_phone', 30), $id]);
            }
        } else {
            if ($identifier === '') {
                flash('Enter the device identifier shown in the Traccar app.', 'error');
                redirect("teams.php#team-$teamId");
            }
            if (val('SELECT id FROM devices WHERE identifier = ?', [$identifier])) {
                flash("A phone with ID $identifier already exists. Look under New phones or in its team.", 'error');
                redirect("teams.php#team-$teamId");
            }
            q('INSERT INTO devices (identifier, token, name, team_id, car_id, source, approved, active, created_at) VALUES (?,?,?,?,?,?,1,1,?)',
                [$identifier, random_token(), $name ?: "Phone $identifier", $teamId, $carId, 'traccar', time()]);
        }
        flash('Phone saved.');
        redirect($teamId ? "teams.php#team-$teamId" : 'teams.php');
    }

    if ($do === 'device_web') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        $carId = !empty($_POST['car_id']) ? (int) $_POST['car_id'] : null;
        q('INSERT INTO devices (identifier, token, name, team_id, car_id, source, approved, active, created_at) VALUES (?,?,?,?,?,?,1,1,?)',
            ['web-' . random_token(4), random_token(), clean('name') ?: 'Browser phone', $teamId ?: null, $carId, 'web', time()]);
        flash('Browser sharing link created. Copy it from the phone list and send it to that volunteer.');
        redirect("teams.php#team-$teamId");
    }

    if ($do === 'device_delete' && $id) {
        $dev = delete_device($id);
        $teamId = $dev ? (int) $dev['team_id'] : 0;
        flash($dev ? "Deleted {$dev['name']}." : 'That phone was already deleted.');
        redirect($teamId ? "teams.php#team-$teamId" : 'teams.php');
    }

    if ($do === 'devices_delete') {
        $n = 0;
        foreach (array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))) as $did) {
            $n += delete_device($did) ? 1 : 0;
        }
        flash($n ? "Deleted $n phone" . ($n === 1 ? '' : 's') . '.' : 'Tick the phones you want to delete first.', $n ? 'ok' : 'error');
        redirect('teams.php');
    }
    redirect('teams.php');   // after any form post, always redirect (refresh must reload, not re-send)
}

$teams = rows('SELECT * FROM teams ORDER BY id');
$cars = rows('SELECT * FROM cars ORDER BY team_id, id');
$devices = rows('SELECT * FROM devices ORDER BY team_id, car_id, id');
$stationCounts = array_column(rows('SELECT team_id, COUNT(*) AS n FROM stations WHERE active = 1 AND team_id IS NOT NULL GROUP BY team_id'), 'n', 'team_id');
// Plus the stops every team makes (Borivali first, Byculla last...), unless the team skips one.
$sharedIds = array_column(rows("SELECT id FROM stations WHERE active = 1 AND shared <> ''"), 'id');
$skips = array_column(rows("SELECT v.team_id, COUNT(*) AS n FROM station_visits v JOIN stations s ON s.id = v.station_id WHERE v.skip = 1 AND s.active = 1 AND s.shared <> '' GROUP BY v.team_id"), 'n', 'team_id');
$newPhones = array_filter($devices, fn($d) => !$d['approved']);
$unassigned = array_filter($devices, fn($d) => $d['approved'] && !$d['team_id']);
$carsByTeam = [];
foreach ($cars as $c) {
    $carsByTeam[(int) $c['team_id']][] = $c;
}
$devByTeam = [];
foreach ($devices as $d) {
    if ($d['approved'] && $d['team_id']) {
        $devByTeam[(int) $d['team_id']][] = $d;
    }
}
$editId = (int) ($_GET['edit'] ?? 0);
$now = time();
$serverUrl = absolute_url('api/traccar.php');
$usedColors = array_column($teams, 'color');
$nextColor = current(array_diff(TEAM_COLORS, $usedColors)) ?: TEAM_COLORS[count($teams) % count(TEAM_COLORS)];

function phone_state(array $d, int $now): string
{
    if (!$d['last_seen_at']) {
        return '<span class="pill pending">Never connected</span>';
    }
    $age = $now - (int) ($d['last_fix_at'] ?: $d['last_seen_at']);
    $cls = $age > 900 ? 'offline' : ($age > 180 ? 'stale' : 'live');
    $label = $cls === 'live' ? 'Live' : ($cls === 'stale' ? 'Delayed' : 'Offline');
    $bat = $d['last_battery'] !== null ? ' · ' . (int) $d['last_battery'] . '%' : '';
    return '<span class="pill ' . $cls . '" title="Last update ' . h(date('g:i A', (int) ($d['last_fix_at'] ?: $d['last_seen_at']))) . '">' . $label . $bat . '</span>';
}

/** Bin button that deletes a phone straight away (after a warning), wherever the phone is listed. */
function delete_phone_button(array $d, string $label = ''): string
{
    return '<button type="button" class="btn ' . ($label ? 'btn-sm btn-danger' : 'btn-del') . '" data-del-device="' . (int) $d['id'] . '" data-approved="' . ($d['approved'] ? 1 : 0) . '" data-name="' . h($d['name']) . '" title="Delete this phone" aria-label="Delete ' . h($d['name']) . '">'
        . '<i class="bi bi-trash3"></i>' . ($label ? ' ' . h($label) : '') . '</button>';
}

/** One phone that still needs a team: name it, pick team and car, or delete it. */
function assign_phone_row(array $d, array $teams, array $cars, int $now): void
{
    ?>
    <form method="post" class="dev-row" data-device-row>
      <?= csrf_field() ?><input type="hidden" name="do" value="device_save"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
      <div class="dev-main"><b class="mono"><?= h($d['approved'] ? $d['name'] : $d['identifier']) ?></b>
        <div class="tiny muted"><?= $d['approved'] ? 'ID ' . h($d['identifier']) . ' · ' : '' ?>first seen <?= h(date('g:i A', (int) $d['created_at'])) ?> · <?= phone_state($d, $now) ?></div></div>
      <input type="text" name="name" value="<?= $d['approved'] ? h($d['name']) : '' ?>" placeholder="Name, e.g. Rahul's phone" style="flex:1 1 160px">
      <select name="team_id" required style="width:auto;min-width:140px;flex:1 1 140px" data-car-filter>
        <option value="">Team…</option>
        <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="car_id" style="width:auto;min-width:120px;flex:1 1 120px">
        <option value="">Car (optional)</option>
        <?php foreach ($cars as $c): ?><option value="<?= (int) $c['id'] ?>" data-team="<?= (int) $c['team_id'] ?>"><?= h($c['label'] . ($c['plate'] ? ' · ' . $c['plate'] : '')) ?></option><?php endforeach; ?>
      </select>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Add to team</button><?= delete_phone_button($d, 'Delete') ?></div>
    </form>
    <?php
}

function team_form(array $t, string $nextColor): void
{
    $color = $t['color'] ?? $nextColor;
    ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="team_save">
      <input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
      <div class="form-grid">
        <div class="field" style="margin:0"><label>Team name</label><input type="text" name="name" value="<?= h($t['name'] ?? '') ?>" required placeholder="e.g. Team A – South"></div>
        <div class="field" style="margin:0"><label>Leader PIN (4–8 digits)</label><input type="text" name="pin" inputmode="numeric" pattern="\d{4,8}" placeholder="<?= !empty($t['pin_hash']) ? 'Blank = keep current' : 'e.g. 4821' ?>"></div>
        <div class="field" style="margin:0"><label>Member PIN (4–8 digits)</label><input type="text" name="member_pin" inputmode="numeric" pattern="\d{4,8}" placeholder="<?= !empty($t['member_pin_hash']) ? 'Blank = keep current' : 'e.g. 1357' ?>"></div>
        <div class="field" style="margin:0"><label>Leader name</label><input type="text" name="leader_name" value="<?= h($t['leader_name'] ?? '') ?>" placeholder="e.g. Rahul Shah"></div>
        <div class="field" style="margin:0"><label>Leader phone</label><input type="tel" name="leader_phone" value="<?= h($t['leader_phone'] ?? '') ?>" placeholder="e.g. 98200 00000"></div>
      </div>
      <div class="hint" style="margin-top:6px"><i class="bi bi-info-circle"></i> The leader logs in with the leader PIN (marks stations, shares location). Members log in with the member PIN, their name and mobile (share location only).</div>
      <div class="field" style="margin-bottom:0">
        <label>Colour on the map</label>
        <div class="swatches">
          <?php foreach (TEAM_COLORS as $c): ?>
            <label><input type="radio" name="color" value="<?= h($c) ?>" <?= strcasecmp($c, $color) === 0 ? 'checked' : '' ?>><span style="background:<?= h($c) ?>"></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field" style="margin-bottom:0"><label>Description</label><input type="text" name="notes" value="<?= h($t['notes'] ?? '') ?>" placeholder="e.g. Borivali to Malad stations · 3 cars · 18 volunteers"><div class="hint">Shown under the team name on the live map.</div></div>
      <div class="row"><button class="btn btn-primary" type="submit">Save team</button></div>
    </form>
    <?php
}

page_head('Teams & phones', 'teams');
?>
<main class="wrap">
  <div class="page-head">
    <div><h1>Teams &amp; phones</h1><p class="muted"><?= count($teams) ?> teams · <?= count($cars) ?> cars · <span data-dev-total><?= count($devices) - count($newPhones) ?></span> phones</p></div>
    <a class="btn btn-primary" href="?add=1#add">+ Add team</a>
  </div>

  <?php if ($newPhones): ?>
  <div class="card" style="border-color:#f6dc8f;background:#fffbeb;margin-bottom:16px" data-device-card>
    <div class="card-head"><h2>New phones sending location</h2><span class="pill reached plain"><?= count($newPhones) ?> waiting</span></div>
    <p class="small muted">These phones switched on the Traccar app with this server. Add each one to a team so it shows on the map, or delete the ones you don't know.</p>
    <?php foreach ($newPhones as $d) { assign_phone_row($d, $teams, $cars, $now); } ?>
  </div>
  <?php endif; ?>

  <?php if ($unassigned): ?>
  <div class="card" style="margin-bottom:16px" data-device-card>
    <div class="card-head"><h2>Phones without a team</h2><span class="pill pending plain"><?= count($unassigned) ?></span></div>
    <p class="small muted">These phones are not on the map. Put each one in a team, or delete it.</p>
    <?php foreach ($unassigned as $d) { assign_phone_row($d, $teams, $cars, $now); } ?>
  </div>
  <?php endif; ?>

  <details class="box" style="margin-bottom:16px" <?= !$devices ? 'open' : '' ?>>
    <summary>How phones connect (Traccar app setup)</summary>
    <div class="box-body">
      <ol class="steps">
        <li>Install <b>Traccar Client</b> from the <a href="https://play.google.com/store/apps/details?id=org.traccar.client" target="_blank" rel="noopener">Play Store</a> or <a href="https://apps.apple.com/app/traccar-client/id843156974" target="_blank" rel="noopener">App Store</a>.</li>
        <li>Set <b>Server URL</b> to:
          <div class="kbd-copy" style="margin-top:6px"><code><?= h($serverUrl) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h($serverUrl) ?>">Copy</button></div></li>
        <li>Note the <b>Device identifier</b> the app shows (or type your own, like <code>car01</code>). Add it under the team below, or just switch the app on and it appears in <b>New phones</b> above.</li>
        <li>Set the update interval / frequency to <b>15 seconds</b>, accuracy <b>High</b>, then turn the service <b>on</b>. Allow location <b>all the time</b> and turn off battery optimisation for the app.</li>
      </ol>
      <p class="small">No app? Create a <b>browser link</b> for that phone in its team below. It works only while the page stays open. Full guide for volunteers: <a href="<?= h(app_url('help.php')) ?>" target="_blank">Help page</a>.</p>
    </div>
  </details>

  <?php if (isset($_GET['add']) || !$teams): ?>
  <div class="card" id="add" style="margin-bottom:16px">
    <div class="card-head"><h2>Add a team</h2><?php if ($teams): ?><a class="btn btn-ghost btn-sm" href="teams.php">Cancel</a><?php endif; ?></div>
    <?php team_form([], $nextColor); ?>
  </div>
  <?php endif; ?>

  <div class="grid grid-auto">
  <?php foreach ($teams as $t): $tid = (int) $t['id']; $tCars = $carsByTeam[$tid] ?? []; $tDevs = $devByTeam[$tid] ?? []; ?>
    <section class="card" id="team-<?= $tid ?>" style="border-top:5px solid <?= h($t['color']) ?>">
      <div class="card-head">
        <div><div class="row" style="gap:8px"><span class="dot" style="--c:<?= h($t['color']) ?>;width:14px;height:14px"></span><h2><?= h($t['name']) ?></h2></div>
          <?php if ($t['notes']): ?><div class="small muted" style="margin-top:2px"><?= h($t['notes']) ?></div><?php endif; ?></div>
        <?= team_status_pill($t['status']) ?>
      </div>
      <div class="small" style="display:grid;gap:3px">
        <div><b><?= (int) ($stationCounts[$tid] ?? 0) + count($sharedIds) - (int) ($skips[$tid] ?? 0) ?></b> stations · <a href="<?= h(app_url('plan.php')) ?>">plan</a></div>
        <div>Leader: <?= $t['leader_name'] ? h($t['leader_name']) : '<span class="muted">not set</span>' ?><?= $t['leader_phone'] ? ' · <a href="tel:' . h($t['leader_phone']) . '">' . h($t['leader_phone']) . '</a>' : '' ?></div>
        <div>Leader PIN: <?= $t['pin_hash'] ? '<span class="pill done plain">Set</span>' : '<span class="pill offline plain">Not set – leader can\'t log in</span>' ?>
          · Member PIN: <?= $t['member_pin_hash'] ? '<span class="pill done plain">Set</span>' : '<span class="pill pending plain">Not set</span>' ?></div>

      </div>

      <details class="box" style="margin-top:12px" <?= $editId === $tid ? 'open' : '' ?>>
        <summary>Edit team</summary>
        <div class="box-body">
          <?php team_form($t, $nextColor); ?>
          <hr>
          <form method="post" data-confirm="Delete <?= h($t['name']) ?>? Its stations become unassigned and its cars are removed."><?= csrf_field() ?>
            <input type="hidden" name="do" value="team_delete"><input type="hidden" name="id" value="<?= $tid ?>">
            <button class="btn btn-sm btn-danger" type="submit">Delete team</button></form>
        </div>
      </details>

      <h3 style="margin:16px 0 8px">Cars (<?= count($tCars) ?>)</h3>
      <?php foreach ($tCars as $c): ?>
        <details class="box" style="margin-bottom:6px">
          <summary style="padding:9px 12px;font-weight:600"><?= h($c['label']) ?> <span class="muted small" style="font-weight:500"><?= h(trim($c['plate'] . ' · ' . $c['driver_name'], ' ·')) ?></span></summary>
          <div class="box-body">
            <form method="post" class="form-grid"><?= csrf_field() ?>
              <input type="hidden" name="do" value="car_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="team_id" value="<?= $tid ?>">
              <input type="text" name="label" value="<?= h($c['label']) ?>" placeholder="Car name">
              <input type="text" name="plate" value="<?= h($c['plate']) ?>" placeholder="Number plate">
              <input type="text" name="driver_name" value="<?= h($c['driver_name']) ?>" placeholder="Driver name">
              <input type="tel" name="driver_phone" value="<?= h($c['driver_phone']) ?>" placeholder="Driver phone">
              <div class="row form-actions"><button class="btn btn-sm btn-primary" type="submit">Save</button>
                <button class="btn btn-sm btn-danger" type="submit" name="do" value="car_delete">Remove car</button></div>
            </form>
          </div>
        </details>
      <?php endforeach; ?>
      <details class="box">
        <summary style="padding:9px 12px;color:var(--brand)">+ Add car</summary>
        <div class="box-body">
          <form method="post" class="form-grid"><?= csrf_field() ?>
            <input type="hidden" name="do" value="car_save"><input type="hidden" name="team_id" value="<?= $tid ?>">
            <input type="text" name="label" placeholder="Car name (e.g. Car <?= count($tCars) + 1 ?>)">
            <input type="text" name="plate" placeholder="Number plate">
            <input type="text" name="driver_name" placeholder="Driver name">
            <input type="tel" name="driver_phone" placeholder="Driver phone">
            <div class="form-actions"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add car</button></div>
          </form>
        </div>
      </details>

      <h3 style="margin:16px 0 8px">Phones sharing location (<span data-dev-count><?= count($tDevs) ?></span>)</h3>
      <?php if (!$tDevs): ?><p class="small muted">No phones yet. Add the Traccar ID below, or create a browser link.</p><?php endif; ?>
      <?php foreach ($tDevs as $d): $car = null; foreach ($tCars as $c) { if ((int) $c['id'] === (int) $d['car_id']) { $car = $c; } } ?>
        <details class="box" style="margin-bottom:6px" data-device-row>
          <summary class="has-del" style="padding:7px 8px 7px 12px;font-weight:600;flex-wrap:wrap">
            <span><?= h($d['name']) ?></span>
            <?php if ($d['role']): ?><span class="pill plain <?= $d['role'] === 'leader' ? 'reached' : 'pending' ?>"><?= $d['role'] === 'leader' ? 'Leader' : 'Member' ?></span><?php endif; ?>
            <span class="muted small" style="font-weight:500"><?= h(implode(' · ', array_filter([$d['person_phone'], $car ? $car['label'] : '', $d['app_seen_at'] && $d['source'] === 'web' ? 'browser + app' : ($d['source'] === 'web' && !$d['app_seen_at'] ? 'browser' : 'app'), 'ID ' . $d['identifier']]))) ?></span>
            <?= phone_state($d, $now) ?><?= $d['active'] ? '' : ' <span class="pill inactive plain">Off</span>' ?>
            <?= delete_phone_button($d) ?>
          </summary>
          <div class="box-body">
            <?php if ($d['source'] === 'web'): $link = absolute_url('share.php?t=' . $d['token']); ?>
              <div class="kbd-copy" style="margin-bottom:10px"><code><?= h($link) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h($link) ?>">Copy link</button></div>
            <?php endif; ?>
            <form method="post" class="form-grid"><?= csrf_field() ?>
              <input type="hidden" name="do" value="device_save"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input type="text" name="name" value="<?= h($d['name']) ?>" placeholder="Name" aria-label="Name">
              <input type="tel" name="person_phone" value="<?= h($d['person_phone']) ?>" placeholder="Mobile number" aria-label="Mobile number">
              <input type="text" name="identifier" value="<?= h($d['identifier']) ?>" placeholder="Device ID" aria-label="Device ID" <?= $d['source'] === 'web' ? 'readonly' : '' ?>>
              <select name="team_id" data-car-filter>
                <?php foreach ($teams as $tt): ?><option value="<?= (int) $tt['id'] ?>" <?= (int) $tt['id'] === $tid ? 'selected' : '' ?>><?= h($tt['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="car_id">
                <option value="">No car</option>
                <?php foreach ($cars as $c): ?><option value="<?= (int) $c['id'] ?>" data-team="<?= (int) $c['team_id'] ?>" <?= (int) $c['id'] === (int) $d['car_id'] ? 'selected' : '' ?>><?= h($c['label']) ?></option><?php endforeach; ?>
              </select>
              <select name="active"><option value="1" <?= $d['active'] ? 'selected' : '' ?>>On</option><option value="0" <?= $d['active'] ? '' : 'selected' ?>>Off – stop tracking, keep history</option></select>
              <div class="row form-actions"><button class="btn btn-sm btn-primary" type="submit">Save</button>
                <?= delete_phone_button($d, 'Delete phone') ?></div>
            </form>
          </div>
        </details>
      <?php endforeach; ?>
      <details class="box">
        <summary style="padding:9px 12px;color:var(--brand)">+ Add phone</summary>
        <div class="box-body">
          <form method="post" class="stack"><?= csrf_field() ?>
            <input type="hidden" name="do" value="device_save"><input type="hidden" name="team_id" value="<?= $tid ?>">
            <div class="form-grid">
              <input type="text" name="identifier" placeholder="Traccar device ID" required>
              <input type="text" name="name" placeholder="Name, e.g. Driver's phone">
              <select name="car_id"><option value="">Car (optional)</option><?php foreach ($tCars as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['label']) ?></option><?php endforeach; ?></select>
              <div class="form-actions"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-phone"></i> Add Traccar phone</button></div>
            </div>
          </form>
          <hr>
          <form method="post" class="stack"><?= csrf_field() ?>
            <input type="hidden" name="do" value="device_web"><input type="hidden" name="team_id" value="<?= $tid ?>">
            <div class="form-grid">
              <input type="text" name="name" placeholder="Name, e.g. Priya's phone">
              <select name="car_id"><option value="">Car (optional)</option><?php foreach ($tCars as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['label']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="form-actions"><button class="btn btn-sm" type="submit"><i class="bi bi-link-45deg"></i> Create browser sharing link</button></div>
          </form>
        </div>
      </details>
    </section>
  <?php endforeach; ?>
  </div>

  <?php if ($devices): $teamNames = array_column($teams, 'name', 'id'); $carNames = array_column($cars, 'label', 'id'); ?>
  <details class="box" id="cleanup" style="margin-top:16px">
    <summary><i class="bi bi-trash3"></i> Delete several phones at once</summary>
    <div class="box-body">
      <p class="small muted" style="margin:0">Use this to clear test phones and duplicates before the event. Deleting a phone also deletes its location history, so it drops out of the report.
        To stop tracking a phone but keep its history, open it above and set it to <b>Off</b>. A phone that is still sharing will show up again as a new phone.</p>
      <form method="post" data-confirm="Delete the ticked phones and their location history? This can't be undone.">
        <?= csrf_field() ?><input type="hidden" name="do" value="devices_delete">
        <div class="filters" style="margin-top:12px">
          <button type="button" class="chip" data-pick="never">Never connected</button>
          <button type="button" class="chip" data-pick="old">No update for 1 hour+</button>
          <button type="button" class="chip" data-pick="none">Clear ticks</button>
        </div>
        <div class="bulk-list">
          <?php foreach ($devices as $d): $seen = (int) ($d['last_fix_at'] ?: $d['last_seen_at']); ?>
            <label class="check" data-device-row data-never="<?= $seen ? 0 : 1 ?>" data-age="<?= $seen ? $now - $seen : 999999 ?>">
              <input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>">
              <span><b><?= h($d['approved'] ? $d['name'] : 'New phone') ?></b>
                <?php if ($d['role']): ?><span class="pill plain <?= $d['role'] === 'leader' ? 'reached' : 'pending' ?>"><?= $d['role'] === 'leader' ? 'Leader' : 'Member' ?></span><?php endif; ?>
                <?= phone_state($d, $now) ?>
                <span class="tiny muted" style="display:block"><?= h(implode(' · ', array_filter([
                    $d['team_id'] ? ($teamNames[$d['team_id']] ?? '') : 'No team', $d['car_id'] ? ($carNames[$d['car_id']] ?? '') : '', $d['person_phone'], 'ID ' . $d['identifier'], $d['active'] ? '' : 'Off']))) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-danger" type="submit"><i class="bi bi-trash3"></i> Delete ticked phones</button>
      </form>
    </div>
  </details>
  <?php endif; ?>
</main>
<script>
// Only show the cars of the chosen team in the car dropdown next to it.
document.querySelectorAll('[data-car-filter]').forEach((teamSel) => {
  const carSel = teamSel.parentElement.querySelector('select[name=car_id]');
  if (!carSel) return;
  const sync = () => {
    carSel.querySelectorAll('option[data-team]').forEach((o) => {
      o.hidden = o.dataset.team !== teamSel.value;
      if (o.hidden && o.selected) carSel.value = '';
    });
    carSel.dispatchEvent(new Event('fst:sync'));
  };
  teamSel.addEventListener('change', sync);
  sync();
});
// Quick ticks in "Delete several phones at once".
document.querySelectorAll('[data-pick]').forEach((b) => b.addEventListener('click', () => {
  document.querySelectorAll('#cleanup [data-device-row]').forEach((row) => {
    const box = row.querySelector('input');
    if (b.dataset.pick === 'none') box.checked = false;
    else if (b.dataset.pick === 'never' && row.dataset.never === '1') box.checked = true;
    else if (b.dataset.pick === 'old' && +row.dataset.age > 3600) box.checked = true;
  });
}));
// After a phone is deleted, keep the counts on this page right without reloading.
document.addEventListener('fst:device-deleted', (e) => {
  document.querySelectorAll('#cleanup input[value="' + e.detail.id + '"]').forEach((i) => i.closest('[data-device-row]').remove());
  document.querySelectorAll('section[id^="team-"]').forEach((sec) => {
    const n = sec.querySelector('[data-dev-count]');
    if (n) n.textContent = sec.querySelectorAll('details[data-device-row]').length;
  });
  const total = document.querySelector('[data-dev-total]');
  if (total && e.detail.approved) total.textContent = Math.max(0, +total.textContent - 1);
});
</script>
<?php page_foot(); ?>
