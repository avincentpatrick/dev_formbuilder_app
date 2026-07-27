// Read-only sidecar fetch for the Integrations page (H15b).
//
// Reuses `builderClient` for the same reasons scopesClient does — it is the repo's only fetch client and it
// already carries the XSRF-cookie handling, same-origin credentials and non-ok error shape. This is its third
// consumer.
//
// READ ONLY, deliberately. Every mutation on /integrations is an Inertia visit, because bootstrap/app.php keys
// its API branch on the `api/v1/*` PATH: a domain exception raised on a web route renders as
// `back()->with('toast')`, a 302, even for an XHR — which builderClient would follow, see `response.ok`, and
// then choke parsing HTML.
//
// The channel endpoint answers 200 even when the listing failed, carrying `error` in the body. That is what
// lets the picker show WHY it is empty; a 502 would arrive here as a thrown BuilderRequestError and the reason
// would be gone by the time the modal rendered.

import { builderClient, BuilderRequestError } from '@/components/builder/builderClient';
import type { ChannelsPayload } from './types';

/**
 * The destinations this grant can deliver into. Returns null only when the request itself failed (403/404/419
 * or a network error) — a provider-side failure comes back as a normal payload with `error` set, so the caller
 * can tell "you can't ask" apart from "we asked and Slack said no".
 */
export async function fetchChannels(connectionId: string): Promise<ChannelsPayload | null> {
    try {
        const result = await builderClient.get<ChannelsPayload>(
            `/integrations/connections/${connectionId}/channels`,
        );
        return result.conflict ? null : result.data;
    } catch (error) {
        if (error instanceof BuilderRequestError) return null;
        throw error;
    }
}
