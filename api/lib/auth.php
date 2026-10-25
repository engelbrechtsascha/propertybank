<?php
declare(strict_types=1);

/**
 * Accounts, sessions, roles and CSRF protection.
 *
 * PropertyBank starts as a one-person application: the installer creates
 * the administrator and registration is off. The account machinery is the
 * Pricer's and MarketData's, so opening it to more people later is a setting:
 *
 *   user    searches the listings and refreshes them
 *   admin   the same, plus the cities (landmarks, stations), the OLX
 *           searches, accounts and settings.
 *
 * How credentials are kept:
 *   - passwords are hashed with bcrypt at cost 12 and never stored or logged
 *     in any other form;
 *   - the hash is upgraded automatically at sign-in if the cost or the PHP
 *     default algorithm changes;
 *   - sign-in attempts are recorded so an account can be locked briefly after
 *     repeated failures;
 *   - the session holds only the user id, in a cookie scoped to this
 *     installation's own path.
 */

const ROLES = ['admin', 'user'];

function normalise_role(string $role): string
{
    return in_array($role, ROLES, true) ? $role : 'user';
}

/** bcrypt work factor. 12 is a sensible balance on shared hosting. */
const PASSWORD_OPTIONS = ['cost' => 12];

const MIN_PASSWORD_LENGTH = 12;

/** Lockout: this many failures from one email or one address within the window. */
const MAX_FAILURES = 8;
const FAILURE_WINDOW_MINUTES = 15;

function auth_user(): ?array
{
    // The phone app sends a token; that alone identifies it, and no session
    // is started for it. See tokens.php.
    $token = request_token();
    if ($token !== null) {
        static $byToken = [];
        if (!array_key_exists($token, $byToken)) {
            $byToken[$token] = token_user($token);
        }
        return $byToken[$token];
    }

    pb_session_start();
    $id = $_SESSION['uid'] ?? null;
    if (!$id) {
        return null;
    }
    static $cached = null;
    if ($cached !== null && (int) $cached['id'] === (int) $id) {
        return $cached;
    }
    $row = one('SELECT id, email, name, role, active FROM users WHERE id = ?', [$id]);
    if (!$row || (int) $row['active'] !== 1) {
        auth_logout();
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['role'] = normalise_role((string) $row['role']);
    unset($row['active']);
    return $cached = $row;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return mb_substr($ip, 0, 45);
}

function record_attempt(string $email, bool $ok): void
{
    q('INSERT INTO login_attempts (email, ip, successful, created_at) VALUES (?, ?, ?, ?)',
        [mb_substr($email, 0, 190), client_ip(), $ok ? 1 : 0, now_sql()]);

    // Keep the table small without needing a cron job.
    if (random_int(1, 20) === 1) {
        q('DELETE FROM login_attempts WHERE created_at < ?',
            [(new DateTimeImmutable('-2 days'))->format('Y-m-d H:i:s')]);
    }
}

/**
 * Takes back the most recent failure mark for this address and client.
 *
 * Used by registration, which has to record its attempt *before* doing the
 * work — `fail()` exits, so there is no path back to record it afterwards —
 * and then withdraws the mark when the work in fact succeeded. Without this,
 * a run of successful registrations from one office would lock that office
 * out.
 */
function clear_last_attempt(string $email): void
{
    $row = one('SELECT id FROM login_attempts
                 WHERE email = ? AND ip = ? AND successful = 0
                 ORDER BY id DESC',
        [mb_strtolower($email), client_ip()]);
    if ($row) {
        q('DELETE FROM login_attempts WHERE id = ?', [$row['id']]);
    }
}

/** True when this email address or address is temporarily locked out. */
function locked_out(string $email): bool
{
    $since = (new DateTimeImmutable('-' . FAILURE_WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');
    $row = one(
        'SELECT COUNT(*) AS n FROM login_attempts
          WHERE successful = 0 AND created_at > ? AND (email = ? OR ip = ?)',
        [$since, mb_strtolower($email), client_ip()]
    );
    return ((int) ($row['n'] ?? 0)) >= MAX_FAILURES;
}

/**
 * @return array{user:?array,reason:?string} reason is 'invalid', 'inactive' or
 *         'locked' when no user is returned.
 */
/**
 * Checks an address and password, with lockout, and nothing else — no
 * session. Shared by the browser's sign-in and the phone app's token.
 *
 * @return array{row:?array,reason:?string}
 */
function auth_verify(string $email, string $password): array
{
    $email = mb_strtolower(trim($email));

    if (locked_out($email)) {
        return ['row' => null, 'reason' => 'locked'];
    }

    $row = one('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$row || !password_verify($password, (string) $row['password_hash'])) {
        record_attempt($email, false);
        return ['row' => null, 'reason' => 'invalid'];
    }
    if ((int) $row['active'] !== 1) {
        record_attempt($email, false);
        return ['row' => null, 'reason' => 'inactive'];
    }

    // Transparently upgrade an old hash to the current algorithm and cost.
    if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT, PASSWORD_OPTIONS)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT, PASSWORD_OPTIONS), $row['id']]);
    }
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now_sql(), $row['id']]);
    record_attempt($email, true);
    return ['row' => $row, 'reason' => null];
}

