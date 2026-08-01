<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Submissions\DraftCompleteness;

/*
|--------------------------------------------------------------------------
| Increment H9a — the draft progress indicator. Pure + relevance-unaware: percent = answered / answerable
| over the Stage-1 normalized answers, where display-only / calculated / hidden fields are excluded and each
| repeatable section counts as one unit. No container / DB (unsaved models; casts apply — see Pest.php).
|--------------------------------------------------------------------------
*/

it('is 100 when every answerable field is answered', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::Integer]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x', 'b' => '3']))->toBe(100);
});

it('is 50 when half the answerable fields are answered', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::ShortText]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x']))->toBe(50);
});

it('rounds 1 of 3 to 33', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'b', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'c', 'field_type' => FieldType::ShortText]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['b' => 'x']))->toBe(33);
});

it('excludes note, page break, calculated, and hidden fields from the denominator', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'intro', 'field_type' => FieldType::Note]),
        makeSchemaField(['key' => 'brk', 'field_type' => FieldType::PageBreak]),
        makeSchemaField(['key' => 'total', 'field_type' => FieldType::Calculated]),
        makeSchemaField(['key' => 'ref', 'field_type' => FieldType::Hidden]),
    ]);

    // Only `a` is answerable → answering it is 100%, even though the hidden `ref` also has a stored value.
    expect(DraftCompleteness::of($fields, collect(), ['a' => 'x', 'ref' => 'server-set']))->toBe(100)
        ->and(DraftCompleteness::of($fields, collect(), ['ref' => 'server-set']))->toBe(0);
});

it('counts a repeatable section as one unit, folding in its member fields', function (): void {
    $section = makeSchemaSection(['id' => 'sec1', 'key' => 'hh', 'is_repeatable' => true]);
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'm1', 'field_type' => FieldType::ShortText, 'form_section_id' => 'sec1']),
        makeSchemaField(['key' => 'm2', 'field_type' => FieldType::ShortText, 'form_section_id' => 'sec1']),
    ]);
    $sections = collect([$section]);

    // Denominator is 2 (the top-level `a` + the one section unit), NOT 3 — members do not count individually.
    expect(DraftCompleteness::of($fields, $sections, ['a' => 'x', 'hh' => [['m1' => 'v']]]))->toBe(100)
        ->and(DraftCompleteness::of($fields, $sections, ['a' => 'x']))->toBe(50)
        ->and(DraftCompleteness::of($fields, $sections, ['hh' => [['m1' => 'v']]]))->toBe(50);
});

