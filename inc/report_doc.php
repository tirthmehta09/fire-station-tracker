<?php
/**
 * The report as a written document (A4, plain text and tables) for printing or saving as PDF.
 * Included by report.php?doc=1 after it has worked out all the numbers.
 */
defined('APP_DIR') || exit;

$n = fn(int $count, string $one, ?string $many = null) => $count . ' ' . ($count === 1 ? $one : ($many ?? $one . 's'));
$carFull = fn(array $v) => $teamName($v['team_id']) . "'s " . $v['label'] . ($v['plate'] ? ' (' . $v['plate'] . ')' : '');
$teamsOut = count(array_filter($teams, fn($t) => $t['started_at'] !== null));
$teamsBack = count(array_filter($teams, fn($t) => $t['status'] === 'returned'));
$notDone = array_values(array_filter($stations, fn($s) => empty($stationDone[(int) $s['id']])));
$unassigned = array_values(array_filter($stations, fn($s) => !$s['team_id'] && $s['shared'] === ''));
$event = setting('event_name');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($event) ?> – Event report</title>
<style>
  @page { size: A4; margin: 14mm 13mm 16mm; }
  * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  body { margin: 0; background: #ece8e1; color: #1f1b16; font: 10.5pt/1.55 "Segoe UI", "Helvetica Neue", Arial, sans-serif; }
  .sheet { max-width: 210mm; margin: 24px auto; background: #fff; padding: 16mm 15mm; box-shadow: 0 6px 30px rgba(0, 0, 0, .15); }
  .toolbar { max-width: 210mm; margin: 20px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
  .toolbar button, .toolbar a { font: 600 13px/1 inherit; padding: 10px 14px; border-radius: 8px; border: 1px solid #c9bfb0; background: #fff; color: #1f1b16; cursor: pointer; text-decoration: none; }
  .toolbar .primary { background: #e2660f; border-color: #c9550a; color: #fff; }

  header.doc-head { position: relative; background: linear-gradient(135deg, #fff3e3 0%, #ffe6cf 55%, #ffd9cf 100%); border-radius: 12px; padding: 16px 18px 14px 22px; margin-bottom: 18px; overflow: hidden; }
  header.doc-head::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 7px; background: linear-gradient(180deg, #f7a531, #ea580c 55%, #d6322b); }
  .kicker { font-size: 8.5pt; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; color: #c2410c; }
  h1 { font-size: 22pt; line-height: 1.12; margin: 3px 0 4px; letter-spacing: -.01em; color: #7c2d12; }
  .meta { color: #6b4a33; font-size: 9.3pt; }

  .figs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin: 4px 0 14px; }
  .fig { border-radius: 10px; padding: 9px 11px; border: 1px solid; break-inside: avoid; }
  .fig b { display: block; font-size: 15pt; line-height: 1.1; font-weight: 800; }
  .fig span { display: block; font-size: 7.8pt; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; margin-top: 3px; }
  .fig.green { background: #e9f7ef; border-color: #b9e3c9; color: #14653a; }
  .fig.blue { background: #e6f3f8; border-color: #b7dbe9; color: #0b5d75; }
  .fig.orange { background: #fff0e2; border-color: #f6c79c; color: #a8430a; }
  .fig.purple { background: #f1ecfb; border-color: #d3c5f1; color: #5b3aa6; }

  h2 { font-size: 13.5pt; margin: 22px 0 9px; padding-bottom: 5px; border-bottom: 2px solid #f0b27a; color: #9a3412; break-after: avoid; display: flex; align-items: center; gap: 8px; }
  h2 .no { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; background: #ea580c; color: #fff; font-size: 10pt; }
  h3 { font-size: 11.5pt; margin: 16px 0 5px; padding: 6px 10px; border-left: 5px solid var(--c, #ea580c); background: color-mix(in srgb, var(--c, #ea580c) 9%, #fff); border-radius: 0 8px 8px 0; break-after: avoid; }
  h3 .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: var(--c, #ea580c); margin-right: 6px; vertical-align: 1px; }
  h4 { font-size: 10pt; margin: 12px 0 5px; color: #7c2d12; break-after: avoid; }
  p { margin: 0 0 8px; text-align: justify; }
  b.hl { color: #b4410b; }
  table { width: 100%; border-collapse: collapse; margin: 4px 0 12px; font-size: 9pt; break-inside: auto; }
  thead { display: table-header-group; }
  tr { break-inside: avoid; }
  th, td { border: 1px solid #ecd9c6; padding: 4px 6px; text-align: left; vertical-align: top; }
  th { background: #fde8d4; color: #7c2d12; font-weight: 700; font-size: 8.3pt; }
  tbody tr:nth-child(even) td { background: #fffaf4; }
  td.num, th.num { text-align: right; white-space: nowrap; }
  td.ok { color: #15803d; font-weight: 700; }
  td.warn { color: #b45309; font-weight: 700; }
  td.pend { color: #8a8075; }
  tr.total td { font-weight: 700; background: #fff0e2 !important; color: #7c2d12; }
  tr.back td { font-weight: 700; background: #e9f7ef !important; color: #14653a; }
  table.kv th { width: 44%; }
  .team-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 5px; vertical-align: 1px; }
  table.keep { break-inside: avoid; }
  h2.new-page { break-before: page; page-break-before: always; margin-top: 0; }
  @media screen { h2.new-page { margin-top: 34px; padding-top: 22px; border-top: 1px dashed #d9c3ad; } }
  h3 + p { break-before: avoid; }
  .muted { color: #8a7b6c; }
  .note { font-size: 8.5pt; color: #6b6156; background: #faf6f0; border-left: 4px solid #f0b27a; border-radius: 0 8px 8px 0; padding: 8px 10px; margin-top: 18px; }
  @media print {
    body { background: #fff; }
    .sheet { margin: 0; padding: 0; box-shadow: none; max-width: none; }
    .toolbar { display: none; }
  }
  @media screen and (max-width: 640px) {
    body { font-size: 10pt; overflow-wrap: anywhere; }
    .toolbar { margin: 0; padding: 10px 12px; flex-wrap: wrap; justify-content: stretch; background: #fff; border-bottom: 1px solid #ecd9c6; }
    .toolbar > * { flex: 1 1 auto; text-align: center; }
    .sheet { margin: 0; padding: 18px 14px; box-shadow: none; }
    h1 { font-size: 18pt; }
    p { text-align: left; }
    .figs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    /* tables become one small card per row, each value with its column name */
    table, tbody, tr, td, th { display: block; width: auto !important; }
    table { border: 0; font-size: 9.5pt; }
    thead { display: none; }
    tbody tr { border: 1px solid #ecd9c6; border-radius: 10px; padding: 8px 10px; margin-bottom: 8px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 12px; background: #fff; }
    tbody tr:nth-child(even) td { background: none; }
    th, td { border: 0; padding: 0; text-align: left !important; white-space: normal !important; }
    td[data-label]::before { content: attr(data-label); display: block; font-size: 7.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #a8794f; }
    td:first-child { grid-column: 1 / -1; font-weight: 700; }
    td:first-child::before { display: none; }
    td:empty { display: none; }
    td.num:first-child::before { display: inline; content: "Visit "; font: inherit; text-transform: none; letter-spacing: 0; color: inherit; }
    tr.back td:first-child { display: none; }
    tr.total, tr.back { background: #fff0e2; }
    table.kv tr { grid-template-columns: 1fr; gap: 2px; }
    table.kv th { background: none; padding: 0; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .05em; color: #a8794f; }
    table.kv td:first-child { grid-column: auto; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <a href="<?= h(app_url('report.php')) ?>">← Back to report</a>
  <a href="<?= h(app_url('report.php?csv=1')) ?>">Download for Excel</a>
  <button class="primary" type="button" onclick="window.print()">Print / Save as PDF</button>
</div>
<div class="sheet">
  <header class="doc-head">
    <div class="kicker">Event report</div>
    <h1><?= h($event) ?></h1>
    <div class="meta"><?= h(setting('event_subtitle')) ?><br>Start and end point: <?= h(setting('base_name')) ?> · Report prepared on <?= date('l, j F Y \a\t g:i A') ?></div>
  </header>

  <div class="figs">
    <div class="fig green"><b><?= $done ?> / <?= count($stations) ?></b><span>Stations delivered</span></div>
    <div class="fig blue"><b><?= $totalKm ?> km</b><span>Total distance</span></div>
    <div class="fig orange"><b><?= $fastest ? (int) $fastest['max_kmh'] . ' km/h' : '–' ?></b><span>Top speed</span></div>
    <div class="fig purple"><b><?= h(dur($avgTravel)) ?></b><span>Avg time to reach</span></div>
  </div>

  <h2>Summary</h2>
  <p>
    <?= h($n(count($teams), 'team')) ?> took part in the event<?= $vehicles ? ', using ' . h($n(count(array_filter($vehicles, fn($v) => str_starts_with($v['key'], 'car'))), 'car')) : '' ?>.
    Sweets were delivered at <b class="hl"><?= $done ?> of <?= h($n(count($stations), 'fire station')) ?></b><?= $notDone ? ' (' . h($n(count($notDone), 'station')) . ' not completed)' : ', so every planned station was covered' ?>.
    <?php if ($starts): ?>
      The first team left the start point at <b class="hl"><?= fmt_time((int) min($starts)) ?></b><?= $backs ? ' and the last team was back at <b class="hl">' . fmt_time((int) max($backs)) . '</b>' : '' ?>.
      <?= $teamsBack === $teamsOut ? 'All teams that set out returned to base.' : h($n($teamsOut - $teamsBack, 'team')) . ' had not been marked back at base when this report was prepared.' ?>
    <?php else: ?>
      No team has started yet.
    <?php endif; ?>
  </p>
  <?php if ($totalKm > 0): ?>
  <p>
    Together the cars travelled <b class="hl"><?= $totalKm ?> km</b>.
    <?php if ($longest): ?>The longest distance was <b class="hl"><?= $longest['distance_km'] ?> km</b> by <?= h($carFull($longest)) ?>. <?php endif; ?>
    <?php if ($fastest): ?>The highest speed recorded was <b class="hl"><?= (int) $fastest['max_kmh'] ?> km/h</b> by <?= h($carFull($fastest)) ?><?= $fastest['max_at'] ? ' at ' . fmt_time((int) $fastest['max_at']) : '' ?>. <?php endif; ?>
    <?php if ($bestAvg): ?>The best average driving speed was <b class="hl"><?= $bestAvg['avg_kmh'] ?> km/h</b> by <?= h($carFull($bestAvg)) ?>.<?php endif; ?>
  </p>
  <?php endif; ?>
  <?php if ($avgTravel !== null || $avgStay !== null): ?>
  <p>On average a team took <b class="hl"><?= h(dur($avgTravel)) ?></b> to reach a station and spent <b class="hl"><?= h(dur($avgStay)) ?></b> there handing over the sweets.</p>
  <?php endif; ?>

  <h2>Key figures</h2>
  <table class="kv keep">
    <tbody>
      <tr><th style="width:45%">Stations delivered</th><td><?= $done ?> of <?= count($stations) ?></td></tr>
      <tr><th>Teams</th><td><?= count($teams) ?> (<?= $teamsBack ?> back at base)</td></tr>
      <tr><th>First team left / last team back</th><td><?= $starts ? fmt_time((int) min($starts)) : '–' ?> / <?= $backs ? fmt_time((int) max($backs)) : '–' ?></td></tr>
      <tr><th>Total distance, all cars</th><td><?= $totalKm ?> km</td></tr>
      <tr><th>Longest distance by one car</th><td><?= $longest ? $longest['distance_km'] . ' km – ' . h($carFull($longest)) : '–' ?></td></tr>
      <tr><th>Highest speed</th><td><?= $fastest ? (int) $fastest['max_kmh'] . ' km/h – ' . h($carFull($fastest)) : '–' ?></td></tr>
      <tr><th>Best average speed</th><td><?= $bestAvg ? $bestAvg['avg_kmh'] . ' km/h – ' . h($carFull($bestAvg)) : '–' ?></td></tr>
      <tr><th>Average time to reach a station</th><td><?= h(dur($avgTravel)) ?></td></tr>
      <tr><th>Average time at a station</th><td><?= h(dur($avgStay)) ?></td></tr>
    </tbody>
  </table>

  <?php if ($byDistance): ?>
  <h2>Distance travelled by each car</h2>
  <table class="<?= count($byDistance) <= 14 ? 'keep' : '' ?>">
    <thead><tr><th>Car</th><th>Car number</th><th>Team</th><th>Driver</th><th class="num">Total distance</th><th class="num">Driving time</th><th class="num">Average speed</th><th class="num">Top speed</th></tr></thead>
    <tbody>
    <?php foreach ($byDistance as $v): ?>
      <tr><td><?= h($v['label']) ?></td><td><?= h($v['plate'] ?: '–') ?></td><td><?php if ($v['team_id'] && isset($teamById[$v['team_id']])): ?><span class="team-dot" style="background:<?= h($teamById[$v['team_id']]['color']) ?>"></span><?php endif; ?><?= h($teamName($v['team_id'])) ?></td><td><?= h($v['driver'] ?: '–') ?></td>
        <td class="num"><?= $v['distance_km'] ?> km</td><td class="num"><?= h($v['drive_seconds'] ? dur($v['drive_seconds']) : '–') ?></td>
        <td class="num"><?= $v['avg_kmh'] !== null ? $v['avg_kmh'] . ' km/h' : '–' ?></td><td class="num"><?= $v['max_kmh'] !== null ? (int) $v['max_kmh'] . ' km/h' : '–' ?></td></tr>
    <?php endforeach; ?>
      <tr class="total"><td colspan="4">All cars together</td><td class="num"><?= $totalKm ?> km</td><td colspan="3"></td></tr>
    </tbody>
  </table>
  <?php endif; ?>

  <h2 class="new-page"><span class="no">1</span> Teams</h2>
  <?php if (!$teams): ?><p class="muted">No teams were set up.</p><?php endif; ?>
  <?php foreach ($teams as $i => $tm): $tid = (int) $tm['id']; [$visits, $notVisited, $back] = $visitsByTeam[$tid];
      $mine = $stopsByTeam[$tid];
      $doneN = count(array_filter($mine, fn($s) => $s['status'] === 'done'));
      $sp = $teamSpeed($tid); $cars = $vehiclesByTeam[$tid] ?? []; ?>
  <section class="team-block" style="--c:<?= h($tm['color']) ?>">
    <h3><span class="dot"></span>1.<?= $i + 1 ?> <?= h($tm['name']) ?></h3>
    <p>
      <?php if ($tm['notes']): ?><?= h($tm['notes']) ?>. <?php endif; ?>
      <?php if ($tm['leader_name']): ?>Team leader: <?= h($tm['leader_name']) ?><?= $tm['leader_phone'] ? ' (' . h($tm['leader_phone']) . ')' : '' ?>. <?php endif; ?>
      <?php if ($tm['started_at']): ?>
        The team left at <?= fmt_time((int) $tm['started_at']) ?><?= $tm['returned_at'] ? ' and was back at base at ' . fmt_time((int) $tm['returned_at']) . ', a total of ' . h(fmt_duration((int) $tm['returned_at'] - (int) $tm['started_at'])) : ' and had not been marked back at base' ?>.
      <?php else: ?>
        The team did not start.
      <?php endif; ?>
      It delivered sweets at <?= $doneN ?> of its <?= h($n(count($mine), 'station')) ?><?= $notVisited ? ' (not reached: ' . h(implode(', ', array_column($notVisited, 'name'))) . ')' : '' ?>.
      <?php if ($sp['distance'] > 0): ?>
        Its cars travelled <?= $sp['distance'] ?> km<?= $sp['avg'] !== null ? ' at an average of ' . $sp['avg'] . ' km/h' : '' ?><?= $sp['max'] !== null ? ', with a top speed of ' . $sp['max'] . ' km/h' : '' ?>.
      <?php endif; ?>
    </p>

    <?php if ($visits): ?>
    <h4>Stations completed, in the order visited</h4>
    <table>
      <thead><tr><th class="num">#</th><th>Fire station</th><th>Reached</th><th class="num">Time to reach</th><th class="num">Time there</th><th>Delivered</th><th>Marked by</th></tr></thead>
      <tbody>
      <?php foreach ($visits as $v): ?>
        <tr><td class="num"><?= (int) $v['visit_no'] ?></td><td><?= h($v['name']) ?><?= $v['area'] ? ' <span class="muted">(' . h($v['area']) . ')</span>' : '' ?></td>
          <td><?= fmt_time((int) $v['reached_at']) ?></td><td class="num"><?= h(dur($v['travel'])) ?></td><td class="num"><?= h(dur($v['stay'])) ?></td>
          <td class="<?= $v['done_at'] ? 'ok' : 'warn' ?>"><?= $v['done_at'] ? fmt_time((int) $v['done_at']) : h(STATUS_LABELS[$v['status']] ?? '') ?></td><td><?= h($byLabels[$v['done_by'] ?: $v['reached_by']] ?? '–') ?></td></tr>
      <?php endforeach; ?>
      <?php if ($back !== null): ?>
        <tr class="back"><td class="num">⌂</td><td>Back to base</td><td><?= fmt_time((int) $tm['returned_at']) ?></td><td class="num"><?= h(dur($back)) ?></td><td colspan="3"></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($cars): ?>
    <h4>Cars and speeds</h4>
    <table class="keep">
      <thead><tr><th>Car</th><th>Car number</th><th>Driver</th><th class="num">Total distance</th><th class="num">Driving time</th><th class="num">Average speed</th><th class="num">Top speed</th></tr></thead>
      <tbody>
      <?php foreach ($cars as $v): ?>
        <tr><td><?= h($v['label']) ?></td><td><?= h($v['plate'] ?: '–') ?></td><td><?= h($v['driver'] ?: '–') ?></td><td class="num"><?= $v['distance_km'] ?> km</td>
          <td class="num"><?= h($v['drive_seconds'] ? dur($v['drive_seconds']) : '–') ?></td><td class="num"><?= $v['avg_kmh'] !== null ? $v['avg_kmh'] . ' km/h' : '–' ?></td>
          <td class="num"><?= $v['max_kmh'] !== null ? (int) $v['max_kmh'] . ' km/h' . ($v['max_at'] ? ' at ' . fmt_time((int) $v['max_at']) : '') : '–' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <h2 class="new-page"><span class="no">2</span> Locations</h2>
  <p>Every fire station on the plan, with the team that went there and how long it took.<?= $unassigned ? ' ' . h($n(count($unassigned), 'station')) . ' had no team assigned.' : '' ?></p>
  <table>
    <thead><tr><th>Fire station</th><th>Area</th><th>Team</th><th>Reached</th><th class="num">Time to reach</th><th class="num">Time there</th><th>Delivered</th><th>Marked by</th></tr></thead>
    <tbody>
    <?php foreach ($locations as $s): $v = $visitOf[$s['id'] . '-' . $s['team_id']] ?? null; ?>
      <tr><td><?= h($s['name']) ?><?= $s['shared'] !== '' ? ' <span class="muted">(every team)</span>' : '' ?></td><td><?= h($s['area'] ?: '–') ?></td><td><?php if ($s['team_id'] && isset($teamById[(int) $s['team_id']])): ?><span class="team-dot" style="background:<?= h($teamById[(int) $s['team_id']]['color']) ?>"></span><?php endif; ?><?= h($teamName($s['team_id'])) ?></td>
        <td><?= $s['reached_at'] ? fmt_time((int) $s['reached_at']) : '–' ?></td><td class="num"><?= h($v ? dur($v['travel']) : '–') ?></td><td class="num"><?= h($v ? dur($v['stay']) : '–') ?></td>
        <td class="<?= $s['done_at'] ? 'ok' : ($s['status'] === 'reached' ? 'warn' : 'pend') ?>"><?= $s['done_at'] ? fmt_time((int) $s['done_at']) : h(STATUS_LABELS[$s['status']] ?? '') ?></td><td><?= h($byLabels[$s['done_by'] ?: $s['reached_by']] ?? '–') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="note">
    How these figures are worked out: “Time to reach” runs from leaving the previous stop (or the start point) to arriving at the station; “Time there” runs from arriving to delivering.
    Distances and speeds come from the phones' GPS. Readings faster than <?= MAX_PLAUSIBLE_KMH ?> km/h (GPS errors) and small movements while parked are ignored,
    phones in the same car are counted once, and average speed counts driving time only. Arrivals were detected automatically within <?= (int) setting('arrival_radius') ?> m of each station or marked by the team leader or admin.
  </p>
</div>
<script>
// Label every table cell with its column name (used when the tables turn into cards on a phone screen).
document.querySelectorAll('table').forEach((t) => {
  const heads = [...t.querySelectorAll('thead th')].map((th) => th.textContent.trim());
  if (!heads.length) return;
  t.querySelectorAll('tbody tr').forEach((tr) => {
    let col = 0;
    [...tr.children].forEach((td) => {
      if (heads[col] && td.colSpan === 1) td.setAttribute('data-label', heads[col]);
      col += td.colSpan || 1;
    });
  });
});
</script>
<?php if (isset($_GET['print'])): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script><?php endif; ?>
</body>
</html>
