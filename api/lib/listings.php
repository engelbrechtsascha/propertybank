<?php
declare(strict_types=1);

/**
 * Listings: storing what a site says, finding where the flat is, and the
 * distances.
 *
 *   listing_upsert   a listing from a search page: new, changed or unchanged.
 *                    A new or changed one is marked needs_enrich.
 *   listing_enrich   where it is: the seller's exact address (OLX), else an
 *                    address in the text, each looked up with the geocoder
 *                    and accepted only inside the listing's city and
 *                    district; else OLX's own map point when it is within a
 *                    kilometre; else nothing. Then the distances.
 *   geo_recompute    the distances of every listing of a city, after its
 *                    landmarks or stations changed.
 */

/**
 * Adds the translation columns to an installation made before 0.2.0 — the
 * first request that needs them does it, so updating needs no database step.
 */
function ensure_listing_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        q('SELECT translated_by FROM listings LIMIT 1');
        return;
    } catch (Throwable) {
        // not there yet: add whichever columns are missing
    }
    $sqlite = (string) cfg('driver', 'mysql') === 'sqlite';
    foreach ([
        'title_en' => $sqlite ? 'TEXT' : 'VARCHAR(400) NULL',
        'description_en' => $sqlite ? 'TEXT' : 'MEDIUMTEXT NULL',
        'translated_from' => $sqlite ? 'TEXT' : 'VARCHAR(8) NULL',
        'translated_at' => $sqlite ? 'TEXT' : 'DATETIME NULL',
        'translated_by' => $sqlite ? 'TEXT' : 'VARCHAR(40) NULL',
    ] as $col => $type) {
        try {
            q('SELECT ' . $col . ' FROM listings LIMIT 1');
        } catch (Throwable) {
            try {
                db()->exec(sql('ALTER TABLE listings ADD COLUMN ' . $col . ' ' . $type));
            } catch (Throwable) {
                // added by a parallel request
            }
        }
    }
}