it('returns 0 rather than dividing by zero when there are no answerable units', function (): void {
    $fields = collect([
        makeSchemaField(['key' => 'intro', 'field_type' => FieldType::Note]),
        makeSchemaField(['key' => 'brk', 'field_type' => FieldType::PageBreak]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), []))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Increment H21a — the denominator, pinned (Doc #27 §5.2 and §9).
|--------------------------------------------------------------------------
| `data-dictionary.md` stated no denominator for `completeness_percent` at all; Doc #27 §5.2 pins it HERE:
| every top-level field whose type is not `note`, `page_break`, `calculated` or `hidden`, plus EXACTLY ONE
| unit per repeatable section regardless of instance count, counted over the Stage-1 normalized answers, with
| key presence (not non-emptiness) as the numerator.
|
| It stays RELEVANCE-UNAWARE, and that is a decision rather than an omission: making it relevance-aware would
| run the full `SemanticValidator` on every draft autosave — on field blur, on step navigation and on the
| 30-second backstop — which is exactly why this class is documented as pure and static with no container, no
| database and no expression engine. The tenant-facing consequence is worse than the cost: the inbox column
| would become "% of the path this particular respondent happens to be on", which is NOT comparable between
| two rows, the one property a reviewer's column actually needs.
|
| §9 asks for each exclusion to be pinned INDEPENDENTLY because `isAnswerable()` is a `match` with a
| `default => true` arm — the H8 hazard, which will absorb a 32nd `FieldType` as answerable silently. It is
| left unfixed by construction (a `default`-less match here would make every new field type a decision about
| draft progress, which is not where that decision belongs) and is therefore owed a test.
*/

it('excludes each display-only, computed and hidden type independently', function (): void {
    // Independently, not as a set: a single mixed fixture would stay green if any ONE arm of the match were
    // deleted, because the others would still carry the assertion.
    $excluded = [FieldType::Note, FieldType::PageBreak, FieldType::Calculated, FieldType::Hidden];

    foreach ($excluded as $type) {
        $fields = collect([
            makeSchemaField(['key' => 'answerable', 'field_type' => FieldType::ShortText]),
            makeSchemaField(['key' => 'excluded', 'field_type' => $type]),
        ]);

        // The excluded field is UNANSWERED, so if it counted the result would be 50 rather than 100.
        expect(DraftCompleteness::of($fields, collect(), ['answerable' => 'x']))
            ->toBe(100, "{$type->value} must not be counted in the completeness denominator");
    }
});

it('counts an ordinary field type, so the exclusions above are not vacuous', function (): void {
    // The anti-vacuity twin: a `isAnswerable()` that returned false for EVERYTHING would satisfy every
    // assertion above and return 0 here.
    $fields = collect([
        makeSchemaField(['key' => 'answerable', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'other', 'field_type' => FieldType::Date]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['answerable' => 'x']))->toBe(50);
});

it('counts a repeatable section as exactly one unit however many instances it holds', function (): void {
    $sections = collect([makeSchemaSection(['id' => 's_hh', 'key' => 'hh', 'is_repeatable' => true])]);
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'member_name', 'field_type' => FieldType::ShortText, 'form_section_id' => 's_hh']),
        makeSchemaField(['key' => 'member_age', 'field_type' => FieldType::Integer, 'form_section_id' => 's_hh']),
    ]);

    // Denominator is 2 (the flat field + ONE unit for `hh`), not 3 and not 1-per-instance. Three instances
    // must not move it, or a long roster would dominate the number.
    $three = ['a' => 'x', 'hh' => [
        ['member_name' => 'Ana'], ['member_name' => 'Ben'], ['member_name' => 'Cal'],
    ]];
    $one = ['a' => 'x', 'hh' => [['member_name' => 'Ana']]];

    expect(DraftCompleteness::of($fields, $sections, $three))->toBe(100);
    expect(DraftCompleteness::of($fields, $sections, $one))->toBe(100);
    // An empty roster leaves the unit unanswered, so the section is half of the denominator.
    expect(DraftCompleteness::of($fields, $sections, ['a' => 'x', 'hh' => []]))->toBe(50);
});

it('never counts a repeat member on its own', function (): void {
    // The member fields fold into their section's single unit. If they were counted individually, a
    // two-member roster would make the denominator 3 and this would be 33 rather than 50.
    $sections = collect([makeSchemaSection(['id' => 's_hh', 'key' => 'hh', 'is_repeatable' => true])]);
    $fields = collect([
        makeSchemaField(['key' => 'a', 'field_type' => FieldType::ShortText]),
        makeSchemaField(['key' => 'member_name', 'field_type' => FieldType::ShortText, 'form_section_id' => 's_hh']),
        makeSchemaField(['key' => 'member_age', 'field_type' => FieldType::Integer, 'form_section_id' => 's_hh']),
    ]);

    expect(DraftCompleteness::of($fields, $sections, ['a' => 'x']))->toBe(50);
});

it('stays relevance-unaware, so an irrelevant field still counts against the total', function (): void {
    // §5.2's knock-on, asserted so nobody "fixes" the disagreement later: `completeness_percent` is COVERAGE
    // OF THE AUTHORED FORM, not position on the taken path. A respondent on a short branch is legitimately at
    // step 3 of 3 and 50% coverage — which is why the respondent-facing "you're N% complete" copy is dropped
    // and the number stays on tenant surfaces, where "% of the authored form" is what a reviewer wants.
    $fields = collect([
        makeSchemaField(['key' => 'gate', 'field_type' => FieldType::ShortText]),
        makeSchemaField([
            'key' => 'never_shown', 'field_type' => FieldType::ShortText,
            'relevant_expression' => '${gate} = \'yes\'',
        ]),
    ]);

    expect(DraftCompleteness::of($fields, collect(), ['gate' => 'no']))->toBe(50);
});
