<?php

declare(strict_types=1);

namespace App\Models;

use App\Auth\RlsAwareUserProvider;
use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Enums\ThemeMode;
use App\Models\Concerns\HasUuidv7;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * The global user identity (multi-tenancy-rbac-design.md §6). One row per person across every tenant.
 *
 * NOT tenant-scoped and deliberately does NOT use BelongsToTenant — visibility is governed by the
 * join-shape `users` RLS policy (self + active co-tenant membership). The connection stays the default
 * (`meridian_app`); the pre-auth read path lives entirely in App\Auth\RlsAwareUserProvider.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $is_super_admin Global platform-staff flag (RBAC §9); never a Spatie role.
 * @property ?Carbon $two_factor_confirmed_at Set once 2FA enrollment is confirmed.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;          // Spatie RBAC — teams-mode role resolution against the active tenant (B2a)
    use HasUuidv7;
    use Notifiable;
    use SoftDeletes;

    // Aliased, not plain, so replaceRecoveryCode() below can wrap it — see that method for why.
    use TwoFactorAuthenticatable {
        replaceRecoveryCode as private replaceRecoveryCodeOnCurrentConnection;
    }

    /**
     * Rotate a used recovery code on the connection that can actually see this row (Increment J3c1).
     *
     * ⚠️ THIS IS A PRE-AUTH WRITE, AND WITHOUT THE SWITCH IT SILENTLY DID NOTHING. Its only caller is
     * Fortify's TwoFactorAuthenticatedSessionController::store(), which rotates the code the person just
     * spent — BEFORE `Auth::login()`, so `app.current_user_id` is still unset. The trait's body is
     * `forceFill([...])->save()` on the default connection, and while `users_app_update` is permissive,
     * PostgreSQL applies the SELECT policy to an UPDATE whose WHERE reads a column: `users_users_visibility`
     * matches nothing, the statement affects ZERO ROWS, throws nothing, `save()` returns true, and
     * `RecoveryCodeReplaced` fires as if it had worked.
     *
     * The consequence is not cosmetic. Recovery codes are single-use by construction, so a rotation that
     * never lands leaves the spent code VALID FOREVER — turning a lockout defect into a credential-reuse
     * one. It is the same zero-row shape PR #147 fixed on six other Fortify endpoints, arriving on the one
     * write that middleware cannot reach, because this write happens inside the login request itself.
     *
     * `pgsql_auth` can do it: `users_auth_select USING (true) TO meridian_auth` makes the row visible, the
     * permissive `users_app_update` policy permits the write, and the apply_users_rls migration GRANTs
     * SELECT + UPDATE on `users` to that role. This is the same asymmetry
     * {@see RlsAwareUserProvider} already documents for the password-reset save.
     *
     * Switch-call-restore rather than a GUC change, mirroring
     * {@see RlsAwareUserProvider::updateRememberToken()}: the object this is called on becomes
     * `Auth::user()` moments later, and `meridian_auth` holds grants on `users` alone, so leaving it
     * elevated would fail on the first relation the request touched. `finally`, so a throw cannot strand it.
     *
     * @param  string  $code
     */
    public function replaceRecoveryCode($code): void
    {
        $original = $this->getConnectionName();

        $this->setConnection(RlsAwareUserProvider::AUTH_CONNECTION);

        try {
            $this->replaceRecoveryCodeOnCurrentConnection($code);
        } finally {
            $this->setConnection($original ?? (string) Config::get('database.default'));
        }
    }

    /**
     * Pin Spatie's guard for this model to the RBAC catalog's guard (`web`, per RolePermissionSeeder),
     * independent of the request's runtime default guard (Increment E). The /api/v1 token pipeline calls
     * `Auth::shouldUse('sanctum')`, which mutates `config('auth.defaults.guard')` to `sanctum`; without
     * this, Spatie's Guard::getDefaultName would resolve `sanctum` and every permission check would throw
     * PermissionDoesNotExist against the `web`-guarded catalog. All of this app's roles/permissions are
     * `web`-guarded, so this is always correct (and hardens the session path against the same drift).
     */
    public function guardName(): string
    {
        return 'web';
    }

    /**
     * Queue email verification on the `mail` queue (H3) instead of sending it synchronously in-request.
     * The signed URL is built HERE — where $this is a live User and the URL captures the REQUEST host —
     * and handed to a scalar-only queued notification delivered to an on-demand notifiable, so no User
     * model is ever serialized under a NULL GUC on the worker (ADR-0007 §D5). Mirrors the stock
     * {@see VerifyEmail::verificationUrl()}. Covers every call site: the
     * Registered→SendEmailVerificationNotification listener and UpdateUserProfileInformation.
     */
    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            ['id' => $this->getKey(), 'hash' => sha1($this->getEmailForVerification())],
        );

        // No ->withBrand(): the two Fortify emails are deliberately the only unbranded ones (H23a4).
        // config/fortify.php carries no tenancy middleware, so there is no resolved tenant here — and a
        // User may be a member of several, so "whose brand" has no correct answer either. They still
        // render through the Meridian template; see QueuedVerifyEmail::buildMailMessage().
        Notification::route('mail', $this->getEmailForVerification())
            ->notify(new QueuedVerifyEmail($url));
    }

    /**
     * Queue the password-reset link on the `mail` queue (H3). Same rationale as
     * {@see sendEmailVerificationNotification()}: build the URL in-request, deliver to an on-demand
     * notifiable, never serialize the User. Mirrors the stock
     * {@see ResetPassword::resetUrl()}. Driven by the Fortify PasswordBroker.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $this->getEmailForPasswordReset(),
        ], false));

        // Unbranded for the same two reasons as the verification email above (H23a4).
        Notification::route('mail', $this->getEmailForPasswordReset())
            ->notify(new QueuedResetPassword($url));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'tos_accepted_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The user's personal UI preferences (theme, etc.). Belongs to the PERSON, not a tenant —
     * governed by the "belongs to me" RLS shape (keyed on app.current_user_id).
     *
     * @return HasOne<UserUiPreference, $this>
     */
    public function uiPreference(): HasOne
    {
        return $this->hasOne(UserUiPreference::class);
    }

    /**
     * Resolved appearance preferences for server-side <html> attribute emission (Increment C for
     * `mode`; G11 added the other three — PRD Feature #9, design-system-reference.md §2.9).
     *
     * Fail-safe: `user_ui_preferences` is under belongs-to-user RLS, so if app.current_user_id isn't
     * set the read fails closed — degrade to the product defaults, never throw. That rescue is
     * load-bearing and must be preserved: it is what lets any request render even when the tenant
     * database context has not been established.
     *
     * Shape is camelCase because it crosses the wire as an Inertia prop (matching `auth.can.*`); the
     * PATCH request fields stay snake_case to match the column names.
     *
     * @return array{mode: string, accent: ?string, fontSize: string, dyslexiaFont: bool}
     */
    public function uiTheme(): array
    {
        return rescue(
            function (): array {
                $preference = $this->uiPreference()->first();

                if ($preference === null) {
                    return self::defaultUiTheme();
                }

                return [
                    'mode' => $preference->theme_mode->value,
                    // NULL is CARRIED THROUGH, not collapsed to Blueprint (H23a3). It means "no opinion",
                    // and on a branded tenant that resolves to the organisation's brand rather than to
                    // the product default — a distinction `AccentToken::fromColumn()` deliberately loses,
                    // which is why it is not used here.
                    'accent' => $preference->accent_token?->value,
                    'fontSize' => $preference->font_size_scale->value,
                    'dyslexiaFont' => $preference->use_dyslexia_friendly_font,
                ];
            },
            self::defaultUiTheme(),
            report: false,
        );
    }

    /**
     * The product-default appearance, in one place.
     *
     * Both the rescue fallback above and HandleInertiaRequests' guest branch need these, and Increment
     * C duplicated the literal across both — so a fourth axis would have meant editing two lists that
     * nothing forced to agree.
     *
     * `accent` is NULL rather than `blueprint` since H23a3, and the difference is behavioural: this is
     * the value a user who has NEVER OPENED SETTINGS gets, and such a user has expressed no opinion — so
     * on a branded tenant they should see the organisation's brand. Returning `blueprint` here would mean
     * "explicitly the product default", i.e. every un-personalized member would silently opt OUT of their
     * own organisation's branding.
     *
     * @return array{mode: string, accent: ?string, fontSize: string, dyslexiaFont: bool}
     */
    public static function defaultUiTheme(): array
    {
        return [
            'mode' => ThemeMode::System->value,
            'accent' => null,
            'fontSize' => FontSizeScale::Standard->value,
            'dyslexiaFont' => false,
        ];
    }
}
