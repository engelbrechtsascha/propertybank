<?php
declare(strict_types=1);

/**
 * Outbound HTTP — the server's requests to OLX, the geocoder and the
 * exchange-rate sources. cURL where the host has it, file_get_contents
 * otherwise (as MarketData).
 *
 * For local testing, 'sources_mock' in api/config.php (or the environment
 * variable PB_SOURCES_MOCK) sends every request to tools/mock-sources.php
 * instead: https://www.olx.ua/api/v1/… becomes <mock>/www.olx.ua/api/v1/….
 */

const PB_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function sources_mock(): string
{
    $m = (string) (getenv('PB_SOURCES_MOCK') ?: cfg('sources_mock', ''));
    return $m === '' ? '' : rtrim($m, '/') . '/';
}

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string,error:string}
 */
function http_get(string $url, array $headers = [], int $timeout = 25): array
{
    $mock = sources_mock();
    if ($mock !== '') {
        $url = $mock . preg_replace('#^https?://#', '', $url);
    }
    $lines = [];
    $hasUa = false;
    foreach ($headers as $k => $v) {
        $lines[] = $k . ': ' . $v;
        if (strtolower($k) === 'user-agent') {
            $hasUa = true;
        }
    }
    if (!$hasUa) {
        $lines[] = 'User-Agent: ' . PB_USER_AGENT;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_ENCODING       => '',      // accept gzip/deflate and decode
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        return ['status' => $status, 'body' => $body === false ? '' : (string) $body, 'error' => $error];
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'header' => implode("\r\n", $lines), 'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    return ['status' => $status, 'body' => $body === false ? '' : $body, 'error' => $body === false ? 'no response' : ''];
}

/** GET and decode JSON. @return array{status:int,json:mixed,error:string} */
function http_json(string $url, array $headers = [], int $timeout = 25): array
{
    $r = http_get($url, $headers + ['Accept' => 'application/json'], $timeout);
    $json = json_decode($r['body'], true);
    $error = $r['error'];
    if ($error === '' && ($r['status'] < 200 || $r['status'] >= 300)) {
        $error = 'HTTP ' . $r['status'];
    }
    if ($error === '' && $json === null) {
        $error = 'the answer was not JSON' . (stripos($r['body'], '<html') !== false ? ' (an HTML page — perhaps a block or a captcha)' : '');
    }
    return ['status' => $r['status'], 'json' => $json, 'error' => $error];
}

/** Compressed text for large columns, as MarketData's cache: "z:" + base64(zlib). */
function pack_text(string $s): string
{
    $z = function_exists('gzcompress') ? gzcompress($s, 6) : false;
    return $z === false ? $s : 'z:' . base64_encode($z);
}

function unpack_text(?string $s): string
{
    if ($s === null) {
        return '';
    }
    if (str_starts_with($s, 'z:')) {
        $raw = base64_decode(substr($s, 2), true);
        $out = $raw === false ? false : @gzuncompress($raw);
        return $out === false ? '' : $out;
    }
    return $s;
}

/**
 * POST a JSON body and decode the JSON answer.
 * @param array<string,string> $headers
 * @return array{status:int,json:mixed,error:string}
 */
function http_post_json(string $url, mixed $body, array $headers = [], int $timeout = 30): array
{
    $mock = sources_mock();
    if ($mock !== '') {
        $url = $mock . preg_replace('#^https?://#', '', $url);
    }
    $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $lines = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: FINATUM-PropertyBank/' . PB_VERSION];
    foreach ($headers as $k => $v) {
        $lines[] = $k . ': ' . $v;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_HTTPHEADER => $lines, CURLOPT_ENCODING => '',
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines), 'content' => $payload,
            'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        $error = $raw === false ? 'no response' : '';
    }
    $json = json_decode((string) $raw, true);
    if ($error === '' && ($status < 200 || $status >= 300)) {
        $error = 'HTTP ' . $status;
    }
    return ['status' => $status, 'json' => $json, 'error' => $error];
}
