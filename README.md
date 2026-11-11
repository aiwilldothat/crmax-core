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
and nothing calls out unless you add one.

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

## Backups

Everything lives in `data/crm.sqlite`. Copy that file to back up; replace it to restore. (Because SQLite
runs in WAL mode, copy `crm.sqlite`, `crm.sqlite-wal` and `crm.sqlite-shm` together, or stop traffic first.)

## Security notes

- Keep `data/` out of the web root, or rely on the bundled `data/.htaccess` / the nginx rule above.
- Always run behind HTTPS in production (session cookies are marked secure automatically over HTTPS).
- CSRF protection, login rate limiting, and HTML sanitisation of saved content are built in.

## Need more?

CRMax Core covers the essentials. If you want calendars and booking, invoicing/ERP, funnel and page
publishing, custom domains, two-way inboxes, or a managed cloud setup, that is the hosted platform at
**[crmax.au](https://crmax.au)**. We also do **custom integrations and bespoke features** - get in touch
through the website.

## License

MIT - see [LICENSE](LICENSE). Use it, change it, ship it, commercially or not.
