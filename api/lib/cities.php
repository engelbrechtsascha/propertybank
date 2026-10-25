<?php
declare(strict_types=1);

/**
 * Cities: the landmarks distances are measured to, and the metro stations.
 *
 * Both are per city and editable in the application (Cities page). A change
 * recomputes the distances of every listing in that city at once — no site
 * is asked again, the coordinates are already stored.
 *
 * Kyiv is set up at installation:
 *   landmarks  the Golden Gate and the Maidan (Independence Monument),
 *              coordinates from OpenStreetMap
 *   stations   all 52 stations of the Kyiv Metro, from OpenStreetMap
 *              (railway=station, station=subway), October 2026, with their
 *              line: M1 Sviatoshynsko-Brovarska (red), M2 Obolonsko-Teremkivska
 *              (blue), M3 Syretsko-Pecherska (green)
 */

const KYIV_OLX_CITY_ID = 268;

const KYIV_LANDMARKS = [
    ['key' => 'golden_gate', 'name' => 'Golden Gate', 'lat' => 50.4488526, 'lon' => 30.5133663],
    ['key' => 'maidan', 'name' => 'Maidan', 'lat' => 50.4495513, 'lon' => 30.5253858],
];

const KYIV_STATIONS = [
        ['name' => "Akademmistechko", 'name_orig' => "Академмістечко", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.464704, 'lon' => 30.355083],
        ['name' => "Arsenalna", 'name_orig' => "Арсенальна", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.444488, 'lon' => 30.545433],
        ['name' => "Beresteiska", 'name_orig' => "Берестейська", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.459092, 'lon' => 30.419688],
        ['name' => "Chernihivska", 'name_orig' => "Чернігівська", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.45989, 'lon' => 30.630312],
        ['name' => "Darnytsia", 'name_orig' => "Дарниця", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.45594, 'lon' => 30.612842],
        ['name' => "Dnipro", 'name_orig' => "Дніпро", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.441041, 'lon' => 30.558688],
        ['name' => "Hidropark", 'name_orig' => "Гідропарк", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.445993, 'lon' => 30.577034],
        ['name' => "Khreshchatyk", 'name_orig' => "Хрещатик", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.447187, 'lon' => 30.522946],
        ['name' => "Lisova", 'name_orig' => "Лісова", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.46476, 'lon' => 30.64597],
        ['name' => "Livoberezhna", 'name_orig' => "Лівобережна", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.451863, 'lon' => 30.598168],
        ['name' => "Nyvky", 'name_orig' => "Нивки", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.458596, 'lon' => 30.404205],
        ['name' => "Politekhnichnyi Instytut", 'name_orig' => "Політехнічний інститут", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.450793, 'lon' => 30.466127],
        ['name' => "Shuliavska", 'name_orig' => "Шулявська", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.455075, 'lon' => 30.44537],
        ['name' => "Sviatoshyn", 'name_orig' => "Святошин", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.457825, 'lon' => 30.390801],
        ['name' => "Teatralna", 'name_orig' => "Театральна", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.44521, 'lon' => 30.518045],
        ['name' => "Universytet", 'name_orig' => "Університет", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.444247, 'lon' => 30.505893],
        ['name' => "Vokzalna", 'name_orig' => "Вокзальна", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.44164, 'lon' => 30.488251],
        ['name' => "Zhytomyrska", 'name_orig' => "Житомирська", 'line' => 'M1', 'colour' => '#D7261E', 'lat' => 50.456172, 'lon' => 30.365873],
        ['name' => "Demiivska", 'name_orig' => "Деміївська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.404932, 'lon' => 30.516914],
        ['name' => "Heroiv Dnipra", 'name_orig' => "Героїв Дніпра", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.52267, 'lon' => 30.498899],
        ['name' => "Holosiivska", 'name_orig' => "Голосіївська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.397329, 'lon' => 30.508162],
        ['name' => "Ipodrom", 'name_orig' => "Іподром", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.376553, 'lon' => 30.469045],
        ['name' => "Kontraktova Ploshcha", 'name_orig' => "Контрактова площа", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.465835, 'lon' => 30.515081],
        ['name' => "Lybidska", 'name_orig' => "Либідська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.413111, 'lon' => 30.524828],
        ['name' => "Maidan Nezalezhnosti", 'name_orig' => "Майдан Незалежності", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.449936, 'lon' => 30.524441],
        ['name' => "Minska", 'name_orig' => "Мінська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.512171, 'lon' => 30.498574],
        ['name' => "Obolon", 'name_orig' => "Оболонь", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.501533, 'lon' => 30.498223],
        ['name' => "Olimpiiska", 'name_orig' => "Олімпійська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.432282, 'lon' => 30.516403],
        ['name' => "Palats Ukraina", 'name_orig' => "Палац «Україна»", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.420682, 'lon' => 30.521309],
        ['name' => "Ploshcha Ukrainskykh Heroiv", 'name_orig' => "Площа Українських Героїв", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.439417, 'lon' => 30.516642],
        ['name' => "Pochaina", 'name_orig' => "Почайна", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.486195, 'lon' => 30.497891],
        ['name' => "Poshtova Ploshcha", 'name_orig' => "Поштова площа", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.458776, 'lon' => 30.524894],
        ['name' => "Tarasa Shevchenka", 'name_orig' => "Тараса Шевченка", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.473541, 'lon' => 30.504539],
        ['name' => "Teremky", 'name_orig' => "Теремки", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.366905, 'lon' => 30.453821],
        ['name' => "Vasylkivska", 'name_orig' => "Васильківська", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.393335, 'lon' => 30.488223],
        ['name' => "Vystavkovyi Tsentr", 'name_orig' => "Виставковий центр", 'line' => 'M2', 'colour' => '#0A6EB4', 'lat' => 50.382499, 'lon' => 30.477584],
        ['name' => "Boryspilska", 'name_orig' => "Бориспільська", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.403273, 'lon' => 30.684265],
        ['name' => "Chervonyi Khutir", 'name_orig' => "Червоний Хутір", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.409459, 'lon' => 30.696177],
        ['name' => "Dorohozhychi", 'name_orig' => "Дорогожичі", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.473734, 'lon' => 30.448777],
        ['name' => "Kharkivska", 'name_orig' => "Харківська", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.40069, 'lon' => 30.652306],
        ['name' => "Klovska", 'name_orig' => "Кловська", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.436929, 'lon' => 30.531921],
        ['name' => "Lukianivska", 'name_orig' => "Лукʼянівська", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.462362, 'lon' => 30.481805],
        ['name' => "Osokorky", 'name_orig' => "Осокорки", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.395186, 'lon' => 30.616249],
        ['name' => "Palats Sportu", 'name_orig' => "Палац спорту", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.438289, 'lon' => 30.520869],
        ['name' => "Pecherska", 'name_orig' => "Печерська", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.427625, 'lon' => 30.538926],
        ['name' => "Pozniaky", 'name_orig' => "Позняки", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.397897, 'lon' => 30.634662],
        ['name' => "Slavutych", 'name_orig' => "Славутич", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.3942, 'lon' => 30.605158],
        ['name' => "Syrets", 'name_orig' => "Сирець", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.476389, 'lon' => 30.430831],
        ['name' => "Vydubychi", 'name_orig' => "Видубичі", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.402093, 'lon' => 30.560301],
        ['name' => "Vyrlytsia", 'name_orig' => "Вирлиця", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.402895, 'lon' => 30.666116],
        ['name' => "Zoloti Vorota", 'name_orig' => "Золоті ворота", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.448246, 'lon' => 30.513479],
        ['name' => "Zvirynetska", 'name_orig' => "Звіринецька", 'line' => 'M3', 'colour' => '#1A9A48', 'lat' => 50.41825, 'lon' => 30.545028],
];

/** Sets Kyiv up when the cities table is empty (the installer, or a first request). */
function cities_seed(): void
{
    if (one('SELECT id FROM cities LIMIT 1')) {
        return;
    }
    q('INSERT INTO cities (olx_city_id, name_en, name_orig, landmarks, stations, updated_at) VALUES (?, ?, ?, ?, ?, ?)', [
        KYIV_OLX_CITY_ID, 'Kyiv', 'Київ',
        json_encode(KYIV_LANDMARKS, JSON_UNESCAPED_UNICODE),
        json_encode(KYIV_STATIONS, JSON_UNESCAPED_UNICODE),
        now_sql(),
    ]);
    if (!one('SELECT id FROM searches LIMIT 1')) {
        q('INSERT INTO searches (source, label, params, active) VALUES (?, ?, ?, 1)', [
            'olx', 'Kyiv · Shevchenkivskyi · flats for sale',
            json_encode(['category_id' => 1758, 'region_id' => 25, 'city_id' => KYIV_OLX_CITY_ID, 'district_id' => 19]),
        ]);
    }
}

/** @return list<array{id:int,olx_city_id:?int,name_en:string,name_orig:string,landmarks:list<array>,stations:list<array>,updated_at:string}> */
function cities_all(): array
{
    cities_seed();
    $out = [];
    foreach (all('SELECT * FROM cities ORDER BY name_en') as $r) {
        $out[] = city_row($r);
    }
    return $out;
}

function city_row(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'olx_city_id' => $r['olx_city_id'] !== null ? (int) $r['olx_city_id'] : null,
        'name_en' => (string) $r['name_en'],
        'name_orig' => (string) $r['name_orig'],
        'landmarks' => array_values((array) json_decode((string) $r['landmarks'], true)),
        'stations' => array_values((array) json_decode((string) $r['stations'], true)),
        'updated_at' => (string) $r['updated_at'],
    ];
}

function city_by_olx_id(int $olxCityId): ?array
{
    static $cache = [];
    if (!array_key_exists($olxCityId, $cache)) {
        $r = one('SELECT * FROM cities WHERE olx_city_id = ?', [$olxCityId]);
        $cache[$olxCityId] = $r ? city_row($r) : null;
    }
    return $cache[$olxCityId];
}

function city_by_id(int $id): ?array
{
    $r = one('SELECT * FROM cities WHERE id = ?', [$id]);
    return $r ? city_row($r) : null;
}

/**
 * Checks and normalises landmarks or stations sent from the Cities page.
 * @return list<array>
 */
function city_points(mixed $list, string $what): array
{
    if (!is_array($list)) {
        fail('validation', ucfirst($what) . ' must be a list.', 422);
    }
    $out = [];
    $keys = [];
    foreach ($list as $i => $p) {
        $name = trim((string) ($p['name'] ?? ''));
        $lat = $p['lat'] ?? null;
        $lon = $p['lon'] ?? null;
        if ($name === '' || !is_numeric($lat) || !is_numeric($lon) || abs((float) $lat) > 90 || abs((float) $lon) > 180
            || ((float) $lat === 0.0 && (float) $lon === 0.0)) {
            fail('validation', ucfirst($what) . ' ' . ($i + 1) . ' needs a name and a latitude and longitude in degrees.', 422);
        }
        $row = ['name' => mb_substr($name, 0, 80), 'lat' => round((float) $lat, 7), 'lon' => round((float) $lon, 7)];
        if ($what === 'landmark') {
            $key = trim((string) ($p['key'] ?? '')) ?: strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', translit($name)) ?? '', '_'));
            $key = mb_substr($key !== '' ? $key : 'lm' . ($i + 1), 0, 40);
            while (in_array($key, $keys, true)) {
                $key .= '_';
            }
            $keys[] = $key;
            $row = ['key' => $key] + $row;
        } else {
            $row['name_orig'] = mb_substr(trim((string) ($p['name_orig'] ?? '')), 0, 80);
            $row['line'] = mb_substr(trim((string) ($p['line'] ?? '')), 0, 20);
            $colour = trim((string) ($p['colour'] ?? ''));
            $row['colour'] = preg_match('/^#[0-9a-fA-F]{6}$/', $colour) ? $colour : '';
        }
        $out[] = $row;
    }
    if ($what === 'landmark' && count($out) > 6) {
        fail('validation', 'At most six landmarks per city.', 422);
    }
    if (count($out) > 400) {
        fail('validation', 'Too many stations.', 422);
    }
    return $out;
}
