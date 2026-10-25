<?php
declare(strict_types=1);

/**
 * FINATUM PropertyBank — checks of the parts that read listings: the OLX
 * record, addresses in the text, ceiling height, year, transliteration,
 * distances. Run before a release:   php tools/test-parse.php
 *
 * The fixtures are copies of real answers from OLX (23 October 2026). When
 * OLX changes its API, save fresh copies over them and see what breaks.
 */

$root = dirname(__DIR__);
function cfg(string $k, mixed $d = null): mixed { return $d; }
function setting(string $n): string { return '0'; }
require $root . '/api/lib/version.php';
require $root . '/api/lib/fetch.php';
require $root . '/api/lib/translit.php';
require $root . '/api/lib/olx.php';
require $root . '/api/lib/geo.php';

$ok = 0; $bad = 0;
function check(string $what, mixed $got, mixed $want): void
{
    global $ok, $bad;
    if ($got === $want) { $ok++; return; }
    $bad++;
    echo "FAIL  $what\n      got:  " . var_export($got, true) . "\n      want: " . var_export($want, true) . "\n";
}

// --- the OLX record --------------------------------------------------------
$o = json_decode((string) file_get_contents(__DIR__ . '/fixtures/olx_offer_935797158.json'), true);
$p = olx_parse_offer($o);
check('source id', $p['source_id'], '935797158');
check('city', $p['city_orig'], 'Київ');
check('district', $p['district_orig'], 'Шевченківський');
check('floor', $p['floor'], 6);
check('floors', $p['floors_total'], 6);
check('area', $p['area_m2'], 45.0);
check('rooms', $p['rooms'], 1);
check('ceiling from the description', $p['ceiling_m'], 4.0);
check('price', $p['price_value'], 117000.0);
check('currency', $p['price_currency'], 'USD');
check('posted (Kyiv 09:59 = Berlin 08:59 in summer time)', substr((string) $p['posted_at'], 0, 10), '2026-09-24');
check('map radius', $p['map_radius'], 2.0);

$ex = json_decode((string) file_get_contents(__DIR__ . '/fixtures/olx_exact_935797158.json'), true);
$a = $ex['preferences']['location']['address'];
check('exact address in English', address_en($a['street'], $a['buildingNo']), '9 Mykhailivskyi Lane');

// --- English ---------------------------------------------------------------
check('Kyiv', name_en('Київ'), 'Kyiv');
check('district', district_en('Шевченківський'), 'Shevchenkivskyi');
check('district, Russian', district_en('Шевченковский р-н'), 'Shevchenkivskyi');
check('street from geocoder', address_en('Глибочицька вулиця', '73'), '73 Hlybochytska Street');
check('apostrophe', address_en('Лук’янівська вулиця', '22'), '22 Lukianivska Street');
check('зг', street_en('Згурівська вулиця'), 'Zghurivska Street');
check('Val', address_en('Ярославів Вал', '14'), '14 Yaroslaviv Val');
check('avenue', address_en('проспект Берестейський', '42А'), '42A Beresteiskyi Avenue');
check('Russian', street_en('ул. Дегтяревская'), 'Degtyarevskaya Street');

// --- addresses in text -----------------------------------------------------
$first = static function (string $t, string $d = ''): string {
    $c = address_candidates($t, $d);
    return $c ? $c[0]['street'] . '|' . $c[0]['house'] : '';
};
check('title with type after', $first('Продаж 1к центр Києва Михайлівський пров. 9  рядом м. Золоті Ворота'), 'Михайлівський пров|9');
check('description, вул.', $first('Продаж 3к', 'за адресою: вул. Олександра Довженка, 16-Б, Шулявка'), 'вул Олександра Довженка|16-Б');
check('dot before number', $first('ЖК Podil Plaza, вул. Глибочицька.73'), 'вул Глибочицька|73');
check('avenue with old name', $first('* 3 к. кв. пр. Берестейський (Перемоги) 20 Політехнічний'), 'пр Берестейський|20');
check('type after, slash', $first('за адресою: Берестейський проспект 62 / 1 поруч метро'), 'Берестейський проспект|62/1');
check('hyphenated name', $first('Продажа', 'за адресою: вул. Довгар-Запольського, 1. Простір'), 'вул Довгар-Запольського|1');
check('bare street in title', $first('Продаж 3к квартири 198 м² | Назарівська 17 | вид на Ботсад'), 'Назарівська|17');
check('rooms are not a house', array_column(address_candidates('Ключі! Видова 4-к 123м2', ''), 'street'), []);
check('no address', address_candidates('Продам квартиру без комісії', 'Світла квартира 45 м², 6 поверх.'), []);
$streets = static fn (string $t): array => array_map(static fn ($c) => $c['street'] . '|' . $c['how'], address_candidates($t, ''));
check('street without a number in the title (OLX 922039215)',
    $streets('Золоті ворота продаж 2к Хрещатик Велика Житомирська центр міста Пейзажна алея Воздвиженка'),
    ['Велика Житомирська|title-street', 'Житомирська|title-street']);
check('a district is not a street', $streets('Продаж 3к квартири, ЖК Златоустівський, Шевченківський р-н'), []);
check('a numbered address still comes first', $streets('Продаж 1к центр Києва Михайлівський пров. 9 рядом м. Золоті Ворота')[0], 'Михайлівський пров|title');

// --- ceiling and year ------------------------------------------------------
check('ceiling Н=2.7 метри', ceiling_from_text('висота стелі Н=2.7 метри'), 2.7);
check('ceiling 3,5 м', ceiling_from_text('Висота стелі — 3,5 м. Квартира'), 3.5);
check('ceiling Russian', ceiling_from_text('Высота потолков 3,1 м'), 3.1);
check('ceiling in cm', ceiling_from_text('стелі 280 см'), 2.8);
check('no ceiling number', ceiling_from_text('Високі стелі та великі вікна'), null);
check('plasterboard is not a height', ceiling_from_text('гіпсокартонна стеля з вбудованим освітленням і 2 санвузли'), null);
check('year', year_from_text('1992 року побудови за адресою'), 1992);
check('year Russian', year_from_text('Дом 1958 года постройки'), 1958);
check('year будинок', year_from_text('будинок 2021 року (введений в експлуатацію'), 2021);

// --- tidy text -------------------------------------------------------------
check('OLX line breaks and paragraphs', tidy_text("Перший рядок \n\n  Другий рядок\n\n\n\n Новий абзац\u{00A0}\n\n", true), "Перший рядок\nДругий рядок\n\nНовий абзац");
check('OLX text with line breaks only', tidy_text("a\n\nb\n\nc", true), "a\nb\nc");
check('doubled style recognised', tidy_text("a\n\nb\n\n\n\nc"), "a\nb\n\nc");
check('tidy text left alone', tidy_text("a\nb\n\nc"), "a\nb\n\nc");
check('paragraphs only left alone', tidy_text("a\n\nb\n\nc"), "a\n\nb\n\nc");
check('tidying twice changes nothing', tidy_text(tidy_text("x\n\ny\n\n\n\nz", true)), "x\ny\n\nz");
check('long gaps in tidy text shortened', tidy_text("a\nb\n\n\n\n\nc"), "a\nb\n\nc");

// --- distances -------------------------------------------------------------
$gg = haversine_m(50.4488526, 30.5133663, 50.4495513, 30.5253858);
check('Golden Gate to Maidan ≈ 855 m', (int) round($gg / 5) * 5, 855);

echo str_repeat('-', 50) . "\n" . $ok . ' passed, ' . $bad . " failed\n";
exit($bad ? 1 : 0);
