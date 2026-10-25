<?php
declare(strict_types=1);

/**
 * OLX.ua — reading listings.
 *
 * OLX's own web pages are built from a JSON API that answers without a
 * sign-in, and PropertyBank reads that rather than the HTML:
 *
 *   /api/v1/offers/?category_id=1758&city_id=268&district_id=19&offset=…&limit=50
 *       the search: 50 listings a page, each with title, description,
 *       parameters (floor, floors, area, rooms, year), price with its
 *       currency, posting date, city, district and a map point
 *   /apigateway/exact-location-backend/api/v1/offers/<id>?representation=public
 *       the street and house number — the "Михайлівський пер. 9" in the
 *       Location panel — for listings whose seller entered one (header
 *       olx-locale: uk-UA; 404 when there is none)
 *
 * What the API does not give, PropertyBank looks for in the text: the
 * ceiling height, the year of construction when the parameter is missing,
 * and an address in the title or description when there is no exact one.
 *
 * The search answers at most 1,000 listings (offset ≤ 999). A district with
 * more is read in price bands, each under that limit, found by halving the
 * price range until each band is small enough (olx_plan).
 *
 * Checked against the live site on 23 October 2026 (category 1758 = flats
 * for sale, city 268 = Kyiv, district 19 = Shevchenkivskyi, 2,454 listings).
 * If OLX changes the API, tools/test-parse.php against fresh copies in
 * tools/fixtures/ shows where.
 */

const OLX_BASE = 'https://www.olx.ua';
/**
 * Raised whenever finding addresses improves. The next refresh then locates
 * again every listing that has no exact position yet (refresh.php).
 *   2  (0.1.1) a street named in the title without a house number
 */
const PB_PARSER_VERSION = '2';
const OLX_PAGE = 50;
const OLX_CAP = 1000;        // listings the search answers at most
const OLX_BAND_MAX = 950;    // a price band is read whole when it holds no more than this

/** @return array<string,string> */
function olx_headers(): array
{
    return ['Accept' => 'application/json', 'Accept-Language' => 'uk-UA,uk;q=0.9', 'olx-locale' => 'uk-UA'];
}

function olx_pause(): void
{
    if (sources_mock() === '') {
        usleep(max(0, (int) setting('olx_delay_ms')) * 1000);
    }
}

/**
 * One page of a search, optionally within a price band in USD (bounds inclusive).
 * @param array<string,int|string> $params
 * @return array{offers:list<array>,total:int}
 */
function olx_search_page(array $params, int $offset, int $limit, ?int $from = null, ?int $to = null): array
{
    $q = ['offset' => $offset, 'limit' => $limit] + $params;
    if ($from !== null || $to !== null) {
        $q['currency'] = 'USD';
        if ($from !== null) {
            $q['filter_float_price:from'] = $from;
        }
        if ($to !== null) {
            $q['filter_float_price:to'] = $to;
        }
    }
    $q['sort_by'] = 'created_at:desc';
    $url = OLX_BASE . '/api/v1/offers/?' . http_build_query($q);
    olx_pause();
    $r = http_json($url, olx_headers());
    if ($r['error'] !== '' || !is_array($r['json']) || !isset($r['json']['data'])) {
        throw new RuntimeException('OLX search failed: ' . ($r['error'] ?: 'unexpected answer'));
    }
    $meta = (array) ($r['json']['metadata'] ?? []);
    return [
        'offers' => array_values((array) $r['json']['data']),
        'total' => (int) ($meta['visible_total_count'] ?? $meta['total_elements'] ?? 0),
    ];
}

function olx_count(array $params, ?int $from, ?int $to): int
{
    return olx_search_page($params, 0, 1, $from, $to)['total'];
}

/**
 * Splits a search into price bands of at most OLX_BAND_MAX listings.
 * @return list<array{from:?int,to:?int,n:int}>
 */
