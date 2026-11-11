<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a data-explorer filter compares a field (ADR-0025 §D4).
 */
enum ExplorerFilterOperator: string
{
    case In = 'in';
    case NotIn = 'not_in';
    case AtLeast = 'gte';
    case AtMost = 'lte';
    case IsEmpty = 'is_empty';
    case IsNotEmpty = 'not_empty';

    public function label(): string
    {
        return match ($this) {
            self::In => 'is any of',
            self::NotIn => 'is none of',
            self::AtLeast => 'is at least',
            self::AtMost => 'is at most',
            self::IsEmpty => 'is empty',
            self::IsNotEmpty => 'is not empty',
        };
    }

    public function takesValues(): bool
    {
        return $this !== self::IsEmpty && $this !== self::IsNotEmpty;
    }

    public function appliesTo(ExplorerFieldType $type): bool
    {
        return match ($this) {
            self::In, self::NotIn => $type === ExplorerFieldType::Category
                || $type === ExplorerFieldType::Boolean
                || $type === ExplorerFieldType::MultiCategory,
            self::AtLeast, self::AtMost => $type === ExplorerFieldType::Number,
            self::IsEmpty, self::IsNotEmpty => ! $type->isTemporal(),
        };
    }
}
