<?php
declare(strict_types=1);

/**
 * A refresh, in steps.
 *
 * A shared host stops a PHP request after 30–60 seconds; reading 2,500
 * listings and locating the new ones takes many minutes (the geocoder allows
 * one address a second). So a refresh is a row in refresh_runs with its work
 * queue in `state`, and the browser asks for one step after another
 * (POST refresh/step), each working for about STEP_SECONDS. Closing the page
 * pauses the refresh; opening it again offers to continue.
 *
 *   fx       today's exchange rates (ECB, NBU)
 *   plan     for each search, the price bands that keep each under OLX's
 *            1,000-listing limit
 *   list     every page of every band: new and changed listings are stored
 *            and marked for locating; when a search has been read completely,
 *            its listings that were not seen any more are marked removed
 *   enrich   each new or changed listing is located (listings.php)
 *   translate  the title and description of each listing not yet in
 *            English, a batch at a time (translate.php), stored in the row
 *   finish   the USD and EUR prices of all listings at today's rate
 */

const STEP_SECONDS = 18;
const MAX_FAILURES_IN_A_ROW = 4;

function run_load(int $id): ?array
{
    $r = one('SELECT * FROM refresh_runs WHERE id = ?', [$id]);
    if (!$r) {
        return null;
    }
    $r['id'] = (int) $r['id'];
    $r['state'] = json_decode((string) $r['state'], true) ?: [];
    $r['stats'] = json_decode((string) $r['stats'], true) ?: [];
    return $r;
}

function run_save(array $run): void
{
    q('UPDATE refresh_runs SET status = ?, phase = ?, state = ?, stats = ?, message = ?, updated_at = ?, finished_at = ? WHERE id = ?', [
        $run['status'], $run['phase'], json_encode($run['state'], JSON_UNESCAPED_UNICODE), json_encode($run['stats']),
        mb_substr((string) $run['message'], 0, 400), now_sql(), $run['finished_at'] ?? null, $run['id'],
    ]);
}

function run_current(): ?array
{
    $r = one("SELECT id FROM refresh_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1");
    return $r ? run_load((int) $r['id']) : null;
}

function run_last_done(): ?array
{
    $r = one("SELECT id FROM refresh_runs WHERE status = 'done' ORDER BY id DESC LIMIT 1");
    return $r ? run_load((int) $r['id']) : null;
}

/** Starts a refresh, or returns the one already running. */
function run_start(array $user): array
{
    $cur = run_current();
    if ($cur) {
        return $cur;
    }
    $searches = [];
    foreach (all('SELECT * FROM searches WHERE active = 1 ORDER BY id') as $s) {
        $searches[] = ['id' => (int) $s['id'], 'label' => (string) $s['label'], 'source' => (string) $s['source'],
            'params' => json_decode((string) $s['params'], true) ?: [], 'bands' => null, 'band' => 0, 'offset' => 0,
            'seen' => 0, 'complete' => false];
    }
    if (!$searches) {
        fail('validation', 'There is no active search to refresh. Add one on the Sources page.', 422);
    }
    // The address parser improved since these listings were located: try the
    // ones without an exact position again (the geocoder's cache keeps it quick).
    if (setting('parser_version') !== PB_PARSER_VERSION) {
        q("UPDATE listings SET needs_enrich = 1 WHERE active = 1 AND loc_quality IN ('none', 'approx', 'street')");
        set_setting('parser_version', PB_PARSER_VERSION);
    }
    $now = now_sql();
    q("INSERT INTO refresh_runs (user_id, status, phase, state, stats, message, started_at, updated_at) VALUES (?, 'running', 'fx', ?, ?, '', ?, ?)",
        [$user['id'], json_encode(['searches' => $searches, 'si' => 0, 'skip' => [], 'fails' => 0, 'notes' => []], JSON_UNESCAPED_UNICODE),
            json_encode(['new' => 0, 'changed' => 0, 'same' => 0, 'removed' => 0, 'located' => 0, 'not_located' => 0, 'enrich_total' => 0,
                'translated' => 0, 'translate_total' => 0]),
            $now, $now]);
    $runId = last_id();
    audit($user, 'refresh', $runId, 'start', 'Refresh started');
    return run_load($runId);
}

function run_cancel(array $run): array
{
    $run['status'] = 'cancelled';
    $run['finished_at'] = now_sql();
    $run['message'] = 'Stopped. Listings read so far are kept; the next refresh starts again from the beginning.';
    run_save($run);
    return $run;
}

