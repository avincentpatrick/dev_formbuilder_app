<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Form;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Services\Audit\AuditLogPresenter;
use App\Services\Dashboard\DashboardMetricsService;
use Illuminate\Support\Str;

/**
 * THE address of a form (Increment J2d) — the one place `/forms/{id}` is spelled, and the one predicate that
 * decides whether a given reader may be handed it.
 *
 * ── WHY A LINK NEEDS A PREDICATE AT ALL ────────────────────────────────────────────────────────────────
 * J2b gave a form an address; J2d hands that address to the six surfaces that name a form and link nowhere.
 * Every one of those surfaces resolves its form titles through a query that is deliberately WIDER than the
 * hub: {@see DashboardMetricsService} and {@see AuditLogPresenter} both use `withTrashed()`, on purpose —
 * "a soft-deleted form otherwise renders as a bare uuid on the very row recording that it was archived".
 * Naming a form and reaching it are therefore different questions, and a link that assumes they are the same
 * ships a 404 on precisely the rows most worth reading.
 *
 * ── THE PREDICATE, AND WHY EVERY OTHER CANDIDATE IS A COINCIDENCE ──────────────────────────────────────
 * `/forms/{form}` resolves through DEFAULT route-model binding (there is no `Route::bind` and no
 * `resolveRouteBinding()` override) gated by `can:viewOverview,form`. {@see Form::scopeReadableBy()} is
 * exactly those two questions in one query: the default soft-delete scope answers "will binding resolve
 * it", and its two conjuncts are byte-for-byte {@see FormPolicy::viewOverview()}. So membership of that
 * scope is not merely correlated with reachability — it IS reachability, which is what lets every call site
 * reduce to `?? null`.
 *
 * The three predicates NOT used here, each of which reads as equivalent and is not:
 *   • a `withTrashed()` label lookup — answers NAMING, not reachability. That is the whole 404.
 *   • "the eager-loaded relation resolved" (the shape `WebhookEndpointPresenter` invites) — true only
 *     because that eager load omits `withTrashed()` today. Add it for a better LABEL, which is a live
 *     defect there, and every link built on the coincidence starts 404ing silently.
 *   • `$form->trashed()` — returns **false** on a model whose `select` omitted `deleted_at`, so it emits a
 *     404 link with no error anywhere. Never expose a shape that invites it.
 *
 * ── FAIL-CLOSED BY OMISSION ────────────────────────────────────────────────────────────────────────────
 * {@see pathsFor()} returns a map missing every id it will not vouch for — trashed, hard-deleted, or
 * out-of-grant alike, with no branch distinguishing them. A caller that forgets the guard gets `null` and
 * renders text, which is the pre-J2d behaviour; there is no spelling of the call that produces a bad link.
 * The cost is one PK-indexed query per surface over an already-bounded id list. That query buys the removal
 * of a silent failure mode no test naturally covers, and is the right trade.
 */
final class FormHubLink
{
    /**
     * The hub's path. Callers must ALREADY have established that this reader can reach this form.
     *
     * ⚠️ Permitted only where reachability is proven by the surrounding scope, and every such call site
     * states its proof in a comment and carries a test that GETs the emitted URL as the NARROWEST role
     * reaching that surface. Two qualify today: `FormSearchArm` (scoped `readableBy`, the same scope) and
     * `CrumbTrail` (which asks `viewOverview` itself, per form, one crumb at a time). Everywhere a set of
     * ids arrives from a wider query — every presenter — use {@see pathsFor()} instead.
     */
    public static function path(string $formId): string
    {
        return '/forms/'.$formId;
    }

    /**
     * The reachable subset of `$formIds`, as `id => path`.
     *
     * ⚠️ Ids this reader cannot reach are ABSENT, not mapped to null — so `$paths[$id] ?? null` is the whole
     * guard at every call site and there is no third state to handle. An empty input short-circuits, because
     * `whereIn('id', [])` is a query worth not issuing on a dashboard that may have no forms at all.
     *
     * ⚠️ NON-UUID ENTRIES ARE DROPPED BEFORE THE QUERY, AND THAT IS A CRASH GUARD, NOT TIDINESS.
     * `forms.id` is a Postgres `uuid` column, so a `whereIn` carrying `'unassigned'` raises SQLSTATE 22P02
     * — "invalid input syntax for type uuid" — which surfaces as a **500 on the dashboard**, not as an empty
     * result. And callers feed this raw BUCKET KEYS by design: the top-forms and breakdown charts both carry
     * `'unassigned'` / `'other'` aggregate rows beside real uuids. Filtering here rather than at each of the
     * six call sites is the point of the seam; `FormHubLinkTest` reproduces the crash directly, because it
     * was found by writing that case rather than by reasoning about it.
     *
     * ⚠️ THE PARAMETER IS `mixed[]` ON PURPOSE, NOT `list<string>`. Callers hand this raw chart-bucket keys
     * and plucked column values — `'unassigned'`, `'other'`, nulls, non-list arrays — and the whole point of
     * the seam is that it defends itself rather than making six call sites each cast first. A narrower
     * signature would move the filtering back out to the callers that were most likely to skip it.
     *
     * @param  array<array-key, mixed>  $formIds
     * @return array<string, string>
     */
    public static function pathsFor(User $user, array $formIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $formIds,
            static fn (mixed $id): bool => is_string($id) && Str::isUuid($id),
        )));

        if ($ids === []) {
            return [];
        }

        return Form::query()
            ->readableBy($user)
            ->whereIn('forms.id', $ids)
            ->pluck('forms.id')
            ->mapWithKeys(static fn (string $id): array => [$id => self::path($id)])
            ->all();
    }
}
