<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\SettingKey;
use App\Http\Requests\Tenant\UpdateMaintenanceSettingsRequest;
use App\Services\Settings\PlatformSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The platform settings write (Increment I5, PRD Feature #10) — the super-admin console's signup toggle and
 * platform maintenance pair.
 *
 * ALL-`required`, and for a stronger version of the reason
 * {@see UpdateMaintenanceSettingsRequest} gives: this is one small form with one
 * Save button covering three fields that describe a single operational stance, and a partial write here
 * would take the entire product offline behind a stale notice. There is no autosave on this page for the
 * same reason — the blast radius is every tenant.
 *
 * Authorization is the route's `superadmin` + `superadmin.mfa` middleware; `authorize()` returning true is
 * not a gap, it is where every other admin route puts the decision too.
 */
final class UpdatePlatformSettingsRequest extends FormRequest
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
            'signup_open' => ['required', 'boolean'],
            'maintenance_enabled' => ['required', 'boolean'],
            'maintenance_message' => ['present', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Keyed by SETTINGS key, not by wire name — {@see SettingKey} is the vocabulary the store speaks.
     *
     * The message is stored as a string (empty when cleared) rather than as null: the column is NOT NULL
     * jsonb, and "" is what {@see PlatformSettings::maintenanceMessage()} reads as
     * "no message — use the product's own copy".
     *
     * @return array<string, bool|string>
     */
    public function toSettings(): array
    {
        $message = $this->input('maintenance_message');

        return [
            SettingKey::RegistrationOpenSignup->value => $this->boolean('signup_open'),
            SettingKey::MaintenanceEnabled->value => $this->boolean('maintenance_enabled'),
            SettingKey::MaintenanceMessage->value => is_string($message) ? trim($message) : '',
        ];
    }
}
