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
        'contacts'           => ['first_name', 'last_name', 'email', 'phone', 'company', 'company_id', 'address', 'tags', 'custom', 'source', 'owner_id', 'dnd_email', 'created_at', 'updated_at'],
        'companies'          => ['name', 'domain', 'phone', 'website', 'address', 'industry', 'notes', 'custom', 'owner_id', 'created_at', 'updated_at'],
        'notes'              => ['contact_id', 'user_id', 'body', 'created_at'],
        'tasks'              => ['contact_id', 'title', 'due_date', 'status', 'assigned_to', 'created_by', 'created_at'],
        'activities'         => ['contact_id', 'type', 'meta', 'created_at'],
        'pipelines'          => ['name', 'stages', 'stage_probs', 'created_at'],
        'deals'              => ['pipeline_id', 'contact_id', 'company_id', 'name', 'stage', 'value_cents', 'status', 'close_date', 'probability', 'owner_id', 'closed_at', 'lost_reason', 'notes', 'created_at', 'updated_at'],
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
/**
 * Delete one row. The delete goes in the audit log with what the row was called, read just before it
 * goes - so every delete in the app is covered from this one place. Pass $audit = false when the caller
 * writes a fuller line of its own (removing a team member, merging two companies).
 */
function go_del(string $table, int $id, bool $audit = true): void {
    $row = $audit ? go_get($table, $id) : null;
    db()->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
    if ($row) audit('delete', 'Deleted ' . audit_record($table, $row), $table, $id);
}
/** A row as a log line names it: the contact "Dana Lee" (dana@example.com), the deal "Website rebuild", task #12. */
function audit_record(string $table, array $row): string {
    static $noun = ['contacts' => 'the contact', 'companies' => 'the company', 'deals' => 'the deal', 'tasks' => 'the task', 'campaigns' => 'the campaign',
        'forms' => 'the form', 'workflows' => 'the workflow', 'templates' => 'the template', 'pipelines' => 'the pipeline', 'email_accounts' => 'the email account', 'users' => 'the team member'];
    $what = $noun[$table] ?? 'a row of ' . $table;
    if ($table === 'contacts') {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); $email = (string) ($row['email'] ?? '');
        return $what . ' ' . ($name !== '' ? '"' . $name . '"' . ($email !== '' ? ' (' . $email . ')' : '') : ($email !== '' ? $email : '#' . (int) ($row['id'] ?? 0)));
    }
    foreach (['name', 'title', 'subject', 'from_email', 'email'] as $k) if (trim((string) ($row[$k] ?? '')) !== '') return $what . ' "' . trim((string) $row[$k]) . '"';
    return $what . ' #' . (int) ($row['id'] ?? 0);
}
/**
 * Run $fn as one all-or-nothing change and return its result. The write lock is taken up front, so a
 * request that arrives at the same moment waits its turn instead of failing. Calls may nest: an inner
 * call simply joins the transaction that is already open. Read what you need before calling this, or
 * inside it - never while a query from outside is still being looped over.
 */
function db_tx(callable $fn) {
    static $depth = 0;
    if ($depth > 0) return $fn();
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $depth++;
    try {
        $out = $fn();
        $pdo->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignored) { /* nothing left to undo */ }
        throw $e;
    } finally {
        $depth--;
    }
}
