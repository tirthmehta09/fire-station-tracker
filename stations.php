<?php
/**
 * Admin: fire stations. Add with name + Google Maps link, bulk paste from Excel, switch off (Not going) instead of deleting.
 * Edit/add happen in a pop-up and every change saves without reloading the page (the old form posts still work as a fallback).
 */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';
require APP_DIR . '/inc/maps.php';

require_admin();

/** One pasted line -> [name, link, lat, lng]. Accepts Excel rows (tab separated), "Name | link", "Name, link" or "Name lat,lng". */
function parse_bulk_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || (preg_match('/google maps link|latitude/i', $line) && !preg_match('#https?://#i', $line))) {
        return null;   // blank line or the Excel header row
    }
    $link = '';
    if (preg_match('#https?://\S+#i', $line, $m)) {
        $link = rtrim($m[0], ',;|');
        $line = str_replace($m[0], "\t", $line);
    }
    $cells = array_values(array_filter(array_map('trim', preg_split('/\t|\|/', $line)), fn($c) => $c !== ''));
    if (count($cells) === 1 && strpos($cells[0], ',') !== false && !$link) {
        $cells = array_map('trim', explode(',', $cells[0]));
    }
    $lat = $lng = null;
    $name = '';
    $nums = [];
    foreach ($cells as $c) {
        if (preg_match('/^-?\d{1,3}\.\d+$/', $c)) {
            $nums[] = (float) $c;
        } elseif (preg_match('/^(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)$/', $c, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        } elseif ($name === '' && !preg_match('/^\d{1,3}$/', $c)) {
            $name = trim($c, " ,;");
        }
    }
    if ($lat === null && count($nums) >= 2 && valid_coords($nums[0], $nums[1])) {
        [$lat, $lng] = [$nums[0], $nums[1]];
    }
    if ($name === '') {
        return null;
    }
    return [$name, $link, $lat, $lng];
}

/** The fields the edit pop-up needs. */
function station_data(array $s): array
{
    return [
        'id' => (int) $s['id'], 'name' => $s['name'], 'area' => $s['area'], 'address' => $s['address'], 'phone' => $s['phone'],
        'maps_link' => (string) $s['maps_link'], 'lat' => $s['lat'] !== null ? (float) $s['lat'] : null, 'lng' => $s['lng'] !== null ? (float) $s['lng'] : null,
        'team_id' => $s['shared'] !== '' ? $s['shared'] : ($s['team_id'] !== null ? (int) $s['team_id'] : null), 'boxes' => $s['boxes'] !== null ? (int) $s['boxes'] : null,
        'notes' => (string) $s['notes'], 'active' => (bool) $s['active'],
    ];
}

