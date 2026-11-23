<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Exceptions\Explorer\InvalidExplorerQueryException;

/**
 * What an agreement list counts (M250, `agreement-report`, `D234`; ADR-0025 §D10).
 *
 * An agreement list names the questions to report on, the answers that are not a view (NHETE's "No view or
 * opinion"), optionally the answers that count together as agreeing, a threshold and a minimum number of views.
 * It is part of the DECLARATION, as {@see ExplorerQuery::$grid} is: stored only when set, so no chart saved before
 * it reads or hashes differently.
 *
 * ── A pick, not an item ─────────────────────────────────────────────────────────────────────────────────
 * A pick is what the author ticks: `answer:{key}` (a choice question), `grid:{key}:{column}` (one choice column of a
 * question table, which reports every row of it as an item) or `grid:{key}` (a Likert grid, every row an item). A
 * 35-row table is therefore one pick, not 35 — the reason ADR-0025 §D9 gave for not offering each cell as a field.
 * Every part of a pick matches the question-key pattern, so a pick holds no quote, no colon of its own, and nothing
 * SQL gives meaning to; the runner binds it as data regardless.
 *
 * Bounded by construction, as the query is: a stored definition and a request pass the same checks.
 */
final readonly class ExplorerAgreement
{
    public const int MAX_PICKS = 100;

    /** The most items the picks may expand to, once a table's rows are counted. */
    public const int MAX_ITEMS = 500;

    public const int MAX_MARKS = 50;

    public const int MAX_MARK_LENGTH = 200;

    public const int DEFAULT_THRESHOLD = 75;

    public const int MIN_THRESHOLD = 50;

    public const int MAX_THRESHOLD = 100;

    public const int DEFAULT_MIN_VIEWS = 6;

    public const int MAX_MIN_VIEWS = 1000;

    /** A question key, as `AnswersSource::KEY_PATTERN` reads one; two or three of them make a pick. */
    private const string PART = '[A-Za-z0-9_.\-]{1,150}';

    /**
     * @param  list<string>  $items  the picks, in the order the author ticked them
     * @param  list<string>  $leaveOut  answers that are not a view: a response giving only these gave none
     * @param  list<string>  $agreeing  answers that count together as agreeing, where an item offers one of them
     */
    public function __construct(
        public array $items,
        public array $leaveOut = [],
        public array $agreeing = [],
        public int $threshold = self::DEFAULT_THRESHOLD,
        public int $minViews = self::DEFAULT_MIN_VIEWS,
    ) {
        if ($items === []) {
            throw InvalidExplorerQueryException::agreementShape('needs at least one question to report on');
        }

        if (count($items) > self::MAX_PICKS) {
            throw InvalidExplorerQueryException::tooMany('questions', self::MAX_PICKS);
        }

        if (count(array_unique($items)) !== count($items)) {
            throw InvalidExplorerQueryException::agreementShape('names the same question twice');
        }

        foreach ($items as $item) {
            if (self::parse($item) === null) {
                throw InvalidExplorerQueryException::malformed('it names a question that is not a question key');
            }
        }

        foreach (['answers left out' => $leaveOut, 'answers counted as agreeing' => $agreeing] as $what => $marks) {
            if (count($marks) > self::MAX_MARKS) {
                throw InvalidExplorerQueryException::tooMany($what, self::MAX_MARKS);
            }

            foreach ($marks as $mark) {
                if ($mark === '' || strlen($mark) > self::MAX_MARK_LENGTH) {
                    throw InvalidExplorerQueryException::malformed('it names an answer that is not an answer');
                }
            }
        }

        if (array_intersect($leaveOut, $agreeing) !== []) {
            throw InvalidExplorerQueryException::agreementShape('cannot both leave an answer out and count it as agreeing');
        }

        if ($threshold < self::MIN_THRESHOLD || $threshold > self::MAX_THRESHOLD) {
            throw InvalidExplorerQueryException::agreementShape('needs a threshold from '.self::MIN_THRESHOLD.'% to '.self::MAX_THRESHOLD.'%');
        }

        if ($minViews < 1 || $minViews > self::MAX_MIN_VIEWS) {
            throw InvalidExplorerQueryException::agreementShape('needs a minimum of 1 to '.self::MAX_MIN_VIEWS.' views');
        }
    }

    /**
     * A pick's parts: the question key, and for a table its row-wise column; null when it is not a pick.
     *
     * @return array{kind: 'answer'|'grid', key: string, column: string|null}|null
     */
    public static function parse(string $pick): ?array
    {
        if (preg_match('/^answer:('.self::PART.')$/', $pick, $match) === 1) {
            return ['kind' => 'answer', 'key' => $match[1], 'column' => null];
        }

        if (preg_match('/^grid:('.self::PART.')(?::('.self::PART.'))?$/', $pick, $match) === 1) {
            return ['kind' => 'grid', 'key' => $match[1], 'column' => ($match[2] ?? '') === '' ? null : $match[2]];
        }

        return null;
    }

    /**
     * The stored shape. Every key is written, so a stored list reads back exactly as it was saved.
     *
     * @return array{items: list<string>, leave_out: list<string>, agreeing: list<string>, threshold: int, min_views: int}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'leave_out' => $this->leaveOut,
            'agreeing' => $this->agreeing,
            'threshold' => $this->threshold,
            'min_views' => $this->minViews,
        ];
    }

    /**
     * Read the agreement member of a declaration. Total over arbitrary input, as {@see ExplorerQuery::fromArray()} is.
     */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw InvalidExplorerQueryException::malformed('its agreement list is not an entry');
        }

        return new self(
            items: self::strings($data['items'] ?? null, 'questions', self::MAX_PICKS),
            leaveOut: self::strings($data['leave_out'] ?? [], 'answers left out', self::MAX_MARKS),
            agreeing: self::strings($data['agreeing'] ?? [], 'answers counted as agreeing', self::MAX_MARKS),
            threshold: self::whole($data['threshold'] ?? self::DEFAULT_THRESHOLD, 'threshold'),
            minViews: self::whole($data['min_views'] ?? self::DEFAULT_MIN_VIEWS, 'minimum number of views'),
        );
    }

    /** @return list<string> */
    private static function strings(mixed $values, string $what, int $max): array
    {
        if (! is_array($values)) {
            throw InvalidExplorerQueryException::malformed("its {$what} are not a list");
        }

        if (count($values) > $max) {
            throw InvalidExplorerQueryException::tooMany($what, $max);
        }

        $out = [];

        foreach ($values as $value) {
            // An answer stored as a number (a Likert score) is named by its text, as the answers read it.
            if (! is_string($value) && ! is_int($value)) {
                throw InvalidExplorerQueryException::malformed("one of its {$what} is not text");
            }

            $out[] = (string) $value;
        }

        return $out;
    }

    private static function whole(mixed $value, string $what): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,4}$/', $value) === 1) {
            return (int) $value;
        }

        throw InvalidExplorerQueryException::malformed("its {$what} is not a whole number");
    }
}
