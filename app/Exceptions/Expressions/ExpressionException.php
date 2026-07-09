<?php

declare(strict_types=1);

namespace App\Exceptions\Expressions;

use App\Enums\ExpressionKind;
use RuntimeException;

/**
 * Base for every expression-engine failure (technical-architecture.md §4.3). Carries an optional
 * `fieldKey` + `ExpressionKind` so Stage-3 validation (F3) can attach the error to the offending field,
 * and a stable `slug()` (snake_case) that the golden-file `expected_error` matches — never a message
 * string, so wording can change without breaking the cross-engine contract. No `render()`: HTTP mapping
 * is centralised in bootstrap/app.php by the consumer increment, mirroring app/Exceptions/Forms/*.
 *
 * @see ExpressionSyntaxException compile/parse-time (an authoring error, surfaced at build/publish)
 * @see ExpressionEvaluationException runtime integrity failure (should be impossible post-parse)
 */
abstract class ExpressionException extends RuntimeException
{
    protected ?string $fieldKey = null;

    protected ?ExpressionKind $expressionKind = null;

    public function __construct(string $message, protected readonly string $slug)
    {
        parent::__construct($message);
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function fieldKey(): ?string
    {
        return $this->fieldKey;
    }

    public function expressionKind(): ?ExpressionKind
    {
        return $this->expressionKind;
    }

    public function withField(?string $fieldKey, ?ExpressionKind $kind = null): static
    {
        $this->fieldKey = $fieldKey;

        if ($kind !== null) {
            $this->expressionKind = $kind;
        }

        return $this;
    }
}
