<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant Maintenance panel write (Increment I5, PRD Feature #10) — pause the PUBLIC form runtime and
 * say why. The authenticated app is deliberately unaffected, so the person who switched it on can switch it
 * off.
 *
 * ⚠️ BOTH FIELDS ARE `required`, and that is not belt-and-braces — it is the same argument I4 made for
 * notification channels. The flag and the notice are ONE decision: a partial write that flipped
 * `maintenance_mode` alone would take a tenant's forms offline behind whatever sentence they last used,
 * possibly months ago and about something else entirely. ({@see UpdateAccessSettingsRequest} and
 * {@see UpdateDraftSettingsRequest} are `sometimes` for the opposite and equally deliberate reason —
 * independent axes on one row.)
 *
 * The message is `nullable` but always SENT: an empty string clears it, and
 * {@see Tenant::maintenanceNotice()} then supplies the product's own copy, so a respondent
 * never sees a blank page. 500 chars because this is a notice, not a status page.
 */
final class UpdateMaintenanceSettingsRequest extends FormRequest
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
            'maintenance_mode' => ['required', 'boolean'],
            'maintenance_message' => ['present', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        $message = $this->input('maintenance_message');
        $message = is_string($message) ? trim($message) : '';

        return [
            'maintenance_mode' => $this->boolean('maintenance_mode'),
            'maintenance_message' => $message === '' ? null : $message,
        ];
    }
}
