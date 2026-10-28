<?php
declare(strict_types=1);

/**
 * Table prefixing — as in the Pricer and the Language Teacher.
 *
 * With its own database the application uses plain table names and this does
 * nothing. When it has to share a database with another application, a
 * prefix such as `pb_` keeps its tables, indexes and keys apart. The same
 * function rewrites the schema at installation and every query at runtime,
 * so the two can never drift apart.
 */

/** Every table the application owns. No name may be a whole-word prefix of another. */
const PB_TABLES = ['users', 'listings', 'geocode_cache', 'cities', 'searches', 'refresh_runs', 'fx_rates', 'audit_log', 'settings', 'login_attempts', 'api_tokens', 'marks'];

const PB_IDENTIFIER_PREFIXES = ['fk_', 'ix_', 'uq_'];

function pb_valid_prefix(string $prefix): bool
{
    return $prefix === '' || (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{0,15}$/', $prefix);
}

function pb_apply_prefix(string $sql, string $prefix): string
{
    if ($prefix === '') {
        return $sql;
    }
    $patterns = [];
    $replacements = [];
    foreach (PB_TABLES as $table) {
        $patterns[] = '/\b' . $table . '\b/';
        $replacements[] = $prefix . $table;
    }
    foreach (PB_IDENTIFIER_PREFIXES as $identifier) {
        $patterns[] = '/\b' . $identifier . '(?=[A-Za-z0-9_])/';
        $replacements[] = $identifier . $prefix;
    }
    return (string) preg_replace($patterns, $replacements, $sql);
}
