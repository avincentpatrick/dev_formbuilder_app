<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PdfFieldRole;
use App\Enums\PipingEligibility;

// H17's submission-PDF field classification, locked type by type. The third member of the
// OcrFieldEligibilityTest / PipingEligibilityTest family, with the same totality lock — and one
// assertion neither sibling has: PdfFieldRole::Omitted is a MIRROR of a TypeScript set, so it is
// checked against the TypeScript source rather than against a second hand-written list.
//
// No database, no container (tests/Pest.php binds TestCase to Feature only), so the repo root is
// computed from __DIR__ rather than through base_path().

/**
 * The verdict table, transcribed independently of the enum — the point is that the two are written
 * separately and must agree.
 *
 * @return array{answer: list<string>, prose: list<string>, omitted: list<string>}
 */
function pdfVerdictTable(): array
{
    return [
        // Everything the respondent could type, pick, capture or draw (27). Object-valued and
        // media types are INCLUDED here and excluded by PipingEligibility — see the divergence
        // test below.
        'answer' => [
            'short_text', 'long_text', 'email', 'phone', 'url',
            'integer', 'decimal',
            'date', 'time', 'datetime', 'duration',
            'single_select', 'multi_select', 'dropdown', 'yes_no', 'cascading_select',
            'likert_scale', 'likert_matrix', 'matrix',
            'geopoint', 'geotrace', 'geoshape',
            'file_upload', 'image_capture', 'audio_capture', 'video_capture', 'signature',
        ],
        // Visible to the respondent, carries no answer (1).
        'prose' => ['note'],
        // Never on the respondent's screen (3) — the RENDERS_NOTHING mirror.
        'omitted' => ['hidden', 'calculated', 'page_break'],
    ];
}

/** @return list<string> the backing values of every case with the given role, in catalog order */
function pdfTypesWithRole(PdfFieldRole $role): array
{
    return array_values(array_map(
        static fn (FieldType $t): string => $t->value,
        array_filter(FieldType::cases(), static fn (FieldType $t): bool => PdfFieldRole::for($t) === $role),
    ));
}

/**
 * The `RENDERS_NOTHING` members as the guest SPA actually declares them, parsed out of the
 * TypeScript source. Deliberately NOT a hand-copied list: a hand-copied list would pass forever
 * after someone edits the .ts file, which is the exact drift this test exists to catch.
 *
 * @return list<string>
 */
function rendersNothingFromTypescript(): array
{
    // Increment H21a relocated the declaration from `lib/schema-mapping.ts` down into `engine/`, because
    // the semantic validator became a second consumer of the same set (the `min_instances` step-visibility
    // narrowing, Doc #27 §4.3) and the engine must not import from `lib/`. `schema-mapping.ts` re-exports
    // `rendersNothing()`, so nothing else moved.
    $path = dirname(__DIR__, 3).'/resources/public-runtime/engine/field-roles.ts';
    $source = file_get_contents($path);

    expect($source)->toBeString("field-roles.ts must be readable at {$path}");

    $matched = preg_match(
        '/const\s+RENDERS_NOTHING\s*=\s*new\s+Set<string>\(\s*\[(?<members>[^\]]*)\]\s*\)/',
        (string) $source,
        $set,
    );

    // A rename or a shape change (a frozen array, a union type, a computed set) must FAIL here
    // rather than silently yield an empty list and let the equality assertion pass vacuously.
    expect($matched)->toBe(1, 'RENDERS_NOTHING is no longer a `new Set<string>([...])` literal in engine/field-roles.ts');

    preg_match_all("/'([a-z_]+)'/", $set['members'], $members);

    expect($members[1])->not->toBeEmpty('parsed RENDERS_NOTHING but found no members');

    return $members[1];
}

