// Sidecar fetches for the Integrations page (H15b): three reads and, since H16b, one write.
//
// Reuses `builderClient` for the same reasons scopesClient does — it is the repo's only fetch client and it
// already carries the XSRF-cookie handling, same-origin credentials and non-ok error shape. This is its third
// consumer.
//
// Rule mutations on /integrations are Inertia visits. That was first FORCED: until M90 a domain exception on a
// web route rendered as `back()->with('toast')`, a 302, even for an XHR, which builderClient would follow and
// choke on. Since M90 a JSON caller gets a flat JSON refusal and no flash, so a fetch could read one — though
// every failure path below still returns `null` or a generic sentence, and the server's message is dropped.
//
// The channel endpoint answers 200 even when the listing failed, carrying `error` in the body. That is what
// lets the picker show WHY it is empty; a 502 would arrive here as a thrown BuilderRequestError and the reason
// would be gone by the time the modal rendered.

import { builderClient, BuilderRequestError } from '@/components/builder/builderClient';
import type { ChannelsPayload, MappableColumnsPayload, TabularDestinationPayload } from './types';

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

// ── H16b: ONE WRITE LIVES HERE ─────────────────────────────────────────────────────────────────────────────
// `createDestination` is a POST. The read-only rule it seemed to break was made when a domain exception on a
// web route was a 302 even to a fetch, and that stopped at M90. `TabularDestinationDirectory` never throws and
// answers 200 with a nullable `error` for every outcome; the one domain refusal that can reach these endpoints
// is their `feature:native_connectors` gate, now a readable 402 that `transportFailure()` reduces to a sentence.
//
// An Inertia visit was the alternative and is wrong here on BEHAVIOUR, not taste: this produces a value the
// OPEN, UNSAVED form needs (the new id, its tab, its header row), so a redirect would have to round-trip the
// tenant's half-written rule through the session to survive. Every rule MUTATION is still an Inertia visit.

/**
 * Everything a destination column can be bound to for the form this rule is scoped to. Null on a request
 * failure — the caller degrades to "we couldn't load your fields", the same shape as {@link fetchChannels}.
 */
export async function fetchMappableColumns(
    connectionId: string,
    formId: string | null,
): Promise<MappableColumnsPayload | null> {
    const query = formId ? `?form_id=${encodeURIComponent(formId)}` : '';

    try {
        const result = await builderClient.get<MappableColumnsPayload>(
            `/integrations/connections/${connectionId}/columns${query}`,
        );
        return result.conflict ? null : result.data;
    } catch (error) {
        if (error instanceof BuilderRequestError) return null;
        throw error;
    }
}

/** Read an existing document's tabs and header row — a Google spreadsheet's tabs or an Airtable base's tables. */
export async function inspectDestination(
    connectionId: string,
    reference: string,
    sheetName?: string | null,
): Promise<TabularDestinationPayload> {
    const query = new URLSearchParams({ reference });

    if (sheetName) {
        query.set('sheet_name', sheetName);
    }

    try {
        const result = await builderClient.get<TabularDestinationPayload>(
            `/integrations/connections/${connectionId}/destinations?${query.toString()}`,
        );
        return result.conflict ? transportFailure() : result.data;
    } catch (error) {
        if (error instanceof BuilderRequestError) return transportFailure();
        throw error;
    }
}

/**
 * Create a document in the tenant's provider account with `headers` in row 1.
 *
 * Only offered for a provider that can provision — Airtable deliberately cannot (ADR-0009 §D8), and its editor
 * renders no create control, so this is never called for one.
 */
export async function createDestination(
    connectionId: string,
    title: string,
    headers: string[],
): Promise<TabularDestinationPayload> {
    try {
        const result = await builderClient.post<TabularDestinationPayload>(
            `/integrations/connections/${connectionId}/destinations`,
            { title, headers },
        );
        return result.conflict ? transportFailure() : result.data;
    } catch (error) {
        if (error instanceof BuilderRequestError) return transportFailure();
        throw error;
    }
}

/**
 * What the two destination calls return when the REQUEST failed rather than the provider.
 *
 * {@link fetchChannels} answers null and its caller renders "couldn't load", which is right for an aid the
 * tenant can skip. It is wrong for these two: they gate the destination, so a null would leave the editor
 * unable to distinguish "the call failed" from "we asked and there is nothing". Non-null either way, matching
 * the server's own contract.
 */
function transportFailure(): TabularDestinationPayload {
    return {
        destination: null,
        error: 'We couldn’t reach Meridian just then. Check your connection and try again.',
    };
}
