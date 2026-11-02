<?php
/**
 * Admin: event report in two sections.
 *   1. Teams     – timings, stations completed in visit order (time to reach, time there), cars with distance and speeds
 *   2. Locations – every station: which team, when reached/delivered, time to reach, time spent there
 * Printable, and downloadable for Excel (CSV with the same sections).
 */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';
require APP_DIR . '/inc/report.php';

require_admin();

$teams = rows('SELECT * FROM teams ORDER BY id');
$teamById = array_column($teams, null, 'id');
$stations = rows("SELECT * FROM stations WHERE active = 1 ORDER BY shared = 'first' DESC, shared = 'last', team_id IS NULL, team_id, suggested_order, name");
$vehicles = vehicle_stats();
// Each team's stops (including the stops every team makes), and the Locations rows (one per team at those stops).
$sharedVisits = shared_visits();
$stopsByTeam = [];
foreach ($teams as $t) {
    $list = team_stops((int) $t['id'], $stations, $sharedVisits);
    $stopsByTeam[(int) $t['id']] = array_map(fn($s, $i) => $s + ['stop_no' => $i + 1], $list, array_keys($list));
}
$locations = location_rows($stations, $teams, $sharedVisits);
// A station is delivered once every team going there has delivered.
$stationDone = [];
foreach ($locations as $s) {
    $stationDone[(int) $s['id']] = ($stationDone[(int) $s['id']] ?? true) && $s['status'] === 'done';
}
$byLabels = ['auto' => 'Automatic', 'team' => 'Team leader', 'admin' => 'Admin'];

// Per-team visit timings, and the same numbers per station for section 2.
$visitsByTeam = [];
$visitOf = [];
foreach ($teams as $t) {
    $visitsByTeam[(int) $t['id']] = team_visits($t, $stopsByTeam[(int) $t['id']]);
    foreach ($visitsByTeam[(int) $t['id']][0] as $v) {
        $visitOf[$v['id'] . '-' . $t['id']] = $v;
    }
}
$vehiclesByTeam = [];
foreach ($vehicles as $v) {
    $vehiclesByTeam[$v['team_id'] ?? 0][] = $v;
}
$teamSpeed = function (int $tid) use ($vehiclesByTeam): array {
    $list = $vehiclesByTeam[$tid] ?? [];
    $dist = array_sum(array_column($list, 'distance_km'));
    $drive = array_sum(array_column($list, 'drive_seconds'));
    $max = $list ? max(array_map(fn($v) => $v['max_kmh'] ?? 0, $list)) : 0;
    return ['distance' => round($dist, 1), 'avg' => $drive >= 120 && $dist > 0 ? round($dist / ($drive / 3600), 1) : null, 'max' => $max ?: null];
};
$carName = fn(array $v) => trim($v['label'] . ($v['plate'] ? ' · ' . $v['plate'] : ''));
$teamName = fn($id) => $id && isset($teamById[(int) $id]) ? $teamById[(int) $id]['name'] : 'No team';

// Event highlights
$done = count(array_filter($stationDone));
$moving = array_values(array_filter($vehicles, fn($v) => $v['max_kmh'] !== null));
usort($moving, fn($a, $b) => $b['max_kmh'] <=> $a['max_kmh']);
$fastest = $moving[0] ?? null;
$avgList = array_values(array_filter($vehicles, fn($v) => $v['avg_kmh'] !== null && $v['drive_seconds'] >= 600));
usort($avgList, fn($a, $b) => $b['avg_kmh'] <=> $a['avg_kmh']);
$bestAvg = $avgList[0] ?? null;
$totalKm = round(array_sum(array_column($vehicles, 'distance_km')), 1);
// Cars ranked by the complete distance each one travelled (start point → stations → back).
$byDistance = array_values(array_filter($vehicles, fn($v) => $v['distance_km'] > 0 || $v['plate']));
usort($byDistance, fn($a, $b) => $b['distance_km'] <=> $a['distance_km']);
$longest = $byDistance && $byDistance[0]['distance_km'] > 0 ? $byDistance[0] : null;
$allVisits = array_values($visitOf);
$avgTravel = avg_or_null(array_column($allVisits, 'travel'));
$avgStay = avg_or_null(array_column($allVisits, 'stay'));
$starts = array_filter(array_column($teams, 'started_at'));
$backs = array_filter(array_column($teams, 'returned_at'));

