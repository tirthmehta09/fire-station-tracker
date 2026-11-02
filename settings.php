<?php
/** Admin: event details, start/end point, detection tuning, public map, password, reset after a test run. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';
require APP_DIR . '/inc/maps.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        foreach (['event_name' => 100, 'event_subtitle' => 150, 'base_name' => 150, 'base_link' => 500] as $k => $max) {
            set_setting($k, mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max));
        }
        $lat = trim((string) ($_POST['base_lat'] ?? ''));
        $lng = trim((string) ($_POST['base_lng'] ?? ''));
        if (!valid_coords($lat, $lng) && setting('base_link')) {
            $r = resolve_location((string) setting('base_link'));
            if ($r['ok']) {
                [$lat, $lng] = [$r['lat'], $r['lng']];
            }
        }
        if (valid_coords($lat, $lng)) {
            set_setting('base_lat', (string) (float) $lat);
            set_setting('base_lng', (string) (float) $lng);
        }
        $nums = ['arrival_radius' => [30, 1000], 'base_radius' => [50, 2000], 'min_dwell' => [0, 1800], 'avg_speed' => [5, 80], 'stop_minutes' => [0, 120]];
        foreach ($nums as $k => [$min, $max]) {
            if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
                set_setting($k, (string) max($min, min($max, (int) $_POST[$k])));
            }
        }
        foreach (['auto_done', 'public_enabled', 'public_trails', 'accept_new_devices'] as $k) {
            set_setting($k, !empty($_POST[$k]) ? '1' : '0');
        }
        @unlink(APP_DIR . '/data/cache_public.json');
        flash('Settings saved.');
        redirect('settings.php');
    }

    if ($do === 'password') {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, (string) setting('admin_hash', ''))) {
            flash('Current password is wrong.', 'error');
        } elseif (strlen($new) < 8) {
            flash('Use at least 8 characters.', 'error');
        } else {
            set_setting('admin_hash', password_hash($new, PASSWORD_DEFAULT));
            flash('Password changed.');
        }
        redirect('settings.php');
    }

    if ($do === 'reset') {
        if (trim((string) ($_POST['confirm'] ?? '')) !== 'RESET') {
            flash('Type RESET to confirm.', 'error');
            redirect('settings.php#reset');
        }
        q("UPDATE stations SET status = 'pending', reached_at = NULL, reached_by = NULL, done_at = NULL, done_by = NULL, near_since = NULL, near_device_id = NULL, hold_until = NULL");
        q("UPDATE station_visits SET status = 'pending', reached_at = NULL, reached_by = NULL, done_at = NULL, done_by = NULL, near_since = NULL, near_device_id = NULL, hold_until = NULL");
        q("UPDATE teams SET status = 'not_started', started_at = NULL, returned_at = NULL, expected_return_at = NULL");
        if (!empty($_POST['positions'])) {
            q('DELETE FROM positions');
            q('UPDATE devices SET last_lat = NULL, last_lng = NULL, last_speed = NULL, last_bearing = NULL, last_accuracy = NULL, last_fix_at = NULL');
        }
        q('DELETE FROM events');
        log_event('reset', null, null, null, 'Progress reset by admin');
        @unlink(APP_DIR . '/data/cache_public.json');
        flash('All progress cleared. Teams, cars, phones and stations are kept.');
        redirect('settings.php');
    }
    redirect('settings.php');   // after any form post, always redirect (refresh must reload, not re-send)
}

$checked = fn($k) => setting($k) === '1' ? 'checked' : '';
page_head('Settings', 'settings', ['map' => true]);
?>
<main class="wrap wrap-narrow">
  <div class="page-head"><h1>Settings</h1></div>

  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <section class="card">
      <h2>Event</h2>
      <div class="field"><label>Event name</label><input type="text" name="event_name" value="<?= h(setting('event_name')) ?>" required></div>
      <div class="field" style="margin:0"><label>Subtitle</label><input type="text" name="event_subtitle" value="<?= h(setting('event_subtitle')) ?>"></div>
    </section>

    <section class="card" data-location-picker>
      <h2>Start &amp; end point</h2>
      <p class="small muted">Every team starts and finishes here.</p>
      <div class="field"><label>Name</label><input type="text" name="base_name" value="<?= h(setting('base_name')) ?>" data-name></div>
      <div class="field">
        <label>Google Maps link</label>
        <div class="input-group"><input type="url" name="base_link" value="<?= h(setting('base_link')) ?>" data-link><button type="button" class="btn" data-find>Find</button></div>
        <div class="hint" data-resolve-msg>Paste a link and tap Find, or drag the pin.</div>
      </div>
      <div class="grid grid-2" style="gap:10px">
        <div class="field" style="margin:0"><label>Latitude</label><input type="text" name="base_lat" value="<?= h(setting('base_lat')) ?>" data-lat></div>
        <div class="field" style="margin:0"><label>Longitude</label><input type="text" name="base_lng" value="<?= h(setting('base_lng')) ?>" data-lng></div>
      </div>
      <div class="mini-map" data-pick-map></div>
    </section>

    <section class="card">
      <h2>Automatic arrival detection</h2>
      <div class="grid grid-2" style="gap:10px">
        <div class="field"><label>Arrival distance (metres)</label><input type="number" name="arrival_radius" value="<?= h(setting('arrival_radius')) ?>" min="30" max="1000"><div class="hint">A car this close to its station is marked “At station”.</div></div>
        <div class="field"><label>Start point distance (metres)</label><input type="number" name="base_radius" value="<?= h(setting('base_radius')) ?>" min="50" max="2000"><div class="hint">Used to detect leaving and returning.</div></div>
        <div class="field"><label>Minimum stop (seconds)</label><input type="number" name="min_dwell" value="<?= h(setting('min_dwell')) ?>" min="0" max="1800"><div class="hint">Leaving sooner than this isn't counted as delivered.</div></div>
      </div>
      <label class="check"><input type="checkbox" name="auto_done" value="1" <?= $checked('auto_done') ?>> Mark “Delivered” automatically when the car drives away from a station</label>
    </section>

    <section class="card">
      <h2>Return-time estimate</h2>
      <div class="grid grid-2" style="gap:10px">
        <div class="field" style="margin:0"><label>Average speed (km/h)</label><input type="number" name="avg_speed" value="<?= h(setting('avg_speed')) ?>" min="5" max="80"></div>
        <div class="field" style="margin:0"><label>Minutes at each station</label><input type="number" name="stop_minutes" value="<?= h(setting('stop_minutes')) ?>" min="0" max="120"></div>
      </div>
    </section>

    <section class="card">
      <h2>Sharing</h2>
      <label class="check" style="margin-bottom:10px"><input type="checkbox" name="public_enabled" value="1" <?= $checked('public_enabled') ?>> Public live map: anyone with the link can watch (no phone numbers shown)</label>
      <label class="check" style="margin-bottom:10px"><input type="checkbox" name="public_trails" value="1" <?= $checked('public_trails') ?>> Show the path a car has driven when someone taps it</label>
      <label class="check"><input type="checkbox" name="accept_new_devices" value="1" <?= $checked('accept_new_devices') ?>> List new phones automatically when they start sending (turn off after setup)</label>
      <div class="kbd-copy" style="margin-top:12px"><code><?= h(absolute_url('index.php')) ?></code><button class="btn btn-sm" type="button" data-copy="<?= h(absolute_url('index.php')) ?>">Copy public link</button></div>
    </section>

    <div><button class="btn btn-primary btn-lg" type="submit">Save settings</button></div>
  </form>

  <form method="post" class="card" style="margin-top:20px">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="password">
    <h2>Admin password</h2>
    <div class="grid grid-2" style="gap:10px">
      <div class="field" style="margin:0"><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
      <div class="field" style="margin:0"><label>New password</label><input type="password" name="new" autocomplete="new-password" minlength="8" required></div>
    </div>
    <div style="margin-top:12px"><button class="btn" type="submit">Change password</button></div>
  </form>

  <form method="post" class="card" id="reset" style="margin-top:20px;border-color:#f5c2c2" data-confirm="Clear all progress? This can't be undone.">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="reset">
    <h2>Reset after a test run</h2>
    <p class="small">Sets every station back to <b>Pending</b>, every team to <b>Not started</b>, and clears the activity feed.</p>
    <div class="notice ok" style="margin-bottom:12px"><b>Nothing you set up is deleted:</b> all fire stations (with their locations on the map), teams, PINs, cars, phones, the route plan and these settings stay exactly as they are.</div>
    <label class="check" style="margin-bottom:10px"><input type="checkbox" name="positions" value="1" checked> Also clear the GPS history from the test drive (car trails, distances and speeds in the report)</label>
    <div class="input-group"><input type="text" name="confirm" placeholder="Type RESET" autocomplete="off"><button class="btn btn-danger" type="submit">Reset progress</button></div>
  </form>
</main>
<?php page_foot(); ?>
