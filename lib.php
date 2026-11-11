<?php
/**
 * CRMax Core - engines: outbound email over your own SMTP, optional bring-your-own AI,
 * contact de-duplication, public form handling, and the workflow + campaign processors.
 */
declare(strict_types=1);
require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/_data.php';

/* ============================================================ EMAIL ACCOUNTS */
function email_accounts(): array { return db()->query("SELECT * FROM email_accounts ORDER BY is_default DESC, id")->fetchAll(); }
function email_account_get(int $id): ?array { $st = db()->prepare("SELECT * FROM email_accounts WHERE id=?"); $st->execute([$id]); return $st->fetch() ?: null; }
function email_account_default(): ?array { $r = db()->query("SELECT * FROM email_accounts ORDER BY is_default DESC, id LIMIT 1")->fetch(); return $r ?: null; }
function email_ready(): bool { return email_account_default() !== null; }

/* --------------------------------------------------- raw SMTP (no extensions) */
/** Read one (possibly multi-line) SMTP reply. Returns [code,int ; text,string]. */
function smtp_read($fp): array {
    $data = ''; $code = 0;
    while (($line = fgets($fp, 2048)) !== false) {
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') { $code = (int) substr($line, 0, 3); break; }
    }
    return [$code, $data];
}
function smtp_cmd($fp, string $cmd, int $expect): array {
    fwrite($fp, $cmd . "\r\n");
    [$code, $text] = smtp_read($fp);
    return [$code === $expect || ($expect === 250 && $code === 251), $code, $text];
}
/**
 * Send one email through a bring-your-own SMTP account. No PHP extensions required
 * (plain sockets + stream crypto). $secure: 'ssl' (implicit), 'tls' (STARTTLS), 'none'.
 * Returns ['ok'=>bool, 'err'=>string].
 */
function smtp_send(array $acc, string $to, string $subject, string $html, array $opts = []): array {
    $host = trim((string) $acc['smtp_host']); $port = (int) ($acc['smtp_port'] ?: 587);
    $secure = (string) ($acc['smtp_secure'] ?: 'tls');
    $fromEmail = trim((string) $acc['from_email']); $fromName = (string) ($acc['from_name'] ?: org_name());
    if ($host === '' || $fromEmail === '') return ['ok' => false, 'err' => 'Email account is not fully configured.'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'err' => 'Invalid recipient address.'];

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $transport = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['ok' => false, 'err' => "Could not connect to $host:$port ($errstr)"];
    stream_set_timeout($fp, 20);
    $fail = function (string $m) use ($fp) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return ['ok' => false, 'err' => $m]; };

    [$code] = smtp_read($fp); if ($code !== 220) return $fail("SMTP greeting failed ($code)");
    $ehloHost = mail_host();
    [$ok] = smtp_cmd($fp, 'EHLO ' . $ehloHost, 250); if (!$ok) { [$ok2] = smtp_cmd($fp, 'HELO ' . $ehloHost, 250); if (!$ok2) return $fail('EHLO rejected'); }
    if ($secure === 'tls') {
        [$ok] = smtp_cmd($fp, 'STARTTLS', 220); if (!$ok) return $fail('STARTTLS not available');
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) return $fail('TLS negotiation failed');
        smtp_cmd($fp, 'EHLO ' . $ehloHost, 250);
    }
    if (($acc['smtp_user'] ?? '') !== '') {
        [$ok] = smtp_cmd($fp, 'AUTH LOGIN', 334); if (!$ok) return $fail('Server refused AUTH LOGIN');
        [$ok] = smtp_cmd($fp, base64_encode((string) $acc['smtp_user']), 334); if (!$ok) return $fail('Username rejected');
        [$ok, $c, $t] = smtp_cmd($fp, base64_encode((string) $acc['smtp_pass']), 235); if (!$ok) return $fail('Authentication failed (' . trim($t) . ')');
    }
    [$ok, $c, $t] = smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', 250); if (!$ok) return $fail('MAIL FROM rejected (' . trim($t) . ')');
    [$ok, $c, $t] = smtp_cmd($fp, 'RCPT TO:<' . $to . '>', 250); if (!$ok) return $fail('Recipient rejected (' . trim($t) . ')');
    [$ok] = smtp_cmd($fp, 'DATA', 354); if (!$ok) return $fail('DATA rejected');

    // Build a multipart/alternative message (plain + HTML) for good deliverability.
    $boundary = 'b' . bin2hex(random_bytes(10));
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    $fromHdr = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>' : $fromEmail;
    $headers = "From: $fromHdr\r\nTo: <$to>\r\nSubject: $encSubject\r\n";
    // Only a plain address / a URL without spaces or brackets, so a value can never add a header of its own.
    $replyTo = trim((string) ($opts['reply_to'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    $unsub = (string) ($opts['unsubscribe'] ?? '');
    if ($unsub !== '' && !preg_match('/[\s<>]/', $unsub)) $headers .= 'List-Unsubscribe: <' . $unsub . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehloHost . ">\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$boundary--\r\n";
    // Dot-stuff lines that start with '.'
    $msg = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $headers . "\r\n" . $body));
    $msg = str_replace("\n", "\r\n", $msg);
    fwrite($fp, $msg . "\r\n.\r\n");
    [$code, $t] = smtp_read($fp);
    smtp_cmd($fp, 'QUIT', 221); @fclose($fp);
    return $code === 250 ? ['ok' => true, 'err' => ''] : ['ok' => false, 'err' => 'Send rejected (' . trim($t) . ')'];
}

