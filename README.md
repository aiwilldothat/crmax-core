# CRMax Core

A free, self-hosted CRM you can run in minutes. One folder of PHP + SQLite. No framework, no
Composer, no build step, no monthly fee. Point a web server at it and open it in a browser.

> Looking for the hosted, full-featured platform (calendar, ERP, publishing, more)? See
> **[crmax.au](https://crmax.au)**. CRMax Core is the open-source essentials, yours to run and modify.

## Features

- **Contacts** - searchable, taggable, CSV import/export, notes, activity timeline; assign, tag or delete many at once from the list
- **Companies** - a record for each organisation with its people, deals and combined activity; type a company on a contact and it is created and linked for you; merge duplicates
- **Pipeline** - drag-and-drop deal board with custom stages; each deal has an expected close date, win probability, company, contact, owner and notes; won/lost with a reason; reopen closed deals
- **Tasks** - a single to-do list across every contact, with due dates and who each task is for
- **Owners** - every contact, company and deal has an owner and every task an assignee; show Mine, Everyone, Unassigned or one person's on the contacts list, the pipeline, tasks and the dashboard; share new leads out to one person or round-robin; the new owner gets an email
- **Forms** - build lead-capture forms, share a link or embed them, submissions become contacts
- **Campaigns** - send email to all contacts or a tagged segment, with open tracking and one-click unsubscribe
- **Workflows** - automate follow-ups: when a contact is created, a form is submitted or a tag is added, wait / email / tag / create a task
- **Reports** - sales forecast (pipeline weighted by win probability, by expected close month), win rate, average deal size and days to close, contacts over time, lead sources, pipeline by stage, email performance
- **Team** - invite colleagues, admin and member roles; removing someone hands what they own to whoever you choose
- **Two-factor sign-in** - a code from an authenticator app as well as the password, with recovery codes; optional per person, or required for the whole team
- **Audit log** - who signed in, what changed in security, the team and the settings, and who exported, deleted, bulk-changed or re-assigned records; kept for 12 months
- **REST API** - a simple JSON API for integrations
- **Add-ons** - add your own menu items, cards and rules without editing its files

**Your data, your email, your choice of AI.** Email is sent through *your own* SMTP account - nothing is
routed through a third party. AI drafting is optional and uses *your own* API key; none is bundled.

## Requirements

- PHP **8.1+** with the `pdo_sqlite` and `curl` extensions (both are standard on nearly every host)
- Any web server: Apache, nginx, Caddy, or even PHP's built-in server for a quick try

## Install

1. Download or clone this folder onto your server.
2. Make sure the `data/` directory is writable by the web server (it is created automatically if missing).
3. Point your web server's document root at the folder (or drop it in a subfolder - it works at either).
4. Open it in your browser. The first visit shows a short setup form - **the first account you create is the admin.**

That's it. The SQLite database installs itself on first run.

> Until that first account exists, whoever opens the CRM first can create it. To rule that out on a public
> server, put `'allow_install' => false` in `config.php` before you upload: visitors then see "Not set up yet".
> Remove the line (or set it to `true`) just while you create your account, then put it back.

### Try it locally

```bash
php -S localhost:8000
```

then open http://localhost:8000

### Apache

Works out of the box. The included `data/.htaccess` blocks web access to the database.

### nginx

Serve the folder and deny the data directory:

```nginx
location / { try_files $uri $uri/ =404; }
location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php-fpm.sock; }
location ^~ /data/ { deny all; return 404; }
```

## Sending email (SMTP)

Go to **Settings -> Email accounts** and add your mail provider's SMTP details (host, port, username,
password). Use the **Test** button to confirm it works. Campaigns, workflow emails, and form
notifications all send through this account. Common settings: port **587** with STARTTLS, or **465**
with SSL. For Gmail/Google Workspace, create an **App Password**.

## Owners, assignment and lead routing

Every contact, company and deal has an **owner**, and every task an **assignee**. Choose them on the
contact form and contact page, in the deal and company editors, and when adding a task; click the round
badge on a task to pass it to someone else. The contacts list, the pipeline board, the task list and the
dashboard each have a filter - **Everyone**, **Mine**, **Unassigned** or one person - which is remembered
per page until you sign out.

Owners organise the work; they do not restrict it. Everyone on the team can still see and edit every record.

**Lead routing** (Settings -> Lead routing, admins only) decides who gets a new contact that arrives
through a form, the API or a CSV import:

