<?php
/** Team leader console (phone-first). The leader's phone also shares the team's location from here. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

$team = require_team();
page_head($team['name'], 'team', ['map' => true, 'app' => [
    'teamId'     => (int) $team['id'],
    'leaderName' => $team['leader_name'],
]]);
?>
<main class="console" style="--c:<?= h($team['color']) ?>">
  <section class="hero">
    <div class="row-between">
      <div><h1><?= h($team['name']) ?></h1><?php if ($team['notes']): ?><div class="small muted"><?= h($team['notes']) ?></div><?php endif; ?></div>
      <span class="pill" id="team-status">…</span>
    </div>
    <div class="bar" id="hero-bar"></div>
    <div class="hero-stats">
      <div><b id="n-done">–</b><span>Delivered</span></div>
      <div><b id="n-left">–</b><span>Left</span></div>
      <div><b id="n-eta">–</b><span id="eta-label">Back by</span></div>
    </div>
  </section>

  <section class="share-bar" id="share-bar" style="margin-top:12px">
    <span class="si"><i class="bi bi-geo-alt"></i></span>
    <div class="st-main">
      <b id="share-title">Location sharing is off</b>
      <div class="st-sub" id="share-sub">Tap Start so everyone can see where your team is.</div>
      <select id="share-car" class="hidden" style="margin-top:8px;min-height:34px;padding:4px 8px" aria-label="Which car is this phone in?"></select>
    </div>
    <button class="btn btn-primary" id="share-toggle" type="button">Start</button>
  </section>

  <div id="primary" style="margin-top:12px"></div>

  <section class="card next-card" id="next" style="margin-top:12px"></section>

  <div class="section-title"><span>Map</span></div>
  <div class="console-map" id="map"></div>

  <div class="section-title"><span>Your stations</span><span class="tiny">Suggested order · go in any order</span></div>
  <div class="st-list" id="my-stations"></div>
  <a class="btn btn-block" id="route-btn" target="_blank" rel="noopener" style="margin-top:10px"><i class="bi bi-sign-turn-right"></i> Open remaining route in Google Maps</a>

  <section class="card" style="margin-top:14px">
    <h3><i class="bi bi-clock"></i> When will you be back at base?</h3>
    <div class="input-group">
      <input type="time" id="eta-input" aria-label="Expected return time">
      <button class="btn btn-primary" id="eta-save" type="button">Save</button>
    </div>
    <div class="hint" id="eta-hint">Everyone on the live map sees this time.</div>
  </section>

  <div class="section-title"><span>Phones sharing for your team</span></div>
  <div class="st-list" id="my-phones"></div>

  <div class="section-title"><span>Latest updates</span></div>
  <div class="card" style="padding:8px 12px"><div class="feed" id="feed"></div></div>
  <p class="small muted" style="text-align:center;margin-top:16px">Keep this page open while driving. Add it to your home screen for one-tap access.</p>
</main>
<?php page_foot(['team.js']); ?>
