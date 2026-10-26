<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one entry in a question's history records (M188, `R-3894369d`; data-dictionary §42).
 *
 * - `edited` — settings saved through the builder's ordinary save; each changed setting with its value before and after.
 * - `put_back` — the same, saved by **Put back** (`D167`), naming the entry it put back.
 * - `type_changed` — the question type changed (`/convert`), with the settings the change dropped or rewrote. Listed,
 *   never put back (`D168`): the type goes back through the type dialog.
 * - `moved` — the question moved to another section, or to another place among its section's questions. Listed, never
 *   put back (`D168`): it is dragged back.
 *
 * `form_field_revisions_kind_check` is generated from {@see self::values()}.
 */
enum FieldRevisionKind: string
{
    case Edited = 'edited';
    case PutBack = 'put_back';
    case TypeChanged = 'type_changed';
    case Moved = 'moved';

    /**
     * Every stored value, in declaration order — the source of `form_field_revisions_kind_check`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Whether an entry of this kind can be put back (`D168`: a type change and a move cannot). */
    public function canBePutBack(): bool
    {
        return $this === self::Edited || $this === self::PutBack;
    }
}