/** One table row (also sent back after each change so the page can update itself). */
function station_row(array $s, array $teamById): string
{
    $id = (int) $s['id'];
    $t = $s['team_id'] ? ($teamById[(int) $s['team_id']] ?? null) : null;
    ob_start(); ?>
        <tr class="<?= $s['active'] ? '' : 'is-off' ?>" data-id="<?= $id ?>" data-off="<?= $s['active'] ? 0 : 1 ?>" data-team="<?= $s['shared'] !== '' ? 'all' : ($t ? (int) $t['id'] : 0) ?>" data-text="<?= h(mb_strtolower($s['name'] . ' ' . $s['area'] . ' ' . $s['address'])) ?>">
          <td class="cell-main">
            <b><?= h($s['name']) ?></b>
            <div class="small muted"><?= h($s['area'] ?: $s['address']) ?><?= $s['boxes'] ? ' · ' . (int) $s['boxes'] . ' boxes' : '' ?></div>
          </td>
          <td data-label="Team"><?= $s['shared'] !== '' ? '<span class="nowrap"><i class="bi bi-people-fill"></i> Every team · ' . ($s['shared'] === 'first' ? 'first stop' : 'last stop') . '</span>' : ($t ? '<span class="nowrap"><span class="dot" style="--c:' . h($t['color']) . '"></span> ' . h($t['name']) . ($s['suggested_order'] ? ' · #' . (int) $s['suggested_order'] : '') . '</span>' : '<span class="muted">–</span>') ?></td>
          <td data-label="Location" data-loc="<?= $id ?>">
            <?php if ($s['lat'] !== null): ?>
              <a class="small" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=<?= h($s['lat'] . ',' . $s['lng']) ?>">On map ↗</a>
            <?php else: ?>
              <span class="pill offline">Missing</span>
            <?php endif; ?>
          </td>
          <td data-label="Status"><?= $s['active'] ? status_pill($s['status']) : '<span class="pill inactive">Not going</span>' ?></td>
          <td class="right nowrap">
            <a class="btn btn-sm" href="?edit=<?= $id ?>" data-edit="<?= $id ?>"><i class="bi bi-pencil"></i> Edit</a>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-sm <?= $s['active'] ? 'btn-ghost' : 'btn-ok' ?>" type="submit" data-toggle="<?= $id ?>"><?= $s['active'] ? '<i class="bi bi-x-circle"></i> Not going' : '<i class="bi bi-check-circle"></i> Make active' ?></button></form>
            <?php if (!$s['active']): ?>
            <form method="post" style="display:inline" data-confirm="Delete <?= h($s['name']) ?> for good?"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="btn btn-sm btn-danger" type="submit" data-delete="<?= $id ?>" data-name="<?= h($s['name']) ?>"><i class="bi bi-trash"></i> Delete</button></form>
            <?php endif; ?>
          </td>
        </tr>
    <?php
    return trim((string) ob_get_clean());
}

