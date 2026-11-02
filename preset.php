<?php
/**
 * Admin: apply the station list for Sunday 1 Nov 2026 in one go (from the organisers' "Fire Sweet 1" list).
 * The 26 stations on the list are Going and everything else is Not going. Borivali becomes the first stop and
 * Byculla the last stop for every team. Teams keep the stations already given to them.
 * Shows what will change first; applying it again is harmless.
 */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

require_admin();

// The two stations the app doesn't have yet. Neither post's exact building is published, so the pins are the gates.
const KALINA_LAT = 19.0752618;
const KALINA_LNG = 72.8572149;
const KALINA_NOTES = 'Pin is the gate of the Maharashtra Fire Service headquarters / academy campus on Hans Bhugra Marg (SCLR service road). The mini fire station is on this campus (Indian Express, 20 Oct 2017); ask security at the gate which building.';
const FILMCITY_LAT = 19.1656019;
const FILMCITY_LNG = 72.8781331;
const FILMCITY_NOTES = 'Pin is Film City Gate No. 1 (next to the IGIDR gate), not the fire post itself: where it is inside Film City is not published (Indian Express, 20 Oct 2017). Ask security at Gate No. 1.';

// No. on the list, name on the list, how to recognise it among the stations already in the app,
// and for the two stations the app doesn't have yet, what to add.
const NOV1_LIST = [
    [1, 'Colaba Fire Station', '/colaba/i'],
    [2, 'Nariman Point Fire Station', '/nariman\s*point/i'],
    [3, 'Fort Fire Station', '/^fort\b/i'],
    [4, 'JSS Road / Chira Bazaar Mini Fire Station', '/j\.?\s?s\.?\s?s\.?\s+road|chira\s*baza/i'],
    [5, 'Memonwada Fire Station', '/memonwada/i'],
    [6, 'Gowalia Tank Fire Station', '/gowalia/i'],
    [7, 'Byculla Fire Station / Headquarters', '/byculla/i', 'last'],
    [8, 'Worli Fire Station', '/worli/i'],
    [9, 'Dadar Fire Station', '/^dadar/i'],
    [10, 'Shivaji Park Fire Station', '/shivaji\s*park(?!.*mini)/i'],
    [12, 'Bandra Fire Station', '/^bandra\s+fire/i'],
    [13, 'Bandra Kurla Complex Fire Station', '/bkc|bandra\s*kurla/i'],
    [14, 'Santacruz Mini Fire Station', '/santa\s*cruz|sanatcruz/i'],
    [15, 'Kalina Mini Fire Station', '/kalina/i', '', [
        'area' => 'Kalina, Santacruz (E)', 'address' => 'Maharashtra Fire Service HQ campus gate, Hans Bhugra Marg, Kalina, Santacruz (E), Mumbai 400098',
        'lat' => KALINA_LAT, 'lng' => KALINA_LNG, 'notes' => KALINA_NOTES]],
    [16, 'Vile Parle Fire Station', '/vile\s*parle/i'],
    [17, 'Andheri / Irla Fire Station', '/^andheri|irla/i'],
    [18, 'Marol Fire Station', '/marol/i'],
    [19, 'Goregaon / Motilal Nagar Fire Station', '/^(?!.*(mini|\(e\))).*(goregaon|motilal)/i'],
    [20, 'Film City Mini Fire Station', '/film\s*city/i', '', [
        'area' => 'Film City, Goregaon (E)', 'address' => 'Film City Gate No. 1, Film City Road, near IGIDR gate, Goregaon (E), Mumbai 400065',
        'lat' => FILMCITY_LAT, 'lng' => FILMCITY_LNG, 'notes' => FILMCITY_NOTES]],
    [21, 'Dindoshi Fire Station', '/dindoshi/i'],
    [22, 'Chincholi Bunder Fire Station', '/chincholi/i'],
    [23, 'Malad / Malvani Fire Station', '/malvani|malwani/i'],
    [24, 'Madh Beat Mini Fire Station', '/\bmadh\b/i'],
    [25, 'Kandivali Fire Station', '/kandivali(?!.*thakur)/i'],
    [26, 'Thakur Village Fire Station', '/thakur/i'],
    [27, 'Borivali Fire Station', '/borivali/i', 'first'],
];

