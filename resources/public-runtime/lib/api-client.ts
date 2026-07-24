/**
 * The SPA's only network layer. Every call is a SAME-ORIGIN relative URL: the shell is served from the tenant
 * subdomain (`/f/{slug}`) and the host-agnostic `/api/v1/public/*` endpoints resolve the tenant purely from
 * the share token, so no CORS, no cookies, no CSRF (the group has no `web` middleware).
 *
 * The client is STATEFUL — it owns the current share token and the slug, so token lifecycle is invisible to
 * the components: an expired token (`401 share_token_expired`) is transparently re-minted and the call retried
 * once. A superseding republish (`409 form_updated` / `submission_version_superseded`) is NOT auto-handled —
 * it surfaces as an `ApiError` (kind `refresh`) so the caller can re-mint AND re-fetch the new schema.
 * Any other non-2xx becomes an `ApiError` carrying the normalized shape the caller branches on.
 */

import { ApiError, normalizeError } from './error-normalizer';
import type {
    AnswerMap,
    MintResponse,
    ResumeDraftResult,
    SaveDraftPayload,
    SaveDraftResult,
    SchemaResponse,
    SubmitResult,
} from './types';

export interface SubmitPayload {
    // A nested repeat-section value (Increment G2) serializes straight through as a JSON array of instance maps.
    answers: AnswerMap;
    clientSubmissionUuid: string;
    locale: string;
    guestContactEmail?: string | null;
    // Increment G8b — device provenance, threaded through for both live submits and offline outbox replay.
    deviceId?: string | null;
    appVersion?: string | null;
}

export interface ApiClient {
    fetchSchema(): Promise<SchemaResponse>;
    submit(payload: SubmitPayload): Promise<SubmitResult>;
    // Increment H10 — upsert the durable server draft; returns the resume handle (token + url + completeness).
    saveDraft(payload: SaveDraftPayload): Promise<SaveDraftResult>;
    remint(): Promise<MintResponse>;
    token(): string;
}

