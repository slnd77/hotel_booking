#!/usr/bin/env bash
# Checks a release can make PDFs (invoices, prints, reports): Node, the Puppeteer module, the
# Chrome binary and its system libraries, then a real render through the app. deploy.sh runs it
# before going live; run it by hand any time:
#   deploy/sanity-check.sh [release dir, default: the current directory]
# Exits non-zero on the first failed requirement.
set -uo pipefail

RELEASE="${1:-$(pwd)}"
PHP="${PHP:-php}"
NODE_MIN_MAJOR=20
cd "$RELEASE" || { echo "No such release: $RELEASE"; exit 1; }

failed=0
ok()   { echo "  [ok]   $1"; }
fail() { echo "  [FAIL] $1"; failed=1; }
warn() { echo "  [warn] $1"; }

echo "PDF requirements in $RELEASE"

# Node: Browsershot drives Puppeteer through it.
if command -v node >/dev/null 2>&1; then
    node_major="$(node -p 'process.versions.node.split(".")[0]')"
    if [ "$node_major" -ge "$NODE_MIN_MAJOR" ]; then ok "node $(node -v)"; else fail "node $(node -v) is too old (need $NODE_MIN_MAJOR+)"; fi
else
    fail "node is not on the PATH (install Node $NODE_MIN_MAJOR+, e.g. from nodesource)"
fi

# The Puppeteer module, next to the app (deploy.sh keeps node_modules for it).
if node -e "require.resolve('puppeteer')" >/dev/null 2>&1; then
    ok "puppeteer $(node -p "require('puppeteer/package.json').version")"
else
    fail "the puppeteer module is missing: run npm ci (node_modules must stay in the release)"
fi

# The Chrome binary: LARAVEL_PDF_CHROME_PATH from the release's .env (deploy.sh writes it).
chrome="$(grep -E '^LARAVEL_PDF_CHROME_PATH=' .env 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"')"
if [ -z "$chrome" ]; then
    fail "LARAVEL_PDF_CHROME_PATH is not set in .env (deploy.sh sets it after installing chrome-headless-shell)"
elif [ ! -x "$chrome" ]; then
    fail "LARAVEL_PDF_CHROME_PATH is not an executable file: $chrome"
else
    ok "chrome $chrome"

    # Shared libraries Chrome needs from the OS (see deploy/README.md for the apt packages).
    if command -v ldd >/dev/null 2>&1; then
        missing="$(ldd "$chrome" 2>/dev/null | awk '/not found/ {print $1}' | sort -u | tr '\n' ' ')"
        if [ -n "$missing" ]; then fail "chrome is missing system libraries: $missing"; else ok "chrome's system libraries are installed"; fi
    fi
    if timeout 20 "$chrome" --version >/dev/null 2>&1; then
        ok "chrome starts"
    else
        warn "chrome did not answer --version; the render below is the real test"
    fi
fi

# A real render through the app, as this user...
if [ "$failed" -eq 0 ]; then
    if "$PHP" artisan ops:pdf-check --no-interaction; then ok "the app renders a PDF"; else fail "the app could not render a PDF (see above)"; fi

    # ...and as the web server's user, when we may switch to it: that is who renders in production.
    WEB_USER="${WEB_USER:-www-data}"
    if [ "$(id -un)" != "$WEB_USER" ] && id "$WEB_USER" >/dev/null 2>&1; then
        if sudo -n -u "$WEB_USER" true 2>/dev/null; then
            if sudo -n -u "$WEB_USER" "$PHP" artisan ops:pdf-check --no-interaction >/dev/null 2>&1; then
                ok "the app renders a PDF as $WEB_USER"
            else
                fail "the app could not render a PDF as $WEB_USER (run: sudo -u $WEB_USER $PHP artisan ops:pdf-check)"
            fi
        else
            warn "could not switch to $WEB_USER to check a render as it (no passwordless sudo); run: sudo -u $WEB_USER $PHP artisan ops:pdf-check"
        fi
    fi
fi

if [ "$failed" -ne 0 ]; then
    echo "PDF requirements: FAILED"
    exit 1
fi
echo "PDF requirements: all OK"
