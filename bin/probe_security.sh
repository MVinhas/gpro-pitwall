#!/usr/bin/env bash
# Post-deploy leak probe. Hits a live deployment with curl and asserts that
# sensitive paths return 403/404 (or otherwise don't serve content), and that
# the user-facing surface (/ and /healthz) returns 200.
#
# Usage:
#   bin/probe_security.sh https://pitwall.your-domain.tld
#   PROBE_URL=https://... bin/probe_security.sh
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

# Pause between probes so shared-host WAFs (Hetzner / cPanel / etc.) don't
# fingerprint the burst as a scanner and ban the running IP. ~400ms × ~25
# checks = a 10-second probe instead of a 2-second one.
PROBE_DELAY="${PROBE_DELAY:-0.4}"

# A leak check passes when the path is NOT served (status 4xx / 5xx).
# 200 OR a redirect into a login flow that returns content of the file = FAIL.
leak_must_fail() {
    local path="$1"
    local description="$2"
    checks=$((checks + 1))
    sleep "$PROBE_DELAY"

    local response
    response="$(curl -sS -o /dev/null -w "%{http_code} %{size_download}" -L --max-redirs 2 "${URL}${path}" 2>/dev/null || echo "000 0")"
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
    sleep "$PROBE_DELAY"

    local code
    code="$(curl -sS -o /dev/null -w "%{http_code}" "${URL}${path}" 2>/dev/null || echo "000")"

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
    local body
    body="$(curl -sS --max-time 5 "${URL}/healthz" 2>/dev/null || true)"

    if echo "$body" | grep -q '"status":"ok"'; then
        printf "  ${GREEN}PASS${RESET}  %-40s status=ok\n" "/healthz"
    else
        printf "  ${RED}FAIL${RESET}  %-40s %s\n" "/healthz" "expected status=ok, got: ${body:0:120}"
        fails=$((fails + 1))
    fi
}

# HSTS / nosniff headers should be present on the landing page.
header_must_be_set() {
    local header="$1"
    checks=$((checks + 1))

    if curl -sSI "${URL}/" 2>/dev/null | grep -qi "^${header}:"; then
        printf "  ${GREEN}PASS${RESET}  %-40s present\n" "header: ${header}"
    else
        printf "  ${YELLOW}WARN${RESET}  %-40s missing (security header)\n" "header: ${header}"
        # Headers are graceful-degradation, not blockers — count as a warn but
        # don't fail the script. Tighten this later if you want them strict.
    fi
}

echo "▶ probing ${URL}"
echo

echo "Sensitive paths (must be blocked):"
leak_must_fail "/.env"                            "secrets — APP_SECRET, SMTP password"
leak_must_fail "/.env.example"                    "placeholder file; still shouldn't be served"
leak_must_fail "/config/secrets.php"              "game formulas"
leak_must_fail "/gpro_pilots.sqlite"              "user data (emails + tokens, encrypted)"
leak_must_fail "/bootstrap.php"                   "PHP bootstrap; outside docroot"
leak_must_fail "/composer.json"                   "dep listing"
leak_must_fail "/composer.lock"                   "exact dep versions"
leak_must_fail "/CLAUDE.md"                       "project context (should be excluded from bundle)"
leak_must_fail "/TODO.md"                         "project backlog (should be excluded from bundle)"
leak_must_fail "/gpro-public-api.yml"             "private OpenAPI spec"
leak_must_fail "/src/Service/AuthService.php"     "PHP source — outside docroot"
leak_must_fail "/src/"                            "src/ directory listing"
leak_must_fail "/var/cache/"                      "cache directory listing"
leak_must_fail "/var/log/"                        "log directory listing"
leak_must_fail "/var/mail/"                       "dev-only mail directory listing"
leak_must_fail "/var/"                            "writable runtime dir"
leak_must_fail "/.git/HEAD"                       "git directory accidentally SFTP'd"
leak_must_fail "/bin/db_browser.php"              "CLI-only debug tool"
leak_must_fail "/bin/seed_tracks.php"             "CLI seeder"
leak_must_fail "/data/tracks.csv"                 "tracks fixture"

echo
echo "Public surface (must serve 200):"
surface_must_pass "/"                              "anonymous landing page"
surface_must_pass "/login"                         "login form"
surface_must_pass "/register"                      "register form"
surface_must_pass "/assets/app.css"                "compiled tailwind"
healthz_must_be_ok

echo
echo "Security headers on /:"
header_must_be_set "Strict-Transport-Security"
header_must_be_set "X-Content-Type-Options"
header_must_be_set "X-Frame-Options"
header_must_be_set "Referrer-Policy"

echo
if [[ "$fails" -eq 0 ]]; then
    printf "${GREEN}✓ all %d checks passed${RESET}\n" "$checks"
    exit 0
fi

printf "${RED}✗ %d of %d checks failed${RESET}\n" "$fails" "$checks"
exit 1
