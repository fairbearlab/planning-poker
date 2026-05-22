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
# On a REUSED target the allowlist alone isn't enough: copying in the runtime files
# leaves any pre-existing dev-only junk (vendor/, .git/, tests/, an old composer.json)
# in place, still served. So before copying we PRUNE every top-level entry in the
# target that isn't allowlisted — EXCEPT data/, which holds the live SQLite DB and
# must survive a redeploy. Removing a top-level dir clears everything nested under it.
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

# -P resolves symlinks to the physical path. The guard below compares physical
# paths so a symlinked target (e.g. /var/www/current -> releases/x) can't slip
# past the "don't deploy onto the source" check on a logical-path match.
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

# Guard against deploying onto the source tree itself (physical-path compare).
if [[ "$(cd "$TARGET" 2>/dev/null && pwd -P || echo)" == "$SRC" ]]; then
  echo "refusing to deploy onto the source directory ($SRC)" >&2
  exit 2
fi

mkdir -p -- "$TARGET"

# Is a top-level basename one we keep? Allowlisted runtime files + data/ (the
# live DB lives there). bash-3.2-safe: a loop, not an associative array, so this
# runs on the dev Mac and the Linux host alike.
_is_kept() {
  local needle="$1" f
  [[ "$needle" == "data" ]] && return 0
  for f in "${RUNTIME_FILES[@]}"; do
    [[ "$f" == "$needle" ]] && return 0
  done
  return 1
}

# Prune stale top-level entries left by a prior deploy or manual copy. data/ is
# preserved wholesale (DB + WAL/SHM survive); everything else not on the allowlist
# is removed so the target ends up as exactly the runtime artifact, nothing more.
while IFS= read -r -d '' entry; do
  base="$(basename "$entry")"
  if ! _is_kept "$base"; then
    echo "  - pruning stale: $base"
    rm -rf -- "$entry"
  fi
done < <(find "$TARGET" -mindepth 1 -maxdepth 1 -print0)

echo "Deploying runtime files → $TARGET"
for f in "${RUNTIME_FILES[@]}"; do
  if [[ ! -e "$SRC/$f" ]]; then
    echo "  MISSING from source: $f" >&2
    exit 1
  fi
  cp -p -- "$SRC/$f" "$TARGET/$f"
  echo "  + $f"
done

# Writable data dir for the SQLite file. This is the in-web-root fallback location;
# the PRIMARY recommendation is to point POKER_DB_PATH OUTSIDE the web root (PLAN §9).
mkdir -p -- "$TARGET/data"
chmod 0775 "$TARGET/data"
# Ship the data/ deny file too: it's the documented defense-in-depth fallback
# (Require all denied) for the in-web-root DB. Creating data/ without it would
# leave the SQLite file reachable on any host that honors .htaccess in subdirs.
if [[ -e "$SRC/data/.htaccess" ]]; then
  cp -p -- "$SRC/data/.htaccess" "$TARGET/data/.htaccess"
  echo "  + data/.htaccess (DB deny)"
fi
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
