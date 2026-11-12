<?php
/**
 * CRMax Core - Audit log (admins only): who signed in, what changed in security, the team and the
 * settings, and who exported, deleted, bulk-changed or re-assigned records. Read-only: the app has no
 * way to edit or delete a line. Lines are kept for 12 months. Filter by kind, person, date or words,
 * and download what the filter shows as CSV.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_admin();
audit_prune();

$actions = audit_actions();
$groups = array_values(array_unique(array_column($actions, 1)));

/* ---------- the filter, from the address ---------- */
$fKind = is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '';       // 'g:<group>' or an action code
$fUser = is_string($_GET['user'] ?? null) ? $_GET['user'] : '';       // a user id, or 'other' (the API, the command line, sign-ins that failed)
$fFrom = valid_date(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$fTo   = valid_date(is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
$fQ    = trim(is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '');
$where = []; $params = [];
if (str_starts_with($fKind, 'g:') && in_array(substr($fKind, 2), $groups, true)) {
    $codes = array_keys(array_filter($actions, fn($a) => $a[1] === substr($fKind, 2)));
    $where[] = 'action IN (' . implode(',', array_fill(0, count($codes), '?')) . ')'; $params = array_merge($params, $codes);
} elseif (isset($actions[$fKind])) { $where[] = 'action=?'; $params[] = $fKind; }
else $fKind = '';
if ($fUser === 'other') $where[] = 'user_id=0';
elseif (ctype_digit($fUser) && strlen($fUser) < 10 && (int) $fUser > 0) {   // what they did, and sign-ins as them that failed
    $where[] = "(user_id=? OR (entity='users' AND entity_id=? AND action='login_failed'))"; $params[] = (int) $fUser; $params[] = (int) $fUser;
} else $fUser = '';
if ($fFrom !== '') { $where[] = 'created_at>=?'; $params[] = (int) strtotime($fFrom . ' 00:00:00'); }
if ($fTo !== '')   { $where[] = 'created_at<=?'; $params[] = (int) strtotime($fTo . ' 23:59:59'); }
if ($fQ !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $fQ) . '%';
    $where[] = "(summary LIKE ? ESCAPE '\\' OR actor LIKE ? ESCAPE '\\' OR ip LIKE ? ESCAPE '\\')"; array_push($params, $like, $like, $like);
}
$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$keep = array_filter(['kind' => $fKind, 'user' => $fUser, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ], fn($v) => $v !== '');
$label = fn(string $code): string => $actions[$code][0] ?? $code;

/* ---------- CSV of what the filter shows ---------- */
if (isset($_GET['export'])) {
    $st = db()->prepare("SELECT * FROM audit_log$sqlWhere ORDER BY id DESC LIMIT 100000");
    $st->execute($params);
    $rows = $st->fetchAll(); $st = null;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-log-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['time', 'who', 'what', 'details', 'record', 'address'], ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, [date('Y-m-d H:i:s', (int) $r['created_at']), csv_safe($r['actor']), csv_safe($label($r['action'])), csv_safe($r['summary']),
            $r['entity'] !== '' ? csv_safe($r['entity'] . ((int) $r['entity_id'] ? ' ' . (int) $r['entity_id'] : '')) : '', csv_safe($r['ip'])], ',', '"', '');
    }
    fclose($out);
    audit('export', 'Exported ' . count($rows) . ' audit log lines' . ($keep ? ' (filtered)' : ''), 'audit_log');
    exit;
}

