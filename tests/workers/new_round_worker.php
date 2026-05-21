<?php
declare(strict_types=1);

/**
 * Concurrency test worker (PLAN §3a, §10): one process = one new_round call.
 * The ApiTest launches several of these in parallel against the same SQLite
 * file to prove BEGIN IMMEDIATE + in-transaction dedup serialize concurrent
 * "New Round" clicks into exactly one new round.
 *
 * Usage: POKER_DB_PATH=... php new_round_worker.php <code> <token>
 */

require_once __DIR__ . '/../../api.php';

$code = $argv[1] ?? '';
$token = $argv[2] ?? '';

try {
    $res = api_new_round(['code' => $code, 'token' => $token]);
    fwrite(STDOUT, (string) $res['round_id']);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
