# VoxelBooking

Open-source booking infrastructure for small businesses. Four booking patterns: timeslots, resources, capacity, events, in one self-hosted installation. No SaaS dependency, no vendor lock-in, and no mandatory AI-provider dependency.

[![VoxelBooking business dashboard](https://voxelbooking.com/screens/desktop/business-dashboard.webp)](https://voxelbooking.com/demo)

## Links

- Website: [voxelbooking.com](https://voxelbooking.com)
- Documentation: [voxelbooking.com/docs](https://voxelbooking.com/docs)
- Demo: [voxelbooking.com/demo](https://voxelbooking.com/demo)
- Support and issues: [github.com/NowSquare/VoxelBooking/issues](https://github.com/NowSquare/VoxelBooking/issues)

## License

VoxelBooking is open source software licensed under the GNU Affero General Public License v3.0. See [LICENSE](LICENSE) for the full terms.

## Booking patterns

| Pattern | Built for | How it works |
|---------|-----------|--------------|
| **Timeslot** | Salons, clinics, consultants | Services × staff matrix, configurable slot duration and buffer times |
| **Resource** | Hotels, rentals, co-working | Per-unit nightly availability with check-in/out day restrictions |
| **Capacity** | Restaurants, group classes | Party size against slot min/max, remaining seats updated in real time |
| **Event** | Workshops, concerts, classes | RRULE recurring events, waitlist, per-booking spot limits |

Each business is assigned one pattern at creation. All four share the same bookings table, admin UI, and email pipeline.

---

## Before you install

### What you get

Everything VoxelBooking needs to run ships in the repository. Clone it and it runs as-is. There is no build step, no `composer install`, and no Node.js on your server.

| Included | Notes |
|----------|-------|
| PHP source + framework | Custom micro-framework — no Laravel or Symfony |
| `vendor/` | All Composer dependencies, committed and ready |
| `public/assets/` | Pre-built CSS and JS — no Node.js required |
| Migration files | Run automatically by the installer |
| English translations | 12 additional locales are registry-ready |
| Demo SQLite database | For demo mode (optional) |

Your production server needs only PHP and MySQL — never Composer, Node.js, or npm.

### Server requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3+ |
| MySQL | 8.0+ |
| Web server | Apache with `mod_rewrite`, or Nginx |
| Location | The root of a domain or subdomain |

**Required PHP extensions:** `pdo` `pdo_mysql` `curl` `json` `mbstring` `fileinfo` `openssl` `gd` `sodium` `zip`

VoxelBooking runs at `example.com` or `booking.example.com`. It can't run from a subfolder like `example.com/booking`, because every route and link starts at `/`. If your main site already uses the domain, add a subdomain for VoxelBooking.

Most shared hosting providers include all of these by default. The installer's system check step confirms every requirement before proceeding.

---

## Installation

Three steps: get the files, point your web server at the `public/` directory, and open your domain. The first visit launches a five-step setup wizard that writes your configuration and runs the database migrations for you. Nothing to compile, no config file to edit by hand.

### Step 1 — Get the files

Clone the repository onto your server (a VPS, or any shared host with SSH and Git):

```bash
git clone https://github.com/NowSquare/VoxelBooking.git
```

The clone includes `vendor/` and the pre-built `public/assets/`, so it runs immediately — no `composer install`, no `npm install`.

**No SSH or Git on your host?** Download the latest [release archive](https://github.com/NowSquare/VoxelBooking/releases), extract it, and upload the contents with FTP/SFTP or your control panel's file manager. The result is identical.

### Step 2 — Point your web server at `public/`

VoxelBooking works with the document root on the project folder or on its `public/` folder. Either way, it has to be the document root of a domain or subdomain.

**Shared hosting (cPanel, Plesk, DirectAdmin).** Put the files in the web root of the domain or subdomain (typically `public_html/`, or the folder your control panel created for the subdomain). The root `.htaccess` rewrites every request into `public/` and blocks direct access to the application directories, so you don't need to change the document root. Create a MySQL database in your control panel and note the name, user, and password — the wizard asks for them.

**VPS / dedicated server — Apache.** Point `DocumentRoot` at the `public/` directory. That keeps `.env`, `app/`, and `vendor/` outside the web root:

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/VoxelBooking/public

    <Directory /var/www/VoxelBooking/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable the site and reload Apache.

**VPS / dedicated server — Nginx.**

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/VoxelBooking/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

Match the `fastcgi_pass` socket to your PHP-FPM version, then reload Nginx.

> **HTTPS:** On a production server, get a free TLS certificate with [Let's Encrypt / Certbot](https://certbot.eff.org/). `certbot --nginx` adds the certificate and HTTPS redirect for you.

### Step 3 — Open your domain and run the installer

Visit your domain. With no configuration yet, VoxelBooking sends you straight to the installer — there is no special URL to remember. The five-step wizard takes about a minute:

| Step | What happens |
|------|-------------|
| System check | Verifies your PHP version, required extensions, and writable directories |
| Database | Connects to MySQL, runs every migration, and seeds initial settings |
| Email | Configures SMTP for booking confirmations and admin notifications |
| Operator account | Creates the super-admin login for your installation |
| First business | Creates your first booking page — pattern, name, slug, timezone |

The wizard writes your `.env` automatically; you never edit it by hand. When it finishes you land in the admin panel, and your first booking page is live at `yourdomain.com/book/your-business-slug`.

> The installer locks itself once setup is complete. Re-opening the install URL on a configured site returns 404, so it can never run twice.

---

## After installation

### Admin panel

Your admin panel is at `yourdomain.com/admin`. Log in with the operator account created during the wizard.

From the admin panel you can:

- Create additional businesses — each gets its own isolated booking page and settings
- Invite business owners — they log in and manage only their own business
- View bookings, customers, audit logs, and email logs across all businesses
- Configure SMTP, data retention policy, and system-wide defaults

### Booking pages

Each business has a public booking page at `yourdomain.com/book/{slug}`. The slug is set at business creation and can be changed from Business Settings at any time.

Customers see one booking summary through selection, details, and review. They can edit the choices that apply to their booking pattern. Labelled phases show where they are. Failed availability requests offer **Try again**. Field errors stay beside their inputs, and customers keep their entered details while they correct a problem.

Physical bookings use venue time. Timezone context appears when the customer and business clocks differ at the appointment. Virtual appointments offer a timezone choice on a mismatch and show the converted date beside its time, including a previous or next day. Use the calendar to browse appointments. The optional **Find a time for me** experiment searches 7 days through the same availability rules.

The booking page preserves each business's identity color. Selected fills use a measured foreground, while primary actions keep white labels on a derived brand fill. Normal and hover states reach at least 4.5:1 contrast. The calendar supports date-based keyboard navigation, a single tab stop, and named unavailable dates.

The admin dashboard leads with upcoming bookings and today's work, with totals below. Business users see pending approvals, their next booking, and today's schedule. Setup guidance follows the booking pattern and the user's role.

### Background delivery and maintenance

Configure the cron command shown in **Admin → Settings → Cron** to run every five minutes. Keep its token private. Cron delivers queued email and signed webhooks, retries failed deliveries, reconciles interrupted uploads, runs scheduled maintenance and applies configured data retention.

A successful booking commits independently of delivery. Without cron, queued notifications and maintenance remain pending; monitor their status before going live.

---

## Upgrading

Updates run from **Admin → Updates**, on two paths.

- **Git updates (recommended for Git installs).** When the install is a Git checkout, the Updates page shows a **Git Updates** card. It reports your branch and whether a new version is available, then applies it in one click: it pulls the latest release and runs any pending database migrations together. It refuses to run if you have local changes or are ahead of the repository, preserves your `.env` and uploads, and applies pending database migrations. If files are current after a failed migration, **Retry database update** resumes the pending migrations.
- **Release archive (for shared hosting without Git).** Download the latest [release archive](https://github.com/NowSquare/VoxelBooking/releases) and upload it from the same Updates page, or drop it in `/dist/`. VoxelBooking stages every file, applies it, and runs migrations — your `.env`, `storage/`, and `public/uploads/` are preserved.

Back up your database, deployed code, `.env`, `storage/keys/`, `storage/media-journal/` and `public/uploads/` as a coherent set before any update, and test on a staging copy first — AGPL-3.0 places no limit on the number of installations. The full guide, including the shell `git fetch --tags` / `git checkout <tag>` workflow for pinning a specific version, is in the [Updating documentation](https://voxelbooking.com/docs/getting-started/updating).

---

## Agent API and native MCP

Customers, business teams and operators can use scoped connections to discover and perform the actions their current role permits. The versioned HTTP API and native MCP transport share application operations with the human interface across all four booking patterns. Reviewed changes use browser approval, stable idempotency keys and recoverable operation receipts. Connections can be inspected, revoked and reviewed in **AI connections**.

See the [Agent API documentation](https://voxelbooking.com/docs/agent-api), [release notes](docs/agent-api/release-notes.md) and [verification evidence](docs/agent-api/release-evidence.md) for migration requirements, exact tested clients and remaining external verification boundaries.

---

## Demo mode

Demo mode lets visitors try bookings against the configured demo database while settings and account changes stay locked. Use a separate installation with fictional fixtures.

### Activate

```bash
touch .demo
```

When active:

- Public bookings, cancellations, and rescheduling use the normal workflow
- System/business settings and security-sensitive writes stay locked
- The admin topbar shows the existing dismissible demo pill
- The public booking notice can be dismissed for the current visit and returns after a reload
- The login page shows clickable credential cards for all demo accounts

### Demo accounts

All accounts use the password `welcome3210`.

| Role | Email | Sees |
|------|-------|------|
| Operator | `demo@voxelbooking.com` | All 4 businesses and system settings |
| Demo Studio (owner) | `owner@demo-studio.test` | Timeslot pattern — Services, Staff, Availability |
| Demo Studio (manager) | `manager@demo-studio.test` | Timeslot pattern — restricted manager view |
| Hotel Marina | `owner@hotel-marina.test` | Resource pattern — Resources |
| Trattoria Roma | `owner@trattoria-roma.test` | Capacity pattern — Capacity Slots |
| Workshop Studio | `owner@workshop-studio.test` | Event pattern — Events, Waitlist |

The operator sees all four businesses on the dashboard. Each business owner is auto-redirected to their own business and sees only the menus relevant to their booking pattern.

### Deactivate

```bash
rm .demo
```

The next request stops applying demo restrictions. The configured database stays the same.

---

## Troubleshooting

**Blank page or HTTP 500 on first load**
Enable PHP error output temporarily (add `php_flag display_errors on` to `.htaccess` or set in `php.ini`), reload, read the error, then remove it. The most common causes are a missing PHP extension or incorrect file permissions on `storage/`.

**The installer does not appear — I see a default server page**
The root `.htaccess` is not being applied. Confirm `mod_rewrite` is enabled (`a2enmod rewrite` on Ubuntu/Debian) and that `AllowOverride All` is set for the document root directory in your Apache config.

**Every page returns 404, including the installer**
You're opening VoxelBooking from a subfolder, such as `example.com/booking` or `server-ip/VoxelBooking/public`. VoxelBooking only runs at the root of a domain or subdomain. Create a subdomain (or an Apache virtual host) whose document root is the VoxelBooking folder or its `public/` folder, then open that address. Nothing shows up in `storage/logs/` for this, because a 404 isn't an error.

**"Access denied" on the database step**
The MySQL user lacks sufficient privileges. The installation user needs `CREATE`, `ALTER`, `INSERT`, `SELECT`, `UPDATE`, `DELETE`, and `DROP` on the target database. Grant full privileges for installation; you can restrict them afterwards if your security policy requires it.

**Pages load but all internal links return 404**
`mod_rewrite` is enabled but rules are not being applied. Set `AllowOverride All` in your virtual host or `httpd.conf`. On Nginx, confirm the `try_files $uri $uri/ /index.php` rule is in place and Nginx has been reloaded.

**Booking page loads but the booking widget is blank**
The `public/assets/` directory is missing or empty. Re-clone or re-extract — the pre-built assets must be present at `public/assets/js/booking.js` and `public/assets/css/booking-css.css`.

**Emails are not being delivered**
Go to Admin → Settings → Email → Send Test Email. The result tells you whether the SMTP connection succeeds. Port 587 with STARTTLS is the most widely supported configuration. Check that your SMTP host, port, encryption type, username, and password are all correct. If your host blocks outbound SMTP on port 25, switch to 587 or 465.

**SMTP reports "Could not authenticate" but the credentials work elsewhere**
Installations up to 1.1.0 discarded the SMTP password on save, so the server received a blank one. Update, then re-enter the password in Admin → Settings → Email and save. The field always loads blank, so typing it again is the only way to confirm it is stored.

**Admin session drops after every page load**
PHP cannot write session files. Confirm that `storage/sessions/` exists and is writable by the web server user (`chmod 755` or `chmod 775` depending on your server setup). Also check that your `session.save_path` in `php.ini` is not overriding this.

**Logo or cover image upload fails silently**
Confirm `public/uploads/` exists and is writable by the web server user. Also verify that the `fileinfo` and `gd` PHP extensions are enabled — the system check in the installer confirms both.

---

## Localization

English ships as the only complete translation. Twelve additional locales are registered with full formatting rules and are ready for translation files: `nl`, `de`, `es`, `fr`, `id`, `it`, `ja`, `pt`, `pl`, `tr`, `ar`. Arabic (`ar`) includes full RTL layout — the platform sets `dir="rtl"` on `<html>` and uses CSS logical properties throughout.

### Locale resolution

| Surface | Locale source | Timezone source |
|---------|--------------|----------------|
| Booking page | Business override → browser `Accept-Language` (if translation exists) → business default → `en` | Business timezone for storage and physical bookings; chosen customer timezone for virtual appointment display |
| Privacy pages | Same as booking page | Business timezone |
| Admin panel | Business locale → `APP_LOCALE` in `.env` → `en` | Browser-detected (set in session) |
| Emails | Active locale at send time | Business timezone |

### Per-business formatting

Each business stores explicit formatting values, editable in Business Settings. All values are validated against whitelists; tampered values are rejected.

| Setting | Column | Allowed values |
|---------|--------|---------------|
| Date notation | `tenants.date_format` | `Y-m-d` `d/m/Y` `m/d/Y` `d-m-Y` `d.m.Y` |
| Time format | `tenants.time_format` | `12h` `24h` |
| Number notation | `tenants.number_format` | `period` `comma` `space` |
| Week start | `tenants.week_start` | `0` (Sunday) `1` (Monday) `6` (Saturday) |

### Currency

Thirty currencies are registered in `config/currencies.php`, mapping ISO 4217 codes to display symbols. The selected currency symbol is injected into the booking page as `window.__VB_FMT__.currency_symbol` for consistent rendering across PHP and JS surfaces.

### Adding a locale

1. Add the locale entry to `config/locales.php` with its formatting rules.
2. Create `lang/{locale}/` with translation files mirroring the `lang/en/` structure.
3. No code changes required — the locale resolver picks it up automatically.

---

## Privacy and compliance

VoxelBooking is built with privacy-by-design architecture. The following describes what the software implements — not a legal guarantee. Compliance obligations remain with the operator.

### Explicit outbound integrations

Configured email providers and webhooks make outbound delivery requests. OAuth can fetch a connecting client’s HTTPS metadata, and an operator can request a Git update. There is no required AI provider, telemetry, analytics beacon, automatic update check or CDN dependency. Fonts, icons and scripts are bundled.

### What it stores

- **A necessary session cookie** (`vb_session`) supports sign-in, browser CSRF protection and verified customer access. Bearer-authenticated Agent API and MCP requests use credentials rather than browser-session authority. No analytics cookies, marketing cookies or fingerprinting.
- **One localStorage entry** (`vb-theme`) — dark/light mode preference stored in the browser.

### GDPR controls

| Control | What it does |
|---------|-------------|
| Consent capture | Records the exact consent text shown to the customer and a `consent_given_at` timestamp at booking time. Changing the consent text later does not retroactively alter recorded consents. |
| Customer anonymization | Name → "Deleted", email → SHA-256 hash, phone and notes → NULL. Booking structure and consent evidence are preserved. |
| Data export | JSON export of all customer personal data, booking history, and consent records for GDPR Art. 20 portability requests. |
| Self-service privacy page | Customers verify their email at `/book/{slug}/privacy/{customer-ulid}` to access, export or request deletion of their data. Booking-management links use separate revocable credentials. |
| Operator deletion queue | Customer deletion requests appear in Admin → Deletion Queue. The operator confirms (triggers anonymization) or dismisses. Both actions are audit-logged. |
| Retention cron | Automated per-business anonymization based on a configurable `data_retention_months` setting. |

### Audit log

Authentication events (login, logout, failed login), settings changes, and password changes produce structured, append-only audit log entries. Passwords and tokens are never logged. Email addresses are stored as SHA-256 prefixes.

### What operators are responsible for

- A legally reviewed privacy policy appropriate for their jurisdiction
- Consent text that is accurate and specific to their business
- Responding to data-subject requests within legal timeframes
- Data Processing Agreements with their hosting and SMTP providers
- Server-level security: TLS certificates, backups, and access control

The software does not claim GDPR certification or any legal guarantee. It provides the technical controls — organizational and legal obligations remain with the operator.

---

## Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8.3+ — custom micro-framework, no Laravel or Symfony |
| Database | MySQL 8+ — PDO with prepared statements, no ORM |
| Admin frontend | Alpine.js 3, Tailwind CSS 4, Lucide icons |
| Booking frontend | Alpine.js 3, Tailwind CSS 4, Lucide icons |
| Build tooling | Vite 6, dual entry points (admin + booking) |

### Runtime Composer dependencies

| Package | Purpose |
|---------|---------|
| `phpmailer/phpmailer` | SMTP email delivery |
| `robinvdvleuten/ulid` | ULID primary key generation |
| `rlanvin/php-rrule` | Recurring event rule expansion |
| `nikic/fast-route` | HTTP route dispatching |

---

## Directory structure

```
app/                    PHP application code
  Controllers/          Route handlers (Admin, Booking, Auth, API)
  Engine/               Core framework classes and booking calculators
  Middleware/           Request middleware (CSRF, Auth, Demo, Rate limit)
  Migrations/           Sequential SQL migrations (001 onward)
  Models/               Data models (no ORM)
config/                 Configuration files (locale registry, currency registry)
lang/                   Translation files
  en/                   English — booking, auth, admin, validation, email, privacy
public/                 Web root — set this as your document root
  assets/               Pre-built CSS and JS (Vite output)
  uploads/              Uploaded logos and cover images
resources/              Frontend source files (not web-accessible)
  css/                  Tailwind 4 source with design token definitions
  js/                   Alpine.js source — admin and booking entry points
storage/                Runtime storage (not web-accessible)
  logs/                 Application logs
  sessions/             PHP session files (8-hour expiry)
  cache/                Cache files
  demo/                 Demo SQLite database
templates/              PHP view templates
tests/                  PHPUnit test suites
  Integration/          HTTP-level booking flow tests
  Unit/                 Calculator and model unit tests
```

**Never expose to the web:** `app/`, `config/`, `lang/`, `resources/`, `storage/`, `templates/`, `.env`. The root `.htaccess` and the Nginx config above block all of these by design.

---

---

## For developers

> Everything below this line is for developers extending, modifying, or contributing to VoxelBooking. If you want to install VoxelBooking on a production server, everything you need is above.

---

---

### Local development setup

**Prerequisites:** PHP 8.3+, MySQL 8.0+, Node.js 20+, Composer, and [Laravel Herd](https://herd.laravel.com/) or [Laravel Valet](https://laravel.com/docs/valet).

```bash
# Install PHP dependencies
composer install

# Install JS build dependencies
npm install

# Copy and configure the environment file
cp .env.example .env
# Edit .env with your local database credentials

# Create the local database
mysql -u root --host=127.0.0.1 --port=3309 -e "CREATE DATABASE IF NOT EXISTS voxelbooking;"

# Build frontend assets
npm run build

# Start Vite dev server with hot module replacement
npm run dev
```

The app serves via Herd or Valet at the domain matching the directory name. The document root must be the `public/` directory — Herd and Valet configure this automatically.

The default local MySQL port is `3309` (Herd's default). Adjust `DB_PORT` in `.env` if your setup differs.

### Schema refresh

The migration files are the source of truth during development. When the data model changes, drop and recreate the database rather than layering compatibility patches over stale tables:

```bash
mysql -u root --host=127.0.0.1 --port=3309 \
  -e "DROP DATABASE IF EXISTS voxelbooking; CREATE DATABASE voxelbooking;"
```

Then rerun the installer or your local migration bootstrap against the clean database.

> VoxelBooking standardizes on `DATETIME` for all persisted date-time columns. Do not introduce `TIMESTAMP` columns.

### Demo database (SQLite)

`storage/demo/demo.db` is committed to the repository, so demo mode works on any clone (the release archive omits it). To activate demo mode locally:

```bash
touch .demo                # Activates demo mode — remove with `rm .demo`
```

To regenerate the demo database after schema changes:

```bash
php demo-seed.php          # Rebuilds storage/demo/demo.db
```

> **Schema parity:** `demo-seed.php` creates SQLite tables that must mirror every MySQL migration in `app/Migrations/`. When you add or alter a migration, update `demo-seed.php` to match, bump the `db_version` setting, regenerate, and commit the updated `demo.db`. The build script also regenerates during packaging as a safety net.

### Disposable MySQL verification

Use `php scripts/test-isolated.php` for write-path tests. It creates a private application copy, an explicitly disposable MySQL database and a guarded test manifest. Use `--keep --serve` when inspecting synthetic browser or client flows, then stop the retained runner.

The legacy `demo-seed-mysql.php` script drops and recreates its configured tables. Do not run it against an ordinary development or production database; the isolated runner is the supported verification path.

### Building assets

```bash
npm run build    # production — compiles CSS and JS to public/assets/
npm run dev      # dev server with hot module replacement
```

After changing CSS, run the token audit. It checks admin and booking stylesheets for undefined `--vb-*` references, with exemptions for runtime properties:

```bash
python3 scripts/audit-css-tokens.py
```

After adding or changing user-facing text, run the i18n audit. It scans the templates, the PHP code and the JS for hardcoded (untranslated) strings, English-only date formats, and translation keys used in code but missing from `lang/en/`, and checks that every other `lang/{locale}/` mirrors `lang/en/`:

```bash
python3 scripts/audit-i18n.py             # exit code 1 when problems are found
python3 scripts/audit-i18n.py --verbose   # also list the clean files
python3 scripts/audit-i18n.py --strict    # locale parity warnings fail too
python3 -m unittest discover -s tests/scripts   # tests of the audit itself
```

The findings are heuristics. English that is intentional (API contracts, example data, last-resort defaults) is listed with its reason in the `IGNORE` table at the top of the script; a single line can also be silenced with an `i18n-ignore` comment.

### Building a release archive (optional)

You no longer need a ZIP to install or update VoxelBooking — cloning the repository is the simplest path, and updates run from the admin panel. A packaged archive is still handy for offline installs, mirroring, or distribution. Build one with:

```bash
./scripts/build-dist.sh            # uses the version in VERSION
./scripts/build-dist.sh 1.2.0      # set and stamp a specific version
```

The script builds production assets, copies the working tree (excluding runtime data, dev tooling, and user content), verifies every critical file is present, and writes `dist/voxelbooking-v{version}.zip`. That archive is exactly what the **Admin → Updates** page accepts, or what you extract for a fresh install.

### Testing

Use the disposable runner for both suites. It starts MySQL in Docker, copies the application into a private temporary directory, starts a loopback HTTP server with four workers, and captures email. It never copies the application's `.env`, uploads or ordinary database. PHPUnit refuses to run without the generated test manifest.

The test host needs Docker and PHP with `pdo_mysql`, `pdo_sqlite`, `pcntl` and `posix`. These runner requirements do not apply to production hosting.

```bash
# Full test suite
php scripts/test-isolated.php --testdox

# Unit tests only
php scripts/test-isolated.php --testsuite Unit

# Integration tests only
php scripts/test-isolated.php --testsuite Integration

# Specific pattern tests
php scripts/test-isolated.php --filter EventBookingFlowTest
php scripts/test-isolated.php --filter CapacityBookingFlowTest

# Supported minimum database and upgrade/recovery rehearsal
php scripts/test-isolated.php --mysql-image=mysql:8.0
php scripts/test-isolated.php --upgrade-check --mysql-image=mysql:8.0

# Keep a private environment running for browser/API checks
php scripts/test-isolated.php --keep --serve
```

Both suites include database-backed checks. Integration coverage exercises actual HTTP/MCP flows, authorization, retries and competing writers. The upgrade rehearsal creates a fictional version 28 installation, verifies migration and interrupted-DDL recovery, restores its backup and upgrades again. The default database image is MySQL 8.4; CI runs PHP 8.3/MySQL 8.0 and PHP 8.5/MySQL 8.4.

`--keep` preserves private evidence after shutdown. `--serve` prints a private `run-tests.sh` command and keeps the server available until interrupted. Every invocation has its own database and files; the runner stops its workers and removes its database container on exit.

---

## Version

The canonical version lives in the root [`VERSION`](VERSION) file. Do not hardcode version strings elsewhere. The installer seeds `settings.version` from this file; the admin UI and diagnostics read it via `Version::get()`. Bump this file when cutting a release.
