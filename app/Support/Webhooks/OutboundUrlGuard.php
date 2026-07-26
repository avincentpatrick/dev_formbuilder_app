<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Exceptions\Webhooks\BlockedWebhookUrlException;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Rules\PublicHttpUrl;

/**
 * The single SSRF authority for outbound webhook URLs (technical-architecture.md §7.4,
 * webhook-integration-design.md §2.2). Used at BOTH checkpoints so the two cannot drift:
 *   1. at endpoint save ({@see PublicHttpUrl}), and
 *   2. immediately before EVERY delivery attempt ({@see DeliverWebhookJob}) — the
 *      DNS-rebinding defense, since a hostname public at save time can be repointed internally before a
 *      retry fires (the retry window spans ~7 days).
 *
 * The check: the URL must be http(s) with a host; the host is resolved to its A records (or is a literal IP);
 * and NO resolved address may fall in a private, loopback, link-local, or otherwise reserved range
 * (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` — which also covers 127.0.0.0/8 and the
 * 169.254.169.254 cloud-metadata address). A non-resolving host is refused (fail-closed: it cannot be
 * proven public). Exact hostnames in `config('webhooks.ssrf_allowlist')` are permitted regardless (the
 * enterprise/on-prem escape hatch). IPv6-only hosts do not resolve via the A-record lookup and are therefore
 * refused fail-closed — a deliberate H13a narrowing.
 */
final class OutboundUrlGuard
{
    /**
     * @throws BlockedWebhookUrlException when the URL is malformed, non-http(s), unresolvable, or resolves
     *                                    to a non-public address
     */
    public function assertPublic(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            throw BlockedWebhookUrlException::malformed();
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw BlockedWebhookUrlException::insecureScheme();
        }

        $host = strtolower($parts['host']);

        if (in_array($host, $this->allowlist(), true)) {
            return; // explicitly trusted enterprise/on-prem host
        }

        foreach ($this->resolve($host) as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw BlockedWebhookUrlException::privateAddress();
            }
        }
    }

    /**
     * The addresses a host resolves to. A literal IP host resolves to itself; otherwise the A records via
     * `gethostbynamel()`. A host that resolves to nothing throws (fail-closed) — it cannot be proven public.
     *
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // Strip an IPv6 literal's brackets (`[::1]`) before validating.
        $literal = trim($host, '[]');

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || $addresses === []) {
            throw BlockedWebhookUrlException::unresolvable();
        }

        return $addresses;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        /** @var list<string> $list */
        $list = config('webhooks.ssrf_allowlist', []);

        return array_map('strtolower', $list);
    }
}