async function parseBody(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function toError(response: Response, body: unknown): ApiError {
    return new ApiError(normalizeError(response.status, body, response.headers.get('Retry-After')));
}

export function createApiClient(options: { token: string; slug: string; fetch?: typeof fetch }): ApiClient {
    const doFetch = options.fetch ?? fetch;
    let currentToken = options.token;

    async function remint(): Promise<MintResponse> {
        const response = await doFetch(`/f/${encodeURIComponent(options.slug)}`, {
            headers: { Accept: 'application/json' },
        });
        const body = await parseBody(response);
        if (!response.ok) {
            throw toError(response, body);
        }
        const mint = body as MintResponse;
        currentToken = mint.shareToken;
        return mint;
    }

    /** Run a token-bound request; on a bare expiry, re-mint once and retry (transparent to the caller). */
    async function withFreshToken<T>(run: (token: string) => Promise<Response>): Promise<T> {
        let response = await run(currentToken);
        if (response.status === 401) {
            const body = await parseBody(response.clone());
            const normalized = normalizeError(response.status, body, response.headers.get('Retry-After'));
            if (normalized.kind === 'remint') {
                await remint();
                response = await run(currentToken);
            }
        }
        const body = await parseBody(response);
        if (!response.ok) {
            throw toError(response, body);
        }
        return body as T;
    }

    return {
        token: () => currentToken,
        remint,

        async fetchSchema(): Promise<SchemaResponse> {
            const body = await withFreshToken<{ data: SchemaResponse }>((token) =>
                doFetch(`/api/v1/public/f/${encodeURIComponent(token)}`, { headers: { Accept: 'application/json' } }),
            );
            return body.data;
        },

        async submit(payload: SubmitPayload): Promise<SubmitResult> {
            // We need the HTTP status (201 vs 200) so we don't fold this into withFreshToken's body-only return.
            const run = (token: string): Promise<Response> =>
                doFetch(`/api/v1/public/f/${encodeURIComponent(token)}/submissions`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        answers: payload.answers,
                        client_submission_uuid: payload.clientSubmissionUuid,
                        locale: payload.locale,
                        ...(payload.guestContactEmail ? { guest_contact_email: payload.guestContactEmail } : {}),
                        ...(payload.deviceId ? { device_id: payload.deviceId } : {}),
                        ...(payload.appVersion ? { app_version: payload.appVersion } : {}),
                    }),
                });

            let response = await run(currentToken);
            if (response.status === 401) {
                const peek = normalizeError(response.status, await parseBody(response.clone()), null);
                if (peek.kind === 'remint') {
                    await remint();
                    response = await run(currentToken);
                }
            }
            const body = await parseBody(response);
            if (!response.ok) {
                throw toError(response, body);
            }
            const data = (body as { data: { id: string; status: string } }).data;
            return { id: data.id, status: data.status, created: response.status === 201 };
        },

        async saveDraft(payload: SaveDraftPayload): Promise<SaveDraftResult> {
            // Share-token-bound like submit(), so it rides withFreshToken (transparent re-mint on expiry). A
            // republish surfaces as an ApiError kind `refresh` (409 form_updated) exactly like submit().
            const body = await withFreshToken<{
                data: {
                    id: string;
                    completeness_percent: number | null;
                    resume_token: string;
                    resume_url: string;
                    expires_at: string;
                };
            }>((token) =>
                doFetch(`/api/v1/public/f/${encodeURIComponent(token)}/draft`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        answers: payload.answers,
                        client_submission_uuid: payload.clientSubmissionUuid,
                        locale: payload.locale,
                        ...(payload.draftCurrentStep ? { draft_current_step: payload.draftCurrentStep } : {}),
                        ...(payload.guestContactEmail ? { guest_contact_email: payload.guestContactEmail } : {}),
                        ...(payload.deviceId ? { device_id: payload.deviceId } : {}),
                        ...(payload.appVersion ? { app_version: payload.appVersion } : {}),
                        ...(payload.finishLater ? { finish_later: true } : {}),
                    }),
                }),
            );
            const data = body.data;
            return {
                id: data.id,
                completenessPercent: data.completeness_percent,
                resumeToken: data.resume_token,
                resumeUrl: data.resume_url,
                expiresAt: data.expires_at,
            };
        },
    };
}

/**
 * Fetch a saved draft's state for a resume link (Increment H10). Deliberately STANDALONE, not an
 * {@link ApiClient} method: it runs BEFORE a share token exists and authenticates with the RESUME token in the
 * path (a different context — `EstablishGuestDraftContext`), so it must NOT go through `withFreshToken`/re-mint.
 * The response carries a fresh SHARE token the caller then hands to {@link createApiClient} for the resumed
 * session. A promoted/reaped draft → 404 `draft_not_found`, normalized to kind `terminal`.
 */
export async function resumeDraft(
    resumeToken: string,
    options: { fetch?: typeof fetch } = {},
): Promise<ResumeDraftResult> {
    const doFetch = options.fetch ?? fetch;
    const response = await doFetch(`/api/v1/public/drafts/${encodeURIComponent(resumeToken)}`, {
        headers: { Accept: 'application/json' },
    });
    const body = await parseBody(response);
    if (!response.ok) {
        throw toError(response, body);
    }
    const data = (
        body as {
            data: {
                id: string;
                completeness_percent: number | null;
                client_submission_uuid: string | null;
                form_version_id: string;
                answers: AnswerMap;
                last_saved_at: string | null;
                draft_current_step: string | null;
                locale: string | null;
                share_token: string;
                share_token_expires_at: string;
            };
        }
    ).data;
    return {
        id: data.id,
        completenessPercent: data.completeness_percent,
        clientSubmissionUuid: data.client_submission_uuid,
        formVersionId: data.form_version_id,
        answers: data.answers ?? {},
        lastSavedAt: data.last_saved_at,
        draftCurrentStep: data.draft_current_step,
        locale: data.locale,
        shareToken: data.share_token,
        shareTokenExpiresAt: data.share_token_expires_at,
    };
}

export { ApiError } from './error-normalizer';
