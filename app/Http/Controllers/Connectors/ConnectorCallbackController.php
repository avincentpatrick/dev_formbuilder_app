<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connectors;

use App\Exceptions\Connectors\ConnectorOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EstablishConnectorOauthContext;
use App\Services\Connectors\ConnectionService;
use App\Services\Connectors\ConnectorRedirector;
use App\Support\Connectors\ConnectorOAuthState;
use App\Support\Connectors\ConnectorRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The native-connector OAuth callback (ADR-0009 §D2, H15a) — the CENTRAL-domain endpoint every provider
 * redirects to, for every tenant.
 *
 * It runs with no session and no CSRF token, because a third-party GET arriving from a consent screen has
 * neither; `state` is the CSRF control (which is the role OAuth assigns it), verified by
 * {@see EstablishConnectorOauthContext} before this runs and before any RLS context exists. By the time this
 * method executes, the tenant GUC is set from claims we signed, so the write below can only land in the
 * tenant that started the flow.
 *
 * Every exit is a redirect to the TENANT's own host, built by {@see ConnectorRedirector} from the `domains`
 * table — never echoed from the request — with the outcome as a query parameter, since the central host
 * cannot write the tenant host's session cookie. There is no error page here: a human who just clicked
 * "Allow" should end up back in their own app either way.
 */
final class ConnectorCallbackController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly ConnectionService $connections,
        private readonly ConnectorRedirector $redirector,
    ) {}

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        /** @var ConnectorOAuthState $state */
        $state = $request->attributes->get('connectorOauthState');

        $adapter = $this->registry->fromKey($provider);

        // The user declined at the consent screen (or the provider refused before issuing a code). Not an
        // error condition — bounce them home with the reason.
        $error = $request->query('error');
        if (is_string($error) && $error !== '') {
            return redirect()->away($this->redirector->failureUrl($state->tenantId, $adapter->key(), $error));
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            return redirect()->away($this->redirector->failureUrl($state->tenantId, $adapter->key(), 'missing_code'));
        }

        try {
            $grant = $adapter->exchangeCode($code, $this->redirector->callbackUrl($adapter->key()));
        } catch (ConnectorOAuthException $e) {
            return redirect()->away($this->redirector->failureUrl($state->tenantId, $adapter->key(), $e->errorCode));
        }

        $this->connections->upsertFromGrant($adapter->key(), $grant, $state->tenantId, $state->userId);

        return redirect()->away($this->redirector->successUrl($state->tenantId, $adapter->key()));
    }
}
