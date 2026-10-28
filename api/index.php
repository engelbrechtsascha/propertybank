<?php
declare(strict_types=1);

/**
 * FINATUM PropertyBank — API front controller.
 *
 * Same shape as the Pricer and MarketData: every call is addressed with a
 * query path, so no URL rewriting is needed on shared hosting:
 *
 *   GET  api/index.php?p=auth/me
 *   GET  api/index.php?p=listings               every listing, the cities, the exchange rate
 *   GET  api/index.php?p=status                 last refresh, a refresh in progress
 *   POST api/index.php?p=refresh/start          begins a refresh (or returns the running one)
 *   POST api/index.php?p=refresh/step           ~20 s of work; the browser repeats until done
 *   POST api/index.php?p=refresh/cancel
 *   GET  api/index.php?p=cities                 PUT cities/<id>: landmarks and stations
 *   GET  api/index.php?p=searches               POST, PUT searches/<id>, DELETE searches/<id>
 *   GET  api/index.php?p=geocode&q=…            looks a place up (for adding a landmark)
 *   GET  api/index.php?p=diag                   can the server reach OLX, the geocoder, ECB, NBU?
 *
 * Hosts that block PUT/PATCH/DELETE also accept POST with ?_method=PUT.
 * Filtering and sorting happen in the browser, on the whole list — a few
 * thousand rows, a few hundred kilobytes compressed.
 */

require __DIR__ . '/lib/bootstrap.php';

const MAX_PREFS_JSON = 8000;

cors_handle();

header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

