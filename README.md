# VoxelBooking

Self-hosted booking infrastructure for small businesses. Four booking patterns — timeslots, resources, capacity, events — in one installation. No SaaS fees, no vendor lock-in, no outbound connections.

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

### What's in the ZIP

The distribution ZIP is self-contained. No build tools are required on your server.

| Included | Notes |
|----------|-------|
| PHP source + framework | Custom micro-framework — no Laravel or Symfony |
| `vendor/` | All Composer dependencies, pre-installed |
| `public/assets/` | Pre-built CSS and JS — no Node.js required |
| Migration files | Run automatically by the installer |
| English translations | 12 additional locales are registry-ready |
| Demo SQLite database | For demo mode (optional) |

You do not need Composer, Node.js, or npm on your production server.

### Server requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.3+ |
| MySQL | 8.0+ |
| Web server | Apache with `mod_rewrite`, or Nginx |

**Required PHP extensions:** `pdo` `pdo_mysql` `curl` `json` `mbstring` `fileinfo` `openssl` `gd` `zip`

Most shared hosting providers include all of these by default. The installer's system check step confirms every requirement before proceeding.

---

## Installation

### Shared hosting (Apache, cPanel, Plesk)

1. **Upload and extract** the ZIP into your web root — typically `public_html/` — not into a subdirectory inside it.

2. **Create a MySQL database** in your hosting control panel. Note the database name, username, and password — the installer will ask for them.

3. **Navigate to your domain** in a browser. The root `.htaccess` rewrites all traffic into `public/` and blocks direct access to all application directories. You should see the installer immediately.

4. **Complete the five-step wizard:**

   | Step | What happens |
   |------|-------------|
   | System check | Verifies PHP version, required extensions, and directory write permissions |
   | Database | Connects to MySQL, runs all migrations, and seeds initial settings |
   | Email | Configures SMTP transport for booking confirmations and admin notifications |
   | Operator account | Creates the super-admin login for your installation |
   | First business | Sets up your first booking page — pattern, name, slug, timezone |

   The installer creates and writes your `.env` configuration file automatically. No manual file editing is required.

5. **After the wizard**, you are redirected to the admin panel. Your first booking page is live at `yourdomain.com/book/your-business-slug`.

> The installer disables itself after successful completion. Navigating to the install URL again after setup will redirect to the admin panel.

---

### VPS / dedicated server — Apache

Point Apache's `DocumentRoot` directly to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/voxelbooking/public

    <Directory /var/www/voxelbooking/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable the site, reload Apache, then navigate to your domain and complete the wizard.

---

### VPS / dedicated server — Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/voxelbooking/public;
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

Adjust the `fastcgi_pass` socket path to match your PHP-FPM version (e.g., `php8.3-fpm.sock`). Reload Nginx, then navigate to your domain and complete the wizard.

