<?php
/** Public read-only live map for all volunteers. */
require __DIR__ . '/inc/bootstrap.php';
require APP_DIR . '/inc/layout.php';

start_session(true);
// Opened from the installed app icon: take leaders and admins straight to their own screen.
if (isset($_GET['app']) && role()) {
    redirect(is_admin() ? 'admin.php' : (role() === 'member' ? 'member.php' : 'team.php'));
}
if (setting('public_enabled') !== '1' && !role()) {
    page_head('Live map', 'public');
    echo '<main class="login-body"><div class="login-wrap"><div class="login-card"><h1>Live map is private</h1><p class="muted">The organisers have turned off the public map.</p>'
        . '<a class="btn btn-primary" href="' . h(app_url('login.php?as=team')) . '">Team leader login</a></div></div></main>';
    page_foot();
    exit;
}
// Logged-in team leaders and admins see this page as the public sees it.
page_head('Live map', 'public', ['map' => true, 'app' => ['role' => 'public']]);
include APP_DIR . '/inc/live_panel.php';
page_foot(['live.js']);
