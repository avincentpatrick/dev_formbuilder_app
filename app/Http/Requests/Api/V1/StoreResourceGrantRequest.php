<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ResourceCapacity;
use App\Enums\ResourceScopeable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Grant a capacity on a form or a scope node (Increment G10b).
 *
 * The target arrives as `scopeable_type` + `scopeable_id` — an alias from {@see ResourceScopeable}, never
 * a class name — and the controller resolves it to a real tenant-scoped model before handing it to the
 * service, which associates it. The raw pair is never mass-assigned: that is precisely the cross-tenant
 * attack surface `ResourceGrant::$fillable` excludes those columns for.
 */
final class StoreResourceGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scopeable_type' => ['required', 'string', Rule::in(ResourceScopeable::aliases())],
            'scopeable_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'capacity' => ['required', 'string', Rule::in(ResourceCapacity::values())],
            'includes_descendants' => ['nullable', 'boolean'],
        ];
    }
}
