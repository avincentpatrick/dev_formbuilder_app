<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The typed projection target for a queryable field (data-dictionary §5). Required whenever
 * `form_fields.is_queryable = true`; tells the projection job which `submission_answer_index.value_*`
 * column to populate.
 */
enum IndexedDataType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Datetime = 'datetime';
}
