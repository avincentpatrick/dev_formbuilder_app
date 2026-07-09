<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The read-only evaluation context (technical-architecture.md §4.3 §7): a flat, ALREADY relevance-pruned
 * answer map (`${key}` → value) plus an optional `self` value for the `.` reference in a constraint.
 * Relevance pruning is the caller's job (F3 builds the effective set); the interpreter has no notion of a
 * "retained-for-restore" value — an irrelevant field is simply absent here, resolving to EMPTY.
 *
 * Frozen answer-value shape (the engines cannot diverge on it): scalar string for text/choice/date;
 * int/float for integer/decimal; ALWAYS a `list<string>` for multi_select (never a space-delimited
 * scalar — the Phase-2 XLSForm layer translates the wire form before an answer reaches here); null or an
 * absent key for unanswered.
 */
final readonly class EvaluationContext
{
    private const UNSET = "\0__unset__\0";

    /** @param array<string, mixed> $answers relevance-pruned effective answers, key => value */
    public function __construct(
        public array $answers,
        public mixed $self = self::UNSET,
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->answers);
    }

    public function get(string $key): mixed
    {
        return $this->answers[$key] ?? null;
    }

    public function hasSelf(): bool
    {
        return $this->self !== self::UNSET;
    }

    /**
     * Golden-runner factory.
     *
     * @param  array{answers?: array<string, mixed>, self?: mixed}  $vector
     */
    public static function fromVector(array $vector): self
    {
        return new self(
            $vector['answers'] ?? [],
            array_key_exists('self', $vector) ? $vector['self'] : self::UNSET,
        );
    }
}