/** Does about STEP_SECONDS of work and saves where it got to. */
function run_step(array $run): array
{
    @set_time_limit(STEP_SECONDS + 60);
    $deadline = microtime(true) + STEP_SECONDS;
    $st = &$run['state'];

    while ($run['status'] === 'running' && microtime(true) < $deadline) {
        try {
            switch ($run['phase']) {
                case 'fx':
                    $fx = fx_refresh();
                    if ($fx['note'] !== '') {
                        $st['notes'][] = $fx['note'];
                    }
                    $run['phase'] = 'plan';
                    break;

                case 'plan':
                    $planned = true;
                    foreach ($st['searches'] as &$s) {
                        if ($s['bands'] === null) {
                            $s['bands'] = olx_plan($s['params']);
                            $planned = false;
                            break;
                        }
                    }
                    unset($s);
                    if ($planned) {
                        $run['phase'] = 'list';
                    }
                    break;

                case 'list':
                    if (!run_list_page($run)) {
                        $run['phase'] = 'enrich';
                        $run['stats']['enrich_total'] = (int) (one('SELECT COUNT(*) AS n FROM listings WHERE needs_enrich = 1 AND active = 1')['n'] ?? 0);
                    }
                    break;

                case 'enrich':
                    if (!run_enrich_one($run)) {
                        run_begin_translate($run);
                    }
                    break;

                case 'translate':
                    try {
                        if (!run_translate_batch($run)) {
                            $run['phase'] = 'finish';
                        }
                    } catch (TranslationQuotaException $e) {
                        // The month's allowance is used up: the rest waits for the next refresh.
                        $st['notes'][] = $e->getMessage() . ' The remaining listings are translated on a later refresh.';
                        $run['phase'] = 'finish';
                    }
                    break;

                case 'finish':
                    fx_apply_all();
                    foreach ($st['searches'] as $s) {
                        if ($s['complete']) {
                            q('UPDATE searches SET last_run_at = ?, last_count = ? WHERE id = ?', [now_sql(), $s['seen'], $s['id']]);
                        }
                    }
                    $run['status'] = 'done';
                    $run['finished_at'] = now_sql();
                    $s = $run['stats'];
                    $run['message'] = sprintf('%d new, %d changed, %d removed; %d located, %d without a usable address; %d translated.',
                        $s['new'], $s['changed'], $s['removed'], $s['located'], $s['not_located'], $s['translated'] ?? 0)
                        . ($st['notes'] ? ' ' . implode(' ', array_unique($st['notes'])) : '');
                    break;
            }
            $st['fails'] = 0;
        } catch (Throwable $e) {
            $st['fails'] = ($st['fails'] ?? 0) + 1;
            $run['message'] = $e->getMessage();
            if ($st['fails'] >= MAX_FAILURES_IN_A_ROW) {
                if ($run['phase'] === 'enrich') {
                    // The geocoder or OLX's location service is down: the rest is located next time.
                    $st['notes'][] = 'Locating stopped early (' . $e->getMessage() . '); the remaining listings are located on the next refresh.';
                    run_begin_translate($run);
                    $st['fails'] = 0;
                    continue;
                }
                if ($run['phase'] === 'translate') {
                    $st['notes'][] = 'Translating stopped early (' . $e->getMessage() . '); the remaining listings are translated on the next refresh.';
                    $run['phase'] = 'finish';
                    $st['fails'] = 0;
                    continue;
                }
                $run['status'] = 'failed';
                $run['finished_at'] = now_sql();
                $run['message'] = 'Stopped after repeated errors: ' . $e->getMessage();
            }
            break;   // give the source a moment: the browser asks again
        }
    }
    unset($st);
    run_save($run);
    return $run;
}

/** After locating: translate what is not yet in English, if a service is set up. */
function run_begin_translate(array &$run): void
{
    ensure_listing_columns();
    $n = (int) (one('SELECT COUNT(*) AS n FROM listings WHERE active = 1 AND translated_at IS NULL')['n'] ?? 0);
    $run['stats']['translate_total'] = $n;
    if ($n > 0 && !translator_ready()) {
        $run['state']['notes'][] = $n . ' listings are not in English yet — export them under Accounts and settings → Translation.';
        $n = 0;
    }
    $run['phase'] = $n > 0 ? 'translate' : 'finish';
}

/** Translates up to TRANSLATE_BATCH listings in one request. False when none is left. */
function run_translate_batch(array &$run): bool
{
    $skip = array_map('intval', $run['state']['tskip'] ?? []);
    $rows = all('SELECT id, title, raw FROM listings WHERE active = 1 AND translated_at IS NULL'
        . ($skip ? ' AND id NOT IN (' . implode(',', $skip) . ')' : '') . ' ORDER BY id LIMIT ' . TRANSLATE_BATCH);
    if (!$rows) {
        return false;
    }
    try {
        $n = translate_listings($rows);
    } catch (TranslationQuotaException $e) {
        throw $e;
    } catch (Throwable $e) {
        // Leave the first of the batch for the next refresh, so one text the
        // service will not take cannot stop all the others; then try again.
        $run['state']['tskip'][] = (int) $rows[0]['id'];
        throw $e;
    }
    $run['stats']['translated'] = ($run['stats']['translated'] ?? 0) + $n;
    return true;
}

