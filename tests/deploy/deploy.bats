#!/usr/bin/env bats
#
# Tests for deploy.sh — the one shipped non-PHP runtime tool. It runs `rm -rf`
# on prune (deploy.sh prune step), so a regression here deletes the wrong files
# rather than failing a test. These pin the guards, the allowlist, the
# prune-but-preserve-data/ behavior, and every fail-closed exit.
#
# Two test styles:
#   - Against the REAL repo script, deploying into throwaway temp targets (safe:
#     prune only ever touches TARGET, never the source).
#   - Against a SYNTHESIZED fake source tree (a copy of deploy.sh + a chosen
#     subset of runtime files) to drive the missing-file fail-closed paths and
#     the disjoint-list tripwire under full control.

REPO_ROOT="${BATS_TEST_DIRNAME}/../.."
SCRIPT="${REPO_ROOT}/deploy.sh"

# All runtime files the allowlist ships (mirror of RUNTIME_FILES in deploy.sh).
RUNTIME=(index.php api.php store.php db.php stats.php app.html app.js style.css .htaccess)

setup() {
  WORK="$(mktemp -d)"
  TARGET="${WORK}/target"
}

teardown() {
  [ -n "${WORK:-}" ] && rm -rf -- "$WORK"
}

# Build a complete, self-contained fake source dir: a copy of deploy.sh plus all
# runtime files and data/.htaccess. Callers can then delete a file to test a
# fail-closed path. Echoes the fake source path.
make_fake_source() {
  local src="${WORK}/src"
  mkdir -p "$src/data"
  cp "$SCRIPT" "$src/deploy.sh"
  local f
  for f in "${RUNTIME[@]}"; do printf 'stub\n' > "$src/$f"; done
  printf 'Require all denied\n' > "$src/data/.htaccess"
  echo "$src"
}

# ── --list and usage ──────────────────────────────────────────────────────────

@test "--list prints the allowlist and exits 0 without a target" {
  run bash "$SCRIPT" --list
  [ "$status" -eq 0 ]
  [[ "$output" == *"Runtime files (allowlist):"* ]]
  [[ "$output" == *"index.php"* ]]
  [[ "$output" == *"Never shipped:"* ]]
}

@test "no target prints usage and exits 2" {
  run bash "$SCRIPT"
  [ "$status" -eq 2 ]
  [[ "$output" == *"usage:"* ]]
}

# ── Refused targets (guard before any destructive prune) ────────────────────────

@test "refuses to deploy onto / " {
  run bash "$SCRIPT" /
  [ "$status" -eq 2 ]
  [[ "$output" == *"refusing to deploy onto /"* ]]
}

@test "refuses to deploy onto \$HOME" {
  run env HOME="$WORK" bash "$SCRIPT" "$WORK"
  [ "$status" -eq 2 ]
  [[ "$output" == *"refusing to deploy onto \$HOME"* ]]
}

@test "refuses to deploy onto the source directory" {
  local src; src="$(make_fake_source)"
  run bash "$src/deploy.sh" "$src"
  [ "$status" -eq 2 ]
  [[ "$output" == *"refusing to deploy onto the source directory"* ]]
}

# ── Happy path ──────────────────────────────────────────────────────────────────

@test "deploys every runtime file plus a writable data/ with its deny .htaccess" {
  run bash "$SCRIPT" "$TARGET"
  [ "$status" -eq 0 ]
  local f
  for f in "${RUNTIME[@]}"; do
    [ -f "$TARGET/$f" ] || { echo "missing runtime file: $f"; false; }
  done
  [ -d "$TARGET/data" ]
  [ -f "$TARGET/data/.htaccess" ]
}

@test "deploy ships ONLY allowlisted files (no dev tooling leaks through)" {
  run bash "$SCRIPT" "$TARGET"
  [ "$status" -eq 0 ]
  # Things that must never appear in the artifact.
  for junk in vendor composer.json phpunit.xml tests node_modules package.json \
              vitest.config.js docker docker-compose.yml deploy.sh .git docs; do
    [ ! -e "$TARGET/$junk" ] || { echo "dev-only path leaked: $junk"; false; }
  done
}

# ── Prune (the destructive path) ────────────────────────────────────────────────

@test "prunes pre-existing non-allowlisted entries on a reused target" {
  mkdir -p "$TARGET/vendor" "$TARGET/.git"
  printf 'old\n' > "$TARGET/composer.json"
  printf 'old\n' > "$TARGET/stowaway.txt"
  run bash "$SCRIPT" "$TARGET"
  [ "$status" -eq 0 ]
  [ ! -e "$TARGET/vendor" ]
  [ ! -e "$TARGET/.git" ]
  [ ! -e "$TARGET/composer.json" ]
  [ ! -e "$TARGET/stowaway.txt" ]
  [[ "$output" == *"pruning stale"* ]]
}

@test "preserves data/ (the live SQLite DB) across a redeploy" {
  mkdir -p "$TARGET/data"
  printf 'LIVE DB\n' > "$TARGET/data/poker.db"
  printf 'wal\n'     > "$TARGET/data/poker.db-wal"
  run bash "$SCRIPT" "$TARGET"
  [ "$status" -eq 0 ]
  [ -f "$TARGET/data/poker.db" ]
  [ -f "$TARGET/data/poker.db-wal" ]
  run cat "$TARGET/data/poker.db"
  [ "$output" = "LIVE DB" ]
}

# ── Fail-closed paths (synthesized source) ──────────────────────────────────────

@test "fails closed when a runtime file is missing from source" {
  local src; src="$(make_fake_source)"
  rm "$src/stats.php"
  run bash "$src/deploy.sh" "$TARGET"
  [ "$status" -eq 1 ]
  [[ "$output" == *"MISSING from source: stats.php"* ]]
}

@test "fails closed when data/.htaccess (DB deny) is missing from source" {
  local src; src="$(make_fake_source)"
  rm "$src/data/.htaccess"
  run bash "$src/deploy.sh" "$TARGET"
  [ "$status" -eq 1 ]
  [[ "$output" == *"data/.htaccess"* ]]
}

# ── Disjoint-list tripwire ───────────────────────────────────────────────────────

@test "tripwire fails the deploy if a NEVER_SHIP path leaks into RUNTIME_FILES" {
  local src; src="$(make_fake_source)"
  # Tamper: inject a dev-only path (composer.json, which is in NEVER_SHIP) into
  # the RUNTIME_FILES array. The disjoint assertion must catch it (exit 3).
  sed -i 's/^RUNTIME_FILES=(/RUNTIME_FILES=(\n  composer.json/' "$src/deploy.sh"
  run bash "$src/deploy.sh" "$TARGET"
  [ "$status" -eq 3 ]
  [[ "$output" == *"both RUNTIME_FILES and NEVER_SHIP"* ]]
}
