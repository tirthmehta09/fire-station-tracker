<?php
/**
 * One-time setup: database connection, tables, admin password and the starting station list.
 * After setup it refuses to run again (log in as admin to re-run the table upgrade).
 */
define('INSTALLER', true);
require __DIR__ . '/inc/bootstrap.php';

$step = 'db';
$error = '';
$done = '';

function write_config(array $c): bool
{
    $php = "<?php\n// Created by install.php. Keep this file private.\nreturn " . var_export([
        'db_host'  => $c['db_host'],
        'db_name'  => $c['db_name'],
        'db_user'  => $c['db_user'],
        'db_pass'  => $c['db_pass'],
        'timezone' => 'Asia/Kolkata',
        'debug'    => false,
    ], true) . ";\n";
    return (bool) @file_put_contents(APP_DIR . '/config.php', $php);
}

function seed_stations(): int
{
    $file = APP_DIR . '/data/stations.json';
    if (!is_file($file)) {
        return 0;
    }
    $list = json_decode((string) file_get_contents($file), true);
    $n = 0;
    foreach ((array) $list as $s) {
        if (empty($s['name'])) {
            continue;
        }
        $lat = isset($s['lat']) && is_numeric($s['lat']) ? (float) $s['lat'] : null;
        $lng = isset($s['lng']) && is_numeric($s['lng']) ? (float) $s['lng'] : null;
        $link = $s['maps_link'] ?? ($lat !== null ? "https://www.google.com/maps/search/?api=1&query=$lat,$lng" : '');
        q('INSERT INTO stations (name, area, address, phone, maps_link, lat, lng, active, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            mb_substr($s['name'], 0, 150), mb_substr((string) ($s['area'] ?? ''), 0, 100), mb_substr((string) ($s['address'] ?? ''), 0, 255),
            mb_substr((string) ($s['phone'] ?? ''), 0, 60), $link, $lat, $lng, ($s['active'] ?? $s['in_scope'] ?? true) ? 1 : 0,
            (string) ($s['notes'] ?? ''), time(), time(),
        ]);
        $n++;
    }
    return $n;
}

if ($CONFIG !== null) {
    try {
        db();
        $step = 'setup';
    } catch (Throwable $e) {
        $error = 'config.php exists but the database connection failed: ' . $e->getMessage();
        $step = 'broken';
    }
}

