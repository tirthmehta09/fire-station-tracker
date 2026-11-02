<?php
/** Team member: share this phone's location (registered under the member's name and mobile) and follow the team. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

[$team, $device] = require_member();
$server = absolute_url('api/traccar.php');
page_head('My phone', 'member', ['map' => true, 'app' => [
    'teamId'      => (int) $team['id'],
    'deviceId'    => (int) $device['id'],
    'deviceToken' => $device['token'],
]]);
?>
<main class="console" style="--c:<?= h($team['color']) ?>">
  <section class="hero">
    <div class="row-between">
      <div>
        <div class="eyebrow"><span class="dot" style="--c:<?= h($team['color']) ?>"></span> <?= h($team['name']) ?> · Member</div>
        <h1 style="margin-top:4px">Hi <?= h(explode(' ', trim($device['name']))[0] ?: $device['name']) ?></h1>
        <div class="small muted"><?= h($device['name']) ?> · <?= h($device['person_phone']) ?></div>
      </div>
      <span class="pill" id="team-status">…</span>
    </div>
    <div class="bar" id="hero-bar" style="margin-top:12px"></div>
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
      <div class="st-sub" id="share-sub">Tap Start so the organisers can see your car.</div>
      <select id="share-car" class="hidden" style="margin-top:8px" aria-label="Which car are you in?"></select>
    </div>
    <button class="btn btn-primary" id="share-toggle" type="button">Start</button>
  </section>

  <details class="box" style="margin-top:12px" id="app-box">
    <summary><i class="bi bi-phone"></i> Keep tracking with the screen off <span class="pill plain pending" id="app-state" style="margin-left:6px">Not set up</span></summary>
    <div class="box-body">
      <p class="small">This page stops sharing when the screen locks. For all-day tracking, also install the free <b>Traccar Client</b> app and enter these two things. It then counts as this same phone.</p>
      <ol class="steps small">
        <li>Install: <a href="https://play.google.com/store/apps/details?id=org.traccar.client" target="_blank" rel="noopener">Android</a> · <a href="https://apps.apple.com/app/traccar-client/id843156974" target="_blank" rel="noopener">iPhone</a></li>
        <li><b>Device identifier</b>
          <div class="kbd-copy" style="margin-top:4px"><code><?= h($device['identifier']) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h($device['identifier']) ?>">Copy</button></div></li>
        <li><b>Server URL</b>
          <div class="kbd-copy" style="margin-top:4px"><code><?= h($server) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h($server) ?>">Copy</button></div></li>
        <li>Location accuracy <b>Highest</b>, Distance <b>0</b>, Interval <b>15</b>, Offline buffering <b>On</b>, then switch tracking <b>On</b> and allow location <b>all the time</b>.</li>
      </ol>
    </div>
  </details>

  <div class="section-title"><span>Your team on the map</span></div>
  <div class="console-map" id="map"></div>

  <div class="section-title"><span>Your team's stations</span><span class="tiny">Your leader marks deliveries</span></div>
  <div class="st-list" id="my-stations"></div>

  <div class="section-title"><span>Latest updates</span></div>
  <div class="card" style="padding:8px 12px"><div class="feed" id="feed"></div></div>
</main>
<?php page_foot(['member.js']); ?>
