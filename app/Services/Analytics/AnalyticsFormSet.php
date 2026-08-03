<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\AnalyticsFormSelection;
use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Analytics\AnalyticsQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves an {@see AnalyticsQuery}'s form selection, composed with the requesting user's visibility, into
 * ONE subquery of `forms.id` (Increment H24a).
 *
 * Everything an analytics aggregate is allowed to count passes through here, which is what makes the
 * authorization boundary a single place rather than a rule every query method has to remember.
 *
 * ── Three things that must be got right ─────────────────────────────────────────────────────────────────
 *
 * 1. **One subquery, not two.** {@see ResourceGrantResolver::grantedFormIdsQuery()} returns a CHAINABLE
 *    `Builder<Form>`, so the selection predicate is applied to that builder and the single result handed to
 *    one `whereIn`. Resolving visibility and selection independently would produce two semi-joins against
 *    `forms` for no gain.
 *
 * 2. **`Form::withTrashed()` in BOTH arms.** `ResourceGrantResolver::formScope()` is `withTrashed()`,
 *    deliberately — its docblock records that submissions of a soft-deleted form stay visible to a
 *    grant-holder, and that dropping it would be an unflagged behaviour change. If the org-wide arm here
 *    built its set from a soft-delete-scoped `Form::query()` while the scoped arm inherited `withTrashed()`,
 *    an Owner's total and a Form Editor's total would disagree about exactly those submissions — an
 *    ADR-0011 §D2-class drift *inside one feature*. Today `DashboardMetricsService` hides this only because
 *    its org-wide arm adds no form predicate at all.
 *
 * 3. **The subtree predicate is called, never copied.** §D6 adopts `nodeReach()`'s semantics — a deactivated
 *    node and its whole branch are excluded — and H24a extracted
 *    {@see ResourceGrantResolver::subtreeFormIdsQuery()} so this is its second call site rather than the
 *    repository's third hand-written copy of the predicate.
 */
final class AnalyticsFormSet
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * The forms this query may aggregate over, as a subquery of `forms.id`.
     *
     * @return Builder<Form>
     */
    public function resolve(AnalyticsQuery $query, User $user): Builder
    {
        $base = $this->visible($user);

        return match ($query->selection) {
            AnalyticsFormSelection::All => $base,

            AnalyticsFormSelection::Forms => $base->whereIn('forms.id', $query->formIds),

            // Intersected rather than substituted: a subtree selection can never widen what the user may
            // already see, so a Form Editor selecting a region they hold no grant on gets their own forms
            // within it, not the region's.
            AnalyticsFormSelection::ScopeNode => $base->whereIn(
                'forms.id',
                $this->grants->subtreeFormIdsQuery($this->node($query->scopeNodeId)),
            ),
        };
    }

    /**
     * The user's whole visible form set, ignoring any query's selection.
     *
     * Public because `docs/PRD.md:197`'s "forms currently accepting responses" is a **right now** figure, not
     * a range-scoped one: it answers "what can a respondent reach today", so narrowing it by a report's date
     * range or form selection would answer a different question.
     *
     * @return Builder<Form>
     */
    public function resolveAllVisible(User $user): Builder
    {
        return $this->visible($user);
    }

    /**
     * The user's whole visible form set — the same `dashboard.org.view` split
     * {@see Submission::scopeVisibleTo()} applies, so an analytics total can never exceed the
     * inbox for the same user.
     *
     * @return Builder<Form>
     */
    private function visible(User $user): Builder
    {
        if ($user->can('dashboard.org.view')) {
            return Form::withTrashed()->select('forms.id');
        }

        // Already `formScope()->select('forms.id')` internally, and already an explicit empty set
        // (`whereRaw('false')`) for a user holding no grants — never an unconstrained query.
        return $this->grants->grantedFormIdsQuery($user);
    }

    /**
     * RLS scopes the lookup to the tenant, so a node id from another tenant resolves to nothing. Deliberately
     * `findOrFail`: a saved view naming a node that has since been deleted is §D8's read-time degradation and
     * must surface as a stated refusal, not as a silently tenant-wide result — which is what returning "all
     * forms" on a missing node would do.
     */
    private function node(?string $scopeNodeId): ScopeNode
    {
        return ScopeNode::query()->findOrFail($scopeNodeId);
    }
}
