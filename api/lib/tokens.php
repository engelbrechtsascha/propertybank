<?php
declare(strict_types=1);

/**
 * Sign-in tokens for a phone version (as MarketData's /m, not built yet) — and CORS for a native shell.
 *
 * The browser version signs in with a session cookie and sends a CSRF token
 * with every change. That is the right mechanism for a page, and the wrong one
 * for a phone: a cookie session ends when the browser decides it has, which on
 * a phone is often the next morning, and a native shell built with Capacitor
 * runs its pages from its own origin (capacitor://localhost on iOS,
 * https://localhost on Android) where a finatum.de cookie is a third-party
 * cookie that iOS simply drops.
 *
 * So the phone app signs in once with an email address and a password and gets
 * a long random token, which it keeps in the device's storage and sends with
 * every call:
 *
 *   X-PB-Token: <token>          (preferred — nothing to configure)
 *   Authorization: Bearer <token>    (also accepted)
 *
 * Only the SHA-256 of a token is stored, so a copy of the database hands
 * nobody a working sign-in. A token lasts TOKEN_DAYS from its last use, is
 * revoked by signing out in the app, and every token of an account is revoked
 * when its password changes or the account is closed.
 *
 * A request carrying a token is authenticated by the token alone — the session
 * cookie is not consulted — so the CSRF check, which exists to protect cookie
 * sessions, does not apply to it. A page on another site cannot attach that
 * header without a CORS preflight, and only the origins in `cors_origins` pass
 * one.
 */

/** How long a token survives without being used. */
const TOKEN_DAYS = 180;

/** Origins a native shell runs its own pages from. Override as 'cors_origins'. */
const DEFAULT_CORS_ORIGINS = ['capacitor://localhost', 'ionic://localhost', 'https://localhost', 'http://localhost'];

/**
 * Creates the token table the first time one is needed.
 *
 * Deliberately not part of the schema files or upgrade.php: the phone app is
 * an addition, and an installation that never opens it never grows the table.
 * It also means /m works the moment the files are uploaded, with no database
 * step to forget.
 */
function ensure_token_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if ((string) cfg('driver', 'mysql') === 'sqlite') {
        db()->exec(sql("CREATE TABLE IF NOT EXISTS api_tokens (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            token_hash   TEXT NOT NULL UNIQUE,
            device       TEXT NOT NULL DEFAULT '',
            created_at   TEXT NOT NULL,
            last_used_at TEXT NOT NULL
        )"));
        db()->exec(sql('CREATE INDEX IF NOT EXISTS ix_tokens_user ON api_tokens (user_id)'));
        return;
    }

    db()->exec(sql("CREATE TABLE IF NOT EXISTS api_tokens (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      INT UNSIGNED NOT NULL,
        token_hash   CHAR(64)     NOT NULL,
        device       VARCHAR(120) NOT NULL DEFAULT '',
        created_at   DATETIME     NOT NULL,
        last_used_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tokens_hash (token_hash),
        KEY ix_tokens_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
}

/** The token sent with this request, or null when it is a cookie session. */
function request_token(): ?string
{
    $token = (string) ($_SERVER['HTTP_X_PB_TOKEN'] ?? '');
    if ($token === '') {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            $token = $m[1];
        }
    }
    $token = trim($token);
    return $token === '' ? null : $token;
}

function uses_token(): bool
{
    return request_token() !== null;
}

function token_issue(int $userId, string $device): string
{
    ensure_token_table();
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    q('INSERT INTO api_tokens (user_id, token_hash, device, created_at, last_used_at) VALUES (?, ?, ?, ?, ?)',
        [$userId, hash('sha256', $token), mb_substr($device, 0, 120), now_sql(), now_sql()]);

    // Occasional clean-up, so the table cannot grow without bound and no cron
    // job is needed on a shared host.
    if (random_int(1, 20) === 1) {
        q('DELETE FROM api_tokens WHERE last_used_at < ?',
            [(new DateTimeImmutable('-' . TOKEN_DAYS . ' days'))->format('Y-m-d H:i:s')]);
    }
    return $token;
}

/**
 * The account a token belongs to, or null when it is unknown, worn out, or
 * the account is no longer active.
 *
 * @return array{id:int,email:string,name:string,role:string}|null
 */
function token_user(string $token): ?array
{
    if (strlen($token) < 20 || strlen($token) > 200) {
        return null;
    }
    ensure_token_table();
    $row = one('SELECT t.id AS token_id, t.last_used_at, u.id, u.email, u.name, u.role, u.active
                  FROM api_tokens t JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ?', [hash('sha256', $token)]);
    if (!$row || (int) $row['active'] !== 1) {
        return null;
    }

    $lastUsed = new DateTimeImmutable((string) $row['last_used_at']);
    if ($lastUsed < new DateTimeImmutable('-' . TOKEN_DAYS . ' days')) {
        q('DELETE FROM api_tokens WHERE id = ?', [$row['token_id']]);
        return null;
    }
    // Sliding expiry, written at most once an hour: a phone that is used keeps
    // working, one that is lost stops after half a year.
    if ($lastUsed < new DateTimeImmutable('-1 hour')) {
        q('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [now_sql(), $row['token_id']]);
    }

    return [
        'id'    => (int) $row['id'],
        'email' => (string) $row['email'],
        'name'  => (string) $row['name'],
        'role'  => normalise_role((string) $row['role']),
    ];
}

function token_revoke(string $token): void
{
    ensure_token_table();
    q('DELETE FROM api_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
}

/** Signs every device of an account out, optionally except the one asking. */
function token_revoke_all(int $userId, ?string $except = null): void
{
    ensure_token_table();
    if ($except !== null) {
        q('DELETE FROM api_tokens WHERE user_id = ? AND token_hash <> ?', [$userId, hash('sha256', $except)]);
        return;
    }
    q('DELETE FROM api_tokens WHERE user_id = ?', [$userId]);
}

/** The devices signed in on an account, for the "More" screen. */
function token_list(int $userId): array
{
    ensure_token_table();
    $rows = all('SELECT id, device, created_at, last_used_at FROM api_tokens
                  WHERE user_id = ? ORDER BY last_used_at DESC', [$userId]);
    return array_map(static fn (array $r): array => [
        'id'           => (int) $r['id'],
        'device'       => (string) $r['device'],
        'created_at'   => (string) $r['created_at'],
        'last_used_at' => (string) $r['last_used_at'],
    ], $rows);
}

/* ------------------------------------------------------------- CORS ---- */

/** @return list<string> */
function cors_origins(): array
{
    $configured = cfg('cors_origins', null);
    return is_array($configured) ? array_values(array_map('strval', $configured)) : DEFAULT_CORS_ORIGINS;
}

/**
 * Answers a native shell's cross-origin requests.
 *
 * Same-origin requests — the browser version and /m — send no Origin header or
 * this site's own, and are untouched. Credentials are never allowed
 * cross-origin: a native app sends its token, never a cookie.
 */
function cors_handle(): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($origin !== '' && in_array($origin, cors_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-PB-Token, Authorization, X-HTTP-Method-Override');
            header('Access-Control-Max-Age: 86400');
        }
    }
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
