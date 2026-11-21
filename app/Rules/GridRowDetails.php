<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Forms\GridRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * M246 (`grid-question-table`) — a grid row's details: note content with no picture, within the row-details bounds
 * ({@see GridRules::detailsProblem()}). Lenient here, as an author mid-edit has a blank paragraph; strict at publish.
 */
final class GridRowDetails implements ValidationRule
{
    public function __construct(private readonly bool $strict = false) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = GridRules::detailsProblem($value, $this->strict);

        if ($problem !== null) {
            $fail("The row's details are invalid: {$problem}.");
        }
    }
}