/**
 * @return array{user:?array,reason:?string} reason is 'invalid', 'inactive' or
 *         'locked' when no user is returned.
 */
function auth_login(string $email, string $password): array
{
    $checked = auth_verify($email, $password);
    $row = $checked['row'];
    if (!$row) {
        return ['user' => null, 'reason' => $checked['reason']];
    }

    pb_session_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));

    return [
        'user' => [
            'id'    => (int) $row['id'],
            'email' => $row['email'],
            'name'  => $row['name'],
            'role'  => normalise_role((string) $row['role']),
        ],
        'reason' => null,
    ];
}

function auth_logout(): void
{
    pb_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT, PASSWORD_OPTIONS);
}

/* ----------------------------------------------------------- CSRF ------ */

function csrf_token(): string
{
    pb_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_verify(): void
{
    // CSRF protects cookie sessions. A token request carries no cookie and
    // cannot be made from another site's page without a CORS preflight.
    if (uses_token()) {
        return;
    }
    pb_session_start();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $known = (string) ($_SESSION['csrf'] ?? '');
    if ($known === '' || !is_string($sent) || !hash_equals($known, $sent)) {
        fail('csrf', 'Your session has expired. Please sign in again.', 419);
    }
}

/* ----------------------------------------------------------- gates ----- */

function require_login(): array
{
    $u = auth_user();
    if (!$u) {
        fail('unauthenticated', 'Please sign in.', 401);
    }
    return $u;
}

function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        fail('forbidden', 'Your account does not have permission for that.', 403);
    }
    return $u;
}

/* ------------------------------------------------------- validation ---- */

function password_problem(string $password, string $email = '', string $name = ''): ?string
{
    if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'The password must be at least ' . MIN_PASSWORD_LENGTH . ' characters long.';
    }
    if (mb_strlen($password) > 200) {
        return 'The password is too long.';
    }
    $lower = mb_strtolower($password);
    $weak = ['password', 'passwort', '123456789', 'qwertzuiop', 'qwertyuiop', 'willkommen', 'finatum', 'propertybank'];
    foreach ($weak as $w) {
        if (str_contains($lower, $w)) {
            return 'Please choose a password that does not contain “' . $w . '”.';
        }
    }
    $local = $email !== '' ? mb_strtolower(explode('@', $email)[0]) : '';
    if ($local !== '' && mb_strlen($local) > 3 && str_contains($lower, $local)) {
        return 'The password must not contain your email name.';
    }
    if ($name !== '' && mb_strlen($name) > 3 && str_contains($lower, mb_strtolower($name))) {
        return 'The password must not contain your name.';
    }
    return null;
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 190;
}

/* ---------------------------------------------------- registration ----- */

/**
 * Creates an account from the sign-in screen, honouring the registration
 * policy. Returns ['id' => int, 'pending' => bool].
 */
function auth_register(string $name, string $email, string $password): array
{
    $mode = setting('registration_mode');
    if ($mode === 'off') {
        fail('registration_closed', 'Accounts are created by an administrator. Please ask for one.', 403);
    }
    if (accounts_full()) {
        fail('accounts_full',
            'This application has reached the number of accounts it is set up for. Please ask the administrator for a place.',
            503);
    }

    $email = mb_strtolower(trim($email));
    $name  = trim($name);

    if ($name === '') {
        fail('validation', 'Please enter your name.', 422);
    }
    if (!valid_email($email)) {
        fail('validation', 'That is not a valid email address.', 422);
    }
    if ($mode === 'domain') {
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));
        $allowed = allowed_domains();
        if (!in_array($domain, $allowed, true)) {
            fail('validation',
                'Registration is limited to addresses at ' . implode(', ', $allowed) . '.', 422);
        }
    }
    if ($problem = password_problem($password, $email, $name)) {
        fail('validation', $problem, 422);
    }
    if (one('SELECT id FROM users WHERE email = ?', [$email])) {
        // Deliberately vague, so the form cannot be used to discover who has
        // an account.
        fail('validation', 'If that address can be registered, an administrator has been notified.', 422);
    }

    $pending = setting('registration_approval') === '1';

    q('INSERT INTO users (email, name, password_hash, role, active, source, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$email, $name, hash_password($password), 'user', $pending ? 0 : 1, 'self', now_sql()]);

    $id = last_id();
    audit(['id' => $id, 'name' => $name], 'user', $id,
        'register', $name . ' created an account' . ($pending ? ' (awaiting approval)' : ''));

    return ['id' => $id, 'pending' => $pending];
}
