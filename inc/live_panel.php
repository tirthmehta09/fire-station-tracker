<?php
/** Full-screen live map with a floating glass panel (desktop) / pull-up sheet (phones). Filled in by assets/live.js. */
defined('APP_DIR') || exit;
$adminView = is_admin() && basename($_SERVER['SCRIPT_NAME']) === 'admin.php';
?>
<main class="live-layout" id="live-layout">
  <div class="live-map">
    <div id="map" role="application" aria-label="Live map"></div>
    <div class="map-toast" id="map-toast" aria-live="polite"></div>
  </div>
  <aside class="live-panel glass" id="panel">
    <div class="sheet-handle" id="sheet-handle" role="button" tabindex="0" aria-label="Show more"><span></span></div>
    <section class="hero-live" id="hero-live">
      <div class="eyebrow map-status" id="live-status"><span class="live-dot"></span><span class="status-text">Connecting…</span></div>
      <h1 class="grad-text"><?= h(setting('event_name')) ?></h1>
      <p class="sub"><?= h(setting('event_subtitle')) ?></p>
      <div class="sum" id="summary"></div>
    </section>
    <?php if ($adminView): ?><div id="alerts"></div><?php endif; ?>
    <div class="section-title"><span>Teams</span><span class="tiny">Tap a team to focus</span></div>
    <div id="teams"></div>
    <div class="section-title"><span>Stations <span id="st-count" class="tiny"></span></span></div>
    <div class="filters" style="margin-bottom:10px">
      <button class="chip on" data-f="all">All</button>
      <button class="chip" data-f="pending">Pending</button>
      <button class="chip" data-f="reached">Reached</button>
      <button class="chip" data-f="done">Delivered</button>
    </div>
    <div class="st-list" id="stations"></div>
    <?php if ($adminView): ?>
      <div class="section-title"><span>Phones</span><a class="tiny" href="<?= h(app_url('teams.php')) ?>">Manage</a></div>
      <div class="st-list" id="phones"></div>
    <?php endif; ?>
    <div class="section-title"><span>Latest updates</span></div>
    <div class="card" style="padding:8px 14px"><div class="feed" id="feed"></div></div>
  </aside>
</main>
