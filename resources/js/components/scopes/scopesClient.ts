// Read-only sidecar fetches for the scoping page (Increment G10b2).
//
// Reuses the builder's `builderClient` rather than relocating or forking it — it already carries the
// XSRF-cookie handling, the same-origin credentials and the non-ok error shape, and it is the repo's only
// fetch client. This is its second consumer.
//
// READS ONLY, deliberately. Every mutation on /scopes is an Inertia visit, because bootstrap/app.php keys
// its API branch on the `api/v1/*` PATH: a ScopeNodeException or GrantException raised on a web route is
// rendered as `back()->with('toast')`, a 302, even for an XHR. builderClient would follow that redirect,
// see `response.ok === true`, skip its guarded non-ok branch and throw a raw SyntaxError parsing HTML.
// These two endpoints raise no domain exception, and a 403/404 on them still negotiates to JSON.

import { builderClient, BuilderRequestError } from '@/components/builder/builderClient';
import type { NodeGrant, NodeImpact } from './types';

/** Returns null on failure — the callers render a "couldn't load" state rather than blocking the page. */
async function read<T>(url: string): Promise<T | null> {
    try {
        const result = await builderClient.get<T>(url);
        return result.conflict ? null : result.data;
    } catch (error) {
        if (error instanceof BuilderRequestError) return null;
        throw error;
    }
}

export async function fetchNodeGrants(nodeId: string): Promise<NodeGrant[] | null> {
    const payload = await read<{ grants: NodeGrant[] }>(`/scopes/${nodeId}/grants`);
    return payload?.grants ?? null;
}

export async function fetchNodeImpact(nodeId: string): Promise<NodeImpact | null> {
    return read<NodeImpact>(`/scopes/${nodeId}/impact`);
}