- **Leave new leads unassigned** - the default, and how earlier versions behaved.
- **Give every new lead to one person.**
- **Share them out in turn (round-robin)** between the people you tick. Each lead takes the next turn,
  whichever way it came in, and two leads arriving at the same moment never get the same turn.

A contact someone adds by hand is theirs unless they choose another owner. A lead who is already a
contact keeps their owner. A CSV can name the owner itself in an `owner` column (a team member's email
address or name), and the API takes `owner_id`; both win over the rule. A task created by a workflow goes
to the contact's owner.

**Assignment emails.** When something is given to someone - a contact, company, deal or task, or a new
lead routed to them - they get an email with a link to it, sent through your default email account.
Nothing is sent until an email account is connected, nobody is emailed about something they assign to
themselves, and a bulk assign or a CSV import sends one summary instead of an email per contact. Switch
these emails off with the box under Lead routing.

**Bulk actions.** Tick contacts on the list to assign them, add a tag to them or delete them in one go.
Deleting asks first.

**Removing a team member** (Settings -> Team) asks who takes over their contacts, companies, deals and
tasks, or lets you leave them unassigned. They also come out of the lead routing rule.

## Two-factor sign-in

With two-factor sign-in a password is not enough on its own: signing in also takes a 6-digit code from
an authenticator app on the person's phone. Any app that does time-based codes works - Google
Authenticator, Microsoft Authenticator, Authy, most password managers.

**Turning it on.** Everyone does this for their own account: **Settings -> Your account -> Set up
two-factor sign-in** (members find it under **Account** in the menu). Scan the QR code with the app,
type the code it shows, and it is on. The QR code is drawn by the CRM itself, so the secret in it never
leaves your server.

**Recovery codes.** Turning it on shows ten recovery codes, once. Each signs you in one time when the
phone is not to hand. Keep them somewhere that is not that phone. The account page shows how many are
left and makes new ones (the old ones then stop working).

**Signing in** asks for the password, then the code. Until the code is accepted nothing in the CRM opens.
A code works once, codes from the 30 seconds either side are accepted in case the phone's clock is a
little out, and wrong codes are counted: six from one address, or eight against one account, lock the
code step for ten minutes.

**A new phone.** The account page sets it up again from the start. The old phone keeps working until
the new one is confirmed.

**Requiring it** (Settings -> Security, admins only). Tick "Require two-factor sign-in for everyone" and
anyone who has not set it up is taken to the set-up page the next time they open the CRM, and can use
nothing else until it is done. You need it on your own account first. While it is required, nobody can
turn theirs off. The API token is not a sign-in and is not affected.

**When someone is locked out.**

- They lost their phone but have a recovery code: they sign in with it and set up the new phone.
- They have neither: an admin clicks **Reset two-factor** next to their name in Settings -> Team. They
  are signed out everywhere and sign in with their password alone (and set it up again, if it is required).
- Nobody can do that - the only admin is the one locked out, or forgot their password: run this on the
  server, in the CRM's folder.

```bash
php recover.php                          # lists the accounts
php recover.php 2fa-off you@example.com  # turns two-factor sign-in off for that account
php recover.php password you@example.com # gives it a new random password, printed once
```

`recover.php` only runs from the command line (a browser gets "not found"). Whoever can run commands on
the server can already read the whole database, so it opens nothing new. What it does is written to the
audit log as "Command line".

**Other sessions.** Your account page has **Sign out my other sessions**: every other browser and device
signed in as you is signed out, and this one stays. Changing your password, and turning two-factor
sign-in on or off, do the same.

How the secret is kept: each person's authenticator secret is stored sealed, with a key made from a
secret that lives in the same database, and recovery codes are stored only as hashes. That keeps them
out of sight in an export or a table viewed by accident. It does not protect them from someone who has
the whole `data/crm.sqlite` file - so keep `data/` private and treat backups of it as you would passwords.

## Audit log

**Settings -> Security -> Open the audit log** (admins only) lists what was done, by whom, when and from
which address, newest first:

- sign-ins, failed sign-ins (with the address that was typed) and sign-outs;
- password changes, every two-factor change, the API token being regenerated;
- team members added and removed, and what happened to the records of someone removed;
- changes to the organization, lead routing, email accounts and AI settings - that they changed, never
  a password or a key;
- every delete, with what was deleted; companies merged;
- bulk assign, bulk tag and bulk delete; CSV imports and exports, with the number of rows;
- every change of owner or assignee, with who had it before; campaigns sent.

