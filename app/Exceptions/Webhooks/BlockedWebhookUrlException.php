<?php

declare(strict_types=1);

namespace App\Exceptions\Webhooks;

use App\Rules\PublicHttpUrl;
use App\Support\Webhooks\OutboundUrlGuard;
use RuntimeException;

/**
 * A webhook destination URL failed SSRF validation ({@see OutboundUrlGuard}) — either at save time (→ a
 * validation error via {@see PublicHttpUrl}) or at delivery time (→ the attempt is recorded as a
 * failed `[dns_rebinding_blocked]` delivery). `reason` is a short machine tag for the observability marker;
 * the message is human-facing.
 */
final class BlockedWebhookUrlException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function malformed(): self
    {
        return new self('The webhook URL must be a valid http(s) URL.', 'malformed_url');
    }

    public static function insecureScheme(): self
    {
        return new self('The webhook URL must use http or https.', 'invalid_scheme');
    }

    public static function unresolvable(): self
    {
        return new self('The webhook URL host could not be resolved to a public address.', 'unresolvable_host');
    }

    public static function privateAddress(): self
    {
        return new self('The webhook URL resolves to a private or reserved network address, which is not allowed.', 'dns_rebinding_blocked');
    }
}
