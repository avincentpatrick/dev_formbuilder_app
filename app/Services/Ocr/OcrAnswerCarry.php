<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\FieldType;

/**
 * Turns what a scan read into the answers a reviewer starts from (M129 — single-form OCR groundwork 2).
 *
 * The matcher reads a sheet against the version it was PRINTED from, superseded ones included, because the
 * stamp on the paper names that version. A response is saved against the CURRENT published version, because
 * every save path refuses any other. This class is the bridge between the two, and it is pure: two schema
 * snapshots and an extraction in, answers and their provenance out.
 *
 * ── WHAT CARRIES (`D74` = A, answered by the user in chat) ──────────────────────────────────────────
 * A read answer moves to the question with the same key in the current version when the type is the same and
 * the value is one the current question can hold: every choice still offered, every cascade level still
 * valid, and a flat target rather than one inside a repeating section. Anything else is listed for the
 * reviewer with the text the scan read, so nothing read is lost in silence. The rule is strict on purpose:
 * the review screen renders the shared encode controls, and a select, a cascade or a number box shows nothing
 * for a value it cannot recognise — so a value handed to a control that cannot show it would be saved by a
 * reviewer who never saw it.
 *
 * ── TWO MORE THINGS NEVER CARRY ─────────────────────────────────────────────────────────────────────
 * A value the matcher withheld (the `manual` tier, below the review threshold) is null already and stays
 * empty for a person to enter. A `duration` is read but has no answer control in any channel
 * (`FieldTypeMirrorDriftTest` pins the encode and guest lists together), so it is listed rather than saved
 * unseen.
 *
 * ⚠️ NUMBERS ARRIVE AS TEXT. The reader returns `'41'` for an integer, and the shared number control displays
 * only a value whose type is number, so a carried number is converted here — or the box would look empty
 * while the answer map held a value.
 */
final class OcrAnswerCarry
{
    /** The reason codes a non-carried answer is listed under, with the sentence the reviewer reads. */
    public const REASONS = [
        'question_removed' => 'This question is not in the current version of the form.',
        'type_changed' => 'This question has changed type since the paper was printed.',
        'option_missing' => 'The choice read from the paper is no longer offered.',
        'not_answerable' => 'Answers to this kind of question cannot be entered yet.',
        'repeat' => 'This question is now inside a repeating section.',
    ];

    /** Types whose answer is a choice among the question's own options. */
    private const SINGLE_CHOICE = [FieldType::SingleSelect, FieldType::Dropdown, FieldType::LikertScale];

