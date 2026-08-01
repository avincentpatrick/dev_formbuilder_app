<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Models\FormField;
use App\Support\Forms\LocaleVariant;

/**
 * Turns a stored answer (from `submission_answers.answers`, keyed by `form_fields.key`) into a single
 * human-readable string, and normalizes a choice field's author-defined option list. Shared by the read
 * side of F7 — the inbox detail view (walks live {@see FormField} rows) and the streamed
 * {@see SubmissionExporter} (walks a version's immutable `schema_snapshot`) — so choice-value → label
 * resolution and multi-select/boolean/date rendering are defined exactly once for both surfaces.
 *
 * Structural `note`/`page_break` types carry no answer and are excluded upstream (see {@see isDataField()});
 * every other type resolves here. Empty answers return '' so an export cell is blank and the detail view can
 * substitute its own em-dash.
 */
final class SchemaValueFormatter
{
    /** Field types that never hold a respondent answer — excluded from both the detail view and the export. */
    private const NON_DATA = [FieldType::Note, FieldType::PageBreak];

    /** Whether a field type contributes an answer column/row (everything but pure structural labels). */
    public function isDataField(FieldType $type): bool
    {
        return ! in_array($type, self::NON_DATA, true);
    }

    /**
     * Format one answer for display/export. Choice values resolve to their option labels; multi-select /
     * repeat arrays join with "; "; yes/no renders Yes/No; everything else stringifies. Empty → ''.
     *
     * `$locale` (Increment H6b, Doc #26 amendment A8) resolves a choice option's `label_translations`
     * variant before falling back to its base label. Null — the default every pre-H6b caller keeps — is
     * byte-identical to the old behaviour. It exists because §4's normative order (resolve the locale,
     * THEN render) was only half-honoured: the sentence AROUND a hole resolved, the choice label dropped
     * INTO it did not, so a Filipino respondent read an English option label mid-Filipino-sentence and
     * the tenant's inbox disagreed with the respondent's own screen about the same answer.
     *
     * @param  array<string, mixed>  $config  the field's `config` jsonb (holds `options` for choice types)
     */
    public function displayValue(FieldType $type, mixed $answer, array $config, ?string $locale = null): string
    {
        if ($answer === null || $answer === '' || $answer === []) {
            return '';
        }

        if ($type === FieldType::YesNo) {
            return $this->boolLabel($answer);
        }

        // Geospatial (Increment G5b1): the object-valued GeoJSON envelope must be summarised BEFORE the
        // generic is_array join below (which would stringify its keys). geopoint → "lat, lon (±m)";
        // geotrace/geoshape → "Line/Area — N points".
        if ($type->isGeo()) {
            return $this->formatGeo($type, $answer);
        }

        // Choice fields resolve values to labels; cascading select does too (its `config.options` carry
        // value+label alongside level/parent, which optionLabels ignores) so an exported cascade reads as
        // "NCR; Manila", not "ncr; manila" (Increment G4a).
        $labels = ($type->hasOptions() || $type === FieldType::CascadingSelect) ? $this->optionLabels($config, $locale) : [];

        if (is_array($answer)) {
            $parts = array_map(
                fn (mixed $v): string => $labels[$this->scalarString($this->scalar($v))] ?? $this->scalarString($this->scalar($v)),
                $answer,
            );

            return implode('; ', $parts);
        }

        $scalar = $this->scalarString($this->scalar($answer));

        return $labels[$scalar] ?? $scalar;
    }

    /**
     * The author-defined option list for choice fields (stored in `config.options`), normalised to the
     * `{value,label}` pairs a select/checkbox binds to. Empty for non-choice types or a malformed list.
     *
     * TWO fallbacks at two levels, deliberately different (Increment H6b), each mirroring the runtime:
     * the LOCALE variant falls back when missing, non-string OR blank — `resolveText()`'s "never blank"
     * rule (`schema-mapping.ts`), which Doc #26 §4 makes normative; the BASE label falls back to the value
     * on NULL only, so an author's explicit `"label": ""` still wins (unchanged, and vector-pinned).
     *
     * @param  array<string, mixed>  $config
     * @return list<array{value: string, label: string}>
     */
    public function options(array $config, ?string $locale = null): array
    {
        $options = $config['options'] ?? [];
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $option) {
            if (! is_array($option) || ! isset($option['value'])) {
                continue;
            }
            $value = $this->scalarString($this->scalar($option['value']));
            $base = isset($option['label']) ? $this->scalarString($this->scalar($option['label'])) : $value;
            $normalized[] = ['value' => $value, 'label' => $this->variant($option, $locale) ?? $base];
        }

