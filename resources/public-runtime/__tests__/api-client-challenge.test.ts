import { describe, expect, it, vi } from 'vitest';
import { createApiClient } from '../lib/api-client';
import { sha256Hex } from '../lib/challenge';

/**
 * The proof-of-work spam check as the client sees it (Increment I8b).
 *
 * ⚠️ EVERYTHING ABOUT THIS FEATURE LIVES IN api-client.ts, AND THIS FILE EXISTS TO KEEP IT THERE. No
 * component, composable, outbox row or service-worker file knows it exists — which is what lets a queued
 * offline submission carry a challenge solved 200ms before it is sent rather than days earlier when the
 * respondent filled the form. If `db.ts`, `outbox.ts`, `replay.ts` or `sw.ts` ever needs a line for this,
 * the design has drifted and the offline story has quietly broken.
 *
 * Kept separate from api-client.test.ts because it needs its own fetch router (three endpoints instead of
 * one) and because the challenge is a distinct concern from token lifecycle.
 */

function res(status: number, body: unknown, headers: Record<string, string> = {}): Response {
    const response = {
        ok: status >= 200 && status < 300,
        status,
        headers: new Headers(headers),
        json: async () => body,
        clone(): Response {
            return response as unknown as Response;
        },
    };

    return response as unknown as Response;
}

const SALT = 'testsalt.eyJmaWQiOiJ4In0';

/** A challenge whose answer is `n`, shaped exactly as GuestChallengeService issues it. */
function challengeBody(n: number) {
    return {
        algorithm: 'SHA-256',
        challenge: sha256Hex(SALT + n),
        salt: SALT,
        maxnumber: 50,
        signature: 'sig',
    };
}

const payload = { answers: {}, clientSubmissionUuid: 'u', locale: 'en' };

type Call = [string, { method?: string; headers: Record<string, string> }];

function urlsOf(fetchImpl: { mock: { calls: Call[] } }, suffix: string): Call[] {
    return fetchImpl.mock.calls.filter(([url]) => String(url).endsWith(suffix));
}

describe('bot challenge', () => {
    it('sends no challenge header, and no round trip, for a form that does not require one', async () => {
        const fetchImpl = vi.fn(async (url: string) =>
            String(url).endsWith('/submissions')
                ? res(201, { data: { id: 'a', status: 'submitted' } })
                : res(200, { data: { form: { id: 'f' }, version: { id: 'v' } } }),
        );
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();
        await client.submit(payload);

        // Zero cost for the forms that opted out — which is every form until an author says otherwise.
        expect(urlsOf(fetchImpl as never, '/challenge')).toHaveLength(0);
        expect(urlsOf(fetchImpl as never, '/submissions')[0][1].headers).not.toHaveProperty('X-Meridian-Challenge');
    });

    it('solves and attaches a challenge when the schema said the form requires one', async () => {
        const body = challengeBody(2);
        const fetchImpl = vi.fn(async (url: string) => {
            const path = String(url);
            if (path.endsWith('/challenge')) return res(200, { data: body });
            if (path.endsWith('/submissions')) return res(201, { data: { id: 'a', status: 'submitted' } });

            return res(200, { data: { form: { id: 'f', bot_challenge: 'proof_of_work' }, version: { id: 'v' } } });
        });
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();
        await client.submit(payload);

        const header = urlsOf(fetchImpl as never, '/submissions')[0][1].headers['X-Meridian-Challenge'];
        expect(header).toBeTruthy();

        const solved = JSON.parse(atob(header));
        expect(solved.number).toBe(2);
        expect(solved.signature).toBe('sig');
        // ⚠️ POST, not GET. sw.ts NetworkFirst-caches GET on this path prefix, so a GET challenge would be
        // served from cache and then rejected by the server's replay guard — a bug that appears only
        // after the service worker activates.
        expect(urlsOf(fetchImpl as never, '/challenge')[0][1].method).toBe('POST');
    });

    it('recovers from a 403 challenge_required with exactly ONE re-solve and retry', async () => {
        // The path rows 2..n of an outbox drain take: replay.ts caches one SchemaResponse per slug per
        // pass, so those clients never call fetchSchema() and carry no hint. Correctness lives here, not
        // in the hint — which is the whole reason the hint is allowed to be a mere optimisation.
        let submits = 0;
        const fetchImpl = vi.fn(async (url: string) => {
            if (String(url).endsWith('/challenge')) return res(200, { data: challengeBody(1) });
            submits++;

            return submits === 1
                ? res(403, { error: { code: 'challenge_required', message: 'nope' } })
                : res(201, { data: { id: 'a', status: 'submitted' } });
        });
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        expect(await client.submit(payload)).toEqual({ id: 'a', status: 'submitted', created: true });
        expect(submits).toBe(2);
        expect(urlsOf(fetchImpl as never, '/challenge')).toHaveLength(1);
    });

    it('gives up after ONE retry and surfaces kind challenge, never an infinite loop', async () => {
        // A loop here would hammer the endpoint from inside a service worker, on a device that may be on
        // metered data. The kind matters too: `challenge` keeps the row pending in replay.ts, where
        // `terminal` would park it for a human who cannot do anything about a spam check.
        const fetchImpl = vi.fn(async (url: string) =>
            String(url).endsWith('/challenge')
                ? res(200, { data: challengeBody(1) })
                : res(403, { error: { code: 'challenge_failed', message: 'nope' } }),
        );
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await expect(client.submit(payload)).rejects.toMatchObject({ normalized: { kind: 'challenge' } });
        expect(urlsOf(fetchImpl as never, '/submissions')).toHaveLength(2);
    });

    it('re-solves after a token re-mint rather than replaying a spent challenge', async () => {
        // A challenge is single-use, and the one attached to the 401'd attempt may already have been
        // spent by it. Reusing it would turn a recoverable expiry into a hard failure.
        let submits = 0;
        const fetchImpl = vi.fn(async (url: string) => {
            const path = String(url);
            if (path.endsWith('/challenge')) return res(200, { data: challengeBody(3) });
            if (path === '/f/s') return res(200, { shareToken: 't2', expiresAt: 'x', form: { id: 'f', title: 'T' } });
            if (path.endsWith('/submissions')) {
                submits++;

                return submits === 1
                    ? res(401, { error: { code: 'share_token_expired', message: 'gone' } })
                    : res(201, { data: { id: 'a', status: 'submitted' } });
            }

            return res(200, { data: { form: { id: 'f', bot_challenge: 'proof_of_work' }, version: { id: 'v' } } });
        });
        const client = createApiClient({ token: 't1', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();
        await client.submit(payload);

        expect(urlsOf(fetchImpl as never, '/challenge')).toHaveLength(2);
        expect(client.token()).toBe('t2');
    });

    it('treats a manifest cached before I8b as "no challenge"', async () => {
        // The service worker can serve a SchemaResponse minted before this shipped. Absent must read as
        // off — which is what every form defaults to anyway — not as undefined-is-something.
        const fetchImpl = vi.fn(async (url: string) =>
            String(url).endsWith('/submissions')
                ? res(201, { data: { id: 'a', status: 'submitted' } })
                : res(200, { data: { form: { id: 'f' }, version: { id: 'v' } } }),
        );
        const client = createApiClient({ token: 't', slug: 's', fetch: fetchImpl as unknown as typeof fetch });

        await client.fetchSchema();
        await client.submit(payload);

        expect(urlsOf(fetchImpl as never, '/challenge')).toHaveLength(0);
    });
});
