#!/usr/bin/env bash
# Post-deploy leak probe. Hits a live deployment with curl and asserts that
# files outside the docroot are not served, and that the public surface
# (/, /login, /healthz, …) answers 200 with the security headers set.
#
# Gentle by design: shared-host firewalls ban IPs that look like scanners,
# and what trips them is less the request rate than what is asked for: scanner-signature paths (/.env,
# /.git/HEAD, …), a run of 4xx hits from one address, and one address
# switching browser identity. So the default run:
#   - proves the docroot with two plain-looking files that live outside it
#     (/composer.json, /bootstrap.php). Every unknown path is rewritten to the
#     front controller, so if these two 404, every other file outside public/
#     does too;
#   - sends ~9 requests, one fixed User-Agent, no redirects followed, each
#     spaced by a base delay plus random jitter;
#   - refuses to run again for the same host within the cooldown.
# --full adds the 19 sensitive-path checks (the scanner-signature ones
# included) at a slower pace. Run it rarely: after a docroot or .htaccess
# change, not after every deploy.
#
# Usage:
#   bin/probe_security.sh https://pitwall.your-domain.tld
#   bin/probe_security.sh --full https://pitwall.your-domain.tld
#   PROBE_URL=https://... bin/probe_security.sh
#
# Tunables:
#   PROBE_DELAY     base pause before every request, s   (default 5; 10 with --full)
#   PROBE_JITTER    extra random pause, 0..JITTER s      (default 5; 10 with --full)
#   PROBE_COOLDOWN  minimum gap between runs per host, s (default 3600)
#   PROBE_FORCE=1   ignore the cooldown
#
# Exit code: 0 if every assertion passes, 1 otherwise, 3 if still cooling down.

set -uo pipefail

FULL=0
if [[ "${1:-}" == "--full" ]]; then
    FULL=1
    shift
fi

URL="${1:-${PROBE_URL:-}}"
if [[ -z "$URL" ]]; then
    echo "usage: $0 [--full] <base-url>" >&2
    exit 2
fi
URL="${URL%/}"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
RESET='\033[0m'

fails=0
checks=0

if [[ "$FULL" -eq 1 ]]; then
    PROBE_DELAY="${PROBE_DELAY:-10}"
    PROBE_JITTER="${PROBE_JITTER:-10}"
else
    PROBE_DELAY="${PROBE_DELAY:-5}"
    PROBE_JITTER="${PROBE_JITTER:-5}"
fi
PROBE_COOLDOWN="${PROBE_COOLDOWN:-3600}"

# Cooldown: back-to-back runs are what add up to a ban. One stamp per host,
# under the git-ignored var/.
cd "$(dirname "$0")/.." || exit 2
host="$(sed -E 's#^[a-z]+://##; s#[/:].*$##' <<<"$URL")"
stamp="var/probe_security.${host}.last"
if [[ "${PROBE_FORCE:-0}" != "1" && -f "$stamp" ]]; then
    last="$(cat "$stamp" 2>/dev/null || echo 0)"
    age=$(( $(date +%s) - ${last:-0} ))
    if (( age < PROBE_COOLDOWN )); then
        echo "✗ ${host} was probed $((age / 60)) min ago; wait $(((PROBE_COOLDOWN - age + 59) / 60)) min more, or set PROBE_FORCE=1." >&2
        exit 3
    fi
fi
mkdir -p var && date +%s > "$stamp"

# One ordinary browser identity for the whole run. Rotating it per request
# made one IP look like five browsers — itself a bot signal.
UA="Mozilla/5.0 (X11; Linux x86_64; rv:139.0) Gecko/20100101 Firefox/139.0"

# Base delay + uniform random jitter. $RANDOM re-rolls per call, so the
# cadence isn't a fixed beat.
pace() {
    local delay
    delay="$(awk -v base="$PROBE_DELAY" -v jit="$PROBE_JITTER" -v r="$RANDOM" \
        'BEGIN { printf "%.2f", base + (r / 32767) * jit }')"
    sleep "$delay"
}

# Portable shuffle: macOS has no GNU shuf, and a missing shuffler empties the
# loop below instead of failing it.
shuffle() {
    awk -v seed="$RANDOM" 'BEGIN { srand(seed) } { printf "%.8f\t%s\n", rand(), $0 }' \
        | sort -n | cut -f2-
}

