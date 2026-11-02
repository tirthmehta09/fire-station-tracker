<?php
/**
 * Turn a Google Maps link (short or long), a plus code or "lat, lng" text into coordinates.
 * Shared links from the Maps app often contain no coordinates at all, so we follow the
 * redirect, then fall back to Google's place preview data, then to the plus code in the address.
 */
defined('APP_DIR') || exit;

const OLC_ALPHABET = '23456789CFGHJMPQRVWX';
const MAPS_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function resolve_location(string $input): array
{
    $input = trim($input);
    if ($input === '') {
        return ['ok' => false, 'error' => 'Paste a Google Maps link first.'];
    }
    if ($ll = latlng_from_text($input)) {
        return ['ok' => true, 'lat' => $ll[0], 'lng' => $ll[1], 'name' => '', 'address' => '', 'method' => 'coordinates'];
    }
    if (!preg_match('#^https?://#i', $input)) {
        if ($pc = plus_code_from_text($input)) {
            return ['ok' => true, 'lat' => $pc[0], 'lng' => $pc[1], 'name' => '', 'address' => '', 'method' => 'plus code'];
        }
        return ['ok' => false, 'error' => 'That doesn\'t look like a Google Maps link. Copy it from the Share button in Google Maps.'];
    }
    $host = strtolower((string) parse_url($input, PHP_URL_HOST));
    if (!preg_match('/(^|\.)(goo\.gl|google\.[a-z.]+|g\.co)$/', $host)) {
        return ['ok' => false, 'error' => 'Only Google Maps links are supported.'];
    }

    $final = $input;
    $body = '';
    if ($c = coords_from_url($input)) {
        return result_from($c, $input, 'link');
    }

    [$final, $body, $err] = http_fetch($input);
    if ($final === '') {
        return ['ok' => false, 'error' => 'Could not open the link (' . ($err ?: 'no response') . '). Drop the pin on the map instead.'];
    }
    // EU-style consent page: the real URL is in the "continue" parameter.
    if (strpos($final, 'consent.google') !== false) {
        parse_str((string) parse_url($final, PHP_URL_QUERY), $qs);
        if (!empty($qs['continue'])) {
            $final = $qs['continue'];
        }
    }
    if ($c = coords_from_url($final)) {
        return result_from($c, $final, 'link');
    }

    // Place pages: Google's own preview data holds the exact pin.
    $html = str_replace(['\\u0026', '\\u003d', '&amp;'], ['&', '=', '&'], $body);
    if (preg_match('#/maps/preview/place\?[^"\'\s<>\\\\]+#', $html, $m)) {
        [, $preview] = http_fetch('https://www.google.com' . $m[0]);
        if (preg_match('/\[null,null,(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)\]/', $preview, $p) && valid_coords($p[1], $p[2])) {
            return result_from([(float) $p[1], (float) $p[2]], $final, 'place');
        }
    }
    if ($pc = plus_code_from_text(rawurldecode($final))) {
        return result_from($pc, $final, 'plus code');
    }
    if (preg_match('/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $html, $p) && valid_coords($p[1], $p[2])) {
        return result_from([(float) $p[1], (float) $p[2]], $final, 'page');
    }
    return ['ok' => false, 'error' => 'Google didn\'t give a location for this link. Drop the pin on the map instead.', 'name' => name_from_url($final)[0]];
}

function result_from(array $c, string $url, string $method): array
{
    [$name, $address] = name_from_url($url);
    return ['ok' => true, 'lat' => round($c[0], 7), 'lng' => round($c[1], 7), 'name' => $name, 'address' => $address, 'method' => $method];
}

function http_fetch(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['', '', 'curl is not available on this server'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => MAPS_UA,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept-Language: en-IN,en;q=0.9'],
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $ca = cfg('ca_bundle');
    if ($ca && is_file($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $body = curl_exec($ch);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['', '', $err];
    }
    return [$final, (string) $body, ''];
}

function latlng_from_text(string $s): ?array
{
    if (preg_match('/^\s*(-?\d{1,2}\.\d+)\s*[,\s]\s*(-?\d{1,3}\.\d+)\s*$/', $s, $m) && valid_coords($m[1], $m[2])) {
        return [(float) $m[1], (float) $m[2]];
    }
    return null;
}

function coords_from_url(string $url): ?array
{
    $u = rawurldecode(html_entity_decode($url));
    $patterns = [
        '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',                                                 // exact place pin
        '/[?&](?:q|query|ll|center|destination|daddr|sll)=(?:loc:)?(-?\d{1,2}\.\d+)\s*,\s*\+?(-?\d{1,3}\.\d+)/',
        '#/(?:place|search|dir)/(-?\d{1,2}\.\d+)\s*,\s*\+?(-?\d{1,3}\.\d+)#',
        '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',                                                     // map view centre
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $u, $m) && valid_coords($m[1], $m[2])) {
            return [(float) $m[1], (float) $m[2]];
        }
    }
    return null;
}

/** ["Aum Darshan CHS ltd", "Shankar Ln, Kandivali West, ..."] from a /maps/place/... URL. */
function name_from_url(string $url): array
{
    if (!preg_match('#/maps/place/([^/@?]+)#', $url, $m)) {
        return ['', ''];
    }
    $text = trim(rawurldecode(str_replace('+', ' ', $m[1])));
    $text = trim(preg_replace('/^[23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3}\s*,?\s*/i', '', $text));
    if (latlng_from_text($text)) {
        return ['', ''];
    }
    $parts = array_map('trim', explode(',', $text));
    $name = array_shift($parts);
    return [$name, implode(', ', $parts)];
}

/* --------------------------------------------------- Open Location Code */

function plus_code_from_text(string $s): ?array
{
    if (!preg_match('/(?<![0-9A-Z])([23456789CFGHJMPQRVWX]{4,8}\+[23456789CFGHJMPQRVWX]{2,3})(?![0-9A-Z])/i', $s, $m)) {
        return null;
    }
    return olc_decode(strtoupper($m[1]));
}

/** Decode a full or short plus code. Short codes are taken as near Mumbai. */
function olc_decode(string $code, float $refLat = 19.1, float $refLng = 72.85): ?array
{
    [$pre, $post] = array_pad(explode('+', $code, 2), 2, '');
    $pre = rtrim($pre, '0');
    if (strlen($pre) > 8 || strlen($pre) % 2 === 1 || strlen($pre) < 2) {
        return null;
    }
    if (strlen($pre) < 8) {
        $pad = 8 - strlen($pre);
        $area = olc_area(substr(olc_encode($refLat, $refLng), 0, $pad) . $pre . $post);
        if (!$area) {
            return null;
        }
        // Pick the copy of the code nearest the reference point.
        $res = 20 ** (2 - $pad / 2);
        [$lat, $lng] = $area;
        if ($refLat + $res / 2 < $lat && $lat - $res >= -90) {
            $lat -= $res;
        } elseif ($refLat - $res / 2 > $lat && $lat + $res <= 90) {
            $lat += $res;
        }
        if ($refLng + $res / 2 < $lng) {
            $lng -= $res;
        } elseif ($refLng - $res / 2 > $lng) {
            $lng += $res;
        }
        return [round($lat, 7), round($lng, 7)];
    }
    $area = olc_area($pre . $post);
    return $area ? [round($area[0], 7), round($area[1], 7)] : null;
}

/** Centre of the area for code digits without "+". */
function olc_area(string $digits): ?array
{
    $pairRes = [20.0, 1.0, 0.05, 0.0025, 0.000125];
    $lat = -90.0;
    $lng = -180.0;
    $latH = $lngH = 0.0;
    $n = strlen($digits);
    for ($p = 0; $p < 5 && $p * 2 + 1 < $n; $p++) {
        $a = strpos(OLC_ALPHABET, $digits[$p * 2]);
        $b = strpos(OLC_ALPHABET, $digits[$p * 2 + 1]);
        if ($a === false || $b === false) {
            return null;
        }
        $lat += $a * $pairRes[$p];
        $lng += $b * $pairRes[$p];
        $latH = $lngH = $pairRes[$p];
    }
    for ($i = 10; $i < $n; $i++) {
        $v = strpos(OLC_ALPHABET, $digits[$i]);
        if ($v === false) {
            return null;
        }
        $latH /= 5;
        $lngH /= 4;
        $lat += intdiv($v, 4) * $latH;
        $lng += ($v % 4) * $lngH;
    }
    return [$lat + $latH / 2, $lng + $lngH / 2];
}

function olc_encode(float $lat, float $lng): string
{
    $lat += 90;
    $lng += 180;
    $code = '';
    foreach ([20.0, 1.0, 0.05, 0.0025, 0.000125] as $res) {
        $a = (int) floor($lat / $res);
        $b = (int) floor($lng / $res);
        $code .= OLC_ALPHABET[$a] . OLC_ALPHABET[$b];
        $lat -= $a * $res;
        $lng -= $b * $res;
    }
    return $code;
}