/**
 * The host name this CRM sends mail as (EHLO and Message-ID): the web request's host, or on the command
 * line the host of config.php 'base_url'. The port is dropped, an IPv4 address is bracketed as SMTP wants,
 * and anything else that is not a plain host name falls back to localhost.
 */
function mail_host(): string {
    $h = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($h === '' && BASE_URL_CFG !== '') $h = (string) parse_url(BASE_URL_CFG, PHP_URL_HOST);
    $h = strtolower((string) preg_replace('/:\d*$/', '', trim($h)));
    if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return '[' . $h . ']';
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $h) ? $h : 'localhost';
}

/**
 * High-level send: pick an account (default or $opts['from_account']), send one email.
 * Replies go to the account's from address unless the caller passes its own $opts['reply_to'].
 */
function send_mail(string $to, string $subject, string $html, array $opts = []): array {
    $acc = isset($opts['from_account']) && $opts['from_account'] ? email_account_get((int) $opts['from_account']) : email_account_default();
    if (!$acc) return ['ok' => false, 'err' => 'No email account configured. Add one in Settings to send email.'];
    $defaults = $acc['from_email'] !== '' ? ['reply_to' => $acc['from_email']] : [];
    return smtp_send($acc, $to, $subject, $html, $opts + $defaults);
}

/* ----------------------------------------------------------- merge tags ---- */
function render_merge(string $text, array $c): string {
    $map = [
        '{{first_name}}' => $c['first_name'] ?? '', '{{last_name}}' => $c['last_name'] ?? '',
        '{{name}}' => contact_name($c), '{{email}}' => $c['email'] ?? '', '{{company}}' => $c['company'] ?? '',
        '{{phone}}' => $c['phone'] ?? '', '{{org}}' => org_name(),
    ];
    return strtr($text, $map);
}

/* ============================================================ CONTACTS ------ */
/**
 * Insert or update a contact, de-duplicating on email. Fires the contact_created workflow trigger for a
 * new contact, and tag_added for each tag the contact gains. Tags are replaced only when $in has 'tags'.
 */
