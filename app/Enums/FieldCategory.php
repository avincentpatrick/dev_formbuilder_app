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
}
