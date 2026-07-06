<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The eight field-type categories (data-dictionary FieldType catalog). Returned by
 * {@see FieldType::category()} so the builder can group the palette without a second lookup table.
 */
enum FieldCategory: string
{
    case Text = 'text';
    case Numeric = 'numeric';
    case DateTime = 'date_time';
    case Choice = 'choice';
    case Likert = 'likert';
    case Geographic = 'geographic';
    case Media = 'media';
    case Structural = 'structural';

    /** Human label for the builder palette group header. */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Numeric => 'Number',
            self::DateTime => 'Date & time',
            self::Choice => 'Choice',
            self::Likert => 'Rating',
            self::Geographic => 'Location',
            self::Media => 'Media',
            self::Structural => 'Layout',
        };
    }

    /** The Meridian icon (Icon registry name) shown against this category in the palette. */
    public function icon(): string
    {
        return match ($this) {
            self::Text => 'type',
            self::Numeric => 'hash',
            self::DateTime => 'calendar',
            self::Choice => 'list',
            self::Likert => 'sliders',
            self::Geographic => 'map-pin',
            self::Media => 'image',
            self::Structural => 'layout',
        };
    }
}
