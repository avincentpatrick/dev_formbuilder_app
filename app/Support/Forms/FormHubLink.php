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
 * would ship a 404 on rows worth reading.
 *
 * ⚠️ THAT DIVERGENCE IS CURRENTLY LATENT, AND THIS DOCBLOCK SAYS SO RATHER THAN OVERSTATING ITS OWN VALUE.
 * No product path soft-deletes a form: `Form` uses `SoftDeletes` and `FormPolicy::delete()` exists, but
 * there is no delete route and no `$form->delete()` in `app/`, and `FormService::archive()` sets `status` +
 * `archived_at` only — an ARCHIVED form's hub resolves 200 and stays linked everywhere. What is genuinely
 * live is the reader half: `readableBy` refuses a form the caller holds no grant on, which the audit ledger
 * and both charts can otherwise name.
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
 *
 * The cost is one PK-indexed query per surface. ⚠️ FIVE OF THE SIX CALL SITES PASS A GENUINELY BOUNDED LIST
 * (one page of audit rows, the top-N chart rows, one endpoint, one rule); `AnalyticsPresenter::formOptions()`
 * is the exception — it feeds every form the reader can see, on every `/analytics` render, and could fold
 * this into the query directly above it. Left as-is because that surface already runs an unbounded
 * `whereIn` of its own and merging them is an optimisation with its own correctness question (the label
 * query needs `withTrashed()` and this one must not have it); recorded here so the next author sees the
 * asymmetry rather than inheriting a claim that every site is bounded.
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
     * ⚠️ NON-UUID ENTRIES ARE DROPPED BEFORE THE QUERY, AND THAT IS A CRASH GUARD RATHER THAN TIDINESS —
     * BUT NO LIVE CALLER CAN FIRE IT TODAY, AND THE FIRST VERSION OF THIS NOTE CLAIMED OTHERWISE.
     *
     * The mechanism is real: `forms.id` is a Postgres `uuid` column, so a `whereIn` carrying `'unassigned'`
     * raises SQLSTATE 22P02 — "invalid input syntax for type uuid" — a **500**, not an empty result, and
     * SQLite would coerce it silently. `FormHubLinkTest` reproduces it directly.
     *
     * What this note used to say was that the charts "feed this raw bucket keys by design … every one of the
     * six call sites would have hit it". **That is false**, and checking it is what corrected it:
     * `AnalyticsMetricsService::breakdown()` hoists both aggregates into SIBLING keys (`unassigned` is
     * summed into an int and `continue`d; the top-N remainder becomes `other`), so `rows` only ever carries
     * real uuids — and both chart call sites additionally `array_filter` before calling. The other four pass
     * `auditable_id`, `form_id` or a plucked `forms.id`.
     *
     * The filter STAYS, labelled as what it is: a fail-closed guard on a public seam whose whole purpose is
     * that six call sites need not each remember to sanitise. It is not evidence that any of them forgot.
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
