<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

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
            'about' => $this->build->toArray(),
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->superAdmin->updatePlatformSettings($request->toSettings(), $actor);

        return back()
            ->with('status', 'platform-settings-updated')
            ->with('toast', ['type' => 'success', 'message' => 'Platform settings saved']);
    }

    private function rawMaintenanceMessage(): string
    {
        $stored = $this->settings->all()['maintenance.message'] ?? null;

        return is_string($stored) ? $stored : '';
    }
}
