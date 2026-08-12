<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Support\Forms\FormScheduleView;
// Both collections are in play and they are not interchangeable. `Collection` here is the ELOQUENT one,
// because `modelKeys()` exists only on that and `FormPresenter::list()` hands over the result of
// `->get()`; `SupportCollection` is what `toBase()->get()` returns, whose rows are `stdClass`, not models.
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The forms list's per-row counts (JR3), gathered for the WHOLE page in a constant number of
 * queries rather than per row.
 *
 * ⚠️ WHY THIS CLASS EXISTS AT ALL: {@see FormHubPresenter::stats()} answers the same question for ONE
 * form and costs THREE queries doing it (a max + two counts, each off a fresh builder). Reusing it per
 * row would make the forms list 3N — on a page that has no pagination and today issues a constant 2–4
 * queries regardless of how many forms a tenant owns. That constancy is the property being protected.
 *
 * ⚠️ AND WHY IT IS TWO QUERIES RATHER THAN ONE. They are not two halves of one question; they are two
 * questions with DIFFERENT VISIBILITY RULES, and folding them together corrupts one or the other:
 *
 *   - the stats are visibility-scoped, because "responses" means the ones this reader may see;
 *   - the capacity count is deliberately NOT, because a form that is full is full for everyone
 *     (FormHubPresenter::schedule()'s docblock argues this at length — a `visibleTo()` there would let
 *     two roles disagree about whether a form is still accepting answers).
 *
 * Both are O(1) in the number of forms, which is what "one grouped query, never per-row" is actually
 * asking for. The capacity query is skipped entirely when no visible form is capped, matching
 * `PublicFormPresenter::schedule()`'s rule that an uncapped form pays for no count.
 *
 * ⚠️ THE `FILTER` PREDICATES ARE NOT `countable()` / the draft scope CALLED — they cannot be, because
 * one query has to produce both a non-draft and a draft aggregate and a scope emits a WHERE that would
 * bind the whole statement. They are written from {@see SubmissionStatus::Draft} rather than a string
 * literal, and `FormListCardDataTest` pins the result against `FormHubPresenter::stats()` for the same
 * form and user — so if `countable()` is ever widened, the hub moves, this does not, and the test goes
 * red. That parity assertion is a stronger guarantee than copying the scope body would have been.
 *
 * ⚠️ ROOTED ON `Submission`, NEVER JOINED OUT FROM `forms`. The `deleted_at IS NULL` half comes from
 * the SoftDeletes GLOBAL SCOPE, which only applies when Submission is the query root —
 * `Submission::scopeCountable()`'s own docblock says so. A `leftJoin` from `forms` would count
 * soft-deleted rows with nothing to notice.
 *
 * ⚠️ AND `toBase()` DOES NOT UNDO THAT, which is worth stating because it looks like it might. These
 * rows are aggregates, not models, so hydrating them into `Submission` instances would be waste; the
 * base builder returns `stdClass`. `Builder::toBase()` runs `applyScopes()` BEFORE handing back the
 * underlying query, so every global scope — SoftDeletes included — is already compiled into it. That is
 * a load-bearing framework detail rather than an obvious one, so `FormListCardDataTest` drives a
 * soft-deleted submission through this path rather than trusting the paragraph.
 */
final class FormListMetrics
{
    /**
     * @param  array<string, array{responses: int, drafts: int, last_response_at: ?string}>  $stats
     * @param  array<string, int>  $consumed
     */
    private function __construct(
        private readonly array $stats,
        private readonly array $consumed,
    ) {}

    /**
     * @param  Collection<int, Form>  $forms
     */
    public static function for(Collection $forms, User $user): self
    {
        if ($forms->isEmpty()) {
            return new self([], []);
        }

        return new self(self::gatherStats($forms, $user), self::gatherConsumed($forms));
    }

    /**
     * @return array{responses: int, drafts: int, last_response_at: ?string}
     */
    public function stats(string $formId): array
    {
        return $this->stats[$formId] ?? ['responses' => 0, 'drafts' => 0, 'last_response_at' => null];
    }

    /**
     * The capacity-consuming count, or null for an uncapped form — which is exactly the argument
     * {@see FormScheduleView::present()} takes, so callers hand this straight over.
     */
    public function consumed(Form $form): ?int
    {
        if ($form->max_responses === null) {
            return null;
        }

        return $this->consumed[(string) $form->id] ?? 0;
    }

    /**
     * @param  Collection<int, Form>  $forms
     * @return array<string, array{responses: int, drafts: int, last_response_at: ?string}>
     */
    private static function gatherStats(Collection $forms, User $user): array
    {
        $draft = SubmissionStatus::Draft->value;

        /** @var SupportCollection<int, \stdClass> $rows */
        $rows = Submission::query()
            ->whereIn('form_id', $forms->modelKeys())
            // Called AS A SCOPE, never inlined: the closure wrapper that keeps its `whereIn ... orWhere`
            // pair from re-associating against the surrounding predicate is applied by
            // `Builder::callScope()`, and evaporates the moment the body is hoisted (see its docblock).
            ->visibleTo($user)
            ->groupBy('form_id')
            ->selectRaw('form_id')
            ->selectRaw('count(*) filter (where submissions.status <> ?) as responses', [$draft])
            ->selectRaw('count(*) filter (where submissions.status = ?) as drafts', [$draft])
            ->selectRaw('max(submitted_at) filter (where submissions.status <> ?) as last_response_at', [$draft])
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->form_id] = [
                'responses' => (int) $row->responses,
                'drafts' => (int) $row->drafts,
                // Left as the driver's own string, exactly as FormHubPresenter::stats() does — the two
                // have to be comparable for the parity test to mean anything.
                'last_response_at' => $row->last_response_at !== null ? (string) $row->last_response_at : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Form>  $forms
     * @return array<string, int>
     */
    private static function gatherConsumed(Collection $forms): array
    {
        $capped = $forms->filter(fn (Form $form): bool => $form->max_responses !== null);

        if ($capped->isEmpty()) {
            return [];
        }

        /** @var SupportCollection<int, \stdClass> $rows */
        $rows = Submission::query()
            ->whereIn('form_id', $capped->modelKeys())
            // Verbatim, and NOT `countable()`: a screened-out respondent was finalized but shown no
            // questions, so charging them a paid slot is the I9a bug. The scope also spells its two
            // exclusions as separate `!=` conjuncts on purpose — the partial index proves the
            // implication from those and not from a `whereNotIn`.
            ->consumesCapacity()
            ->groupBy('form_id')
            ->selectRaw('form_id, count(*) as consumed')
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->form_id] = (int) $row->consumed;
        }

        return $out;
    }
}
