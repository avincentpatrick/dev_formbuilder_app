<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Enums\ExplorerFieldType;
use App\Models\Form;
use App\Models\User;
use App\Support\Explorer\ExplorerField;
use App\Support\Explorer\ExplorerQuery;
use BackedEnum;
use Illuminate\Support\Str;

/**
 * The parts every data-explorer source would otherwise write out again.
 */
abstract class AbstractExplorerSource implements ExplorerSource
{
    public function requiresForm(): bool
    {
        return false;
    }

    public function defaultRangeDays(): int
    {
        return ExplorerQuery::DEFAULT_RANGE_DAYS;
    }

    public function notes(User $user, ?Form $form): array
    {
        return [];
    }

    /**
     * A field whose values are the cases of an enum.
     *
     * The labels come from the enum's own `label()` where it has one, so a status reads here exactly as it
     * reads in the inbox; an enum without one is printed as a headline of its value.
     *
     * @param  literal-string  $expression
     * @param  class-string<BackedEnum>  $enum
     * @param  list<BackedEnum>  $except  cases this source can never return, so a filter does not offer them
     */
    protected function enumField(string $key, string $label, string $expression, string $enum, string $group = '', array $except = []): ExplorerField
    {
        $options = [];

        foreach ($enum::cases() as $case) {
            if (in_array($case, $except, true)) {
                continue;
            }

            $options[(string) $case->value] = method_exists($case, 'label')
                ? (string) $case->label()
                : Str::headline((string) $case->value);
        }

        return new ExplorerField($key, $label, ExplorerFieldType::Category, $expression, $group, options: $options);
    }

    /** @param  literal-string  $expression */
    protected function category(string $key, string $label, string $expression, string $group = '', ?string $lookup = null, bool $headline = false): ExplorerField
    {
        return new ExplorerField($key, $label, ExplorerFieldType::Category, $expression, $group, lookup: $lookup, headline: $headline);
    }

    /** @param  literal-string  $expression */
    protected function boolean(string $key, string $label, string $expression, string $group = ''): ExplorerField
    {
        return new ExplorerField($key, $label, ExplorerFieldType::Boolean, $expression, $group, options: ['true' => 'Yes', 'false' => 'No']);
    }

    /** @param  literal-string  $expression */
    protected function number(string $key, string $label, string $expression, string $group = ''): ExplorerField
    {
        return new ExplorerField($key, $label, ExplorerFieldType::Number, $expression, $group);
    }

    /** @param  literal-string  $expression */
    protected function time(string $key, string $label, string $expression, string $group = ''): ExplorerField
    {
        return new ExplorerField($key, $label, ExplorerFieldType::Time, $expression, $group);
    }
}
