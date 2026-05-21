<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Coverage-gap tests (ship audit). Same conventions as ApiTest: fresh temp
 * SQLite per test, handlers called directly with plain request arrays. These
 * close validation, idempotency, and stats-fallback paths the original suite
 * left uncovered.
 */
final class ApiCoverageTest extends TestCase
{
    private const DECK = ['1', '2', '3', '5', '8', '13', '21', '?', '☕'];

    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/pp_' . bin2hex(random_bytes(8)) . '.db';
        putenv('POKER_DB_PATH=' . $this->dbFile);
        db_reset();
    }

    protected function tearDown(): void
    {
        db_reset();
        foreach (['', '-wal', '-shm'] as $suffix) {
            $f = $this->dbFile . $suffix;
            if (is_file($f)) {
                unlink($f);
            }
        }
        putenv('POKER_DB_PATH');
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @param array<int,string> $deck */
    private function makeRoom(array $deck = self::DECK): string
    {
        $res = api_create_room(['name' => 'Team Bear', 'voting_options' => $deck]);
        return $res['code'];
    }

    /** @return array{0:int,1:string} */
    private function joinRoom(string $code, string $name): array
    {
        $token = $this->uuid();
        $res = api_join(['code' => $code, 'name' => $name, 'client_token' => $token]);
        return [$res['participant_id'], $token];
    }

    private function currentRoundId(string $code): int
    {
        $room = room_by_code($code);
        $round = current_round((int) $room['id']);
        return (int) $round['id'];
    }

    private function assertApiError(int $status, string $slug, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected ApiException $slug ($status), none thrown");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status, 'HTTP status');
            $this->assertSame($slug, $e->slug, 'error slug');
        }
    }

    /* --------------------------------------------------- create_room guards */

    public function testCreateRoomRejectsEmptyVotingOptions(): void
    {
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => 'X', 'voting_options' => []]);
        });
        // Also: not an array at all.
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => 'X', 'voting_options' => 'nope']);
        });
    }

    public function testCreateRoomRejectsTooManyOptions(): void
    {
        $deck = array_map('strval', range(1, 41)); // > MAX_VOTING_OPTIONS (40)
        $this->assertApiError(422, 'invalid_input', function () use ($deck) {
            api_create_room(['name' => 'X', 'voting_options' => $deck]);
        });
    }

    public function testCreateRoomRejectsNonStringAndOversizedAndEmptyOptions(): void
    {
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => 'X', 'voting_options' => [5]]); // non-string
        });
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => 'X', 'voting_options' => [str_repeat('a', 17)]]); // > OPTION_MAX_LEN
        });
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => 'X', 'voting_options' => ['   ']]); // empty after trim
        });
    }

    public function testCreateRoomDedupesDuplicateOptions(): void
    {
        $code = api_create_room(['name' => 'X', 'voting_options' => ['1', '1', '2', '2', '3']])['code'];
        $room = room_by_code($code);
        $this->assertSame(['1', '2', '3'], room_options($room));
    }

    /* ----------------------------------------------------- input validators */

    public function testReqStringRejectsEmptyAndOversized(): void
    {
        // Missing/empty name on create_room exercises req_string's empty branch.
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => '   ', 'voting_options' => ['1']]);
        });
        // Oversized name (> NAME_MAX = 80).
        $this->assertApiError(422, 'invalid_input', function () {
            api_create_room(['name' => str_repeat('a', 81), 'voting_options' => ['1']]);
        });
    }

    public function testReqIntRejectsNonDigitRoundId(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        // round_id present but not a digit string → req_int throws.
        $this->assertApiError(422, 'invalid_input', function () use ($tok, $code) {
            api_vote(['token' => $tok, 'round_id' => 'abc', 'code' => $code, 'value' => '5']);
        });
    }

    /* ---------------------------------------------------------- set_topic */

    public function testSetTopicUpdatesAndClearsTopic(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $rid = $this->currentRoundId($code);

        api_set_topic(['token' => $tok, 'round_id' => $rid, 'topic' => 'Login story']);
        $this->assertSame('Login story', round_by_id($rid)['topic']);

        // Empty topic normalizes to null (opt_string) → clears it.
        api_set_topic(['token' => $tok, 'round_id' => $rid, 'topic' => '']);
        $this->assertNull(round_by_id($rid)['topic']);
    }

    public function testSetTopicRejectedOnStaleRound(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $stale = $this->currentRoundId($code);
        api_reveal(['token' => $tok, 'round_id' => $stale]);
        api_new_round(['code' => $code, 'token' => $tok]);

        $this->assertApiError(409, 'round_not_current', function () use ($tok, $stale) {
            api_set_topic(['token' => $tok, 'round_id' => $stale, 'topic' => 'too late']);
        });
    }

    /* ------------------------------------------------------------- reveal */

    public function testRevealIsIdempotent(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $rid = $this->currentRoundId($code);
        api_vote(['token' => $tok, 'round_id' => $rid, 'value' => '5']);

        api_reveal(['token' => $tok, 'round_id' => $rid]);
        $afterFirstReveal = api_state(['code' => $code, 'token' => $tok])['version'];

        // Second reveal must NOT bump version again (already revealed branch).
        api_reveal(['token' => $tok, 'round_id' => $rid]);
        $afterSecondReveal = api_state(['code' => $code, 'token' => $tok])['version'];
        $this->assertSame($afterFirstReveal, $afterSecondReveal, 'double reveal bumped version');
    }

    /* ------------------------------------------------ stats: off-deck spread */

    public function testWideSpreadNumericFallbackForOffDeckValues(): void
    {
        // '8' is not on this deck, so the max endpoint can't be deck-mapped and
        // is_wide_spread takes the ratio fallback: 8/2 = 4 >= 3 → wide.
        $deck = ['2', 'XS', 'XL'];
        $r = compute_results([1 => '2', 2 => '8'], $deck);
        $this->assertTrue($r['wide_spread'], 'off-deck ratio fallback should flag wide');

        // Ratio below threshold: 2 and 3 → 3/2 = 1.5 < 3 → not wide.
        $r2 = compute_results([1 => '2', 2 => '3'], $deck);
        $this->assertFalse($r2['wide_spread'], 'off-deck ratio below threshold');
    }

    /* ----------------------------------------------- cross-room authz isolation */

    public function testParticipantTokenIsScopedToItsRoom(): void
    {
        // A valid token in room A must NOT be accepted in room B. require_participant
        // scopes by (room_id, token); this guards against a regression that dropped
        // the room_id scope (which would turn any token into a global pass).
        $roomA = $this->makeRoom();
        [, $tokenA] = $this->joinRoom($roomA, 'Adam');
        $roomB = $this->makeRoom();

        $this->assertApiError(403, 'not_a_participant', function () use ($roomB, $tokenA) {
            api_state(['code' => $roomB, 'token' => $tokenA]);
        });
    }

    /* ----------------------------------------------------------- recap shape */

    public function testRecapRowExposesDocumentedFields(): void
    {
        // Recap rows must carry the full documented field set (PLAN §5):
        // round_id, topic, average, consensus, leading, distribution.
        $code = $this->makeRoom();
        [, $token] = $this->joinRoom($code, 'Adam');
        $rid = $this->currentRoundId($code);
        api_set_topic(['token' => $token, 'round_id' => $rid, 'topic' => 'Login flow']);
        api_vote(['token' => $token, 'round_id' => $rid, 'value' => '5']);
        api_reveal(['token' => $token, 'round_id' => $rid]);

        $recap = api_recap(['code' => $code, 'token' => $token]);
        $this->assertCount(1, $recap['rounds']);
        $row = $recap['rounds'][0];
        $this->assertSame($rid, $row['round_id']);
        $this->assertSame('Login flow', $row['topic']);
        $this->assertSame(5.0, $row['average']);
        $this->assertTrue($row['consensus'], 'single voter is unanimous');
        $this->assertSame('5', $row['leading']);
        $this->assertSame([['value' => '5', 'count' => 1]], $row['distribution']);
    }
}
