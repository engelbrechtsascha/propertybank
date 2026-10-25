<?php
declare(strict_types=1);

/**
 * Exchange rates, fetched at the start of each refresh.
 *
 *   EUR/USD   the ECB's euro reference rate (published around 16:00 CET on
 *             TARGET days) — the rate a European reader expects
 *   UAH       the National Bank of Ukraine's official rates, for listings
 *             priced in hryvnia
 *
 * If the ECB cannot be reached, EUR/USD is taken as the cross of the NBU's
 * two hryvnia rates; if neither answers, the last rates stored are used and
 * the annunciator says from when they are.
 */

const ECB_DAILY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
const NBU_RATES = 'https://bank.gov.ua/NBUStatService/v1/statdirectory/exchange?json';

/** @return array{eurusd:float,usduah:?float,euruah:?float,rate_date:?string,source:string,fetched_at:string}|null */
function fx_latest(): ?array
{
    $row = one('SELECT * FROM fx_rates ORDER BY id DESC LIMIT 1');
    if (!$row) {
        return null;
    }
    return [
        'eurusd' => (float) $row['eurusd'],
        'usduah' => $row['usduah'] !== null ? (float) $row['usduah'] : null,
        'euruah' => $row['euruah'] !== null ? (float) $row['euruah'] : null,
        'rate_date' => $row['rate_date'] ? (string) $row['rate_date'] : null,
        'source' => (string) $row['source'],
        'fetched_at' => (string) $row['fetched_at'],
    ];
}

/**
 * Fetches today's rates and stores them. Returns the rates in force and a
 * note when something could not be fetched.
 *
 * @return array{fx:?array,note:string}
 */
function fx_refresh(): array
{
    $notes = [];
    $eurusd = null;
    $date = null;
    $src = [];

    $r = http_get(ECB_DAILY, [], 15);
    if ($r['error'] === '' && $r['status'] === 200 && preg_match("/currency='USD'\\s+rate='([0-9.]+)'/", $r['body'], $m)) {
        $eurusd = (float) $m[1];
        if (preg_match("/time='(\\d{4}-\\d{2}-\\d{2})'/", $r['body'], $d)) {
            $date = $d[1];
        }
        $src[] = 'ECB';
    } else {
        $notes[] = 'ECB not reachable (' . ($r['error'] ?: 'HTTP ' . $r['status']) . ')';
    }

    $usduah = $euruah = null;
    $n = http_json(NBU_RATES, [], 15);
    if ($n['error'] === '' && is_array($n['json'])) {
        foreach ($n['json'] as $row) {
            if (($row['cc'] ?? '') === 'USD') {
                $usduah = (float) $row['rate'];
            } elseif (($row['cc'] ?? '') === 'EUR') {
                $euruah = (float) $row['rate'];
            }
        }
        if ($usduah && $euruah) {
            $src[] = 'NBU';
            if ($eurusd === null) {
                $eurusd = $euruah / $usduah;
                if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', (string) ($n['json'][0]['exchangedate'] ?? ''), $d)) {
                    $date = $d[3] . '-' . $d[2] . '-' . $d[1];
                }
            }
        }
    } else {
        $notes[] = 'NBU not reachable (' . $n['error'] . ')';
    }

    if ($eurusd === null || $eurusd <= 0) {
        return ['fx' => fx_latest(), 'note' => 'No new exchange rate — ' . implode('; ', $notes) . '. The last one stored is used.'];
    }
    q('INSERT INTO fx_rates (eurusd, usduah, euruah, rate_date, source, fetched_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$eurusd, $usduah, $euruah, $date, implode(' + ', $src), now_sql()]);
    return ['fx' => fx_latest(), 'note' => implode('; ', $notes)];
}

/**
 * A price in USD and EUR at the given rates.
 * @return array{usd:?float,eur:?float}
 */
function fx_convert(?float $value, string $currency, ?array $fx): array
{
    if ($value === null || $value <= 0) {
        return ['usd' => null, 'eur' => null];
    }
    if ($currency === 'USD') {
        return ['usd' => $value, 'eur' => $fx ? round($value / $fx['eurusd'], 2) : null];
    }
    if ($currency === 'EUR') {
        return ['usd' => $fx ? round($value * $fx['eurusd'], 2) : null, 'eur' => $value];
    }
    if ($currency === 'UAH' && $fx && $fx['usduah'] && $fx['euruah']) {
        return ['usd' => round($value / $fx['usduah'], 2), 'eur' => round($value / $fx['euruah'], 2)];
    }
    return ['usd' => null, 'eur' => null];
}

/** Recomputes the converted prices of every listing at the latest rates. */
function fx_apply_all(): void
{
    $fx = fx_latest();
    if (!$fx) {
        return;
    }
    q("UPDATE listings SET price_usd = price_value, price_eur = ROUND(price_value / ?, 2) WHERE price_currency = 'USD'", [$fx['eurusd']]);
    q("UPDATE listings SET price_eur = price_value, price_usd = ROUND(price_value * ?, 2) WHERE price_currency = 'EUR'", [$fx['eurusd']]);
    if ($fx['usduah'] && $fx['euruah']) {
        q("UPDATE listings SET price_usd = ROUND(price_value / ?, 2), price_eur = ROUND(price_value / ?, 2) WHERE price_currency = 'UAH'",
            [$fx['usduah'], $fx['euruah']]);
    }
}