    /**
     * @param  array<string, mixed>  $extraction  the scan's `extraction` column
     * @param  array<string, mixed>  $paper  the schema snapshot of the version the paper was printed from
     * @param  array<string, mixed>  $current  the schema snapshot of the current published version
     * @return array{
     *     answers: array<string, mixed>,
     *     fields: array<string, array{state: string, tier: string|null, confidence: int|null, text: string|null, page: int|null, carried: bool, reason: string|null}>,
     *     dropped: list<array{key: string, label: string, text: string|null, reason: string, message: string}>
     * }
     */
    public function carry(array $extraction, array $paper, array $current): array
    {
        /** @var array<string, mixed> $read */
        $read = is_array($extraction['fields'] ?? null) ? $extraction['fields'] : [];
        $paperFields = $this->fieldsByKey($paper);
        $repeatable = $this->repeatableSections($current);

        $answers = [];
        $fields = [];
        $dropped = [];
        $placed = [];

        foreach ($this->fieldsByKey($current) as $key => $field) {
            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
            if ($type === null || ! $this->takesPaperAnswer($type)) {
                continue;
            }

            $entry = $read[$key] ?? null;
            if (! is_array($entry)) {
                if (! array_key_exists($key, $paperFields)) {
                    $fields[$key] = $this->meta(['state' => 'not_on_paper'], false, null);
                }

                continue;
            }

            /** @var array<string, mixed> $entry */
            $placed[$key] = true;

            // Nothing to carry: blank, unreadable, not found, or a value the matcher withheld.
            if (($entry['state'] ?? null) !== 'read' || ($entry['value'] ?? null) === null) {
                $fields[$key] = $this->meta($entry, false, null);

                continue;
            }

            $reason = $this->refusal($entry, $type, $field, $repeatable);
            if ($reason === null) {
                $answers[$key] = $this->coerce($entry['value'], $type);
                $fields[$key] = $this->meta($entry, true, null);

                continue;
            }

            $fields[$key] = $this->meta($entry, false, $reason);
            $dropped[] = $this->drop($key, $paperFields[$key] ?? $field, $entry, $reason);
        }

        // A question on the paper that the current version no longer has at all.
        foreach ($read as $key => $entry) {
            if (isset($placed[$key]) || ! is_array($entry)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            if ($this->carriesSomething($entry)) {
                $dropped[] = $this->drop((string) $key, $paperFields[$key] ?? [], $entry, 'question_removed');
            }
        }

        return ['answers' => $answers, 'fields' => $fields, 'dropped' => $dropped];
    }

    /**
     * Why a read value cannot go to this question, or null when it can.
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $field
     * @param  array<string, true>  $repeatable
     */
    private function refusal(array $entry, FieldType $type, array $field, array $repeatable): ?string
    {
        $sectionKey = $field['section_key'] ?? null;

        return match (true) {
            is_string($sectionKey) && isset($repeatable[$sectionKey]) => 'repeat',
            $type === FieldType::Duration => 'not_answerable',
            ($entry['type'] ?? null) !== $type->value => 'type_changed',
            ! $this->valueFits($entry['value'] ?? null, $type, $field) => 'option_missing',
            default => null,
        };
    }

    /**
     * Whether a value is one the current question's own options can hold. Free-text, number, date and
     * yes/no answers have no options to fall out of.
     *
     * @param  array<string, mixed>  $field
     */
    private function valueFits(mixed $value, FieldType $type, array $field): bool
    {
        /** @var array<string, mixed> $config */
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        if (in_array($type, self::SINGLE_CHOICE, true)) {
            return is_string($value) && in_array($value, $this->optionValues($config), true);
        }

        if ($type === FieldType::MultiSelect) {
            $offered = $this->optionValues($config);

            return is_array($value) && array_diff(array_map('strval', $value), $offered) === [];
        }

        if ($type === FieldType::CascadingSelect) {
            return is_array($value) && $this->cascadeFits(array_values($value), $config);
        }

        return true;
    }

    /**
     * Each chosen value must exist at its own level, under the value chosen at the level above.
     *
     * @param  list<mixed>  $chosen
     * @param  array<string, mixed>  $config
     */
    private function cascadeFits(array $chosen, array $config): bool
    {
        $levels = [];
        foreach ((array) ($config['levels'] ?? []) as $level) {
            $key = is_array($level) ? ($level['key'] ?? null) : null;
            if (is_string($key)) {
                $levels[] = $key;
            }
        }

        $parent = null;
        foreach ($chosen as $i => $value) {
            $level = $levels[$i] ?? null;
            if ($level === null || ! is_string($value) || ! $this->cascadeOffers($config, $level, $value, $parent)) {
                return false;
            }
            $parent = $value;
        }

        return true;
    }

    /** @param  array<string, mixed>  $config */
    private function cascadeOffers(array $config, string $level, string $value, ?string $parent): bool
    {
        foreach ((array) ($config['options'] ?? []) as $option) {
            if (is_array($option)
                && ($option['level'] ?? null) === $level
                && ($option['value'] ?? null) === $value
                && ($parent === null || ($option['parent'] ?? null) === $parent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function optionValues(array $config): array
    {
        $values = [];
        foreach ((array) ($config['options'] ?? []) as $option) {
            if (is_array($option) && (is_string($option['value'] ?? null) || is_int($option['value'] ?? null))) {
                $values[] = (string) $option['value'];
            }
        }

        return $values;
    }

    /** The reader's numbers are text; the shared number control shows only a number. */
    private function coerce(mixed $value, FieldType $type): mixed
    {
        if ($type === FieldType::Integer && is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if ($type === FieldType::Decimal && is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * A type a respondent answers on paper — the extractable set the matcher reads. Display-only, computed
     * and app-only types take no carried value.
     */
    private function takesPaperAnswer(FieldType $type): bool
    {
        return ! in_array($type, [FieldType::Note, FieldType::PageBreak, FieldType::Hidden, FieldType::Calculated], true);
    }

    /**
     * Whether a question the current version lacks still had something on the paper worth listing: a value,
     * or text the reader saw and withheld.
     *
     * @param  array<string, mixed>  $entry
     */
    private function carriesSomething(array $entry): bool
    {
        $state = $entry['state'] ?? null;
        $text = $entry['text'] ?? null;

        return ($state === 'read' && (($entry['value'] ?? null) !== null || (is_string($text) && $text !== '')))
            || ($state === 'unreadable' && is_string($text) && $text !== '');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{state: string, tier: string|null, confidence: int|null, text: string|null, page: int|null, carried: bool, reason: string|null}
     */
    private function meta(array $entry, bool $carried, ?string $reason): array
    {
        return [
            'state' => (string) ($entry['state'] ?? 'not_found'),
            'tier' => is_string($entry['tier'] ?? null) ? $entry['tier'] : null,
            'confidence' => is_int($entry['confidence'] ?? null) ? $entry['confidence'] : null,
            'text' => is_string($entry['text'] ?? null) ? $entry['text'] : null,
            'page' => is_int($entry['page'] ?? null) ? $entry['page'] : null,
            'carried' => $carried,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $field  the question as the paper printed it, when known
     * @param  array<string, mixed>  $entry
     * @return array{key: string, label: string, text: string|null, reason: string, message: string}
     */
    private function drop(string $key, array $field, array $entry, string $reason): array
    {
        $label = $field['label'] ?? null;

        return [
            'key' => $key,
            'label' => is_string($label) && $label !== '' ? $label : $key,
            'text' => is_string($entry['text'] ?? null) ? $entry['text'] : null,
            'reason' => $reason,
            'message' => self::REASONS[$reason],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByKey(array $snapshot): array
    {
        $fields = [];
        foreach ((array) ($snapshot['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                /** @var array<string, mixed> $field */
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, true>
     */
    private function repeatableSections(array $snapshot): array
    {
        $keys = [];
        foreach ((array) ($snapshot['sections'] ?? []) as $section) {
            if (is_array($section) && ($section['is_repeatable'] ?? false) === true && is_string($section['key'] ?? null)) {
                $keys[$section['key']] = true;
            }
        }

        return $keys;
    }
}
