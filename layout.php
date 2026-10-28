<?php
/** CRMax Core - page layout: the app shell (sidebar + nav), public page chrome, icons, logo. */
declare(strict_types=1);

/* Stroked line icons (Lucide-style), inherit colour via currentColor. */
function svg_icon(string $n, int $size = 19): string {
    $p = [
        'dash'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h5v-6h4v6h5V9.5"/>',
        'contacts'  => '<path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20"/><circle cx="10" cy="8" r="3.5"/><path d="M20 20v-1.4a3.5 3.5 0 0 0-2.7-3.4"/><path d="M15.5 4.6a3.5 3.5 0 0 1 0 6.8"/>',
        'pipelines' => '<rect x="3" y="4" width="5" height="16" rx="1.2"/><rect x="9.5" y="4" width="5" height="10" rx="1.2"/><rect x="16" y="4" width="5" height="13" rx="1.2"/>',
        'tasks'     => '<rect x="3.5" y="3.5" width="17" height="17" rx="2.6"/><path d="m8.3 12.2 2.6 2.6 5-5.4"/>',
        'campaigns' => '<path d="M4 10.5v3l11 4.5V6L4 10.5z"/><path d="M4 10.5H3a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1h1"/><path d="M7 15v2.3A1.7 1.7 0 0 0 10.3 18l-.3-1.4"/><path d="M18.5 9a3.2 3.2 0 0 1 0 6"/>',
        'workflows' => '<rect x="3" y="3.5" width="6" height="6" rx="1.6"/><rect x="15" y="14.5" width="6" height="6" rx="1.6"/><path d="M9 6.5h3.5a2.5 2.5 0 0 1 2.5 2.5v5.5"/>',
        'forms'     => '<rect x="5" y="3.5" width="14" height="17.5" rx="2.2"/><path d="M9 3.5v1.8h6V3.5"/><path d="M8.5 10h7M8.5 13.5h7M8.5 17h4.5"/>',
        'reports'   => '<path d="M3.5 20.5h17"/><rect x="5.5" y="11" width="3" height="7" rx="0.7"/><rect x="10.5" y="6" width="3" height="12" rx="0.7"/><rect x="15.5" y="13.5" width="3" height="4.5" rx="0.7"/>',
        'settings'  => '<path d="M4 7h9M17 7h3"/><path d="M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2.3"/><circle cx="9" cy="17" r="2.3"/>',
        'logout'    => '<path d="M9.5 21H6a2.5 2.5 0 0 1-2.5-2.5v-13A2.5 2.5 0 0 1 6 3h3.5"/><path d="m15.5 16 4-4-4-4M20 12H9"/>',
        'ai'        => '<path d="M12 3.5 13.7 8 18 9.7 13.7 11.4 12 16l-1.7-4.6L6 9.7 10.3 8 12 3.5z"/>',
    ];
    $d = $p[$n] ?? '<circle cx="12" cy="12" r="8"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" width="' . $size . '" height="' . $size . '" aria-hidden="true">' . $d . '</svg>';
}
function crmax_logo(int $size = 30): string {
    $id = 'clg' . $size;
    return '<svg class="logomark" width="' . $size . '" height="' . $size . '" viewBox="0 0 32 32" fill="none" aria-hidden="true">'
        . '<rect width="32" height="32" rx="8.5" fill="url(#' . $id . ')"/>'
        . '<path d="M7.5 20.5 13 15l3.2 3.2L24 10.5" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="24" cy="10.5" r="2.35" fill="#fff"/>'
        . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse"><stop stop-color="#0ca678"/><stop offset="1" stop-color="#0a9fc0"/></linearGradient></defs></svg>';
}
function crmax_wordmark(): string { return '<span class="wm">CRM<span>ax</span> <small>Core</small></span>'; }

