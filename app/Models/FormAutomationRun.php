<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormAutomationRunStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Automations\FormAutomationDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One automation's run for one response (M132, `R-b7bc5149`). Created by {@see FormAutomationDispatcher} once per
 * automation per `SubmissionCreated` event (unique on the event id), and moved through its statuses by the delivery
 * job. It stores no payload and no response body — see the `form_automation_runs` migration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_automation_id
 * @property string $submission_id
 * @property string $event_id
 * @property FormAutomationRunStatus $status
 * @property int $attempt_count
 * @property int|null $response_status
 * @property string|null $error_code
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormAutomationRun extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'form_automation_id',
        'submission_id',
        'event_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FormAutomationRunStatus::class,
            'attempt_count' => 'integer',
            'response_status' => 'integer',
            'last_attempted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FormAutomation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(FormAutomation::class, 'form_automation_id');
    }
}