/* ------------------------------------------------------------------ CSV */
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sweets-drive-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads the file as UTF-8
    $t = fn($ts) => $ts ? date('H:i', (int) $ts) : '';
    $m = fn($s) => $s === null ? '' : round($s / 60);

    fputcsv($out, [setting('event_name') . ' – report ' . date('d M Y H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['ALL CARS: TOTAL DISTANCE TRAVELLED']);
    fputcsv($out, ['Car', 'Car number', 'Team', 'Driver', 'Total distance travelled (km)', 'Driving time (min)', 'Average speed (km/h)', 'Top speed (km/h)']);
    foreach ($byDistance as $v) {
        fputcsv($out, [$v['label'], $v['plate'], $teamName($v['team_id']), $v['driver'], $v['distance_km'], $m($v['drive_seconds']), $v['avg_kmh'] ?? '', $v['max_kmh'] ?? '']);
    }
    fputcsv($out, ['All cars together', '', '', '', $totalKm]);
    fputcsv($out, []);
    fputcsv($out, ['SECTION 1: TEAMS']);
    fputcsv($out, ['Team', 'Description', 'Leader', 'Started', 'Back at base', 'Total time (min)', 'Delivered', 'Total distance travelled (km)', 'Average speed (km/h)', 'Top speed (km/h)']);
    foreach ($teams as $tm) {
        [$visits, , ] = $visitsByTeam[(int) $tm['id']];
        $mine = $stopsByTeam[(int) $tm['id']];
        $sp = $teamSpeed((int) $tm['id']);
        fputcsv($out, [$tm['name'], $tm['notes'], trim($tm['leader_name'] . ' ' . $tm['leader_phone']), $t($tm['started_at']), $t($tm['returned_at']),
            $tm['started_at'] && $tm['returned_at'] ? $m((int) $tm['returned_at'] - (int) $tm['started_at']) : '',
            count(array_filter($mine, fn($s) => $s['status'] === 'done')) . '/' . count($mine), $sp['distance'], $sp['avg'] ?? '', $sp['max'] ?? '']);
    }
    fputcsv($out, []);
    fputcsv($out, ['Stations completed, in the order visited']);
    fputcsv($out, ['Team', 'Visit #', 'Station', 'Reached', 'Time to reach (min)', 'Time there (min)', 'Delivered', 'Marked by']);
    foreach ($teams as $tm) {
        foreach ($visitsByTeam[(int) $tm['id']][0] as $v) {
            fputcsv($out, [$tm['name'], $v['visit_no'], $v['name'], $t($v['reached_at']), $m($v['travel']), $m($v['stay']), $t($v['done_at']), $byLabels[$v['done_by'] ?: $v['reached_by']] ?? '']);
        }
    }
    fputcsv($out, []);
    fputcsv($out, ['Cars and speeds']);
    fputcsv($out, ['Team', 'Car', 'Car number', 'Driver', 'Total distance travelled (km)', 'Driving time (min)', 'Average speed (km/h)', 'Top speed (km/h)', 'Top speed at']);
    foreach ($vehicles as $v) {
        fputcsv($out, [$teamName($v['team_id']), $v['label'], $v['plate'], $v['driver'], $v['distance_km'], $m($v['drive_seconds']), $v['avg_kmh'] ?? '', $v['max_kmh'] ?? '', $t($v['max_at'])]);
    }
    fputcsv($out, []);
    fputcsv($out, ['SECTION 2: LOCATIONS']);
    fputcsv($out, ['Station', 'Area', 'Team', 'Status', 'Reached', 'Time to reach (min)', 'Time there (min)', 'Delivered', 'Marked by', 'Sweet boxes']);
    foreach ($locations as $s) {
        $v = $visitOf[$s['id'] . '-' . $s['team_id']] ?? null;
        fputcsv($out, [$s['name'] . ($s['shared'] !== '' ? ' (every team)' : ''), $s['area'], $teamName($s['team_id']), STATUS_LABELS[$s['status']] ?? $s['status'], $t($s['reached_at']),
            $v ? $m($v['travel']) : '', $v ? $m($v['stay']) : '', $t($s['done_at']), $byLabels[$s['done_by'] ?: $s['reached_by']] ?? '', $s['boxes']]);
    }
    exit;
}

/* ------------------------------------------- written document (print / PDF) */
if (isset($_GET['doc'])) {
    require APP_DIR . '/inc/report_doc.php';
    exit;
}

page_head('Report', 'report');
?>
<main class="wrap report">
  <div class="page-head">
    <div>
      <div class="eyebrow">Event report</div>
      <h1><?= h(setting('event_name')) ?></h1>
      <p><?= date('l, j F Y · g:i A') ?></p>
    </div>
    <div class="row no-print">
      <a class="btn" href="?doc=1" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> Written report</a>
      <a class="btn" href="?doc=1&amp;print=1" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Print / PDF</a>
      <a class="btn btn-primary" href="?csv=1"><i class="bi bi-file-earmark-spreadsheet"></i> Download for Excel</a>
    </div>
  </div>

  <section class="rep-hl">
    <div class="hl"><span class="ti ok"><i class="bi bi-check2-circle"></i></span><div><b><?= $done ?><small>/<?= count($stations) ?></small></b><span>Stations delivered</span></div></div>
    <div class="hl"><span class="ti info"><i class="bi bi-signpost-2"></i></span><div><b><?= $totalKm ?><small> km</small></b><span>Total distance, all cars</span></div></div>
    <div class="hl"><span class="ti info"><i class="bi bi-car-front"></i></span><div><b><?= $longest ? $longest['distance_km'] : '–' ?><small> km</small></b><span>Longest distance<?= $longest ? ' · ' . h($teamName($longest['team_id']) . ' · ' . $carName($longest)) : '' ?></span></div></div>
    <div class="hl"><span class="ti warn"><i class="bi bi-speedometer2"></i></span><div><b><?= $fastest ? (int) $fastest['max_kmh'] : '–' ?><small> km/h</small></b><span>Top speed<?= $fastest ? ' · ' . h($teamName($fastest['team_id']) . ' · ' . $carName($fastest)) : '' ?></span></div></div>
    <div class="hl"><span class="ti pend"><i class="bi bi-activity"></i></span><div><b><?= $bestAvg ? $bestAvg['avg_kmh'] : '–' ?><small> km/h</small></b><span>Best average<?= $bestAvg ? ' · ' . h($teamName($bestAvg['team_id']) . ' · ' . $carName($bestAvg)) : '' ?></span></div></div>
    <div class="hl"><span class="ti info"><i class="bi bi-stopwatch"></i></span><div><b><?= h(dur($avgTravel)) ?></b><span>Average time to reach a station</span></div></div>
    <div class="hl"><span class="ti ok"><i class="bi bi-hourglass-split"></i></span><div><b><?= h(dur($avgStay)) ?></b><span>Average time at a station</span></div></div>
    <?php if ($starts): ?>
    <div class="hl"><span class="ti warn"><i class="bi bi-flag"></i></span><div><b><?= fmt_time((int) min($starts)) ?></b><span>First team left</span></div></div>
    <div class="hl"><span class="ti ok"><i class="bi bi-house-check"></i></span><div><b><?= $backs ? fmt_time((int) max($backs)) : '–' ?></b><span>Last team back</span></div></div>
    <?php endif; ?>
  </section>

  <?php if ($byDistance): $maxKm = max(0.1, $byDistance[0]['distance_km']); ?>
  <section class="card" style="margin-top:14px">
    <div class="card-head"><h2><i class="bi bi-car-front"></i> Distance travelled by each car</h2><span class="muted small">All cars together: <b><?= $totalKm ?> km</b></span></div>
    <div class="table-wrap">
      <table class="list cards">
        <thead><tr><th>Car</th><th>Car number</th><th>Total distance travelled</th><th>Average speed</th><th>Top speed</th></tr></thead>
        <tbody>
        <?php foreach ($byDistance as $v): $tm = $v['team_id'] ? ($teamById[$v['team_id']] ?? null) : null; ?>
          <tr>
            <td class="cell-main"><b><?= h($v['label']) ?></b><div class="small muted"><span class="dot" style="--c:<?= h($tm['color'] ?? '#94a3b8') ?>;width:8px;height:8px"></span> <?= h($teamName($v['team_id'])) ?><?= $v['driver'] ? ' · ' . h($v['driver']) : '' ?></div></td>
            <td data-label="Car number"><?= $v['plate'] ? '<span class="plate">' . h($v['plate']) . '</span>' : '<span class="muted">–</span>' ?></td>
            <td data-label="Total distance travelled" class="dist-cell"><b><?= $v['distance_km'] ?></b> km<?= $v['drive_seconds'] ? ' <span class="tiny muted">· ' . h(dur($v['drive_seconds'])) . ' driving</span>' : '' ?><div class="dist-bar"><i style="width:<?= round(100 * $v['distance_km'] / $maxKm) ?>%;background:<?= h($tm['color'] ?? '#94a3b8') ?>"></i></div></td>
            <td data-label="Average speed"><?= $v['avg_kmh'] !== null ? $v['avg_kmh'] . ' km/h' : '–' ?></td>
            <td data-label="Top speed"><?= $v['max_kmh'] !== null ? (int) $v['max_kmh'] . ' km/h' : '–' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <nav class="filters no-print" style="margin:18px 0 6px">
    <a class="chip on" href="#teams"><i class="bi bi-people"></i> 1. Teams</a>
    <a class="chip" href="#locations"><i class="bi bi-geo-alt"></i> 2. Locations</a>
  </nav>

  <!-- ============================================================ SECTION 1: TEAMS -->
  <h2 class="rep-h" id="teams"><span>1</span> Teams</h2>
  <?php if (!$teams): ?><div class="card empty">No teams yet.</div><?php endif; ?>
  <?php foreach ($teams as $tm): $tid = (int) $tm['id']; [$visits, $notVisited, $back] = $visitsByTeam[$tid];
      $mine = $stopsByTeam[$tid];
      $doneN = count(array_filter($mine, fn($s) => $s['status'] === 'done'));
      $sp = $teamSpeed($tid); $cars = $vehiclesByTeam[$tid] ?? []; ?>
  <article class="card rep-team" style="--c:<?= h($tm['color']) ?>">
    <header class="rep-team-head">
      <span class="tc-av"><?= h(mb_strtoupper(mb_substr(trim(preg_replace('/^team\s+/i', '', $tm['name'])), 0, 2))) ?></span>
      <div class="st-main">
        <h3><?= h($tm['name']) ?></h3>
        <div class="small muted"><?= h($tm['notes'] ?: '') ?><?= $tm['leader_name'] ? ($tm['notes'] ? ' · ' : '') . 'Leader ' . h($tm['leader_name']) . ($tm['leader_phone'] ? ' · ' . h($tm['leader_phone']) : '') : '' ?></div>
      </div>
      <?= team_status_pill($tm['status']) ?>
    </header>

    <div class="rep-stats">
      <div><span>Started</span><b><?= fmt_time($tm['started_at'] ? (int) $tm['started_at'] : null) ?: '–' ?></b></div>
      <div><span>Back at base</span><b><?= fmt_time($tm['returned_at'] ? (int) $tm['returned_at'] : null) ?: '–' ?></b></div>
      <div><span>Total time</span><b><?= $tm['started_at'] && $tm['returned_at'] ? h(fmt_duration((int) $tm['returned_at'] - (int) $tm['started_at'])) : '–' ?></b></div>
      <div><span>Delivered</span><b><?= $doneN ?>/<?= count($mine) ?></b></div>
      <div><span>Total distance</span><b><?= $sp['distance'] ?> km</b></div>
      <div><span>Average speed</span><b><?= $sp['avg'] !== null ? $sp['avg'] . ' km/h' : '–' ?></b></div>
      <div><span>Top speed</span><b><?= $sp['max'] !== null ? $sp['max'] . ' km/h' : '–' ?></b></div>
    </div>

    <h4>Stations completed <span class="muted small">(in the order they were visited)</span></h4>
    <?php if (!$visits): ?>
      <p class="small muted">No station reached yet.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="list cards">
        <thead><tr><th>#</th><th>Station</th><th>Reached</th><th>Time to reach</th><th>Time there</th><th>Delivered</th></tr></thead>
        <tbody>
        <?php foreach ($visits as $v): ?>
          <tr>
            <td data-label="Visit"><span class="badge-num <?= h($v['status']) ?>" style="--c:<?= h($tm['color']) ?>"><?= (int) $v['visit_no'] ?></span></td>
            <td class="cell-main"><b><?= h($v['name']) ?></b><div class="small muted"><?= h($v['area']) ?><?= ' · planned stop #' . (int) $v['stop_no'] ?><?= $v['shared'] !== '' ? ' · every team' : '' ?></div></td>
            <td data-label="Reached"><?= fmt_time((int) $v['reached_at']) ?></td>
            <td data-label="Time to reach"><b><?= h(dur($v['travel'])) ?></b></td>
            <td data-label="Time there"><?= h(dur($v['stay'])) ?></td>
            <td data-label="Delivered"><?= $v['done_at'] ? '<b class="ok-text"><i class="bi bi-check2-circle"></i> ' . fmt_time((int) $v['done_at']) . '</b>' : status_pill($v['status']) ?><div class="tiny muted">by <?= h(strtolower($byLabels[$v['done_by'] ?: $v['reached_by']] ?? '–')) ?></div></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($back !== null): ?>
          <tr class="rep-back"><td data-label="Visit"><i class="bi bi-house-check"></i></td><td class="cell-main"><b>Back to base</b></td><td data-label="Reached"><?= fmt_time((int) $tm['returned_at']) ?></td><td data-label="Time to reach"><b><?= h(dur($back)) ?></b></td><td></td><td></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($notVisited): ?>
      <p class="small" style="margin-top:8px"><b>Not reached:</b> <?= h(implode(', ', array_column($notVisited, 'name'))) ?></p>
    <?php endif; ?>

    <h4>Cars and speeds</h4>
    <?php if (!$cars): ?>
      <p class="small muted">No car or phone recorded for this team.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="list cards">
        <thead><tr><th>Car</th><th>Car number</th><th>Total distance travelled</th><th>Average speed</th><th>Top speed</th></tr></thead>
        <tbody>
        <?php foreach ($cars as $v): $isTop = $fastest && $fastest['key'] === $v['key']; ?>
          <tr>
            <td class="cell-main"><b><i class="bi bi-car-front"></i> <?= h($v['label']) ?></b><div class="small muted"><?= h(implode(' · ', array_filter([$v['driver'] ? 'Driver: ' . $v['driver'] : '', $v['phones'] ? implode(', ', $v['phones']) : '']))) ?></div></td>
            <td data-label="Car number"><?= $v['plate'] ? '<span class="plate">' . h($v['plate']) . '</span>' : '<span class="muted">–</span>' ?></td>
            <td data-label="Total distance travelled"><b><?= $v['distance_km'] ?></b> km<?php if ($v['drive_seconds']): ?><div class="tiny muted"><?= h(dur($v['drive_seconds'])) ?> driving</div><?php endif; ?></td>
            <td data-label="Average speed"><?= $v['avg_kmh'] !== null ? '<b>' . $v['avg_kmh'] . '</b> km/h' : '–' ?></td>
            <td data-label="Top speed"><?= $v['max_kmh'] !== null ? '<b>' . (int) $v['max_kmh'] . '</b> km/h' . ($v['max_at'] ? ' <span class="muted small">at ' . fmt_time((int) $v['max_at']) . '</span>' : '') . ($isTop ? ' <span class="pill reached plain">Fastest</span>' : '') : '–' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>

  <?php $loose = $vehiclesByTeam[0] ?? []; if ($loose): ?>
    <p class="small muted">Phones not in any team: <?= h(implode(', ', array_column($loose, 'label'))) ?>.</p>
  <?php endif; ?>

  <!-- ============================================================ SECTION 2: LOCATIONS -->
  <h2 class="rep-h" id="locations"><span>2</span> Locations</h2>
  <div class="card">
    <div class="table-wrap">
      <table class="list cards">
        <thead><tr><th>Station</th><th>Team</th><th>Reached</th><th>Time to reach</th><th>Time there</th><th>Delivered</th></tr></thead>
        <tbody>
        <?php foreach ($locations as $s): $v = $visitOf[$s['id'] . '-' . $s['team_id']] ?? null; $tm = $s['team_id'] ? ($teamById[(int) $s['team_id']] ?? null) : null; ?>
          <tr>
            <td class="cell-main"><b><?= h($s['name']) ?></b><div class="small muted"><?= h($s['area']) ?><?= $s['shared'] !== '' ? ' · every team stops here' : '' ?><?= $s['boxes'] ? ' · ' . (int) $s['boxes'] . ' boxes' : '' ?></div></td>
            <td data-label="Team"><?= $tm ? '<span class="nowrap"><span class="dot" style="--c:' . h($tm['color']) . '"></span> ' . h($tm['name']) . '</span>' : '<span class="muted">No team</span>' ?></td>
            <td data-label="Reached"><?= fmt_time($s['reached_at'] ? (int) $s['reached_at'] : null) ?: '–' ?></td>
            <td data-label="Time to reach"><?= h($v ? dur($v['travel']) : '–') ?></td>
            <td data-label="Time there"><b><?= h($v ? dur($v['stay']) : '–') ?></b></td>
            <td data-label="Delivered"><?= $s['done_at'] ? '<b class="ok-text"><i class="bi bi-check2-circle"></i> ' . fmt_time((int) $s['done_at']) . '</b>' : status_pill($s['status']) ?><?php if ($s['done_by'] ?: $s['reached_by']): ?><div class="tiny muted">by <?= h(strtolower($byLabels[$s['done_by'] ?: $s['reached_by']] ?? '')) ?></div><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <p class="small muted" style="margin-top:12px">Time to reach = from leaving the previous stop (or the start point) to arriving. Speeds come from the phones' GPS; GPS glitches over <?= MAX_PLAUSIBLE_KMH ?> km/h and parked jitter are ignored. Average speed counts driving time only, not time parked at stations.</p>
</main>
<?php page_foot(); ?>
