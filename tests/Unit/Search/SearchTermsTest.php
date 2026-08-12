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

it('recognises a submission id only from a FULL canonical uuid', function (string $raw, ?string $expected): void {
    // ⚠️ REWRITTEN IN J2e, AND THE OLD VERSION WENT RED BY DESIGN. This used to accept any hex run of eight
    // characters or more as a `uuidPrefix()`. It no longer does, because the consumer changed from
    // `id::text LIKE ?` — where a partial value merely matched a ~49-day window of rows — to `id = ?`, where
    // a non-uuid raises 22P02 and surfaces as a 500 on an Inertia GET.
    expect(SearchTerms::parse($raw)->submissionId())->toBe($expected);
})->with([
    'full uuid' => ['0198a3c1-2b3c-7def-8123-456789abcdef', '0198a3c1-2b3c-7def-8123-456789abcdef'],
    'uppercase full uuid is lowered' => ['0198A3C1-2B3C-7DEF-8123-456789ABCDEF', '0198a3c1-2b3c-7def-8123-456789abcdef'],
    // Every one of these reached a binding before J2e. Each is now a 22P02 the parser refuses to cause.
    'first group alone' => ['0198a3c1', null],
    'group and a half' => ['0198a3c1-2b3c', null],
    'one character short of canonical' => ['0198a3c1-2b3c-7def-8123-456789abcde', null],
    'de-hyphenated uuid' => ['0198a3c12b3c7def8123456789abcdef', null],
    'hex-looking word' => ['deadbeef', null],
    'not hex' => ['clinic-intake', null],
    'uuid with a trailing word' => ['0198a3c1-2b3c-7def-8123-456789abcdef intake', null],
]);

it('does not constrain the uuid version nibble', function (): void {
    // A support ticket may quote any id this system has ever issued. Pinning v7 here would refuse a
    // pre-uuidv7 row's own id — a lookup that must keep working precisely because it is the fallback when
    // the short reference is not to hand.
    expect(SearchTerms::parse('0198a3c1-2b3c-4def-8123-456789abcdef')->submissionId())
        ->toBe('0198a3c1-2b3c-4def-8123-456789abcdef');
});

it('recognises a submission reference, and keeps it disjoint from a submission id', function (string $raw, ?string $expected): void {
    expect(SearchTerms::parse($raw)->referenceCandidate())->toBe($expected);
})->with([
    'stored form' => ['7K4M2QXB', '7K4M2QXB'],
    'displayed form' => ['7K4M-2QXB', '7K4M2QXB'],
    'lowercase' => ['7k4m-2qxb', '7K4M2QXB'],
    // Hex is a strict SUBSET of Crockford Base32, so an 8-character hex string is a valid reference too —
    // which is exactly why the two parsers discriminate by LENGTH rather than by alphabet.
    'hex-looking word is a valid reference' => ['deadbeef', 'DEADBEEF'],
    'a full uuid is 36 characters, never 8' => ['0198a3c1-2b3c-7def-8123-456789abcdef', null],
    'a de-hyphenated uuid is 32 characters' => ['0198a3c12b3c7def8123456789abcdef', null],
    'seven characters' => ['7K4M2QX', null],
    'contains U' => ['7K4MUQXB', null],
]);

it('never returns both a submission id and a reference for one query', function (string $raw): void {
    // The disjointness claim, asserted rather than described. If it ever failed, `scopeMatchingKeyword`
    // would OR two identity predicates for a single pasted string.
    $terms = SearchTerms::parse($raw);

    expect($terms->submissionId() !== null && $terms->referenceCandidate() !== null)->toBeFalse();
})->with([
    'a reference' => '7K4M-2QXB',
    'a full uuid' => '0198a3c1-2b3c-7def-8123-456789abcdef',
    'hex that fits a reference' => 'deadbeef',
    'ordinary words' => 'clinic intake',
]);
