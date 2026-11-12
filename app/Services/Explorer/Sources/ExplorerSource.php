<?php

declare(strict_types=1);

namespace App\Services\Explorer\Sources;

use App\Models\Form;
use App\Models\User;
use App\Support\Explorer\ExplorerField;
use Illuminate\Database\Query\Builder;

/**
 * One data source of the data explorer (ADR-0025 §D2, §D5).
 *
 * A source answers three questions and the engine asks nothing else of it: MAY this reader use it
 * ({@see allows()}), WHICH ROWS may they see ({@see query()}), and WHAT can be charted
 * ({@see fields()}). Adding a source is adding one class and one line in the catalogue; the engine,
 * the page and the saved-chart format do not change.
 *
 * ⛔ `allows()` and `query()` must repeat the rule the source's OWN page enforces — the inbox for
 * responses, `/audit` for activity. The explorer is a second way to read data a member can already read.
 * It must never be a first way to read something they cannot.
 */
interface ExplorerSource
{
    public function key(): string;

    public function label(): string;

    /** One sentence for the source picker: what a row of this source is. */
    public function description(): string;

    /** What one row is called, plural, as in "Count of responses". */
    public function rowNoun(): string;

    /** Whether a chart on this source must name a form. */
    public function requiresForm(): bool;

    /** The range a new chart on this source opens with. */
    public function defaultRangeDays(): int;

    public function allows(User $user): bool;

    /**
     * The key of the `Time` field the chart's date range applies to. Every source has one, because every
     * explorer query is time-bounded (ADR-0011 §D7).
     */
    public function rangeField(): string;

    /** @return list<ExplorerField> */
    public function fields(User $user, ?Form $form): array;

    /**
     * The rows this reader may aggregate, before the range and the chart's own filters.
     *
     * Always constrained to the current tenant in SQL as well as by row-level security: every index on
     * these tables leads with `tenant_id`.
     */
    public function query(User $user, ?Form $form): Builder;

    /**
     * Sentences the page shows beside every chart on this source — what was left out, and why.
     *
     * @return list<string>
     */
    public function notes(User $user, ?Form $form): array;
}
