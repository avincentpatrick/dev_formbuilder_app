<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // Resolve uniqueness on the pre-auth connection: as `meridian_app` with no user
                // context, the fail-closed join-shape RLS on `users` would hide existing rows and a
                // duplicate registration would surface as a raw unique-index 500 instead of a clean
                // validation error. See App\Auth\RlsAwareUserProvider.
                Rule::unique('pgsql_auth.users', 'email'),
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        // ⛔ `forceFill()` RATHER THAN `User::create([...])`, AND THE DIFFERENCE IS THE WHOLE POINT OF M76.
        // `password_set_at` is deliberately NOT in `User`'s `#[Fillable]` attribute — it decides whether a
        // holder of an invitation token may overwrite this account's password, so it must not be reachable
        // by mass assignment. Passing it to `create()` would therefore have been dropped **in silence**,
        // with no exception and no log, and this door — self-registration — is the exact door that
        // manufactured the population the column exists to protect. One INSERT, not two.
        $user = new User;

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'password_set_at' => now(),
        ])->save();

        return $user;
    }
}
