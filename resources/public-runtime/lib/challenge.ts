/**
 * The guest proof-of-work solver (Increment I8b) — the client half of PRD Feature #3's bot-challenge
 * criterion. The server publishes `challenge = sha256(salt + n)` and withholds `n`; this finds it.
 *
 * ── ⚠️ NO NPM DEPENDENCY, AND THAT IS A CONSTRAINT RATHER THAN A FLOURISH ─────────────────────────────
 * Production dependencies pass a merge-blocking `npm audit --omit=dev --audit-level=high` gate, and
 * ADR-0015 already rejected a ~1MB library for a job this bundle could do itself. A SHA-256 is ~90 lines.
 * The Altcha widget, and every hosted CAPTCHA, would also need `script-src` added to
 * PublicRuntimeSecurityHeaders — whose test asserts the ABSENCE of that directive as a deliberate
 * tripwire. If that test ever needs editing to ship this feature, the feature was designed wrong.
 *
 * ── ⚠️ `crypto.subtle` IS UNDEFINED IN AN INSECURE CONTEXT, IN PRODUCTION, NOT ONLY IN DEV ────────────
 * `PublicRuntimeSecurityHeaders` sets `frame-ancestors *` on purpose, because PRD:187 requires third-party
 * iframe embedding. Secure-context is evaluated over the WHOLE ancestor chain — so a form embedded in an
 * `http://` page has no WebCrypto at all, no matter that our own origin is https. That is a real customer
 * configuration, not an edge case, and it is why the pure-JS fallback below is not optional. It is also
 * the only reason this is testable in E2E, where the origin is plain http.
 *
 * ── ⚠️ NO WEB WORKER ─────────────────────────────────────────────────────────────────────────────────
 * The solver runs inside `ApiClient.submit()`, which the SERVICE WORKER also calls when it drains the
 * offline outbox — and a service worker cannot spawn a Worker. Two solvers would be strictly worse than
 * one, so this yields cooperatively instead (see `solveChallenge`).
 *
 * ⛔ AND THE PARAGRAPH ABOVE USED TO SAY THE YIELD KEEPS "BOTH THE TAB AND THE SW RESPONSIVE", WHICH IS
 * BACKWARDS — CORRECTED IN M77, MEASURED RATHER THAN REASONED. A service worker only ever runs in a
 * SECURE context, so it always takes the `crypto.subtle` branch, and an awaited native digest already
 * turns the event loop on every candidate: measured in this project's node container, a `setTimeout(…, 0)`
 * fires during a run of 200 awaited `crypto.subtle.digest()` calls and does NOT fire during 200 awaited
 * already-resolved promises. So in the service worker `yieldToEventLoop()` changes nothing; the loop was
 * interruptible without it.
 *
 * The context the cooperative yield actually serves is the INSECURE EMBED — an `http://` host page, where
 * `crypto.subtle` is undefined, the solver falls back to the synchronous `sha256Hex()` above, and awaiting
 * it is a microtask that never lets a timer run. That context has no service worker either, for the same
 * secure-context reason. One paragraph, two claims, both inverted: the yield is a TAB protection on the
 * FALLBACK path, and the SW sentence is why nobody looked there.
 */

/** The wire shape the server issues. Field names follow Altcha's; we claim no compatibility with it. */
export interface Challenge {
    algorithm: string;
    challenge: string;
    salt: string;
    maxnumber: number;
    signature: string;
}

/** What the server wants back, base64-encoded into the `X-Meridian-Challenge` header. */
export interface ChallengeSolution extends Omit<Challenge, 'maxnumber'> {
    number: number;
}

/* ── SHA-256 (FIPS 180-4), used when crypto.subtle is unavailable ────────────────────────────────── */

const K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

function rotr(x: number, n: number): number {
    return (x >>> n) | (x << (32 - n));
}

