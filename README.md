# CRMax Core

A free, self-hosted CRM you can run in minutes. One folder of PHP + SQLite. No framework, no
Composer, no build step, no monthly fee. Point a web server at it and open it in a browser.

> Looking for the hosted, full-featured platform (calendar, ERP, publishing, more)? See
> **[crmax.au](https://crmax.au)**. CRMax Core is the open-source essentials, yours to run and modify.

## Features

- **Contacts** - searchable, taggable, CSV import/export, notes, activity timeline
- **Companies** - a record for each organisation with its people, deals and combined activity; type a company on a contact and it is created and linked for you; merge duplicates
- **Pipeline** - drag-and-drop deal board with custom stages; each deal has an expected close date, win probability, company, contact, owner and notes; won/lost with a reason; reopen closed deals
- **Tasks** - a single to-do list across every contact, with due dates
- **Forms** - build lead-capture forms, share a link or embed them, submissions become contacts
- **Campaigns** - send email to all contacts or a tagged segment, with open tracking and one-click unsubscribe
- **Workflows** - automate follow-ups: when a contact is created or a form is submitted, wait / email / tag / create a task
- **Reports** - sales forecast (pipeline weighted by win probability, by expected close month), win rate, average deal size and days to close, contacts over time, lead sources, pipeline by stage, email performance
- **Team** - invite colleagues, admin and member roles
- **REST API** - a simple JSON API for integrations

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

## Background jobs (cron)

Workflows and campaign sending run from a scheduled task. Add one line to cron to run every minute:

```cron
* * * * * php /path/to/crmax-core/cron.php >/dev/null 2>&1
```

Prefer HTTP? Set `cron_key` in `config.php` (copy `config.sample.php`) and schedule
`GET https://your-crm/cron.php?key=YOUR_KEY` every minute.

> On the built-in PHP dev server, workflows and campaigns also run inline, so you don't need cron while testing.

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
| `GET /contacts`, `GET /contacts/{id}` | List (`?q=`, `?limit=`) or fetch contacts |
| `POST /contacts` | Create or update a contact (matched by email); `company` links or creates the company |
| `GET /companies`, `GET /companies/{id}` | List (`?q=`, `?limit=`) or fetch companies |
| `POST /companies` | Create or update a company (matched by name) |
| `GET /deals`, `GET /deals/{id}` | List deals (`?status=open\|won\|lost\|all`, open by default) or fetch one |
| `POST /deals` | Create a deal: `name`, plus optional `value`, `stage`, `pipeline_id`, `contact_id`, `company` or `company_id`, `close_date`, `probability`, `owner_id`, `notes` |
| `POST /deals/{id}` | Update a deal - only the fields you send change. `status` takes `open`, `won` or `lost` |
| `GET /pipelines` | Pipelines with their stages and each stage's win probability |

## Upgrading

Replace the application files with the new version and keep your `data/` folder. The database upgrades
itself the first time the app is opened - upgrades only ever add tables and columns, so nothing you
have is changed or removed. Copy `data/crm.sqlite` somewhere safe first, as you would before any upgrade.

Upgrading from 1.0 to 1.1 turns every company name already typed on a contact into a company record
and links the contacts to it.

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
