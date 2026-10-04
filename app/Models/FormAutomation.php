<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormAutomationAction;
use App\Enums\FormAutomationTrigger;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\FormAutomationPolicy;
use App\Services\Automations\FormAutomationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One of a form's automations (M132, `R-b7bc5149`): a trigger and an action — "when a response is submitted, send an
 * email" (`D82`) or "… send the answers to a web address" (`D83`). See the `form_automations` migration for the row's
 * shape and why the secret is treated as a credential.
 *
 * Written only through {@see FormAutomationService}, which audits every change under the `form_automation` alias.
 * Who may manage one is {@see FormAutomationPolicy}: a web-address automation additionally needs `webhooks.manage`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $name
 * @property FormAutomationTrigger $trigger
 * @property FormAutomationAction $action
 * @property list<string>|null $recipients
 * @property string|null $url
 * @property string|null $secret
 * @property bool $enabled
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FormAutomation extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'name',
        'trigger',
        'action',
        'recipients',
        'url',
        'enabled',
    ];

    /** The signing secret never leaves the model by serialization: it is shown once, by the service, at creation. */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => FormAutomationTrigger::class,
            'action' => FormAutomationAction::class,
            'recipients' => 'array',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return HasMany<FormAutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(FormAutomationRun::class);
    }

    /** The host a web-address automation sends to — what someone who may not see the whole address is shown. */
    public function host(): ?string
    {
        if ($this->url === null) {
            return null;
        }

        $host = parse_url($this->url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