/** @return 'new'|'changed'|'same' */
function listing_upsert(array $p, int $searchId, array $raw): string
{
    ensure_listing_columns();
    if ($p['source_id'] === '' || $p['url'] === '') {
        return 'same';
    }
    $now = now_sql();
    $fx = fx_latest();
    $conv = fx_convert($p['price_value'], $p['price_currency'], $fx);
    $existing = one('SELECT id, content_hash, raw FROM listings WHERE source = ? AND source_id = ?', [$p['source'], $p['source_id']]);
    $city = $p['olx_city_id'] ? city_by_olx_id($p['olx_city_id']) : null;

    $cols = [
        'search_id' => $searchId, 'url' => $p['url'], 'title' => $p['title'],
        'city_id' => $city['id'] ?? null,
        'city_orig' => $p['city_orig'], 'city_en' => $city['name_en'] ?? name_en($p['city_orig']),
        'district_orig' => $p['district_orig'], 'district_en' => district_en($p['district_orig']),
        'floor' => $p['floor'], 'floors_total' => $p['floors_total'], 'area_m2' => $p['area_m2'], 'rooms' => $p['rooms'],
        'ceiling_m' => $p['ceiling_m'], 'year_built' => $p['year_built'],
        'price_value' => $p['price_value'], 'price_currency' => $p['price_currency'],
        'price_usd' => $conv['usd'], 'price_eur' => $conv['eur'],
        'posted_at' => $p['posted_at'], 'site_refreshed_at' => $p['site_refreshed_at'],
        'last_seen_at' => $now, 'active' => 1, 'raw' => olx_raw($raw),
    ];

    if (!$existing) {
        $cols += ['source' => $p['source'], 'source_id' => $p['source_id'], 'first_seen_at' => $now,
            'content_hash' => $p['content_hash'], 'needs_enrich' => 1];
        q('INSERT INTO listings (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($cols));
        return 'new';
    }
    $changed = $existing['content_hash'] !== $p['content_hash'];
    if ($changed) {
        // A fingerprint made the old way (before 0.2.3) differs although the
        // text may not: compare the text itself before treating it as changed.
        $old = json_decode(unpack_text($existing['raw'] ?? ''), true) ?: [];
        if ($old && listing_text_hash((string) ($old['title'] ?? ''), plain_text((string) ($old['description'] ?? ''))) === $p['content_hash']) {
            $changed = false;
        }
        $cols['content_hash'] = $p['content_hash'];
    }
    if ($changed) {
        $cols['needs_enrich'] = 1;
        // The text changed on OLX: the translation is made again when next opened.
        $cols['title_en'] = null;
        $cols['description_en'] = null;
        $cols['translated_at'] = null;
    }
    $set = implode(', ', array_map(static fn ($c) => $c . ' = ?', array_keys($cols)));
    q('UPDATE listings SET ' . $set . ' WHERE id = ?', [...array_values($cols), $existing['id']]);
    return $changed ? 'changed' : 'same';
}

/** A district name reduced to its stem, for comparing "Шевченківський" with "Шевченківський район" or "Шевченковский". */
function district_stem(string $d): string
{
    return mb_substr(mb_strtolower(trim($d)), 0, 6);
}

/**
 * Looks one address up and accepts it only inside the city and, when the
 * listing names one, the district.
 * @return array{lat:float,lon:float,road:string,house:string,borough:string,kind:string}|null
 */
function geocode_listing_address(string $street, string $house, array $row, ?array $city): ?array
{
    $cityName = $row['city_orig'] ?: ($city['name_orig'] ?? '');
    // The kind of street helps the geocoder in Ukrainian, so a Russian
    // abbreviation is spelt out the Ukrainian way.
    [$type, $name] = street_split($street);
    $uk = ['Street' => 'вулиця', 'Lane' => 'провулок', 'Avenue' => 'проспект', 'Boulevard' => 'бульвар', 'Square' => 'площа',
        'Descent' => 'узвіз', 'Embankment' => 'набережна', 'Highway' => 'шосе', 'Passage' => 'проїзд', 'Alley' => 'алея', 'Dead End' => 'тупик'];
    $tries = [];
    $streetQ = trim($name . ($type ? ' ' . $uk[$type] : ''));
    $tries[] = ['street' => trim($house . ' ' . $streetQ), 'city' => $cityName, 'country' => 'Україна'];
    if ($type) {
        $tries[] = ['street' => trim($house . ' ' . $name), 'city' => $cityName, 'country' => 'Україна'];
    }
    foreach ($tries as $params) {
        $g = geocode($params);
        if (!$g) {
            continue;
        }
        if ($city && !point_in_city($g['lat'], $g['lon'], $city)) {
            continue;
        }
        if ($row['district_orig'] !== '' && $g['borough'] !== ''
            && !str_contains(mb_strtolower($g['borough']), district_stem($row['district_orig']))) {
            continue;
        }
        if (in_array($g['kind'], ['city', 'state', 'country', 'borough', 'suburb', 'county'], true)) {
            continue;
        }
        return $g;
    }
    return null;
}

/**
 * Finds where a listing is and stores address, coordinates and distances.
 * Network: one request to OLX, up to a few to the geocoder (cached).
 * @return string the quality found: exact | text | street | approx | none
 */
function listing_enrich(array $row): string
{
    $city = $row['city_id'] ? city_by_id((int) $row['city_id']) : null;
    $raw = json_decode(unpack_text($row['raw'] ?? ''), true) ?: [];
    $p = $raw ? olx_parse_offer($raw) : null;

    $street = '';
    $house = '';
    $quality = 'none';
    $lat = $lon = null;
    $road = '';
    $candidates = [];

    if ($row['source'] === 'olx') {
        $exact = olx_exact_location((string) $row['source_id']);
        if ($exact) {
            $candidates[] = $exact + ['how' => 'exact'];
        }
    }
    if ($p) {
        foreach (address_candidates($p['title'], $p['description']) as $c) {
            $candidates[] = $c;
        }
    }

    $tried = 0;
    foreach ($candidates as $c) {
        if ($tried >= 4) {
            break;
        }
        $tried++;
        $g = geocode_listing_address($c['street'], $c['house'], $row, $city);
        if ($g) {
            $street = $c['street'];
            $house = $c['house'];
            $lat = $g['lat'];
            $lon = $g['lon'];
            $road = $g['road'];
            $housed = $c['house'] !== '' && $g['house'] !== '';
            $quality = $c['how'] === 'exact' ? ($housed ? 'exact' : 'street') : ($housed ? 'text' : 'street');
            break;
        }
    }
    // Nothing found by the geocoder: keep the seller's address as text at least.
    if ($lat === null && $candidates && $candidates[0]['how'] === 'exact') {
        $street = $candidates[0]['street'];
        $house = $candidates[0]['house'];
    }
    // OLX's map point, when it is meant to be within about a kilometre.
    if ($lat === null && $p && $p['map_lat'] !== null && $p['map_radius'] !== null && $p['map_radius'] <= 1.0) {
        $lat = $p['map_lat'];
        $lon = $p['map_lon'];
        $quality = 'approx';
    }

    $streetForEn = $road !== '' ? $road : $street;
    $houseForEn = $house;
    $geo = ($lat !== null && $city) ? geo_for_point($lat, $lon, $city) : null;

    q('UPDATE listings SET street_orig = ?, street_en = ?, house_no = ?, address_orig = ?, address_en = ?, loc_quality = ?,
                           lat = ?, lon = ?, geo = ?, needs_enrich = 0, enriched_at = ? WHERE id = ?', [
        mb_substr($road !== '' ? $road : $street, 0, 200), mb_substr(street_en($streetForEn), 0, 200), mb_substr($house, 0, 30),
        mb_substr(address_orig($road !== '' ? $road : $street, $house), 0, 250),
        mb_substr(address_en($streetForEn, $houseForEn), 0, 250),
        $quality, $lat, $lon, $geo ? json_encode($geo, JSON_UNESCAPED_UNICODE) : null, now_sql(), $row['id'],
    ]);
    return $quality;
}