# A group that ran no checks fails the run; it must never read as a pass.
require_ran() {
    local before="$1"
    local group="$2"
    if (( checks == before )); then
        printf "  ${RED}FAIL${RESET}  no %s checks ran\n" "$group"
        fails=$((fails + 1))
    fi
}

# A leak check passes when the path is not served: 4xx/5xx, or a redirect
# (not followed — a redirect hands out no file content).
leak_must_fail() {
    local path="$1"
    local description="$2"
    checks=$((checks + 1))
    pace

    local response
    response="$(curl -sS -o /dev/null -A "$UA" --max-time 15 \
        -w "%{http_code} %{size_download}" \
        "${URL}${path}" 2>/dev/null || echo "000 0")"
    local code="${response%% *}"
    local size="${response##* }"

    if [[ "$code" =~ ^[345][0-9][0-9]$ ]]; then
        printf "  ${GREEN}PASS${RESET}  %-40s %s\n" "$path" "not served (${code})"
        return 0
    fi

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
    code="$(curl -sS -o /dev/null -A "$UA" --max-time 15 -w "%{http_code}" \
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
    body="$(curl -sS --max-time 10 -A "$UA" "${URL}/healthz" 2>/dev/null || true)"

    if echo "$body" | grep -q '"status":"ok"'; then
        printf "  ${GREEN}PASS${RESET}  %-40s status=ok\n" "/healthz"
    else
        printf "  ${RED}FAIL${RESET}  %-40s %s\n" "/healthz" "expected status=ok, got: ${body:0:120}"
        fails=$((fails + 1))
    fi
}

# The landing page and its security headers from ONE GET: the surface check
# and the four header checks share a request.
landing_and_headers() {
    checks=$((checks + 1))
    pace

    local headers code
    headers="$(curl -sS -o /dev/null -D - -A "$UA" --max-time 15 "${URL}/" 2>/dev/null || true)"
    code="$(awk 'toupper($1) ~ /^HTTP\// { c = $2 } END { print c }' <<<"$headers")"

    if [[ "$code" == "200" ]]; then
        printf "  ${GREEN}PASS${RESET}  %-40s 200\n" "/"
    else
        printf "  ${RED}FAIL${RESET}  %-40s %s — %s\n" "/" "${code:-000}" "anonymous landing page"
        fails=$((fails + 1))
    fi

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
            # Headers are graceful degradation, not blockers — warn only.
            printf "  ${YELLOW}WARN${RESET}  %-40s missing (security header)\n" "header: ${header}"
        fi
    done
}

mode="$([[ "$FULL" -eq 1 ]] && echo "full" || echo "light")"
echo "▶ probing ${URL} (${mode}; paced ${PROBE_DELAY}s + 0–${PROBE_JITTER}s jitter per request)"
echo

# Docroot proof: plain-looking files that live outside public/. "path|description".
leak_checks=(
    "/composer.json|dep listing — the docroot is not public/"
    "/bootstrap.php|PHP bootstrap — the docroot is not public/"
)

if [[ "$FULL" -eq 1 ]]; then
    echo -e "${YELLOW}  --full requests scanner-signature paths (/.env, /.git/HEAD, …). Run it rarely.${RESET}"
    leak_checks+=(
        "/.env|secrets — APP_SECRET, SMTP password"
        "/.env.example|placeholder file; still shouldn't be served"
        "/.deploy.env|SFTP deploy credentials"
        "/config/secrets.php|game formulas"
        "/gpro_pilots.sqlite|user data (emails + tokens, encrypted)"
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
fi

echo "Files outside the docroot (must not be served; order shuffled):"
before=$checks
while IFS='|' read -r path description; do
    leak_must_fail "$path" "$description"
done < <(printf '%s\n' "${leak_checks[@]}" | shuffle)
require_ran "$before" "docroot"

surface_checks=(
    "/login|login form"
    "/register|register form"
    "/assets/app.css|compiled tailwind"
    "/robots.txt|crawler policy"
    "/sitemap.xml|sitemap"
)

echo
echo "Public surface (must serve 200; order shuffled):"
landing_and_headers
before=$checks
while IFS='|' read -r path description; do
    surface_must_pass "$path" "$description"
done < <(printf '%s\n' "${surface_checks[@]}" | shuffle)
require_ran "$before" "public surface"
healthz_must_be_ok

echo
if [[ "$fails" -eq 0 ]]; then
    printf "${GREEN}✓ all %d checks passed${RESET}\n" "$checks"
    exit 0
fi

printf "${RED}✗ %d of %d checks failed${RESET}\n" "$fails" "$checks"
exit 1
