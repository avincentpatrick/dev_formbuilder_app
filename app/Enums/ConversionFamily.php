<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Forms\FieldTypeConversion;

/**
 * Which field types a question may be CONVERTED between without deleting it (Increment M121, the overhaul
 * plan's `B5a`). A conversion keeps the field's id and key, so every reference to it survives by
 * construction — which is the whole reason to convert rather than delete and re-add.
 *
 * ⚠️ THIS IS AN EIGHTH PARTITION OF THE SAME THIRTY-ONE CASES, AND {@see ValueShape}'s DOCBLOCK ASKS WHOEVER
 * ADDS ONE TO SAY WHY THE OTHER SEVEN DO NOT ANSWER. `ValueShape` answers "what may be asserted about this
 * value"; this answers "what may this question BECOME". They disagree on exactly the cases the user decided
 * on 2026-10-01 (the `D64` amendment in `docs/claims/decisions.md`):
 *
 *   - `hidden` is `ValueShape::Text` but {@see self::Parking} here — it converts to and from anything.
 *   - `likert_scale` is `ValueShape::Scale` but {@see self::Choice} here — it carries the same flat option
 *     list as the three choice types, so the four convert among each other carrying their options.
 *   - `calculated` is `ValueShape::Number` but {@see self::Isolated} here — turning an answered question
 *     into a computed one silently ignores its rules, and the reverse leaves a dead formula.
 *   - `duration`, `yes_no` and `cascading_select` each have no sibling of their own shape.
 *   - `page_break` shares `ValueShape::NoAnswer` with `note` but is {@see self::Fixed} — swapping it changes
 *     how the form is paginated, which is not a question changing its type.
 *
 * The rules over these families live in {@see FieldTypeConversion::compatible()}.
 */
enum ConversionFamily: string
{
    /** Free text and the text types with a keyboard hint. Lossless among themselves. */
    case Text = 'text';

    /** `integer` and `decimal`. Lossless among themselves. */
    case Number = 'number';

    /** `date`, `time`, `datetime`. A default value may need rewriting. */
    case Temporal = 'temporal';

    /** The four option-list types, carrying their options and their translations. */
    case Choice = 'choice';

    /** The three geospatial types. */
    case Geo = 'geo';

    /** The five media types. The accepted file types reset when the kind changes. */
    case Attachment = 'attachment';

    /** `matrix` and `likert_matrix`. */
    case Grid = 'grid';

    /** Converts only to `note` or `hidden`: `calculated`, `duration`, `yes_no`, `cascading_select`. */
    case Isolated = 'isolated';

    /** `note` and `hidden` — "turn this question off", reversible in both directions. */
    case Parking = 'parking';

    /** `page_break`, which converts to and from nothing. */
    case Fixed = 'fixed';
}
