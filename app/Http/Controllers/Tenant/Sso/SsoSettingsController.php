<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Sso;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ImportSsoMetadataRequest;
use App\Http\Requests\Tenant\UpdateSsoConnectionRequest;
use App\Http\Requests\Tenant\UpdateSsoStatusRequest;
use App\Models\User;
use App\Services\Sso\SsoConnectionPresenter;
use App\Services\Sso\SsoConnectionService;
use App\Services\Sso\SsoMetadataException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's SSO settings surface (P1a — ADR-0016).
 *
 * ── WHY THIS IS ITS OWN CONTROLLER AND NOT PART OF `TenantSettingsController` ────────────────────────
 * Three reasons, in order of force. (1) That class's docblock scopes it to writes reaching TWO stores that
 * "emit the SAME audit alias, so the ledger shows one resource with one history" — SSO is a third store with
 * its own alias and its own lifecycle, and folding it in would falsify that paragraph. (2) It never renders;
 * rendering lives on `PreferencesController`, whose docblock records that the split exists because
 * `scripts/controller-gate.php` caps a controller and "a fifth surface built inline is what would breach
 * that". (3) `Tenant\Sso\` already exists from P1a (1/2) and P1b adds the login and ACS controllers beside
 * this one.
 *
 * The write targets the SUBDOMAIN-RESOLVED tenant only, never an id from the request — {@see ResolvesTenant}.
 * Transactions live in {@see SsoConnectionService}, because the audit row must be atomic with the change and
 * a controller is the wrong place to open one.
 */
final class SsoSettingsController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly SsoConnectionService $connections) {}

    /** The tenant's SSO configuration, the SP values to paste into their identity provider, and what they may change. */
    public function show(Request $request, SsoConnectionPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Settings/Sso', $presenter->page($user, $this->currentTenant()));
    }

    /**
     * Replace the identity-provider trust anchor from a pasted metadata document.
     *
     * ⚠️ THE `catch` IS THE ONLY BRANCH IN THIS CLASS, AND IT SURFACES THE MESSAGE AS A FIELD ERROR RATHER
     * THAN A TOAST. {@see SsoMetadataException} is a bare `RuntimeException` with no render arm in
     * `bootstrap/app.php`, so uncaught it is a 500 — and its own docblock says every message it carries is
     * "written to be shown VERBATIM to a tenant admin", which is exactly what a 500 page does not do. A
     * field error is the right channel over a toast for three reasons: the paste box stays on screen, the
     * failure is attributable to the one field the tenant typed into, and `withInput()` preserves a
     * multi-KB paste they would otherwise have to re-fetch from their IdP. Rendering it unescaped is safe
     * because the parser's messages are all constants — it never echoes the submitted document back.
     */
    public function importMetadata(ImportSsoMetadataRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $connection = $this->connections->importMetadata($request->metadataXml(), $user);
        } catch (SsoMetadataException $e) {
            return back()->withInput()->withErrors(['metadata_xml' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Metadata imported from {$connection->idp_entity_id}.",
        ]);
    }

    /** Amend the provisioning policy — JIT, the default role, the NameID format and the attribute map. */
    public function update(UpdateSsoConnectionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connection = $this->connections->updatePolicy($request->toColumns(), $user);

        if ($connection === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Import your identity provider’s metadata before changing these settings.',
            ]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Single sign-on settings saved.']);
    }

    /**
     * Switch single sign-on on or off.
     *
     * Its route is deliberately NOT `feature:`-gated (ADR-0016 §D5) so a downgraded tenant can still switch
     * off; the service refuses the *enable* direction instead, which is why a null return here means "not
     * entitled" rather than "no connection".
     */
    public function updateStatus(UpdateSsoStatusRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $connection = $this->connections->changeStatus($request->status(), $user);

        if ($connection === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Single sign-on is not part of your current plan, so it cannot be switched back on. You can still switch it off or remove it.',
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $connection->servesProtocol()
                ? 'Single sign-on is on.'
                : 'Single sign-on is off.',
        ]);
    }

    /** Remove the trust anchor. Ungated by the plan feature, so a downgraded tenant can always undo. */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->connections->delete($user)) {
            return back()->with('toast', ['type' => 'error', 'message' => 'There is no single sign-on configuration to remove.']);
        }

        return to_route('settings.sso')->with('toast', [
            'type' => 'success',
            'message' => 'Single sign-on configuration removed.',
        ]);
    }
}
