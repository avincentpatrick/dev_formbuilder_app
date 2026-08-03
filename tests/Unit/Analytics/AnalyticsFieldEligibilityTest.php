<?php

declare(strict_types=1);

use App\Enums\AnalyticsAxis;
use App\Enums\AnalyticsFieldEligibility;
use App\Enums\AnalyticsFieldRefusal;
use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Models\FormField;
use App\Support\Analytics\ReportableFields;

/*
|--------------------------------------------------------------------------
| ADR-0011 §D3's type axis. Unit — no container, no DB.
|
| The failure this guards is silent by construction: a 32nd FieldType that inherits "indexable" would make
| the field picker offer a question that can never produce a row, which is exactly the false promise §D3(ii)
| says the authoring surface must refuse to make.
*/

it('classifies every one of the 31 field types', function (): void {
    // Totality, asserted rather than trusted to the `match`. If a case were unclassified this would throw
    // UnhandledMatchError — and PHPStan level 8 would already have failed the merge — but the count also
    // catches the opposite mistake: a type quietly REMOVED from the catalog while a consumer still expects it.
    $classified = array_map(
        static fn (FieldType $t): AnalyticsFieldEligibility => AnalyticsFieldEligibility::for($t),
        FieldType::cases()
    );

    expect($classified)->toHaveCount(31)
        ->and(FieldType::cases())->toHaveCount(31);
});

it('treats likert_scale as indexable and both grid types as not', function (): void {
    // ADR-0011 calls this out TWICE as the thing people get wrong. A likert scale's answer is a single
    // chosen value, and the rest of the codebase already agrees (`hasOptions()` includes it; XlsformTypeMap
    // maps it to select_one). It is the two GRID types that are object-valued.
    expect(AnalyticsFieldEligibility::for(FieldType::LikertScale))->toBe(AnalyticsFieldEligibility::Indexable)
        ->and(AnalyticsFieldEligibility::for(FieldType::LikertMatrix))->toBe(AnalyticsFieldEligibility::Structured)
        ->and(AnalyticsFieldEligibility::for(FieldType::Matrix))->toBe(AnalyticsFieldEligibility::Structured);
});

it('excludes multi_select and cascading_select, which look scalar to an author and are not', function (): void {
    // multi_select stores a LIST even when one option is chosen; cascading_select stores the resolved path.
    // Both reach AnswerIndexProjector's is_array guard and are dropped.
    expect(AnalyticsFieldEligibility::for(FieldType::MultiSelect))->toBe(AnalyticsFieldEligibility::Structured)
        ->and(AnalyticsFieldEligibility::for(FieldType::CascadingSelect))->toBe(AnalyticsFieldEligibility::Structured);
});

it('gives the three geo types their own case, because they are excluded but NOT unindexed', function (): void {
    // §D3 verbatim: "The three geo types are excluded from this index but are not unindexed: they have their
    // own GiST-indexed submission_geo_index." Telling an author a location question "cannot be reported on"
    // would therefore be false, which is why this is not folded into Structured.
    foreach ([FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape] as $type) {
        expect(AnalyticsFieldEligibility::for($type))->toBe(AnalyticsFieldEligibility::Geospatial);
    }
});

it('counts hidden and calculated as indexable, classified by what the projector actually does', function (): void {
    // Neither feels like respondent data, and both project. `hidden` stores an ordinary scalar and the
    // projector never asks where a value came from; `calculated` reaches it because SubmissionFinalizer
    // merges $result->computed into the answer map BEFORE projecting.
    expect(AnalyticsFieldEligibility::for(FieldType::Hidden))->toBe(AnalyticsFieldEligibility::Indexable)
        ->and(AnalyticsFieldEligibility::for(FieldType::Calculated))->toBe(AnalyticsFieldEligibility::Indexable);
});

it('classifies the five media types as structured, never as indexable', function (): void {
    foreach ([
        FieldType::FileUpload, FieldType::ImageCapture,
        FieldType::AudioCapture, FieldType::VideoCapture, FieldType::Signature,
    ] as $type) {
        expect(AnalyticsFieldEligibility::for($type))->toBe(AnalyticsFieldEligibility::Structured);
    }
});

