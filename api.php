<?php
declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/stats.php';

/**
 * Domain layer (PLAN §3a, §5). Pure-ish: each handler takes a plain request
 * array (already parsed by the transport in index.php) and returns a plain
 * array. No $_GET, no echo, no HTTP, no SQL. All business rules — guards,
 * validation, and the blind-vote boundary — live here and are enforced
 * server-side, never on the client's word.
 *
 * Errors are signalled by throwing ApiException, which carries the HTTP status
 * and the machine-readable error slug. The transport turns it into the error
 * envelope { error, code }.
 */

const NAME_MAX = 80;
const TOPIC_MAX = 200;
const ROOM_CODE_LEN = 12;             // 12 base32 chars ≈ 60 bits (PLAN §4)
const ONLINE_WINDOW = 15;             // seconds; presence dot (PLAN §12)
const STALE_ROOM_AGE = 30 * 24 * 3600; // 30 days; opportunistic purge (PLAN §8)
const MAX_VOTING_OPTIONS = 40;
const OPTION_MAX_LEN = 16;

/** A domain error with an HTTP status and a machine slug for the envelope. */
class ApiException extends \RuntimeException
{
    public int $status;
    public string $slug;

    public function __construct(int $status, string $slug, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->slug = $slug;
    }
}

/* --------------------------------------------------------- input helpers -- */

/** Fetch a required, non-empty, length-capped string field. */
function req_string(array $req, string $key, int $max): string
{
    $v = $req[$key] ?? null;
    if (!is_string($v)) {
        throw new ApiException(422, 'invalid_input', "Missing or invalid field: $key");
    }
    $v = trim($v);
    if ($v === '') {
        throw new ApiException(422, 'invalid_input', "Field cannot be empty: $key");
    }
    if (mb_strlen($v) > $max) {
        throw new ApiException(422, 'invalid_input', "Field too long: $key");
    }
    return $v;
}

/** Fetch an optional string field (null when absent/empty), length-capped. */
function opt_string(array $req, string $key, int $max): ?string
{
    $v = $req[$key] ?? null;
    if ($v === null) {
        return null;
    }
    if (!is_string($v)) {
        throw new ApiException(422, 'invalid_input', "Invalid field: $key");
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (mb_strlen($v) > $max) {
        throw new ApiException(422, 'invalid_input', "Field too long: $key");
    }
    return $v;
}

/** Fetch a required integer field. Accepts an int or a digit-only string. */
function req_int(array $req, string $key): int
{
    $v = $req[$key] ?? null;
    if (is_int($v)) {
        return $v;
    }
    if (is_string($v) && ctype_digit($v)) {
        return (int) $v;
    }
    throw new ApiException(422, 'invalid_input', "Missing or invalid field: $key");
}

/** Identity token must be UUID-shaped (PLAN §9). Identity only, never authority. */
function req_client_token(array $req, string $key): string
{
    $v = $req[$key] ?? null;
    if (!is_string($v) || !preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $v
    )) {
        throw new ApiException(422, 'invalid_token', 'Missing or malformed client token');
    }
    return strtolower($v);
}

/** Decode a room's stored voting_options JSON to a list of strings. */
function room_options(array $room): array
{
    $opts = json_decode($room['voting_options'], true);
    return is_array($opts) ? array_values(array_map('strval', $opts)) : [];
}

function gen_room_code(): string
{
    // RFC 4648 base32 alphabet minus padding; uppercase, unambiguous enough.
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bytes = random_bytes(ROOM_CODE_LEN);
    $code = '';
    for ($i = 0; $i < ROOM_CODE_LEN; $i++) {
        $code .= $alphabet[ord($bytes[$i]) & 31];
    }
    return $code;
}

/* -------------------------------------------------------- guard helpers -- */

/**
 * Resolve the room a request targets without trusting a client-passed code more
 * than necessary (PLAN §5). Prefer explicit `code`; otherwise derive from
 * `round_id`. 404 if neither resolves.
 *
 * @return array<string,mixed> room row
 */