Changes made through the API are recorded as "API", and what `recover.php` does as "Command line".
Filter by kind, person, date or words, and download what the filter shows as CSV.

The log cannot be edited or cleared in the app. Lines are kept for 12 months and then removed, by
`cron.php` or the next time the log is opened. It records addresses (IP) and the email address typed at
a failed sign-in, so tell your team it exists if your local rules ask for that.

The log starts when you install or upgrade to 1.3: nothing is made up about what happened before.

## Background jobs (cron)

Workflows and campaign sending run from a scheduled task. Add one line to cron to run every minute:

```cron
* * * * * php /path/to/crmax-core/cron.php >/dev/null 2>&1
```

Then tell the CRM its web address in `config.php` (copy `config.sample.php` to `config.php` if you
have none). On the command line there is no web request to learn the address from, so without this the
unsubscribe and open-tracking links in campaign and workflow email point at `localhost` and do not work:

```php
return [
    'base_url' => 'https://crm.example.com',   // or https://example.com/crm when it lives in a subfolder
];
```

`base_url` is used on the command line only; pages in the browser always use the address they were
opened at. Run `php cron.php` once by hand to check: it prints a warning if `base_url` is missing.

Prefer HTTP? Set `cron_key` in `config.php` and schedule `GET https://your-crm/cron.php?key=YOUR_KEY`
every minute. Call it at the CRM's public address, because that is the address the links in the email use.

**Settings -> Background jobs** shows when cron.php last ran, and every admin page shows a warning while
the email it sends has links that cannot work.

> On the built-in PHP dev server, campaigns also send straight away when you press Send. Workflow steps
> always wait for cron.php, so run `php cron.php` by hand to move them along while testing.

## Timezone

Choose the organisation's timezone in **Settings -> Organization**; dates, due dates and reports use it.
To fix it for the whole server instead, set `'timezone' => 'Australia/Sydney'` in `config.php`. That
setting wins, and the Settings field then shows it read-only.

## Currency

Amounts show as `$1,234.00 USD`. In `config.php`, `'currency' => 'AUD'` changes the code after the amount
(`''` shows none) and `'currency_symbol'` the sign before it, for example `'currency' => 'GBP'` with
`'currency_symbol'` set to the pound sign.

## AI (optional, bring your own key)

In **Settings -> AI**, paste your own Anthropic API key to enable AI email drafting. No key is included
and nothing calls out unless you add one. A saved key shows as a row of stars: leave them to keep it,
type over them to replace it, or tick **Remove the saved key**.

## REST API

Create a token in **Settings -> REST API**, then send it as a Bearer header:

```bash
curl -H "Authorization: Bearer <token>" https://your-crm/api.php/contacts
curl -H "Authorization: Bearer <token>" https://your-crm/api.php/me

# create / update a contact (de-duplicated by email)
curl -X POST https://your-crm/api.php/contacts \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"first_name":"Dana","email":"dana@example.com","tags":["web"],"source":"api"}'

# create a deal, then mark it won
curl -X POST https://your-crm/api.php/deals \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name":"Website rebuild","value":4800,"company":"Acme Pty Ltd","close_date":"2027-03-31"}'
curl -X POST https://your-crm/api.php/deals/12 \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"status":"won"}'
```

| Endpoint | What it does |
|---|---|
| `GET /me` | Organisation name, app version and counts |
| `GET /contacts`, `GET /contacts/{id}` | List (`?q=`, `?owner_id=`, `?limit=`) or fetch contacts |
| `POST /contacts` | Create or update a contact (matched by email); `company` links or creates the company; `tags` replaces the contact's tags (leave it out to keep them); `owner_id` sets the owner (`0` for nobody) - without it a new contact follows the lead routing rule and an existing one keeps its owner |
| `GET /companies`, `GET /companies/{id}` | List (`?q=`, `?limit=`) or fetch companies |
| `POST /companies` | Create or update a company (matched by name) |
| `GET /deals`, `GET /deals/{id}` | List deals (`?status=open\|won\|lost\|all`, open by default; `?owner_id=`) or fetch one |
| `POST /deals` | Create a deal: `name`, plus optional `value`, `stage`, `pipeline_id`, `contact_id`, `company` or `company_id`, `close_date`, `probability`, `owner_id`, `notes` |
| `POST /deals/{id}` | Update a deal - only the fields you send change. `status` takes `open`, `won` or `lost` |
| `GET /pipelines` | Pipelines with their stages and each stage's win probability |
| `GET /users`, `GET /users/{id}` | The team: `id`, `name` and `role` for each person, to make sense of `owner_id`. Read-only, and no email addresses |

