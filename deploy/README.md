# Production runbook

How to run the PMS on a single Ubuntu server (24.04): nginx, PHP-FPM 8.4, MySQL 8, a queue
worker under Supervisor and the scheduler in cron. Every file referred to is in this folder.

## 1. Server

```bash
sudo apt install nginx mysql-server supervisor certbot python3-certbot-nginx git unzip \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-bcmath php8.4-intl php8.4-gd php8.4-sqlite3
# Composer 2 and Node 22 (asset build, and PDFs at runtime), e.g. from nodesource.

# What Chrome needs to make PDFs (invoices, prints, reports), and fonts for Indian scripts.
sudo apt install libnss3 libnspr4 libatk1.0-0t64 libatk-bridge2.0-0t64 libcups2t64 libdrm2 \
  libdbus-1-3 libxkbcommon0 libxcomposite1 libxdamage1 libxfixes3 libxrandr2 libgbm1 \
  libpango-1.0-0 libcairo2 libasound2t64 libexpat1 fonts-liberation fonts-noto-core fonts-noto-color-emoji
```

PDFs are made by Puppeteer with Chrome (`chrome-headless-shell`). `deploy.sh` installs the Chrome
build that matches Puppeteer into `shared/puppeteer`, writes its path to `LARAVEL_PDF_CHROME_PATH`
in `shared/.env`, and runs `deploy/sanity-check.sh`. It stops before migrating if a PDF can't be made.
Run the check by hand any time:

```bash
cd /var/www/hotel-booking/current && bash deploy/sanity-check.sh
sudo -u www-data php artisan ops:pdf-check     # a render as the web server's user
```

It names what is missing: Node, the Puppeteer module, the Chrome binary or a system library. If
Chrome can't start its sandbox (Ubuntu 24.04 limits it with AppArmor), set `LARAVEL_PDF_NO_SANDBOX=true`.

`mysqldump` (from mysql-client) must be on the PATH for backups, or set `BACKUP_DUMP_BINARY_PATH`.

Create the database and a user with rights on it only:

```sql
CREATE DATABASE hotel_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hotel_booking'@'localhost' IDENTIFIED BY '…';
GRANT ALL ON hotel_booking.* TO 'hotel_booking'@'localhost';
```

## 2. First deploy

```bash
sudo mkdir -p /var/www/hotel-booking/shared && sudo chown -R deploy:www-data /var/www/hotel-booking
cp .env.production.example /var/www/hotel-booking/shared/.env   # fill in every CHANGE value
APP_DIR=/var/www/hotel-booking REPO=git@github.com:you/hotel_booking.git deploy/deploy.sh master
cd /var/www/hotel-booking/current
php artisan key:generate --force                          # once; the key then stays in shared/.env
php artisan db:seed --class=ProductionSeeder --force      # reference data and roles, no demo hotels
php artisan admin:create you@example.com                  # the first admin
```

Moving an existing database over instead: restore it (section 4), then run
`php artisan ledger:journal-backfill` once.

Then:

| What           | How                                                                                                            |
| -------------- | -------------------------------------------------------------------------------------------------------------- |
| HTTPS          | `deploy/nginx.conf` → `/etc/nginx/sites-available/`, enable it, `sudo certbot --nginx -d your.domain`          |
| Queue worker   | `deploy/supervisor.conf` → `/etc/supervisor/conf.d/`, `sudo supervisorctl reread && sudo supervisorctl update` |
| Scheduler      | `sudo crontab -u www-data deploy/crontab`                                                                      |
| Storage rights | `sudo chown -R www-data:www-data /var/www/hotel-booking/shared/storage`                                        |

Later deploys: run `deploy/deploy.sh` again. It builds a new release, migrates, switches the
`current` symlink, restarts the workers and checks `/health`.

## 3. E-mail

Set `MAIL_MAILER=smtp` and the `MAIL_*` values (SES, Postmark, SendGrid, Zoho…). Use a
`MAIL_FROM_ADDRESS` on a domain with SPF and DKIM set up, or guests' mail providers will
junk it. Check it:

```bash
php artisan ops:mail-test you@example.com
```

Guest e-mails and scheduled reports are queued, so they only go out while the worker runs.

## 4. Backups

`ops:backup` runs every night at 01:30: a gzipped database dump and a zip of the uploaded
files (ID documents, attachments, logos) under `backups/` on `BACKUP_DISK`, keeping
`BACKUP_KEEP_DAYS` days.

They go to the off-server `backups` disk (`BACKUP_DISK=backups`): a private S3-compatible bucket
(AWS S3, Wasabi, Cloudflare R2, Backblaze B2). Fill in the `BACKUP_AWS_*` settings; for a
non-AWS store also `BACKUP_AWS_ENDPOINT` and `BACKUP_AWS_USE_PATH_STYLE_ENDPOINT=true`. Give the
key rights on that bucket only, and turn on versioning so a bad backup can't overwrite a good one.
Check it with `php artisan ops:backup` and look for the files in the bucket.

A second database connection is backed up with `php artisan ops:backup --connection=<its connection>`.

Restore:

```bash
php artisan down
gunzip -c 2026-10-13-013000-mysql.sql.gz | mysql -u hotel_booking -p hotel_booking
unzip -o 2026-10-13-013000-files.zip -d /var/www/hotel-booking/shared/storage/app/private
php artisan optimize:clear && php artisan up
```

Test a restore into a scratch database once a month.

## 5. Monitoring and errors

- **Uptime**: point an uptime monitor (UptimeRobot, Better Stack…) at `https://your.domain/health`.
  It answers 503 when the database, cache, queue worker or scheduler is down. Add the header
  `X-Health-Token: <HEALTH_CHECK_TOKEN>` to see each check.
- **Alerts**: `ops:monitor` runs every five minutes. When something fails (worker stuck, scheduler
  stopped, failed jobs, no recent backup, disk nearly full) it logs a critical error and e-mails
  `OPS_ALERT_EMAIL`, at most once an hour per problem.
- **Errors**: every unhandled exception goes to Sentry once `SENTRY_LARAVEL_DSN` is set: grouped,
  with stack traces, the URL and the release (`SENTRY_RELEASE`, deploy.sh sets it to the git
  commit). Guests' personal data isn't sent. Check it with `php artisan sentry:test`.
  `LOG_STACK=daily,slack` also keeps 30 days of logs in `storage/logs` and posts errors to Slack.

## 6. Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` is the https address
- [ ] `/health` answers 200 and the uptime monitor is set up
- [ ] `ops:mail-test` reaches an inbox (not spam)
- [ ] `deploy/sanity-check.sh` is all OK and `sudo -u www-data php artisan ops:pdf-check` passes
- [ ] `ops:backup` succeeded, the files are in the backup bucket, and a restore was tried
- [ ] `php artisan sentry:test` shows up in Sentry
- [ ] Live Razorpay / Cashfree keys, and the test gateway is **not** enabled
- [ ] Channex production key and URL; webhook URL registered in Channex
- [ ] Every property: Settings → Privacy & Terms filled in (grievance officer e-mail) and its
      GST invoice check is all OK
- [ ] The seeded admin's password changed, and two-factor sign-in on for admins
- [ ] `php artisan ledger:journal-backfill` shows a zero difference for every property
