#!/usr/bin/env bash
# Post-deploy leak probe. Hits a live deployment with curl and asserts that
# sensitive paths return 403/404 (or otherwise don't serve content), and that
# the user-facing surface (/ and /healthz) returns 200.
#
# WAF-friendly by design: shared-host firewalls (Hetzner / cPanel) fingerprint
# scanners by burst rate, fixed cadence, request order and the curl UA — and
# ban the source IP. So every request here is spaced by a base delay plus
# random jitter, the probe order is shuffled per run, and each request carries
# a rotating real-browser User-Agent. Expect a full run to take ~2–3 minutes.
#
# Usage:
#   bin/probe_security.sh https://pitwall.your-domain.tld
#   PROBE_URL=https://... bin/probe_security.sh
#
# Tunables (seconds):
#   PROBE_DELAY   base pause before every request   (default 3)
#   PROBE_JITTER  extra random pause, 0..JITTER     (default 4)
#
# Exit code: 0 if every assertion passes, 1 otherwise.

set -uo pipefail

URL="${1:-${PROBE_URL:-}}"
if [[ -z "$URL" ]]; then
    echo "usage: $0 <base-url>" >&2
    exit 2
fi
URL="${URL%/}"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
RESET='\033[0m'

fails=0
checks=0

PROBE_DELAY="${PROBE_DELAY:-3}"
PROBE_JITTER="${PROBE_JITTER:-4}"

# Plausible, current desktop browser UAs. One is picked at random per request
# so the probe doesn't announce itself as curl 25 times in a row.
USER_AGENTS=(
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36"
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15"
    "Mozilla/5.0 (X11; Linux x86_64; rv:139.0) Gecko/20100101 Firefox/139.0"
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:139.0) Gecko/20100101 Firefox/139.0"
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36"
)

random_ua() {
    printf '%s' "${USER_AGENTS[RANDOM % ${#USER_AGENTS[@]}]}"
}

# Base delay + uniform random jitter. $RANDOM re-rolls per call, so no two
# gaps look alike and the cadence can't be fingerprinted.
pace() {
    local delay
    delay="$(awk -v base="$PROBE_DELAY" -v jit="$PROBE_JITTER" -v r="$RANDOM" \
        'BEGIN { printf "%.2f", base + (r / 32767) * jit }')"
    sleep "$delay"
}

# A leak check passes when the path is NOT served (status 4xx / 5xx).
# 200 OR a redirect into a login flow that returns content of the file = FAIL.
leak_must_fail() {
    local path="$1"
    local description="$2"
    checks=$((checks + 1))
    pace

    local response
    response="$(curl -sS -o /dev/null -A "$(random_ua)" \
        -w "%{http_code} %{size_download}" -L --max-redirs 2 \
        "${URL}${path}" 2>/dev/null || echo "000 0")"
    local code="${response%% *}"
    local size="${response##* }"

    if [[ "$code" =~ ^[45][0-9][0-9]$ ]]; then
        printf "  ${GREEN}PASS${RESET}  %-40s %s\n" "$path" "blocked (${code})"
        return 0
    fi

    # 200 with non-zero body = leak. 200 with an empty body shouldn't happen on
    # static assets, but flag it as suspicious — better a false positive than a miss.
    printf "  ${RED}FAIL${RESET}  %-40s %s — %s\n" "$path" "served (${code}, ${size}B)" "$description"
    fails=$((fails + 1))
}

# A surface check passes when the path serves 200.
surface_must_pass() {
    local path="$1"
    local description="$2"
    checks=$((checks + 1))
    pace

    local code
    code="$(curl -sS -o /dev/null -A "$(random_ua)" -w "%{http_code}" \
        "${URL}${path}" 2>/dev/null || echo "000")"

    if [[ "$code" == "200" ]]; then
        printf "  ${GREEN}PASS${RESET}  %-40s 200\n" "$path"
        return 0
    fi

    printf "  ${RED}FAIL${RESET}  %-40s %s — %s\n" "$path" "$code" "$description"
    fails=$((fails + 1))
}

