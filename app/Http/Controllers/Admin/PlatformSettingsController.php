<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SettingKey;
use App\Exceptions\Settings\PlatformSettingsConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\User;
use App\Services\Admin\SuperAdminService;
use App\Services\Settings\PlatformSettings;
use App\Support\Platform\BuildInfo;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform half of App Settings (PRD Feature #10) — Increment I5. Served on the central domain behind
 * `auth` + `superadmin` + `superadmin.mfa`, like the rest of the console.
 *
 * Its own controller rather than three more methods on {@see TenantAdminController}: that one is the tenant
 * LIFECYCLE console (list / suspend / reactivate / assign plan) and this is platform CONFIGURATION — two
 * jobs that share a shell and nothing else — and `scripts/controller-gate.php` caps a controller at 250
 * lines / complexity 10, which a fifth surface there would start to press against.
 *
 * Thin, like its sibling: the write delegates to {@see SuperAdminService::updatePlatformSettings()}, which
 * is where the elevated connection and the NULL-tenant audit live. Nothing here branches on
 * `is_super_admin` — the middleware already did.
 */
final class PlatformSettingsController extends Controller
{
    public function __construct(
        private readonly SuperAdminService $superAdmin,
        private readonly PlatformSettings $settings,
        private readonly BuildInfo $build,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'signup_open' => $this->settings->signupOpen(),
                'maintenance_enabled' => $this->settings->maintenanceEnabled(),
                // The RAW stored message, not the resolved one: this is an editor, and pre-filling it with
                // the product's fallback copy would silently turn "I have no message" into a message the
                // operator never wrote the moment they press Save.
                'maintenance_message' => $this->rawMaintenanceMessage(),
            ],
            // The optimistic-concurrency token for the Save below (M103, R-2173fe28). It travels with the
            // page rather than with the settings object so the form posts it back untouched.
            'fingerprint' => $this->settings->fingerprint(),
            'about' => $this->build->toArray(),
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $changed = $this->superAdmin->updatePlatformSettings(
                $request->toSettings(),
                $actor,
                $request->fingerprint(),
            );
        } catch (PlatformSettingsConflictException $e) {
            // `back()` re-runs index(), so the page re-renders from the CURRENT settings with a fresh
            // token — the operator sees what the values actually are instead of their stale copy. The
            // error rides on the `fingerprint` field because that is the input that was stale, and it is
            // what Settings.vue keys its conflict banner off.
            return back()->withErrors(['fingerprint' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'platform-settings-updated')
            ->with('toast', ['type' => 'success', 'message' => $this->savedMessage($changed)]);
    }

    /**
     * Say what the Save actually did (M103, R-2173fe28).
     *
     * The fixed 'Platform settings saved' this replaces was true of a no-op and of turning public signup
     * on, which is the row's literal complaint: an operator could not tell from the console whether the
     * thing they meant to change had changed, or whether anything had.
     *
     * @param  list<string>  $changed  setting keys whose stored value moved
     */
    private function savedMessage(array $changed): string
    {
        if ($changed === []) {
            return 'No changes to save';
        }

        $labels = array_values(array_filter(array_map(
            static fn (string $key): ?string => match (SettingKey::tryFrom($key)) {
                SettingKey::RegistrationOpenSignup => 'open signup',
                SettingKey::MaintenanceEnabled => 'platform maintenance',
                SettingKey::MaintenanceMessage => 'the maintenance notice',
                default => null,
            },
            $changed,
        )));

        return $labels === []
            ? 'Platform settings saved'
            : 'Saved — updated '.$this->sentenceList($labels);
    }

    /** @param  list<string>  $items */
    private function sentenceList(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }

    private function rawMaintenanceMessage(): string
    {
        $stored = $this->settings->all()['maintenance.message'] ?? null;

        return is_string($stored) ? $stored : '';
    }
}