Contacts, companies and deals carry `owner_id` (`0` = unassigned), and `?owner_id=` narrows the contact
and deal lists to one owner (`?owner_id=0` lists the unassigned ones). A write that gives a contact or a
deal to someone emails that person, as it does in the app; add `"notify": false` to the body to skip the
email, for example when syncing many records.

## Add-ons

An add-on extends CRMax Core without changing its files, so upgrading stays a plain file replace. Put it
in `addons/<name>/addon.php`. Every `addon.php` there is loaded on each request, after the core, and
registers callbacks with `addon_on()`:

```php
<?php
declare(strict_types=1);
if (!defined('APP_VERSION')) { http_response_code(404); exit; }   // opened directly: do nothing

// A card on every contact page.
addon_on('contact_panels', fn(array $contact, array $user): string =>
    '<div class="card"><h2>Hello</h2><p>' . h(contact_name($contact)) . '</p></div>');
```

`addon.php` is loaded before `lib.php`, so at that point it should only define functions and register
callbacks; do the work inside them. Add-ons run with the app's full access to the database, so install
only ones you trust.

| Hook | Kind | Called with | When |
|---|---|---|---|
| `authed` | event | `$user` | every signed-in request |
| `user_added` | event | `$userId` | a team member is added in Settings |
| `password_changed` | event | `$userId` | someone changes their password |
| `contact_deleted` | event | `$contactId` | a contact is deleted (just before its record goes) |
| `dashboard` | HTML | `$user` | under the dashboard counters |
| `contact_panels` | HTML | `$contact, $user` | on a contact's page, after the details |
| `settings_cards` | HTML | `$user` | for admins, after the other Settings cards |
| `campaign_editor` | HTML | `$campaign, $segment, $user` | at the top of the campaign editor |
| `nav` | filter | `$nav, $user` | the sidebar: `key => [file, icon name, label]` |
| `icons` | filter | `$paths` | extra sidebar icons: `icon name => SVG path markup` (24 x 24, stroked) |
| `password_problem` | filter | `$message, $newPassword, $user` | return a reason to refuse a new password, or `''` |
| `form_elsewhere` | filter | `$url, $form` | for a form whose status is not `active`: return the http(s) address where visitors should go instead |

An event's return value is ignored. Strings returned by HTML hooks are printed as they are, so escape what
you output with `h()`. A filter receives the value first and returns it, changed or not. Deleting several
contacts at once from the list calls `contact_deleted` once for each of them, inside that one delete.
`password_changed` also runs when `recover.php` resets a password from the server.

An add-on can write its own lines to the audit log with `audit('my_sync', 'Sent 12 invoices to the
accounts system')`: a short code for what happened, then one plain sentence. Never put a password, a
key or a token in it.

## Upgrading

Replace the application files with the new version and keep your `data/` folder. The database upgrades
itself the first time the app is opened - upgrades only ever add tables and columns, so nothing you
have is changed or removed. Copy `data/crm.sqlite` somewhere safe first, as you would before any upgrade.

Upgrading from 1.0 to 1.1 turns every company name already typed on a contact into a company record
and links the contacts to it.

Upgrading from 1.1.0 to 1.1.1 changes no data. Check these things afterwards:

- If cron.php runs from the command line, add `base_url` to `config.php` (see Background jobs). Without
  it, email sent by cron had unsubscribe links pointing at `localhost`, and those links did not work.
- Workflow emails now carry an unsubscribe link and a `List-Unsubscribe` header, like campaigns.
- The timezone chosen in Settings now takes effect (in 1.1.0 only the `config.php` value did). Check that
  Settings -> Organization shows the timezone you want.
- Workflows that start when "A tag is added" now run. In 1.1.0 they never started. A contact now enters
  them whenever it gains the tag: added by hand, by CSV import, through the API, or by another workflow's
  tag step. Look over any active ones before you upgrade, because they start enrolling contacts.
- Updating a contact through the API without a `tags` field, or importing a CSV without a tags column,
  no longer clears the contact's tags.
- After someone submits a public form, they now see its thank-you message, or go to its redirect address.
  In 1.1.0 they got the empty form again, although the submission was saved.