function resolve_room(array $req): array
{
    $code = $req['code'] ?? null;
    if (is_string($code) && $code !== '') {
        $room = room_by_code($code);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found');
        }
        return $room;
    }

    if (isset($req['round_id'])) {
        $round = round_by_id(req_int($req, 'round_id'));
        if ($round !== null) {
            $room = room_by_id((int) $round['room_id']);
            if ($room !== null) {
                return $room;
            }
        }
    }

    throw new ApiException(404, 'room_not_found', 'Room not found');
}

/**
 * Confirm the token maps to a participant *in this resolved room*. The room is
 * never taken on the client's word (PLAN §5).
 *
 * @return array<string,mixed> participant row
 */
function require_participant(string $token, array $room): array
{
    $p = participant_by_token((int) $room['id'], $token);
    if ($p === null) {
        throw new ApiException(403, 'not_a_participant', 'Not a participant in this room');
    }
    return $p;
}

/**
 * Confirm $roundId is the room's current (most-recent) round, else 409. Stops
 * vote/reveal/set_topic from mutating a past or already-revealed-then-superseded
 * round (PLAN §5).
 *
 * @return array<string,mixed> the current round row
 */
function assert_current_round(int $roundId, array $room): array
{
    $cur = current_round((int) $room['id']);
    if ($cur === null || (int) $cur['id'] !== $roundId) {
        throw new ApiException(409, 'round_not_current', 'That round is no longer current');
    }
    return $cur;
}

/* ------------------------------------------------------- state assembly -- */

/**
 * Build the full state payload for a viewer. Enforces the blind-vote boundary
 * (PLAN §5): while the round is `voting`, every participant's `value` is null on
 * the wire and only `has_voted` is exposed; the viewer sees only their own vote
 * under `you.value`. Other people's values are never serialized until reveal.
 *
 * @return array<string,mixed>
 */
function build_state(array $room, array $viewer): array
{
    $roomId = (int) $room['id'];
    $round = current_round($roomId);
    $participants = participants_for_room($roomId);

    $votes = $round !== null ? votes_for_round((int) $round['id']) : [];
    $revealed = $round !== null && $round['state'] === 'revealed';
    $nowTs = now_ts();
    $options = room_options($room); // decode the deck once; reused below

    $people = [];
    foreach ($participants as $p) {
        $pid = (int) $p['id'];
        $hasVoted = isset($votes[$pid]);
        $people[] = [
            'participant_id' => $pid,
            'name' => $p['name'],
            'online' => ($nowTs - (int) $p['last_seen_at']) <= ONLINE_WINDOW,
            'has_voted' => $hasVoted,
            // Blind boundary: real value only after reveal.
            'value' => $revealed ? ($votes[$pid] ?? null) : null,
        ];
    }

    $results = null;
    if ($revealed) {
        $results = compute_results($votes, $options);
    }

    $viewerId = (int) $viewer['id'];

    return [
        'version' => (int) $room['version'],
        'room' => [
            'code' => $room['code'],
            'name' => $room['name'],
            'voting_options' => $options,
        ],
        'round' => $round === null ? null : [
            'id' => (int) $round['id'],
            'topic' => $round['topic'],
            'state' => $round['state'],
        ],
        'you' => [
            'participant_id' => $viewerId,
            // Viewer always sees their own current vote.
            'value' => $votes[$viewerId] ?? null,
        ],
        'participants' => $people,
        'results' => $results,
    ];
}

/* -------------------------------------------------------------- handlers -- */

