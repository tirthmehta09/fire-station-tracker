<?php
/**
 * Shared bootstrap: config, database, settings, sessions and small helpers.
 * Every page and API endpoint starts with:  require __DIR__ . '/inc/bootstrap.php';
 */
declare(strict_types=1);

define('APP_DIR', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

const DEFAULT_SETTINGS = [
    'event_name'         => 'Sweets for Our Fire Heroes',
    'event_subtitle'     => 'Western line fire stations · Kandivali to Churchgate',
    'base_name'          => 'Aum Darshan CHS, Shankar Lane, Kandivali West',
    'base_link'          => 'https://maps.app.goo.gl/t8EkdzXhw1xdmehD7',
    'base_lat'           => '19.1981694',
    'base_lng'           => '72.8463094',
    'arrival_radius'     => '150',   // metres: a car this close to a station counts as "reached"
    'base_radius'        => '250',   // metres: this close to the start point counts as "at base"
    'auto_done'          => '1',     // mark a station delivered when the car drives away
    'min_dwell'          => '120',   // seconds a car must stay before leaving counts as delivered
    'avg_speed'          => '20',    // km/h used for the return-time estimate
    'stop_minutes'       => '10',    // minutes spent at each station, for the estimate
    'public_enabled'     => '1',
    'public_trails'      => '1',
    'accept_new_devices' => '1',
];

const TEAM_COLORS = ['#e53935', '#1e88e5', '#43a047', '#8e24aa', '#fb8c00', '#00897b', '#3949ab', '#d81b60',
                     '#6d4c41', '#00acc1', '#7cb342', '#5e35b1', '#f4511e', '#546e7a', '#c0a000', '#039be5'];

$CONFIG   = is_file(APP_DIR . '/config.php') ? require APP_DIR . '/config.php' : null;
$SETTINGS = null;

date_default_timezone_set(is_array($CONFIG) && !empty($CONFIG['timezone']) ? $CONFIG['timezone'] : 'Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', is_array($CONFIG) && !empty($CONFIG['debug']) ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(APP_DIR . '/data') && is_writable(APP_DIR . '/data')) {
    ini_set('error_log', APP_DIR . '/data/error.log');
}

/* ------------------------------------------------------- security headers */

if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');                       // browsers must not guess file types
    header('X-Frame-Options: SAMEORIGIN');                           // no embedding in other sites (click-jacking)
    header('Referrer-Policy: strict-origin-when-cross-origin');      // don't leak page addresses (share links) to other sites
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()');
}

/* ------------------------------------------------------------------ URLs */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** Web path of the app folder, e.g. "/" or "/firetrack/". Works from the root and from /api/. */
function app_base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if (substr($dir, -4) === '/api') {
            $dir = substr($dir, 0, -4);
        }
        $base = $dir . '/';
    }
    return $base;
}

function app_url(string $path = ''): string
{
    return app_base_path() . ltrim($path, '/');
}

function absolute_url(string $path = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . app_url($path);
}

function redirect(string $path): void
{
    $url = preg_match('#^(https?:)?/#', $path) ? $path : app_url($path);
    header('Location: ' . $url, true, 303);
    exit;
}

/* ------------------------------------------------------------- database */

