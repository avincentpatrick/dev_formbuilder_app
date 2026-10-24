<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;

/**
 * A repeatable section laid out as a table (M185, `R-0a7275ce`, `D129` — Fillout's Table): every question a column, every
 * entry a row. This is the ONE answer to "can this section be one", read by the response PDF and the paper form here and
 * by the guest form, the encode page, the builder preview and the builder's Layout setting through its TypeScript twin,
 * `resources/js/components/submissions/repeat-table.ts`. `tests/fixtures/repeat-table.json` holds the cases both read.
 *
 * A table cell holds one short answer: a text (short, email, phone or web address), a number, a date, or a choice (one, a
 * dropdown, or several as ticks) — a grid column's types ({@see MatrixColumns::TYPES}) and the three text kinds and a
 * dropdown beside them. Anything else a respondent sees — a long text, a time, a cascade, a note, a shown calculation, a
 * Yes / No kept from before `D140` — keeps the section as cards, and the builder names the question that does. A member
 * that renders nothing (a hidden question, a page break, a calculation left Never) is no column at all.
 */
final class RepeatTable
{
    /** The question types a table cell holds, in the order the Layout setting's note lists them. */
    public const array TYPES = [
        FieldType::ShortText,
        FieldType::Email,
        FieldType::Phone,
        FieldType::Url,
        FieldType::Integer,
        FieldType::Decimal,
        FieldType::Date,
        FieldType::SingleSelect,
        FieldType::Dropdown,
        FieldType::MultiSelect,
    ];

    /**
     * Whether a section in the snapshot's shape asks to be drawn as a table — repeatable, and set to one.
     *
     * @param  array<string, mixed>  $section
     */
    public static function asked(array $section): bool
    {
        return ($section['is_repeatable'] ?? false) === true && ($section['repeat_layout'] ?? null) === 'table';
    }

    /**
     * The section's columns: its members a respondent sees, in order. Each is a field in the snapshot's shape.
     *
     * @param  iterable<mixed>  $members
     * @return list<array<string, mixed>>
     */
    public static function columns(iterable $members): array
    {
        $columns = [];
        foreach ($members as $member) {
            if (! is_array($member)) {
                continue;
            }
            $type = FieldType::tryFrom((string) ($member['field_type'] ?? ''));
            if ($type !== null && ! FieldVisibility::rendersNothing($type, $member['config'] ?? null)) {
                $columns[] = $member;
            }
        }

        return $columns;
    }

    /**
     * The first column a table cannot hold — the question that keeps the section as cards — or null when it can be one.
     *
     * @param  iterable<mixed>  $members
     * @return array<string, mixed>|null
     */
    public static function blocker(iterable $members): ?array
    {
        foreach (self::columns($members) as $column) {
            if (! in_array(FieldType::tryFrom((string) ($column['field_type'] ?? '')), self::TYPES, true)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Whether the section is drawn as a table: it asks to be one, has a column, and every column fits a cell.
     *
     * @param  array<string, mixed>  $section
     * @param  iterable<mixed>  $members
     */
    public static function draws(array $section, iterable $members): bool
    {
        $members = is_array($members) ? $members : iterator_to_array($members, false);

        return self::asked($section) && self::columns($members) !== [] && self::blocker($members) === null;
    }
}
