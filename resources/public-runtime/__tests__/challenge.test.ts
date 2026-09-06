import { describe, expect, it, vi } from 'vitest';

import {
    encodeSolution,
    hasSubtleCrypto,
    sha256Hex,
    shouldYieldAt,
    solveChallenge,
    YIELD_EVERY,
    type Challenge,
} from '../lib/challenge';

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
        //
        // ⛔ THE `crypto` STUB IS THIS CASE'S SUBJECT, NOT ITS SCENERY (M75). Before it, the case ran on
        // `crypto.subtle`, which happy-dom supplies — so every candidate was an AWAITED native digest, the
        // event loop turned on each one, and this timer fired after candidate ZERO. It never reached the
        // first `yieldToEventLoop()` at n = 4999 at all. MEASURED: with `challenge.ts`'s yield line deleted
        // outright the case still passed 11/11. It asserted nothing about the solver — it was VACUOUS, and
        // the row that filed it as a race had the diagnosis backwards.
        //
        // On the fallback path `sha256Hex()` is synchronous, so the loop between two yields never returns to
        // the event loop. The timer can therefore fire ONLY if the solver actually yielded, and the same
        // mutation turns this red. ⚠️ It is also ~10x cheaper — MEASURED at 78 ms here against 747 ms on the
        // subtle path — and that 747 ms against Vitest's 5000 ms default `testTimeout` is the load artefact
        // M74 filed as a race. The cost was the flake; the vacuity was the defect.
        //
        // ⚠️ HONEST LIMIT: this pins the yield on the fallback path only. On the subtle path the property is
        // real but unobservable by this technique, because the awaited digest yields on every candidate
        // whether or not `yieldToEventLoop()` is ever called.
        const realCrypto = globalThis.crypto;

        try {
            vi.stubGlobal('crypto', {});
            expect(hasSubtleCrypto()).toBe(false);

            let ticked = false;
            setTimeout(() => {
                ticked = true;
            }, 0);

            await solveChallenge(challengeFor(12000, 20000));

            expect(ticked).toBe(true);
        } finally {
            vi.stubGlobal('crypto', realCrypto);
        }
    });
});

describe('the yield cadence', () => {
    /**
     * ⛔ WHY THIS BLOCK EXISTS AT ALL (M77). The case above pins THAT the solver yields; nothing pinned
     * HOW OFTEN. `challenge.ts` yielded at a bare `n % 5000 === 4999` while `config/guest.php` sets
     * `max_number` to 120000 — so a worst-case fallback solve yields 24 times and no test, comment or
     * document stated that number or defended the interval. The interval's VALUE is a live decision;
     * its cadence is not, and this is the half that can be pinned honestly.
     *
     * ⚠️ IT IS PINNED THROUGH AN INJECTED `onYield`, NOT A TIMER, AND THAT IS WHAT MAKES IT SCALE-FREE.
     * The timer technique the case above uses can only ever answer "at least one yield happened", and
     * only on the fallback path. Counting calls answers "exactly this many", on either path.
     *
     * ⛔ THE HONEST LIMIT, STATED BECAUSE IT IS THE SHAPE THAT SILENTLY MAKES GATES VACUOUS. Both tables
     * below derive their expectations FROM `YIELD_EVERY`, so changing the interval from 5000 to 2500
     * moves the expectations with it and everything stays green. That is DELIBERATE — the interval's
     * value is an open decision and pinning it here would be this repository asserting its own
     * undecided question — but it means these cases cannot detect a re-tuning, only a mis-COUNTING.
     * A test whose expectation is derived from the thing it tests can never catch a change to that
     * thing; the scope is therefore the offset within a block and the number of blocks, and a
     * deliberate defect was run against exactly those (see the release notes).
     */
    const countingSolve = async (answer: number, maxnumber: number): Promise<number> => {
        let yields = 0;
        await solveChallenge(challengeFor(answer, maxnumber), async () => {
            yields++;
        });

        return yields;
    };

    it.each([
        [0, false],
        [1, false],
        [YIELD_EVERY - 2, false],
        [YIELD_EVERY - 1, true],
        [YIELD_EVERY, false],
        [2 * YIELD_EVERY - 1, true],
    ])('shouldYieldAt(%i) is %s', (n, expected) => {
        // ⛔ THE PREDICATE'S BOUNDARY, READ DIRECTLY. The plausible wrong form, `n % YIELD_EVERY === 0`,
        // fires on candidate ZERO — before any work — and then one candidate early forever. Both rows
        // of this table that mention 0 exist to kill it.
        expect(shouldYieldAt(n)).toBe(expected);
    });

    it.each([
        [0, 0],
        [YIELD_EVERY - 2, 0],
        [YIELD_EVERY - 1, 0],
        [YIELD_EVERY, 1],
        [2 * YIELD_EVERY - 1, 1],
        [2 * YIELD_EVERY, 2],
    ])('yields floor(answer / YIELD_EVERY) times when the answer is %i', async (answer, expected) => {
        // ⛔ THE FORMULA IS `floor(answer / YIELD_EVERY)`, AND THE OBVIOUS ALTERNATIVE IS FALSE.
        // `floor(candidatesTried / YIELD_EVERY)` looks equivalent and is wrong for every answer at
        // `n ≡ YIELD_EVERY - 1 (mod YIELD_EVERY)` — measured by sweeping all 120,001 answers in the real
        // search space: 24 disagreements, zero for the form asserted here. The reason is one line of
        // `solveChallenge`: the match RETURNS before the yield check is reached, so the final partial
        // block never yields.
        //
        // ⚠️ THE TWO ROWS THAT CARRY THIS ARE `YIELD_EVERY - 1` AND `2 * YIELD_EVERY - 1`. Every other
        // row here passes under the wrong formula too — including, by coincidence, the 12000/20000
        // fixture the case above already uses, which is why the defect could survive a test suite that
        // looked like it covered this.
        const realCrypto = globalThis.crypto;

        try {
            // The fallback path: ~10x cheaper than the subtle path for the same candidate count
            // (measured at 78 ms vs 747 ms for 12k), and the yield count is identical on both.
            vi.stubGlobal('crypto', {});

            expect(await countingSolve(answer, answer + 1)).toBe(expected);
        } finally {
            vi.stubGlobal('crypto', realCrypto);
        }
    });

    it('never yields when the search space is smaller than one interval', async () => {
        // The common case in production: the server picks `n` uniformly in [0, max_number], so most
        // solves are long — but a short one must not pay for a yield it does not need.
        const realCrypto = globalThis.crypto;

        try {
            vi.stubGlobal('crypto', {});
            expect(await countingSolve(10, 100)).toBe(0);
        } finally {
            vi.stubGlobal('crypto', realCrypto);
        }
    });
});

describe('encodeSolution', () => {
    it('round-trips through base64 to the exact object the server parses', async () => {
        const solution = await solveChallenge(challengeFor(3));
        const decoded = JSON.parse(atob(encodeSolution(solution)));

        expect(decoded).toEqual(solution);
    });
});
