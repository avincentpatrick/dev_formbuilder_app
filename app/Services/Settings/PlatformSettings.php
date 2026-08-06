<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Http\Middleware\EnforcePlatformMaintenance;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\Admin\SuperAdminService;
use Illuminate\Http\Request;

/**
 * The platform half of the `settings` store (data-dictionary §20, PRD Feature #10) — Increment I5: the
 * NULL-tenant rows the super-admin console owns (open signup, platform maintenance and its notice).
 *
 * READ-ONLY. The write lives on {@see SuperAdminService::updatePlatformSettings()}, because a tenant-scoped
 * connection physically cannot author these rows: `settings` is nullable_global, so its SELECT policy
 * reveals platform rows to everyone while its INSERT/UPDATE policies stay strict — and a strict UPDATE that
 * matches nothing reports SUCCESS with zero rows affected. Splitting read from write here is what keeps
 * that trap out of reach of ordinary request code.
 *
 * Bound `scoped()` (per-request), never `singleton()`: a process-lifetime memo would mean an operator
 * switching platform maintenance on does not take effect on a long-lived worker until it restarts, and one
 * Pest test's platform row would bleed into the next.
 *
 * ── Readable from any connection, including with no tenant context at all ──────────────────────────────
 * nullable_global's SELECT policy is `tenant_id = <ctx> OR tenant_id IS NULL`, and the second disjunct is
 * true whether or not a tenant GUC is set — so the central admin host, the tenant app and the guest runtime
 * all read the same rows. That is what lets {@see EnforcePlatformMaintenance} be a
 * single global middleware rather than one per routing surface.
 *
 * ── The cost, stated ───────────────────────────────────────────────────────────────────────────────────
 * ONE indexed query per request that actually asks — the middleware skips it entirely on an exempt path,
 * and {@see Request} paths that never consult a platform setting never pay it. Caching would buy nothing:
 * `CACHE_STORE=database`, so a cache read is the same round trip as the query it would replace.
 */
final class PlatformSettings
{
    /** @var ?array<string, mixed> */
    private ?array $memo = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        /** @var array<string, mixed> $rows */
        $rows = Setting::query()
            ->whereNull('tenant_id')
            ->pluck('value', 'key')
            ->map(static fn (mixed $raw): mixed => is_string($raw) ? json_decode($raw, true) : $raw)
            ->all();

        return $this->memo = $rows;
    }

    /** The resolved value of a platform key — the stored row, or {@see SettingKey::default()}. */
    public function get(SettingKey $key): bool|string
    {
        $value = $this->all()[$key->value] ?? null;
        $default = $key->default();

        if (is_bool($default)) {
            return is_bool($value) ? $value : $default;
        }

        return is_string($value) ? $value : $default;
    }

    public function signupOpen(): bool
    {
        return (bool) $this->get(SettingKey::RegistrationOpenSignup);
    }

    public function maintenanceEnabled(): bool
    {
        return (bool) $this->get(SettingKey::MaintenanceEnabled);
    }

    /**
     * The notice shown while the window is open, falling back to the product's own copy.
     *
     * The fallback lives here rather than in the blade so the HTML arm, the JSON arm and the API envelope
     * cannot render three different sentences for one state — the same argument
     * {@see Tenant::maintenanceNotice()} makes for the tenant half.
     */
    public function maintenanceMessage(): string
    {
        $message = (string) $this->get(SettingKey::MaintenanceMessage);

        return trim($message) !== ''
            ? trim($message)
            : 'Meridian is temporarily unavailable while we carry out planned maintenance. Please try again shortly.';
    }

    /** Drop the memo — for tests, and after a console write in the same request. */
    public function forget(): void
    {
        $this->memo = null;
    }
}
