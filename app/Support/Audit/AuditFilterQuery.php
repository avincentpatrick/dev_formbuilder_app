<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Http\Controllers\Tenant\AuditLogController;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Audit\AuditExporter;
use App\Services\Audit\AuditLogPresenter;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE audit-ledger filter chain (Increment J1e), applied identically by the page and by the export.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ WHY THIS CLASS EXISTS: THE EXPORT WAS A SECOND, INDEPENDENT COPY OF THE SAME FIVE CLAUSES.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * {@see AuditLogController} hands one `AuditLogFilterRequest::filters()` array
 * to BOTH {@see AuditLogPresenter::index()} and {@see AuditExporter::stream()}, and until J1e each spelled
 * the `->when()` chain out for itself. That is load-bearing duplication rather than cosmetic: `audit/Index.vue`
 * builds the download URL from the same `queryParams()` it navigates with, precisely so that
 * **"I exported what I was looking at" is a compliance guarantee**. A filter added to one side and not the
 * other makes that sentence false — the page shows three rows and the CSV carries four thousand — and no
 * existing test compares the two.
 *
 * Adding `q` was the change that would have done it. One chain, two callers, and `AuditLogPageTest` +
 * `AuditExportTest` pass UNEDITED across the extraction, which is the proof it moved nothing.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ `q` NARROWS TO TARGET AND ACTOR. IT IS NEVER A TEXT SEARCH OVER THE DIFF, AND THAT IS A SECURITY RULE.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * `old_values`/`new_values` are jsonb documents that {@see AuditRedactor} has already placeholdered on both
 * sides for every SECRETS and PII key — and {@see AuditableTypes::label()} FAILS OPEN, so a newly registered
 * alias is un-redacted until someone extends the catalog. A `::text ILIKE` over those columns would
 * therefore be an oracle over exactly the values redaction exists to remove: the placeholder hides a secret
 * from the screen while a search that matched it would confirm it, one guess at a time, to the same viewer.
 *
 * **The safe design is also the fast one, which is why there is no trade to weigh here.** `audits` carries
 * `audits_tenant_auditable_idx (tenant_id, auditable_type, auditable_id)` and
 * `audits_tenant_user_idx (tenant_id, user_id)`, and NO index a jsonb text scan could use — on a table that
 * is never pruned. Both branches below are shaped to those two indexes.
 *
 * And note WHERE the non-leakproof operators sit. J1b measured that PostgreSQL refuses to promote a
 * non-leakproof clause (`@@`, `~~*`) to an index qual on a relation carrying RLS quals, which is what made
 * the two GIN indexes unreachable. Here every predicate ON `audits` is `=` or `IN` over plain columns —
 * leakproof, and therefore still securely promotable — while `@@` and `ILIKE` are confined to subqueries
 * against `forms` and `users`. That is a property of the shape, not an accident of it; keep it if this ever
 * grows a third branch. `AuditKeywordFilterTest` runs a real `EXPLAIN` rather than asserting the plan here
 * in prose, because J1b's central finding was a reasoned-about plan that turned out to be wrong.
 */
final class AuditFilterQuery
{
    /**
     * The alias whose targets are keyword-resolvable. `form` is the only one with a name to match: half the
     * catalog (`form_version`, `subscription`, `tenant`, `settings`) has no addressable row with a title,
     * and `resource_grants` rows are HARD-deleted by design, so the ledger is the only record they existed.
     * Kept as a constant so {@see AuditLogPresenter::resolveTargets()}'s matching assumption is greppable.
     */
    public const string RESOLVABLE_TARGET = 'form';

    /**
     * @param  Builder<Audit>  $query
     * @param  array{auditable_type?: ?string, event?: ?string, user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, q?: ?SearchTerms}  $filters
     * @return Builder<Audit>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->tap(fn (Builder $q) => self::applyKeyword($q, $filters['q'] ?? null));
    }

    /**
     * Whether anything at all was filtered — the input to `empty_reason`.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function hasAnyFilter(array $filters): bool
    {
        foreach (['auditable_type', 'event', 'user_id', 'from', 'to'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                return true;
            }
        }

        $terms = $filters['q'] ?? null;

        return $terms instanceof SearchTerms && ! $terms->isEmpty();
    }

    /**
     * `(target is a form whose title/description/slug matches) OR (actor's name/email matches)`.
     *
     * ⚠️ THE `auditable_type` CONJUNCT IS REQUIRED INSIDE THE BRANCH, NOT IMPLIED BY IT. `auditable_id` is a
     * bare uuid column with no type discriminator of its own, so without it a row about a *submission* whose
     * id collided with a matching form's would be returned under a form's name. Vanishingly unlikely and
     * still wrong, and it is what makes the branch match `audits_tenant_auditable_idx`'s leading columns.
     *
     * ⚠️ THE FORMS SUBQUERY APPLIES `withTrashed()` AND NO `Form::scopeVisibleTo()`, BOTH DELIBERATELY.
     * `withTrashed` mirrors {@see AuditLogPresenter::resolveTargets()}, which already prints an archived
     * form's title on the very row recording that it was archived — a search that could not find it would
     * be unable to find a row the page itself names. And visibility: `audit_log.view` is Owner/Admin only,
     * who hold `forms.edit.any` anyway, so a second rule would refuse nothing while making the page's own
     * printed titles unsearchable for anyone it ever did refuse.
     *
     * ⚠️ THE ACTORS SUBQUERY RUNS ON THE DEFAULT CONNECTION. The standing rule from J1c, now in RBAC §9 and
     * broader than search: no user-supplied predicate may EVER run on `pgsql_auth`, where
     * `users_auth_select ... USING (true)` means there is no tenant boundary of any kind. On this connection
     * the join-shape `users_visibility` policy IS the boundary, so the actor filter can only ever name
     * people this viewer already sees on `/members` — the same set {@see AuditLogPresenter::actorOptions()}
     * offers in the Actor dropdown. It also inherits `User`'s SoftDeletes scope, which is the right default
     * for the same reason: a deactivated account is not in that dropdown and its rows already render as
     * "Unknown user", so making it findable by a name the page refuses to print would be the odd one out.
     *
     * ⚠️ NO uuid-PREFIX BRANCH, unlike {@see Submission::scopeMatchingKeyword()}. It would be a
     * scan on `auditable_id` and, worse, it would answer a question the two index-shaped branches cannot:
     * "show me every ledger row about this id" for an alias with NO resolvable name — i.e. for the
     * hard-deleted `resource_grants` and the settings rows. That is a real feature and it belongs behind the
     * existing `auditable_type` select plus a proper target filter, not smuggled in through the keyword box.
     *
     * @param  Builder<Audit>  $query
     */
    private static function applyKeyword(Builder $query, ?SearchTerms $terms): void
    {
        if ($terms === null || $terms->isEmpty()) {
            return;
        }

        $query->where(function (Builder $match) use ($terms): void {
            $match->where(function (Builder $target) use ($terms): void {
                $target
                    ->where('auditable_type', self::RESOLVABLE_TARGET)
                    ->whereIn('auditable_id', Form::withTrashed()
                        ->select('forms.id')
                        ->whereRaw("forms.search_vector @@ to_tsquery('simple', ?)", [$terms->tsQuery()]));
            });

            $match->orWhereIn('user_id', User::query()
                ->select('users.id')
                ->tap(fn (Builder $q) => KeywordFilter::applyLike($q, $terms, ['users.name', 'users.email'])));
        });
    }
}
