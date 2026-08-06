<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Entitlements\EntitlementService;
use App\Support\Entitlements\ToggleableModules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A per-module on/off write (Increment I5, PRD Feature #10's "per-module toggles").
 *
 * ONE module per request, not a whole map — the card autosaves on change like the notification preferences
 * card above it, so a map would mean re-sending eleven values to change one, and the audit row would then
 * claim eleven things changed.
 *
 * The key is validated against {@see ToggleableModules::KEYS} rather than against `plans.feature_flags`,
 * which is the closed list of what this SURFACE offers. That is a separate question from what the tenant's
 * plan grants, and both matter: a key outside the list is rejected here (422), while a key the plan denies
 * is simply irrelevant — {@see EntitlementService::feature()} ANDs the toggle with the plan flag, so writing
 * `true` for a module the plan does not include changes nothing and can never be an escalation.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate.
 */
final class UpdateModuleSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'module' => ['required', 'string', Rule::in(ToggleableModules::KEYS)],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function toSettings(): array
    {
        return [
            ToggleableModules::settingKey((string) $this->validated('module')) => $this->boolean('enabled'),
        ];
    }
}