        return $normalized;
    }

    /**
     * One option's `label_translations` variant for `$locale`, or null when there is none to use.
     * Mirrors `resolveText()`: a missing, non-string or BLANK variant is no variant at all.
     *
     * The rule itself moved to {@see LocaleVariant} in H17, when the submission PDF became its
     * second PHP caller — behaviour here is unchanged, and this method stays as the option-shaped
     * adapter so every call site in this class reads the same as it did in H6b.
     *
     * @param  array<string, mixed>  $option
     */
    private function variant(array $option, ?string $locale): ?string
    {
        return LocaleVariant::from($option['label_translations'] ?? null, $locale);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string> value ⇒ label
     */
    private function optionLabels(array $config, ?string $locale = null): array
    {
        $map = [];
        foreach ($this->options($config, $locale) as $option) {
            $map[$option['value']] = $option['label'];
        }

        return $map;
    }

    private function boolLabel(mixed $answer): string
    {
        $truthy = $answer === true || $answer === 1 || in_array($answer, ['1', 'true', 'yes', 'on'], true);

        return $truthy ? 'Yes' : 'No';
    }

    /** Reduce a JSON scalar to a string-castable value (guards against nested arrays/objects in answers). */
    private function scalar(mixed $value): string|int|float|bool
    {
        return is_scalar($value) ? $value : (string) json_encode($value);
    }

    /**
     * Stringify a scalar under a rule BOTH engines can reproduce byte-for-byte (Increment H6b, Doc #26
     * amendment A7). This replaces the bare `(string)` cast, which was not mirrorable:
     *
     *     value        (string)              String()                  here
     *     true         "1"                   "true"                    "1"
     *     false        ""                    "false"                   ""
     *     0.1 + 0.2    "0.3"                 "0.30000000000000004"     "0.3"
     *     1 / 3        "0.33333333333333"    "0.3333333333333333"      "0.3333333333"
     *     1e15         "1.0E+15"             "1000000000000000"        "1000000000000000"
     *     0.00001      "1.0E-5"              "0.00001"                 "0.00001"
     *
     * Both of the divergent branches are REACHABLE, not theoretical: `ExpressionEvaluator::normalize()`
     * passes a native bool straight through, so a `calculated` field holding `${age} >= 18` stores one,
     * and any `calculated` field doing division holds a non-integral float. H6a's only float vector was
     * `3.5` — exactly representable in binary, so it concealed the whole problem.
     *
     * The rule avoids PHP's `precision`/`serialize_precision` ini entirely (runtime-configurable, so a
     * cast is not even stable across deployments) and avoids E-notation on both sides. It is
     * fixed-notation to 10 decimals, trailing zeros trimmed — `sprintf('%.10F')` here, `toFixed(10)` in
     * `display-value.ts`, verified equal across the reachable range.
     *
     * ONE accepted divergence, with a precise boundary: at |v| >= 1e21 JavaScript's `toFixed` falls back
     * to exponential notation per spec ("1e+21") where `%.10F` does not. Hence the integral branch's
     * `< 1e21` guard is a documentation of where the mirror stops, not an optimisation. No form
     * arithmetic reaches it, and no vector may pin a value there.
     *
     * `Coercion::toStr()` remains BARRED (§3.2) — this is the pinned primitive it was pointing at.
     */
    private function scalarString(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return '';
            }

            return floor($value) === $value && abs($value) < 1e21
                ? sprintf('%.0F', $value)
                : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return (string) $value;
    }

    /**
     * Summarise a geo answer (Increment G5b1). A geopoint shows human "lat, lon" (with the ± accuracy in
     * metres when captured) — note the display order is lat-first, the opposite of the internal lon-first
     * `[lon, lat]` storage. A geotrace/geoshape shows a point count. Malformed → ''.
     */
    private function formatGeo(FieldType $type, mixed $answer): string
    {
        $coordinates = is_array($answer) ? ($answer['coordinates'] ?? null) : null;
        if (! is_array($coordinates)) {
            return '';
        }

        if ($type === FieldType::Geopoint) {
            $lon = $coordinates[0] ?? null;
            $lat = $coordinates[1] ?? null;
            if (! is_numeric($lon) || ! is_numeric($lat)) {
                return '';
            }
            $text = $this->coord($lat).', '.$this->coord($lon);
            $accuracy = $answer['accuracy'] ?? null;
            if (is_numeric($accuracy)) {
                $text .= ' (±'.$this->coord($accuracy).' m)';
            }

            return $text;
        }

        // geotrace = the position list; geoshape = the first (outer) ring.
        $positions = $type === FieldType::Geoshape ? ($coordinates[0] ?? []) : $coordinates;
        $count = is_array($positions) ? count($positions) : 0;
        $noun = $type === FieldType::Geoshape ? 'Area' : 'Line';

        return $noun.' — '.$count.' '.($count === 1 ? 'point' : 'points');
    }

    /** Format one ordinate/accuracy as a clean decimal string (trailing zeros trimmed; "-0" → "0"). */
    private function coord(mixed $n): string
    {
        $s = rtrim(rtrim(sprintf('%.7f', (float) $n), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