it('classifies every field type in the catalog exactly once for the PDF', function (): void {
    // The forcing mechanism's second layer. for() has no `default` arm, so a new FieldType case
    // throws UnhandledMatchError right here (PHPStan L8 reports it first, in the merge-blocking
    // static-analysis job) — and if someone adds a `default` to silence that, these three set
    // assertions still fail.
    //
    // This is the THIRD assertion of the number 31 in the suite (OcrFieldEligibilityTest and
    // PipingEligibilityTest have the others), so a 32nd field type means editing three files.
    // Deliberate: three independent policies, three owed decisions.
    $table = pdfVerdictTable();

    expect(FieldType::cases())->toHaveCount(31)
        ->and(pdfTypesWithRole(PdfFieldRole::Answer))->toEqualCanonicalizing($table['answer'])
        ->and(pdfTypesWithRole(PdfFieldRole::Prose))->toEqualCanonicalizing($table['prose'])
        ->and(pdfTypesWithRole(PdfFieldRole::Omitted))->toEqualCanonicalizing($table['omitted']);

    // 27 + 1 + 3 = 31. A case can only take one match arm, so this guards the three literal lists
    // against drifting into overlap or dropping a type, and survives someone adding a `default`.
    expect(count($table['answer']) + count($table['prose']) + count($table['omitted']))
        ->toBe(count(FieldType::cases()));
});

it('keeps Omitted byte-identical to the guest runtimes RENDERS_NOTHING set', function (): void {
    // THE drift assertion of this increment. The PDF's whole claim is "this is what the respondent
    // saw"; if PHP and TypeScript disagree about which types render, the document is lying in one
    // direction or the other. Asserted in BOTH directions — toEqualCanonicalizing fails on a
    // missing member and on an extra one alike, so neither engine can quietly widen.
    expect(pdfTypesWithRole(PdfFieldRole::Omitted))
        ->toEqualCanonicalizing(rendersNothingFromTypescript());
});

it('parses the TypeScript set rather than trusting a copy of it', function (): void {
    // Anti-vacuity for the test above. If the regex silently failed it would return [] and the
    // equality assertion would fail loudly rather than pass — but a future edit could make the
    // parse succeed against the WRONG construct, so pin what was actually read.
    expect(rendersNothingFromTypescript())->toHaveCount(3)
        ->toContain('hidden')->toContain('calculated')->toContain('page_break');
});

it('renders a note that the inbox drops', function (): void {
    // The one place PdfFieldRole deliberately disagrees with SchemaValueFormatter::NON_DATA.
    // An inbox lists answers, so it drops `note`. A PDF is a record of what the respondent was
    // shown, and a consent paragraph they were shown belongs in it.
    expect(PdfFieldRole::for(FieldType::Note))->toBe(PdfFieldRole::Prose)
        ->and(PdfFieldRole::for(FieldType::PageBreak))->toBe(PdfFieldRole::Omitted);
});

it('omits exactly the two types piping deliberately widened to', function (): void {
    // The clean statement of why this is not a reuse of PipingEligibility. `hidden` and
    // `calculated` are the two types H6a widened INTO Pipeable, for the same underlying reason
    // they are omitted here: their values exist without the respondent doing anything. One rule
    // could not serve both — it would have to be wrong for one of them.
    $pipeableButUnseen = array_values(array_filter(
        FieldType::cases(),
        static fn (FieldType $t): bool => PipingEligibility::for($t) === PipingEligibility::Pipeable
            && PdfFieldRole::for($t) === PdfFieldRole::Omitted,
    ));

    expect(array_map(static fn (FieldType $t): string => $t->value, $pipeableButUnseen))
        ->toEqualCanonicalizing(['calculated', 'hidden']);
});

it('keeps object-valued and media answers that piping excludes', function (): void {
    // The divergence in the other direction, and the reason the two enums cannot share a rule.
    // A hole in a sentence needs a scalar, so PipingEligibility excludes grids, geo and media.
    // A PDF row has a whole cell and the respondent demonstrably saw the control, so it keeps
    // them — how each one renders is displayValue()'s problem, not a visibility question.
    foreach (FieldType::cases() as $type) {
        if ($type->isComposite() || $type->isGeo() || $type->isMedia()) {
            expect(PdfFieldRole::for($type))->toBe(
                PdfFieldRole::Answer,
                "{$type->value} was on the respondent's screen and must appear in the PDF",
            );
            expect(PipingEligibility::for($type))->toBe(PipingEligibility::Excluded);
        }
    }
});
