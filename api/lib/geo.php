<?php
declare(strict_types=1);

/**
 * Coordinates and distances.
 *
 * Distances are great-circle ("as the crow flies") distances in metres, by
 * the haversine formula on a sphere of 6,371,008.8 m — within a city the
 * error against the ellipsoid is a few metres in a kilometre, far less than
 * the uncertainty of where a building's door is.
 *
 * Addresses become coordinates through Nominatim (OpenStreetMap). Its usage
 * policy asks for at most one request a second, a cache, and an identifiable
 * application: every answer is kept in geocode_cache, requests are spaced by
 * GEOCODE_GAP_US, and each carries a Referer naming this installation.
 */

const EARTH_RADIUS_M = 6371008.8;
const GEOCODE_GAP_US = 1100000;

function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = $p2 - $p1;
    $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
}

/**
 * Distances from one point to a city's landmarks and its two nearest stations.
 *
 * @param array{landmarks:list<array>,stations:list<array>} $city
 * @return array{lm:array<string,int>,st:list<array{n:string,o:string,l:string,c:string,m:int,lat:float,lon:float}>}
 */
function geo_for_point(float $lat, float $lon, array $city): array
{
    $lm = [];
    foreach ($city['landmarks'] as $l) {
        $lm[(string) $l['key']] = (int) round(haversine_m($lat, $lon, (float) $l['lat'], (float) $l['lon']));
    }
    $st = [];
    foreach ($city['stations'] as $s) {
        $st[] = [
            'n' => (string) $s['name'], 'o' => (string) ($s['name_orig'] ?? ''), 'l' => (string) ($s['line'] ?? ''),
            'c' => (string) ($s['colour'] ?? ''),
            'm' => (int) round(haversine_m($lat, $lon, (float) $s['lat'], (float) $s['lon'])),
            'lat' => (float) $s['lat'], 'lon' => (float) $s['lon'],
        ];
    }
    usort($st, static fn (array $a, array $b): int => $a['m'] <=> $b['m']);
    return ['lm' => $lm, 'st' => array_slice($st, 0, 2)];
}

/* ------------------------------------------------------------ geocoding --- */

/**
 * Looks up an address. Returns null when the geocoder found nothing (that is
 * cached too) and throws when it could not be asked at all.
 *
 * @param array<string,string> $params Nominatim search parameters (street, city, country — or q)
 * @return array{lat:float,lon:float,road:string,house:string,borough:string,kind:string}|null
 */
function geocode(array $params): ?array
{
    ksort($params);
    $query = http_build_query($params);
    $hash = sha1($query);
    $hit = one('SELECT * FROM geocode_cache WHERE qhash = ?', [$hash]);
    if ($hit) {
        return (int) $hit['ok'] === 1 ? [
            'lat' => (float) $hit['lat'], 'lon' => (float) $hit['lon'], 'road' => (string) $hit['road'],
            'house' => (string) $hit['house'], 'borough' => (string) $hit['borough'], 'kind' => (string) $hit['kind'],
        ] : null;
    }

    geocode_wait();
    $base = rtrim(setting('geocoder_url') ?: 'https://nominatim.openstreetmap.org/', '/') . '/';
    $url = $base . 'search?' . http_build_query($params + [
        'format' => 'jsonv2', 'limit' => '1', 'addressdetails' => '1', 'accept-language' => 'uk',
    ]);
    $headers = [
        'User-Agent' => 'FINATUM-PropertyBank/' . PB_VERSION . ' (personal property research)',
        'Referer'    => 'https://www.finatum.de' . app_base_path(),
    ];
    $contact = trim(setting('geocoder_contact'));
    if ($contact !== '') {
        $headers['From'] = $contact;
    }
    $r = http_json($url, $headers, 20);
    if ($r['error'] !== '') {
        throw new RuntimeException('The geocoder did not answer: ' . $r['error']);
    }
    $first = is_array($r['json']) ? ($r['json'][0] ?? null) : null;
    $result = null;
    if (is_array($first) && isset($first['lat'], $first['lon'])) {
        $a = (array) ($first['address'] ?? []);
        $result = [
            'lat' => (float) $first['lat'], 'lon' => (float) $first['lon'],
            'road' => (string) ($a['road'] ?? $a['pedestrian'] ?? $a['square'] ?? ''),
            'house' => (string) ($a['house_number'] ?? ''),
            'borough' => (string) ($a['borough'] ?? $a['city_district'] ?? $a['district'] ?? ''),
            'kind' => (string) ($first['addresstype'] ?? $first['type'] ?? ''),
        ];
    }
    q('INSERT INTO geocode_cache (qhash, query, ok, lat, lon, road, house, borough, kind, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $hash, mb_substr($query, 0, 400), $result ? 1 : 0, $result['lat'] ?? null, $result['lon'] ?? null,
        mb_substr($result['road'] ?? '', 0, 200), mb_substr($result['house'] ?? '', 0, 40),
        mb_substr($result['borough'] ?? '', 0, 120), mb_substr($result['kind'] ?? '', 0, 40), now_sql(),
    ]);
    return $result;
}

/** One request a second, also across the steps of a refresh. */
function geocode_wait(): void
{
    static $last = 0.0;
    if (sources_mock() !== '') {
        return;
    }
    $now = microtime(true);
    $since = ($now - $last) * 1e6;
    if ($last > 0 && $since < GEOCODE_GAP_US) {
        usleep((int) (GEOCODE_GAP_US - $since));
    }
    $last = microtime(true);
}

/** True when a point lies within the city's box (the stations and landmarks, with a margin). */
function point_in_city(float $lat, float $lon, array $city): bool
{
    $pts = array_merge($city['landmarks'], $city['stations']);
    if (!$pts) {
        return true;
    }
    $lats = array_map(static fn ($p) => (float) $p['lat'], $pts);
    $lons = array_map(static fn ($p) => (float) $p['lon'], $pts);
    $m = 0.12;   // about 13 km north–south, 9 km east–west at Kyiv's latitude
    return $lat >= min($lats) - $m && $lat <= max($lats) + $m && $lon >= min($lons) - $m && $lon <= max($lons) + $m;
}
