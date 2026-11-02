<?php
/** Page header/footer shared by every screen (same shell as the attendance system: top bar + light sidebar). */
defined('APP_DIR') || exit;
require_once __DIR__ . '/logic.php';

/** Asset URL that changes whenever the file changes, so phones never run an old cached copy. */
function asset(string $file): string
{
    $path = APP_DIR . '/assets/' . $file;
    return app_url('assets/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : APP_VERSION);
}

/** Admin sidebar: key => [href, label, Bootstrap icon]. */
function admin_nav(): array
{
    return [
        'admin'    => ['admin.php', 'Live map', 'map'],
        'plan'     => ['plan.php', 'Route plan', 'signpost-split'],
        'stations' => ['stations.php', 'Fire stations', 'building'],
        'teams'    => ['teams.php', 'Teams & phones', 'people'],
        'report'   => ['report.php', 'Report', 'file-earmark-bar-graph'],
        'settings' => ['settings.php', 'Settings', 'gear'],
    ];
}

/** Top bar links for team leaders and the public. */
function top_nav(): array
{
    if (role() === 'team') {
        return ['team' => ['team.php', 'My team', 'flag'], 'public' => ['index.php', 'Live map', 'map'], 'help' => ['help.php', 'Help', 'question-circle']];
    }
    if (role() === 'member') {
        return ['member' => ['member.php', 'My phone', 'phone'], 'public' => ['index.php', 'Live map', 'map'], 'help' => ['help.php', 'Help', 'question-circle']];
    }
    return ['public' => ['index.php', 'Live map', 'map'], 'help' => ['help.php', 'Help', 'question-circle']];
}

$GLOBALS['ADMIN_SHELL'] = false;

/**
 * $opts: map (bool: load the map libraries), app (array passed to JS as window.APP), bare (no top bar), shell (bool, default: admin)
 */
function page_head(string $title, string $active = '', array $opts = []): void
{
    $event = setting('event_name');
    $shell = $opts['shell'] ?? (is_admin() && empty($opts['bare']));
    $GLOBALS['ADMIN_SHELL'] = $shell;
    $app = array_merge([
        'base' => app_url(),
        'api'  => app_url('api/'),
        'csrf' => session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '',
        'role' => role() ?? 'public',
        'sw'   => app_url('sw.js'),
    ], $opts['app'] ?? []);
    $home = is_admin() ? 'admin.php' : (role() === 'team' ? 'team.php' : (role() === 'member' ? 'member.php' : 'index.php'));
    ?><!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#d97a22">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Fire Track">
<title><?= h($title) ?> · <?= h($event) ?></title>
<link rel="manifest" href="<?= h(app_url('manifest.php')) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= h(app_url('assets/icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(app_url('assets/icon-180.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php if (!empty($opts['map'])): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.js"></script>
<script src="https://unpkg.com/@maplibre/maplibre-gl-leaflet@0.0.22/leaflet-maplibre-gl.js"></script>
<?php endif; ?>
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
<script>
/* Apply the saved theme before paint (same as the attendance system). */
(function(){try{document.documentElement.setAttribute('data-theme',localStorage.getItem('fst_theme')||'light');}catch(e){}})();
window.APP = <?= json_encode($app, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= h(asset('app.js')) ?>"></script>
</head>
<body>
<?php if (empty($opts['bare'])): ?>
<header class="app-topbar">
  <?php if ($shell): ?><button class="menu-btn" id="sidebarToggle" type="button" aria-label="Menu"><i class="bi bi-list"></i></button><?php endif; ?>
  <a class="brand" href="<?= h(app_url($home)) ?>">
    <span class="brand-icon"><i class="bi bi-fire"></i></span>
    <span class="brand-text"><span><?= h($event) ?></span><small><?= h(setting('event_subtitle')) ?></small></span>
  </a>
  <div class="top-actions">
    <?php if (!$shell): ?>
    <nav class="top-nav" aria-label="Main">
      <?php foreach (top_nav() as $key => [$href, $label, $icon]): ?>
        <a href="<?= h(app_url($href)) ?>" class="<?= $key === $active ? 'on' : '' ?>" title="<?= h($label) ?>"><i class="bi bi-<?= h($icon) ?>"></i><span><?= h($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <button class="icon-btn install-btn" id="installBtn" type="button" title="Install as app"><i class="bi bi-download"></i></button>
    <button class="icon-btn" id="themeToggle" type="button" title="Dark / light mode"><i class="bi bi-moon-stars"></i></button>
    <?php if (role()): ?>
      <a class="icon-btn" href="<?= h(app_url('logout.php')) ?>" title="Log out"><i class="bi bi-box-arrow-right"></i></a>
    <?php else: ?>
      <a class="icon-btn" href="<?= h(app_url('login.php')) ?>" title="Log in"><i class="bi bi-person-circle"></i></a>
    <?php endif; ?>
  </div>
</header>
<?php endif; ?>
<?php if ($shell): ?>
<div class="app-wrapper">
  <aside class="app-sidebar" id="appSidebar">
    <?php foreach (admin_nav() as $key => [$href, $label, $icon]): ?>
      <a href="<?= h(app_url($href)) ?>" class="<?= $key === $active ? 'on' : '' ?>"><i class="bi bi-<?= h($icon) ?>"></i><span><?= h($label) ?></span></a>
    <?php endforeach; ?>
    <div class="sep"></div>
    <a href="<?= h(app_url('index.php')) ?>" class="<?= $active === 'public' ? 'on' : '' ?>"><i class="bi bi-broadcast"></i><span>Public view</span></a>
    <a href="<?= h(app_url('help.php')) ?>" class="<?= $active === 'help' ? 'on' : '' ?>"><i class="bi bi-question-circle"></i><span>Volunteer guide</span></a>
    <div class="sidebar-foot">v<?= h(APP_VERSION) ?> · Asia/Kolkata</div>
  </aside>
  <div class="app-content">
<?php endif; ?>
<?php foreach (session_status() === PHP_SESSION_ACTIVE ? take_flashes() : [] as [$type, $msg]): ?>
  <div class="flash flash-<?= h($type) ?>" role="status"><?= h($msg) ?></div>
<?php endforeach; ?>
<?php
}

function page_foot(array $scripts = []): void
{
    if ($GLOBALS['ADMIN_SHELL']) {
        echo "  </div>\n</div>\n<div class=\"sidebar-backdrop\" id=\"sidebarBackdrop\"></div>\n";
    }
    foreach ($scripts as $s) {
        echo '<script src="' . h(asset($s)) . '"></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function status_pill(string $status): string
{
    return '<span class="pill ' . h($status) . '">' . h(STATUS_LABELS[$status] ?? $status) . '</span>';
}

function team_status_pill(string $status): string
{
    return '<span class="pill t-' . h($status) . '">' . h(TEAM_STATUS_LABELS[$status] ?? $status) . '</span>';
}
