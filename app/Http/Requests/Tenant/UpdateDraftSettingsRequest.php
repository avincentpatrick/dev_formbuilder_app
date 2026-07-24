<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant-level draft-settings write (Increment H10) — currently the draft-expiry window in days
 * (docs/ux/form-filling-ux-flow.md §5.2). Mirrors {@see UpdateAppearanceRequest}'s partial-write shape
 * (`sometimes` + a {@see self::toColumns()} helper that emits only the keys actually sent) so the surface can
 * grow to more per-tenant draft settings without a full-payload clobber.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate (Owner/Admin), and the controller writes the
 * subdomain-resolved current tenant only — never a tenant id from the request — so a member cannot reach
 * another tenant's row (`tenants` is RLS-exempt, so that scoping is the controller's job, not the database's).
 *
 * 1–365 days: at least a day (a sub-day expiry would reap a draft the respondent is still filling), at most a
 * year (an unbounded window turns abandoned guest drafts into an indefinite liability).
 */
final class UpdateDraftSettingsRequest extends FormRequest
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
            'draft_ttl_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
        ];
    }

    /**
     * Only the keys this request actually sent, mapped to their column values. Deliberately NOT named
     * attributes() (that is a real FormRequest validation-message hook).
     *
     * @return array<string, int>
     */
    public function toColumns(): array
    {
        $validated = $this->validated();
        $columns = [];

        if (array_key_exists('draft_ttl_days', $validated)) {
            $columns['draft_ttl_days'] = (int) $validated['draft_ttl_days'];
        }

        return $columns;
    }
}
