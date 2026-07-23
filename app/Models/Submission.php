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
 * @property ?int $completeness_percent
 * @property Carbon|null $last_saved_at
 * @property Carbon|null $draft_expires_at
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
