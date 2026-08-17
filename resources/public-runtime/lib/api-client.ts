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

import { encodeSolution, solveChallenge, type Challenge } from './challenge';
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
    /**
     * Increment P3a — the lost-update baseline, needed on SUBMIT because a submit against an existing server
     * draft performs a draft save first and then promotes, so a stale device would overwrite another
     * device's answers AND finalize them. Carried on the outbox row too, so an offline replay makes the same
     * claim it would have made live. Null/absent when this session never created a server draft, which is
     * the ordinary fill: the server has no draft to overwrite and the check does not run.
     */
    baseContentChecksum?: string | null;
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

    /*
     * Increment I8b — the proof-of-work spam check. Everything about it lives in this file, so no
     * component, composable, outbox row or service-worker file learns the feature exists.
     *
     * ⚠️ THE CHALLENGE IS FETCHED AND SOLVED AT SEND TIME, NEVER AT FILL TIME, AND THAT IS WHAT MAKES
     * OFFLINE WORK. A challenge captured while someone fills the form would be days stale by the time a
     * queued row drains in a village with signal. But `replay.ts` already re-mints the share token and
     * re-resolves the schema immediately before every outbox POST — a row is only ever replayed at a
     * moment the device is demonstrably online and already talking to us — so a challenge minted 200ms
     * before the POST cannot expire. The consequence is the design's whole value: `OutboxRow` gains no
     * columns, `lib/db.ts` needs no Dexie version bump, and outbox.ts / replay.ts / sw.ts / RuntimeSession
     * are untouched. If a diff ever touches those, something here went wrong.
     */
    let challengeRequired = false;

    async function solveChallengeHeader(token: string): Promise<string> {
        const response = await doFetch(`/api/v1/public/f/${encodeURIComponent(token)}/challenge`, {
            method: 'POST',
            headers: { Accept: 'application/json' },
        });
        const body = await parseBody(response);
        if (!response.ok) {
            throw toError(response, body);
        }

        return encodeSolution(await solveChallenge((body as { data: Challenge }).data));
    }

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
            // Self-configure from the schema the caller was fetching anyway (I8b). App.vue calls this on
            // load and replay.ts calls it once per drain pass, so both paths pick it up for free — and
            // nobody has to remember to wire it. A ROUND-TRIP OPTIMISATION ONLY: submit() recovers from a
            // 403 regardless, which is what covers rows 2..n of a drain reusing a cached schema.
            challengeRequired = body.data.form.bot_challenge === 'proof_of_work';

            return body.data;
        },

        async submit(payload: SubmitPayload): Promise<SubmitResult> {
            // We need the HTTP status (201 vs 200) so we don't fold this into withFreshToken's body-only return.
            const run = (token: string, challengeHeader: string | null): Promise<Response> =>
                doFetch(`/api/v1/public/f/${encodeURIComponent(token)}/submissions`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        // A HEADER, not a body field — see VerifyGuestBotChallenge. It keeps the solution
                        // out of the validated payload, out of the stored answers, out of openapi.json's
                        // request schema and, decisively, out of the persisted OutboxRow.
                        ...(challengeHeader ? { 'X-Meridian-Challenge': challengeHeader } : {}),
                    },
                    body: JSON.stringify({
                        answers: payload.answers,
                        client_submission_uuid: payload.clientSubmissionUuid,
                        locale: payload.locale,
                        ...(payload.guestContactEmail ? { guest_contact_email: payload.guestContactEmail } : {}),
                        ...(payload.deviceId ? { device_id: payload.deviceId } : {}),
                        ...(payload.appVersion ? { app_version: payload.appVersion } : {}),
                        // Increment P3a — conditionally spread, UNLIKE the draft channel's, and the
                        // difference is the server's posture rather than an inconsistency: submit checks
                        // only when a claim is made, so that an outbox row serialized by an older build
                        // replays instead of being refused. Sending an explicit null here would be a claim
                        // of "the server holds nothing", which is a different and wrong assertion.
                        ...(payload.baseContentChecksum ? { base_content_checksum: payload.baseContentChecksum } : {}),
                    }),
                });

            let challengeHeader = challengeRequired ? await solveChallengeHeader(currentToken) : null;
            let response = await run(currentToken, challengeHeader);

            if (response.status === 401) {
                const peek = normalizeError(response.status, await parseBody(response.clone()), null);
                if (peek.kind === 'remint') {
                    await remint();
                    // The new token needs its own challenge: a spent one cannot be reused, and the old
                    // one may well have been spent by the attempt we are retrying.
                    challengeHeader = challengeRequired ? await solveChallengeHeader(currentToken) : null;
                    response = await run(currentToken, challengeHeader);
                }
            }

            // The same retry-once shape as the 401 above, applied to the second credential this request
            // carries. Reached when the hint was absent or stale — the common case being rows 2..n of an
            // outbox drain, which reuse a cached schema and so never called fetchSchema().
            if (response.status === 403) {
                const peek = normalizeError(response.status, await parseBody(response.clone()), null);
                if (peek.kind === 'challenge') {
                    challengeRequired = true;
                    response = await run(currentToken, await solveChallengeHeader(currentToken));
                }
            }

            const body = await parseBody(response);
            if (!response.ok) {
                throw toError(response, body);
            }
            const data = (body as { data: { id: string; reference: string; status: string } }).data;
            return { id: data.id, reference: data.reference, status: data.status, created: response.status === 201 };
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
                    content_checksum: string | null;
                };
            }>((token) =>
                doFetch(`/api/v1/public/f/${encodeURIComponent(token)}/draft`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        answers: payload.answers,
                        client_submission_uuid: payload.clientSubmissionUuid,
                        locale: payload.locale,
                        // Increment P3a — sent even when null, unlike the conditionally-spread fields below.
                        // A first save genuinely HAS no base, and null is the value that says so; omitting
                        // the key entirely would look identical to a client that forgot, and the server
                        // cannot tell those apart.
                        base_content_checksum: payload.baseContentChecksum ?? null,
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
                contentChecksum: data.content_checksum,
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
                content_checksum: string | null;
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
        contentChecksum: data.content_checksum,
    };
}

export { ApiError } from './error-normalizer';
