<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\AnalyticsFieldEligibility;
use App\Enums\AnalyticsFieldRefusal;
use App\Models\FormField;
use App\Services\Submissions\AnswerIndexProjector;
use App\Services\Submissions\SubmissionFinalizer;

/**
 * Whether ONE field can be aggregated by value, and if not, why (ADR-0011 §D3, Increment H24a).
 *
 * The composition point: {@see AnalyticsFieldEligibility} answers the TYPE axis, and this adds the three
 * facts no field type carries — the author's `is_queryable` flag, the declared `indexed_data_type`, and
 * whether the owning section repeats.
 *
 * ── Why the repeatable-section check is here and not in the enum ────────────────────────────────────────
 * It is a property of `form_sections.is_repeatable`, not of `FieldType`. Pushing it into the enum's `match`
 * would mean passing a whole `FormField` into an enum static, and the enum would stop being the total
 * classification of the catalog that makes an unclassified new type a static-analysis failure.
 *
 * ── The predicate this mirrors ──────────────────────────────────────────────────────────────────────────
 * {@see SubmissionFinalizer::projectIndex()} plus
 * {@see AnswerIndexProjector::project()}. Between them a row exists iff the field
 * is flagged, declares a type, the answer is non-empty and NOT an array, and the key is reachable from the
 * top level of the answer map. This class is the STATIC, author-time twin of that runtime behaviour: it
 * cannot see the answer, so it reports what is *structurally* possible and leaves per-answer drops to the
 * coverage numbers the aggregate returns.
 *
 * Deliberately answers only "could a row ever exist", never "are there rows" — the second question is a
 * query, and §D3(iii) requires it to be reported separately as coverage precisely because a field can be
 * perfectly reportable and still have projected nothing.
 */
final class ReportableFields
{
    /**
     * Why `$field` cannot be reported on, or null when it can.
     *
     * `$inRepeatableSection` is supplied by the caller rather than read off the relation, so a bulk
     * classification over a version can resolve sections once instead of once per field.
     */
    public static function refusalFor(FormField $field, bool $inRepeatableSection): ?AnalyticsFieldRefusal
    {
        $eligibility = AnalyticsFieldEligibility::for($field->field_type);

        // The type axis first: a grid or a media field is unreportable no matter what the author ticks, and
        // saying "turn on Indexed for reporting" about one would be the exact false promise §D3(ii) forbids.
        $typeRefusal = match ($eligibility) {
            AnalyticsFieldEligibility::Structured => AnalyticsFieldRefusal::StructuredAnswer,
            AnalyticsFieldEligibility::Geospatial => AnalyticsFieldRefusal::GeospatialAnswer,
            AnalyticsFieldEligibility::NoAnswer => AnalyticsFieldRefusal::NoAnswer,
            AnalyticsFieldEligibility::Indexable => null,
        };

        if ($typeRefusal !== null) {
            return $typeRefusal;
        }

        // Structure next, and BEFORE the author's flag for the same reason: a scalar inside a repeat group
        // is unreachable however it is configured, so offering the flag as the remedy would be wrong.
        if ($inRepeatableSection) {
            return AnalyticsFieldRefusal::InRepeatableSection;
        }

        if (! $field->is_queryable) {
            return AnalyticsFieldRefusal::NotFlagged;
        }

        if ($field->indexed_data_type === null) {
            return AnalyticsFieldRefusal::NoIndexedType;
        }

        return null;
    }

    public static function isReportable(FormField $field, bool $inRepeatableSection): bool
    {
        return self::refusalFor($field, $inRepeatableSection) === null;
    }
}
