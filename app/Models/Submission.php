<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\SubmissionPolicy;
use App\Services\Analytics\AnswerValueAggregator;
use App\Services\Authorization\ResourceGrantResolver;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A submission (data-dictionary §7). FKs to the immutable `form_version_id` it was collected against,
 * never to the live form rows. Mutable through its review lifecycle → the plain `strict` RLS shape.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $form_version_id
 * @property ?string $respondent_user_id
 * @property SubmissionStatus $status
 * @property SubmissionSource $source
 * @property ?string $client_submission_uuid
 * @property ?string $locale
 * @property ?string $guest_contact_email
 * @property ?int $completeness_percent
 * @property Carbon|null $last_saved_at
 * @property Carbon|null $draft_expires_at
 * @property ?string $draft_current_step
 * @property ?string $validated_by
 * @property Carbon|null $validated_at
 * @property ?string $returned_reason
 * @property ?string $remarks
 * @property Carbon|null $submitted_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $pii_erased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Submission extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'form_id',
        'form_version_id',
        'respondent_user_id',
        'status',
        'source',
        'client_submission_uuid',
        'guest_token',
        'guest_ip',
        'guest_user_agent',
        'guest_contact_email',
        'device_id',
        'app_version',
        'locale',
        'completeness_percent',
        'last_saved_at',
        'draft_expires_at',
        'draft_current_step',
        'source_batch_id',
        'validated_by',
        'validated_at',
        'returned_reason',
        'remarks',
        'submitted_at',
        'finalized_at',
        'pii_erased_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'source' => SubmissionSource::class,
            'completeness_percent' => 'integer',
            'last_saved_at' => 'datetime',
            'draft_expires_at' => 'datetime',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'finalized_at' => 'datetime',
            'pii_erased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /** @return HasOne<SubmissionAnswer, $this> */
    public function answers(): HasOne
    {
        return $this->hasOne(SubmissionAnswer::class);
    }

    /** @return HasMany<SubmissionAnswerIndex, $this> */
    public function answerIndex(): HasMany
    {
        return $this->hasMany(SubmissionAnswerIndex::class);
    }

    /**
     * THE countable-submission predicate (ADR-0011 §D2): a submission counts when its `status` is not
     * `draft` and it is not soft-deleted. Named once here and binding on H11's dashboard and H24a's
     * analytics alike, so the two surfaces cannot drift into two definitions of "a response" — the failure
     * that makes a dashboard and an inbox disagree in front of a customer.
     *
     * The `deleted_at IS NULL` half comes from the {@see SoftDeletes} global scope, not from this method —
     * which means **this scope only carries D2 when `Submission` is the query ROOT.** A query rooted on
     * `submission_answer_index` and joined back to `submissions` would inherit neither, and since that
     * table's FK cascade fires on HARD delete only, its rows outlive a soft-deleted submission. That is why
     * {@see AnswerValueAggregator} is deliberately rooted here and joins OUTWARD to
     * the index rather than the other way round: it makes D2 structural rather than remembered.
     *
     * Visibility is deliberately NOT embedded — {@see scopeVisibleTo()} is a different axis, and three of the
     * non-adopters below are public/guest paths that must not consult it.
     *
     * ── Deliberate non-adopters, so nobody "finishes the refactor" ───────────────────────────────────────
     * Three sites used to spell this predicate identically while answering a DIFFERENT question — "how many
     * responses have consumed the paid `max_responses` cap?" Since I9a they no longer spell it at all: they
     * adopt {@see scopeConsumesCapacity()}, which is the NAME this paragraph always said the other question
     * deserved. Binding a purchased capacity cap to the analytics definition of a response would mean a later
     * analytics change — excluding `returned`, say — silently changing what a customer bought. Keeping the
     * two scopes separate is what let `screened_out` diverge from `draft` in one of them and not the other.
     *
     * A further site, `ReconcileTenantUsageJob`, is a metering COUNT with no status predicate at all (drafts
     * fall out only incidentally, because their `submitted_at` is NULL) — a non-adopter of BOTH scopes. Same
     * decoupling argument: it is billing.
     *
     * ⚠️ `screened_out` IS countable and is deliberately NOT excluded here. It is a real row the tenant
     * received and the inbox lists it by default, so dropping it from analytics would make the tile disagree
     * with the list a reviewer is looking at — the exact §D2 failure this scope exists to prevent. It is also
     * the predicate `submissions_analytics_series_idx` is partial on, so changing it is a migration.
     *
     * The column is QUALIFIED. `status` is unqualified-ambiguous the moment a caller joins `forms`, which
     * also has one — and H24a's scope-node breakdown does exactly that. An unqualified scope would work
     * everywhere it is used today and fail on the first join, which is the kind of latent break a shared
     * predicate must not carry.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('submissions.status', '!=', SubmissionStatus::Draft->value);
    }

    /**
     * THE capacity predicate (Increment I9a): a submission consumed one of the form's paid `max_responses`
     * slots. The sibling of {@see scopeCountable()} and deliberately NOT the same predicate — see that
     * method's non-adopters paragraph for why the two questions are kept apart, and this scope for what
     * happens once they answer differently.
     *
     * A `draft` has not been finalized. A `screened_out` submission was finalized but the respondent was
     * shown no questions at all ({@see SubmissionStatus}) — charging a paid slot for a response that was
     * never solicited is the live bug `docs/workflow-branching-design.md:140` recorded and deferred.
     * Everything else — including `archived`, which is why `screened_out` must never be archivable —
     * consumed one.
     *
     * ⚠️ TWO SEPARATE NOT-EQUAL CONJUNCTS, NEVER `whereNotIn`, AND THAT IS NOT A STYLE PREFERENCE. (`!=` is
     * spelled here to match `scopeCountable()`; Postgres's grammar folds it to `<>` before planning, so the
     * two spellings reach the planner as the same node.) The hot
     * consumer is `FormAcceptanceGuard::assertCapacity()`, running under `lockForUpdate()` inside the
     * finalize transaction, and it is served by the partial index `submissions_form_finalized_idx`, whose
     * predicate is `WHERE status <> 'draft'`. Postgres will use a partial index only when it can PROVE the
     * index predicate from the query's clauses; a bare `status <> 'draft'` conjunct matches it verbatim.
     * `whereNotIn` compiles to a `ScalarArrayOpExpr` (`<> ALL (...)`), from which that implication is not
     * guaranteed to be established — which would silently turn the count into a scan on the ingest path,
     * under a lock, with no test failing. `ScreenedOutTest`'s "spells the capacity predicate as two
     * not-equal conjuncts, never as NOT IN" case pins the emitted SQL and its bindings for this reason.
     *
     * The column is QUALIFIED for the same reason `scopeCountable()`'s is: `forms` also has a `status`.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeConsumesCapacity(Builder $query): Builder
    {
        return $query
            ->where('submissions.status', '!=', SubmissionStatus::Draft->value)
            ->where('submissions.status', '!=', SubmissionStatus::ScreenedOut->value);
    }

    /**
     * Restrict the inbox list to what `$user` may see (F7). RLS already scopes every query to the tenant;
     * this adds the role-level split that {@see SubmissionPolicy} enforces per-row: Owner/Admin/
     * Viewer (who hold `dashboard.org.view`) see all tenant submissions, so no extra predicate; Form Editor/
     * Reviewer see only forms they hold a grant on. A user with no grants gets an empty set.
     *
     * Since G10a the grant subquery comes from {@see ResourceGrantResolver}, the same object
     * {@see SubmissionPolicy} consults for single-row checks — so the list and the per-row check cannot
     * drift into "a row appears in the inbox but 403s when opened".
     *
     * I8a adds the RESPONDENT arm, mirroring {@see SubmissionPolicy::view()}'s new clause. It is the other
     * direction of the same invariant: without it a respondent could OPEN a submission the inbox never
     * lists, which is the drift the paragraph above exists to prevent, merely running the other way.
     *
     * ⚠️ THE `orWhere` IS WRAPPED, AND THE REASON IS NARROWER THAN IT LOOKS. Flat, the OR would associate
     * against every predicate already on the builder — the inbox composes status filters, date ranges and
     * form filters onto this scope — and `(A and B) or C` is not `A and (B or C)`; a "returned this week"
     * filter would additionally return every submission the user ever made, of any status.
     *
     * That does NOT happen when this is invoked as a scope: {@see Builder::callScope()} counts the wheres
     * before and after and calls `addNewWheresWithinGroup()`, so the framework already nests whatever a
     * local scope adds. Verified by mutation — flattening this leaves the whole suite green. The wrapper
     * stays anyway, because the protection is a property of the CALL MECHANISM rather than of this method:
     * it evaporates the moment anyone invokes `scopeVisibleTo($query, $user)` directly, or hoists the body
     * into a `where(fn ($q) => ...)` elsewhere. Correctness that depends on how you were called is worth
     * one closure to make unconditional.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('dashboard.org.view')) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped
                ->whereIn('form_id', app(ResourceGrantResolver::class)->grantedFormIdsQuery($user))
                ->orWhere('respondent_user_id', $user->getKey());
        });
    }
}
