<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform super-admin (RBAC §9). Global flag, never a tenant role.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * A user who has completed two-factor enrollment (B2c). `two_factor_confirmed_at` is what the
     * `superadmin.mfa` middleware checks; the secret is a placeholder (never challenged in tests).
     *
     * ⛔ THE RECOVERY CODES USED TO BE ABSENT ENTIRELY, WHICH IS NOT THE SAME AS EMPTY (`M107`,
     * `R-0f8b73f9`). A NULL column makes Fortify's `recoveryCodes()` call `decrypt(null)` and throw, so
     * this state produced a user who LOOKED fully enrolled and fatally errored on the one path that
     * matters when somebody is locked out. Nothing reached it — `TwoFactorChallengeTest` builds its own
     * identity rather than using this state — so it was a trap set for the next caller rather than a live
     * defect, and the next caller is any test of the reset this increment adds.
     *
     * Deliberately a real, decodable list rather than `[]`: an empty list is itself the state `D37` exists
     * to rescue, and a factory that hands it out by default seeds the defect into every future test.
     */
    public function confirmedTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('PLACEHOLDERSECRET'),
            'two_factor_recovery_codes' => encrypt((string) json_encode([
                'factoryAAA-AAAAAAAAAA',
                'factoryBBB-BBBBBBBBBB',
                'factoryCCC-CCCCCCCCCC',
                'factoryDDD-DDDDDDDDDD',
                'factoryEEE-EEEEEEEEEE',
                'factoryFFF-FFFFFFFFFF',
                'factoryGGG-GGGGGGGGGG',
                'factoryHHH-HHHHHHHHHH',
            ])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
