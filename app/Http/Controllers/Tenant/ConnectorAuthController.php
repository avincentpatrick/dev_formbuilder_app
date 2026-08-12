<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EstablishConnectorOauthContext;
use App\Models\User;
use App\Services\Connectors\ConnectorRedirector;
use App\Support\Connectors\ConnectorOAuthStateService;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Starts a native-connector OAuth flow (ADR-0009, H15a). This is the ONLY half of the flow that runs on the
 * tenant subdomain with a session — the browser leaves for the provider's consent screen and comes back to
 * the central-domain callback, which has no session to return to (see
 * {@see EstablishConnectorOauthContext}).
 *
 * It therefore does one thing: mint the signed `state` that carries tenant + user identity across that host
 * boundary, and send the browser to the provider. Authorization is the route's `can:` policy gate +
 * `feature:native_connectors`; no `ability:` (that is Sanctum token-scope middleware, and a session carries
 * no token — the H14 precedent).
 *
 * There is no page here: H15a is backend+api and H15b builds the Integrations UI that will link to this
 * route. Until then the flow is exercised by route and test, exactly as the webhook API was until H14.
 */
final class ConnectorAuthController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectorOAuthStateService $states,
        private readonly ConnectorRedirector $redirector,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $adapter = $this->registry->fromKey($provider);

        // The tenant comes from the established RLS context, not from anything the request supplied — the
        // same source the rest of this surface writes under, so the state can only ever name the tenant
        // whose subdomain the user is actually authenticated on.
        $state = $this->states->mint(
            (string) TenantContext::currentTenantId(),
            (string) $user->getKey(),
            $adapter->key(),
        );

        // away(): the destination is the provider's own domain, not a named route in this app.
        return redirect()->away(
            $adapter->authorizeUrl(
                $state,
                $this->redirector->callbackUrl($adapter->key()),
                // H16c. Derived from the state rather than stashed in the session, because the callback that
                // needs it again has no session to read — the same reason the state itself is a token.
                $this->states->codeVerifierFor($state),
            )
        );
    }
}
