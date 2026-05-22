<?php
declare(strict_types=1);

/**
 * Reveal statistics — pure functions, no I/O (PLAN §3a, §6).
 *
 * Everything here takes plain arrays and returns plain arrays. No SQL, no
 * globals, no echo. This is the most heavily unit-tested file because it is
 * the cheapest to test and the easiest to get subtly wrong.
 */

/**
 * Is a deck value numeric for averaging purposes? `?` and `☕` are not.
 * Accepts plain integer and decimal strings only (e.g. "0", "5", "0.5"). Unlike
 * is_numeric() this deliberately rejects scientific notation ("1e3"), signs,
 * and surrounding whitespace — those are author-supplied labels that must not be
 * silently pulled into the average or treated as different from their trimmed
 * form by the (string-based) consensus check.
 */
function stats_is_numeric(string $value): bool
{
    return preg_match('/^\d+(\.\d+)?$/', $value) === 1;
}

/**
 * Compute the reveal results for a set of cast votes.
 *
 * @param array<int,string> $castVotes participant_id => value (only participants
 *        who actually voted; non-voters are excluded by the caller).
 * @param array<int,string> $votingOptions the room deck, in display order — used
 *        for the deterministic tie-break and the wide-spread step calc.
 * @return array{
 *   average: float|null,
 *   consensus: bool,
 *   leading: string|null,
 *   range: array{min: float, max: float}|null,
 *   distribution: list<array{value: string, count: int}>,
 *   wide_spread: bool,
 *   unsure_count: int
 * }
 */
function compute_results(array $castVotes, array $votingOptions): array
{
    $values = array_values($castVotes);

    // Distribution: count per distinct value, in deck order where possible so
    // the bars render stably. Unknown values (shouldn't happen post-validation)
    // are appended in first-seen order.
    $distribution = build_distribution($values, $votingOptions);

    // Numeric subset drives average / range / wide_spread.
    $numbers = [];
    foreach ($values as $v) {
        if (stats_is_numeric($v)) {
            $numbers[] = (float) $v;
        }
    }

    $average = null;
    if (count($numbers) > 0) {
        $average = round(array_sum($numbers) / count($numbers), 1);
    }

    $range = null;
    $wideSpread = false;
    if (count($numbers) > 0) {
        $min = min($numbers);
        $max = max($numbers);
        $range = ['min' => $min, 'max' => $max];
        $wideSpread = is_wide_spread($min, $max, $votingOptions);
    }

    // Consensus: every cast vote identical (across ALL values, numeric or not).
    $consensus = count($values) > 0 && count(array_unique($values)) === 1;

    $leading = leading_value($distribution, $votingOptions);

    $unsureCount = $distribution['?'] ?? 0;

    // Emit distribution as an ordered LIST of {value,count}, not a value=>count
    // map: PHP recasts numeric-string keys to ints, so a 0-based numeric deck
    // (e.g. "0","1","2") would json_encode as an array and lose the labels.
    $distributionList = [];
    foreach ($distribution as $value => $count) {
        $distributionList[] = ['value' => (string) $value, 'count' => $count];
    }

    return [
        'average' => $average,
        'consensus' => $consensus,
        'leading' => $leading,
        'range' => $range,
        'distribution' => $distributionList,
        'wide_spread' => $wideSpread,
        'unsure_count' => $unsureCount,
    ];
}

/**
 * Count per distinct value. Keys are emitted in deck order first (so the UI
 * gets a stable bar order), then any leftover values in first-seen order.
 *
 * @param array<int,string> $values
 * @param array<int,string> $votingOptions
 * @return array<string,int>
 */
function build_distribution(array $values, array $votingOptions): array
{
    $counts = [];
    foreach ($values as $v) {
        $counts[$v] = ($counts[$v] ?? 0) + 1;
    }

    $ordered = [];
    foreach ($votingOptions as $opt) {
        if (isset($counts[$opt])) {
            $ordered[$opt] = $counts[$opt];
            unset($counts[$opt]);
        }
    }
    // Anything not in the deck (defensive) keeps first-seen order.
    foreach ($counts as $v => $c) {
        $ordered[$v] = $c;
    }
    return $ordered;
}

/**
 * The leading estimate = the mode. Tie-break (PLAN §6, deterministic): when two
 * values tie for most-frequent, the one with the LOWER index in voting_options
 * wins (conservative estimate). Values not in the deck sort last.
 *
 * @param array<string,int> $distribution
 * @param array<int,string> $votingOptions
 */
function leading_value(array $distribution, array $votingOptions): ?string
{
    if (count($distribution) === 0) {
        return null;
    }

    $deckIndex = array_flip(array_values($votingOptions));
    $big = count($votingOptions); // sentinel for off-deck values

    $best = null;
    $bestCount = -1;
    $bestRank = PHP_INT_MAX;

    foreach ($distribution as $value => $count) {
        $value = (string) $value;
        $rank = $deckIndex[$value] ?? $big;
        if ($count > $bestCount || ($count === $bestCount && $rank < $bestRank)) {
            $best = $value;
            $bestCount = $count;
            $bestRank = $rank;
        }
    }

    return $best;
}

/**
 * "Wide spread" = min and max are more than two deck steps apart (PLAN §6),
 * measured by their positions in the deck. Falls back to a numeric heuristic
 * if either endpoint isn't a deck member.
 *
 * @param array<int,string> $votingOptions
 */
function is_wide_spread(float $min, float $max, array $votingOptions): bool
{
    if ($min === $max) {
        return false;
    }

    // Map numeric deck values to their positions.
    $numericPositions = [];
    foreach (array_values($votingOptions) as $i => $opt) {
        if (stats_is_numeric($opt)) {
            $numericPositions[(string) (float) $opt] = $i;
        }
    }

    $minKey = (string) $min;
    $maxKey = (string) $max;
    if (isset($numericPositions[$minKey], $numericPositions[$maxKey])) {
        return abs($numericPositions[$maxKey] - $numericPositions[$minKey]) > 2;
    }

    // Endpoints not both on the deck: fall back to "max more than double min".
    return $min > 0 ? ($max / $min) >= 3 : true;
}
