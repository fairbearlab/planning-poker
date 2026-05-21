<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Domain tests against a fresh temp SQLite DB per test (PLAN §10). Handlers are
 * called directly with plain request arrays — no HTTP — so these exercise the
 * domain layer in isolation from index.php.
 */
final class ApiTest extends TestCase
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

    /* ------------------------------------------------------------ helpers */

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
        $res = api_create_room(['name' => 'Team Bear — Refinement', 'voting_options' => $deck]);
        return $res['code'];
    }

    /** @return array{0:int,1:string} [participant_id, token] */
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

    /* -------------------------------------------------------------- tests */

    public function testCreateRoomReturnsCodeAndOpensVotingRound(): void
    {
        $code = $this->makeRoom();
        $this->assertSame(12, strlen($code));

        $room = room_by_code($code);
        $this->assertNotNull($room);
        $round = current_round((int) $room['id']);
        $this->assertSame('voting', $round['state']);
    }

    /**
     * CRITICAL regression test (PLAN §10, §5): during voting, no other
     * participant's value reaches the wire — only has_voted. Once green, stays
     * green.
     */
    public function testBlindMaskHidesOthersValuesDuringVoting(): void
    {
        $code = $this->makeRoom();
        [$adamId, $adamTok] = $this->joinRoom($code, 'Adam');
        [$jaymeId, $jaymeTok] = $this->joinRoom($code, 'Jayme');
        $roundId = $this->currentRoundId($code);

        api_vote(['token' => $adamTok, 'round_id' => $roundId, 'value' => '5']);
        api_vote(['token' => $jaymeTok, 'round_id' => $roundId, 'value' => '8']);

        // Adam polls; he must NOT see Jayme's value, only that she voted.
        $state = api_state(['code' => $code, 'token' => $adamTok]);

        $byId = [];
        foreach ($state['participants'] as $p) {
            $byId[$p['participant_id']] = $p;
        }
        $this->assertTrue($byId[$jaymeId]['has_voted']);
        $this->assertNull($byId[$jaymeId]['value'], 'BLIND LEAK: other vote exposed pre-reveal');
        $this->assertTrue($byId[$adamId]['has_voted']);
        $this->assertNull($byId[$adamId]['value'], 'own value not in participants list pre-reveal');

        // Adam still sees his own vote under you.value.
        $this->assertSame('5', $state['you']['value']);
        $this->assertNull($state['results']);

        // And nothing in the serialized payload leaks "8" anywhere.
        $wire = json_encode($state, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('"value":"8"', $wire);
    }

    public function testRevealExposesValuesAndResults(): void
    {
        $code = $this->makeRoom();
        [, $adamTok] = $this->joinRoom($code, 'Adam');
        [$jaymeId, $jaymeTok] = $this->joinRoom($code, 'Jayme');
        $roundId = $this->currentRoundId($code);

        api_vote(['token' => $adamTok, 'round_id' => $roundId, 'value' => '5']);
        api_vote(['token' => $jaymeTok, 'round_id' => $roundId, 'value' => '8']);
        api_reveal(['token' => $adamTok, 'round_id' => $roundId]);

        $state = api_state(['code' => $code, 'token' => $adamTok]);
        $byId = [];
        foreach ($state['participants'] as $p) {
            $byId[$p['participant_id']] = $p;
        }
        $this->assertSame('8', $byId[$jaymeId]['value']);
        $this->assertNotNull($state['results']);
        $this->assertSame(6.5, $state['results']['average']);
        $this->assertSame('revealed', $state['round']['state']);
    }

    public function testVoteRejectedOnRevealedRound(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $roundId = $this->currentRoundId($code);
        api_vote(['token' => $tok, 'round_id' => $roundId, 'value' => '5']);
        api_reveal(['token' => $tok, 'round_id' => $roundId]);

        $this->assertApiError(409, 'round_revealed', function () use ($tok, $roundId) {
            api_vote(['token' => $tok, 'round_id' => $roundId, 'value' => '8']);
        });
    }

    public function testNewRoundDedupReusesEmptyVotingRound(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');

        // First call sees the initial empty voting round → reuses it.
        $r1 = api_new_round(['code' => $code, 'token' => $tok]);
        // Second call, still empty → reuses again. No duplicate.
        $r2 = api_new_round(['code' => $code, 'token' => $tok]);

        $this->assertSame($r1['round_id'], $r2['round_id']);
        $room = room_by_code($code);
        $this->assertCount(1, rounds_for_room((int) $room['id']));
    }

    /**
     * Genuine concurrency (PLAN §3a, §10): with a non-empty current round, fire
     * several parallel new_round processes against the same DB file. BEGIN
     * IMMEDIATE + in-transaction dedup must yield exactly one new round.
     */
    public function testNewRoundConcurrentCreatesExactlyOneRound(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open unavailable');
        }

        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        // Make the current round non-empty so new_round must create, not reuse.
        api_vote(['token' => $tok, 'round_id' => $this->currentRoundId($code), 'value' => '5']);

        $room = room_by_code($code);
        $before = count(rounds_for_room((int) $room['id']));

        $worker = __DIR__ . '/workers/new_round_worker.php';
        $procs = [];
        $pipes = [];
        $env = ['POKER_DB_PATH' => $this->dbFile, 'PATH' => getenv('PATH')];
        for ($i = 0; $i < 5; $i++) {
            $procs[$i] = proc_open(
                ['php', $worker, $code, $tok],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                __DIR__,
                $env
            );
        }
        $roundIds = [];
        foreach ($procs as $i => $proc) {
            $roundIds[] = trim(stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            $this->assertSame(0, proc_close($proc), "worker $i exited non-zero");
        }

        // db() cached a stale handle from before the subprocess writes; reset.
        db_reset();
        $after = count(rounds_for_room((int) $room['id']));

        $this->assertSame($before + 1, $after, 'concurrent New Round created duplicates');
        $this->assertCount(1, array_unique($roundIds), 'workers disagreed on the round id');
    }

    public function testJoinIsIdempotentSameToken(): void
    {
        $code = $this->makeRoom();
        $token = $this->uuid();
        $first = api_join(['code' => $code, 'name' => 'Adam', 'client_token' => $token]);
        $second = api_join(['code' => $code, 'name' => 'Adam', 'client_token' => $token]);
        $this->assertSame($first['participant_id'], $second['participant_id']);

        $room = room_by_code($code);
        $this->assertCount(1, participants_for_room((int) $room['id']));
    }

    public function testIdempotentRejoinSameNameDoesNotBumpVersion(): void
    {
        $code = $this->makeRoom();
        $token = $this->uuid();
        $first = api_join(['code' => $code, 'name' => 'Adam', 'client_token' => $token]);
        $v1 = $first['version'];
        $second = api_join(['code' => $code, 'name' => 'Adam', 'client_token' => $token]);
        $this->assertSame($v1, $second['version'], 'idempotent rejoin must not bump version');

        // But a name change is a real state change → bump.
        $third = api_join(['code' => $code, 'name' => 'Adam B', 'client_token' => $token]);
        $this->assertGreaterThan($v1, $third['version']);
    }

    public function testStateShortCircuitsWhenSinceMatchesVersion(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $state = api_state(['code' => $code, 'token' => $tok]);
        $version = $state['version'];

        $again = api_state(['code' => $code, 'token' => $tok, 'since' => $version]);
        $this->assertSame(['unchanged' => true], $again);

        // A real change moves the version; the short-circuit no longer fires.
        api_vote(['token' => $tok, 'round_id' => $this->currentRoundId($code), 'value' => '5']);
        $after = api_state(['code' => $code, 'token' => $tok, 'since' => $version]);
        $this->assertArrayNotHasKey('unchanged', $after);
        $this->assertGreaterThan($version, $after['version']);
    }

    public function testBadTokenIsRejected(): void
    {
        $code = $this->makeRoom();
        $this->joinRoom($code, 'Adam');
        $stranger = $this->uuid(); // valid shape, never joined

        $this->assertApiError(403, 'not_a_participant', function () use ($code, $stranger) {
            api_state(['code' => $code, 'token' => $stranger]);
        });
    }

    public function testMalformedTokenRejected(): void
    {
        $code = $this->makeRoom();
        $this->joinRoom($code, 'Adam');
        $this->assertApiError(422, 'invalid_token', function () use ($code) {
            api_state(['code' => $code, 'token' => 'not-a-uuid']);
        });
    }

    public function testStaleRoundIdRejected(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $stale = $this->currentRoundId($code);
        // Reveal then start a new round so $stale is no longer current.
        api_reveal(['token' => $tok, 'round_id' => $stale]);
        api_new_round(['code' => $code, 'token' => $tok]);

        $this->assertApiError(409, 'round_not_current', function () use ($tok, $stale) {
            api_vote(['token' => $tok, 'round_id' => $stale, 'value' => '5']);
        });
    }

    public function testInvalidVoteValueRejected(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $roundId = $this->currentRoundId($code);
        $this->assertApiError(422, 'invalid_value', function () use ($tok, $roundId) {
            api_vote(['token' => $tok, 'round_id' => $roundId, 'value' => '99']);
        });
    }

    public function testCoffeeVoteRoundTripsThroughReveal(): void
    {
        $code = $this->makeRoom();
        [$pid, $tok] = $this->joinRoom($code, 'Adam');
        $roundId = $this->currentRoundId($code);
        api_vote(['token' => $tok, 'round_id' => $roundId, 'value' => '☕']);
        api_reveal(['token' => $tok, 'round_id' => $roundId]);

        $state = api_state(['code' => $code, 'token' => $tok]);
        $this->assertSame('☕', $state['participants'][0]['value']);
        $wire = json_encode($state, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('☕', $wire);
    }

    public function testRoomNotFoundForUnknownCode(): void
    {
        $this->assertApiError(404, 'room_not_found', function () {
            api_state(['code' => 'ZZZZZZZZZZZZ', 'token' => $this->uuid()]);
        });
    }

    public function testRecapReturnsOnlyRevealedRounds(): void
    {
        $code = $this->makeRoom();
        [, $tok] = $this->joinRoom($code, 'Adam');
        $r1 = $this->currentRoundId($code);
        api_vote(['token' => $tok, 'round_id' => $r1, 'value' => '5']);
        api_reveal(['token' => $tok, 'round_id' => $r1]);
        api_set_topic(['token' => $tok, 'round_id' => $r1, 'topic' => 'Login story']);
        // Start a fresh, unrevealed round — must NOT appear in recap.
        api_new_round(['code' => $code, 'token' => $tok, 'topic' => 'Next story']);

        $recap = api_recap(['code' => $code, 'token' => $tok]);
        $this->assertCount(1, $recap['rounds']);
        $this->assertSame('Login story', $recap['rounds'][0]['topic']);
        $this->assertSame(5.0, $recap['rounds'][0]['average']);
    }
}
