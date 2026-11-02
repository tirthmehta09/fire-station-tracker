<?php
/**
 * Log in as team leader (team + leader PIN), team member (team + member PIN + name + mobile) or admin.
 * A member's phone is registered under their name and mobile so the admin can see who carries each tracked phone.
 */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

start_session();
$as = $_GET['as'] ?? $_POST['as'] ?? 'team';
$as = in_array($as, ['team', 'member', 'admin'], true) ? $as : 'team';
$error = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
if (!preg_match('#^/[^/\\\\]#', $next)) {
    $next = '';
}

/** Find this person's phone in the team (same mobile number) or register a new one. */
function member_device(array $team, string $name, string $phone): array
{
    $key = phone_key($phone);
    foreach (rows("SELECT * FROM devices WHERE team_id = ? AND role = 'member'", [$team['id']]) as $d) {
        if ($key !== '' && phone_key($d['person_phone']) === $key) {
            q('UPDATE devices SET name = ?, person_phone = ?, approved = 1, active = 1 WHERE id = ?', [$name, $phone, $d['id']]);
            return row('SELECT * FROM devices WHERE id = ?', [$d['id']]);
        }
    }
    do {
        $identifier = 'm' . random_int(100000, 999999);   // short, easy to type into the Traccar app
    } while (val('SELECT id FROM devices WHERE identifier = ?', [$identifier]));
    q('INSERT INTO devices (identifier, token, name, team_id, source, role, person_phone, approved, active, created_at) VALUES (?,?,?,?,?,?,?,1,1,?)',
        [$identifier, random_token(), $name, $team['id'], 'web', 'member', $phone, time()]);
    return row('SELECT * FROM devices WHERE id = ?', [(int) db()->lastInsertId()]);
}

/**
 * Wrong PIN / password limits, counted on the server so clearing cookies doesn't help:
 * after 5 wrong tries in 15 minutes each try is slowed down; after 25 for one team (or 60 from one network) it is blocked for 15 minutes.
 */