function contact_upsert(array $in, string $source = ''): int {
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    $existing = null;
    if ($email !== '') { $st = db()->prepare("SELECT * FROM contacts WHERE email=? LIMIT 1"); $st->execute([$email]); $existing = $st->fetch() ?: null; }
    $fields = ['first_name', 'last_name', 'email', 'phone', 'company', 'address', 'source'];
    $data = [];
    foreach ($fields as $f) if (isset($in[$f]) && $in[$f] !== '') $data[$f] = (string) $in[$f];
    if ($email !== '') $data['email'] = $email;
    if (isset($in['tags'])) $data['tags'] = json_encode(array_values(array_filter(array_map('trim', (array) $in['tags']))), JSON_UNESCAPED_SLASHES);
    if (isset($in['custom'])) $data['custom'] = json_encode((array) $in['custom'], JSON_UNESCAPED_SLASHES);
    if ($existing) {
        $data['updated_at'] = time();
        go_update('contacts', (int) $existing['id'], $data);
        if (isset($data['company'])) contact_set_company((int) $existing['id'], $data['company']);
        if (isset($data['tags'])) wf_fire_tags_added((int) $existing['id'], jdec($existing['tags']), jdec($data['tags']));
        return (int) $existing['id'];
    }
    if ($source !== '') $data['source'] = $source;
    $data['created_at'] = time(); $data['updated_at'] = time();
    $id = go_insert('contacts', $data);
    if (isset($data['company'])) contact_set_company($id, $data['company']);
    act_log($id, 'contact_created', ['source' => $source]);
    wf_fire('contact_created', $id);
    if (isset($data['tags'])) wf_fire_tags_added($id, [], jdec($data['tags']));
    return $id;
}

/* ============================================================ COMPANIES ----- */
/** Tidy a company name: trimmed, inner whitespace collapsed, capped at 160 characters. */
function company_clean(string $name): string { return mb_substr(trim((string) preg_replace('/\s+/', ' ', $name)), 0, 160); }
/** The bare domain of a website or email-style value: "https://www.Acme.com/about" -> "acme.com". */
function company_domain(string $v): string {
    $v = mb_strtolower(trim($v));
    if ($v === '') return '';
    $host = (string) (parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#', $v) ? $v : 'http://' . $v, PHP_URL_HOST) ?: '');
    $host = preg_replace('/^www\./', '', $host);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', (string) $host) ? mb_substr((string) $host, 0, 120) : '';
}
/** Clean the editable company columns out of a form post, CSV row or API body (name is handled separately). */
function company_input(array $in): array {
    $website = mb_substr(trim((string) ($in['website'] ?? '')), 0, 200);
    $domain = company_domain((string) ($in['domain'] ?? ''));
    if ($domain === '' && $website !== '') $domain = company_domain($website);
    return [
        'website'  => $website,
        'domain'   => $domain,
        'phone'    => mb_substr(trim((string) ($in['phone'] ?? '')), 0, 60),
        'address'  => mb_substr(trim((string) ($in['address'] ?? '')), 0, 300),
        'industry' => mb_substr(trim((string) ($in['industry'] ?? '')), 0, 80),
        'notes'    => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 5000),
    ];
}
/** A safe href for a website someone typed: only http(s), with the scheme added when missing. Returns '' if unusable. */
function website_href(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $v)) $v = 'https://' . $v;
    return preg_match('#^https?://[^\s<>"\']+$#i', $v) ? $v : '';
}
/**
 * Find a company by name (case-insensitive) or create it. Returns its id, or 0 for an empty name.
 * $extra holds other columns to set (website, phone...); on an existing company only non-empty values are applied.
 */
function company_upsert(string $name, array $extra = []): int {
    $name = company_clean($name);
    if ($name === '') return 0;
    $st = db()->prepare("SELECT id FROM companies WHERE name=? COLLATE NOCASE ORDER BY id LIMIT 1"); $st->execute([$name]);
    $id = (int) $st->fetchColumn();
    $extra = array_filter($extra, fn($v) => $v !== '' && $v !== null);
    unset($extra['name']);
    if ($id) { if ($extra) go_update('companies', $id, $extra + ['updated_at' => time()]); return $id; }
    return go_insert('companies', ['name' => $name, 'created_at' => time(), 'updated_at' => time()] + $extra);
}
/**
 * Link a contact to a company by name and keep contacts.company (the text copy used by merge tags
 * and exports) in step. An empty name unlinks. Never throws: saving a contact must not fail because
 * of company linking - forms, imports and the API all pass through here.
 */
