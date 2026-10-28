<?php
declare(strict_types=1);

/**
 * FINATUM PropertyBank — stand-in for OLX, Nominatim, the ECB and the NBU,
 * for testing without the internet. NOT REAL LISTINGS: 1,240 invented flats
 * in the shapes of the real APIs (as read on 23 October 2026). tools/ is
 * never deployed.
 *
 *   php -S 127.0.0.1:8099 tools/mock-sources.php
 *   PB_SOURCES_MOCK=http://127.0.0.1:8099/ php -S 127.0.0.1:8000 -t ..
 *
 * Requests arrive as /<host>/<path>, e.g. /www.olx.ua/api/v1/offers/?…
 */

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $q);

function out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

const STREETS = [
    ['Михайлівський провулок', 'Михайлівський пров.', 50.45242, 30.51933],
    ['Глибочицька вулиця', 'вул. Глибочицька', 50.46352, 30.50341],
    ['Ярославів Вал', 'вул. Ярославів Вал', 50.45110, 30.50890],
    ['Січових Стрільців вулиця', 'вул. Січових Стрільців', 50.45640, 30.50010],
    ['Берестейський проспект', 'проспект Берестейський', 50.45210, 30.45300],
    ['Велика Житомирська вулиця', 'вул. Велика Житомирська', 50.45460, 30.51270],
    ['Олеся Гончара вулиця', 'вул. Олеся Гончара', 50.45180, 30.50560],
    ['Паторжинського вулиця', 'вул. Паторжинського', 50.44990, 30.51060],
    ['Коперника вулиця', 'вул. Коперника', 50.45700, 30.44800],
    ['Лук\'янівська вулиця', 'вул. Лук’янівська', 50.46290, 30.48230],
];

