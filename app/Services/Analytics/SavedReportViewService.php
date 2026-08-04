<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\Analytics\InvalidAnalyticsQueryException;
use App\Models\SavedReportView;
use App\Models\User;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved analytics reports (`docs/PRD.md:204`, ADR-0011 §D8, Increment H24a).
 *
 * ── The declaration is validated on the way IN and on the way OUT ───────────────────────────────────────
 * On the way in, so a definition that could never resolve is refused at the point the user can still fix it.
 * On the way out, because §D8's whole point is that the stored row is a DECLARATION resolved at read time:
 * a view naming a form, a scope node or a `field_key` that has since gone must degrade to a **stated
 * refusal** rather than to a wrong number. {@see resolve()} is where that happens, and it returns the
 * refusal rather than throwing so a list of views can render the broken one alongside the working ones.
 *
 * ── Per-user scoping is an application predicate ────────────────────────────────────────────────────────
 * `saved_report_views` carries `strict` RLS, which scopes to the TENANT only. Every read here goes through
 * {@see SavedReportView::scopeOwnedBy()}; the single-row equivalent is `SavedReportViewPolicy`'s owner
 * check on the route.
 */
final class SavedReportViewService
{
    /** @return Collection<int, SavedReportView> */
    public function forUser(User $user): Collection
    {
        return SavedReportView::query()
            ->ownedBy($user)
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, mixed> $definition */
    public function create(User $user, string $name, array $definition): SavedReportView
    {
        // Validated before the insert: a definition the query object refuses could never be resolved on read,
        // so storing it would be storing a row that is broken from birth.
        AnalyticsQuery::fromArray($definition);

        return $this->guardName(fn (): SavedReportView => SavedReportView::create([
            'tenant_id' => TenantContext::currentTenantId(),
            'user_id' => $user->getKey(),
            'name' => $name,
            'definition' => $definition,
        ]));
    }

    /** @param array<string, mixed>|null $definition */
    public function update(SavedReportView $view, ?string $name, ?array $definition): SavedReportView
    {
        if ($definition !== null) {
            AnalyticsQuery::fromArray($definition);
            $view->definition = $definition;
        }

        if ($name !== null) {
            $view->name = $name;
        }

        return $this->guardName(function () use ($view): SavedReportView {
            $view->save();

            return $view;
        });
    }

    public function delete(SavedReportView $view): void
    {
        $view->delete();
    }

    /**
     * Resolve a stored view into a usable query, or report why it cannot be.
     *
     * Returns the refusal instead of throwing because a LIST of views must be able to render a broken one
     * beside the working ones — an exception here would take the whole page down for one stale definition,
     * which is the opposite of §D8's intent.
     *
     * @return array{query: AnalyticsQuery|null, refused: bool, reason: string|null, message: string|null}
     */
    public function resolve(SavedReportView $view): array
    {
        try {
            return [
                'query' => AnalyticsQuery::fromArray($view->definition),
                'refused' => false,
                'reason' => null,
                'message' => null,
            ];
        } catch (InvalidAnalyticsQueryException $e) {
            return [
                'query' => null,
                'refused' => true,
                'reason' => $e->reason(),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Turn the unique constraint into a 422 rather than a 500.
     *
     * `saved_report_views_tenant_user_name_unique` is a real race — two tabs, same name — so a
     * check-then-write would still surface a raw QueryException as a 500 some of the time. PostgreSQL raises
     * SQLSTATE 23505; catching the CODE rather than matching the message keeps this independent of the
     * server's locale.
     *
     * ── The write is wrapped, and that is not ceremony (H24b2) ──────────────────────────────────────────
     * PostgreSQL aborts the CURRENT TRANSACTION on a constraint violation: every statement after it fails
     * with SQLSTATE 25P02 until the block ends. Catching 23505 and carrying on therefore only works while
     * this happens to be the outermost transaction — true on a bare API call, false the moment a caller
     * wraps it, and false under `RefreshDatabase`, where the poisoned transaction takes down the redirect
     * that was supposed to carry the field error. `DB::transaction()` opens a SAVEPOINT when nested, so the
     * violation rolls back to it and the caller's transaction survives to render the 422.
     *
     * @template T of SavedReportView
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function guardName(callable $write): SavedReportView
    {
        try {
            // Wrapped in a Closure that ignores the Connection argument `transaction()` passes: the
            // callable here is a zero-arg factory, and handing it over directly loses the template return
            // type PHPStan needs to see `T` come back out.
            return DB::transaction(static fn (): SavedReportView => $write());
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                // ValidationException::withMessages, not a bespoke type: the framework already renders it as
                // a 422 with a field error on both the API and web paths.
                throw ValidationException::withMessages([
                    'name' => ['You already have a saved report with that name.'],
                ]);
            }

            throw $e;
        }
    }
}