> **HTTPS:** For production servers, obtain a TLS certificate via [Let's Encrypt / Certbot](https://certbot.eff.org/) and update the server block to listen on port 443. Certbot can modify the Nginx config automatically with `certbot --nginx`.

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

### Retention cron (recommended)

For automated GDPR data retention — anonymizing customer PII after a configurable number of months — schedule this URL to run once daily:

```
0 3 * * * curl -s "https://yourdomain.com/cron/run?token=YOUR_CRON_TOKEN" > /dev/null
```

Your cron token is displayed in Admin → Settings → Advanced. Without this cron, all other features work normally — only automated retention is inactive.

---

## Upgrading

1. **Download** the new version ZIP from your purchase account.
2. **Back up** your database and the `public/uploads/` directory before doing anything else.
3. **Replace all files** from the new ZIP, with two exceptions:
   - **`.env`** — your environment configuration. Never overwrite this.
   - **`public/uploads/`** — your uploaded logos and cover images.
4. **Run any migrations** described in the release upgrade notes. Each release ships an `UPGRADE.md` with the exact steps required for that version — read it before replacing files.

---

## Demo mode

Demo mode switches the database to a pre-seeded SQLite file and blocks all writes, letting you explore the full admin and booking UI without affecting any real data.

### Activate

```bash
touch .demo
```

When active:
- The database switches to `storage/demo/demo.db`
- All write operations (POST, PUT, DELETE) are blocked, except login and logout
- A persistent "Demo Mode" banner appears in the admin UI
- The booking page shows a notice that submissions are disabled
- The login page shows clickable credential cards for all demo accounts

### Demo accounts

All accounts use the password `welcome3210`.

| Role | Email | Sees |
|------|-------|------|
| Operator | `demo@voxelbooking.com` | All 4 businesses and system settings |
| Demo Studio | `owner@demo-studio.test` | Timeslot pattern — Services, Staff, Availability |
| Hotel Marina | `owner@hotel-marina.test` | Resource pattern — Resources |
| Trattoria Roma | `owner@trattoria-roma.test` | Capacity pattern — Capacity Slots |
| Workshop Studio | `owner@workshop-studio.test` | Event pattern — Events, Waitlist |

The operator sees all four businesses on the dashboard. Each business owner is auto-redirected to their own business and sees only the menus relevant to their booking pattern.

### Deactivate

```bash
rm .demo
```

The next request reconnects to your MySQL database.

---

## Troubleshooting

**Blank page or HTTP 500 after upload**
Enable PHP error output temporarily (add `php_flag display_errors on` to `.htaccess` or set in `php.ini`), reload, read the error, then remove it. The most common causes are a missing PHP extension or incorrect file permissions on `storage/`.

**The installer does not appear — I see a default server page**
The root `.htaccess` is not being applied. Confirm `mod_rewrite` is enabled (`a2enmod rewrite` on Ubuntu/Debian) and that `AllowOverride All` is set for the document root directory in your Apache config.

**"Access denied" on the database step**
The MySQL user lacks sufficient privileges. The installation user needs `CREATE`, `ALTER`, `INSERT`, `SELECT`, `UPDATE`, `DELETE`, and `DROP` on the target database. Grant full privileges for installation; you can restrict them afterwards if your security policy requires it.

**Pages load but all internal links return 404**
`mod_rewrite` is enabled but rules are not being applied. Set `AllowOverride All` in your virtual host or `httpd.conf`. On Nginx, confirm the `try_files $uri $uri/ /index.php` rule is in place and Nginx has been reloaded.

**Booking page loads but the booking widget is blank**
The `public/assets/` directory is missing or empty. Re-upload from the ZIP — the pre-built assets must be present at `public/assets/booking.js` and `public/assets/booking.css`.

**Emails are not being delivered**
Go to Admin → Settings → Email → Send Test Email. The result tells you whether the SMTP connection succeeds. Port 587 with STARTTLS is the most widely supported configuration. Check that your SMTP host, port, encryption type, username, and password are all correct. If your host blocks outbound SMTP on port 25, switch to 587 or 465.

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
| Booking page | Business override → browser `Accept-Language` (if translation exists) → business default → `en` | Business timezone for storage; browser timezone for display |
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

### Zero outbound connections by default

VoxelBooking makes no outbound connections unless you configure SMTP. No telemetry, no analytics beacons, no update checks, no CDN dependencies. Every font, icon, and script is bundled.

### What it stores

- **One session cookie** (`vb_session`) — strictly necessary for admin login. No analytics cookies, no marketing cookies, no fingerprinting.
- **One localStorage entry** (`vb-theme`) — dark/light mode preference stored in the browser.

### GDPR controls

| Control | What it does |
|---------|-------------|
| Consent capture | Records the exact consent text shown to the customer and a `consent_given_at` timestamp at booking time. Changing the consent text later does not retroactively alter recorded consents. |
| Customer anonymization | Name → "Deleted", email → SHA-256 hash, phone and notes → NULL. Booking structure and consent evidence are preserved. |
| Data export | JSON export of all customer personal data, booking history, and consent records for GDPR Art. 20 portability requests. |
| Self-service privacy page | Customers access their data at `/book/{slug}/privacy/{customer-ulid}`. They can export or request deletion without contacting the business. |
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

> Everything below this line is for developers extending, modifying, or contributing to VoxelBooking. If you purchased VoxelBooking and want to install it on a production server, everything you need is above.

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

`storage/demo/demo.db` is committed to the repository and included in the distribution ZIP. To activate demo mode locally:

```bash
touch .demo                # Activates demo mode — remove with `rm .demo`
```

To regenerate the demo database after schema changes:

```bash
php demo-seed.php          # Rebuilds storage/demo/demo.db
```

> **Schema parity:** `demo-seed.php` creates SQLite tables that must mirror every MySQL migration in `app/Migrations/`. When you add or alter a migration, update `demo-seed.php` to match, bump the `db_version` setting, regenerate, and commit the updated `demo.db`. The build script also regenerates during packaging as a safety net.

### MySQL showcase seed

To populate a local MySQL database with all four demo businesses and the full dataset:

```bash
php demo-seed-mysql.php    # DESTRUCTIVE — drops and recreates all tables
```

This seeds the same four-business dataset as demo mode but against your live MySQL database, enabling the full write path for testing.

### Building assets

```bash
npm run build    # production — compiles CSS and JS to public/assets/
npm run dev      # dev server with hot module replacement
```

Before committing any CSS changes, run the token audit to confirm no hardcoded values have been introduced:

```bash
python3 scripts/audit-css-tokens.py
```

### Testing

```bash
# Full test suite
vendor/bin/phpunit --testdox

# Unit tests only
vendor/bin/phpunit --testsuite Unit

# Integration tests only
vendor/bin/phpunit --testsuite Integration

# Specific pattern tests
vendor/bin/phpunit --filter EventBookingFlowTest
vendor/bin/phpunit --filter CapacityBookingFlowTest
```

Integration tests run at the HTTP level against actual booking flows. Unit tests cover the calculators, models, and engine classes in isolation.

---

## Version

The canonical version lives in the root [`VERSION`](VERSION) file. Do not hardcode version strings elsewhere. The installer seeds `settings.version` from this file; the admin UI and diagnostics read it via `Version::get()`. Bump this file when cutting a release.

---

## License

Proprietary. See [LICENSE](LICENSE).