function login_blocked(string $scope, string $ip): ?string
{
    $since = time() - 900;
    q('DELETE FROM login_attempts WHERE at < ?', [time() - 86400]);
    $byScope = (int) val('SELECT COUNT(*) FROM login_attempts WHERE scope = ? AND at >= ?', [$scope, $since]);
    $byIp = (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at >= ?', [$ip, $since]);
    if ($byScope >= 25 || $byIp >= 60) {
        return 'Too many wrong attempts. Please wait 15 minutes, or ask the admin to set a new PIN.';
    }
    if ($byScope >= 5 || $byIp >= 10) {
        sleep(2);
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $team = $as !== 'admin' ? row('SELECT * FROM teams WHERE id = ?', [(int) ($_POST['team_id'] ?? 0)]) : null;
    $pin = trim((string) ($_POST['pin'] ?? ''));
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $scope = $as === 'admin' ? 'admin' : $as . ':' . ($team ? (int) $team['id'] : 0);
    $blocked = login_blocked($scope, $ip);

    if ($blocked) {
        $error = $blocked;
    } elseif ($as === 'admin') {
        $hash = (string) setting('admin_hash', '');
        if ($hash !== '' && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION = ['role' => 'admin', 'csrf' => bin2hex(random_bytes(16))];
            redirect($next ?: 'admin.php');
        }
        $error = 'Wrong password.';
    } elseif ($as === 'team') {
        if ($team && $team['pin_hash'] !== '' && password_verify($pin, $team['pin_hash'])) {
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION = ['role' => 'team', 'team_id' => (int) $team['id'], 'csrf' => bin2hex(random_bytes(16))];
            redirect('team.php');
        }
        $error = $team && $team['pin_hash'] === '' ? 'This team has no leader PIN yet. Ask the admin to set one.' : 'Wrong team or PIN.';
    } else {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        $phone = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 30);
        if ($name === '' || strlen(phone_key($phone)) < 10) {
            $error = 'Enter your name and your 10-digit mobile number.';
        } elseif ($team && $team['member_pin_hash'] !== '' && password_verify($pin, $team['member_pin_hash'])) {
            $device = member_device($team, $name, $phone);
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            session_regenerate_id(true);
            $_SESSION = ['role' => 'member', 'team_id' => (int) $team['id'], 'device_id' => (int) $device['id'], 'csrf' => bin2hex(random_bytes(16))];
            redirect('member.php');
        } else {
            $error = $team && $team['member_pin_hash'] === '' ? 'This team has no member PIN yet. Ask the admin to set one.' : 'Wrong team or member PIN.';
        }
    }
    if (!$blocked) {
        q('INSERT INTO login_attempts (ip, scope, at) VALUES (?, ?, ?)', [$ip, $scope, time()]);
    }
    // Show the message on a fresh page (Post/Redirect/Get) so refreshing never asks to resubmit the form.
    $_SESSION['login_error'] = $error;
    $_SESSION['login_old'] = ['team_id' => (int) ($_POST['team_id'] ?? 0), 'name' => (string) ($_POST['name'] ?? ''), 'phone' => (string) ($_POST['phone'] ?? '')];
    redirect('login.php?as=' . $as . ($next !== '' ? '&next=' . rawurlencode($next) : ''));
}
$error = (string) ($_SESSION['login_error'] ?? '');
$old = $_SESSION['login_old'] ?? ['team_id' => 0, 'name' => '', 'phone' => ''];
unset($_SESSION['login_error'], $_SESSION['login_old']);

$teams = rows('SELECT id, name, color FROM teams ORDER BY id');
$intro = ['team' => 'Team leaders: see your stations, mark deliveries and share your location',
          'member' => 'Team members: share your phone\'s location with the organisers',
          'admin' => 'Organiser login'][$as];
page_head('Log in', 'login');
?>
<main class="login-body">
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-logo"><i class="bi bi-fire"></i></div>
      <h1 style="text-align:center;margin-bottom:4px"><?= h(setting('event_name')) ?></h1>
      <p class="muted small" style="text-align:center"><?= h($intro) ?></p>
      <div class="tabs tabs-3">
        <a href="?as=team" class="<?= $as === 'team' ? 'on' : '' ?>"><i class="bi bi-flag"></i> Leader</a>
        <a href="?as=member" class="<?= $as === 'member' ? 'on' : '' ?>"><i class="bi bi-person"></i> Member</a>
        <a href="?as=admin" class="<?= $as === 'admin' ? 'on' : '' ?>"><i class="bi bi-shield-lock"></i> Admin</a>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="as" value="<?= h($as) ?>">
        <input type="hidden" name="next" value="<?= h($next) ?>">
        <?php if ($error): ?><div class="flash flash-error" style="margin:0 0 14px"><?= h($error) ?></div><?php endif; ?>
        <?php if ($as === 'admin'): ?>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
          </div>
        <?php elseif (!$teams): ?>
          <p class="muted">No teams have been created yet.</p>
        <?php else: ?>
          <div class="field">
            <label for="team_id">Your team</label>
            <select id="team_id" name="team_id" required>
              <option value="">Choose your team</option>
              <?php foreach ($teams as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) $old['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($as === 'member'): ?>
          <div class="field">
            <label for="name">Your name</label>
            <input type="text" id="name" name="name" value="<?= h($old['name']) ?>" autocomplete="name" required placeholder="e.g. Priya Mehta">
          </div>
          <div class="field">
            <label for="phone">Your mobile number</label>
            <input type="tel" id="phone" name="phone" value="<?= h($old['phone']) ?>" autocomplete="tel" inputmode="tel" required placeholder="e.g. 98200 00000">
          </div>
          <?php endif; ?>
          <div class="field">
            <label for="pin"><?= $as === 'member' ? 'Member PIN' : 'Leader PIN' ?></label>
            <input type="password" id="pin" name="pin" inputmode="numeric" autocomplete="one-time-code" required>
            <div class="hint"><?= $as === 'member' ? 'Your team leader or the admin gives you the member PIN.' : 'The admin gives each team leader a PIN.' ?></div>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary btn-lg btn-block" type="submit"><i class="bi bi-box-arrow-in-right"></i> Log in</button>
      </form>
    </div>
    <p class="small muted" style="text-align:center;margin-top:14px">Just want to watch? <a href="<?= h(app_url('index.php')) ?>">Open the live map</a></p>
  </div>
</main>
<?php page_foot(); ?>