/**
 * A listing's title and description, original and in English, as stored —
 * the English is made during a refresh (refresh.php, translate.php).
 */
function listing_description(int $id): array
{
    ensure_listing_columns();
    $row = one('SELECT id, title, raw, title_en, description_en, translated_from, translated_at, translated_by FROM listings WHERE id = ?', [$id]);
    if (!$row) {
        fail('not_found', 'That listing no longer exists.', 404);
    }
    $raw = json_decode(unpack_text($row['raw'] ?? ''), true) ?: [];
    $note = '';
    if ($row['translated_at'] === null) {
        $note = translator_ready() ? 'Not translated yet — the next refresh translates it.'
            : 'Not translated yet — export the untranslated listings under Accounts and settings → Translation.';
    }
    return [
        'title' => tidy_text((string) ($raw['title'] ?? $row['title'])), 'description' => tidy_text(plain_text((string) ($raw['description'] ?? '')), true),
        'title_en' => $row['title_en'] !== null ? tidy_text($row['title_en']) : null,
        'description_en' => $row['description_en'] !== null ? tidy_text($row['description_en']) : null,
        'from' => $row['translated_from'], 'translated_at' => $row['translated_at'],
        'service' => $row['translated_by'] ?: translator_name(), 'note' => $note,
    ];
}

/** Distances of every listing in a city, after its landmarks or stations changed. Returns how many. */
function geo_recompute(int $cityId): int
{
    $city = city_by_id($cityId);
    if (!$city) {
        return 0;
    }
    $n = 0;
    foreach (all('SELECT id, lat, lon FROM listings WHERE city_id = ? AND lat IS NOT NULL', [$cityId]) as $r) {
        $geo = geo_for_point((float) $r['lat'], (float) $r['lon'], $city);
        q('UPDATE listings SET geo = ? WHERE id = ?', [json_encode($geo, JSON_UNESCAPED_UNICODE), $r['id']]);
        $n++;
    }
    return $n;
}

/** Every listing as the browser shows it. */
function listings_for_browser(bool $withInactive): array
{
    ensure_listing_columns();
    $rows = all('SELECT id, source, source_id, url, title, city_id, city_en, city_orig, district_en, district_orig,
                        street_en, street_orig, house_no, address_en, address_orig, loc_quality, lat, lon, geo,
                        floor, floors_total, area_m2, rooms, ceiling_m, year_built, price_value, price_currency,
                        price_usd, price_eur, posted_at, site_refreshed_at, first_seen_at, last_seen_at, active, needs_enrich, title_en
                   FROM listings' . ($withInactive ? '' : ' WHERE active = 1') . ' ORDER BY posted_at DESC');
    $num = static fn ($v) => $v === null ? null : (float) $v;
    $int = static fn ($v) => $v === null ? null : (int) $v;
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'], 'src' => $r['source'], 'sid' => $r['source_id'], 'url' => $r['url'], 'title' => tidy_text($r['title']),
        'title_en' => $r['title_en'] !== null ? tidy_text($r['title_en']) : null,
        'city_id' => $int($r['city_id']), 'city' => $r['city_en'], 'city_o' => $r['city_orig'],
        'district' => $r['district_en'], 'district_o' => $r['district_orig'],
        'street' => $r['street_en'], 'street_o' => $r['street_orig'], 'house' => $r['house_no'],
        'address' => $r['address_en'], 'address_o' => $r['address_orig'], 'q' => $r['loc_quality'],
        'lat' => $num($r['lat']), 'lon' => $num($r['lon']), 'geo' => $r['geo'] ? json_decode((string) $r['geo'], true) : null,
        'floor' => $int($r['floor']), 'floors' => $int($r['floors_total']), 'area' => $num($r['area_m2']), 'rooms' => $int($r['rooms']),
        'ceiling' => $num($r['ceiling_m']), 'year' => $int($r['year_built']),
        'pv' => $num($r['price_value']), 'pc' => $r['price_currency'], 'usd' => $num($r['price_usd']), 'eur' => $num($r['price_eur']),
        'posted' => $r['posted_at'], 'site_refreshed' => $r['site_refreshed_at'], 'first_seen' => $r['first_seen_at'],
        'last_seen' => $r['last_seen_at'], 'active' => (int) $r['active'] === 1, 'pending' => (int) $r['needs_enrich'] === 1,
    ], $rows);
}