function olx_plan(array $params): array
{
    $total = olx_count($params, null, null);
    if ($total <= OLX_BAND_MAX) {
        return [['from' => null, 'to' => null, 'n' => $total]];
    }
    $bands = [];
    $stack = [[0, 100000000]];
    $guard = 0;
    while ($stack && $guard++ < 80) {
        [$lo, $hi] = array_pop($stack);
        $n = olx_count($params, $lo, $hi);
        if ($n <= OLX_BAND_MAX || $hi - $lo < 1000) {
            if ($n > 0) {
                $bands[] = ['from' => $lo, 'to' => $hi, 'n' => $n];
            }
            continue;
        }
        // Prices spread over orders of magnitude: split at the geometric middle.
        $mid = (int) round(sqrt(max(1, $lo) * $hi) / 1000) * 1000;
        if ($mid <= $lo || $mid >= $hi) {
            $mid = intdiv($lo + $hi, 2);
        }
        $stack[] = [$mid + 1, $hi];
        $stack[] = [$lo, $mid];
    }
    usort($bands, static fn ($a, $b) => $a['from'] <=> $b['from']);
    return $bands;
}

/**
 * The street and house number the seller entered, or null.
 * @return array{street:string,house:string}|null
 */
function olx_exact_location(string $id): ?array
{
    olx_pause();
    $r = http_json(OLX_BASE . '/apigateway/exact-location-backend/api/v1/offers/' . rawurlencode($id) . '?representation=public', olx_headers(), 15);
    if ($r['status'] === 404) {
        return null;
    }
    if ($r['error'] !== '') {
        throw new RuntimeException('OLX location failed: ' . $r['error']);
    }
    $loc = $r['json']['preferences']['location'] ?? null;
    if (!is_array($loc) || ($loc['type'] ?? '') !== 'exact') {
        return null;
    }
    $a = (array) ($loc['address'] ?? []);
    $street = trim((string) ($a['street'] ?? ''));
    if ($street === '') {
        return null;
    }
    return ['street' => $street, 'house' => trim((string) ($a['buildingNo'] ?? ''))];
}

/* ------------------------------------------------------------ parsing --- */

/** The value of a parameter: its key ("6", "45", "secondary_market") or for the price the whole record. */
function olx_param(array $offer, string $key): mixed
{
    foreach ((array) ($offer['params'] ?? []) as $p) {
        if (($p['key'] ?? '') === $key) {
            $v = $p['value'] ?? null;
            if ($key === 'price') {
                return is_array($v) ? $v : null;
            }
            if (is_array($v)) {
                return $v['key'] ?? $v['label'] ?? null;
            }
            return $p['normalizedValue'] ?? $v;
        }
    }
    return null;
}

function olx_param_label(array $offer, string $key): string
{
    foreach ((array) ($offer['params'] ?? []) as $p) {
        if (($p['key'] ?? '') === $key) {
            $v = $p['value'] ?? null;
            return is_array($v) ? (string) ($v['label'] ?? '') : (string) $v;
        }
    }
    return '';
}

function to_num(mixed $v): ?float
{
    if ($v === null) {
        return null;
    }
    $s = str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], (string) $v);
    return preg_match('/^-?\d+(\.\d+)?/', $s, $m) ? (float) $m[0] : null;
}

function plain_text(string $html): string
{
    $s = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $s) ?? $s);
}

/**
 * Text as it should be read. OLX writes every line break as a blank line
 * (its "<br />" followed by a newline) and a paragraph as three, and many
 * lines start with spaces. So: spaces at the ends of lines go, one blank line
 * becomes a line break, two or more become one paragraph break, and the
 * invisible characters some sellers paste in are removed.
 *
 * Used for showing and exporting; the fingerprint of a listing (content_hash)
 * keeps using plain_text(), so tidying never makes a listing look changed.
 *
 * $doubled: true for text straight from OLX (always written that way); null
 * to decide from the text — doubled only when no line ends in a single line
 * break and some gap is three lines or more. That way text tidied before is
 * left as it is, and tidying twice changes nothing.
 */