/*
|--------------------------------------------------------------------------
| ReportableFields — the type axis composed with the three facts no field type carries.
*/

/** A detached model: no DB, no container. Only the four attributes the resolver reads. */
function analyticsField(FieldType $type, bool $queryable = true, ?IndexedDataType $indexed = IndexedDataType::Text): FormField
{
    $field = new FormField;
    $field->field_type = $type;
    $field->is_queryable = $queryable;
    $field->indexed_data_type = $indexed;

    return $field;
}

it('reports a flagged, typed, top-level scalar', function (): void {
    expect(ReportableFields::refusalFor(analyticsField(FieldType::ShortText), false))->toBeNull()
        ->and(ReportableFields::isReportable(analyticsField(FieldType::ShortText), false))->toBeTrue();
});

it('refuses a scalar nested in a repeatable section, however it is configured', function (): void {
    // SubmissionFinalizer::projectIndex() iterates TOP-LEVEL answer keys and `continue`s on any array, so a
    // repeat group's instance array is never descended into. The member is unreachable even when flagged
    // and scalar — which is why this is checked BEFORE the flag: offering "tick Indexed for reporting" as
    // the remedy would be advice that cannot work.
    $field = analyticsField(FieldType::ShortText, queryable: true, indexed: IndexedDataType::Text);

    expect(ReportableFields::refusalFor($field, true))->toBe(AnalyticsFieldRefusal::InRepeatableSection);
});

it('puts the type refusal ahead of the flag, so a grid is never told to tick a box', function (): void {
    // The false promise §D3(ii) forbids, in its most likely concrete form: an unflagged grid must report
    // StructuredAnswer, not NotFlagged, or the authoring surface would suggest a remedy that yields nothing.
    $grid = analyticsField(FieldType::Matrix, queryable: false, indexed: null);

    expect(ReportableFields::refusalFor($grid, false))->toBe(AnalyticsFieldRefusal::StructuredAnswer);
});

it('distinguishes not-flagged from flagged-without-a-type', function (): void {
    // NotFlagged is the DEFAULT and is why an answer-value chart is empty for essentially every form alive
    // today. NoIndexedType is reachable only on an unpublished draft (StructuralValidationGate refuses it at
    // publish) — reported rather than assumed impossible.
    expect(ReportableFields::refusalFor(analyticsField(FieldType::Integer, queryable: false, indexed: null), false))
        ->toBe(AnalyticsFieldRefusal::NotFlagged)
        ->and(ReportableFields::refusalFor(analyticsField(FieldType::Integer, queryable: true, indexed: null), false))
        ->toBe(AnalyticsFieldRefusal::NoIndexedType);
});

it('gives every refusal a distinct author-facing sentence', function (): void {
    // The picker renders one of these when it cannot offer a question. Two cases sharing a sentence would
    // make the surface say the same thing about two different problems.
    $labels = array_map(static fn (AnalyticsFieldRefusal $r): string => $r->label(), AnalyticsFieldRefusal::cases());

    expect($labels)->toHaveCount(count(array_unique($labels)))
        ->and($labels)->each->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| AnalyticsAxis — the closed list of grouping axes (§D6).
*/

it('marks exactly the nullable axes as needing an Unassigned bucket', function (): void {
    // §D6: scope_node_id is NULL for every form until explicitly assigned, so a grouped result without an
    // explicit bucket is silently short. `submissions.locale` is nullable too. The other three are NOT NULL.
    $needing = array_values(array_filter(
        AnalyticsAxis::cases(),
        static fn (AnalyticsAxis $a): bool => $a->hasUnassignedBucket()
    ));

    expect($needing)->toBe([AnalyticsAxis::ScopeNode, AnalyticsAxis::Locale]);
});

it('routes only the scope-node axis through the forms join', function (): void {
    // Every other axis is a `submissions` column, which keeps the common breakdowns off the join entirely.
    foreach (AnalyticsAxis::cases() as $axis) {
        expect($axis->groupsThroughForm())->toBe($axis === AnalyticsAxis::ScopeNode);
        expect($axis->column())->toStartWith($axis->groupsThroughForm() ? 'forms.' : 'submissions.');
    }
});