function contact_set_company(int $contactId, string $name): void {
    try {
        $name = company_clean($name);
        if ($name === '') { go_update('contacts', $contactId, ['company_id' => 0, 'company' => '']); return; }
        $coId = company_upsert($name);
        $co = $coId ? go_get('companies', $coId) : null;
        go_update('contacts', $contactId, ['company_id' => $coId, 'company' => (string) ($co['name'] ?? $name)]);
    } catch (Throwable $e) { /* leave the contact as saved */ }
}
/** Rename a company and refresh the text copy on its contacts. */
function company_rename(int $id, string $name): void {
    $name = company_clean($name);
    if ($name === '') return;
    go_update('companies', $id, ['name' => $name, 'updated_at' => time()]);
    db()->prepare("UPDATE contacts SET company=? WHERE company_id=?")->execute([$name, $id]);
}
/** Fold one company into another: its people and deals move across, then it is removed. */
function company_merge(int $fromId, int $intoId): bool {
    $from = go_get('companies', $fromId); $into = go_get('companies', $intoId);
    if (!$from || !$into || $fromId === $intoId) return false;
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE contacts SET company_id=?, company=? WHERE company_id=?")->execute([$intoId, $into['name'], $fromId]);
        $pdo->prepare("UPDATE deals SET company_id=? WHERE company_id=?")->execute([$intoId, $fromId]);
        $fill = [];   // keep details the surviving company is missing
        foreach (['domain', 'phone', 'website', 'address', 'industry'] as $k) if ((string) $into[$k] === '' && (string) $from[$k] !== '') $fill[$k] = $from[$k];
        if ((string) $from['notes'] !== '') $fill['notes'] = trim($into['notes'] . "\n\n" . $from['notes']);
        if ($fill) go_update('companies', $intoId, $fill + ['updated_at' => time()]);
        go_del('companies', $fromId);
        $pdo->commit();
        return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return false; }
}
/** Delete a company. Its people and deals stay; they are just no longer linked (the contact keeps the name as text). */
function company_delete(int $id): void {
    db()->prepare("UPDATE contacts SET company_id=0 WHERE company_id=?")->execute([$id]);
    db()->prepare("UPDATE deals SET company_id=0 WHERE company_id=?")->execute([$id]);
    go_del('companies', $id);
}

