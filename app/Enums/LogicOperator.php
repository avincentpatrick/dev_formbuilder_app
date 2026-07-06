<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Boolean connective for compound validation conditions (data-dictionary §6). Only meaningful when a
 * `form_field_validations.logic_group` is set, joining the rows sharing that group.
 */
enum LogicOperator: string
{
    case And = 'and';
    case Or = 'or';
}
