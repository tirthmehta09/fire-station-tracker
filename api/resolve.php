<?php
/** Admin only: Google Maps link -> coordinates. Optional station_id saves the result straight onto that station. */
require __DIR__ . '/../inc/bootstrap.php';
require APP_DIR . '/inc/maps.php';

require_admin(true);
session_write_close();

$url = (string) ($_GET['url'] ?? $_POST['url'] ?? '');
$stationId = (int) ($_GET['station_id'] ?? $_POST['station_id'] ?? 0);
if ($stationId && $url === '') {
    $url = (string) val('SELECT maps_link FROM stations WHERE id = ?', [$stationId]);
}
$r = resolve_location($url);
if ($stationId && $r['ok']) {
    q('UPDATE stations SET lat = ?, lng = ?, updated_at = ? WHERE id = ?', [$r['lat'], $r['lng'], time(), $stationId]);
    if (!empty($r['address'])) {
        q("UPDATE stations SET address = ? WHERE id = ? AND address = ''", [$r['address'], $stationId]);
    }
}
json_out($r);