- Campaigns sent to a tag, and the contacts list filtered by a tag, now find tags containing `_`, `%` or
  accented letters. In 1.1.0 such a campaign reached nobody, and the list filter could also show similar tags.
- New and optional: add-ons (see Add-ons), and the `currency`, `currency_symbol` and `allow_install`
  settings in `config.php`. Nothing changes unless you use them.

Upgrading to 1.2 adds who created each task and a few indexes, and nothing else changes shape. What to
know afterwards:

- Earlier versions removed a team member without asking what should happen to their records, which
  left those records owned by nobody you could see. The upgrade marks them unassigned. Find them with the
  **Unassigned** filter and hand them to someone with the bulk **Assign** action. In the API their
  `owner_id` now reads `0`.
- Lead routing starts as "leave new leads unassigned", so forms, the API and CSV import behave as before
  until you choose a rule in Settings -> Lead routing.
- Once an email account is connected, people are emailed when a contact, company, deal or task is given
  to them. Switch that off under Lead routing if you do not want it.
- A task created by a workflow is now assigned to the contact's owner (it used to be unassigned).
- The contacts CSV export has one more column at the end, `owner`.
- API: contacts gain `owner_id`, and `GET /users` is new. Every field 1.0 and 1.1 returned is still
  there with the same meaning.

Upgrading from 1.2.0 to 1.2.1 changes no data. It fixes how amounts show on Linux servers: 1.2.0
printed `262145` in front of every amount there (`26214541,000.00` instead of `$41,000.00`), because
PHP on most Linux systems (Debian, Ubuntu, Red Hat and others built on glibc) already defines a
constant named `CURRENCY_SYMBOL`. The amounts stored in the database were always right. Windows
servers were not affected, and macOS, BSD and Alpine Linux should not have been, as PHP has no such
constant there. The `currency_symbol` setting in `config.php` keeps its name.

Upgrading to 1.3 adds what two-factor sign-in needs to each account, and the audit log. Nothing existing
changes: nobody is signed out by the upgrade, every account starts with two-factor sign-in off, and
passwords work as before. What to know afterwards:

- There are four new files: `twofa.php`, `audit.php`, `qr.php` and `recover.php`. Upload all of them.
- Two-factor sign-in is there for anyone who wants it (Settings -> Your account) and is not required
  until an admin says so under Settings -> Security.
- Members now have an **Account** link in the menu, for their password, two-factor sign-in and sessions.
  Until 1.3 only admins had a link to that page.
- Changing your password now signs out your other sessions.
- The audit log starts empty and records from the upgrade on.
- Someone signed out elsewhere, or after two weeks away, could meet "Bad request (CSRF)" on their first
  try at signing in again. That is fixed.
- Settings -> AI: saving the form with the stars still in the key field used to store the stars as the
  key, and an empty field removed it. Now the stars (or an empty field) keep the key and a tick box
  removes it. If AI drafting stopped working after you saved that form, paste your key in again.
- The contact and company CSV files are written and read as plain CSV: a backslash is just a
  backslash. On PHP 8.4 the old way could put a warning line inside an exported file.
- API: nothing changes. Every field 1.0 to 1.2 returned is still there with the same meaning.

## Backups

Everything lives in `data/crm.sqlite`. Copy that file to back up; replace it to restore. (Because SQLite
runs in WAL mode, copy `crm.sqlite`, `crm.sqlite-wal` and `crm.sqlite-shm` together, or stop traffic first.)

## Security notes

- Keep `data/` out of the web root, or rely on the bundled `data/.htaccess` / the nginx rule above.
- Always run behind HTTPS in production (session cookies are marked secure automatically over HTTPS).
- CSRF protection, login rate limiting, and HTML sanitisation of saved content are built in.
- Turn on two-factor sign-in, at least for admins, and consider requiring it (see Two-factor sign-in).
- The audit log shows who signed in and what was changed, exported or deleted (see Audit log).
- A backup of `data/crm.sqlite` holds everything, including what two-factor sign-in relies on. Store
  backups as carefully as the live file.

## Need more?

CRMax Core covers the essentials. If you want calendars and booking, invoicing/ERP, funnel and page
publishing, custom domains, two-way inboxes, or a managed cloud setup, that is the hosted platform at
**[crmax.au](https://crmax.au)**. We also do **custom integrations and bespoke features** - get in touch
through the website.

## License

MIT - see [LICENSE](LICENSE). Use it, change it, ship it, commercially or not.
