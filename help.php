<?php
/** Volunteer guide: share it in the WhatsApp group. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

start_session(true);
$server = absolute_url('api/traccar.php');
$login = absolute_url('login.php');
page_head('Help', 'help');
?>
<main class="wrap wrap-narrow">
  <div class="page-head"><div><h1>Volunteer guide</h1><p><?= h(setting('event_name')) ?></p></div></div>

  <section class="card">
    <h2><i class="bi bi-flag"></i> 1. Team leader: your phone shows where the team is</h2>
    <ol class="steps">
      <li>Open <a href="<?= h($login) ?>"><?= h($login) ?></a>, choose your team and enter the PIN the admin gave you.</li>
      <li>Tap <b>Start</b> on the “Location sharing” bar and allow location. If your team has more than one car, pick the car you are in.</li>
      <li>Keep the page open while you drive (the screen stays on by itself). If you close it, open it again and sharing continues.</li>
      <li>You'll see your stations in the <b>suggested</b> order; go in any order you like. Tap <b>Navigate</b> for Google Maps directions.</li>
      <li>Arrival is detected on its own within about <?= (int) setting('arrival_radius') ?> m. If not, tap <b>Reached</b>. After handing over the sweets tap <b>Delivered</b>.</li>
      <li>Set <b>When will you be back at base?</b> and, back at <?= h(setting('base_name')) ?>, tap <b>We are back at base</b>.</li>
    </ol>
    <div class="notice info"><b>Install it like an app:</b> Android Chrome → menu ⋮ → <b>Install app</b> (or the <i class="bi bi-download"></i> button at the top). iPhone Safari → Share <i class="bi bi-box-arrow-up"></i> → <b>Add to Home Screen</b>. It then opens straight to your team page.</div>
  </section>

  <section class="card">
    <h2><i class="bi bi-person"></i> 2. Team members whose phone is tracked</h2>
    <ol class="steps">
      <li>Open <a href="<?= h(app_url('login.php?as=member')) ?>"><?= h(absolute_url('login.php?as=member')) ?></a> and choose the <b>Member</b> tab.</li>
      <li>Choose your team, type <b>your name</b> and <b>your mobile number</b>, and the <b>member PIN</b> your leader gives you.</li>
      <li>Pick the car you are in and tap <b>Start</b>. Keep the page open, or (better) also set up the Traccar app with the <b>personal ID</b> shown on your page so tracking continues with the screen off.</li>
    </ol>
  </section>

  <section class="card">
    <h2><i class="bi bi-phone"></i> 3. Traccar app (works with the screen off)</h2>
    <p class="small muted">Recommended for the driver's phone on the car charger, so the car stays on the map even if the leader's screen goes off.</p>
    <ol class="steps">
      <li>Install <b>Traccar Client</b>: <a href="https://play.google.com/store/apps/details?id=org.traccar.client" target="_blank" rel="noopener">Android</a> · <a href="https://apps.apple.com/app/traccar-client/id843156974" target="_blank" rel="noopener">iPhone</a>.</li>
      <li>Change these settings:
        <div class="table-wrap" style="margin-top:8px">
        <table class="list stack-sm">
          <tr><td><b>Device identifier</b></td><td>The ID the admin gives you (e.g. <code>car01</code>), or keep the number shown and send it to the admin</td></tr>
          <tr><td><b>Server URL</b></td><td><div class="kbd-copy"><code><?= h($server) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h($server) ?>">Copy</button></div></td></tr>
          <tr><td><b>Location accuracy</b></td><td>Highest</td></tr>
          <tr><td><b>Distance</b></td><td>0</td></tr>
          <tr><td><b>Interval</b></td><td>15</td></tr>
          <tr><td><b>Angle</b></td><td>30</td></tr>
          <tr><td><b>Offline buffering</b> / <b>Wake lock</b></td><td>On</td></tr>
          <tr><td><b>Stop detection</b></td><td>Off</td></tr>
        </table>
        </div>
      </li>
      <li>Turn the tracking switch <b>ON</b> and allow location <b>“Allow all the time”</b>. Android: Settings → Apps → Traccar Client → Battery → <b>Unrestricted</b>.</li>
      <li>Ask the admin to check your car shows <b>Live</b> before leaving.</li>
    </ol>
  </section>

  <section class="card">
    <h2><i class="bi bi-map"></i> 4. Everyone else</h2>
    <p>Watch the live map: <a href="<?= h(app_url('index.php')) ?>"><?= h(absolute_url('index.php')) ?></a>. Tap a team to see only its cars and stations, or tap a car to see the path it has driven.</p>
  </section>
</main>
<?php page_foot(); ?>