if ($step === 'setup') {
    foreach (require APP_DIR . '/inc/schema.php' as $sql) {
        db()->exec($sql);
    }
    // Upgrades for databases created by an older version.
    ensure_schema();
    if (setting('admin_hash', '') !== '') {
        start_session();
        $step = is_admin() ? 'upgraded' : 'locked';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'db') {
        $c = array_map('trim', [
            'db_host' => $_POST['db_host'] ?? 'localhost', 'db_name' => $_POST['db_name'] ?? '',
            'db_user' => $_POST['db_user'] ?? '', 'db_pass' => $_POST['db_pass'] ?? '',
        ]);
        try {
            new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (!write_config($c)) {
                $error = 'Connected, but could not write config.php. Copy config.sample.php to config.php, fill in these details by hand, then reload this page.';
            } else {
                header('Location: install.php', true, 303);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Could not connect: ' . $e->getMessage();
        }
    } elseif ($step === 'setup') {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $error = 'Use at least 8 characters for the admin password.';
        } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
            $error = 'The two passwords don\'t match.';
        } else {
            set_setting('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
            foreach (DEFAULT_SETTINGS as $k => $v) {
                if (setting($k, null) === $v) {
                    set_setting($k, $v);
                }
            }
            if (trim($_POST['event_name'] ?? '') !== '') {
                set_setting('event_name', trim($_POST['event_name']));
            }
            set_setting('installed_at', time());
            $n = !empty($_POST['seed']) && !(int) val('SELECT COUNT(*) FROM stations') ? seed_stations() : 0;
            start_session();
            session_regenerate_id(true);
            $_SESSION = ['role' => 'admin', 'csrf' => bin2hex(random_bytes(16))];
            flash('Setup complete.' . ($n ? " Loaded $n fire stations." : '') . ' Next: create your teams.');
            header('Location: teams.php', true, 303);
            exit;
        }
    }
    // Show any error on a fresh page (Post/Redirect/Get): refreshing never asks to resubmit the form.
    start_session();
    $_SESSION['install_error'] = $error;
    $_SESSION['install_old'] = ['db_host' => (string) ($_POST['db_host'] ?? 'localhost'), 'db_name' => (string) ($_POST['db_name'] ?? ''),
        'db_user' => (string) ($_POST['db_user'] ?? ''), 'event_name' => (string) ($_POST['event_name'] ?? '')];
    header('Location: install.php', true, 303);
    exit;
}
start_session();
$error = $error ?: (string) ($_SESSION['install_error'] ?? '');
$old = $_SESSION['install_old'] ?? ['db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'event_name' => ''];
unset($_SESSION['install_error'], $_SESSION['install_old']);
$stationsFile = is_file(APP_DIR . '/data/stations.json') ? count((array) json_decode((string) file_get_contents(APP_DIR . '/data/stations.json'), true)) : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · Fire Station Tracker</title>
<link rel="stylesheet" href="assets/app.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<main class="login-body" style="min-height:100vh">
  <div class="login-wrap" style="max-width:520px">
  <div class="login-card">
    <div class="login-logo"><img src="assets/icon-192.png" alt="" width="64" height="64" style="border-radius:18px"></div>
    <h1 style="text-align:center">Fire Station Tracker setup</h1>
    <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 14px"><?= h($error) ?></div><?php endif; ?>

    <?php if ($step === 'db'): ?>
      <p class="muted">Step 1 of 2. In Hostinger hPanel go to <b>Databases → Management</b>, create a MySQL database and user, then enter them here.</p>
      <form method="post">
        <div class="field"><label>Database host</label><input type="text" name="db_host" value="<?= h($old['db_host']) ?>" required></div>
        <div class="field"><label>Database name</label><input type="text" name="db_name" value="<?= h($old['db_name']) ?>" placeholder="u123456789_firetrack" required></div>
        <div class="field"><label>Database user</label><input type="text" name="db_user" value="<?= h($old['db_user']) ?>" required></div>
        <div class="field"><label>Database password</label><input type="password" name="db_pass"></div>
        <button class="btn btn-primary btn-lg btn-block">Connect</button>
      </form>

    <?php elseif ($step === 'setup'): ?>
      <p class="muted">Step 2 of 2. Database connected and tables created.</p>
      <form method="post">
        <div class="field"><label>Event name</label><input type="text" name="event_name" value="<?= h(setting('event_name')) ?>"></div>
        <div class="field"><label>Admin password</label><input type="password" name="password" autocomplete="new-password" required minlength="8"></div>
        <div class="field"><label>Admin password again</label><input type="password" name="password2" autocomplete="new-password" required minlength="8"></div>
        <?php if ($stationsFile): ?>
        <div class="field"><label class="check"><input type="checkbox" name="seed" value="1" checked> Load the <?= $stationsFile ?> researched fire stations, Borivali to Colaba. Main stations start as active, mini stations and Dahisar as inactive; you can change any of them later.</label></div>
        <?php endif; ?>
        <button class="btn btn-primary btn-lg btn-block">Finish setup</button>
      </form>

    <?php elseif ($step === 'upgraded'): ?>
      <p>Tables are up to date. <a href="admin.php">Back to the dashboard</a>.</p>

    <?php elseif ($step === 'locked'): ?>
      <p>Setup is already done. <a href="login.php">Log in as admin</a>.</p>

    <?php else: ?>
      <p>Fix the database details in <code>config.php</code>, then reload this page.</p>
    <?php endif; ?>
  </div>
  </div>
</main>
</body>
</html>
