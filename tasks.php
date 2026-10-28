<?php
/** CRMax Core - Tasks: a single list of everything to do, across all contacts. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_guard();
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title !== '') go_insert('tasks', ['contact_id' => (int) ($_POST['contact_id'] ?? 0), 'title' => $title, 'due_date' => (string) ($_POST['due_date'] ?? ''), 'status' => 'open', 'assigned_to' => (int) $u['id'], 'created_at' => time()]);
        redirect('tasks.php');
    }
    if ($do === 'done') { go_update('tasks', (int) ($_POST['id'] ?? 0), ['status' => 'done']); redirect('tasks.php' . (isset($_POST['f']) ? '?f=' . urlencode((string) $_POST['f']) : '')); }
    if ($do === 'reopen') { go_update('tasks', (int) ($_POST['id'] ?? 0), ['status' => 'open']); redirect('tasks.php?f=done'); }
    if ($do === 'del') { go_del('tasks', (int) ($_POST['id'] ?? 0)); redirect('tasks.php'); }
}

$f = (string) ($_GET['f'] ?? 'open');
$today = date('Y-m-d');
if ($f === 'done') $where = "status='done'";
elseif ($f === 'overdue') $where = "status='open' AND due_date!='' AND due_date<'" . $today . "'";
elseif ($f === 'today') $where = "status='open' AND due_date='" . $today . "'";
else { $f = 'open'; $where = "status='open'"; }
$rows = db()->query("SELECT t.*, c.first_name, c.last_name, c.email FROM tasks t LEFT JOIN contacts c ON c.id=t.contact_id WHERE $where ORDER BY (t.due_date=''), t.due_date, t.id DESC LIMIT 500")->fetchAll();
$counts = [];
foreach (['open' => "status='open'", 'today' => "status='open' AND due_date='$today'", 'overdue' => "status='open' AND due_date!='' AND due_date<'$today'", 'done' => "status='done'"] as $k => $w) $counts[$k] = (int) db()->query("SELECT COUNT(*) FROM tasks WHERE $w")->fetchColumn();

head('Tasks', 'tasks', $u);
?>
<div class="pagehead"><h1>Tasks</h1></div>
<div class="card">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="do" value="add">
    <input name="title" placeholder="Add a task..." required style="flex:1;min-width:200px"><input type="date" name="due_date" style="width:auto"><button class="btn sm">Add task</button></form>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    <?php foreach (['open' => 'Open', 'today' => 'Due today', 'overdue' => 'Overdue', 'done' => 'Done'] as $k => $lbl): ?>
      <a class="btn <?= $f === $k ? '' : 'ghost' ?> sm" href="<?= h(url('tasks.php?f=' . $k)) ?>"><?= $lbl ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><div class="empty">Nothing here.</div>
  <?php else: ?><table class="tbl"><tbody>
    <?php foreach ($rows as $t): $overdue = $t['status'] === 'open' && $t['due_date'] !== '' && $t['due_date'] < $today; ?>
    <tr>
      <td style="width:30px"><?php if ($t['status'] === 'open'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="done"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="f" value="<?= h($f) ?>"><button class="btn ghost sm" style="padding:3px 8px" title="Done">&#10003;</button></form><?php else: ?><span style="color:var(--ok)">&#10003;</span><?php endif; ?></td>
      <td<?= $t['status'] === 'done' ? ' style="text-decoration:line-through;color:var(--muted)"' : '' ?>><?= h($t['title']) ?></td>
      <td><?php if ($t['contact_id'] && ($t['first_name'] || $t['email'])): ?><a href="<?= h(url('contact.php?id=' . (int) $t['contact_id'])) ?>" class="muted small"><?= h(contact_name($t)) ?></a><?php endif; ?></td>
      <td style="text-align:right"><?php if ($t['due_date']): ?><span class="tag <?= $overdue ? 'bad' : '' ?>"><?= h($t['due_date']) ?></span><?php endif; ?></td>
      <td style="width:30px"><form method="post" onsubmit="return confirm('Delete task?')"><?= csrf_field() ?><input type="hidden" name="do" value="del"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn danger sm" style="padding:3px 8px">&times;</button></form></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
<?php foot();
