<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\SsoConnectionStatus;
use App\Services\Sso\SsoConnectionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turn a tenant's SSO connection on or off (P1a — ADR-0016 §D5).
 *
 * ⚠️ A SEPARATE REQUEST AND A SEPARATE ROUTE FROM {@see UpdateSsoConnectionRequest}, AND THE REASON IS THE
 * ENTITLEMENT GATE RATHER THAN THE PAYLOAD. This route carries `can:tenant.settings.manage` ALONE, with no
 * `feature:sso_saml`, so a tenant downgraded off Enterprise can still switch SSO off. With `status` on the
 * gated policy write the only action left to such a tenant was to DELETE the trust anchor — "destroy or
 * nothing", the inverse of the ADR-0012 §D9 escape hatch the asymmetry exists to provide, and against
 * {@see SsoConnectionStatus::Disabled}'s own contract that a disabled connection is *retained* so the anchor
 * and its audit history survive a suspension.
 *
 * Enabling is still gated — by {@see SsoConnectionService::changeStatus()} rather than by
 * middleware, because one route serves both directions. The rule: **undo always, redo only on the plan.**
 *
 * ⚠️ `draft` IS DELIBERATELY NOT IN THE VOCABULARY. It is server-authored — the state a fresh import leaves
 * behind, so that "a trust anchor exists but nobody has finished setting it up" is representable — and
 * "put it back to draft" means nothing once a complete anchor is stored. Offering it would let a tenant
 * describe their own connection as unfinished while it is anything but.
 */
final class UpdateSsoStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in([
                SsoConnectionStatus::Active->value,
                SsoConnectionStatus::Disabled->value,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Single sign-on can be switched on or off. Draft is set for you when metadata is first imported.',
        ];
    }

    /** The requested status. */
    public function status(): SsoConnectionStatus
    {
        /** @var array{status: string} $validated */
        $validated = $this->validated();

        return SsoConnectionStatus::from($validated['status']);
    }
}
