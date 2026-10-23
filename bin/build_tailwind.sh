#!/usr/bin/env bash
# Compile assets/css/app.css → public/assets/app.css with the Tailwind v4
# standalone CLI. Downloads the binary on first run so the project stays
# Node-free.
#
# Usage:
#   bin/build_tailwind.sh           one-shot minified build
#   bin/build_tailwind.sh --watch   rebuild on every save (dev)

set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

BIN_DIR="bin"
RELEASE_VERSION="v4.0.6"

case "$(uname -s)" in
    Darwin) OS="macos" ;;
    Linux) OS="linux" ;;
    *) echo "Unsupported OS: $(uname -s)" >&2; exit 1 ;;
esac

case "$(uname -m)" in
    x86_64) TARGET="${OS}-x64" ;;
    aarch64|arm64) TARGET="${OS}-arm64" ;;
    *) echo "Unsupported arch: $(uname -m)" >&2; exit 1 ;;
esac

# Per-target filename: a binary copied over from another OS is never exec'd.
BIN_PATH="${BIN_DIR}/.tailwindcss-${TARGET}"

URL="https://github.com/tailwindlabs/tailwindcss/releases/download/${RELEASE_VERSION}/tailwindcss-${TARGET}"

if [[ ! -x "$BIN_PATH" ]]; then
    echo "→ downloading tailwindcss CLI ${RELEASE_VERSION} (${TARGET})"
    curl -sSL -o "$BIN_PATH" "$URL"
    chmod +x "$BIN_PATH"
fi

INPUT="assets/css/app.css"
OUTPUT="public/assets/app.css"

mkdir -p "$(dirname "$OUTPUT")"

ARGS=(-i "$INPUT" -o "$OUTPUT" --minify)
if [[ "${1:-}" == "--watch" ]]; then
    ARGS=(-i "$INPUT" -o "$OUTPUT" --watch)
fi

exec "$BIN_PATH" "${ARGS[@]}"