/** The invented listings, the same on every request. */
function offers(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    mt_srand(20261023);
    $all = [];
    // A second round (tools/mock-round2 exists): some prices changed, 15 new ads.
    $round2 = is_file(__DIR__ . '/mock-round2');
    for ($i = 0; $i < ($round2 ? 1255 : 1240); $i++) {
        $id = 900000000 + $i * 7;
        $s = STREETS[$i % count(STREETS)];
        $house = (string) (1 + ($i * 13) % 90);
        $rooms = 1 + $i % 4;
        $area = 30 + ($i * 37) % 160;
        $floors = 5 + $i % 20;
        $floor = 1 + ($i * 7) % $floors;
        $usd = (int) round((40000 + $area * (1400 + ($i * 31) % 1600)) / 500) * 500;
        if ($round2 && $i % 97 === 0) {
            $usd -= 5000;
        }
        $cur = $i % 17 === 0 ? 'UAH' : ($i % 23 === 0 ? 'EUR' : 'USD');
        $value = $cur === 'UAH' ? $usd * 41 : ($cur === 'EUR' ? (int) round($usd / 1.08 / 500) * 500 : $usd);
        $mode = $i % 5;
        $title = match ($mode) {
            0 => 'Продаж ' . $rooms . 'к квартири ' . $area . ' м² | ' . $s[1] . ' ' . $house,
            1 => 'Продам ' . $rooms . '-кімнатну квартиру, центр',
            2 => 'ЖК Тест ' . $rooms . 'к квартира без комісії',
            3 => 'Продажа квартиры, ' . $rooms . ' комнаты',
            default => 'Квартира ' . $area . ' м2',
        };
        $desc = match ($mode) {
            1 => 'Квартира за адресою: ' . $s[1] . ', ' . $house . '. Висота стелі ' . (2.6 + ($i % 9) / 10) . ' м. Будинок ' . (1900 + $i % 120) . ' року побудови.',
            3 => 'Продается квартира. Высота потолков 3,1 м. Дом 1958 года постройки.',
            default => 'Світла квартира у тихому центрі. Поруч метро.',
        };
        $params = [
            ['key' => 'price', 'name' => 'Ціна', 'type' => 'price', 'value' => ['value' => $value, 'type' => 'price', 'currency' => $cur,
                'negotiable' => false, 'converted_value' => $cur === 'UAH' ? null : $usd * 41, 'converted_currency' => $cur === 'UAH' ? null : 'UAH',
                'label' => number_format($value, 0, '.', ' ') . ($cur === 'USD' ? ' $' : ($cur === 'EUR' ? ' €' : ' грн.'))]],
            ['key' => 'floor', 'name' => 'Поверх', 'type' => 'input', 'value' => ['key' => (string) $floor, 'label' => (string) $floor]],
            ['key' => 'total_floors', 'name' => 'Поверховість', 'type' => 'input', 'value' => ['key' => (string) $floors, 'label' => (string) $floors]],
            ['key' => 'total_area', 'name' => 'Загальна площа', 'type' => 'input', 'value' => ['key' => (string) $area, 'label' => $area . ' м²']],
            ['key' => 'number_of_rooms_string', 'name' => 'Кількість кімнат', 'type' => 'select', 'value' => ['key' => 'x', 'label' => $rooms . ' кімнати']],
        ];
        if ($i % 3 === 0) {
            $params[] = ['key' => 'year_of_commissioning', 'name' => 'Рік введення в експлуатацію', 'type' => 'input',
                'value' => ['key' => (string) (1950 + $i % 75), 'label' => (string) (1950 + $i % 75)]];
        }
        $all[] = [
            'id' => $id,
            'url' => 'https://www.olx.ua/d/uk/obyavlenie/test-' . $id . '.html',
            'title' => $title,
            'description' => $desc,
            'created_time' => date('c', strtotime('2026-10-22 12:00') - $i * 3600),
            'last_refresh_time' => date('c', strtotime('2026-10-22 12:00') - $i * 1800),
            'params' => $params,
            'location' => ['city' => ['id' => 268, 'name' => 'Київ', 'normalized_name' => 'kiev'],
                'district' => ['id' => 19, 'name' => 'Шевченківський'], 'region' => ['id' => 25, 'name' => 'Київська область']],
            'map' => $mode === 4 ? ['zoom' => 14, 'lat' => 50.4600, 'lon' => 30.4900, 'radius' => 1, 'show_detailed' => false]
                : ['zoom' => 12, 'lat' => 50.47135, 'lon' => 30.4624, 'radius' => 3, 'show_detailed' => false],
            'category' => ['id' => 1758, 'type' => 'real_estate'],
            '_usd' => $usd, '_street' => $s, '_house' => $house, '_exact' => $mode === 2,
        ];
    }
    // The same flat advertised again by other sellers: every 40th flat twice more, other ad numbers and prices.
    $copies = [];
    foreach ($all as $k => $o) {
        if ($k % 40 === 0) {
            foreach ([1, 2] as $c) {
                $d = $o;
                $d['id'] = $o['id'] + 500000000 + $c;
                $d['url'] = 'https://www.olx.ua/d/uk/obyavlenie/test-' . $d['id'] . '.html';
                $d['_usd'] = $o['_usd'] + $c * 3000;
                foreach ($d['params'] as &$prm) {
                    if ($prm['key'] === 'price') {
                        $prm['value']['value'] = $prm['value']['currency'] === 'USD' ? $d['_usd'] : $prm['value']['value'];
                    }
                }
                unset($prm);
                $copies[] = $d;
            }
        }
    }
    $all = array_merge($all, $copies);
    return $all;
}

$parts = explode('/', ltrim($path, '/'));
$host = $parts[0] ?? '';
$rest = '/' . implode('/', array_slice($parts, 1));