/** POST create_room { name, voting_options[] } -> { code } */
function api_create_room(array $req): array
{
    $name = req_string($req, 'name', NAME_MAX);

    $options = $req['voting_options'] ?? null;
    if (!is_array($options) || count($options) === 0) {
        throw new ApiException(422, 'invalid_input', 'voting_options must be a non-empty array');
    }
    if (count($options) > MAX_VOTING_OPTIONS) {
        throw new ApiException(422, 'invalid_input', 'Too many voting options');
    }
    $clean = [];
    foreach ($options as $opt) {
        if (!is_string($opt)) {
            throw new ApiException(422, 'invalid_input', 'voting_options must be strings');
        }
        $opt = trim($opt);
        if ($opt === '' || mb_strlen($opt) > OPTION_MAX_LEN) {
            throw new ApiException(422, 'invalid_input', 'Invalid voting option');
        }
        $clean[] = $opt;
    }
    $clean = array_values(array_unique($clean));
    $optionsJson = json_encode($clean, JSON_UNESCAPED_UNICODE);

    return with_transaction(function () use ($name, $optionsJson) {
        // Opportunistic stale-room cleanup (PLAN §8) — best-effort, no scheduler.
        purge_rooms_older_than(now_ts() - STALE_ROOM_AGE);

        // Generate a unique code; collisions at ~60 bits are astronomically rare.
        $code = gen_room_code();
        for ($tries = 0; $tries < 5 && room_by_code($code) !== null; $tries++) {
            $code = gen_room_code();
        }
        $roomId = create_room($code, $name, $optionsJson);

        // Every room opens with a fresh voting round (PLAN §7).
        create_round($roomId, null);

        return ['code' => $code];
    });
}

/**
 * POST join { code, name, client_token } -> { participant_id } + full state.
 * Idempotent (PLAN §5): find-or-create on (room_id, client_token). A repeated
 * call returns the same participant_id and never errors. Bumps version only on
 * a real change (new participant or a name change).
 */
function api_join(array $req): array
{
    $name = req_string($req, 'name', NAME_MAX);
    $token = req_client_token($req, 'client_token');
    $room = resolve_room($req); // requires `code`

    $result = with_transaction(function () use ($room, $name, $token) {
        $roomId = (int) $room['id'];
        $existing = participant_by_token($roomId, $token);

        if ($existing === null) {
            $pid = create_participant($roomId, $name, $token);
            bump_room_version($roomId);
            return $pid;
        }

        // Idempotent rejoin. Only a name change is a real state change.
        if ($existing['name'] !== $name) {
            update_participant_name((int) $existing['id'], $name);
            bump_room_version($roomId);
        }
        return (int) $existing['id'];
    });

    // Re-read the room so version reflects any bump above.
    $room = room_by_id((int) $room['id']);
    $viewer = ['id' => $result];
    $state = build_state($room, $viewer);
    $state['participant_id'] = $result;
    return $state;
}

/**
 * GET state { code, token, since? } -> full state or { unchanged: true }.
 * Ordering (PLAN §5): always do the cheap heartbeat write first, then read
 * version; short-circuit before assembling participants/votes when nothing
 * changed. The heartbeat does NOT bump version.
 */
function api_state(array $req): array
{
    $room = resolve_room($req); // requires `code`
    $token = req_client_token($req, 'token');
    $viewer = require_participant($token, $room);

    // Heartbeat first (indexed single-row UPDATE; no version bump).
    touch_participant((int) $viewer['id']);

    // Re-read version after the heartbeat to short-circuit unchanged polls.
    $fresh = room_by_id((int) $room['id']);
    $version = (int) $fresh['version'];

    if (isset($req['since'])) {
        $since = req_int($req, 'since');
        if ($since === $version) {
            return ['unchanged' => true];
        }
    }

    return build_state($fresh, $viewer);
}

/**
 * POST vote { token, round_id, value } -> { ok }. Upsert. Rejected on a
 * revealed round (409 round_revealed) and on a stale round (409).
 */