/** Reads one page of the current band. False when every search is read. */
function run_list_page(array &$run): bool
{
    $st = &$run['state'];
    while (isset($st['searches'][$st['si']])) {
        $s = &$st['searches'][$st['si']];
        $band = $s['bands'][$s['band']] ?? null;
        if ($band === null) {
            // The whole search was read: whatever was not seen is gone from the site.
            if (!$s['complete']) {
                $s['complete'] = true;
                // Only when the read looks whole: a band over OLX's limit, or
                // far fewer listings than OLX counted, would mark good ones removed.
                $expected = 0;
                $overCap = false;
                foreach ($s['bands'] as $b) {
                    $expected += min((int) $b['n'], OLX_CAP);
                    $overCap = $overCap || (int) $b['n'] > OLX_CAP;
                }
                if (!$overCap && $s['seen'] >= 0.9 * $expected) {
                    $gone = q('UPDATE listings SET active = 0 WHERE search_id = ? AND active = 1 AND last_seen_at < ?',
                        [$s['id'], $run['started_at']])->rowCount();
                    $run['stats']['removed'] += $gone;
                } else {
                    $st['notes'][] = 'Fewer listings were read for "' . $s['label'] . '" than OLX counted, so none was marked removed.';
                }
            }
            $st['si']++;
            unset($s);
            continue;
        }
        $limit = min(OLX_PAGE, OLX_CAP - $s['offset']);
        $page = $limit > 0 ? olx_search_page($s['params'], $s['offset'], $limit, $band['from'], $band['to']) : ['offers' => [], 'total' => 0];
        foreach ($page['offers'] as $o) {
            $p = olx_parse_offer($o);
            $result = listing_upsert($p, (int) $s['id'], $o);
            $run['stats'][$result]++;
            $s['seen']++;
        }
        $s['offset'] += $limit;
        if (count($page['offers']) < $limit || $s['offset'] >= $band['n'] || $s['offset'] >= OLX_CAP) {
            if ($band['n'] > OLX_CAP) {
                $st['notes'][] = 'A price band of "' . $s['label'] . '" holds more than ' . OLX_CAP . ' listings; some may be missing.';
            }
            $s['band']++;
            $s['offset'] = 0;
        }
        unset($s);
        return true;
    }
    return false;
}

/** Locates one listing. False when none is left. */
function run_enrich_one(array &$run): bool
{
    $skip = array_map('intval', $run['state']['skip'] ?? []);
    $row = one('SELECT * FROM listings WHERE needs_enrich = 1 AND active = 1' . ($skip ? ' AND id NOT IN (' . implode(',', $skip) . ')' : '') . ' ORDER BY id LIMIT 1');
    if (!$row) {
        return false;
    }
    try {
        $quality = listing_enrich($row);
    } catch (Throwable $e) {
        // Leave it for the next refresh; carry on with the others.
        $run['state']['skip'][] = (int) $row['id'];
        throw $e;
    }
    $run['stats'][$quality === 'none' ? 'not_located' : 'located']++;
    return true;
}

/** What the browser shows about a run. */
function run_public(?array $run): ?array
{
    if (!$run) {
        return null;
    }
    $st = $run['state'];
    $s = $run['stats'];
    $listTotal = 0;
    $listDone = 0;
    foreach ($st['searches'] ?? [] as $x) {
        foreach ((array) ($x['bands'] ?? []) as $b) {
            $listTotal += min((int) $b['n'], OLX_CAP);
        }
        $listDone += (int) $x['seen'];
    }
    $enrichTotal = (int) ($s['enrich_total'] ?? 0);
    $translateTotal = (int) ($s['translate_total'] ?? 0);
    $enrichDone = (int) ($s['located'] ?? 0) + (int) ($s['not_located'] ?? 0);
    $labels = ['fx' => 'Exchange rates', 'plan' => 'Planning the search', 'list' => 'Reading listings', 'enrich' => 'Locating new listings', 'translate' => 'Translating into English', 'finish' => 'Finishing'];
    return [
        'id' => $run['id'], 'status' => $run['status'], 'phase' => $run['phase'], 'phase_label' => $labels[$run['phase']] ?? $run['phase'],
        'list' => ['done' => $listDone, 'total' => $listTotal],
        'enrich' => ['done' => $enrichDone, 'total' => $enrichTotal],
        'translate' => ['done' => (int) ($s['translated'] ?? 0), 'total' => $translateTotal],
        'stats' => $s, 'message' => $run['message'],
        'started_at' => $run['started_at'], 'updated_at' => $run['updated_at'], 'finished_at' => $run['finished_at'],
    ];
}