function tidy_text(?string $s, ?bool $doubled = null): string
{
    if ($s === null || $s === '') {
        return '';
    }
    $s = str_replace(["\r\n", "\r", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{00A0}"], ["\n", "\n", '', '', '', '', ' '], $s);
    $lines = array_map(static fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? $l), explode("\n", $s));
    $s = trim(implode("\n", $lines));
    if ($doubled === null) {
        $doubled = !preg_match('/(?<!\n)\n(?!\n)/', $s) && preg_match('/\n{3,}/', $s) === 1;
    }
    $s = $doubled
        ? preg_replace_callback('/\n{2,}/', static fn ($m) => strlen($m[0]) === 2 ? "\n" : "\n\n", $s)
        : preg_replace('/\n{3,}/', "\n\n", $s);
    return trim((string) $s);
}

/** An OLX ISO time (with offset) as a local DATETIME. */
function olx_time(?string $iso): ?string
{
    if (!$iso) {
        return null;
    }
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/**
 * The key data of one OLX listing, as far as the listing itself says them.
 * The address and coordinates come later (listings.php, enrich).
 */
function olx_parse_offer(array $o): array
{
    $title = trim((string) ($o['title'] ?? ''));
    $desc = plain_text((string) ($o['description'] ?? ''));
    $loc = (array) ($o['location'] ?? []);
    $city = (array) ($loc['city'] ?? []);
    $district = (array) ($loc['district'] ?? []);
    $map = (array) ($o['map'] ?? []);

    $price = olx_param($o, 'price');
    $pv = is_array($price) ? to_num($price['value'] ?? null) : null;
    $pc = is_array($price) ? strtoupper((string) ($price['currency'] ?? '')) : '';

    $floor = to_num(olx_param($o, 'floor'));
    $floors = to_num(olx_param($o, 'total_floors') ?? olx_param($o, 'floors_count'));
    $area = to_num(olx_param($o, 'total_area'));
    $rooms = null;
    if (preg_match('/(\d+)/', olx_param_label($o, 'number_of_rooms_string') ?: olx_param_label($o, 'number_of_rooms'), $m)) {
        $rooms = (int) $m[1];
    }
    $year = to_num(olx_param($o, 'year_of_commissioning') ?? olx_param($o, 'year_construction'));
    $year = $year !== null && plausible_year((int) $year) ? (int) $year : year_from_text($title . "\n" . $desc);

    $text = $title . "\n" . $desc;

    return [
        'source' => 'olx',
        'source_id' => (string) ($o['id'] ?? ''),
        'url' => (string) ($o['url'] ?? ''),
        'title' => mb_substr($title, 0, 300),
        'description' => $desc,
        'olx_city_id' => isset($city['id']) ? (int) $city['id'] : null,
        'city_orig' => (string) ($city['name'] ?? ''),
        'district_orig' => (string) ($district['name'] ?? ''),
        'floor' => $floor !== null ? (int) $floor : null,
        'floors_total' => $floors !== null ? (int) $floors : null,
        'area_m2' => $area,
        'rooms' => $rooms,
        'ceiling_m' => ceiling_from_text($text),
        'year_built' => $year,
        'price_value' => $pv,
        'price_currency' => in_array($pc, ['USD', 'EUR', 'UAH'], true) ? $pc : ($pc === '' ? '' : substr($pc, 0, 3)),
        'posted_at' => olx_time($o['created_time'] ?? null),
        'site_refreshed_at' => olx_time($o['last_refresh_time'] ?? null),
        'map_lat' => isset($map['lat']) ? (float) $map['lat'] : null,
        'map_lon' => isset($map['lon']) ? (float) $map['lon'] : null,
        'map_radius' => isset($map['radius']) ? (float) $map['radius'] : null,
        // A fingerprint of the text only — what the address search and the
        // translation depend on. (Until 0.2.3 it included the parameters, and
        // OLX's hryvnia conversion of the price in them changes with the
        // exchange rate, so every listing looked changed after a rate move.)
        'content_hash' => listing_text_hash($title, $desc),
    ];
}

/** The fingerprint of a listing's title and description. */
function listing_text_hash(string $title, string $desc): string
{
    return sha1(trim($title) . "\n" . trim($desc));
}

/** What is kept of the raw listing: enough to parse it again, without photos and contact data. */
function olx_raw(array $o): string
{
    $keep = array_intersect_key($o, array_flip(['id', 'url', 'title', 'description', 'created_time', 'last_refresh_time',
        'params', 'location', 'map', 'category']));
    return pack_text((string) json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function plausible_year(int $y): bool
{
    return $y >= 1700 && $y <= (int) date('Y') + 6;
}

/* ------------------------------------------------- reading the text --- */

/** Ceiling height in metres from text: "Висока стеля 4 м", "висота стелі Н=2.7 метри", "потолки 3,2м", "стелі 280 см". */
function ceiling_from_text(string $text): ?float
{
    $re = '/(?:стел[яіьеи]\p{L}*|потол\p{L}*)(?:[^0-9\n]{0,30}?)(\d(?:[.,]\d{1,2})?|\d{3})\s*(см|м(?!\p{L})|м\.|метр\p{L}*|m(?!\p{L}))/iu';
    if (preg_match_all($re, $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $v = (float) str_replace(',', '.', $m[1]);
            if (mb_strtolower($m[2]) === 'см' || $v >= 100) {
                $v /= 100;
            }
            if ($v >= 2.0 && $v <= 7.0) {
                return round($v, 2);
            }
        }
    }
    return null;
}

/** Year of construction from text: "1992 року побудови", "будинок 2021 року", "год постройки: 1958". */
function year_from_text(string $text): ?int
{
    $res = [
        '/(\d{4})\s*(?:р\.?|року|рік|г\.?|года|год)\s*(?:побудови|постройки|будівництва|забудови|введення|сдачи|здачі)/iu',
        '/(?:рік|год|року|года)\s+(?:побудови|постройки|будівництва|введення в експлуатацію)\s*[:\-–—]?\s*(\d{4})/iu',
        '/(?:побудован\p{L}*|построен\p{L}*|збудован\p{L}*|будинок|дом)\s+(?:у\s+|в\s+)?(\d{4})\s*(?:р\.?|року|рік|г\.?|года|году)/iu',
    ];
    foreach ($res as $re) {
        if (preg_match($re, $text, $m) && plausible_year((int) $m[1])) {
            return (int) $m[1];
        }
    }
    return null;
}

/**
 * Addresses found in a listing's title and description, most credible first.
 * Each is a street as written (with its kind, when given) and a house number,
 * possibly empty.
 *
 * @return list<array{street:string,house:string,how:string}>
 */
function address_candidates(string $title, string $desc): array
{
    $U = 'А-ЯІЇЄҐ';
    $L = 'а-яіїєґёыэъ';
    $word = "(?:[$U]\\.\\s?)?[$U][$L'’ʼ]+(?:-[$U]?[$L'’ʼ]+)*";
    $name = "$word(?:[ \\-]$word){0,3}";
    $type = '(?i:вулиц[яіюе]|вул\.?|ул\.?|улиц[аеуы]|провулок|провулку|пров\.?|переулок|пер\.|проспект[уі]?|просп\.?|пр-к?т\.?|п-кт\.?|п-т\.?|п-р\.?|пр\.|бульвар[уі]?|бульв\.?|б-р\.?|площ[аіе]|пл\.|узвіз|спуск|набережн[аійя]|наб\.)';
    // A house letter is one of the first few (а, б, в, г, д, е, є, ж, з, і, и) — "3к" is three rooms, not a house.
    $hl = 'абвгдеєжзіиАБВГДЕЄЖЗІИ';
    $house = "(\\d{1,3}(?:\\s?[-–]?\\s?[$hl](?![$L]))?(?:\\s?\\/\\s?\\d{1,3}(?:[-–]?[$hl](?![$L]))?)?)";
    $notAfter = "(?![\\d.,]\\d)(?!\\s?[-–]?\\s?[кk](?!\\p{L}))(?!\\s?(?:м²|м2|кв|м\\b|м\\.|метр|поверх|пов|хв|км|%|\\$|грн|сот|кімн|комн|к\\b|years|р\\.|рок))";
    $sep = '\s*(?:\([^)\n]{1,40}\))?\s*[,.]?\s*(?:(?i:буд(?:инок)?\.?|д\.|№)\s*)?';

    $found = [];
    $add = static function (string $street, string $house, string $how) use (&$found): void {
        $street = trim(preg_replace('/\s+/u', ' ', $street) ?? $street, " ,.");
        // Capitalised words in front of a street that are not part of it: "Києва Михайлівський пров."
        $street = preg_replace('/^(?:(?:Києва|Київ|Києві|Киев|Киева|Центр|Центрі|Продаж|Продам|Продається|Продажа|Квартира|Квартири|Квартиру|Без|Метро|Район|Шевченківський|Шевченковский)\s+)+/u', '', $street) ?? $street;
        $house = trim(preg_replace('/\s+/u', '', $house) ?? $house);
        if ($street === '' || mb_strlen($street) < 4) {
            return;
        }
        foreach ($found as $f) {
            if (mb_strtolower($f['street']) === mb_strtolower($street) && ($f['house'] === $house || $house === '')) {
                return;
            }
        }
        $found[] = ['street' => $street, 'house' => $house, 'how' => $how];
    };

    foreach (['title' => $title, 'description' => $desc] as $where => $text) {
        // "вул. Назарівська, 17", "пр-т Берестейський (Перемоги), 20", "провулок Машинобудівний 25"
        if (preg_match_all("/(?<![\\p{L}])($type)\\s*($name)(?:$sep$house$notAfter)?/u", $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $add(rtrim($m[1], '.') . ' ' . $m[2], $m[3] ?? '', $where);
            }
        }
        // "Берестейський проспект 62 / 1", "Лук’янівська вулиця, 22"
        if (preg_match_all("/($name)\\s+($type)(?![\\p{L}])$sep$house$notAfter/u", $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $add($m[1] . ' ' . rtrim($m[2], '.'), $m[3] ?? '', $where);
            }
        }
    }
    // A street name and a number in the title without the word "street": "Назарівська 17", "Паторжинського 14".
    if (preg_match_all("/(?<!ЖК )(?<![\\p{L}«\"'])($name)\\s*,?\\s+$house$notAfter/u", $title, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $words = preg_split('/\s+/u', trim($m[1])) ?: [];
            $last = (string) end($words);
            if (!preg_match('/(ська|цька|зька|ського|цького|ова|ева|єва|іна|ина|ого|ої|ний|ій|ий|ська|ская|ского)$/u', mb_strtolower($last))) {
                continue;
            }
            $two = count($words) >= 2 ? $words[count($words) - 2] . ' ' . $last : '';
            if ($two !== '' && preg_match('/^[' . $U . ']/u', $two)) {
                $add($two, $m[2], 'title-bare');
            }
            $add($last, $m[2], 'title-bare');
        }
    }
    // A street named in the title without a number: "… Велика Житомирська центр міста …".
    // Looked up as the street (quality "street"), last of all, and accepted only
    // inside the listing's district — so a district's own name or a street of
    // the same name elsewhere in the city is rejected by the geocoder check.
    if (preg_match_all("/(?<!ЖК )(?<![\\p{L}«\"'])($name)(?![\\p{L}])/u", $title, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            $words = preg_split('/\s+/u', trim($m[1])) ?: [];
            $last = (string) end($words);
            $lc = mb_strtolower($last);
            if (!preg_match('/(ська|цька|зька|ського|цького|ская|ского)$/u', $lc)
                || preg_match('/^(шевченк|печерськ|подільськ|подольск|голосі|голосе|дарницьк|деснянськ|дніпровськ|днепровск|оболонськ|солом|святошин|київськ|киевск)/u', $lc)) {
                continue;
            }
            $two = count($words) >= 2 ? $words[count($words) - 2] . ' ' . $last : '';
            if ($two !== '') {
                $add($two, '', 'title-street');
            }
            $add($last, '', 'title-street');
        }
    }
    // Only what can be looked up: with a house number first, at most five.
    usort($found, static fn ($a, $b) => ($a['house'] === '') <=> ($b['house'] === ''));
    return array_slice($found, 0, 5);
}
