<?php
/**
 * CRMax Core - tiny data layer: generic CRUD over a per-table column allowlist.
 * Single-organisation app, so there is no per-tenant scoping; every row is the org's.
 */
declare(strict_types=1);

/** Writable columns per table (anything else in a $data array is ignored = mass-assignment safe). */
function go_cols(string $table): array {
    static $m = [
        'users'              => ['email', 'pass_hash', 'name', 'role', 'status', 'created_at', 'last_login'],
        'email_accounts'     => ['name', 'from_email', 'from_name', 'smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'smtp_pass', 'is_default', 'created_at'],
        'contacts'           => ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'tags', 'custom', 'source', 'owner_id', 'dnd_email', 'created_at', 'updated_at'],
        'notes'              => ['contact_id', 'user_id', 'body', 'created_at'],
        'tasks'              => ['contact_id', 'title', 'due_date', 'status', 'assigned_to', 'created_at'],
        'activities'         => ['contact_id', 'type', 'meta', 'created_at'],
        'pipelines'          => ['name', 'stages', 'created_at'],
        'deals'              => ['pipeline_id', 'contact_id', 'name', 'stage', 'value_cents', 'status', 'created_at', 'updated_at'],
        'forms'              => ['name', 'slug', 'fields', 'thankyou', 'notify', 'redirect', 'status', 'submits', 'created_at'],
        'form_submissions'   => ['form_id', 'contact_id', 'data', 'created_at'],
        'campaigns'          => ['name', 'subject', 'body', 'from_account', 'segment', 'status', 'scheduled_at', 'sent_count', 'open_count', 'fail_count', 'created_at'],
        'campaign_recipients' => ['campaign_id', 'contact_id', 'status', 'opened', 'sent_at'],
        'workflows'          => ['name', 'trigger_on', 'config', 'steps', 'status', 'enrolled_count', 'created_at'],
        'wf_enrollments'     => ['workflow_id', 'contact_id', 'step', 'next_at', 'status', 'ctx', 'created_at'],
        'templates'          => ['name', 'subject', 'body', 'created_at'],
    ];
    return $m[$table] ?? [];
}
function go_get(string $table, int $id): ?array {
    $st = db()->prepare("SELECT * FROM $table WHERE id=?"); $st->execute([$id]); return $st->fetch() ?: null;
}
function go_all(string $table, string $where = '', array $params = [], string $order = 'id DESC', int $limit = 0): array {
    $sql = "SELECT * FROM $table" . ($where !== '' ? " WHERE $where" : '') . ($order !== '' ? " ORDER BY $order" : '') . ($limit > 0 ? " LIMIT $limit" : '');
    $st = db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function go_insert(string $table, array $data): int {
    $cols = go_cols($table); $use = [];
    foreach ($data as $k => $v) if (in_array($k, $cols, true)) $use[$k] = $v;
    if (!$use) return 0;
    $keys = array_keys($use);
    $sql = "INSERT INTO $table (" . implode(',', $keys) . ") VALUES (" . implode(',', array_fill(0, count($keys), '?')) . ")";
    db()->prepare($sql)->execute(array_values($use));
    return (int) db()->lastInsertId();
}
function go_update(string $table, int $id, array $data): void {
    $cols = go_cols($table); $set = []; $vals = [];
    foreach ($data as $k => $v) if (in_array($k, $cols, true)) { $set[] = "$k=?"; $vals[] = $v; }
    if (!$set) return;
    $vals[] = $id;
    db()->prepare("UPDATE $table SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
}
function go_del(string $table, int $id): void { db()->prepare("DELETE FROM $table WHERE id=?")->execute([$id]); }
