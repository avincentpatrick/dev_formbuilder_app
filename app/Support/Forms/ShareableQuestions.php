<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;

/**
 * Which questions of a published version another form may take its choices from (M133, `R-5da4a30f` — Connect
 * project v1). One rule, read from the version snapshot, and asked in three places that must agree: the source's
 * Data sharing section (what may be shared), the destination's publish gate (what may be linked) and every serve
 * of the list (what may still be shown).
 *
 * A question is shareable when all four hold:
 *   - it stores ONE short answer a person can recognise as a choice: a line of text, a number, a calculated or
 *     hidden value, or a single choice / dropdown (whose label is shown). Email, phone and web-address questions
 *     are left out: they are contact details whether or not anyone marked them personal;
 *   - it is not inside a repeatable section — a repeat group stores a LIST of answers under its section key, and
 *     v1 makes one choice per response (the remainder row carries repeat sources);
 *   - it is not marked personal (`is_pii`) and not marked sensitive (`is_sensitive`) — `D86`: never shared, because
 *     a shared list is readable by anyone who opens a form that uses it, through its public link included;
 *   - it has a key.
 *
 * Pure: it reads only the snapshot array it is given, so the three callers cannot drift by reading different
 * rows.
 *
 * ── A CASCADE, SHARED TO NARROW ANOTHER FORM'S CHOICES — AND ONLY WHEN CHOSEN (M186, `R-ed7f3127`) ───────────────
 * A cascade stores the PATH a person picked, one value per level, so it cannot feed a list of choices — but its values
 * at one level ("the barangays with a report") can narrow another form's choices ({@see FILTER_TYPES}). Every reader
 * asks for one purpose: the default is a list's (options, suggestions, a grid's copy, the scan's carry), which never
 * sees a cascade; `$forFilters` is a filter's. And a cascade is shared only when the owner ticks it: `null` — "every
 * shareable question" — was a consent given before cascades could be shared, so it is never read as covering one.
 */
final class ShareableQuestions
{
    /** @var list<FieldType> */
    public const TYPES = [
        FieldType::ShortText,
        FieldType::Integer,
        FieldType::Decimal,
        FieldType::Calculated,
        FieldType::Hidden,
        FieldType::SingleSelect,
        FieldType::Dropdown,
    ];

    /**
     * M186 (`R-ed7f3127`): what may narrow another form's choices — every list type, and a cascade by its levels.
     *
     * @var list<FieldType>
     */
    public const FILTER_TYPES = [...self::TYPES, FieldType::CascadingSelect];

    /**
     * The shareable questions of a snapshot, in the order the form asks them — a cascade too when `$forFilters`.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array{key: string, label: string, field_type: string}>
     */
    public static function fromSnapshot(?array $snapshot, bool $forFilters = false): array
    {
        $repeatSections = self::repeatSectionKeys($snapshot);
        $fields = self::fields($snapshot);

        usort($fields, static fn (array $a, array $b): int => self::intAt($a, 'sequence') <=> self::intAt($b, 'sequence'));

        $questions = [];
        foreach ($fields as $field) {
            if (! self::isShareable($field, $repeatSections, $forFilters)) {
                continue;
            }

            $key = (string) $field['key'];
            $label = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? $field['label'] : $key;
            $questions[] = ['key' => $key, 'label' => $label, 'field_type' => (string) $field['field_type']];
        }

        return $questions;
    }

    /**
     * One shareable question's snapshot entry, or null when the key names nothing shareable in this snapshot.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>|null
     */
    public static function find(?array $snapshot, string $key, bool $forFilters = false): ?array
    {
        $repeatSections = self::repeatSectionKeys($snapshot);

        foreach (self::fields($snapshot) as $field) {
            if (($field['key'] ?? null) === $key) {
                return self::isShareable($field, $repeatSections, $forFilters) ? $field : null;
            }
        }

        return null;
    }

    /**
     * The keys a source currently shares: every shareable question when `$chosen` is null (`D60`'s note — null
     * means all), otherwise the chosen keys that are still shareable in this snapshot, in the form's order. For a
     * filter (M186), a cascade's key only when it is among `$chosen` — never through null's "all".
     *
     * @param  array<string, mixed>|null  $snapshot
     * @param  list<string>|null  $chosen
     * @return list<string>
     */
    public static function sharedKeys(?array $snapshot, ?array $chosen, bool $forFilters = false): array
    {
        $keys = [];
        foreach (self::fromSnapshot($snapshot, $forFilters) as $question) {
            $cascade = $question['field_type'] === FieldType::CascadingSelect->value;
            if ($chosen === null ? ! $cascade : in_array($question['key'], $chosen, true)) {
                $keys[] = $question['key'];
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, true>  $repeatSections
     */
    private static function isShareable(array $field, array $repeatSections, bool $forFilters = false): bool
    {
        if (! is_string($field['key'] ?? null) || $field['key'] === '') {
            return false;
        }

        $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
        if ($type === null || ! in_array($type, $forFilters ? self::FILTER_TYPES : self::TYPES, true)) {
            return false;
        }

        $section = $field['section_key'] ?? null;
        if (is_string($section) && isset($repeatSections[$section])) {
            return false;
        }

        return ($field['is_pii'] ?? false) !== true && ($field['is_sensitive'] ?? false) !== true;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, true>
     */
    private static function repeatSectionKeys(?array $snapshot): array
    {
        $keys = [];
        foreach ((array) ($snapshot['sections'] ?? []) as $section) {
            if (is_array($section) && ($section['is_repeatable'] ?? false) === true && is_string($section['key'] ?? null)) {
                $keys[$section['key']] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array<string, mixed>>
     */
    private static function fields(?array $snapshot): array
    {
        $fields = [];
        foreach ((array) ($snapshot['fields'] ?? []) as $field) {
            if (is_array($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param array<string, mixed> $entry */
    private static function intAt(array $entry, string $key): int
    {
        return is_int($entry[$key] ?? null) ? $entry[$key] : 0;
    }
}
