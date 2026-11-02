<?php
/** Share this phone's location from the browser (backup for phones without the Traccar app). */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

$token = (string) ($_GET['t'] ?? '');
$device = $token !== '' ? row('SELECT d.*, t.name AS team_name, t.color, c.label AS car_label FROM devices d
    LEFT JOIN teams t ON t.id = d.team_id LEFT JOIN cars c ON c.id = d.car_id WHERE d.token = ?', [$token]) : null;

page_head('Share location', '', ['bare' => true, 'app' => ['token' => $token]]);
?>
<main class="share">
<?php if (!$device): ?>
  <div class="card"><h1>Link not valid</h1><p class="muted">This sharing link doesn't exist any more. Ask your team leader or the admin for a new one.</p></div>
<?php else: ?>
  <div class="row" style="justify-content:center;gap:8px">
    <span class="dot" style="--c:<?= h($device['color'] ?: '#64748b') ?>;width:14px;height:14px"></span>
    <b style="font-size:18px"><?= h($device['team_name'] ?: 'No team yet') ?></b>
  </div>
  <div class="muted"><?= h($device['car_label'] ? $device['car_label'] . ' · ' : '') ?><?= h($device['name']) ?></div>

  <button class="share-btn" id="toggle" type="button">Start sharing</button>

  <div class="card" style="text-align:left">
    <dl class="kv">
      <dt>Status</dt><dd id="st">Not sharing</dd>
      <dt>Last sent</dt><dd id="last">–</dd>
      <dt>Accuracy</dt><dd id="acc">–</dd>
      <dt>Sent</dt><dd id="count">0 updates</dd>
    </dl>
  </div>
  <div class="notice" style="margin-top:14px;text-align:left">
    <b>Keep this page open</b> with the screen on. The phone stops sending if you lock the screen or switch to another app.
    For all-day tracking with the screen off, use the <b>Traccar Client</b> app instead (see <a href="<?= h(app_url('help.php')) ?>">Help</a>).
  </div>
  <p class="small muted" style="margin-top:14px"><a href="<?= h(app_url('index.php')) ?>">Open the live map</a></p>
<?php endif; ?>
</main>
<?php if ($device): ?>
<script>
(function () {
  const btn = document.getElementById('toggle');
  const $ = (id) => document.getElementById(id);
  let watch = null, wake = null, lastSent = 0, pending = null, sent = 0, battery = null;
  const MIN_GAP = 10000;

  if (navigator.getBattery) navigator.getBattery().then((b) => { battery = b; }).catch(() => {});

  async function keepAwake() {
    try { if ('wakeLock' in navigator) wake = await navigator.wakeLock.request('screen'); } catch (e) { /* not supported */ }
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && watch !== null) keepAwake(); });

  async function send(pos) {
    const now = Date.now();
    if (now - lastSent < MIN_GAP) { pending = pos; return; }
    lastSent = now;
    pending = null;
    const c = pos.coords;
    try {
      const res = await fetch(APP.api + 'location.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ t: APP.token, lat: c.latitude, lng: c.longitude, acc: c.accuracy, speed: c.speed, heading: c.heading, ts: pos.timestamp,
          battery: battery ? Math.round(battery.level * 100) : null, charging: battery ? battery.charging : null }),
      });
      const j = await res.json();
      if (!res.ok) throw new Error(j.error || 'Failed');
      sent++;
      $('st').textContent = 'Sharing ✓';
      $('last').textContent = FST.time(Math.floor(now / 1000));
      $('acc').textContent = Math.round(c.accuracy) + ' m';
      $('count').textContent = sent + ' updates';
    } catch (e) {
      $('st').textContent = 'Could not send: ' + e.message + ' (will retry)';
      lastSent = 0;
    }
  }
  setInterval(() => { if (pending) send(pending); }, 3000);
  setInterval(() => { if (watch !== null && !document.hidden) navigator.geolocation.getCurrentPosition(send, () => {}, { enableHighAccuracy: true, maximumAge: 0, timeout: 25000 }); }, 30000);

  function start() {
    if (!('geolocation' in navigator)) { FST.toast('This browser cannot share location.', 'error'); return; }
    $('st').textContent = 'Waiting for GPS…';
    watch = navigator.geolocation.watchPosition(send, (err) => {
      $('st').textContent = err.code === 1 ? 'Location permission denied. Allow location for this site in browser settings.' : 'GPS error: ' + err.message;
    }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 30000 });
    keepAwake();
    btn.textContent = 'Sharing… tap to stop';
    btn.classList.add('on');
  }
  function stop() {
    if (watch !== null) navigator.geolocation.clearWatch(watch);
    watch = null;
    if (wake) { wake.release().catch(() => {}); wake = null; }
    btn.textContent = 'Start sharing';
    btn.classList.remove('on');
    $('st').textContent = 'Stopped';
  }
  btn.addEventListener('click', () => (watch === null ? start() : stop()));
  window.addEventListener('beforeunload', (e) => { if (watch !== null) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php endif; ?>
<?php page_foot(); ?>
