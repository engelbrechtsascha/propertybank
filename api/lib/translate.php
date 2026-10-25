<?php
declare(strict_types=1);

/**
 * Listing texts into English.
 *
 * Names (city, district, street) are transliterated by rule (translit.php).
 * Free text — a listing's title and description — needs a translation
 * service. Two are supported, each with its own API key, set by an
 * administrator under Accounts and settings → Translation:
 *
 *   DeepL         api-free.deepl.com for a free key (ending ":fx", 500,000
 *                 characters a month), api.deepl.com for a paid one
 *   Google Cloud  Translation API v2 (translation.googleapis.com)
 *
 * The key stays on the server and is never sent to a browser. Translation
 * happens during a refresh, before anything is shown: every listing not yet
 * in English — new ones, and ones whose text changed on OLX — is translated
 * and the English stored in the row (title_en, description_en). Listings are
 * sent in batches, several to a request.
 */

/** Listings per request; each brings a title and a description. */
const TRANSLATE_BATCH = 10;
/** DeepL takes at most 128 KiB per request; stay well below. */
const TRANSLATE_MAX_BYTES = 90000;

/** The service's allowance for the month is used up. */
final class TranslationQuotaException extends RuntimeException
{
}

const TRANSLATORS = ['', 'deepl', 'google'];

function translator(): string
{
    $t = setting('translator');
    return in_array($t, TRANSLATORS, true) ? $t : '';
}

function translator_key(): string
{
    $k = trim((string) (getenv('PB_TRANSLATOR_KEY') ?: cfg('translator_key', '') ?: setting('translator_key')));
    return $k;
}

function translator_ready(): bool
{
    return translator() !== '' && translator_key() !== '';
}

function translator_name(): string
{
    return ['deepl' => 'DeepL', 'google' => 'Google Translate'][translator()] ?? '';
}

/**
 * Translates texts into English, in one request.
 * @param list<string> $texts
 * @return array{texts:list<string>,from:string}
 */
