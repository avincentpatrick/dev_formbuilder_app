<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use DateTimeInterface;

/**
 * One spreadsheet cell as the text a person typed (G7b's rule, shared since M184 by the XLSForm reader and the choice-list
 * reader).
 *
 * ⚠️ A NUMBER CELL HAS ALREADY LOST ITS LEADING ZERO. OpenSpout returns a numeric cell as an int or a float and applies no
 * number format but a date's, so a code Excel stored as the number 1 arrives as 1 — even if the sheet showed "01". Only a
 * cell stored as text keeps "01". Nothing here can bring the zero back; the choice-list upload says so to the author
 * (`R-1059e9bd`). An integer-valued float loses its ".0" tail (a repeat count of 5, a code of 1).
 */
final class CellText
{
    public static function of(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }
        if (is_int($value) || is_float($value)) {
            return $value == (int) $value ? (string) (int) $value : (string) $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
