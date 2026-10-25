<?php
declare(strict_types=1);

/**
 * Runtime settings, stored in the `settings` table so that an administrator
 * can change them in the application instead of editing files.
 */

const SETTING_DEFAULTS = [
    // How people may get an account. PropertyBank starts as a one-person
    // application, so nobody registers: the installer creates the
    // administrator, who can create further accounts or open registration.
    //   off | domain | open
    'registration_mode'     => 'off',
    'registration_domains'  => 'finatum.de',
    'registration_approval' => '1',
    'max_users'             => '5',
    // Nominatim (OpenStreetMap) turns an address into coordinates. Its usage
    // policy: at most one request a second, results cached, and a way to
    // contact the operator. The contact address is sent with each request in
    // the From header — leave it empty and the application's URL is sent.
    'geocoder_url'          => 'https://nominatim.openstreetmap.org/',
    'geocoder_contact'      => '',
    // Milliseconds between two requests to OLX, to be a polite visitor.
    'olx_delay_ms'          => '250',
    // The address parser the stored locations were found with (olx.php,
    // PB_PARSER_VERSION). Kept by the application, not shown anywhere.
    'parser_version'        => '1',
    // Translation of titles and descriptions into English (translate.php):
    // '' (off), 'deepl' or 'google', and that service's API key. The key is
    // never sent to a browser.
    'translator'            => '',
    'translator_key'        => '',
];

/** @return array<string,string> */
function settings(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $values = SETTING_DEFAULTS;
    foreach (all('SELECT name, value FROM settings') as $row) {
        if (array_key_exists($row['name'], $values)) {
            $values[$row['name']] = (string) $row['value'];
        }
    }
    return $cache = $values;
}

function setting(string $name): string
{
    $s = settings();
    return $s[$name] ?? (SETTING_DEFAULTS[$name] ?? '');
}

function set_setting(string $name, string $value): void
{
    if (!array_key_exists($name, SETTING_DEFAULTS)) {
        fail('validation', 'Unknown setting: ' . $name, 422);
    }
    if (one('SELECT name FROM settings WHERE name = ?', [$name])) {
        q('UPDATE settings SET value = ? WHERE name = ?', [$value, $name]);
    } else {
        q('INSERT INTO settings (name, value) VALUES (?, ?)', [$name, $value]);
    }
    settings(true);
}

/** @return list<string> */
function allowed_domains(): array
{
    $raw = setting('registration_domains');
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $d) {
        $d = mb_strtolower(trim($d, " \t.@"));
        if ($d !== '') {
            $out[] = $d;
        }
    }
    return $out;
}

/** What the sign-in screen is allowed to know before anybody has signed in. */
function public_settings(): array
{
    $mode = setting('registration_mode');
    $full = accounts_full();
    return [
        'registration_enabled' => $mode !== 'off' && !$full,
        'registration_mode'    => $mode,
        'registration_domains' => $mode === 'domain' ? allowed_domains() : [],
        'needs_approval'       => setting('registration_approval') === '1',
        'accounts_full'        => $full,
    ];
}
