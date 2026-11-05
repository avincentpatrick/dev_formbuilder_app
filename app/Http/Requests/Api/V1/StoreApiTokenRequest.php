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
 * session `auth` middleware. Every mint carries the signed-in account's current password.
 */
final class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // Why `password` is required, kept out of everything Scramble publishes (the class docblock, and a
    // comment above an array key, both reach openapi.json — M200, `D191`):
    //   · M207 (D205). BHW Connect shares this origin (ADR-0024 §D2), so a script on any of its pages holds
    //     the session and the readable XSRF-TOKEN, and could mint a key with both — measured before this
    //     rule: 201. The `step-up` window would still let it through for fifteen minutes after any
    //     confirmation; a password in the request does not.
    //   · A Google- or SSO-only account holds a random password and cannot mint until it sets one
    //     (`api-key-mint-sso-password`).

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
            // The signed-in account's current password. Every new key asks for it.
            'password' => ['required', 'string', 'current_password:web'],
        ];
    }
}
