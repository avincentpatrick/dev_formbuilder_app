<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\SettingKey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant Access panel write (Increment I5, PRD Feature #10) — currently one question: may a person join
 * this workspace by registering on its subdomain, or only by invitation?
 *
 * `sometimes`, the {@see UpdateAppearanceRequest} shape rather than
 * {@see UpdateMaintenanceSettingsRequest}'s all-`required` one — and the asymmetry between the two is
 * deliberate rather than an inconsistency. Access settings are INDEPENDENT AXES that happen to share a
 * panel: a future "require 2FA for this workspace" (I8 owns it) is a separate decision from invite-only, and
 * a partial write must leave the other alone. Maintenance is the opposite — its flag and its message are one
 * decision, so writing half of it is a bug. Pick per surface, never by symmetry.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate (Owner/Admin); the controller writes the
 * subdomain-resolved tenant only, never an id from the body.
 */
final class UpdateAccessSettingsRequest extends FormRequest
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
            'invite_only' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Only the keys this request actually sent, keyed by their SETTINGS key rather than by the wire name.
     * Deliberately NOT named attributes() (that is a real FormRequest validation-message hook).
     *
     * @return array<string, bool>
     */
    public function toSettings(): array
    {
        $validated = $this->validated();
        $settings = [];

        if (array_key_exists('invite_only', $validated)) {
            $settings[SettingKey::RegistrationInviteOnly->value] = $this->boolean('invite_only');
        }

        return $settings;
    }
}
