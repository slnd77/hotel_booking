# Vasuttam PMS

A property management system for hotels, built with Laravel 13, Inertia v3 and Vue 3: front desk,
reservations, folios and accounting, POS outlets, a channel manager and a commission-free booking
engine in one app.

**Live demo:** <https://pmsdemo.vasuttam.com>
**Guest booking engine (demo hotel):** <https://pmsdemo.vasuttam.com/properties/01K9V3DEM0H0TE1PMS00000000/booking-engine/web>

| Demo login |                     |
| ---------- | ------------------- |
| Email      | `demo@vasuttam.com` |
| Password   | `Demo@1234`         |

The demo is one hotel (Harbour View Hotel, 12 rooms) with rooms bookable every night for the next
six months. It is wiped and seeded again every 24 hours, so feel free to try anything. Payments on
the demo use a test gateway: no real money moves.

---

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Quick start (local)](#quick-start-local)
- [Run with Docker](#run-with-docker)
- [Environment variables](#environment-variables)
- [Production](#production)
- [Tests](#tests)
- [License](#license)

## Features

- **Front desk:** tape chart (smart booking chart), arrivals, in-house and departures, room moves,
  upgrades, overstays, early check-in and late check-out, room blocks, web check-in with ID scan.
- **Reservations:** walk-ins, group and company bookings, holds and quotes, a waitlist that offers
  rooms back when they free up, cancellation rules, add-on services.
- **Rates:** rate plans by meal plan and occupancy, day-wise rates, packages, stay restrictions,
  occupancy pricing, promo codes and deals.
- **Billing and accounting:** guest folios, split payments, folio transfers, city ledger, GST
  invoices and credit notes, payment links (Razorpay, Cashfree), cashier shifts, night audit and a
  general ledger with revenue recognition.
- **POS:** restaurant and bar outlets, tables, kitchen order tickets (KOT), bills posted to rooms,
  thermal printing (ESC/POS, QZ Tray).
- **Booking engine:** the hotel's own commission-free website booking with taxes, promo codes and
  online payment.
- **Channel manager:** availability, rates and OTA bookings through [Channex](https://channex.io).
- **Reports:** occupancy, daily transactions, booking lists, exports to Excel and PDF, scheduled
  e-mail reports.
- **Security:** roles and fine-grained permissions, two-factor authentication (authenticator app, e-mail, SMS, WhatsApp),
  passkeys, Sign in with Google, an audit log, data retention and guest data export.
- **REST API** for the staff app (`/api/v1`, see `public/openapi.yaml`), with scoped tokens.
- **Operations:** health checks, scheduler and queue monitoring, nightly backups to S3-compatible
  storage, Sentry.

## Requirements

- PHP 8.4 with `intl`, `gd`, `zip`, `bcmath`, `exif`, `pdo_mysql` (or `pdo_sqlite`)
- Composer 2
- Node.js 22+ (front-end build, and PDFs at runtime)
- MySQL 8 / MariaDB 10.6+ (SQLite works for local development)
- Chrome or Chromium for PDFs (invoices, prints, reports)

## Quick start (local)

```bash
git clone https://github.com/slnd77/hotel_booking.git
cd hotel_booking
composer setup          # composer install, .env, key, migrations, npm install, npm run build
composer dev            # app at http://localhost:8000, queue worker and Vite
```

`composer setup` uses SQLite by default (`DB_CONNECTION=sqlite` in `.env.example`). For PDFs, point
`LARAVEL_PDF_CHROME_PATH` at Chrome and check it with `php artisan ops:pdf-check`.

## Run with Docker

The image runs the web server (Apache + PHP 8.4), and the same image runs the scheduler and the
queue worker. Chromium is included for PDFs.

```bash
cp .env.example .env
# Set an APP_KEY: docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
docker compose up -d --build
```

The app is on <http://localhost:8080> (`APP_PORT`). `docker-compose.yml` starts MySQL 8.4 and
sets the database, queue, cache and session drivers.

## Environment variables

Everything Laravel itself reads (`APP_*`, `DB_*`, `MAIL_*`, `QUEUE_CONNECTION`, …) works as usual;
see `.env.example` and `.env.production.example`. These are the variables this app adds.

### Payments

| Variable                      | Default                | What it does                                                 |
| ----------------------------- | ---------------------- | ------------------------------------------------------------ |
| `RAZORPAY_KEY_ID`             | `rzp_test_placeholder` | Razorpay key for guest payments and payment links.           |
| `RAZORPAY_KEY_SECRET`         |                        | Razorpay secret.                                             |
| `CASHFREE_APP_ID`             | `cf_test_placeholder`  | Cashfree app ID.                                             |
| `CASHFREE_SECRET_KEY`         |                        | Cashfree secret key.                                         |
| `CASHFREE_MODE`               | `sandbox`              | `sandbox` or `production`.                                   |
| `ENABLE_TEST_PAYMENT_GATEWAY` | `false`                | A fake gateway that always succeeds (demo and testing only). |

### Booking engine and channel manager

| Variable                             | Default                             | What it does                                                |
| ------------------------------------ | ----------------------------------- | ----------------------------------------------------------- |
| `BOOKING_ENGINE_QUOTE_PER_MINUTE`    | `60`                                | Rate limit for price quotes per visitor.                    |
| `BOOKING_ENGINE_CHECKOUT_PER_MINUTE` | `15`                                | Rate limit for checkouts per visitor.                       |
| `CHANNEX_API_KEY`                    |                                     | Channex API key (channel manager).                          |
| `CHANNEX_BASE_URL`                   | `https://staging.channex.io/api/v1` | Channex API; `https://app.channex.io/api/v1` in production. |
| `CHANNEX_APP_URL`                    | `https://staging.channex.io`        | Channex web app (for links).                                |

### Sign-in

| Variable                 | Default | What it does                                                              |
| ------------------------ | ------- | ------------------------------------------------------------------------- |
| `GOOGLE_CLIENT_ID`       |         | Sign in with Google (OAuth client). Off when empty.                       |
| `GOOGLE_CLIENT_SECRET`   |         | Google OAuth client secret.                                               |
| `GOOGLE_REDIRECT_URI`    |         | Callback URL, e.g. `https://pms.example.com/auth/google/callback`.        |
| `PASSKEYS_EXTRA_ORIGINS` |         | Extra origins allowed for passkeys (comma-separated), e.g. the staff app. |

### Messaging

| Variable               | Default | What it does                         |
| ---------------------- | ------- | ------------------------------------ |
| `TEXT_MESSAGES_DRIVER` | `log`   | SMS and WhatsApp: `log` or `twilio`. |
| `TWILIO_SID`           |         | Twilio account SID.                  |
| `TWILIO_AUTH_TOKEN`    |         | Twilio auth token.                   |
| `TWILIO_SMS_FROM`      |         | Sender number for SMS.               |
| `TWILIO_WHATSAPP_FROM` |         | Sender number for WhatsApp.          |

### PDFs and printing

| Variable                         | Default | What it does                                                                               |
| -------------------------------- | ------- | ------------------------------------------------------------------------------------------ |
| `LARAVEL_PDF_CHROME_PATH`        |         | Chrome or `chrome-headless-shell` binary for PDFs. Check with `php artisan ops:pdf-check`. |
| `LARAVEL_PDF_NO_SANDBOX`         | `false` | Start Chrome without its sandbox (needed in Docker and on some Ubuntu servers).            |
| `QZ_TRAY_CERTIFICATE`            |         | QZ Tray certificate for silent receipt printing.                                           |
| `QZ_TRAY_PRIVATE_KEY`            |         | QZ Tray signing key.                                                                       |
| `QZ_TRAY_PRIVATE_KEY_PASSPHRASE` |         | Its passphrase.                                                                            |

### Guest ID scanning

"Scan ID" reads a guest's ID photo on the server first: an Aadhaar's Secure QR code (with
`zbarimg`, package `zbar-tools`) and a passport's machine-readable zone, whose check digits must
all match (with `tesseract`, package `tesseract-ocr`). Other IDs (voter ID, driving licence, PAN)
are read by Claude when an API key is set. The photo is never stored.

| Variable                 | Default                     | What it does                                                                |
| ------------------------ | --------------------------- | --------------------------------------------------------------------------- |
| `ID_SCAN_ZBARIMG_PATH`   | `zbarimg`                   | The QR code reader. Skipped when it isn't installed.                        |
| `ID_SCAN_TESSERACT_PATH` | `tesseract`                 | The text reader for passport MRZs. Skipped when it isn't installed.         |
| `ID_SCAN_TIMEOUT`        | `20`                        | Seconds each reader may take.                                               |
| `ANTHROPIC_API_KEY`      |                             | Reads other ID documents (and unreadable ones) with Claude. Off when empty. |
| `ANTHROPIC_MODEL`        | `claude-opus-5-5`           | The Claude model.                                                           |
| `ANTHROPIC_BASE_URL`     | `https://api.anthropic.com` | API base URL.                                                               |

### Operations, backups and monitoring

| Variable                             | Default                       | What it does                                                    |
| ------------------------------------ | ----------------------------- | --------------------------------------------------------------- |
| `TRUSTED_PROXIES`                    |                               | Behind a load balancer: `*` or its addresses (comma-separated). |
| `HEALTH_CHECK_TOKEN`                 |                               | Token for the health check endpoint.                            |
| `OPS_ALERT_EMAIL`                    |                               | Where operations alerts go.                                     |
| `OPS_QUEUE_WAIT_MINUTES`             | `10`                          | Alert when a queued job waits longer.                           |
| `OPS_SCHEDULER_STALE_MINUTES`        | `15`                          | Alert when the scheduler stops running.                         |
| `OPS_BACKUP_STALE_HOURS`             | `26`                          | Alert when the last backup is older.                            |
| `OPS_DISK_FREE_PERCENT`              | `10`                          | Alert below this free disk space.                               |
| `BACKUP_DISK`                        | `local`                       | Disk for nightly backups (`backups` for S3-compatible storage). |
| `BACKUP_KEEP_DAYS`                   | `14`                          | Days of backups to keep.                                        |
| `BACKUP_INCLUDE_FILES`               | `true`                        | Include uploaded files in backups.                              |
| `BACKUP_DUMP_BINARY_PATH`            |                               | Folder of `mysqldump` / `pg_dump` when not on the PATH.         |
| `BACKUP_AWS_ACCESS_KEY_ID`           | `AWS_ACCESS_KEY_ID`           | Backup bucket credentials (fall back to the `AWS_*` values).    |
| `BACKUP_AWS_SECRET_ACCESS_KEY`       | `AWS_SECRET_ACCESS_KEY`       |                                                                 |
| `BACKUP_AWS_DEFAULT_REGION`          | `AWS_DEFAULT_REGION`          |                                                                 |
| `BACKUP_AWS_BUCKET`                  | `AWS_BUCKET`                  |                                                                 |
| `BACKUP_AWS_ENDPOINT`                | `AWS_ENDPOINT`                | S3-compatible endpoint (R2, Wasabi, Spaces, …).                 |
| `BACKUP_AWS_USE_PATH_STYLE_ENDPOINT` | `AWS_USE_PATH_STYLE_ENDPOINT` |                                                                 |
| `CACHE_STORAGE_DISK`                 |                               | Disk for the file cache store.                                  |
| `CACHE_STORAGE_PATH`                 | `framework/cache/data`        | Path of the file cache store.                                   |
| `DB_ENCRYPT`                         | `yes`                         | SQL Server: encrypt the connection.                             |
| `DB_TRUST_SERVER_CERTIFICATE`        | `false`                       | SQL Server: trust its certificate.                              |

### Docker

| Variable           | Default      | What it does                                      |
| ------------------ | ------------ | ------------------------------------------------- |
| `APP_PORT`         | `8080`       | Host port for the web container.                  |
| `RUN_MIGRATIONS`   | `true` (web) | Migrate on start (set only on the web container). |
| `DB_ROOT_PASSWORD` | `root`       | MySQL root password for the bundled database.     |

<details>
<summary><strong>Sentry (error tracking and performance)</strong></summary>

Set `SENTRY_LARAVEL_DSN` (or `SENTRY_DSN`) to turn it on. All of these come from the Sentry SDK's
`config/sentry.php` and keep its defaults unless set:

`SENTRY_LARAVEL_DSN`, `SENTRY_SPOTLIGHT`, `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT`, `SENTRY_ORG_ID`,
`SENTRY_SAMPLE_RATE`, `SENTRY_TRACES_SAMPLE_RATE`, `SENTRY_PROFILES_SAMPLE_RATE`,
`SENTRY_STRICT_TRACE_CONTINUATION`, `SENTRY_ENABLE_LOGS`, `SENTRY_ENABLE_METRICS`,
`SENTRY_LOG_FLUSH_THRESHOLD`, `SENTRY_LOG_LEVEL`, `SENTRY_SEND_DEFAULT_PII`,
`SENTRY_BREADCRUMBS_LOGS_ENABLED`, `SENTRY_BREADCRUMBS_CACHE_ENABLED`,
`SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED`, `SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED`,
`SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED`, `SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED`,
`SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED`, `SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED`,
`SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED`, `SENTRY_TRACE_QUEUE_ENABLED`,
`SENTRY_TRACE_QUEUE_JOBS_ENABLED`, `SENTRY_TRACE_SQL_QUERIES_ENABLED`,
`SENTRY_TRACE_SQL_BINDINGS_ENABLED`, `SENTRY_TRACE_SQL_ORIGIN_ENABLED`,
`SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS`, `SENTRY_TRACE_VIEWS_ENABLED`,
`SENTRY_TRACE_LIVEWIRE_ENABLED`, `SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED`,
`SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_ENABLED`,
`SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_THRESHOLD_MS`, `SENTRY_TRACE_CACHE_ENABLED`,
`SENTRY_TRACE_REDIS_COMMANDS`, `SENTRY_TRACE_REDIS_ORIGIN_ENABLED`,
`SENTRY_TRACE_NOTIFICATIONS_ENABLED`, `SENTRY_TRACE_MISSING_ROUTES_ENABLED`,
`SENTRY_TRACE_CONTINUE_AFTER_RESPONSE`, `SENTRY_TRACE_GEN_AI_ENABLED`,
`SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED`, `SENTRY_TRACE_GEN_AI_CHAT_ENABLED`,
`SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED`, `SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED`,
`SENTRY_TRACE_GEN_AI_EVALUATE_ENABLED`, `SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED`.

</details>

## Production

`deploy/` has a zero-downtime deploy script, Nginx, Supervisor and cron configuration, and a
checklist (`deploy/README.md`). `.env.production.example` is a starting point for production
settings. The scheduler must run every minute (`php artisan schedule:run`) and a queue worker must
be running.

## Tests

```bash
php artisan test --compact               # unit and feature tests
php artisan test --testsuite=Browser     # browser tests (Pest + Playwright: npx playwright install chromium)
```

## License

Vasuttam PMS is **source-available** under the [Business Source License 1.1](LICENSE):

- Hotels, hostels and other lodging businesses may use, modify and self-host it in production
  **for properties they own or operate**, free of charge.
- **Agencies, IT service providers and resellers** need a commercial license before installing,
  hosting, managing, customising, supporting or reselling it for clients, or offering it as a
  hosted service.
- Each version becomes available under the Apache License 2.0 four years after it is published.

For a commercial license, write to <admin@vasuttam.com>.

"Vasuttam" and its logo are trademarks of Vasuttam Labs and are not covered by the license.
