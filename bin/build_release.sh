#!/usr/bin/env bash
# Assemble a deployable bundle under dist/gpro-pitwall/.
#
# The bundle contains only what the app needs at runtime: vendor (no dev
# deps), source, templates, config, public docroot, and the compiled CSS.
# It excludes anything dev-only or sensitive.
#
# Usage:
#   bin/build_release.sh             assemble dist/gpro-pitwall/
#   bin/build_release.sh --tar       also produce dist/gpro-pitwall.tar.gz
#
# Deployment is a manual SFTP copy of the bundle (see README.md).

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

DIST="dist/gpro-pitwall"

TAR=false
for arg in "$@"; do
    case "$arg" in
        --tar) TAR=true ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

echo "→ cleaning ${DIST}"
rm -rf "$DIST"
mkdir -p "$DIST"

echo "→ compiling Tailwind"
bin/build_tailwind.sh > /dev/null

echo "→ installing prod-only composer dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress > /dev/null

echo "→ copying runtime files"
cp -R src "${DIST}/src"
cp -R templates "${DIST}/templates"
cp -R config "${DIST}/config"
cp -R public "${DIST}/public"
cp -R vendor "${DIST}/vendor"
cp bootstrap.php composer.json "${DIST}/"

# composer.lock is gitignored — a CI checkout starts without it (the
# composer install above regenerates one). Don't depend on its presence;
# the bundle doesn't need it at runtime (vendor/ ships pre-installed).
if [[ -f composer.lock ]]; then
    cp composer.lock "${DIST}/"
fi

if [[ -f data/tracks.csv ]]; then
    mkdir -p "${DIST}/data"
    cp data/tracks.csv "${DIST}/data/tracks.csv"
fi

# Strip dev-only bin/ scripts but keep seed_tracks.php (operator runs it
# once on the server to bootstrap the SQLite schema).
mkdir -p "${DIST}/bin"
cp bin/seed_tracks.php "${DIST}/bin/seed_tracks.php"

# Writable runtime dir — created empty so the host doesn't have to.
mkdir -p "${DIST}/var/cache" "${DIST}/var/mail" "${DIST}/var/log"

# Final guard — these MUST NOT land in dist/.
for forbidden in \
    "${DIST}/.git" \
    "${DIST}/tests" \
    "${DIST}/.github" \
    "${DIST}/CLAUDE.md" \
    "${DIST}/TODO.md" \
    "${DIST}/gpro-public-api.yml" \
    "${DIST}/.env"; do
    if [[ -e "$forbidden" ]]; then
        echo "✗ forbidden artefact in bundle: $forbidden" >&2
        exit 1
    fi
done

echo "→ restoring dev dependencies"
composer install --prefer-dist --no-progress --no-interaction > /dev/null

if [[ "$TAR" == true ]]; then
    echo "→ packaging dist/gpro-pitwall.tar.gz"
    tar -C dist -czf dist/gpro-pitwall.tar.gz gpro-pitwall
    ls -lh dist/gpro-pitwall.tar.gz
fi

echo "✓ bundle ready at ${DIST}"