$teams = rows('SELECT id, name, color FROM teams ORDER BY id');
$teamById = array_column($teams, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = $_POST['do'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $ajax = ($_POST['ajax'] ?? '') === '1';
    $reply = function (array $data, int $code = 200) use ($ajax) {
        if ($ajax) {
            json_out($data, $code);
        }
    };
    $rowOut = function (int $sid) use ($teamById): array {
        $s = row('SELECT * FROM stations WHERE id = ?', [$sid]);
        return $s ? ['row' => station_row($s, $teamById), 'station' => station_data($s)] : [];
    };

    if ($do === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $lat = trim((string) ($_POST['lat'] ?? ''));
        $lng = trim((string) ($_POST['lng'] ?? ''));
        $link = trim((string) ($_POST['maps_link'] ?? ''));
        if ($name === '') {
            $reply(['error' => 'Give the station a name.'], 400);
            flash('Give the station a name.', 'error');
            redirect('stations.php?' . ($id ? "edit=$id" : 'add=1'));
        }
        if (($lat === '' || $lng === '') && $link !== '') {
            $r = resolve_location($link);
            if ($r['ok']) {
                [$lat, $lng] = [(string) $r['lat'], (string) $r['lng']];
            }
        }
        $hasCoords = valid_coords($lat, $lng);
        // Team: a team id, or 'first' / 'last' for a stop every team makes at the start or end of its route.
        $shared = in_array($_POST['team_id'] ?? '', ['first', 'last'], true) ? $_POST['team_id'] : '';
        $teamId = !$shared && !empty($_POST['team_id']) ? (int) $_POST['team_id'] : null;
        $boxes = trim((string) ($_POST['boxes'] ?? '')) === '' ? null : max(0, (int) $_POST['boxes']);
        $data = [
            mb_substr($name, 0, 150), mb_substr(trim((string) ($_POST['area'] ?? '')), 0, 100), mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 255),
            mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 60), $link, $hasCoords ? (float) $lat : null, $hasCoords ? (float) $lng : null,
            $boxes, trim((string) ($_POST['notes'] ?? '')), !empty($_POST['active']) ? 1 : 0, time(),
        ];
        if ($id) {
            $old = row('SELECT * FROM stations WHERE id = ?', [$id]);
            q('UPDATE stations SET name=?, area=?, address=?, phone=?, maps_link=?, lat=?, lng=?, boxes=?, notes=?, active=?, updated_at=? WHERE id=?', array_merge($data, [$id]));
            if ($old && ((int) $old['team_id'] !== (int) $teamId || $old['shared'] !== $shared)) {
                $order = $teamId ? (int) val('SELECT COALESCE(MAX(suggested_order),0)+1 FROM stations WHERE team_id = ?', [$teamId]) : null;
                q('UPDATE stations SET team_id = ?, suggested_order = ?, shared = ? WHERE id = ?', [$teamId, $order, $shared, $id]);
                if (!$shared) {
                    q('DELETE FROM station_visits WHERE station_id = ?', [$id]);
                }
            }
        } else {
            $order = $teamId ? (int) val('SELECT COALESCE(MAX(suggested_order),0)+1 FROM stations WHERE team_id = ?', [$teamId]) : null;
            q('INSERT INTO stations (name, area, address, phone, maps_link, lat, lng, boxes, notes, active, updated_at, team_id, suggested_order, shared, created_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($data, [$teamId, $order, $shared, time()]));
            $id = (int) db()->lastInsertId();
        }
        $msg = $hasCoords ? "Saved $name." : "Saved $name, but it has no location yet. Edit it and drop the pin on the map.";
        $reply(['ok' => true, 'id' => $id, 'message' => $msg, 'warn' => !$hasCoords] + $rowOut($id));
        flash($msg, $hasCoords ? 'ok' : 'error');
        redirect('stations.php');
    }

    if ($do === 'bulk') {
        $added = 0;
        foreach (preg_split('/\r\n|\r|\n/', (string) ($_POST['lines'] ?? '')) as $line) {
            $p = parse_bulk_line($line);
            if (!$p) {
                continue;
            }
            [$name, $link, $lat, $lng] = $p;
            if ($lat === null && $link && ($c = coords_from_url($link))) {
                [$lat, $lng] = $c;   // long links carry the pin; short links are looked up on the page afterwards
            }
            if (!$link && $lat !== null) {
                $link = "https://www.google.com/maps/search/?api=1&query=$lat,$lng";
            }
            q('INSERT INTO stations (name, maps_link, lat, lng, active, created_at, updated_at) VALUES (?,?,?,?,1,?,?)',
                [mb_substr($name, 0, 150), $link, $lat, $lng, time(), time()]);
            $added++;
        }
        flash($added ? "Added $added stations. Locations are being looked up below." : 'Nothing to add. Paste one station per line.', $added ? 'ok' : 'error');
        redirect('stations.php' . ($added ? '?locate=1' : '?bulk=1'));
    }

    if ($do === 'toggle' && $id) {
        $st = row('SELECT * FROM stations WHERE id = ?', [$id]);
        if ($st) {
            q('UPDATE stations SET active = ?, updated_at = ? WHERE id = ?', [$st['active'] ? 0 : 1, time(), $id]);
            if ($st['team_id']) {
                // keep the team's suggested order 1, 2, 3... without the station that was switched off
                $ids = array_column(rows('SELECT id FROM stations WHERE team_id = ? AND active = 1 ORDER BY suggested_order IS NULL, suggested_order, id', [$st['team_id']]), 'id');
                foreach ($ids as $i => $sid) {
                    q('UPDATE stations SET suggested_order = ? WHERE id = ?', [$i + 1, $sid]);
                }
            }
            $msg = $st['active'] ? "{$st['name']} marked Not going." : "{$st['name']} is active again.";
            $reply(['ok' => true, 'active' => !$st['active'], 'message' => $msg] + $rowOut($id));
            flash($msg);
        } else {
            $reply(['error' => 'Station not found'], 404);
        }
        redirect('stations.php');
    }

    if ($do === 'delete' && $id) {
        $st = row('SELECT * FROM stations WHERE id = ? AND active = 0', [$id]);
        if ($st) {
            q('DELETE FROM stations WHERE id = ?', [$id]);
            $reply(['ok' => true, 'deleted' => $id, 'message' => "Deleted {$st['name']}."]);
            flash("Deleted {$st['name']}.");
        } else {
            $reply(['error' => 'Only stations marked Not going can be deleted.'], 400);
        }
        redirect('stations.php');
    }
    $reply(['error' => 'Unknown action'], 400);
    redirect('stations.php');
}

