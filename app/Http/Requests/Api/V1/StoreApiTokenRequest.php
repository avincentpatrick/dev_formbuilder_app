<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Support\Api\ApiAbilities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for minting an API key (Increment E). The requested `abilities` must be members of the
 * catalog ({@see ApiAbilities}); the controller then intersects them against the issuer's RBAC, so the
 * requested set is an upper bound, never a grant. Authorization (an authenticated member) is the route's
 * session `auth` middleware.
 */
final class StoreApiTokenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiAbilities::all())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