function api_vote(array $req): array
{
    $token = req_client_token($req, 'token');
    $roundId = req_int($req, 'round_id');
    $value = req_string($req, 'value', OPTION_MAX_LEN);

    $room = resolve_room($req); // derives from round_id
    $viewer = require_participant($token, $room);

    if (!in_array($value, room_options($room), true)) {
        throw new ApiException(422, 'invalid_value', 'Vote value is not in the deck');
    }

    return with_transaction(function () use ($roundId, $room, $viewer, $value) {
        $round = assert_current_round($roundId, $room);
        if ($round['state'] !== 'voting') {
            throw new ApiException(409, 'round_revealed', 'Round is already revealed');
        }
        upsert_vote($roundId, (int) $viewer['id'], $value);
        bump_room_version((int) $room['id']);
        return ['ok' => true];
    });
}

/** POST reveal { token, round_id } -> { ok }. Any participant may reveal. */
function api_reveal(array $req): array
{
    $token = req_client_token($req, 'token');
    $roundId = req_int($req, 'round_id');

    $room = resolve_room($req);
    require_participant($token, $room);

    return with_transaction(function () use ($roundId, $room) {
        $round = assert_current_round($roundId, $room);
        if ($round['state'] !== 'revealed') {
            reveal_round($roundId);
            bump_room_version((int) $room['id']);
        }
        return ['ok' => true];
    });
}

/**
 * POST new_round { token, code, topic? } -> { round_id }. Any participant may
 * call it. Idempotent dedup (PLAN §5): if the current round is a voting round
 * with zero votes, reuse it instead of creating a duplicate. The dedup check
 * runs inside the transaction so concurrent clicks serialize.
 */
function api_new_round(array $req): array
{
    $token = req_client_token($req, 'token');
    $topic = opt_string($req, 'topic', TOPIC_MAX);
    $room = resolve_room($req); // requires `code`
    require_participant($token, $room);

    return with_transaction(function () use ($room, $topic) {
        $roomId = (int) $room['id'];
        $cur = current_round($roomId);

        // Dedup: reuse an empty, still-voting round (kills double-click dupes).
        if ($cur !== null
            && $cur['state'] === 'voting'
            && count_votes_for_round((int) $cur['id']) === 0
        ) {
            // Still honor a topic the caller passed — otherwise "new round titled
            // X" on a fresh empty round would silently keep the old/null topic.
            if ($topic !== null && $topic !== $cur['topic']) {
                set_round_topic((int) $cur['id'], $topic);
                bump_room_version($roomId);
            }
            return ['round_id' => (int) $cur['id']];
        }

        $roundId = create_round($roomId, $topic);
        bump_room_version($roomId);
        return ['round_id' => $roundId];
    });
}

/** POST set_topic { token, round_id, topic } -> { ok }. Current round only. */
function api_set_topic(array $req): array
{
    $token = req_client_token($req, 'token');
    $roundId = req_int($req, 'round_id');
    $topic = opt_string($req, 'topic', TOPIC_MAX);

    $room = resolve_room($req);
    require_participant($token, $room);

    return with_transaction(function () use ($roundId, $room, $topic) {
        assert_current_round($roundId, $room);
        set_round_topic($roundId, $topic);
        bump_room_version((int) $room['id']);
        return ['ok' => true];
    });
}

/**
 * GET recap { code, token } -> { rounds: [...] }. Read-only history (PLAN §8a),
 * computed with the same stats functions. Only revealed rounds carry results;
 * an in-progress current round is omitted to preserve the blind boundary.
 */
function api_recap(array $req): array
{
    $room = resolve_room($req); // requires `code`
    $token = req_client_token($req, 'token');
    require_participant($token, $room);

    $options = room_options($room);
    $out = [];
    foreach (rounds_for_room((int) $room['id']) as $round) {
        if ($round['state'] !== 'revealed') {
            continue; // never expose an unrevealed round's votes
        }
        $results = compute_results(votes_for_round((int) $round['id']), $options);
        $out[] = [
            'round_id' => (int) $round['id'],
            'topic' => $round['topic'],
            'average' => $results['average'],
            'consensus' => $results['consensus'],
            'leading' => $results['leading'],
            'distribution' => $results['distribution'],
        ];
    }

    return ['rounds' => $out];
}
