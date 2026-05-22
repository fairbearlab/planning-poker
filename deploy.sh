#!/usr/bin/env bash
#
# deploy.sh — copy ONLY the runtime artifact to a deploy target (PLAN §10a Plan C).
#
# The shipped app has zero runtime dependencies: it is just the .php/.html/.js/.css
# files plus a writable data/ dir. Composer, PHPUnit, Vitest, node_modules, and the
# tests are dev-only and MUST NOT land on the host.
#
# This uses an EXPLICIT ALLOWLIST, not an exclude list, on purpose (outside-voice
# decision): "remember to exclude vendor/" is easy to botch and fails open — a new
# dev-only file silently ships. An allowlist fails closed: anything not named here
# is never copied, so the blast radius of forgetting is zero.
#
# Usage:
#   ./deploy.sh <target-dir>            # copy runtime files into <target-dir>
#   ./deploy.sh --list                  # print the allowlist and exit (dry, no target)
#
# Example:
#   ./deploy.sh /var/www/planning-poker
#
set -euo pipefail

# ── The runtime allowlist (PLAN §3 file layout). Nothing else ships. ──────────
RUNTIME_FILES=(
  index.php     # front controller: HTTP routing
  api.php       # domain logic
  store.php     # storage seam (all SQL)
  db.php        # PDO bootstrap + schema + pragmas
  stats.php     # pure stats functions
  app.html      # SPA shell
  app.js        # frontend logic
  style.css     # styling
  .htaccess     # Apache fallback DB-deny + front-controller rewrite
)

# Explicitly NEVER shipped (documented so the intent is auditable). The allowlist
# already excludes these by omission; this list is a tripwire — if any of these
# ever sneaks into RUNTIME_FILES, that is a bug.
NEVER_SHIP=(
  tests/ tests/js/ vendor/ node_modules/ data/
  composer.json composer.lock phpunit.xml .phpunit.result.cache
  package.json package-lock.json vitest.config.js
  docker/ docker-compose.yml deploy.sh
  docs/ .git/ .gitignore .gstack/ CLAUDE.md CHANGELOG.md README.md TODOS.md VERSION .DS_Store
)

if [[ "${1:-}" == "--list" ]]; then
  printf 'Runtime files (allowlist):\n'
  printf '  %s\n' "${RUNTIME_FILES[@]}"
  printf '\nNever shipped:\n'
  printf '  %s\n' "${NEVER_SHIP[@]}"
  exit 0
fi

TARGET="${1:-}"
if [[ -z "$TARGET" ]]; then
  echo "usage: $0 <target-dir>   (or --list)" >&2
  exit 2
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Guard against deploying onto the source tree itself.
if [[ "$(cd "$TARGET" 2>/dev/null && pwd || echo)" == "$SRC" ]]; then
  echo "refusing to deploy onto the source directory ($SRC)" >&2
  exit 2
fi

mkdir -p "$TARGET"

echo "Deploying runtime files → $TARGET"
for f in "${RUNTIME_FILES[@]}"; do
  if [[ ! -e "$SRC/$f" ]]; then
    echo "  MISSING from source: $f" >&2
    exit 1
  fi
  cp -p "$SRC/$f" "$TARGET/$f"
  echo "  + $f"
done

# Writable data dir for the SQLite file. This is the in-web-root fallback location;
# the PRIMARY recommendation is to point POKER_DB_PATH OUTSIDE the web root (PLAN §9).
mkdir -p "$TARGET/data"
chmod 0775 "$TARGET/data"
echo "  + data/ (writable; chmod 0775)"

cat <<EOF

Done. ${#RUNTIME_FILES[@]} runtime files + data/ copied.

NEXT (deploy-time checklist — PLAN §9, TODOS.md):
  1. DB OUTSIDE web root: set POKER_DB_PATH to a writable path that is NOT served
     over HTTP, e.g.  export POKER_DB_PATH=/var/lib/planning-poker/poker.db
     The bundled data/ + .htaccess deny is only a fallback for Apache hosts.
  2. WAL host probe: open the DB, 'PRAGMA journal_mode=WAL;', read it back, and
     fire two concurrent writes — confirm busy_timeout absorbs the lock (some
     network filesystems silently drop WAL).
  3. Verify the DB is NOT downloadable:
       curl -so /dev/null -w '%{http_code}\n' https://YOUR_HOST/data/poker.db   # want 403/404
       curl -so /dev/null -w '%{http_code}\n' https://YOUR_HOST/data/poker.db-wal
  4. Smoke: a real 2-person round; devtools-confirm votes are masked pre-reveal.
EOF