/* Download the current list for Excel (all stations, going and not going). */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fire-stations-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads the file as UTF-8
    fputcsv($out, ['Sr', 'Fire station', 'Going?', 'Area', 'Address', 'Station phone', 'Team', 'Suggested order', 'Status', 'Sweet boxes', 'Latitude', 'Longitude', 'Google Maps link', 'Notes']);
    $n = 0;
    foreach (rows("SELECT * FROM stations ORDER BY active DESC, shared = 'first' DESC, shared = 'last', team_id IS NULL, team_id, suggested_order, lat DESC") as $s) {
        $mapLink = $s['maps_link'] ?: ($s['lat'] !== null ? "https://www.google.com/maps/search/?api=1&query={$s['lat']},{$s['lng']}" : '');
        fputcsv($out, [++$n, $s['name'], $s['active'] ? 'Going' : 'Not going', $s['area'], $s['address'], $s['phone'],
            $s['shared'] !== '' ? 'Every team (' . $s['shared'] . ' stop)' : ($s['team_id'] && isset($teamById[(int) $s['team_id']]) ? $teamById[(int) $s['team_id']]['name'] : ''), $s['suggested_order'],
            STATUS_LABELS[$s['status']] ?? $s['status'], $s['boxes'], $s['lat'], $s['lng'], $mapLink, $s['notes']]);
    }
    exit;
}

$bulk = isset($_GET['bulk']);
$all = rows("SELECT * FROM stations ORDER BY active DESC, shared = 'first' DESC, shared = 'last', team_id IS NULL, team_id, suggested_order, lat DESC");
$activeCount = count(array_filter($all, fn($s) => $s['active']));
$missing = array_values(array_filter($all, fn($s) => $s['lat'] === null && $s['maps_link']));
$data = [];
foreach ($all as $s) {
    $data[(int) $s['id']] = station_data($s);
}

page_head('Stations', 'stations', ['map' => true]);
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Fire stations</h1>
      <p class="muted"><span id="n-going"><?= $activeCount ?></span> going · <span id="n-off"><?= count($all) - $activeCount ?></span> not going</p>
    </div>
    <div class="row">
      <a class="btn" href="<?= h(app_url('preset.php')) ?>"><i class="bi bi-list-check"></i> 1 Nov list</a>
      <a class="btn" href="?csv=1"><i class="bi bi-file-earmark-spreadsheet"></i> Download list</a>
      <a class="btn" href="?bulk=1"><i class="bi bi-clipboard-plus"></i> Paste many</a>
      <a class="btn btn-primary" href="?add=1" data-add><i class="bi bi-plus-lg"></i> Add station</a>
    </div>
  </div>
  <?php if (!setting('preset_1nov_at')): ?>
    <div class="notice info" style="margin-bottom:14px"><b>Your 1 Nov list is ready:</b> 26 stations going, Borivali first and Byculla last for every team. <a href="<?= h(app_url('preset.php')) ?>">Check and apply it</a></div>
  <?php endif; ?>

<?php if ($bulk): ?>
  <form method="post" class="card" style="margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="bulk">
    <div class="card-head"><h2>Paste many stations</h2><a class="btn btn-ghost btn-sm" href="stations.php">Cancel</a></div>
    <p class="muted small">One station per line: a name and a Google Maps link. You can copy the <b>Fire Station</b> and <b>Google Maps Link</b> columns straight from the Excel sheet, or rows that have Latitude and Longitude.</p>
    <textarea name="lines" rows="10" placeholder="Kandivali Fire Station	https://maps.app.goo.gl/abc123&#10;Borivali Fire Station | https://maps.app.goo.gl/xyz789&#10;Malad Fire Station, 19.1872, 72.8484"></textarea>
    <div class="row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Add stations</button></div>
  </form>
<?php endif; ?>

<?php if ($missing): ?>
  <div class="notice" id="locate-box" style="margin-bottom:14px">
    <b><?= count($missing) ?> station<?= count($missing) > 1 ? 's have' : ' has' ?> a link but no location yet.</b>
    <span id="locate-msg"><?= isset($_GET['locate']) ? 'Looking them up…' : '' ?></span>
    <button class="btn btn-sm" id="locate-btn" type="button" style="margin-left:8px">Look up now</button>
  </div>
