<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Sso\SsoConnectionService;
use App\Support\Authorization\AssignableRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Amend the POLICY half of a tenant's SSO connection (P1a — ADR-0016 §D10) — everything that is not the
 * trust anchor, which arrives separately through {@see ImportSsoMetadataRequest}.
 *
 * Every rule is `sometimes`, the {@see UpdateDraftSettingsRequest} shape: the page has several independent
 * controls and each writes only itself. That is the deliberate opposite of
 * {@see UpdateMaintenanceSettingsRequest}, whose two fields are `required` together because they are one
 * decision — pick per surface, never by symmetry.
 *
 * ⚠️ `status` IS NOT HERE. It moved to its own ungated route, because leaving it on this
 * `feature:sso_saml`-gated one left a downgraded tenant able to delete the trust anchor but not disable it
 * (ADR-0016 §D5). See {@see UpdateSsoStatusRequest}.
 *
 * ⚠️ EVERY RULE BELOW IS A COLUMN BOUND, AND A MISSING ONE IS A 500 RATHER THAN A FIELD ERROR. The table
 * carries NOT NULL on `attribute_map`/`jit_provisioning_enabled`, a `varchar(120)` on `name_id_format`, and
 * a CHECK on `default_role_name` — so an unchecked checkbox serialised as `null`, or a role outside the
 * catalog, reaches PostgreSQL as SQLSTATE 23502 / 23514 if it is not caught here.
 *
 * Authorization is the route's `can:tenant.settings.manage` + `feature:sso_saml` gates.
 */
final class UpdateSsoConnectionRequest extends FormRequest
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
            'jit_provisioning_enabled' => ['sometimes', 'required', 'boolean'],
            'default_role_name' => ['sometimes', 'required', 'string', Rule::in(AssignableRoles::values())],
            'name_id_format' => ['sometimes', 'required', 'string', 'max:120', Rule::in(array_keys(SsoConnectionService::NAME_ID_FORMATS))],
            'attribute_map' => ['sometimes', 'array:'.implode(',', SsoConnectionService::ATTRIBUTE_KEYS)],
            'attribute_map.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_role_name.in' => 'Choose a role single sign-on may assign. Owner is not one of them — ownership changes hands only through a transfer.',
            'name_id_format.in' => 'That NameID format is not one this application can accept.',
            'attribute_map.array' => 'Attribute mappings may only name the fields this application resolves.',
        ];
    }

    /**
     * Only the keys this request actually carried, ready for the service.
     *
     * `attribute_map` is compacted rather than stored as sent: an input the tenant cleared arrives as `''`
     * or `null`, and storing that would leave a mapping that exists and points nowhere. The column's default
     * is `{}`, so absence is how "no override" is spelled.
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        if (array_key_exists('attribute_map', $validated)) {
            /** @var array<string, ?string> $map */
            $map = $validated['attribute_map'] ?? [];

            $validated['attribute_map'] = array_filter(
                $map,
                static fn (?string $value): bool => is_string($value) && trim($value) !== '',
            );
        }

        return $validated;
    }
}
