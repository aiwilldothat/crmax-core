<?php
/** CRMax Core - Tasks: a single list of everything to do, across all contacts, and who each task is assigned to. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') task_add($title, (string) ($_POST['due_date'] ?? ''), (int) ($_POST['contact_id'] ?? 0), posted_user('assigned_to', (int) $u['id']), $u);
        redirect('tasks.php');
    }
    if ($do === 'assign') {   // the assignee badge on a task
        $t = go_get('tasks', (int) ($_POST['id'] ?? 0));
        if ($t) {
            $to = posted_user('assigned_to', (int) $t['assigned_to']);
            if (task_assign((int) $t['id'], $to, $u)) flash_set($to ? 'Task assigned to ' . user_label(user_by_id($to)) . '.' : 'Task is now unassigned.');
        }
        redirect('tasks.php' . (isset($_POST['f']) ? '?f=' . urlencode((string) $_POST['f']) : ''));
    }
    if ($do === 'done') { go_update('tasks', (int) ($_POST['id'] ?? 0), ['status' => 'done']); redirect('tasks.php' . (isset($_POST['f']) ? '?f=' . urlencode((string) $_POST['f']) : '')); }
    if ($do === 'reopen') { go_update('tasks', (int) ($_POST['id'] ?? 0), ['status' => 'open']); redirect('tasks.php?f=done'); }
    if ($do === 'del') { go_del('tasks', (int) ($_POST['id'] ?? 0)); redirect('tasks.php'); }
}

$who = who_filter('tasks');
[$whoSql, $whoParams] = who_sql('t.assigned_to', $who, (int) $u['id']);
$and = $whoSql !== '' ? " AND $whoSql" : '';
$today = date('Y-m-d');
$tabs = [   // status tab => [its SQL test, the values for it]
    'open'    => ["t.status='open'", []],
    'today'   => ["t.status='open' AND t.due_date=?", [$today]],
    'overdue' => ["t.status='open' AND t.due_date!='' AND t.due_date<?", [$today]],
    'done'    => ["t.status='done'", []],
];
$f = is_string($_GET['f'] ?? null) && isset($tabs[$_GET['f']]) ? $_GET['f'] : 'open';
$st = db()->prepare("SELECT t.*, c.first_name, c.last_name, c.email FROM tasks t LEFT JOIN contacts c ON c.id=t.contact_id WHERE {$tabs[$f][0]}$and ORDER BY (t.due_date=''), t.due_date, t.id DESC LIMIT 500");
$st->execute(array_merge($tabs[$f][1], $whoParams));
$rows = $st->fetchAll();
$counts = [];
foreach ($tabs as $k => [$test, $vals]) {
    $st = db()->prepare("SELECT COUNT(*) FROM tasks t WHERE $test$and");
    $st->execute(array_merge($vals, $whoParams));
    $counts[$k] = (int) ($st->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0);
}
$team = team_map();

head('Tasks', 'tasks', $u);
?>
<div class="pagehead"><h1>Tasks</h1><div class="sp"><?= who_select($who, $u, who_onchange('tasks.php?f=' . $f)) ?></div></div>
<div class="card">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="do" value="add">
    <input name="title" placeholder="Add a task..." required style="flex:1;min-width:200px"><input type="date" name="due_date" style="width:auto">
    <select name="assigned_to" aria-label="Assign the task to" title="Assign the task to" style="width:auto;max-width:170px"><?= user_options(who_default($who, (int) $u['id'])) ?></select>
    <button class="btn sm">Add task</button></form>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    <?php foreach (['open' => 'Open', 'today' => 'Due today', 'overdue' => 'Overdue', 'done' => 'Done'] as $k => $lbl): ?>
      <a class="btn <?= $f === $k ? '' : 'ghost' ?> sm" href="<?= h(url('tasks.php?f=' . $k)) ?>"><?= $lbl ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><div class="empty">Nothing here.<?php if ($who !== 'all'): ?> <a href="<?= h(url('tasks.php?f=' . $f . '&who=all')) ?>">Show everyone's tasks</a>.<?php endif; ?></div>
  <?php else: ?><table class="tbl"><tbody>
    <?php foreach ($rows as $t):
        $open = $t['status'] === 'open'; $overdue = $open && $t['due_date'] !== '' && $t['due_date'] < $today;
        $by = (int) $t['created_by']; $from = $by && $by !== (int) $t['assigned_to'] && isset($team[$by]) ? $team[$by] : ''; ?>
    <tr>
      <td style="width:30px"><?php if ($open): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="done"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>"><button class="btn ghost sm" style="padding:3px 8px" title="Done">&#10003;</button></form><?php else: ?><span style="color:var(--ok)">&#10003;</span><?php endif; ?></td>
      <td><span<?= $open ? '' : ' style="text-decoration:line-through;color:var(--muted)"' ?>><?= h($t['title']) ?></span><?php if ($from !== ''): ?> <span class="muted small">&middot; from <?= h($from) ?></span><?php endif; ?></td>
      <td><?php if ($t['contact_id'] && ($t['first_name'] || $t['email'])): ?><a href="<?= h(url('contact.php?id=' . (int) $t['contact_id'])) ?>" class="muted small"><?= h(contact_name($t)) ?></a><?php endif; ?></td>
      <td style="width:40px;text-align:center"><?= $open ? who_button((int) $t['assigned_to'], $team, ['do' => 'assign', 'id' => (int) $t['id'], 'f' => $f], 'assigned_to') : who_chip((int) $t['assigned_to'], $team, 'Assigned to') ?></td>
      <td style="text-align:right;white-space:nowrap"><?php if ($t['due_date']): ?><span class="tag <?= $overdue ? 'bad' : '' ?>"><?= h($t['due_date']) ?></span><?php endif; ?></td>
      <td style="width:30px"><form method="post" onsubmit="return confirm('Delete task?')"><?= csrf_field() ?><input type="hidden" name="do" value="del"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn danger sm" style="padding:3px 8px">&times;</button></form></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?= pick_user_js() ?>
  <?php endif; ?>
</div>
<?php foot();
