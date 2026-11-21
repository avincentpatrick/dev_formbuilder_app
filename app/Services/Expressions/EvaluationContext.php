<?php

declare(strict_types=1);

namespace App\Services\Expressions;

/**
 * The read-only evaluation context (technical-architecture.md §4.3 §7): a flat, ALREADY relevance-pruned
 * answer map (`${key}` → value) plus an optional `self` value for the `.` reference in a constraint and an
 * optional injected clock (`now`, grammar v2.0). Relevance pruning is the caller's job (F3 builds the
 * effective set); the interpreter has no notion of a "retained-for-restore" value — an irrelevant field is
 * simply absent here, resolving to EMPTY.
 *
 * The `now` clock is an ISO-8601 datetime string the CALLER stamps (the PHP pipeline uses server time; the
 * golden vectors provide a fixed value) so `today()`/`now()` are deterministic and identical across the two
 * engines — the engine never reads the system clock itself. `today()` is the leading `YYYY-MM-DD` of `now`.
 *
 * Frozen answer-value shape (the engines cannot diverge on it): scalar string for text/choice/date;
 * int/float for integer/decimal; ALWAYS a `list<string>` for multi_select (never a space-delimited
 * scalar — the Phase-2 XLSForm layer translates the wire form before an answer reaches here); null or an
 * absent key for unanswered. A repeatable section's key resolves to its `list<instanceMap>` (for `count()`).
 *
 * M246: a grid's key resolves to its answer object, read only through a cell reference (`${key.row.col}`); and
 * `row` is the current row's cells while a grid column's own rule runs, read by `${.col}` — null everywhere else.
 */
final readonly class EvaluationContext
{
    private const UNSET = "\0__unset__\0";

    /**
     * @param  array<string, mixed>  $answers  relevance-pruned effective answers, key => value
     * @param  array<array-key, mixed>|null  $row  M246: the current grid row's cells, for `${.col}`
     */
    public function __construct(
        public array $answers,
        public mixed $self = self::UNSET,
        public ?string $now = null,
        public ?array $row = null,
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

    public function hasNow(): bool
    {
        return $this->now !== null;
    }

    /**
     * Golden-runner factory.
     *
     * @param  array{answers?: array<string, mixed>, self?: mixed, now?: string, row?: array<array-key, mixed>}  $vector
     */
    public static function fromVector(array $vector): self
    {
        return new self(
            $vector['answers'] ?? [],
            array_key_exists('self', $vector) ? $vector['self'] : self::UNSET,
            $vector['now'] ?? null,
            $vector['row'] ?? null,
        );
    }
}
