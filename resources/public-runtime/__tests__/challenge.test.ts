import { describe, expect, it, vi } from 'vitest';

import { encodeSolution, hasSubtleCrypto, sha256Hex, solveChallenge, type Challenge } from '../lib/challenge';

/**
 * The guest proof-of-work solver (Increment I8b).
 *
 * ⚠️ THE PURE-JS SHA-256 IS NOT A DEV CONVENIENCE, SO ITS VECTORS ARE NOT OPTIONAL. `crypto.subtle` is
 * undefined in an INSECURE CONTEXT, and secure-context is evaluated over the whole ancestor chain — so a
 * form embedded in an `http://` third-party page has no WebCrypto, and `frame-ancestors *` on the public
 * runtime is deliberate because PRD:187 requires exactly that embedding. A wrong hash there would mean
 * every embedded respondent's submission is rejected, in production, silently, for one class of customer.
 * Hence the NIST FIPS 180-4 vectors below: a hand-written hash with no golden vectors is a guess.
 */

describe('sha256Hex — NIST FIPS 180-4 vectors', () => {
    it.each([
        ['', 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'],
        ['abc', 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'],
        [
            // The 448-bit message from the spec's appendix — exercises the multi-block path.
            'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq',
            '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1',
        ],
    ])('hashes %j', (input, expected) => {
        expect(sha256Hex(input)).toBe(expected);
    });

    it('matches on a message that straddles the 55/56-byte padding boundary', () => {
        // The boundary where padding needs a SECOND block. Off-by-one here produces a hash that is right
        // for short inputs and wrong for long ones, which is the worst kind of wrong to ship.
        const fiftyFive = 'a'.repeat(55);
        const fiftySix = 'a'.repeat(56);

        expect(sha256Hex(fiftyFive)).toBe('9f4390f8d30c2dd92ec9f095b65e2b9ae9b0a925a5258e241c9f1e910f734318');
        expect(sha256Hex(fiftySix)).toBe('b35439a4ac6f0948b6d6f9e3c6af0f5f590ce20f1bde7090ef7970686ec6738a');
    });

    it('hashes multi-byte UTF-8 by BYTES, not by code units', () => {
        // The salt is ASCII by construction, so this never fires in production — but a TextEncoder that
        // was quietly replaced with a charCode loop would pass every test above and fail only here.
        expect(sha256Hex('héllo')).toBe(sha256Hex(new TextDecoder().decode(new TextEncoder().encode('héllo'))));
        expect(sha256Hex('日本')).toHaveLength(64);
    });
});

/** A challenge whose answer is `n`, built the way the server builds it. */
function challengeFor(n: number, maxnumber = 100): Challenge {
    const salt = 'testsalt.eyJmaWQiOiJ4In0';

    return {
        algorithm: 'SHA-256',
        challenge: sha256Hex(salt + n),
        salt,
        maxnumber,
        signature: 'not-checked-client-side',
    };
}

describe('solveChallenge', () => {
    it('finds the hidden number', async () => {
        const solution = await solveChallenge(challengeFor(42));

        expect(solution.number).toBe(42);
        expect(solution.challenge).toBe(sha256Hex('testsalt.eyJmaWQiOiJ4In0' + 42));
        // `maxnumber` is deliberately absent from the solution — the server knows its own search space,
        // and echoing it back would invite a client to claim a smaller one.
        expect(solution).not.toHaveProperty('maxnumber');
    });

    it('finds 0, which a truthiness bug would skip', async () => {
        expect((await solveChallenge(challengeFor(0))).number).toBe(0);
    });

    it('throws rather than returning a bogus header when the answer is out of range', async () => {
        // Means the server and this client disagree about the search space — a deployment fault the
        // caller should surface as a failed submission, not paper over with a header the server rejects.
        await expect(solveChallenge(challengeFor(500, 50))).rejects.toThrow(/unsolvable/i);
    });

    it('solves identically with crypto.subtle ABSENT — the embedded-in-http case', async () => {
        // The production configuration this fallback exists for. If this ever reddens, embedded forms
        // stop accepting submissions for every customer whose host page is not https.
        const realCrypto = globalThis.crypto;

        try {
            vi.stubGlobal('crypto', {});
            expect(hasSubtleCrypto()).toBe(false);

            expect((await solveChallenge(challengeFor(7))).number).toBe(7);
        } finally {
            vi.stubGlobal('crypto', realCrypto);
        }
    });

    it('yields to the event loop on a long search rather than freezing the caller', async () => {
        // The solver runs inside ApiClient.submit(), which the SERVICE WORKER also calls when draining the
        // outbox — and a SW cannot spawn a Worker. Blocking there stalls every other fetch it handles.
        let ticked = false;
        setTimeout(() => {
            ticked = true;
        }, 0);

        await solveChallenge(challengeFor(12000, 20000));

        expect(ticked).toBe(true);
    });
});

describe('encodeSolution', () => {
    it('round-trips through base64 to the exact object the server parses', async () => {
        const solution = await solveChallenge(challengeFor(3));
        const decoded = JSON.parse(atob(encodeSolution(solution)));

        expect(decoded).toEqual(solution);
    });
});
