<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Storage seam (PLAN §3a): every SQL string in the app lives here, behind an
 * intention-revealing function. Domain code (api.php) calls these by name and
 * never writes SQL. Moving to Postgres later means touching only this file.
 *
 * Nothing here enforces business rules (blind-vote boundary, guards) — that is
 * the domain layer's job. These functions are thin, named data access.
 */

/**
 * Run $fn inside a single write transaction (PLAN §3a concurrency decision).
 *
 * BEGIN IMMEDIATE acquires the write lock up front, so two near-simultaneous
 * mutations serialize rather than racing on a version bump or round-dedup
 * check. The transaction boundary lives here, in one place: if $fn throws, we
 * roll back and re-throw, so a handler exception can never leak a half-open
 * transaction. Every domain mutation wraps itself in exactly one of these.
 *
 * @template T
 * @param callable():T $fn
 * @return T
 */
function with_transaction(callable $fn)
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $pdo->exec('COMMIT');
        return $result;
    } catch (\Throwable $e) {
        // rollBack() can itself throw if no txn is active; guard so the
        // original exception is what propagates.
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable $ignored) {
        }
        throw $e;
    }
}

function now_ts(): int
{
    return time();
}

/* ---------------------------------------------------------------- rooms -- */

function create_room(string $code, string $name, string $votingOptionsJson): int
{
    $ts = now_ts();
    $stmt = db()->prepare(
        'INSERT INTO rooms (code, name, voting_options, version, created_at, last_activity_at)
         VALUES (:code, :name, :opts, 0, :ts, :ts)'
    );
    $stmt->execute([':code' => $code, ':name' => $name, ':opts' => $votingOptionsJson, ':ts' => $ts]);
    return (int) db()->lastInsertId();
}

/** @return array<string,mixed>|null */
function room_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM rooms WHERE code = :code');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function room_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM rooms WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Bump version + last_activity_at. Called only on real state changes (PLAN §5). */
function bump_room_version(int $roomId): void
{
    $stmt = db()->prepare(
        'UPDATE rooms SET version = version + 1, last_activity_at = :ts WHERE id = :id'
    );
    $stmt->execute([':ts' => now_ts(), ':id' => $roomId]);
}

/** Opportunistic cleanup of stale rooms (PLAN §8). CASCADE clears children. */
function purge_rooms_older_than(int $cutoffTs): int
{
    $stmt = db()->prepare('DELETE FROM rooms WHERE last_activity_at < :cutoff');
    $stmt->execute([':cutoff' => $cutoffTs]);
    return $stmt->rowCount();
}

/* --------------------------------------------------------- participants -- */

/** @return array<string,mixed>|null */
function participant_by_token(int $roomId, string $clientToken): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM participants WHERE room_id = :room AND client_token = :tok'
    );
    $stmt->execute([':room' => $roomId, ':tok' => $clientToken]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function create_participant(int $roomId, string $name, string $clientToken): int
{
    $ts = now_ts();
    $stmt = db()->prepare(
        'INSERT INTO participants (room_id, name, client_token, joined_at, last_seen_at)
         VALUES (:room, :name, :tok, :ts, :ts)'
    );
    $stmt->execute([':room' => $roomId, ':name' => $name, ':tok' => $clientToken, ':ts' => $ts]);
    return (int) db()->lastInsertId();
}

function update_participant_name(int $participantId, string $name): void
{
    $stmt = db()->prepare('UPDATE participants SET name = :name WHERE id = :id');
    $stmt->execute([':name' => $name, ':id' => $participantId]);
}

/** Heartbeat. Deliberately does NOT bump room.version (PLAN §5 ordering). */
function touch_participant(int $participantId): void
{
    $stmt = db()->prepare('UPDATE participants SET last_seen_at = :ts WHERE id = :id');
    $stmt->execute([':ts' => now_ts(), ':id' => $participantId]);
}

/** @return array<int,array<string,mixed>> ordered by join time. */
function participants_for_room(int $roomId): array
{
    $stmt = db()->prepare(
        'SELECT * FROM participants WHERE room_id = :room ORDER BY joined_at, id'
    );
    $stmt->execute([':room' => $roomId]);
    return $stmt->fetchAll();
}

/* --------------------------------------------------------------- rounds -- */

/** Most-recent round of a room is its "current round" (PLAN §4). */
function current_round(int $roomId): ?array
{
    $stmt = db()->prepare(
        'SELECT * FROM rounds WHERE room_id = :room ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([':room' => $roomId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** @return array<string,mixed>|null */
function round_by_id(int $roundId): ?array
{
    $stmt = db()->prepare('SELECT * FROM rounds WHERE id = :id');
    $stmt->execute([':id' => $roundId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function create_round(int $roomId, ?string $topic): int
{
    $stmt = db()->prepare(
        "INSERT INTO rounds (room_id, topic, state, created_at)
         VALUES (:room, :topic, 'voting', :ts)"
    );
    $stmt->execute([':room' => $roomId, ':topic' => $topic, ':ts' => now_ts()]);
    return (int) db()->lastInsertId();
}

function reveal_round(int $roundId): void
{
    $stmt = db()->prepare(
        "UPDATE rounds SET state = 'revealed', revealed_at = :ts WHERE id = :id"
    );
    $stmt->execute([':ts' => now_ts(), ':id' => $roundId]);
}

function set_round_topic(int $roundId, ?string $topic): void
{
    $stmt = db()->prepare('UPDATE rounds SET topic = :topic WHERE id = :id');
    $stmt->execute([':topic' => $topic, ':id' => $roundId]);
}

function count_votes_for_round(int $roundId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM votes WHERE round_id = :round');
    $stmt->execute([':round' => $roundId]);
    return (int) $stmt->fetchColumn();
}

/** @return array<int,array<string,mixed>> all rounds, oldest first (for recap). */
function rounds_for_room(int $roomId): array
{
    $stmt = db()->prepare('SELECT * FROM rounds WHERE room_id = :room ORDER BY id');
    $stmt->execute([':room' => $roomId]);
    return $stmt->fetchAll();
}

/* ---------------------------------------------------------------- votes -- */

/** Upsert one vote per (round, participant) — changing a vote re-taps the card. */
function upsert_vote(int $roundId, int $participantId, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO votes (round_id, participant_id, value, updated_at)
         VALUES (:round, :pid, :value, :ts)
         ON CONFLICT(round_id, participant_id)
         DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':round' => $roundId,
        ':pid' => $participantId,
        ':value' => $value,
        ':ts' => now_ts(),
    ]);
}

/**
 * Votes for a round, keyed by participant_id.
 * @return array<int,string> participant_id => value
 */
function votes_for_round(int $roundId): array
{
    $stmt = db()->prepare('SELECT participant_id, value FROM votes WHERE round_id = :round');
    $stmt->execute([':round' => $roundId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['participant_id']] = (string) $row['value'];
    }
    return $out;
}
