<?php
declare(strict_types=1);

/**
 * Storage bootstrap: PDO connection, pragmas, and idempotent schema.
 *
 * This is the lowest layer of the storage seam (PLAN §3a). It knows about
 * SQLite specifics — file location, pragmas, DDL — and nothing about rooms or
 * votes. Moving to another database means rewriting this file and store.php
 * only.
 *
 * Schema runs on every connection via CREATE TABLE IF NOT EXISTS: cheap,
 * idempotent, and removes the need for a separate migration step on a
 * request-only host.
 */

/**
 * Resolve the SQLite file path. Defaults to ./data/poker.db, but honours
 * POKER_DB_PATH so tests can point at a temp file and the host can point at a
 * directory outside the web root (PLAN §9).
 */
function db_path(): string
{
    $env = getenv('POKER_DB_PATH');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return __DIR__ . '/data/poker.db';
}

/**
 * Open (once per request) a configured PDO handle.
 *
 * Pragmas (PLAN §3):
 *   - journal_mode=WAL    readers don't block the writer
 *   - busy_timeout=5000   wait out brief write locks instead of erroring
 *   - foreign_keys=ON     enables ON DELETE CASCADE cleanup
 */
function db(): PDO
{
    return db_handle(false);
}

/**
 * Drop the cached connection so the next db() call reconnects — used by tests
 * to point each test at a fresh temp DB (after re-setting POKER_DB_PATH).
 */
function db_reset(): void
{
    db_handle(true);
}

function db_handle(bool $reset): PDO
{
    static $pdo = null;
    if ($reset) {
        $pdo = null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = db_path();
    if ($path !== ':memory:') {
        $dir = dirname($path);
        // Check the result rather than letting a failed mkdir() emit a warning:
        // under a web SAPI that warning is written to the response body and
        // corrupts the JSON envelope. Re-check is_dir() to tolerate a concurrent
        // request winning the race.
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: {$dir}");
        }
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // WAL is persistent metadata; the rest are per-connection.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    db_migrate($pdo);

    return $pdo;
}

/**
 * Create the schema if it doesn't exist. Safe to call on every request.
 * Data model per PLAN §4 — schema and UNIQUE constraints are the
 * costly-to-migrate parts and are deliberate.
 */
function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rooms (
    id               INTEGER PRIMARY KEY,
    code             TEXT    NOT NULL UNIQUE,
    name             TEXT    NOT NULL,
    voting_options   TEXT    NOT NULL,           -- JSON array of strings
    version          INTEGER NOT NULL DEFAULT 0, -- bumped on real state changes
    created_at       INTEGER NOT NULL,
    last_activity_at INTEGER NOT NULL
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS participants (
    id           INTEGER PRIMARY KEY,
    room_id      INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    name         TEXT    NOT NULL,
    client_token TEXT    NOT NULL,
    joined_at    INTEGER NOT NULL,
    last_seen_at INTEGER NOT NULL,
    UNIQUE(room_id, client_token)
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS rounds (
    id          INTEGER PRIMARY KEY,
    room_id     INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    topic       TEXT,
    state       TEXT    NOT NULL DEFAULT 'voting', -- 'voting' | 'revealed'
    created_at  INTEGER NOT NULL,
    revealed_at INTEGER
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS votes (
    id             INTEGER PRIMARY KEY,
    round_id       INTEGER NOT NULL REFERENCES rounds(id) ON DELETE CASCADE,
    participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
    value          TEXT    NOT NULL,
    updated_at     INTEGER NOT NULL,
    UNIQUE(round_id, participant_id)
)
SQL);

    // Hot lookups: "current round of a room" and "votes of a round".
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rounds_room ON rounds(room_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_votes_round ON votes(round_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_participants_room ON participants(room_id)');
}
