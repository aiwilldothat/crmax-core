<?php
/** CRMax Core - public form page (render + capture). Safe to embed in an iframe. */
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['s'] ?? '')));
$st = db()->prepare("SELECT * FROM forms WHERE slug=? AND status='active' LIMIT 1"); $st->execute([$slug]);
$form = $st->fetch();
if (!$form) err404('This form is not available.');
$fields = jdec($form['fields']);

$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rl_hit('formsubmit', 3600, 40)) { $err = 'Too many submissions, please try again later.'; }
    else {
        [$ok, $err] = array_pad(form_handle_submit($form, $_POST), 2, '');
        if ($ok) {
            if (($form['redirect'] ?? '') !== '' && preg_match('#^https?://#', $form['redirect'])) { header('Location: ' . $form['redirect']); exit; }
            $done = true;
        }
    }
}

header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
<title><?= h($form['name']) ?></title>
<style>
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#f4f8f6;color:#16241f;line-height:1.5;padding:24px 16px}
 .wrap{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e2ebe6;border-radius:16px;padding:24px;box-shadow:0 8px 30px rgba(20,40,33,.07)}
 h1{font-size:1.3rem;margin-bottom:14px}
 label{display:block;font-size:.82rem;color:#5f7269;margin:12px 0 5px}
 input,textarea{width:100%;background:#fff;border:1px solid #cfdad4;border-radius:10px;padding:11px 13px;font:inherit;color:#16241f}
 input:focus,textarea:focus{outline:none;border-color:#0ca678;box-shadow:0 0 0 3px rgba(12,166,120,.13)}
 textarea{min-height:110px;resize:vertical}
 button{margin-top:18px;background:linear-gradient(110deg,#0ca678,#0a9fc0);color:#fff;font-weight:700;border:none;border-radius:999px;padding:12px 26px;cursor:pointer;font-size:.95rem;width:100%}
 .hp{position:absolute;left:-9999px}
 .err{background:#fdeaea;border:1px solid #f5b5bb;color:#8d2430;padding:10px 13px;border-radius:10px;font-size:.88rem;margin-bottom:12px}
 .ok{text-align:center;padding:20px 8px}.ok .big{font-size:2.4rem}
 .pwr{text-align:center;margin-top:16px;font-size:.72rem;color:#9bada6}
</style></head><body>
<div class="wrap">
<?php if ($done): ?>
  <div class="ok"><div class="big">&#10003;</div><h1 style="margin-top:8px"><?= h($form['thankyou'] ?: 'Thank you') ?></h1></div>
<?php else: ?>
  <h1><?= h($form['name']) ?></h1>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
  <form method="post">
    <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <?php foreach ($fields as $fld): $type = $fld['type'] ?? 'text'; $req = !empty($fld['required']) ? 'required' : ''; $nm = 'f_' . h($fld['name']); ?>
      <label><?= h($fld['label']) ?><?= $req ? ' *' : '' ?></label>
      <?php if ($type === 'textarea'): ?><textarea name="<?= $nm ?>" <?= $req ?>></textarea>
      <?php else: ?><input type="<?= h($type) ?>" name="<?= $nm ?>" <?= $req ?>><?php endif; ?>
    <?php endforeach; ?>
    <button>Submit</button>
  </form>
<?php endif; ?>
</div>
<div class="pwr">Powered by CRMax Core</div>
</body></html>
