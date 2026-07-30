<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;
use App\Models\FormVersion;
use App\Support\Migrations\OcrCompatibilityBackfill;

/**
 * Recomputes `forms.capability_flags` from a version's composition (data-dictionary §2 — "recomputed on
 * every publish", generalizing legacy's OCR-compatibility guard, plan §5). A minimal Phase-1 ruleset; new
 * flags are added as the capabilities they describe land.
 *
 * ── The input is the SNAPSHOT, never live rows (Increment H8) ────────────────────────────────────────
 * {@see PublishService} freezes the canonical snapshot at step 3+4 and then writes these flags at step 8.
 * Before H8, step 8 re-read `form_fields` to answer a question step 3 had already frozen the answer to —
 * and that gap is where drift lives. Taking the snapshot as the ONLY input shape means:
 *
 *   - the publish path evaluates the rule against exactly the bytes it is about to persist;
 *   - the H8 retroactive backfill ({@see OcrCompatibilityBackfill}) evaluates it
 *     against those same persisted bytes;
 *   - H18a re-derives eligibility for ANY version, current or superseded, from those same bytes.
 *
 * One input shape ⇒ one extraction ⇒ nothing to drift. There is deliberately no `FormVersion`-taking
 * overload that reads live rows: a second entry point is precisely the seam this closes.
 *
 * ── `ocr_compatible` is an ALLOWLIST, and monotonically restrictive ──────────────────────────────────
 * The rule is `docs/ocr-pipeline-design.md` §2, classified per type by {@see OcrFieldEligibility::for()}.
 * H8 only ever REMOVES eligibility relative to the pre-H8 denylist (proof: §2's permitted set is disjoint
 * from geo ∪ media ∪ {`calculated`}, and every structural clause below only adds falsity; pinned by
 * tests/Unit/Forms/OcrFieldEligibilityTest.php). That invariant is what lets the backfill read only stored
 * `true`s and write a constant `false`, so a future increment that WIDENS the allowlist breaks it and
 * needs its own backfill.
 */
final class CapabilityFlags
{
    /**
     * The flags for a canonical snapshot ({@see SchemaSnapshotSerializer::snapshot()}).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, bool>
     */
    public static function forSnapshot(array $snapshot): array
    {
        $fields = self::listAt($snapshot, 'fields');
        $sections = self::listAt($snapshot, 'sections');

        // null means the key was absent or not a list — never "present and empty". A draft's
        // schema_snapshot is literally `[]`, which is UNEVALUABLE rather than "a form with no fields", so
        // every flag fails closed rather than answering vacuously.
        $types = $fields === null ? null : self::fieldTypes($fields);

        return [
            'has_geofields' => $types !== null && self::anyIs($types, static fn (FieldType $t): bool => $t->isGeo()),
            'has_media' => $types !== null && self::anyIs($types, static fn (FieldType $t): bool => $t->isMedia()),
            'ocr_compatible' => $types !== null
                && $sections !== null
                && self::everyFieldPermitted($types)
                && self::anyFieldExtractable($types)
                && ! self::anyRepeatableSection($sections),
        ];
    }

    /**
     * Eligibility for a STORED version, re-derived from its frozen snapshot — the seam H18a/H19 use.
     *
     * They must call this rather than read `forms.capability_flags`, which is a display cache describing
     * only the currently published version and is stale until the next publish (`ocr-pipeline-design.md`
     * §2's as-built note).
     */
    public static function isOcrCompatible(FormVersion $version): bool
    {
        return self::forSnapshot($version->schema_snapshot)['ocr_compatible'];
    }

    /**
     * §2 clause 1 — no `Excluded` type appears anywhere in the version.
     *
     * A field type this catalog does not know (a snapshot written by a newer schema than the reading code)
     * counts as not permitted. That is why {@see self::fieldTypes()} resolves with `tryFrom()`: `from()`
     * would throw, turning a fail-safe `false` into a 500 at OCR-request time on a row that is merely from
     * the future.
     *
     * @param  list<?FieldType>  $types
     */
    private static function everyFieldPermitted(array $types): bool
    {
        foreach ($types as $type) {
            if ($type === null || OcrFieldEligibility::for($type) === OcrFieldEligibility::Excluded) {
                return false;
            }
        }

        return true;
    }

    /**
     * §2 clause 3 (non-vacuity) — at least one `Extractable` field is present.
     *
     * A version with no fields, or one composed only of `Neutral` fields (notes, page breaks, hidden),
     * has nothing to extract: offering the channel there would stage an empty draft and burn a provider
     * call. Both states are reachable — nothing requires a published version to carry a field.
     *
     * @param  list<?FieldType>  $types
     */
    private static function anyFieldExtractable(array $types): bool
    {
        return self::anyIs(
            $types,
            static fn (FieldType $t): bool => OcrFieldEligibility::for($t) === OcrFieldEligibility::Extractable,
        );
    }

    /**
     * §2 clause 2 — "any repeat group (`is_repeatable = true` sections)".
     *
     * The predicate is on the SECTION, not on its contents, so an empty repeat group disqualifies too:
     * that is §2's literal wording, it is the fail-safe reading, and it means the rule needs no
     * field→section join. A section entry that does not explicitly say `false` is treated as repeatable —
     * fail-safe in the same direction.
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private static function anyRepeatableSection(array $sections): bool
    {
        foreach ($sections as $section) {
            if (($section['is_repeatable'] ?? true) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The snapshot's `sections` / `fields` list, or null when the key is absent or not a list of arrays.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>|null
     */
    private static function listAt(array $snapshot, string $key): ?array
    {
        $value = $snapshot[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $entries = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                return null;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<?FieldType> null entries = a `field_type` this catalog does not know
     */
    private static function fieldTypes(array $fields): array
    {
        return array_map(
            static function (array $field): ?FieldType {
                $raw = $field['field_type'] ?? null;

                return is_string($raw) ? FieldType::tryFrom($raw) : null;
            },
            $fields,
        );
    }

    /**
     * @param  list<?FieldType>  $types
     * @param  callable(FieldType): bool  $predicate
     */
    private static function anyIs(array $types, callable $predicate): bool
    {
        foreach ($types as $type) {
            if ($type !== null && $predicate($type)) {
                return true;
            }
        }

        return false;
    }
}