function cfg(string $key, $default = null)
{
    global $CONFIG;
    return is_array($CONFIG) && array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', cfg('db_host', 'localhost'), cfg('db_name', ''));
        $pdo = new PDO($dsn, (string) cfg('db_user', ''), (string) cfg('db_pass', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/* ------------------------------------------------------------- settings */

function setting(string $key, $default = null)
{
    global $SETTINGS;
    if ($SETTINGS === null) {
        $SETTINGS = [];
        try {
            foreach (rows('SELECT k, v FROM settings') as $r) {
                $SETTINGS[$r['k']] = $r['v'];
            }
        } catch (Throwable $e) {
            // Table missing before install: fall back to defaults.
        }
    }
    return $SETTINGS[$key] ?? DEFAULT_SETTINGS[$key] ?? $default;
}

function set_setting(string $key, $value): void
{
    global $SETTINGS;
    q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, (string) $value]);
    if (is_array($SETTINGS)) {
        $SETTINGS[$key] = (string) $value;
    }
}

function base_point(): array
{
    return [(float) setting('base_lat'), (float) setting('base_lng')];
}

/* ------------------------------------------------------------- sessions */

const SESSION_NAME = 'fst_sid';

function start_session(bool $onlyIfCookie = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if ($onlyIfCookie && empty($_COOKIE[SESSION_NAME])) {
        return;
    }
    // Own session folder so the host's 24-minute cleanup of other apps can't log teams out mid-event.
    $dir = APP_DIR . '/data/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string) (4 * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 4 * 86400,
        'path'     => app_base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function is_admin(): bool
{
    return role() === 'admin';
}

/** Team of the logged-in team LEADER (members get null here: they can't mark stations). */
function session_team_id(): ?int
{
    return role() === 'team' ? (int) ($_SESSION['team_id'] ?? 0) : null;
}

/** Team of the logged-in team member (location sharing only). */
function member_team_id(): ?int
{
    return role() === 'member' ? (int) ($_SESSION['team_id'] ?? 0) : null;
}

/** [team, device] for a logged-in team member, or back to the login page. */
function require_member(): array
{
    start_session();
    $team = member_team_id() ? row('SELECT * FROM teams WHERE id = ?', [member_team_id()]) : null;
    $device = $team ? row('SELECT * FROM devices WHERE id = ? AND team_id = ?', [(int) ($_SESSION['device_id'] ?? 0), $team['id']]) : null;
    if (!$team || !$device) {
        $_SESSION = [];
        redirect('login.php?as=member');
    }
    return [$team, $device];
}

/** Digits only, last 10 (so +91 98200 00000 and 09820000000 match). */
function phone_key(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone);
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

function require_admin(bool $api = false): void
{
    start_session();
    if (!is_admin()) {
        if ($api) {
            json_out(['error' => 'Please log in as admin again.'], 401);
        }
        redirect('login.php?as=admin&next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
}

function require_team(): array
{
    start_session();
    if (role() === 'member') {
        redirect('member.php');
    }
    $team = session_team_id() ? row('SELECT * FROM teams WHERE id = ?', [session_team_id()]) : null;
    if (!$team) {
        $_SESSION = [];
        redirect('login.php?as=team');
    }
    return $team;
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf(bool $api = false): void
{
    $sent = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if ($sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        if ($api) {
            json_out(['error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        http_response_code(419);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function flash(string $msg, string $type = 'ok'): void
{
    start_session();
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* --------------------------------------------------------------- output */

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

function fmt_time(?int $ts): string
{
    return $ts ? date('g:i A', $ts) : '';
}

function fmt_duration(int $seconds): string
{
    $m = (int) round($seconds / 60);
    if ($m < 60) {
        return $m . ' min';
    }
    return intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
}

/* ------------------------------------------------------------------ geo */

/** Distance in metres between two points. */
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function valid_coords($lat, $lng): bool
{
    return is_numeric($lat) && is_numeric($lng)
        && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
        && !(abs((float) $lat) < 0.001 && abs((float) $lng) < 0.001);
}

function random_token(int $bytes = 10): string
{
    return bin2hex(random_bytes($bytes));
}

/* ----------------------------------------------------- database upgrades */

const SCHEMA_VERSION = 5;

/**
 * Adds columns that newer versions need to a database created by an older version.
 * Runs once by itself after new files are uploaded (no need to open install.php).
 */
function ensure_schema(): void
{
    $add = [
        ['stations', 'hold_until', 'INT NULL'],
        ['teams', 'member_pin_hash', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['devices', 'role', "VARCHAR(10) NOT NULL DEFAULT ''"],
        ['devices', 'person_phone', "VARCHAR(30) NOT NULL DEFAULT ''"],
        ['devices', 'app_seen_at', 'INT NULL'],
        ['stations', 'shared', "VARCHAR(5) NOT NULL DEFAULT ''"],
    ];
    try {
        // New tables (CREATE TABLE IF NOT EXISTS is safe to repeat).
        foreach (require APP_DIR . '/inc/schema.php' as $sql) {
            db()->exec($sql);
        }
        foreach ($add as [$table, $col, $type]) {
            if (!row("SHOW COLUMNS FROM `$table` LIKE '$col'")) {
                db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $type");
            }
        }
        set_setting('schema_version', SCHEMA_VERSION);
    } catch (Throwable $e) {
        error_log('schema upgrade: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------ install guard */

if ($CONFIG !== null && !defined('INSTALLER') && setting('admin_hash', '') !== '' && (int) setting('schema_version', 0) < SCHEMA_VERSION) {
    ensure_schema();
}

if ($CONFIG === null && !defined('INSTALLER')) {
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        json_out(['error' => 'Not installed yet'], 503);
    }
    redirect('install.php');
}