$path     = trim((string) ($_GET['p'] ?? ''), '/');
$segments = $path === '' ? [] : explode('/', $path);
$resource = $segments[0] ?? '';
$id       = isset($segments[1]) && ctype_digit($segments[1]) ? (int) $segments[1] : null;
$action2  = $segments[1] ?? '';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$override = strtoupper((string) ($_GET['_method'] ?? $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
if ($method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
    $method = $override;
}

$openForm = $resource === 'auth' && in_array($segments[1] ?? '', ['login', 'register', 'token'], true);
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !$openForm) {
    csrf_verify();
}

/** The annunciator: when the data were last refreshed, and a refresh in progress. */
function status_payload(): array
{
    $last = run_last_done();
    $n = one('SELECT COUNT(*) AS n, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS a,
                     SUM(CASE WHEN active = 1 AND needs_enrich = 1 THEN 1 ELSE 0 END) AS p FROM listings');
    return [
        'last' => run_public($last),
        'running' => run_public(run_current()),
        'counts' => ['all' => (int) ($n['n'] ?? 0), 'active' => (int) ($n['a'] ?? 0), 'pending' => (int) ($n['p'] ?? 0)],
        'fx' => fx_latest(),
        'now' => now_sql(),
    ];
}

switch ($resource) {

    // ------------------------------------------------------------- auth ---
    case 'auth':
        $action = $segments[1] ?? '';

        if ($action === 'me' && $method === 'GET') {
            $u = auth_user();
            json_out([
                'user'      => $u,
                'csrf'      => $u && !uses_token() ? csrf_token() : null,
                'prefs'     => $u ? (object) user_prefs((int) $u['id']) : null,
                'app_name'  => cfg('app_name', 'FINATUM PropertyBank'),
                'version'   => PB_VERSION,
                'today'     => today_iso(),
                'timezone'  => date_default_timezone_get(),
                'signup'    => public_settings(),
                'min_password_length' => MIN_PASSWORD_LENGTH,
            ]);
        }

        if ($action === 'login' && $method === 'POST') {
            $email    = body_string('email', 190, true, 'email address');
            $password = (string) (request_body()['password'] ?? '');
            usleep(random_int(120000, 260000));

            $result = auth_login($email, $password);
            if (!$result['user']) {
                if ($result['reason'] === 'locked') {
                    fail('locked', 'Too many failed attempts. Please wait a quarter of an hour and try again.', 429);
                }
                if ($result['reason'] === 'inactive') {
                    fail('inactive', 'This account is waiting for an administrator to approve it.', 403);
                }
                fail('invalid_credentials', 'Those sign-in details did not match an account.', 401);
            }
            $user = $result['user'];
            audit($user, 'user', $user['id'], 'login', $user['name'] . ' signed in');
            json_out(['user' => $user, 'csrf' => csrf_token(), 'prefs' => (object) user_prefs((int) $user['id'])]);
        }

        if ($action === 'register' && $method === 'POST') {
            $email = body_string('email', 190, true, 'email address');
            if (locked_out($email)) {
                fail('locked', 'Too many attempts from here. Please wait a quarter of an hour.', 429);
            }
            usleep(random_int(120000, 260000));
            $password = (string) (request_body()['password'] ?? '');

            // The mark goes down first and is withdrawn if registration
            // succeeds: auth_register() exits on every failure.
            record_attempt($email, false);
            $created = auth_register(body_string('name', 120, true, 'name'), $email, $password);
            clear_last_attempt($email);
            record_attempt($email, true);

            if ($created['pending']) {
                json_out([
                    'pending' => true,
                    'message' => 'Your account has been created. An administrator has to approve it before you can sign in.',
                ], 201);
            }
            $result = auth_login($email, $password);
            json_out(['pending' => false, 'user' => $result['user'], 'csrf' => csrf_token(), 'prefs' => (object) []], 201);
        }

        if ($action === 'logout' && $method === 'POST') {
            if (($t = request_token()) !== null) {
                token_revoke($t);
            } else {
                auth_logout();
            }
            json_out(['ok' => true]);
        }

        if ($action === 'token' && $method === 'POST') {
            $email = body_string('email', 190, true, 'email address');
            $password = (string) (request_body()['password'] ?? '');
            usleep(random_int(120000, 260000));
            $checked = auth_verify($email, $password);
            if (!$checked['row']) {
                if ($checked['reason'] === 'locked') {
                    fail('locked', 'Too many failed attempts. Please wait a quarter of an hour and try again.', 429);
                }
                if ($checked['reason'] === 'inactive') {
                    fail('inactive', 'This account is waiting for an administrator to approve it.', 403);
                }
                fail('invalid_credentials', 'Those sign-in details did not match an account.', 401);
            }
            $row = $checked['row'];
            $user = ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'name' => (string) $row['name'],
                     'role' => normalise_role((string) $row['role'])];
            $token = token_issue($user['id'], body_string('device', 120));
            audit($user, 'user', $user['id'], 'login', $user['name'] . ' signed in on a phone');
            json_out(['token' => $token, 'user' => $user, 'prefs' => (object) user_prefs($user['id'])], 201);
        }

        if ($action === 'token' && $method === 'DELETE') {
            if (($t = request_token()) !== null) {
                token_revoke($t);
            }
            json_out(['ok' => true]);
        }

        if ($action === 'password' && $method === 'POST') {
            $u = require_login();
            $current = (string) (request_body()['current_password'] ?? '');
            $next    = (string) (request_body()['new_password'] ?? '');
            $row = one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
            if (!$row || !password_verify($current, (string) $row['password_hash'])) {
                fail('invalid_credentials', 'Your current password is not correct.', 403);
            }
            if ($problem = password_problem($next, (string) $u['email'], (string) $u['name'])) {
                fail('validation', $problem, 422);
            }
            q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($next), $u['id']]);
            token_revoke_all((int) $u['id'], request_token());
            audit($u, 'user', $u['id'], 'password', $u['name'] . ' changed their password');
            json_out(['ok' => true]);
        }

        // A person closing their own account, with its preferences.
        if ($action === 'account' && $method === 'DELETE') {
            $u = require_login();
            $password = (string) (request_body()['password'] ?? '');
            $row = one('SELECT password_hash FROM users WHERE id = ?', [$u['id']]);
            if (!$row || !password_verify($password, (string) $row['password_hash'])) {
                fail('invalid_credentials', 'Your password is not correct.', 403);
            }
            if ($u['role'] === 'admin') {
                $others = one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$u['id']]);
                if ((int) ($others['n'] ?? 0) < 1) {
                    fail('validation', 'You are the last administrator. Make someone else an administrator first.', 422);
                }
            }
            delete_all_data_of((int) $u['id']);
            q('DELETE FROM users WHERE id = ?', [$u['id']]);
            if (($t = request_token()) === null) {
                auth_logout();
            }
            json_out(['ok' => true]);
        }
        break;

    // ------------------------------------------------------------ prefs ---
    case 'prefs':
        if ($method === 'PUT') {
            $u = require_login();
            $prefs = request_body()['prefs'] ?? null;
            if (!is_array($prefs)) {
                fail('validation', 'Preferences must be an object.', 422);
            }
            $json = json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || strlen($json) > MAX_PREFS_JSON) {
                fail('validation', 'Too many preferences.', 422);
            }
            q('UPDATE users SET prefs = ? WHERE id = ?', [$json, $u['id']]);
            json_out(['prefs' => (object) $prefs]);
        }
        break;

    // --------------------------------------------------------- listings ---
    case 'listings':
        // The title and description, original and in English, as stored.
        if ($method === 'GET' && $id !== null && ($segments[2] ?? '') === 'description') {
            require_login();
            json_out(listing_description($id));
        }
        if ($method === 'GET') {
            $me = require_login();
            @ini_set('memory_limit', '256M');
            json_out([
                'listings' => listings_for_browser(($_GET['all'] ?? '') === '1'),
                'marked' => marks_of((int) $me['id']),
                'cities' => cities_all(),
                'status' => status_payload(),
            ]);
        }
        break;

    case 'status':
        if ($method === 'GET') {
            require_login();
            json_out(status_payload());
        }
        break;

    // ---------------------------------------------------------- refresh ---
    case 'refresh':
        $u = require_login();
        if ($method === 'POST' && $action2 === 'start') {
            cities_seed();
            $run = run_start($u);
            json_out(['run' => run_public($run)]);
        }
        if ($method === 'POST' && $action2 === 'step') {
            $runId = (int) (request_body()['run'] ?? 0);
            $run = $runId > 0 ? run_load($runId) : run_current();
            if (!$run) {
                fail('not_found', 'That refresh no longer exists.', 404);
            }
            if ($run['status'] === 'running') {
                $run = run_step($run);
            }
            json_out(['run' => run_public($run), 'status' => $run['status'] === 'running' ? null : status_payload()]);
        }
        if ($method === 'POST' && $action2 === 'cancel') {
            $run = run_current();
            if ($run) {
                $run = run_cancel($run);
                audit($u, 'refresh', $run['id'], 'cancel', 'Refresh stopped');
            }
            json_out(['run' => run_public($run), 'status' => status_payload()]);
        }
        break;

    // ----------------------------------------------------------- cities ---
    case 'cities':
        if ($method === 'GET') {
            require_login();
            json_out(['cities' => cities_all()]);
        }
        if ($method === 'PUT' && $id !== null) {
            $admin = require_role('admin');
            $city = city_by_id($id);
            if (!$city) {
                fail('not_found', 'That city no longer exists.', 404);
            }
            $body = request_body();
            $landmarks = city_points($body['landmarks'] ?? $city['landmarks'], 'landmark');
            $stations = city_points($body['stations'] ?? $city['stations'], 'station');
            $name = body_string('name_en', 120) ?: $city['name_en'];
            q('UPDATE cities SET name_en = ?, landmarks = ?, stations = ?, updated_at = ? WHERE id = ?', [
                $name, json_encode($landmarks, JSON_UNESCAPED_UNICODE), json_encode($stations, JSON_UNESCAPED_UNICODE), now_sql(), $id,
            ]);
            q('UPDATE listings SET city_en = ? WHERE city_id = ?', [$name, $id]);
            $n = geo_recompute($id);
            audit($admin, 'city', $id, 'update', $name . ': ' . count($landmarks) . ' landmarks, ' . count($stations) . ' stations; ' . $n . ' listings recomputed');
            json_out(['city' => city_by_id($id), 'recomputed' => $n]);
        }
        if ($method === 'POST') {
            $admin = require_role('admin');
            $name = body_string('name_en', 120, true, 'name');
            $olx = body_int('olx_city_id');
            q('INSERT INTO cities (olx_city_id, name_en, name_orig, landmarks, stations, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$olx, $name, body_string('name_orig', 120), '[]', '[]', now_sql()]);
            $newId = last_id();
            // Listings of this city are tied to it on the next refresh (by its OLX city id).
            audit($admin, 'city', $newId, 'create', 'City ' . $name . ' added');
            json_out(['city' => city_by_id($newId)], 201);
        }
        break;

    // ---------------------------------------------------------- geocode ---
    case 'geocode':
        if ($method === 'GET') {
            require_role('admin');
            $qText = trim((string) ($_GET['q'] ?? ''));
            if ($qText === '' || mb_strlen($qText) > 200) {
                fail('validation', 'Say what to look for.', 422);
            }
            try {
                $g = geocode(['q' => $qText]);
            } catch (Throwable $e) {
                fail('upstream', $e->getMessage(), 502);
            }
            json_out(['result' => $g]);
        }
        break;

    // --------------------------------------------------------- searches ---
    case 'searches':
        if ($method === 'GET') {
            require_login();
            cities_seed();
            $rows = all('SELECT * FROM searches ORDER BY id');
            foreach ($rows as &$r) {
                $r['id'] = (int) $r['id'];
                $r['active'] = (int) $r['active'] === 1;
                $r['params'] = json_decode((string) $r['params'], true);
                $r['listings'] = (int) (one('SELECT COUNT(*) AS n FROM listings WHERE search_id = ? AND active = 1', [$r['id']])['n'] ?? 0);
            }
            unset($r);
            $runs = all('SELECT id FROM refresh_runs ORDER BY id DESC LIMIT 12');
            json_out(['searches' => $rows, 'runs' => array_map(static fn ($x) => run_public(run_load((int) $x['id'])), $runs)]);
        }
        if ($method === 'POST' || ($method === 'PUT' && $id !== null)) {
            $admin = require_role('admin');
            $body = request_body();
            $params = [];
            foreach (['category_id', 'region_id', 'city_id', 'district_id'] as $k) {
                $v = $body['params'][$k] ?? null;
                if ($v !== null && $v !== '') {
                    if (!ctype_digit((string) $v)) {
                        fail('validation', $k . ' must be a whole number.', 422);
                    }
                    $params[$k] = (int) $v;
                }
            }
            if (empty($params['category_id']) || empty($params['city_id'])) {
                fail('validation', 'A search needs at least the category and the city.', 422);
            }
            $label = body_string('label', 160, true, 'label');
            $active = body_int('active', 1) === 1 ? 1 : 0;
            if ($method === 'POST') {
                q('INSERT INTO searches (source, label, params, active) VALUES (?, ?, ?, ?)', ['olx', $label, json_encode($params), $active]);
                audit($admin, 'search', last_id(), 'create', 'Search ' . $label . ' added');
            } else {
                q('UPDATE searches SET label = ?, params = ?, active = ? WHERE id = ?', [$label, json_encode($params), $active, $id]);
                audit($admin, 'search', $id, 'update', 'Search ' . $label . ' changed');
            }
            json_out(['ok' => true]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $admin = require_role('admin');
            q('DELETE FROM searches WHERE id = ?', [$id]);
            q('UPDATE listings SET search_id = NULL WHERE search_id = ?', [$id]);
            audit($admin, 'search', $id, 'delete', 'Search removed (its listings are kept)');
            json_out(['ok' => true]);
        }
        break;

    // ------------------------------------------------------------ marks ---
    // Flats of interest: POST {ads: ["olx:935797158", …], on: true|false}.
    case 'marks':
        if ($method === 'POST') {
            $u = require_login();
            $ads = request_body()['ads'] ?? null;
            if (!is_array($ads) || !$ads || count($ads) > 50) {
                fail('validation', 'Say which ads to mark.', 422);
            }
            marks_set((int) $u['id'], $ads, !empty(request_body()['on']));
            json_out(['marked' => marks_of((int) $u['id'])]);
        }
        break;

    // ----------------------------------------------------- translations ---
    // Translating outside the app: export what is not in English, import it back.
    case 'translations':
        if ($method === 'GET' && $action2 === 'export') {
            require_role('admin');
            @ini_set('memory_limit', '256M');
            @set_time_limit(120);
            json_out(translations_export(($_GET['all'] ?? '') === '1'));
        }
        if ($method === 'POST' && $action2 === 'import') {
            $admin = require_role('admin');
            $body = request_body();
            $items = $body['listings'] ?? null;
            if (!is_array($items) || count($items) > 1000) {
                fail('validation', 'Send between 1 and 1000 translated listings at a time.', 422);
            }
            $by = trim((string) ($body['translated_by'] ?? '')) ?: 'imported file';
            $res = translations_import(array_values($items), $by);
            audit($admin, 'translate', null, 'import', $res['stored'] . ' translations imported (' . $by . ')');
            json_out($res);
        }
        break;

    // ------------------------------------------------------------- diag ---
    // Can this server reach the sites? Answers what a shared host's firewall
    // or a site's bot protection does to the server's own requests.
    case 'diag':
        if ($method === 'GET') {
            require_role('admin');
            $checks = [];
            $t = microtime(true);
            try {
                $page = olx_search_page(['category_id' => 1758, 'city_id' => KYIV_OLX_CITY_ID], 0, 1);
                $checks[] = ['name' => 'OLX search API', 'ok' => true, 'detail' => number_format($page['total']) . ' flats for sale in Kyiv'];
                $oid = (string) ($page['offers'][0]['id'] ?? '');
                if ($oid !== '') {
                    try {
                        $ex = olx_exact_location($oid);
                        $checks[] = ['name' => 'OLX exact location', 'ok' => true, 'detail' => $ex ? 'answered with an address' : 'answered (no address on that listing)'];
                    } catch (Throwable $e) {
                        $checks[] = ['name' => 'OLX exact location', 'ok' => false, 'detail' => $e->getMessage()];
                    }
                }
            } catch (Throwable $e) {
                $checks[] = ['name' => 'OLX search API', 'ok' => false, 'detail' => $e->getMessage()];
            }
            try {
                $g = geocode(['q' => 'Золоті ворота, Київ']);
                $checks[] = ['name' => 'Geocoder (Nominatim)', 'ok' => (bool) $g, 'detail' => $g ? sprintf('%.5f, %.5f', $g['lat'], $g['lon']) : 'no result'];
            } catch (Throwable $e) {
                $checks[] = ['name' => 'Geocoder (Nominatim)', 'ok' => false, 'detail' => $e->getMessage()];
            }
            $r = http_get(ECB_DAILY, [], 15);
            $checks[] = ['name' => 'ECB reference rates', 'ok' => $r['status'] === 200, 'detail' => $r['error'] ?: 'HTTP ' . $r['status']];
            $n = http_json(NBU_RATES, [], 15);
            $checks[] = ['name' => 'National Bank of Ukraine rates', 'ok' => $n['error'] === '', 'detail' => $n['error'] ?: 'ok'];
            if (translator_ready()) {
                try {
                    $tr = translate_to_english(['Продаж квартири біля Золотих воріт']);
                    $checks[] = ['name' => 'Translation (' . translator_name() . ')', 'ok' => true, 'detail' => '“' . $tr['texts'][0] . '”'];
                } catch (Throwable $e) {
                    $checks[] = ['name' => 'Translation (' . translator_name() . ')', 'ok' => false, 'detail' => $e->getMessage()];
                }
            } else {
                $checks[] = ['name' => 'Translation', 'ok' => false, 'detail' => 'not set up (Accounts and settings → Translation)'];
            }
            json_out(['checks' => $checks, 'seconds' => round(microtime(true) - $t, 1)]);
        }
        break;

    // ----------------------------------------------------------- tokens ---
    case 'tokens':
        if ($method === 'GET') {
            $u = require_login();
            $current = request_token();
            $currentHash = $current !== null ? hash('sha256', $current) : '';
            $list = token_list((int) $u['id']);
            if ($currentHash !== '') {
                $mine = one('SELECT id FROM api_tokens WHERE token_hash = ?', [$currentHash]);
                foreach ($list as &$t) {
                    $t['current'] = $mine && (int) $mine['id'] === $t['id'];
                }
                unset($t);
            }
            json_out(['tokens' => $list]);
        }
        if ($method === 'DELETE' && $id !== null) {
            $u = require_login();
            q('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$id, $u['id']]);
            json_out(['ok' => true]);
        }
        if ($method === 'DELETE' && $action2 === '') {
            $u = require_login();
            token_revoke_all((int) $u['id'], request_token());
            json_out(['ok' => true]);
        }
        break;

    // ------------------------------------------------------------ users ---
    // Account administration only. These routes never read anyone's
    // preferences; deleting an account deletes them with it.
    case 'users':
        if ($method === 'GET') {
            require_role('admin');
            $rows = all('SELECT u.id, u.email, u.name, u.role, u.active, u.source, u.created_at, u.last_login_at
                           FROM users u ORDER BY u.active, u.name');
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                $row['role'] = normalise_role((string) $row['role']);
            }
            unset($row);
            json_out([
                'users'    => $rows,
                'settings' => settings_for_admin(),
                'capacity' => ['used' => user_count(), 'limit' => user_limit()],
            ]);
        }

        if ($method === 'POST') {
            $admin = require_role('admin');
            if (accounts_full()) {
                fail('accounts_full', 'The account limit is reached. Raise it, or delete an account first.', 422);
            }
            $email = mb_strtolower(body_string('email', 190, true, 'email address'));
            $name  = body_string('name', 120, true, 'name');
            $role  = body_enum('role', ROLES, 'user');
            $password = (string) (request_body()['password'] ?? '');
            if (!valid_email($email)) {
                fail('validation', 'That is not a valid email address.', 422);
            }
            if ($problem = password_problem($password, $email, $name)) {
                fail('validation', $problem, 422);
            }
            if (one('SELECT id FROM users WHERE email = ?', [$email])) {
                fail('validation', 'An account with that email address already exists.', 422);
            }
            q('INSERT INTO users (email, name, password_hash, role, active, source, created_at)
               VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$email, $name, hash_password($password), $role, 'admin', now_sql()]);
            $newId = last_id();
            audit($admin, 'user', $newId, 'create', 'Account created for ' . $name . ' (' . $role . ')');
            json_out(['id' => $newId], 201);
        }

        if ($id !== null && $method === 'PUT') {
            $admin = require_role('admin');
            $target = one('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$target) {
                fail('not_found', 'That account no longer exists.', 404);
            }
            $name = body_string('name', 120, true, 'name');
            $role = body_enum('role', ROLES, normalise_role((string) $target['role']));
            $active = body_int('active', (int) $target['active']) === 1 ? 1 : 0;
            $password = (string) (request_body()['password'] ?? '');

            if ((int) $target['id'] === (int) $admin['id'] && ($role !== 'admin' || $active !== 1)) {
                fail('validation', 'You cannot remove your own administrator access.', 422);
            }
            $wasInactive = (int) $target['active'] !== 1;

            if ($password !== '') {
                $confirm = (string) (request_body()['admin_password'] ?? '');
                $self = one('SELECT password_hash FROM users WHERE id = ?', [$admin['id']]);
                if (!$self || !password_verify($confirm, (string) $self['password_hash'])) {
                    fail('confirm_password', 'Enter your own password to set somebody else\'s.', 403);
                }
                if ($problem = password_problem($password, (string) $target['email'], $name)) {
                    fail('validation', $problem, 422);
                }
            }

            q('UPDATE users SET name = ?, role = ?, active = ? WHERE id = ?', [$name, $role, $active, $id]);
            if ($active !== 1) {
                token_revoke_all((int) $id);
            }
            if ($password !== '') {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $id]);
                token_revoke_all((int) $id);
                audit($admin, 'user', $id, 'password_reset',
                    'Administrator ' . $admin['name'] . ' set a new password for ' . $target['email']);
            }
            audit($admin, 'user', $id,
                $wasInactive && $active === 1 ? 'approve' : 'update',
                $wasInactive && $active === 1 ? 'Account ' . $target['email'] . ' approved' : 'Account ' . $target['email'] . ' updated');
            json_out(['ok' => true]);
        }

        if ($id !== null && $method === 'DELETE') {
            $admin = require_role('admin');
            if ((int) $id === (int) $admin['id']) {
                fail('validation', 'Use “Close my account” in your account menu to delete your own account.', 422);
            }
            $target = one('SELECT email, role FROM users WHERE id = ?', [$id]);
            if (!$target) {
                fail('not_found', 'That account no longer exists.', 404);
            }
            if (normalise_role((string) $target['role']) === 'admin') {
                $row = one("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1");
                if ((int) ($row['n'] ?? 0) <= 1) {
                    fail('validation', 'That is the last administrator account.', 422);
                }
            }
            delete_all_data_of($id);
            q('DELETE FROM users WHERE id = ?', [$id]);
            audit($admin, 'user', $id, 'delete', 'Account ' . $target['email'] . ' deleted');
            json_out(['ok' => true]);
        }
        break;

    // --------------------------------------------------------- settings ---
    case 'settings':
        if ($method === 'GET') {
            require_role('admin');
            json_out(['settings' => settings_for_admin(), 'capacity' => ['used' => user_count(), 'limit' => user_limit()]]);
        }

        if ($method === 'PUT') {
            $admin = require_role('admin');
            $mode = body_enum('registration_mode', ['off', 'domain', 'open'], setting('registration_mode'));
            $domains = body_string('registration_domains', 400);
            $approval = body_int('registration_approval', (int) setting('registration_approval')) === 1 ? '1' : '0';
            $max = (int) body_int('max_users', user_limit());
            $geoUrl = body_string('geocoder_url', 200) ?: SETTING_DEFAULTS['geocoder_url'];
            $contact = body_string('geocoder_contact', 190);
            $delay = (int) body_int('olx_delay_ms', (int) setting('olx_delay_ms'));

            if ($mode === 'domain' && trim($domains) === '') {
                fail('validation', 'Name at least one email domain, for example finatum.de.', 422);
            }
            if ($max < 1 || $max > 1000) {
                fail('validation', 'The account limit must be between 1 and 1000.', 422);
            }
            if ($max < user_count()) {
                fail('validation', 'There are already ' . user_count() . ' accounts. The limit cannot be lower than that.', 422);
            }
            if (!preg_match('#^https://#', $geoUrl)) {
                fail('validation', 'The geocoder address must start with https://.', 422);
            }
            if ($delay < 0 || $delay > 5000) {
                fail('validation', 'The pause between OLX requests must be between 0 and 5000 ms.', 422);
            }
            set_setting('registration_mode', $mode);
            set_setting('registration_domains', $domains);
            set_setting('registration_approval', $approval);
            set_setting('max_users', (string) $max);
            set_setting('geocoder_url', $geoUrl);
            set_setting('geocoder_contact', $contact);
            set_setting('olx_delay_ms', (string) $delay);
            // Translation: the service, and its key — a new one replaces the old, "clear" removes it, nothing keeps it.
            $body = request_body();
            if (isset($body['translator'])) {
                set_setting('translator', body_enum('translator', TRANSLATORS, translator()));
            }
            if (!empty($body['translator_key_clear'])) {
                set_setting('translator_key', '');
            } elseif (trim((string) ($body['translator_key'] ?? '')) !== '') {
                $tk = trim((string) $body['translator_key']);
                if (!preg_match('/^[A-Za-z0-9:_\-\.]{20,200}$/', $tk)) {
                    fail('validation', 'That does not look like an API key — copy it again from DeepL or Google.', 422);
                }
                set_setting('translator_key', $tk);
            }
            audit($admin, 'user', null, 'settings', 'Settings changed: registration ' . $mode . ', limit ' . $max);
            json_out(['settings' => settings_for_admin(), 'capacity' => ['used' => user_count(), 'limit' => user_limit()]]);
        }
        break;
}

fail('not_found', 'No such endpoint: ' . $method . ' ' . ($path === '' ? '(empty)' : $path), 404);