<?php endif; ?>

  <div class="card">
    <div class="st-filters">
      <div class="filters" role="tablist" aria-label="Show">
        <button class="chip" type="button" data-show="going">Going <span class="chip-n" id="c-going"></span></button>
        <button class="chip" type="button" data-show="off">Not going <span class="chip-n" id="c-off"></span></button>
        <button class="chip" type="button" data-show="all">All <span class="chip-n" id="c-all"></span></button>
      </div>
      <div class="st-filter-right">
        <select id="f-team" aria-label="Team" style="width:auto;min-width:160px">
          <option value="">All teams</option>
          <option value="0">No team yet</option>
          <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
        </select>
        <input type="search" id="q" placeholder="Search stations" style="max-width:240px">
      </div>
    </div>
    <div class="table-wrap">
    <table class="list cards" id="st-table">
      <thead><tr><th>Station</th><th>Team</th><th>Location</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($all as $s): echo station_row($s, $teamById), "\n"; endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="empty" id="st-empty" style="display:none">No stations match. <a href="?add=1" data-add>Add one</a> or <a href="?bulk=1">paste a list</a>.</div>
  </div>
</main>

<dialog class="modal" id="st-dialog" aria-labelledby="st-dialog-title">
  <form method="post" id="st-form" data-location-picker data-lazy>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="0">
    <div class="modal-head">
      <h2 id="st-dialog-title">Add a station</h2>
      <button class="icon-btn" type="button" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-body">
      <div class="flash flash-error" id="st-form-error" style="display:none;margin:0 0 12px"></div>
      <div class="grid grid-2">
        <div>
          <div class="field"><label for="f-name">Name</label><input type="text" id="f-name" name="name" required data-name placeholder="e.g. Kandivali Fire Station"></div>
          <div class="field">
            <label for="f-link">Google Maps link</label>
            <div class="input-group"><input type="url" id="f-link" name="maps_link" data-link placeholder="https://maps.app.goo.gl/..."><button type="button" class="btn" data-find>Find</button></div>
            <div class="hint" data-resolve-msg>In Google Maps tap Share → Copy link, then paste it here. You can also tap the map to drop the pin.</div>
          </div>
          <div class="form-grid">
            <div class="field" style="margin:0"><label for="f-lat">Latitude</label><input type="text" id="f-lat" name="lat" data-lat inputmode="decimal"></div>
            <div class="field" style="margin:0"><label for="f-lng">Longitude</label><input type="text" id="f-lng" name="lng" data-lng inputmode="decimal"></div>
            <div class="field" style="margin:0"><label for="f-area">Area</label><input type="text" id="f-area" name="area" placeholder="e.g. Kandivali West"></div>
            <div class="field" style="margin:0"><label for="f-phone">Station phone</label><input type="tel" id="f-phone" name="phone"></div>
          </div>
          <div class="field" style="margin-top:14px"><label for="f-address">Address</label><input type="text" id="f-address" name="address" data-address></div>
          <div class="form-grid">
            <div class="field" style="margin:0">
              <label for="f-team-id">Team</label>
              <select id="f-team-id" name="team_id">
                <option value="">Not assigned yet</option>
                <option value="first">Every team · first stop</option>
                <option value="last">Every team · last stop</option>
                <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="field" style="margin:0"><label for="f-boxes">Sweet boxes</label><input type="number" id="f-boxes" name="boxes" min="0"></div>
          </div>
          <div class="field" style="margin-top:14px"><label for="f-notes">Notes</label><input type="text" id="f-notes" name="notes" placeholder="e.g. Ask for the duty officer"></div>
          <label class="check"><input type="checkbox" name="active" value="1" id="f-active" checked> Going (we are visiting this station)</label>
        </div>
        <div>
          <label>Location</label>
          <div class="mini-map" data-pick-map style="height:380px;margin-top:0"></div>
          <div class="hint">Drag the pin or tap the map to fix the exact gate.</div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancel</button>
      <button class="btn btn-primary" type="submit" id="st-save"><i class="bi bi-check-lg"></i> Save station</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const $ = (id) => document.getElementById(id);
  const tbody = document.querySelector('#st-table tbody');
  const store = { get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } } };

  /* ---------------- filters: Going / Not going / All, team, search ---------------- */
  let show = new URLSearchParams(location.search).has('inactive') ? 'off' : (store.get('fst_st_show') || 'going');
  function counts() {
    const rows = [...tbody.rows];
    const off = rows.filter((r) => r.dataset.off === '1').length;
    $('c-going').textContent = rows.length - off;
    $('c-off').textContent = off;
    $('c-all').textContent = rows.length;
    $('n-going').textContent = rows.length - off;
    $('n-off').textContent = off;
  }
  function filter() {
    const term = $('q').value.toLowerCase().trim(), team = $('f-team').value;
    let visible = 0;
    [...tbody.rows].forEach((tr) => {
      const ok = (show === 'all' || (show === 'off') === (tr.dataset.off === '1'))
        && (team === '' || tr.dataset.team === team || (tr.dataset.team === 'all' && team !== '0'))
        && (!term || tr.dataset.text.indexOf(term) !== -1);
      tr.style.display = ok ? '' : 'none';
      if (ok) visible++;
    });
    $('st-empty').style.display = visible ? 'none' : '';
    document.querySelectorAll('[data-show]').forEach((c) => c.classList.toggle('on', c.dataset.show === show));
  }
  document.querySelectorAll('[data-show]').forEach((c) => c.addEventListener('click', () => { show = c.dataset.show; store.set('fst_st_show', show); filter(); }));
  $('q').addEventListener('input', filter);
  $('f-team').addEventListener('change', filter);
  counts();
  filter();

  /* ---------------- saving without reloading ---------------- */
  async function post(fields) {
    const fd = fields instanceof FormData ? fields : new FormData();
    if (!(fields instanceof FormData)) Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    fd.set('ajax', '1');
    fd.set('csrf', APP.csrf);
    const res = await fetch('stations.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    let j = {};
    try { j = await res.json(); } catch (e) { throw new Error('Could not save. Please reload the page.'); }
    if (!res.ok || j.error) throw new Error(j.error || 'Could not save');
    return j;
  }
  function putRow(j, isNew) {
    if (j.station) DATA[j.station.id] = j.station;
    if (!j.row) return;
    const tmp = document.createElement('tbody');
    tmp.innerHTML = j.row;
    const fresh = tmp.firstElementChild;
    const old = tbody.querySelector('tr[data-id="' + j.id + '"]');
    if (old) old.replaceWith(fresh); else tbody.prepend(fresh);
    fresh.classList.add('row-flash');
    counts();
    // keep a just-changed row in view for a moment even if the filter would hide it
    filter();
    if (fresh.style.display === 'none') {
      fresh.style.display = '';
      fresh.classList.add('row-leaving');
      setTimeout(() => { fresh.classList.remove('row-leaving'); filter(); }, 1400);
    }
  }

  tbody.addEventListener('click', async (e) => {
    const tog = e.target.closest('[data-toggle]'), del = e.target.closest('[data-delete]'), edit = e.target.closest('[data-edit]');
    if (edit) { e.preventDefault(); openDialog(+edit.dataset.edit); return; }
    if (!tog && !del) return;
    e.preventDefault();
    const b = tog || del;
    if (b.disabled) return;
    if (del && !confirm('Delete ' + del.dataset.name + ' for good?')) return;
    b.disabled = true;
    b.classList.add('is-busy');
    try {
      if (tog) {
        const j = await post({ do: 'toggle', id: tog.dataset.toggle });
        putRow(Object.assign({ id: +tog.dataset.toggle }, j));
        FST.toast(j.message, 'ok');
      } else {
        const j = await post({ do: 'delete', id: del.dataset.delete });
        const tr = tbody.querySelector('tr[data-id="' + j.deleted + '"]');
        if (tr) { tr.classList.add('row-leaving'); setTimeout(() => { tr.remove(); counts(); filter(); }, 350); }
        delete DATA[j.deleted];
        FST.toast(j.message, 'ok');
      }
    } catch (err) {
      FST.toast(err.message, 'error');
      b.disabled = false;
      b.classList.remove('is-busy');
    }
  });

  /* ---------------- add / edit pop-up ---------------- */
  const dlg = $('st-dialog'), form = $('st-form');
  const field = (n) => form.elements[n];
  function openDialog(id) {
    const s = id ? DATA[id] : null;
    if (id && !s) return;
    $('st-dialog-title').textContent = s ? 'Edit ' + s.name : 'Add a station';
    $('st-form-error').style.display = 'none';
    field('id').value = s ? s.id : 0;
    ['name', 'area', 'address', 'phone', 'notes'].forEach((k) => { field(k).value = s ? (s[k] || '') : ''; });
    field('maps_link').value = s ? s.maps_link : '';
    field('lat').value = s && s.lat != null ? s.lat : '';
    field('lng').value = s && s.lng != null ? s.lng : '';
    field('boxes').value = s && s.boxes != null ? s.boxes : '';
    field('team_id').value = s && s.team_id ? String(s.team_id) : '';
    FST.syncSelect(field('team_id'));
    field('active').checked = s ? s.active : true;
    form.querySelector('[data-resolve-msg]').textContent = 'In Google Maps tap Share → Copy link, then paste it here. You can also tap the map to drop the pin.';
    form.querySelector('[data-resolve-msg]').style.color = '';
    dlg.showModal();
    requestAnimationFrame(() => form._picker && form._picker.show(s && s.lat != null ? s.lat : null, s && s.lng != null ? s.lng : null));
    if (!s) setTimeout(() => field('name').focus(), 50);
  }
  dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dlg.close()));
  dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });   // tap outside the box closes it
  document.querySelectorAll('[data-add]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openDialog(null); }));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = $('st-save');
    if (btn.disabled) return;
    if (!field('name').value.trim()) { field('name').focus(); return; }
    btn.disabled = true;
    btn.classList.add('is-busy');
    try {
      const isNew = field('id').value === '0';
      const j = await post(new FormData(form));
      putRow(j, isNew);
      dlg.close();
      FST.toast(j.message, j.warn ? 'error' : 'ok');
    } catch (err) {
      $('st-form-error').textContent = err.message;
      $('st-form-error').style.display = '';
    }
    btn.disabled = false;
    btn.classList.remove('is-busy');
  });

  // Old links (?add=1, ?edit=5) open the pop-up.
  const qs = new URLSearchParams(location.search);
  if (qs.has('add')) openDialog(null);
  if (qs.get('edit')) openDialog(+qs.get('edit'));
  if (qs.has('add') || qs.has('edit')) history.replaceState(null, '', 'stations.php');

  /* ---------------- look up locations for pasted links ---------------- */
  const missing = <?= json_encode(array_map(fn($s) => (int) $s['id'], $missing)) ?>;
  const btn = $('locate-btn'), msg = $('locate-msg');
  async function locateAll() {
    if (!missing.length) return;
    btn && (btn.disabled = true);
    let ok = 0, fail = 0;
    for (const id of missing) {
      msg.textContent = 'Looking up ' + (ok + fail + 1) + ' of ' + missing.length + '…';
      try {
        const j = await FST.getJSON(APP.api + 'resolve.php?station_id=' + id);
        const cell = document.querySelector('[data-loc="' + id + '"]');
        if (j.ok) { ok++; if (DATA[id]) { DATA[id].lat = j.lat; DATA[id].lng = j.lng; } if (cell) cell.innerHTML = '<span class="pill done">Found</span>'; }
        else { fail++; if (cell) cell.innerHTML = '<a class="pill offline" href="?edit=' + id + '" data-edit="' + id + '">Fix by hand</a>'; }
      } catch (e) { fail++; }
    }
    msg.textContent = ok + ' found' + (fail ? ', ' + fail + ' need the pin placed by hand (Edit → tap the map).' : '.');
    btn && (btn.disabled = false);
  }
  btn && btn.addEventListener('click', locateAll);
  if (qs.has('locate')) locateAll();
})();
</script>
<?php page_foot(); ?>