/** Shared stylesheet (app + public). */
function base_css(): string {
    return <<<CSS
 :root{--bg:#eef2f0;--surface:#fff;--surface2:#f2f6f4;--line:rgba(18,40,33,.11);--text:#16241f;--muted:#5f7269;
   --brand:#0ca678;--brand2:#0a9fc0;--ok:#0ca678;--warn:#b8790a;--bad:#d64553;--card-r:16px;
   --shadow:0 1px 2px rgba(20,40,33,.05),0 6px 20px rgba(20,40,33,.05)}
 *{box-sizing:border-box;margin:0;padding:0}
 body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:
   radial-gradient(900px 520px at 92% -12%,rgba(10,159,192,.07),transparent 60%),
   radial-gradient(760px 520px at -8% 112%,rgba(12,166,120,.06),transparent 60%),var(--bg);
   color:var(--text);line-height:1.5;min-height:100vh}
 a{color:#0a8f66;text-decoration:none}
 .wm{font-weight:800;letter-spacing:-.02em;color:var(--text)}
 .wm span{background:linear-gradient(110deg,var(--brand),var(--brand2));-webkit-background-clip:text;background-clip:text;color:transparent}
 .wm small{font-weight:700;font-size:.62em;color:var(--muted);letter-spacing:0}
 .shell{display:flex;min-height:100vh}
 .side{width:214px;flex:0 0 214px;background:#fff;border-right:1px solid var(--line);padding:16px 10px;position:sticky;top:0;height:100vh;overflow-y:auto}
 .logo{font-weight:800;font-size:1.15rem;padding:2px 8px 16px;display:flex;align-items:center;gap:9px;color:var(--text)}
 .logo .logomark{flex:0 0 auto;border-radius:8px;box-shadow:0 2px 6px rgba(12,166,120,.28)}
 .nav a{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:10px;color:var(--muted);font-size:.9rem;margin-bottom:2px}
 .nav a:hover{color:var(--text);background:var(--surface2)}.nav a:hover .ic{color:var(--text)}
 .nav a.on{color:#0a7a58;background:linear-gradient(110deg,rgba(12,166,120,.13),rgba(10,159,192,.10));border:1px solid rgba(12,166,120,.28);font-weight:600}
 .nav a.on .ic{color:#0a9a6c}
 .nav .ic{flex:0 0 24px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;color:var(--muted)}
 .nav .sep{height:1px;background:var(--line);margin:10px 6px}
 .main{flex:1;min-width:0;padding:20px 22px 60px;max-width:1200px}
 .topbar{display:none}
 .pagehead{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
 .pagehead h1{font-size:1.3rem;font-weight:700}
 .pagehead .sp{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
 .card{background:var(--surface);border:1px solid var(--line);border-radius:var(--card-r);padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}
 .card h2{font-size:.95rem;margin-bottom:12px}
 label{display:block;font-size:.8rem;color:var(--muted);margin:10px 0 5px}
 input,textarea,select{width:100%;background:#fff;border:1px solid #cfdad4;border-radius:10px;padding:10px 13px;color:var(--text);font:inherit}
 input:focus,textarea:focus,select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(12,166,120,.13)}
 textarea{resize:vertical;min-height:100px}
 .btn{display:inline-block;background:linear-gradient(110deg,var(--brand),var(--brand2));color:#fff;font-weight:700;border:none;border-radius:999px;padding:10px 20px;cursor:pointer;font-size:.9rem;box-shadow:0 2px 8px rgba(12,166,120,.25)}
 .btn:hover{opacity:.93}.btn.sm{padding:7px 14px;font-size:.8rem;box-shadow:none}
 .btn.ghost{background:#fff;color:var(--text);border:1px solid #cfdad4;box-shadow:none}.btn.ghost:hover{border-color:var(--brand);color:#0a7a58}
 .btn.danger{background:#fff;color:var(--bad);border:1px solid rgba(214,69,83,.4);box-shadow:none}
 .btn[disabled]{opacity:.5;cursor:not-allowed}
 .row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1;min-width:150px}.grow0{flex:0 0 auto}
 .grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}.grid4{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
 .muted{color:var(--muted)}.small{font-size:.82rem}
 .mono{font-family:ui-monospace,Consolas,monospace;font-size:.82rem;word-break:break-all}
 .tag{display:inline-block;font-size:.72rem;padding:2px 9px;border-radius:999px;border:1px solid var(--line);color:var(--muted);background:#fff}
 .tag.ok{color:#0a7a58;border-color:rgba(12,166,120,.4);background:rgba(12,166,120,.08)}
 .tag.warn{color:#996208;border-color:rgba(184,121,10,.4);background:rgba(184,121,10,.08)}
 .tag.bad{color:#b23343;border-color:rgba(214,69,83,.4);background:rgba(214,69,83,.07)}
 .flash{padding:11px 14px;border-radius:11px;margin-bottom:14px;font-size:.88rem}
 .flash.ok{background:rgba(12,166,120,.10);border:1px solid rgba(12,166,120,.35);color:#0a6b4a}
 .flash.err{background:rgba(214,69,83,.09);border:1px solid rgba(214,69,83,.35);color:#a4283a}
 table.tbl{width:100%;border-collapse:collapse;font-size:.86rem}
 table.tbl th,table.tbl td{text-align:left;padding:9px 8px;border-bottom:1px solid var(--line);vertical-align:middle}
 table.tbl th{color:var(--muted);font-weight:600;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em}
 table.tbl tr:last-child td{border-bottom:none}
 .scrollx{overflow-x:auto}
 .kpi{background:var(--surface);border:1px solid var(--line);border-radius:var(--card-r);padding:14px 16px;box-shadow:var(--shadow)}
 .kpi .n{font-size:1.5rem;font-weight:800}.kpi .l{font-size:.76rem;color:var(--muted);text-transform:uppercase;letter-spacing:.05em}
 .empty{text-align:center;padding:36px 16px;color:var(--muted)}.empty .big{font-size:2rem;margin-bottom:8px}
 .modalbg{display:none;position:fixed;inset:0;background:rgba(20,35,30,.42);z-index:40;align-items:flex-start;justify-content:center;padding:40px 14px;overflow:auto}
 .modalbg.open{display:flex}
 .modal{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;width:100%;max-width:560px;box-shadow:0 20px 60px rgba(20,40,33,.2)}
 @media (max-width:900px){
   .side{position:fixed;left:-240px;z-index:50;transition:left .2s;box-shadow:4px 0 24px rgba(20,40,33,.12)}.side.open{left:0}
   .topbar{display:flex;align-items:center;gap:12px;padding:12px 14px;background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:30}
   .topbar .burger{background:none;border:1px solid var(--line);border-radius:9px;color:var(--text);padding:6px 11px;font-size:1rem;cursor:pointer}
   .main{padding:14px 14px 60px}.grid2,.grid3,.grid4{grid-template-columns:1fr 1fr}
   .shellbg{display:none;position:fixed;inset:0;background:rgba(20,35,30,.35);z-index:45}.shellbg.open{display:block}
 }
 @media (max-width:560px){ .grid2,.grid3,.grid4{grid-template-columns:1fr} }
 button:focus-visible,a:focus-visible,input:focus-visible{outline:2px solid var(--brand2);outline-offset:2px}
CSS;
}

function head(string $title, string $active = '', ?array $u = null): void {
    sess_start();   // establish the session (and its cookie) before any output, so CSRF tokens persist
    $nav = [
        'dash'      => ['index.php', 'dash', 'Dashboard'],
        'contacts'  => ['contacts.php', 'contacts', 'Contacts'],
        'pipelines' => ['pipelines.php', 'pipelines', 'Pipeline'],
        'tasks'     => ['tasks.php', 'tasks', 'Tasks'],
        'campaigns' => ['campaigns.php', 'campaigns', 'Campaigns'],
        'workflows' => ['workflows.php', 'workflows', 'Workflows'],
        'forms'     => ['forms.php', 'forms', 'Forms'],
        'reports'   => ['reports.php', 'reports', 'Reports'],
    ];
    ?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> &middot; <?= h(APP_NAME) ?></title>
<style><?= base_css() ?></style></head><body>
<div class="shell">
 <aside class="side" id="side">
  <a class="logo" href="<?= h(url('index.php')) ?>"><?= crmax_logo(30) ?><?= crmax_wordmark() ?></a>
  <nav class="nav">
  <?php foreach ($nav as $k => $it): ?>
    <a href="<?= h(url($it[0])) ?>" class="<?= $active === $k ? 'on' : '' ?>"><span class="ic"><?= svg_icon($it[1]) ?></span><?= h($it[2]) ?></a>
  <?php endforeach; ?>
    <div class="sep"></div>
  <?php if (is_admin($u)): ?><a href="<?= h(url('settings.php')) ?>" class="<?= $active === 'settings' ? 'on' : '' ?>"><span class="ic"><?= svg_icon('settings') ?></span>Settings</a><?php endif; ?>
    <a href="<?= h(url('logout.php?t=' . csrf())) ?>"><span class="ic"><?= svg_icon('logout') ?></span>Log out</a>
  </nav>
  <?php if ($u): ?><div class="small muted" style="padding:14px 12px 0"><?= h(org_name()) ?><br><span class="tag"><?= h($u['name'] ?: $u['email']) ?></span></div><?php endif; ?>
 </aside>
 <div class="shellbg" id="shellbg" onclick="toggleSide()"></div>
 <div style="flex:1;min-width:0">
  <div class="topbar"><button class="burger" onclick="toggleSide()" aria-label="Menu">&#9776;</button><span class="logo" style="padding:0;font-size:1.05rem"><?= crmax_logo(26) ?><?= crmax_wordmark() ?></span></div>
  <main class="main">
<?php echo flash_html();
}
function foot(): void { ?>
  </main>
 </div>
</div>
<script>function toggleSide(){document.getElementById('side').classList.toggle('open');document.getElementById('shellbg').classList.toggle('open');}</script>
</body></html>
<?php }

/* ---- public page chrome (login, forms, 404) ---- */
function pub_head(string $title): void {
    sess_start();   // establish the session (and its cookie) before any output, so CSRF tokens persist
    ?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> &middot; <?= h(APP_NAME) ?></title>
<style><?= base_css() ?>
 .pwrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px}
 .pcard{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px;width:100%;max-width:440px;box-shadow:var(--shadow)}
 .pcard h1{font-size:1.4rem;margin-bottom:4px}.pmuted{color:var(--muted);font-size:.9rem}
 .pbtn{display:inline-block;background:linear-gradient(110deg,var(--brand),var(--brand2));color:#fff;font-weight:700;border:none;border-radius:999px;padding:11px 22px;cursor:pointer;font-size:.92rem;width:100%;text-align:center}
 .plogo{display:flex;align-items:center;gap:9px;justify-content:center;margin-bottom:16px}
</style></head><body><div class="pwrap"><div style="width:100%;max-width:440px">
 <div class="plogo"><?= crmax_logo(34) ?><?= crmax_wordmark() ?></div>
<?php echo flash_html();
}
function pub_foot(): void { ?>
</div></div></body></html>
<?php }
