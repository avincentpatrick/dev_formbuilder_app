<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tri-state field requiredness (data-dictionary §5), carried forward from legacy's 1/2/3 int, now
 * named. `conditional` means "required only when a `relevant`/`required_if` expression evaluates true".
 */
enum RequiredMode: string
{
    case Required = 'required';
    case Optional = 'optional';
    case Conditional = 'conditional';
}
