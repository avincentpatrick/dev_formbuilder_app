<?php

declare(strict_types=1);

namespace App\Support\Connectors;

use App\Enums\ConnectorProviderKey;
use App\Http\Controllers\Connectors\ConnectorCallbackController;
use App\Services\Connectors\ConnectorRedirector;

/**
 * Turns the OAuth callback's return query into one toast (H15b, ADR-0009 §D5).
 *
 * WHY A QUERY PARAMETER AT ALL. The provider callback lands on the CENTRAL domain and the session cookie is
 * host-only, so the central host cannot write the tenant host's session — there is no flash across that
 * boundary. {@see ConnectorRedirector} therefore returns the outcome as
 * `?connected=slack` or `?provider=slack&connect_error=<code>`, and this class is where that becomes something
 * a human reads. The Integrations controller then consumes it into a real session flash and redirects to a
 * clean URL, so the one-shot semantics are the ones the app already has rather than a second set.
 *
 * THE ERROR CODE IS UNTRUSTED INPUT. {@see ConnectorCallbackController} passes
 * the provider's own `?error=` value through verbatim (truncated to 64 characters), and a callback URL can be
 * visited by anyone with the link — so the code is attacker-influenceable text arriving from a third party. It
 * is used ONLY as a lookup key into the closed table below, never rendered. An unrecognised code produces the
 * generic line; it never reaches a page, an exception message or a log line as content.
 */
final class ConnectorConnectOutcome
{
    private const GENERIC_FAILURE = 'couldn’t be connected. Please try again, or contact support if this keeps happening.';

    /**
     * The toast for this request, or null when neither outcome parameter is present.
     *
     * @return array{type: 'success'|'error', message: string}|null
     */
    public static function toast(?string $connected, ?string $provider, ?string $errorCode): ?array
    {
        // AN ERROR WINS OVER A SUCCESS. The real callback never emits both parameters, but the return URL is
        // just a link — anyone can craft one carrying both, and announcing a green "connected" for a grant
        // that does not exist is the one failure mode here worth engineering against. Fail toward the
        // less reassuring answer.
        if (is_string($errorCode) && $errorCode !== '') {
            return [
                'type' => 'error',
                'message' => self::failureMessage(self::label($provider ?? $connected), $errorCode),
            ];
        }

        if (is_string($connected) && $connected !== '') {
            return [
                'type' => 'success',
                'message' => self::label($connected).' connected. Add a delivery rule to start sending events.',
            ];
        }

        return null;
    }

    /**
     * The provider's display name, resolved through the enum so a bogus `?provider=` cannot put arbitrary text
     * in a sentence. Anything unrecognised degrades to a neutral noun rather than being echoed.
     */
    private static function label(?string $provider): string
    {
        $key = is_string($provider) ? ConnectorProviderKey::tryFrom($provider) : null;

        return $key?->label() ?? 'The integration';
    }

    private static function failureMessage(string $label, string $errorCode): string
    {
        return match ($errorCode) {
            // The human declined at the consent screen. Not a fault, and saying so avoids a support ticket.
            'access_denied' => 'You cancelled the authorization — nothing was connected.',
            'missing_code' => $label.' didn’t send an authorization code. Please try connecting again.',
            'missing_access_token' => $label.' finished sign-in but didn’t return an access token. Please try again.',
            'transport_error' => 'We couldn’t reach '.$label.'. Check your connection and try again.',
            // A single-use code replayed, or a consent screen left open past its window.
            'invalid_code', 'bad_verification_code', 'invalid_grant' => 'That authorization link had already been used or had expired. Start the connection again.',
            // Nothing the tenant can fix — this is our app registration, not their workspace.
            'invalid_client_id', 'bad_client_secret', 'invalid_client_secret' => 'This deployment\'s '.$label.' app credentials were rejected. Contact your administrator.',
            default => $label.' '.self::GENERIC_FAILURE,
        };
    }
}