if ($host === 'www.olx.ua' && str_starts_with($rest, '/api/v1/offers')) {
    $from = isset($q['filter_float_price:from']) ? (int) $q['filter_float_price:from'] : null;
    $to = isset($q['filter_float_price:to']) ? (int) $q['filter_float_price:to'] : null;
    $list = array_values(array_filter(offers(), static fn ($o) => ($from === null || $o['_usd'] >= $from) && ($to === null || $o['_usd'] <= $to)));
    $offset = (int) ($q['offset'] ?? 0);
    if ($offset >= 1000) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><title>Ой, щось пішло не так</title></html>';
        exit;
    }
    $limit = min(50, (int) ($q['limit'] ?? 40));
    $page = array_map(static fn ($o) => array_diff_key($o, ['_usd' => 1, '_street' => 1, '_house' => 1, '_exact' => 1]), array_slice($list, $offset, $limit));
    out(['data' => $page, 'metadata' => ['total_elements' => min(1000, count($list)), 'visible_total_count' => count($list)]]);
}

if ($host === 'www.olx.ua' && preg_match('#/exact-location-backend/api/v1/offers/(\d+)#', $rest, $m)) {
    if (($_SERVER['HTTP_OLX_LOCALE'] ?? '') !== 'uk-UA') {
        out('olx-locale param is invalid', 400);
    }
    foreach (offers() as $o) {
        if ((string) $o['id'] === $m[1]) {
            if ($o['_exact']) {
                out(['offerID' => $o['id'], 'preferences' => ['location' => ['type' => 'exact', 'address' => [
                    'city' => 'Київ', 'district' => 'Шевченківський', 'street' => $o['_street'][1], 'buildingNo' => $o['_house']]]]]);
            }
            break;
        }
    }
    out(['errors' => [['code' => 'OFFER_NOT_FOUND']]], 404);
}

if ($host === 'nominatim.openstreetmap.org' && str_starts_with($rest, '/search')) {
    $text = mb_strtolower((string) ($q['street'] ?? $q['q'] ?? ''));
    if (str_contains($text, 'золоті ворота')) {
        out([['lat' => '50.4488526', 'lon' => '30.5133663', 'addresstype' => 'tourism', 'address' => ['borough' => 'Шевченківський район']]]);
    }
    foreach (STREETS as $s) {
        $core = mb_strtolower(preg_replace('/\s*(вулиця|провулок|проспект)\s*/u', '', $s[0]) ?? '');
        $core = str_replace(["'", '’'], '', $core);
        if ($core !== '' && str_contains(str_replace(["'", '’'], '', $text), $core) && preg_match('/^(\d+)/', $text, $h)) {
            $n = (int) $h[1];
            out([['lat' => (string) ($s[2] + ($n % 10) * 0.0003), 'lon' => (string) ($s[3] + ($n % 7) * 0.0004), 'addresstype' => 'building',
                'address' => ['road' => $s[0], 'house_number' => (string) $n, 'borough' => 'Шевченківський район']]]);
        }
    }
    out([]);
}

if (($host === 'api-free.deepl.com' || $host === 'api.deepl.com') && str_starts_with($rest, '/v2/translate')) {
    if (!str_starts_with((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'DeepL-Auth-Key ')) {
        out(['message' => 'Forbidden'], 403);
    }
    if (str_contains((string) $_SERVER['HTTP_AUTHORIZATION'], 'quota')) {
        out(['message' => 'Quota exceeded'], 456);
    }
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    out(['translations' => array_map(static fn ($t) => ['detected_source_language' => 'UK', 'text' => '[EN] ' . $t], (array) ($body['text'] ?? []))]);
}

if ($host === 'www.ecb.europa.eu') {
    header('Content-Type: text/xml');
    echo "<?xml version=\"1.0\"?><gesmes:Envelope><Cube><Cube time='2026-10-23'><Cube currency='USD' rate='1.0812'/></Cube></Cube></gesmes:Envelope>";
    exit;
}

if ($host === 'bank.gov.ua') {
    out([['r030' => 840, 'txt' => 'Долар США', 'rate' => 41.25, 'cc' => 'USD', 'exchangedate' => '23.10.2026'],
         ['r030' => 978, 'txt' => 'Євро', 'rate' => 44.6, 'cc' => 'EUR', 'exchangedate' => '23.10.2026']]);
}

http_response_code(404);
echo 'mock: nothing at ' . $path;
