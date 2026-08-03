<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a specific question cannot be aggregated by value (ADR-0011 §D3, Increment H24a).
 *
 * This is the API contract behind §D3's central obligation: H24b's field picker must **encode** the three
 * properties of the typed answer index rather than discover them, and the authoring surface must say *"this
 * question cannot be reported on"* rather than let "Indexed for reporting" promise something it will not
 * deliver. Machine-readable so H24b can localise the copy; {@see label()} is the fallback sentence.
 *
 * The publish gate is deliberately NOT changed to refuse these — ADR-0011's Risks table assigns the warning
 * to the authoring surface and records that `StructuralValidationGate` checks only that a queryable field
 * declares a type. An author can still flag a field that can never project; they are simply told.
 */
enum AnalyticsFieldRefusal: string
{
    /** The stored answer is a list or an object, so the projector's `is_array` guard drops it. */
    case StructuredAnswer = 'structured_answer';

    /** Geo answers project into `submission_geo_index`, not the typed answer index. Not "unreportable". */
    case GeospatialAnswer = 'geospatial_answer';

    /** `note` / `page_break` — no respondent answer exists to aggregate. */
    case NoAnswer = 'no_answer';

    /**
     * A scalar field, but nested in a repeatable section. `SubmissionFinalizer::projectIndex()` iterates
     * TOP-LEVEL answer keys only and `continue`s on any array, so a repeat group's instance array is never
     * descended into — the member field is unreachable even when flagged and scalar.
     */
    case InRepeatableSection = 'in_repeatable_section';

    /**
     * The author has not ticked "Indexed for reporting". Not a defect — the default — but it is why an
     * answer-value chart is empty for essentially every form alive today, and turning it on indexes FUTURE
     * submissions only (there is no backfill, and `SchemaChangeClassifier` classifies the flip
     * `'indexing changed'`, so it also needs a new version).
     */
    case NotFlagged = 'not_flagged';

    /**
     * Flagged but no `indexed_data_type` declared. `StructuralValidationGate` refuses this at publish, so it
     * is reachable only on an unpublished draft — reported rather than assumed impossible.
     */
    case NoIndexedType = 'no_indexed_type';

    /** The fallback author-facing sentence; H24b may localise from the case value instead. */
    public function label(): string
    {
        return match ($this) {
            self::StructuredAnswer => 'This question stores a list or a grid, so its answers cannot be summarised in a report.',
            self::GeospatialAnswer => 'Location answers are stored on the map index, not the reporting index.',
            self::NoAnswer => 'This element has no answer to report on.',
            self::InRepeatableSection => 'Questions inside a repeating section cannot be reported on.',
            self::NotFlagged => 'Turn on "Indexed for reporting" to report on this question from the next published version onward.',
            self::NoIndexedType => 'This question is marked for reporting but has no data type, so nothing is recorded.',
        };
    }
}