# JSON-content check on /healthz — exists, returns 200, says status=ok.
healthz_must_be_ok() {
    checks=$((checks + 1))
    pace

    local body
    body="$(curl -sS --max-time 5 -A "$(random_ua)" "${URL}/healthz" 2>/dev/null || true)"

    if echo "$body" | grep -q '"status":"ok"'; then
        printf "  ${GREEN}PASS${RESET}  %-40s status=ok\n" "/healthz"
    else
        printf "  ${RED}FAIL${RESET}  %-40s %s\n" "/healthz" "expected status=ok, got: ${body:0:120}"
        fails=$((fails + 1))
    fi
}

# Security headers on /: ONE request, grepped four times — four identical
# HEAD requests in a row is exactly the pattern a WAF flags.
check_security_headers() {
    pace
    local headers
    headers="$(curl -sSI -A "$(random_ua)" "${URL}/" 2>/dev/null || true)"

    local header
    for header in \
        "Strict-Transport-Security" \
        "X-Content-Type-Options" \
        "X-Frame-Options" \
        "Referrer-Policy"; do
        checks=$((checks + 1))
        if grep -qi "^${header}:" <<<"$headers"; then
            printf "  ${GREEN}PASS${RESET}  %-40s present\n" "header: ${header}"
        else
            printf "  ${YELLOW}WARN${RESET}  %-40s missing (security header)\n" "header: ${header}"
            # Headers are graceful-degradation, not blockers — count as a warn
            # but don't fail the script.
        fi
    done
}

echo "▶ probing ${URL} (paced: ${PROBE_DELAY}s + 0–${PROBE_JITTER}s jitter per request)"
echo

# Sensitive paths, "path|description". The list is shuffled per run so the
# probe never walks the same sequence twice.
leak_checks=(
    "/.env|secrets — APP_SECRET, SMTP password"
    "/.env.example|placeholder file; still shouldn't be served"
    "/.deploy.env|SFTP deploy credentials"
    "/config/secrets.php|game formulas"
    "/gpro_pilots.sqlite|user data (emails + tokens, encrypted)"
    "/bootstrap.php|PHP bootstrap; outside docroot"
    "/composer.json|dep listing"
    "/composer.lock|exact dep versions"
    "/CLAUDE.md|project context (should be excluded from bundle)"
    "/TODO.md|project backlog (should be excluded from bundle)"
    "/gpro-public-api.yml|private OpenAPI spec"
    "/src/Service/AuthService.php|PHP source — outside docroot"
    "/src/|src/ directory listing"
    "/var/cache/|cache directory listing"
    "/var/log/|log directory listing"
    "/var/mail/|dev-only mail directory listing"
    "/var/|writable runtime dir"
    "/.git/HEAD|git directory accidentally SFTP'd"
    "/bin/db_browser.php|CLI-only debug tool"
    "/bin/seed_tracks.php|CLI seeder"
    "/data/tracks.csv|tracks fixture"
)

echo "Sensitive paths (must be blocked; order shuffled):"
while IFS='|' read -r path description; do
    leak_must_fail "$path" "$description"
done < <(printf '%s\n' "${leak_checks[@]}" | shuf)

# Public surface, also shuffled.
surface_checks=(
    "/|anonymous landing page"
    "/login|login form"
    "/register|register form"
    "/assets/app.css|compiled tailwind"
    "/robots.txt|crawler policy"
    "/sitemap.xml|sitemap"
)

echo
echo "Public surface (must serve 200; order shuffled):"
while IFS='|' read -r path description; do
    surface_must_pass "$path" "$description"
done < <(printf '%s\n' "${surface_checks[@]}" | shuf)
healthz_must_be_ok

echo
echo "Security headers on /:"
check_security_headers

echo
if [[ "$fails" -eq 0 ]]; then
    printf "${GREEN}✓ all %d checks passed${RESET}\n" "$checks"
    exit 0
fi

printf "${RED}✗ %d of %d checks failed${RESET}\n" "$fails" "$checks"
exit 1