/** Match every list entry to a station in the app (each station used once; an active one wins over an inactive one). */
function nov1_matches(array $all): array
{
    usort($all, fn($a, $b) => [(int) !$a['active'], (int) $a['id']] <=> [(int) !$b['active'], (int) $b['id']]);
    $used = [];
    $out = [];
    foreach (NOV1_LIST as $item) {
        $hit = null;
        foreach ($all as $s) {
            if (!isset($used[(int) $s['id']]) && preg_match($item[2], $s['name'])) {
                $hit = $s;
                $used[(int) $s['id']] = true;
                break;
            }
        }
        $out[] = ['no' => $item[0], 'name' => $item[1], 'shared' => $item[3] ?? '', 'new' => $item[4] ?? null, 'station' => $hit];
    }
    return $out;
}

$all = rows('SELECT * FROM stations ORDER BY id');
$matches = nov1_matches($all);
$goingIds = array_filter(array_map(fn($m) => $m['station'] ? (int) $m['station']['id'] : null, $matches));
$others = array_values(array_filter($all, fn($s) => !in_array((int) $s['id'], $goingIds, true)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'apply') {
    check_csrf();
    $now = time();
    $added = 0;
    db()->beginTransaction();
    foreach ($matches as $m) {
        $s = $m['station'];
        if (!$s && $m['new']) {
            $n = $m['new'];
            q('INSERT INTO stations (name, area, address, phone, maps_link, lat, lng, notes, active, shared, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,1,?,?,?)', [
                $m['name'], $n['area'], $n['address'], '', "https://www.google.com/maps/search/?api=1&query={$n['lat']},{$n['lng']}",
                $n['lat'], $n['lng'], $n['notes'], $m['shared'], $now, $now]);
            $added++;
        } elseif ($s && $m['shared'] !== '') {
            // Every team goes here, so it is no longer one team's station.
            q('UPDATE stations SET active = 1, shared = ?, team_id = NULL, suggested_order = NULL, updated_at = ? WHERE id = ?', [$m['shared'], $now, $s['id']]);
        } elseif ($s) {
            q("UPDATE stations SET active = 1, shared = '', updated_at = ? WHERE id = ?", [$now, $s['id']]);
            if ($s['shared'] !== '') {
                q('DELETE FROM station_visits WHERE station_id = ?', [$s['id']]);
            }
        }
    }
    foreach ($others as $s) {
        q('UPDATE stations SET active = 0, updated_at = ? WHERE id = ?', [$now, $s['id']]);
    }
    // Keep each team's suggested order reading 1, 2, 3...
    foreach (array_column(rows('SELECT id FROM teams'), 'id') as $tid) {
        $ids = array_column(rows('SELECT id FROM stations WHERE team_id = ? AND active = 1 ORDER BY suggested_order IS NULL, suggested_order, id', [$tid]), 'id');
        foreach ($ids as $i => $sid) {
            q('UPDATE stations SET suggested_order = ? WHERE id = ?', [$i + 1, $sid]);
        }
        q('UPDATE stations SET suggested_order = NULL WHERE team_id = ? AND active = 0', [$tid]);
    }
    set_setting('preset_1nov_at', (string) $now);
    db()->commit();
    @unlink(APP_DIR . '/data/cache_public.json');
    $going = count($goingIds) + $added;
    flash("Done: $going stations going, " . count($others) . ' not going. Every team now starts at Borivali and ends at Byculla. Give each station without a team a team below.');
    redirect('plan.php');
}

$teamById = array_column(rows('SELECT * FROM teams'), null, 'id');
$teamOf = fn($s) => $s && $s['team_id'] && isset($teamById[(int) $s['team_id']]) ? $teamById[(int) $s['team_id']] : null;
$applied = (int) setting('preset_1nov_at', 0);
$turnOff = array_values(array_filter($others, fn($s) => $s['active']));

page_head('1 Nov station list', 'stations');
?>
<main class="wrap preset">
  <div class="page-head">
    <div>
      <div class="eyebrow">Sunday 1 November</div>
      <h1>Apply the 1 Nov station list</h1>
      <p class="muted"><?= count(NOV1_LIST) ?> fire stations from the “Fire Sweet 1” list are going; every other station is marked Not going.</p>
    </div>
  </div>

  <?php if ($applied): ?>
    <div class="notice ok" style="margin-bottom:14px">This list was applied on <?= h(date('j M, g:i A', $applied)) ?>. Applying it again is safe: it only puts the list back the way it is shown below.</div>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2><i class="bi bi-signpost-split"></i> The route for every team</h2></div>
    <ol class="route-steps">
      <li><i class="bi bi-house-door-fill"></i><span>Start at <b><?= h(setting('base_name')) ?></b></span></li>
      <li><i class="bi bi-people-fill"></i><span><b>Borivali Fire Station</b> (near Don Bosco School): first stop for every team</span></li>
      <li><i class="bi bi-diagram-3"></i><span>Each team's own stations, as given on the Route plan</span></li>
      <li><i class="bi bi-people-fill"></i><span><b>Byculla Fire Station / Headquarters</b>: last stop for every team</span></li>
      <li><i class="bi bi-house-door-fill"></i><span>Back home to <b><?= h(setting('base_name')) ?></b></span></li>
    </ol>
    <p class="small muted" style="margin:8px 0 0">Teams keep the stations already given to them. A team that won't go to Borivali or Byculla can press <b>Skip</b> on the Route plan.</p>
  </section>

  <section class="card" style="margin-top:14px">
    <div class="card-head"><h2><i class="bi bi-check2-circle"></i> Going (<?= count(NOV1_LIST) ?>)</h2></div>
    <div class="table-wrap">
      <table class="list cards">
        <thead><tr><th>On the list</th><th>In the app</th><th>After applying</th></tr></thead>
        <tbody>
        <?php foreach ($matches as $m): $s = $m['station']; $t = $teamOf($s); ?>
          <tr>
            <td class="cell-main"><span class="badge-num"><?= (int) $m['no'] ?></span> <b><?= h($m['name']) ?></b></td>
            <td data-label="In the app"><?php if ($s): ?><?= h($s['name']) ?><div class="tiny muted"><?= $s['active'] ? 'Going now' : 'Not going now' ?><?= $t ? ' · ' . h($t['name']) : '' ?></div><?php elseif ($m['new']): ?><span class="pill plain reached">New</span> <span class="small">will be added</span><?php else: ?><span class="pill plain pending">Not found</span><?php endif; ?></td>
            <td data-label="After applying"><?php if ($m['shared'] === 'first'): ?><b><i class="bi bi-people-fill"></i> First stop for every team</b><?php elseif ($m['shared'] === 'last'): ?><b><i class="bi bi-people-fill"></i> Last stop for every team</b><?php elseif ($t): ?>Going · stays with <?= h($t['name']) ?><?php else: ?>Going · <span class="muted">needs a team</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:14px">
    <div class="card-head"><h2><i class="bi bi-x-circle"></i> Not going (<?= count($others) ?>)</h2></div>
    <?php if (!$others): ?>
      <p class="muted small" style="margin:0">No other stations in the app.</p>
    <?php else: ?>
      <p class="small" style="margin:0 0 8px"><?= $turnOff ? '<b>' . count($turnOff) . '</b> of these are going now and will be marked Not going. ' : '' ?>They stay in the app, so you can make one active again on the Fire stations page.</p>
      <ul class="preset-off">
        <?php foreach ($others as $s): ?><li><?= h($s['name']) ?><?= $s['active'] ? ' <span class="pill plain reached">going now</span>' : '' ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <form method="post" class="card preset-go" style="margin-top:14px" data-confirm="Apply the 1 Nov list? <?= count(NOV1_LIST) ?> stations will be going and <?= count($others) ?> not going. Borivali becomes the first stop and Byculla the last stop for every team.">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="apply">
    <p style="margin:0">Nothing changes until you press the button. Progress already marked on the day is kept.</p>
    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2-all"></i> Apply the 1 Nov list</button>
  </form>
</main>
<?php page_foot(); ?>
