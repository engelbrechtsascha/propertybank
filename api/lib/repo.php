<?php
declare(strict_types=1);

/**
 * Accounts bookkeeping and the audit trail.
 *
 * The listings are shared by every account of the installation (for now
 * there is one). What belongs to an account is its preferences — its last
 * filters — kept in users.prefs and read only for the signed-in person.
 */

function audit(?array $user, string $entity, ?int $entityId, string $action, string $summary): void
{
    q(
        'INSERT INTO audit_log (user_id, user_name, entity, entity_id, action, summary, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$user['id'] ?? null, $user['name'] ?? null, $entity, $entityId, $action, mb_substr($summary, 0, 400), now_sql()]
    );
}

/** Everything that belongs to one account, removed when the account goes. */
function delete_all_data_of(int $userId): void
{
    q('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
    token_revoke_all($userId);
}

function user_count(): int
{
    $row = one('SELECT COUNT(*) AS n FROM users');
    return (int) ($row['n'] ?? 0);
}

function user_limit(): int
{
    $limit = (int) setting('max_users');
    return $limit > 0 ? $limit : 5;
}

function accounts_full(): bool
{
    return user_count() >= user_limit();
}

/** The person's saved preferences (the last filters, …). */
function user_prefs(int $userId): array
{
    $row = one('SELECT prefs FROM users WHERE id = ?', [$userId]);
    $decoded = json_decode((string) ($row['prefs'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}
