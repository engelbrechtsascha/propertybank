<?php
declare(strict_types=1);

/** JSON request/response helpers. */

function json_out(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    $json = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    // A long history is a few hundred kilobytes of digits; compressed it is a
    // fifth of that. Shared hosts do not always compress PHP output, so do it
    // here when the browser accepts it and nothing else already does.
    if (strlen($json) > 16384 && function_exists('gzencode') && !headers_sent()
        && !filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)
        && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
        $gz = gzencode($json, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            echo $gz;
            exit;
        }
    }
    echo $json;
    exit;
}

function fail(string $error, string $message, int $status = 400): never
{
    json_out(['error' => $error, 'message' => $message], $status);
}

/** @return array<string,mixed> */
function request_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    // A JSON content type is required, not merely expected. An HTML form on
    // another site can POST to this API — the session cookie is SameSite=Lax,
    // but a top-level form submission is exactly the case Lax still allows —
    // and a form can only send urlencoded, multipart or text/plain. Refusing
    // anything else means no cross-site form can reach a route at all, which
    // covers the two that are deliberately outside the CSRF token: sign in
    // and register.
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($type !== '' && !str_starts_with($type, 'application/json')) {
        fail('bad_request', 'This API accepts application/json only.', 415);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $cached = [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail('bad_request', 'The request body must be a JSON object.', 400);
    }
    return $cached = $decoded;
}

function body_string(string $key, int $maxLen, bool $required = false, ?string $label = null): string
{
    $body = request_body();
    $value = trim((string) ($body[$key] ?? ''));
    $label ??= $key;
    if ($value === '' && $required) {
        fail('validation', ucfirst($label) . ' is required.', 422);
    }
    if (mb_strlen($value) > $maxLen) {
        fail('validation', ucfirst($label) . ' may not be longer than ' . $maxLen . ' characters.', 422);
    }
    return $value;
}

/** @param list<string> $allowed */
function body_enum(string $key, array $allowed, string $default): string
{
    $body = request_body();
    $value = (string) ($body[$key] ?? $default);
    if (!in_array($value, $allowed, true)) {
        fail('validation', 'Unknown value for ' . $key . '.', 422);
    }
    return $value;
}

function body_int(string $key, ?int $default = null): ?int
{
    $body = request_body();
    if (!isset($body[$key]) || $body[$key] === '' || $body[$key] === null) {
        return $default;
    }
    if (!is_numeric($body[$key])) {
        fail('validation', $key . ' must be a number.', 422);
    }
    return (int) $body[$key];
}

function now_sql(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function today_iso(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}