/* ============================================================ DEALS --------- */
/** Win probability (%) for each stage of a pipeline: the saved list, or an even spread where none is saved. */
function stage_probs(array $pipe): array {
    $n = count(jdec($pipe['stages'] ?? '[]'));
    $saved = jdec($pipe['stage_probs'] ?? '[]');
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $v = $saved[$i] ?? null;
        $out[] = is_numeric($v) ? max(0, min(100, (int) $v)) : (int) round(100 * ($i + 1) / ($n + 1));
    }
    return $out;
}
/** A deal's win probability: its own override, otherwise the default for the stage it is in. */
function deal_prob(array $deal, array $probs): int {
    if ((int) ($deal['probability'] ?? -1) >= 0) return min(100, (int) $deal['probability']);
    if (!$probs) return 0;
    return (int) $probs[min(max(0, (int) $deal['stage']), count($probs) - 1)];
}
/** Value x probability, in cents. */
function deal_weighted(array $deal, array $probs): int { return (int) round((int) $deal['value_cents'] * deal_prob($deal, $probs) / 100); }
/** Accept only a real YYYY-MM-DD date; anything else becomes ''. */
function valid_date(string $d): string {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
}
/** Up to two initials for a person, e.g. "Dana Lee" -> "DL". */
function initials(string $name): string {
    $parts = preg_split('/[\s@._-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $s = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    return $s !== '' ? $s : '?';
}

/* ============================================================ FORMS --------- */
/** Handle a public form submission. Returns ['ok'=>bool,'err'=>string,'contact_id'=>int]. */
function form_handle_submit(array $form, array $post): array {
    if (!empty($post['website'])) return ['ok' => true, 'err' => '', 'contact_id' => 0]; // honeypot: pretend success
    $fields = jdec($form['fields']);
    $map = []; $extra = [];
    foreach ($fields as $f) {
        $key = (string) ($f['name'] ?? ''); if ($key === '') continue;
        $val = trim((string) ($post['f_' . $key] ?? ''));
        if (($f['required'] ?? false) && $val === '') return ['ok' => false, 'err' => 'Please complete all required fields.', 'contact_id' => 0];
        if (in_array($key, ['first_name', 'last_name', 'email', 'phone', 'company'], true)) $map[$key] = $val;
        else $extra[$f['label'] ?? $key] = $val;
    }
    if (($map['email'] ?? '') !== '' && !filter_var($map['email'], FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'err' => 'Please enter a valid email address.', 'contact_id' => 0];
    $cid = 0;
    if (($map['email'] ?? '') !== '' || ($map['phone'] ?? '') !== '') {
        $cid = contact_upsert($map + ['custom' => $extra], 'form:' . $form['slug']);
    }
    $subId = go_insert('form_submissions', ['form_id' => (int) $form['id'], 'contact_id' => $cid, 'data' => json_encode($map + $extra, JSON_UNESCAPED_SLASHES), 'created_at' => time()]);
    db()->prepare("UPDATE forms SET submits=submits+1 WHERE id=?")->execute([(int) $form['id']]);
    if ($cid) { act_log($cid, 'form_submitted', ['form' => $form['name']]); wf_fire('form_submitted', $cid, ['form_id' => (int) $form['id']]); }
    // Notify the team (uses the default email account; silently skipped if none configured).
    if ((int) $form['notify'] === 1) {
        $to = (string) (org()['notify_email'] ?? '');
        if ($to === '') { $admin = db()->query("SELECT email FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn(); $to = $admin ?: ''; }
        if ($to !== '') {
            $rows = '';
            foreach (($map + $extra) as $k => $v) $rows .= '<tr><td style="padding:4px 10px;color:#5f7269">' . h((string) $k) . '</td><td style="padding:4px 10px"><b>' . h((string) $v) . '</b></td></tr>';
            send_mail($to, 'New form submission: ' . $form['name'], '<p>A new submission came in via <b>' . h($form['name']) . '</b>:</p><table>' . $rows . '</table>');
        }
    }
    return ['ok' => true, 'err' => '', 'contact_id' => $cid];
}

/* ============================================================ WORKFLOWS ----- */
/** Enrol a contact into every active workflow that listens for $trigger. */
function wf_fire(string $trigger, int $contactId, array $ctx = []): void {
    $st = db()->prepare("SELECT * FROM workflows WHERE status='active' AND trigger_on=?");
    $st->execute([$trigger]);
    foreach ($st->fetchAll() as $wf) {
        // tag_added / specific-form filters live in config; a simple contact_created/form_submitted matches all.
        $cfg = jdec($wf['config']);
        if ($trigger === 'form_submitted' && !empty($cfg['form_id']) && (int) $cfg['form_id'] !== (int) ($ctx['form_id'] ?? 0)) continue;
        if ($trigger === 'tag_added' && !empty($cfg['tag']) && $cfg['tag'] !== ($ctx['tag'] ?? '')) continue;
        // avoid duplicate active enrolment
        $dup = db()->prepare("SELECT 1 FROM wf_enrollments WHERE workflow_id=? AND contact_id=? AND status='active'");
        $dup->execute([(int) $wf['id'], $contactId]);
        if ($dup->fetchColumn()) continue;
        go_insert('wf_enrollments', ['workflow_id' => (int) $wf['id'], 'contact_id' => $contactId, 'step' => 0, 'next_at' => time(), 'status' => 'active', 'ctx' => json_encode($ctx, JSON_UNESCAPED_SLASHES), 'created_at' => time()]);
        db()->prepare("UPDATE workflows SET enrolled_count=enrolled_count+1 WHERE id=?")->execute([(int) $wf['id']]);
    }
}
/**
 * Fire the tag_added trigger once for each tag in $after that was not in $before: wherever a contact
 * gains a tag (by hand, CSV import, the API, a workflow's tag step). Keeping or re-saving a tag fires nothing.
 */
function wf_fire_tags_added(int $contactId, array $before, array $after): void {
    $before = array_map('strval', $before);
    foreach (array_unique(array_map('strval', $after)) as $tag) {
        if ($tag !== '' && !in_array($tag, $before, true)) wf_fire('tag_added', $contactId, ['tag' => $tag]);
    }
}
/** Process due workflow enrolments (called by cron, and inline in dev). */
function wf_run_due(int $limit = 100): int {
    $done = 0;
    $st = db()->prepare("SELECT * FROM wf_enrollments WHERE status='active' AND next_at<=? ORDER BY next_at LIMIT ?");
    $st->execute([time(), $limit]);
    foreach ($st->fetchAll() as $en) {
        $wf = go_get('workflows', (int) $en['workflow_id']); if (!$wf) { go_update('wf_enrollments', (int) $en['id'], ['status' => 'done']); continue; }
        $steps = jdec($wf['steps']); $i = (int) $en['step'];
        $c = go_get('contacts', (int) $en['contact_id']);
        if (!$c) { go_update('wf_enrollments', (int) $en['id'], ['status' => 'done']); continue; }
        if (!isset($steps[$i])) { go_update('wf_enrollments', (int) $en['id'], ['status' => 'done']); continue; }
        $step = $steps[$i]; $type = (string) ($step['type'] ?? '');
        $advanceAt = time();
        if ($type === 'wait') {
            $advanceAt = time() + max(1, (int) ($step['minutes'] ?? 60)) * 60;
            go_update('wf_enrollments', (int) $en['id'], ['step' => $i + 1, 'next_at' => $advanceAt]);
            // waiting is itself the step; move on without "doing" more now
            $done++; continue;
        } elseif ($type === 'email') {
            if ((int) ($c['dnd_email'] ?? 0) !== 1 && ($c['email'] ?? '') !== '') {
                // Like campaigns: an unsubscribe link in the footer and a List-Unsubscribe header (Spam Act, CAN-SPAM).
                $unsub = unsub_url((int) $c['id']);
                $subject = render_merge((string) ($step['subject'] ?? ''), $c);
                $bodyHtml = email_wrap(render_merge(nl2br_html((string) ($step['body'] ?? '')), $c), ['footer' => unsub_footer($unsub)]);
                send_mail((string) $c['email'], $subject, $bodyHtml, ['unsubscribe' => $unsub]);
                act_log((int) $c['id'], 'workflow_email', ['workflow' => $wf['name'], 'subject' => $subject]);
            }
        } elseif ($type === 'tag') {
            // A tag the contact gains here fires tag_added, so one workflow can start another. That cannot loop:
            // a tag the contact already has fires nothing, steps only ever add tags, a contact is never in the same
            // workflow twice at once, and new enrolments wait for the next run.
            $tags = jdec($c['tags']); $tg = trim((string) ($step['tag'] ?? ''));
            if ($tg !== '' && !in_array($tg, $tags, true)) {
                $before = $tags; $tags[] = $tg;
                go_update('contacts', (int) $c['id'], ['tags' => json_encode($tags, JSON_UNESCAPED_SLASHES), 'updated_at' => time()]);
                wf_fire_tags_added((int) $c['id'], $before, $tags);
            }
        } elseif ($type === 'task') {
            go_insert('tasks', ['contact_id' => (int) $c['id'], 'title' => render_merge((string) ($step['title'] ?? 'Follow up'), $c), 'due_date' => date('Y-m-d', time() + max(0, (int) ($step['due_days'] ?? 1)) * 86400), 'status' => 'open', 'created_at' => time()]);
        }
        // advance to next step immediately (unless it was a wait, handled above)
        go_update('wf_enrollments', (int) $en['id'], ['step' => $i + 1, 'next_at' => time(), 'status' => isset($steps[$i + 1]) ? 'active' : 'done']);
        $done++;
    }
    return $done;
}
function nl2br_html(string $s): string { return nl2br(h($s)); }
/** Wrap body text/HTML in a clean responsive email shell. */
function email_wrap(string $inner, array $opts = []): string {
    $footer = $opts['footer'] ?? '';
    return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.6;color:#16241f;max-width:600px;margin:0 auto">'
        . $inner . ($footer !== '' ? '<div style="margin-top:22px;padding-top:14px;border-top:1px solid #e2ebe6;font-size:12px;color:#8aa">' . $footer . '</div>' : '') . '</div>';
}

/* ============================================================ CAMPAIGNS ----- */
/** Contacts matching a campaign segment. segment: {type:'all'} | {type:'tag',tag:'x'}. */
function segment_contacts(array $seg): array {
    $type = (string) ($seg['type'] ?? 'all');
    if ($type === 'tag' && ($seg['tag'] ?? '') !== '') {
        [$cond, $params] = tag_sql((string) $seg['tag']);
        $st = db()->prepare("SELECT * FROM contacts WHERE email!='' AND dnd_email=0 AND $cond");
        $st->execute($params);
        return $st->fetchAll();
    }
    return db()->query("SELECT * FROM contacts WHERE email!='' AND dnd_email=0")->fetchAll();
}
/**
 * SQL condition (and its parameters) for "the contact has this tag", for contacts.tags (a JSON list).
 * It looks for the tag as a whole JSON string, so % and _ in a tag are plain characters, and it matches
 * a non-ASCII tag both as stored (\u-escaped) and as plain UTF-8. Like before, ASCII letters match in any case.
 */
function tag_sql(string $tag): array {
    $like = fn(string $s): string => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
    return ["(tags LIKE ? ESCAPE '\\' OR tags LIKE ? ESCAPE '\\')", [
        $like((string) json_encode($tag, JSON_UNESCAPED_SLASHES)),
        $like((string) json_encode($tag, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ]];
}
/** Materialise recipients for a campaign that is being sent. */
function camp_queue(int $campaignId): int {
    $camp = go_get('campaigns', $campaignId); if (!$camp) return 0;
    $n = 0;
    foreach (segment_contacts(jdec($camp['segment'])) as $c) {
        $dup = db()->prepare("SELECT 1 FROM campaign_recipients WHERE campaign_id=? AND contact_id=?"); $dup->execute([$campaignId, (int) $c['id']]);
        if ($dup->fetchColumn()) continue;
        go_insert('campaign_recipients', ['campaign_id' => $campaignId, 'contact_id' => (int) $c['id'], 'status' => 'pending']); $n++;
    }
    return $n;
}
/** Send pending campaign recipients (called by cron, and inline in dev). */
function camp_run_due(int $limit = 50): int {
    $sent = 0;
    $camps = db()->query("SELECT * FROM campaigns WHERE status='sending' AND scheduled_at<=" . time())->fetchAll();
    foreach ($camps as $camp) {
        $acc = $camp['from_account'] ? email_account_get((int) $camp['from_account']) : email_account_default();
        $st = db()->prepare("SELECT * FROM campaign_recipients WHERE campaign_id=? AND status='pending' LIMIT ?");
        $st->execute([(int) $camp['id'], $limit - $sent]);
        $rows = $st->fetchAll();
        if (!$rows) { db()->prepare("UPDATE campaigns SET status='sent' WHERE id=?")->execute([(int) $camp['id']]); continue; }
        foreach ($rows as $r) {
            $c = go_get('contacts', (int) $r['contact_id']);
            if (!$c || ($c['email'] ?? '') === '' || (int) $c['dnd_email'] === 1) { go_update('campaign_recipients', (int) $r['id'], ['status' => 'skipped']); continue; }
            $unsub = unsub_url((int) $c['id']);
            $pixel = '<img src="' . h(base_url() . '/t.php?c=' . (int) $camp['id'] . '&r=' . (int) $r['id']) . '" width="1" height="1" alt="" style="display:none">';
            $html = email_wrap(render_merge($camp['body'], $c) . $pixel, ['footer' => unsub_footer($unsub)]);
            $res = $acc ? smtp_send($acc, (string) $c['email'], render_merge($camp['subject'], $c), $html, ['unsubscribe' => $unsub]) : ['ok' => false, 'err' => 'no account'];
            if ($res['ok']) { go_update('campaign_recipients', (int) $r['id'], ['status' => 'sent', 'sent_at' => time()]); db()->prepare("UPDATE campaigns SET sent_count=sent_count+1 WHERE id=?")->execute([(int) $camp['id']]); $sent++; }
            else { go_update('campaign_recipients', (int) $r['id'], ['status' => 'failed']); db()->prepare("UPDATE campaigns SET fail_count=fail_count+1 WHERE id=?")->execute([(int) $camp['id']]); }
            if ($sent >= $limit) break 2;
        }
    }
    return $sent;
}
function unsub_token(int $contactId): string { return $contactId . '.' . substr(hash_hmac('sha256', (string) $contactId, app_secret()), 0, 16); }
/** A contact's one-click unsubscribe link. On the command line it needs config.php 'base_url' (see _boot.php). */
function unsub_url(int $contactId): string { return base_url() . '/u.php?c=' . urlencode(unsub_token($contactId)); }
/** The footer campaign and workflow email carry: who it is from, and the unsubscribe link. */
function unsub_footer(string $unsubUrl): string { return 'You are receiving this from ' . h(org_name()) . '. <a href="' . h($unsubUrl) . '">Unsubscribe</a>.'; }
function unsub_verify(string $tok): int {
    $p = explode('.', $tok, 2); if (count($p) !== 2) return 0;
    return hash_equals(substr(hash_hmac('sha256', $p[0], app_secret()), 0, 16), $p[1]) ? (int) $p[0] : 0;
}

/* ============================================================ AI (BYO) ------ */
/** The Anthropic API key, entered by an admin in Settings. AI is entirely optional and bring-your-own. */
function ai_key(): string { return (string) setting_get('ai_key', ''); }
function ai_model(): string { return (string) setting_get('ai_model', 'claude-haiku-4-5'); }
function ai_available(): bool { return ai_key() !== ''; }
/** One-shot completion via the Anthropic Messages API using the admin's own key. Returns text or ''. */
function ai_complete(string $prompt, int $maxTokens = 900): string {
    $key = ai_key(); if ($key === '') return '';
    $payload = json_encode(['model' => ai_model(), 'max_tokens' => $maxTokens, 'messages' => [['role' => 'user', 'content' => $prompt]]], JSON_UNESCAPED_SLASHES);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
    $res = curl_exec($ch); if ($res === false) return ''; curl_close($ch);
    $j = json_decode((string) $res, true);
    return (string) ($j['content'][0]['text'] ?? '');
}

/* ============================================================ REST API key -- */
function api_token(): string { $t = (string) setting_get('api_token', ''); if ($t === '') { $t = 'crm_' . token(); setting_set('api_token', $t); } return $t; }
function api_token_reset(): string { $t = 'crm_' . token(); setting_set('api_token', $t); return $t; }
