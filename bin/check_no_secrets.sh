#!/usr/bin/env bash
# Fail if anything sensitive is staged for commit or already tracked in git.
# Designed for: pre-commit hook AND first step of CI.
# Output never echoes matched values — only filenames + a redacted indicator.

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

fail=0
note() { printf '  - %s\n' "$1" >&2; }
flag() { printf '\033[31m[BLOCK]\033[0m %s\n' "$1" >&2; fail=1; }

# Files that must never appear in the git index — by exact path.
forbidden_paths=(
  ".env"
  "config/secrets.php"
  "gpro-public-api.yml"
  "GPRO Assistant.docx"
  "db_browser.php"
  "bin/db_browser.php"
  "CLAUDE.md"
  "TODO.md"
  "gpro_pilots.sqlite"
)

tracked=$(git ls-files)

for path in "${forbidden_paths[@]}"; do
  if grep -Fxq "$path" <<<"$tracked"; then
    flag "tracked file must not be committed: $path"
  fi
done

# Any *.sqlite anywhere.
if grep -E '\.sqlite$' <<<"$tracked" >/dev/null; then
  flag "SQLite database file is tracked"
  grep -E '\.sqlite$' <<<"$tracked" | while read -r f; do note "$f"; done
fi

# Anything under /data or /var.
if grep -E '^(data|var)/' <<<"$tracked" >/dev/null; then
  flag "data/ or var/ contents are tracked"
  grep -E '^(data|var)/' <<<"$tracked" | while read -r f; do note "$f"; done
fi

# Content scan: only on staged additions (pre-commit) OR full tree on CI.
# Mode: pre-commit if running with staged changes; otherwise scan tracked files.
if git diff --cached --name-only --diff-filter=ACM 2>/dev/null | grep -q .; then
  scan_files=$(git diff --cached --name-only --diff-filter=ACM)
  scope="staged"
else
  scan_files=$(git ls-files)
  scope="tracked"
fi

# Patterns that indicate a real secret leaked into a *value*.
# Each is a fixed-string OR an ERE — kept narrow to avoid false positives
# on the example file (which uses 0.0 placeholders) and on docs.
#
# Why these:
#   - APP_SECRET=... / EMAIL_ENCRYPTION_KEY=... / RECAPTCHA_SECRET_KEY=...
#     are .env keys; if they appear in committed code with a non-empty value,
#     someone pasted real secrets.
#   - eyJ.* matches JWT bearer tokens (GPRO API token shape).
#   - A `secrets.php` import outside config/ is also suspicious.
content_patterns=(
  '^(APP_SECRET|EMAIL_ENCRYPTION_KEY|RECAPTCHA_SECRET_KEY|GPRO_API_TOKEN|MAIL_PASS|REDIS_PASSWORD)=[^[:space:]]'
  'eyJ[A-Za-z0-9_-]{8,}\.eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}'
)

# Skip binaries, vendored deps, the example file, and this scanner itself.
skip_re='^(vendor/|composer\.lock$|config/secrets\.php\.example$|bin/check_no_secrets\.sh$|.*\.(jpg|jpeg|png|gif|webp|ico|woff2?|ttf|otf|pdf|gz|zip)$)'

while IFS= read -r file; do
  [ -z "$file" ] && continue
  [ -f "$file" ] || continue
  grep -E "$skip_re" <<<"$file" >/dev/null && continue

  for pat in "${content_patterns[@]}"; do
    if grep -P -l "$pat" "$file" >/dev/null 2>&1; then
      flag "suspicious content in $file (pattern redacted)"
    fi
  done
done <<<"$scan_files"

if [ "$fail" -eq 0 ]; then
  printf '\033[32m[OK]\033[0m secret scan clean (%s)\n' "$scope"
  exit 0
fi

printf '\nRefusing to proceed. Fix the listed issues, then re-run.\n' >&2
exit 1