function translate_to_english(array $texts): array
{
    if (!translator_ready()) {
        throw new RuntimeException('No translation service is set up (Accounts and settings → Translation).');
    }
    $key = translator_key();
    if (translator() === 'deepl') {
        $host = str_ends_with($key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
        $r = http_post_json($host . '/v2/translate', ['text' => $texts, 'target_lang' => 'EN-GB', 'preserve_formatting' => true],
            ['Authorization' => 'DeepL-Auth-Key ' . $key]);
        if ($r['status'] === 456) {
            throw new TranslationQuotaException('DeepL: this month\'s character allowance is used up.');
        }
        if ($r['error'] !== '') {
            throw new RuntimeException('DeepL: ' . deepl_reason($r));
        }
        $out = array_map(static fn ($t) => (string) ($t['text'] ?? ''), (array) ($r['json']['translations'] ?? []));
        $from = (string) ($r['json']['translations'][0]['detected_source_language'] ?? '');
    } else {
        $r = http_post_json('https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($key),
            ['q' => $texts, 'target' => 'en', 'format' => 'text']);
        if ($r['status'] === 403 && str_contains(strtolower((string) ($r['json']['error']['message'] ?? '')), 'quota')) {
            throw new TranslationQuotaException('Google Translate: the quota is used up.');
        }
        if ($r['error'] !== '') {
            throw new RuntimeException('Google Translate: ' . ($r['json']['error']['message'] ?? $r['error']));
        }
        $tr = (array) ($r['json']['data']['translations'] ?? []);
        $out = array_map(static fn ($t) => html_entity_decode((string) ($t['translatedText'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $tr);
        $from = (string) ($tr[0]['detectedSourceLanguage'] ?? '');
    }
    if (count($out) !== count($texts)) {
        throw new RuntimeException('The translation service answered in an unexpected form.');
    }
    return ['texts' => $out, 'from' => strtoupper(substr($from, 0, 2))];
}

function deepl_reason(array $r): string
{
    return match ($r['status']) {
        403 => 'the API key was refused (check it under Accounts and settings)',
        456 => 'this month\'s character allowance is used up',
        429 => 'too many requests just now; try again in a minute',
        default => (string) ($r['json']['message'] ?? $r['error']),
    };
}

/**
 * Translates listings (rows with id, title, raw) and stores the English.
 * Sends as many as fit into one request. Returns how many were stored.
 *
 * @param list<array<string,mixed>> $rows
 */
function translate_listings(array $rows): int
{
    $texts = [];
    $ids = [];
    $bytes = 0;
    foreach ($rows as $r) {
        $raw = json_decode(unpack_text($r['raw'] ?? ''), true) ?: [];
        $title = (string) ($raw['title'] ?? $r['title']);
        $desc = tidy_text(plain_text((string) ($raw['description'] ?? '')), true);
        $size = strlen($title) + strlen($desc);
        if ($ids && $bytes + $size > TRANSLATE_MAX_BYTES) {
            break;     // the rest goes in the next request
        }
        $bytes += $size;
        $texts[] = $title;
        $texts[] = $desc;
        $ids[] = (int) $r['id'];
    }
    if (!$ids) {
        return 0;
    }
    $t = translate_to_english($texts);
    foreach ($ids as $i => $id) {
        q('UPDATE listings SET title_en = ?, description_en = ?, translated_from = ?, translated_at = ?, translated_by = ? WHERE id = ?',
            [mb_substr(tidy_text($t['texts'][2 * $i]), 0, 400), tidy_text($t['texts'][2 * $i + 1]), $t['from'], now_sql(), translator_name(), $id]);
    }
    return count($ids);
}

/* ------------------------------------------- translating outside the app ---
 *
 * Without a service, the texts can be translated elsewhere (by Claude, by a
 * person): Export writes every listing not yet in English to a JSON file,
 * Import reads the same file back with title_en and description_en filled in.
 * Each item carries the listing's content hash, so a translation of a text
 * that changed on OLX in the meantime is not stored.
 *
 *   {"format":"propertybank-translations","version":1,"exported_at":…,
 *    "listings":[{"id":12,"olx_id":"935797158","hash":"…","title":"…",
 *                 "description":"…","title_en":"","description_en":""}, …]}
 */

/** @return array<string,mixed> */
function translations_export(bool $all): array
{
    ensure_listing_columns();
    $rows = all('SELECT id, source_id, title, raw, content_hash FROM listings WHERE active = 1'
        . ($all ? '' : ' AND translated_at IS NULL') . ' ORDER BY id');
    $items = [];
    foreach ($rows as $r) {
        $raw = json_decode(unpack_text($r['raw'] ?? ''), true) ?: [];
        $items[] = [
            'id' => (int) $r['id'], 'olx_id' => (string) $r['source_id'], 'hash' => (string) $r['content_hash'],
            'title' => tidy_text((string) ($raw['title'] ?? $r['title'])), 'description' => tidy_text(plain_text((string) ($raw['description'] ?? '')), true),
            'title_en' => '', 'description_en' => '',
        ];
    }
    return ['format' => 'propertybank-translations', 'version' => 1, 'exported_at' => now_sql(),
        'instructions' => 'Fill in title_en and description_en with English translations; leave every other field unchanged.',
        'listings' => $items];
}

/**
 * Stores translations from an imported file.
 * @param list<array<string,mixed>> $items
 * @return array{stored:int,changed:int,unknown:int,empty:int}
 */
function translations_import(array $items, string $by): array
{
    ensure_listing_columns();
    $res = ['stored' => 0, 'changed' => 0, 'unknown' => 0, 'empty' => 0];
    $now = now_sql();
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $titleEn = tidy_text((string) ($it['title_en'] ?? ''));
        $descEn = tidy_text((string) ($it['description_en'] ?? ''));
        if ($titleEn === '' && $descEn === '') {
            $res['empty']++;
            continue;
        }
        $row = one('SELECT id, content_hash, title, raw FROM listings WHERE id = ?', [(int) ($it['id'] ?? 0)]);
        if (!$row) {
            $res['unknown']++;
            continue;
        }
        // Stored only when the text translated is still the listing's text:
        // the fingerprint matches, or the original in the file is the same.
        if ((string) ($it['hash'] ?? '') !== (string) $row['content_hash']) {
            $raw = json_decode(unpack_text($row['raw'] ?? ''), true) ?: [];
            // Compared tidied, as exported files carry the tidied text (since 0.2.4).
            $now_text = listing_text_hash(tidy_text((string) ($raw['title'] ?? $row['title'])), tidy_text(plain_text((string) ($raw['description'] ?? '')), true));
            if ($now_text !== listing_text_hash(tidy_text((string) ($it['title'] ?? '')), tidy_text((string) ($it['description'] ?? '')))) {
                $res['changed']++;      // the text changed on OLX since the export
                continue;
            }
        }
        q('UPDATE listings SET title_en = ?, description_en = ?, translated_from = ?, translated_at = ?, translated_by = ? WHERE id = ?', [
            mb_substr($titleEn, 0, 400), mb_substr($descEn, 0, 60000), mb_substr(strtoupper((string) ($it['from'] ?? '')), 0, 8) ?: null,
            $now, mb_substr($by, 0, 40), $row['id'],
        ]);
        $res['stored']++;
    }
    return $res;
}

/** The settings as an administrator's browser sees them: the key only as "set" and its last four characters. */
function settings_for_admin(): array
{
    $s = settings(true);
    $key = (string) ($s['translator_key'] ?? '');
    unset($s['translator_key'], $s['parser_version']);
    $s['translator_key_set'] = translator_key() !== '' ? '1' : '0';
    $s['translator_key_hint'] = $key !== '' ? '…' . substr($key, -4) : '';
    $s['translator_key_from_config'] = (getenv('PB_TRANSLATOR_KEY') ?: cfg('translator_key', '')) ? '1' : '0';
    return $s;
}
