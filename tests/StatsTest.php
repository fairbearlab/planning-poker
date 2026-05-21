<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure stats unit tests (PLAN §10, §6). Highest ROI: no DB, no I/O.
 */
final class StatsTest extends TestCase
{
    /** Modified Fibonacci deck used across most cases. */
    private const DECK = ['1', '2', '3', '5', '8', '13', '21', '?', '☕'];

    public function testAverageOfMixedNumericIgnoresQuestionAndCoffee(): void
    {
        // 2,3,5 numeric + ? + ☕ → mean(2,3,5)=3.3
        $votes = [1 => '2', 2 => '3', 3 => '5', 4 => '?', 5 => '☕'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame(3.3, $r['average']);
    }

    public function testAverageNullWhenNoNumericVotes(): void
    {
        $votes = [1 => '?', 2 => '☕'];
        $r = compute_results($votes, self::DECK);
        $this->assertNull($r['average']);
    }

    public function testConsensusWhenAllIdentical(): void
    {
        $votes = [1 => '5', 2 => '5', 3 => '5'];
        $r = compute_results($votes, self::DECK);
        $this->assertTrue($r['consensus']);
        $this->assertSame('5', $r['leading']);
    }

    public function testNoConsensusWhenSplit(): void
    {
        $votes = [1 => '3', 2 => '5', 3 => '8'];
        $r = compute_results($votes, self::DECK);
        $this->assertFalse($r['consensus']);
    }

    public function testLeadingIsTheMode(): void
    {
        $votes = [1 => '5', 2 => '5', 3 => '8'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame('5', $r['leading']);
    }

    public function testTieBreakPicksLowerDeckValue(): void
    {
        // 3 and 8 each appear twice; lower deck index (3) wins.
        $votes = [1 => '3', 2 => '3', 3 => '8', 4 => '8'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame('3', $r['leading']);
    }

    public function testRangeIsMinMaxOfNumericVotes(): void
    {
        $votes = [1 => '3', 2 => '5', 3 => '13', 4 => '?'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame(['min' => 3.0, 'max' => 13.0], $r['range']);
    }

    public function testWideSpreadFlaggedWhenDeckStepsFarApart(): void
    {
        // 3 (index 2) and 21 (index 6) are 4 deck steps apart → wide.
        $votes = [1 => '3', 2 => '21'];
        $r = compute_results($votes, self::DECK);
        $this->assertTrue($r['wide_spread']);
    }

    public function testNotWideSpreadWhenAdjacentDeckValues(): void
    {
        // 3 (index 2) and 5 (index 3): one step apart → not wide.
        $votes = [1 => '3', 2 => '5'];
        $r = compute_results($votes, self::DECK);
        $this->assertFalse($r['wide_spread']);
    }

    public function testDistributionCountsPerValue(): void
    {
        $votes = [1 => '5', 2 => '5', 3 => '8', 4 => '?'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame(['5' => 2, '8' => 1, '?' => 1], $r['distribution']);
    }

    public function testDistributionKeysFollowDeckOrder(): void
    {
        $votes = [1 => '8', 2 => '1', 3 => '5'];
        $r = compute_results($votes, self::DECK);
        // Deck order is 1,5,8 regardless of vote arrival order. (PHP casts
        // numeric-string keys to int; normalize before comparing — the JSON
        // wire form is still an object keyed by these values.)
        $keys = array_map('strval', array_keys($r['distribution']));
        $this->assertSame(['1', '5', '8'], $keys);
    }

    public function testUnsureCountReflectsQuestionMarks(): void
    {
        $votes = [1 => '5', 2 => '?', 3 => '?'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame(2, $r['unsure_count']);
    }

    public function testSingleVoterIsConsensus(): void
    {
        $votes = [1 => '8'];
        $r = compute_results($votes, self::DECK);
        $this->assertTrue($r['consensus']);
        $this->assertSame(8.0, $r['average']);
        $this->assertSame('8', $r['leading']);
        $this->assertFalse($r['wide_spread']);
    }

    public function testEmptyVotesAreSafe(): void
    {
        $r = compute_results([], self::DECK);
        $this->assertNull($r['average']);
        $this->assertFalse($r['consensus']);
        $this->assertNull($r['leading']);
        $this->assertNull($r['range']);
        $this->assertSame([], $r['distribution']);
        $this->assertFalse($r['wide_spread']);
        $this->assertSame(0, $r['unsure_count']);
    }

    public function testCoffeeUnicodeSurvivesDistribution(): void
    {
        // Encoding regression guard (PLAN §9): non-ASCII deck value round-trips.
        $votes = [1 => '☕', 2 => '☕', 3 => '5'];
        $r = compute_results($votes, self::DECK);
        $this->assertSame(2, $r['distribution']['☕']);
        $this->assertStringContainsString('☕', json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function testHalfPointDecimalDeckAverages(): void
    {
        $deck = ['0.5', '1', '2', '3', '?'];
        $votes = [1 => '0.5', 2 => '1', 3 => '2'];
        $r = compute_results($votes, $deck);
        $this->assertSame(1.2, $r['average']); // mean(0.5,1,2)=1.166→1.2
    }
}