/** SHA-256 of a UTF-8 string, as lowercase hex. Pinned against the NIST vectors in the test suite. */
export function sha256Hex(message: string): string {
    const bytes = new TextEncoder().encode(message);
    const bitLength = bytes.length * 8;

    // Pad: 0x80, then zeros, then the 64-bit big-endian length.
    const padded = new Uint8Array((((bytes.length + 8) >> 6) + 1) * 64);
    padded.set(bytes);
    padded[bytes.length] = 0x80;
    new DataView(padded.buffer).setUint32(padded.length - 4, bitLength >>> 0, false);
    new DataView(padded.buffer).setUint32(padded.length - 8, Math.floor(bitLength / 0x100000000), false);

    const h = new Uint32Array([
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ]);
    const w = new Uint32Array(64);
    const view = new DataView(padded.buffer);

    for (let offset = 0; offset < padded.length; offset += 64) {
        for (let i = 0; i < 16; i++) w[i] = view.getUint32(offset + i * 4, false);
        for (let i = 16; i < 64; i++) {
            const s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
            const s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
            w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
        }

        let [a, b, c, d, e, f, g, hh] = h;

        for (let i = 0; i < 64; i++) {
            const S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
            const ch = (e & f) ^ (~e & g);
            const t1 = (hh + S1 + ch + K[i] + w[i]) >>> 0;
            const S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
            const maj = (a & b) ^ (a & c) ^ (b & c);
            const t2 = (S0 + maj) >>> 0;

            hh = g;
            g = f;
            f = e;
            e = (d + t1) >>> 0;
            d = c;
            c = b;
            b = a;
            a = (t1 + t2) >>> 0;
        }

        h[0] = (h[0] + a) >>> 0;
        h[1] = (h[1] + b) >>> 0;
        h[2] = (h[2] + c) >>> 0;
        h[3] = (h[3] + d) >>> 0;
        h[4] = (h[4] + e) >>> 0;
        h[5] = (h[5] + f) >>> 0;
        h[6] = (h[6] + g) >>> 0;
        h[7] = (h[7] + hh) >>> 0;
    }

    return Array.from(h, (x) => x.toString(16).padStart(8, '0')).join('');
}

/** Whether WebCrypto's digest is usable here. False inside an http:// embed — see the module docblock. */
export function hasSubtleCrypto(): boolean {
    return typeof globalThis.crypto?.subtle?.digest === 'function';
}

async function subtleSha256Hex(message: string): Promise<string> {
    const digest = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(message));

    return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, '0')).join('');
}

/** Yield to the event loop so a long fallback solve does not freeze the embedding tab's paint. */
function yieldToEventLoop(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

/**
 * How many candidates the solver tests between cooperative yields.
 *
 * ⚠️ THE VALUE IS NOT DERIVED FROM ANYTHING, AND SAYING SO IS THE POINT (M77). It has been 5000 since
 * I8b with no stated basis, while `config/guest.php`'s `max_number` is 120000 — so a worst-case
 * production solve on the fallback path yields 24 times, and nothing anywhere records why 5000 rather
 * than 500 or 50000. Exported so the cadence can be ASSERTED rather than re-spelled by a test; whether
 * the number itself is right is a live question and is filed as a decision, not settled here.
 */
export const YIELD_EVERY = 5000;

/**
 * Whether the solver yields after testing candidate `n`.
 *
 * ⛔ THE `- 1` IS THE WHOLE FUNCTION AND IT IS EASY TO GET WRONG IN THE OTHER DIRECTION. Yielding at
 * `n % YIELD_EVERY === 0` would fire on candidate ZERO — before any work has been done — and then one
 * candidate EARLY forever after. Extracted as a named predicate so the boundary is pinned by a test
 * that reads it directly, instead of living only inside a loop condition nothing can reach.
 */
export function shouldYieldAt(n: number): boolean {
    return n % YIELD_EVERY === 0;
}

/**
 * Find the `n` the server hid, or throw if it is not in `[0, maxnumber]`.
 *
 * Throwing on exhaustion rather than returning null is deliberate: an unsolvable challenge means the
 * server and this client disagree about the search space, which is a deployment fault the caller should
 * surface as a failed submission rather than paper over with a header the server will reject anyway.
 */
export async function solveChallenge(
    challenge: Challenge,
    onYield: () => Promise<void> = yieldToEventLoop,
): Promise<ChallengeSolution> {
    const digest = hasSubtleCrypto()
        ? subtleSha256Hex
        : async (message: string): Promise<string> => sha256Hex(message);

    for (let n = 0; n <= challenge.maxnumber; n++) {
        if ((await digest(challenge.salt + n)) === challenge.challenge) {
            return {
                algorithm: challenge.algorithm,
                challenge: challenge.challenge,
                salt: challenge.salt,
                number: n,
                signature: challenge.signature,
            };
        }

        // Frequent enough that a slow fallback solve stays interruptible, rare enough that the yields
        // themselves do not dominate the runtime. `onYield` is a seam for the cadence test and has no
        // production caller — the default is the real thing, so no call site changes.
        if (shouldYieldAt(n)) await onYield();
    }

    throw new Error(`Challenge unsolvable within ${challenge.maxnumber}`);
}

/** base64 of the JSON solution — the `X-Meridian-Challenge` header value. */
export function encodeSolution(solution: ChallengeSolution): string {
    const json = JSON.stringify(solution);

    // btoa is byte-oriented; the payload is hex + base64url + an int, so it is ASCII by construction.
    return btoa(json);
}
