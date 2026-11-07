#!/usr/bin/env bash
# Zero-downtime deploy: builds a new release next to the live one, migrates, then switches the
# "current" symlink. Run on the server as the deploy user:
#   APP_DIR=/var/www/hotel-booking REPO=git@github.com:you/hotel_booking.git deploy/deploy.sh [branch]
#
# Layout:  $APP_DIR/releases/<timestamp>   each build
#          $APP_DIR/shared/.env            the production settings (from .env.production.example)
#          $APP_DIR/shared/storage         uploads, logs, sessions: kept across releases
#          $APP_DIR/shared/puppeteer       Chrome for PDFs, downloaded once per Puppeteer version
#          $APP_DIR/current -> releases/…  what nginx, the worker and cron run
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/hotel-booking}"
REPO="${REPO:?Set REPO to the git URL}"
BRANCH="${1:-master}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP="${PHP:-php}"
RELEASE="$APP_DIR/releases/$(date -u +%Y%m%d%H%M%S)"

echo "Deploying $BRANCH into $RELEASE"
mkdir -p "$APP_DIR/releases" "$APP_DIR/shared/storage"
test -f "$APP_DIR/shared/.env" || { echo "Missing $APP_DIR/shared/.env (copy .env.production.example and fill it in)"; exit 1; }

git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE"
cd "$RELEASE"

# Shared settings and storage.
ln -sfn "$APP_DIR/shared/.env" .env
if [ ! -d "$APP_DIR/shared/storage/app" ]; then cp -r storage/. "$APP_DIR/shared/storage/"; fi
rm -rf storage
ln -sfn "$APP_DIR/shared/storage" storage

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci --no-audit --no-fund
npm run build
# Keep the runtime packages: PDFs (invoices, prints, reports) are made by Puppeteer at runtime.
npm prune --omit=dev --no-audit --no-fund

# Chrome for Puppeteer, in a shared folder so each release doesn't download it again. .npmrc has
# ignore-scripts=true, so Puppeteer's own install step never runs; this installs the build that
# matches the installed Puppeteer, and the app is pointed at it.
PUPPETEER_CACHE_DIR="$APP_DIR/shared/puppeteer"
mkdir -p "$PUPPETEER_CACHE_DIR"
CHROME_PATH="$(PUPPETEER_CACHE_DIR="$PUPPETEER_CACHE_DIR" npx --no-install puppeteer browsers install chrome-headless-shell --format '{{path}}' | tail -n 1)"
test -x "$CHROME_PATH" || { echo "Could not install chrome-headless-shell for Puppeteer"; exit 1; }
if grep -q '^LARAVEL_PDF_CHROME_PATH=' "$APP_DIR/shared/.env"; then
    sed -i "s|^LARAVEL_PDF_CHROME_PATH=.*|LARAVEL_PDF_CHROME_PATH=\"$CHROME_PATH\"|" "$APP_DIR/shared/.env"
else
    printf '\nLARAVEL_PDF_CHROME_PATH="%s"\n' "$CHROME_PATH" >> "$APP_DIR/shared/.env"
fi
echo "Chrome for PDFs: $CHROME_PATH"

# PDFs must work before anything changes: a failure stops the deploy here, before migrations,
# and the old release keeps serving.
bash deploy/sanity-check.sh "$RELEASE"

# Database first: the old release keeps serving while migrations run (keep them additive).
$PHP artisan migrate --force --no-interaction
$PHP artisan storage:link --force --no-interaction
# Sentry tags errors with the commit they happened in.
RELEASE_SHA="$(git rev-parse --short HEAD)"
if grep -q '^SENTRY_RELEASE=' "$APP_DIR/shared/.env"; then
    sed -i "s/^SENTRY_RELEASE=.*/SENTRY_RELEASE=$RELEASE_SHA/" "$APP_DIR/shared/.env"
fi

$PHP artisan optimize --no-interaction

# Go live.
ln -sfn "$RELEASE" "$APP_DIR/current.tmp"
mv -Tf "$APP_DIR/current.tmp" "$APP_DIR/current"

# Workers pick up the new code once their current job finishes; PHP-FPM drops its opcache.
$PHP artisan queue:restart --no-interaction
sudo systemctl reload php8.4-fpm || true

# Old releases.
ls -1dt "$APP_DIR"/releases/* | tail -n +"$((KEEP_RELEASES + 1))" | xargs -r rm -rf

echo "Live: $RELEASE"
curl -fsS "$(grep -E '^APP_URL=' "$APP_DIR/shared/.env" | cut -d= -f2- | tr -d '"')/health" && echo || echo "Warning: /health is not answering 200"
