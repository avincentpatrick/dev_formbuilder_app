<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\SubmissionPolicy;
use App\Services\Authorization\ResourceGrantResolver;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
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
     * The `deleted_at IS NULL` half comes from the {@see SoftDeletes} global scope, not from this method.
     * That is why {@see applyCountableJoin()} exists as a separate artefact: a local scope only applies when
     * `Submission` is the query ROOT, so a query rooted on `submission_answer_index` gets neither this
     * predicate nor the global scope on the joined table.
     *
     * Visibility is deliberately NOT embedded — {@see scopeVisibleTo()} is a different axis, and three of the
     * non-adopters below are public/guest paths that must not consult it.
     *
     * ── Deliberate non-adopters, so nobody "finishes the refactor" ───────────────────────────────────────
     * Three sites spell this predicate identically today and answer a DIFFERENT question — "how many
     * responses have consumed the paid `max_responses` cap?": `FormAcceptanceGuard::assertCapacity()`
     * (enforcement, under `lockForUpdate()` on the ingest path) and its two display twins,
     * `PublicFormPresenter::finalizedCount()` and `EncodeFormPresenter::finalizedCount()`. Binding a
     * purchased capacity cap to the analytics definition of a response would mean a later analytics change
     * — excluding `returned`, say — silently changing what a customer bought, in two places that must agree
     * with the guard or the public banner lies.
     *
     * A fourth, `ReconcileTenantUsageJob`, is a metering COUNT with no status predicate at all (drafts fall
     * out only incidentally, because their `submitted_at` is NULL). Same decoupling argument: it is billing.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('status', '!=', SubmissionStatus::Draft->value);
    }

    /**
     * The join-side spelling of {@see scopeCountable()}, for a query whose root is NOT `submissions`.
     *
     * ADR-0011 §D2's second clause — "analytics may never read `submission_answer_index` without joining
     * `submissions`" — is about a JOIN, and a join inherits neither a local scope nor the joined model's
     * global scopes. The soft-delete half is therefore spelled out here: `submission_answer_index`'s FK
     * cascade fires on HARD delete only, so its rows outlive a soft-deleted submission and a query that
     * forgets this returns the right shape with the wrong number.
     *
     * Also emits the predicate the partial indexes on `submissions` are built over, so a join through this
     * helper stays index-eligible.
     *
     * @param  JoinClause|Builder<Submission>|QueryBuilder  $join
     */
    public static function applyCountableJoin(JoinClause|Builder|QueryBuilder $join, string $alias = 'submissions'): void
    {
        $join->where($alias.'.status', '!=', SubmissionStatus::Draft->value)
            ->whereNull($alias.'.deleted_at');
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
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('dashboard.org.view')) {
            return $query;
        }

        return $query->whereIn('form_id', app(ResourceGrantResolver::class)->grantedFormIdsQuery($user));
    }
}
