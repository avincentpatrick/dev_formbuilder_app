<?php

declare(strict_types=1);

use App\Support\Search\SearchTerms;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Increment J1b — the query sanitiser.
 *
 * ⚠️ EVERY PARSED QUERY IS FED TO A LIVE `to_tsquery`, NOT COMPARED TO A HAND-WRITTEN EXPECTATION.
 * That is the point of the file. A hand-built assertion (`expect($t->tsQuery())->toBe('a & b:*')`) passes
 * happily against a string Postgres would reject, and the failure it misses is not cosmetic: an unparseable
 * tsquery raises SQLSTATE 42601, which surfaces as a **500 on an Inertia GET** the moment a user types `&`
 * into the search box. Driving the real path is the only way this file can fail for the right reason — the
 * rule I12 paid for ("when a hand-built fixture and a real code path can disagree, write one case that
 * drives the real path").
 *
 * Measured, not assumed: `to_tsquery('simple', 'foo &')` really does raise 42601, and `to_tsquery('simple',
 * '')` really is safe and returns an empty query. Both were confirmed against this deployment's PostgreSQL
 * before these cases were written.
 */
uses(TestCase::class);

/** Runs the parsed query through PostgreSQL. Returns null when there is nothing to run. */
function tsQueryIsAccepted(string $raw): ?bool
{
    $ts = SearchTerms::parse($raw)->tsQuery();

    if ($ts === '') {
        return null;
    }

    try {
        DB::connection('pgsql_privileged')->select('select to_tsquery(?, ?)', ['simple', $ts]);

        return true;
    } catch (Throwable) {
        return false;
    }
}

it('produces a query PostgreSQL accepts for every hostile input', function (string $raw): void {
    // null means "sanitised down to nothing", which is a pass: an empty tsquery is never sent.
    expect(tsQueryIsAccepted($raw))->not->toBe(false);
})->with([
    'plain word' => 'clinic',
    'two words' => 'clinic intake',
    'bare ampersand' => 'a & b',
    'every tsquery operator' => 'a & | ! ( ) : * < > b',
    'unbalanced parens' => '((',
    'lone operator' => '&',
    'quotes' => "o'brien \"quoted\"",
    'backslashes' => 'a\\\\b',
    'sql-looking' => "'; drop table forms; --",
    'unicode' => 'Ñuñez São Tomé',
    'non-latin' => 'Ang Bagong Pilipinas',
    'digits' => '2026 08 09',
    'underscores' => 'form_key_1',
    'emoji' => 'clinic 🏥 intake',
    'very long' => 'a-very-long-query-'.str_repeat('x', 400),
    'whitespace only' => '     ',
    'empty' => '',
]);

it('strips every tsquery operator rather than escaping it', function (): void {
    // Mutation: delete the character-class filter in parse() and this reddens — the operators survive into
    // tsQuery() and the live call above starts raising.
    $terms = SearchTerms::parse('a & | ! ( ) b');

    expect($terms->tsQuery())->toBe('a & b:*');
});

it('prefix-matches the LAST token only', function (): void {
    // Every token prefixed would make "cl in" match almost everything; none prefixed breaks type-ahead,
    // which is the whole reason `to_tsquery` was chosen over `websearch_to_tsquery`.
    expect(SearchTerms::parse('clinic inta')->tsQuery())->toBe('clinic & inta:*');
});

it('keeps stopwords, which is why the regconfig is simple', function (): void {
    // Under 'english' this query indexes to nothing and a form titled "The A Team" becomes unfindable by its
    // own title. Verified against PostgreSQL: to_tsvector('english','The A Team') is just 'team'.
    expect(SearchTerms::parse('The A Team')->tsQuery())->toBe('the & a & team:*');
});

it('caps the raw length, the token count and each token', function (): void {
    $terms = SearchTerms::parse(str_repeat('word ', 40));

    expect(substr_count($terms->tsQuery(), '&'))->toBe(SearchTerms::MAX_TOKENS - 1)
        ->and(mb_strlen((string) $terms->raw()))->toBeLessThanOrEqual(SearchTerms::MAX_RAW_LENGTH);

    $long = SearchTerms::parse(str_repeat('x', 200));
    expect(mb_strlen(str_replace(':*', '', $long->tsQuery())))->toBe(SearchTerms::MAX_TOKEN_LENGTH);
});

it('treats whitespace-only as no query at all', function (): void {
    // Collapsed with empty on purpose: the presenter gets ONE "no query" branch rather than two that can
    // drift apart, and `no_query` vs `no_matches` stay meaningful.
    $terms = SearchTerms::parse("  \t \n ");

    expect($terms->isEmpty())->toBeTrue()
        ->and($terms->raw())->toBeNull();
});

it('recognises a uuid prefix only from eight hex characters up', function (string $raw, ?string $expected): void {
    expect(SearchTerms::parse($raw)->uuidPrefix())->toBe($expected);
})->with([
    'full uuid' => ['0198a3c1-2b3c-7def-8123-456789abcdef', '0198a3c1-2b3c-7def-8123-456789abcdef'],
    'first group' => ['0198a3c1', '0198a3c1'],
    'group and a half' => ['0198a3c1-2b3c', '0198a3c1-2b3c'],
    'seven chars is too short' => ['0198a3c', null],
    'hex-looking word' => ['deadbeef', 'deadbeef'],
    'not hex' => ['clinic-intake', null],
    'hex with a trailing word' => ['0198a3c1 intake', null],
]);

it('derives the uuid prefix from the raw string, not the tokens', function (): void {
    // The tokeniser splits on hyphens, so a token-derived prefix would lose the hyphen position — and the
    // hyphen is exactly what makes a prefix longer than eight characters match `id::text`.
    $terms = SearchTerms::parse('0198a3c1-2b3c');

    expect($terms->uuidPrefix())->toBe('0198a3c1-2b3c')
        ->and($terms->tsQuery())->toBe('0198a3c1 & 2b3c:*');
});