/* ---------- one page of lines, newest first ---------- */
$per = 50;
$count = db()->prepare("SELECT COUNT(*) FROM audit_log$sqlWhere"); $count->execute($params);
$total = (int) $count->fetchColumn(); $count = null;
$pages = max(1, (int) ceil($total / $per));
$page = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
$st = db()->prepare("SELECT * FROM audit_log$sqlWhere ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$rows = $st->fetchAll(); $st = null;
$link = fn(array $extra): string => url('audit.php' . (($q = http_build_query($extra + $keep)) !== '' ? '?' . $q : ''));
// A line about a contact or a company links to it, while it still exists.
$open = function (array $r): string {
    $id = (int) $r['entity_id'];
    if ($id && $r['entity'] === 'contacts' && go_get('contacts', $id)) return url('contact.php?id=' . $id);
    if ($id && $r['entity'] === 'companies' && go_get('companies', $id)) return url('company.php?id=' . $id);
    return '';
};
$tone = ['login_failed' => 'bad', 'delete' => 'warn', 'bulk_delete' => 'warn', 'user_removed' => 'warn', '2fa_disabled' => 'warn', '2fa_reset' => 'warn', 'password_reset' => 'warn', 'login' => 'ok', '2fa_enabled' => 'ok'];

head('Audit log', 'settings', $u);
?>
<div class="pagehead"><a href="<?= h(url('settings.php#security')) ?>" class="muted">&larr; Settings</a><h1>Audit log</h1>
  <div class="sp"><a class="btn ghost sm" href="<?= h($link(['export' => 1])) ?>">Download CSV</a></div></div>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin-bottom:14px">
    <div><label for="f_kind">What</label><select id="f_kind" name="kind"><option value="">Everything</option>
      <?php foreach ($groups as $g): ?><optgroup label="<?= h($g) ?>"><option value="g:<?= h($g) ?>" <?= $fKind === 'g:' . $g ? 'selected' : '' ?>>All: <?= h(strtolower($g)) ?></option>
        <?php foreach ($actions as $code => [$name, $grp]): if ($grp !== $g) continue; ?><option value="<?= h($code) ?>" <?= $fKind === $code ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
    <div><label for="f_user">Who</label><select id="f_user" name="user"><option value="">Anyone</option>
      <?php foreach (users_all() as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $fUser === (string) $m['id'] ? 'selected' : '' ?>><?= h(user_label($m)) ?></option><?php endforeach; ?>
      <option value="other" <?= $fUser === 'other' ? 'selected' : '' ?>>Nobody signed in (API, command line, failed sign-ins)</option></select></div>
    <div class="grow0" style="flex:0 0 150px"><label for="f_from">From</label><input id="f_from" type="date" name="from" value="<?= h($fFrom) ?>"></div>
    <div class="grow0" style="flex:0 0 150px"><label for="f_to">To</label><input id="f_to" type="date" name="to" value="<?= h($fTo) ?>"></div>
    <div><label for="f_q">Words</label><input id="f_q" name="q" value="<?= h($fQ) ?>" placeholder="a name, an address..."></div>
    <div class="grow0"><button class="btn sm">Show</button><?php if ($keep): ?> <a class="btn ghost sm" href="<?= h(url('audit.php')) ?>">Clear</a><?php endif; ?></div>
  </form>

  <?php if (!$rows): ?><div class="empty"><?= $keep ? 'Nothing in the log matches that.' : 'Nothing has been logged yet.' ?></div>
  <?php else: /* On a phone the first three columns fold into a line above the details, so nothing has to be scrolled sideways. */ ?>
  <div class="scrollx"><table class="tbl"><thead><tr><th class="hide-sm">When</th><th class="hide-sm">Who</th><th class="hide-sm">What</th><th>Details</th><th class="hide-sm">From</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $href = $open($r); $when = date('j M Y H:i', (int) $r['created_at']);
        $tag = '<span class="tag ' . h($tone[$r['action']] ?? '') . '" style="white-space:nowrap">' . h($label($r['action'])) . '</span>'; ?><tr class="arow">
      <td class="hide-sm" style="white-space:nowrap" title="<?= h(ago((int) $r['created_at'])) ?>"><?= h($when) ?></td>
      <td class="hide-sm" style="min-width:112px"><?= $r['actor'] !== '' ? h($r['actor']) : '<span class="muted">-</span>' ?></td>
      <td class="hide-sm"><?= $tag ?></td>
      <td><div class="show-sm small muted" style="margin-bottom:4px"><?= $tag ?> <?= $r['actor'] !== '' ? h($r['actor']) . ' &middot; ' : '' ?><?= h($when) ?></div><?= h($r['summary']) ?><?php if ($href !== ''): ?> <a class="small" href="<?= h($href) ?>">open</a><?php endif; ?></td>
      <td class="muted small hide-sm" style="white-space:nowrap"><?= h($r['ip']) ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:14px" class="small muted">
    <span><?= number_format($total) ?> line<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
    <?php if ($page > 1): ?><a class="btn ghost sm" href="<?= h($link($page > 2 ? ['p' => $page - 1] : [])) ?>">&larr; Newer</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn ghost sm" href="<?= h($link(['p' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <p class="small muted" style="margin-top:14px">Times are in <?= h(date_default_timezone_get()) ?>. Lines are kept for 12 months and cannot be edited or deleted in the app.</p>
</div>
<?php foot();
