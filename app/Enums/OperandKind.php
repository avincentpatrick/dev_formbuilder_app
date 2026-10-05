<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Forms\ExpressionOperandJudge;

/**
 * What a question's answer IS when an EXPRESSION reads it — a number, a date, a time, a list, nothing at all
 * (Increment M134, `R-62b638e1`). The one server source for what an expression may compare a question with: the
 * publish gate judges orderings and equalities by it ({@see ExpressionOperandJudge}).
 *
 * ⛔ NOT {@see ValueShape}, and the difference is the point. ValueShape answers what a STRUCTURED rule row may do
 * to a field, and it groups date, time and date-time as one Temporal shape that still refuses ordering (the
 * structured half of `R-af395416`). This answers what an expression may compare a question WITH, pairwise: a date
 * orders against a date, a date-time or `today()`, a time only against a time. Text, hidden and the single choices
 * are `Value` — they can hold a number or a date string, so an ordering on them is allowed, as `M126` decided.
 *
 * ⛔ A `match` ON THE ENUM WITH NO `default` ARM — a thirty-second field type is a PHPStan error here.
 */
enum OperandKind: string
{
    case Number = 'number';
    case Computed = 'computed';
    case Date = 'date';
    case Time = 'time';
    case Datetime = 'datetime';
    case Value = 'value';
    case Boolean = 'boolean';
    case List = 'list';
    case Attachment = 'attachment';
    case Object = 'object';
    case None = 'none';

    public static function for(FieldType $type): self
    {
        return match ($type) {
            FieldType::Integer, FieldType::Decimal, FieldType::Duration, FieldType::LikertScale => self::Number,
            FieldType::Calculated => self::Computed,
            FieldType::Date => self::Date,
            FieldType::Time => self::Time,
            FieldType::Datetime => self::Datetime,
            FieldType::ShortText, FieldType::LongText, FieldType::Email, FieldType::Phone, FieldType::Url,
            FieldType::Hidden, FieldType::SingleSelect, FieldType::Dropdown => self::Value,
            FieldType::YesNo => self::Boolean,
            FieldType::MultiSelect, FieldType::CascadingSelect => self::List,
            FieldType::FileUpload, FieldType::ImageCapture, FieldType::AudioCapture,
            FieldType::VideoCapture, FieldType::Signature => self::Attachment,
            FieldType::Matrix, FieldType::LikertMatrix,
            FieldType::Geopoint, FieldType::Geotrace, FieldType::Geoshape => self::Object,
            FieldType::Note, FieldType::PageBreak => self::None,
        };
    }

    /**
     * `M126`'s classification, unchanged: `date` for the three temporal kinds, `non_number` for a kind that is never
     * a number (a note, a page break, a yes/no, a list, a file), null when a number can be held — or when the gate
     * refuses the question as an operand outright (grid, geo), so it is reported once.
     *
     * @return 'date'|'non_number'|null
     */
    public function numberNeverHeld(): ?string
    {
        return match ($this) {
            self::Date, self::Time, self::Datetime => 'date',
            self::None, self::Boolean, self::List, self::Attachment => 'non_number',
            self::Number, self::Computed, self::Value, self::Object => null,
        };
    }

    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::Time || $this === self::Datetime;
    }

    // ── What the CONDITION EDITOR may offer (Increment M134, `R-910d2286`) ─────────────────────────────────────
    // Transmitted by `BuilderPresenter::enums()` as `operand_kinds`, so the editor offers a SUBSET of what the
    // publish gate accepts and never restates it (`ConditionOffersPublishTest` holds the subset). Narrower than the
    // gate on purpose: the gate lets a date order against text that MAY hold a date; the editor offers only dates.

    /** Whether a condition may name such a question at all. Never a note or a page break (no answer), a grid or a point. */
    public function offered(): bool
    {
        return match ($this) {
            self::Number, self::Computed, self::Date, self::Time, self::Datetime, self::Value,
            self::Boolean, self::List, self::Attachment => true,
            self::Object, self::None => false,
        };
    }

    /** more than / less than / at least / at most. */
    public function orders(): bool
    {
        return $this->ordersWith() !== [];
    }

    /** is / is not — never a list or a file, whose answer never equals one value. */
    public function equals(): bool
    {
        return match ($this) {
            self::Number, self::Computed, self::Date, self::Time, self::Datetime, self::Value, self::Boolean => true,
            self::List, self::Attachment, self::Object, self::None => false,
        };
    }

    /** includes / does not include — `selected()`. */
    public function includes(): bool
    {
        return $this === self::Value || $this === self::Boolean || $this === self::List;
    }

    /**
     * The kinds an ordering may compare a question of this kind with, in the editor.
     *
     * @return list<self>
     */
    public function ordersWith(): array
    {
        return match ($this) {
            self::Number, self::Computed, self::Value => [self::Number, self::Computed, self::Value],
            self::Date, self::Datetime => [self::Date, self::Datetime],
            self::Time => [self::Time],
            self::Boolean, self::List, self::Attachment, self::Object, self::None => [],
        };
    }

    /** The browser input a fixed value beside such a question uses: a date, time or date-and-time picker, or null. */
    public function literalInput(): ?string
    {
        return match ($this) {
            self::Date => 'date',
            self::Time => 'time',
            self::Datetime => 'datetime-local',
            self::Number, self::Computed, self::Value, self::Boolean, self::List,
            self::Attachment, self::Object, self::None => null,
        };
    }

    /**
     * Can two temporal kinds be ordered against each other? A date and a date-time can (a date is its midnight); a
     * time only against a time — the engines' `Temporal` reads every other pair as never holding.
     */
    public function ordersTemporallyWith(self $other): bool
    {
        if (! $this->isTemporal() || ! $other->isTemporal()) {
            return false;
        }

        return ($this === self::Time) === ($other === self::Time);
    }
}
