/**
 * Client-generated identifiers that work in ANY browsing context. `crypto.randomUUID()` is only defined in
 * SECURE contexts (HTTPS or localhost); the guest runtime can be served over plain HTTP on a custom host
 * (e.g. a tenant subdomain in dev/CI), where it is `undefined`. `crypto.getRandomValues()` is NOT
 * secure-context-gated, so we build ids from it, falling back to `Math.random` only if crypto is entirely
 * absent.
 */

/** Fill 16 random bytes for a UUID, secure-context-safe (getRandomValues → Math.random fallback). */
function fillRandomBytes(bytes: Uint8Array<ArrayBuffer>): void {
    const c: Crypto | undefined = globalThis.crypto;
    if (c !== undefined && typeof c.getRandomValues === 'function') {
        c.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
}

/** Format 16 bytes as the canonical 8-4-4-4-12 hyphenated UUID string. */
function formatUuid(bytes: Uint8Array): string {
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
    return [
        hex.slice(0, 4).join(''),
        hex.slice(4, 6).join(''),
        hex.slice(6, 8).join(''),
        hex.slice(8, 10).join(''),
        hex.slice(10, 16).join(''),
    ].join('-');
}

/** A random UUIDv4 — used for ephemeral, client-only identities (repeat-instance row keys) that are never sent. */
export function randomUuid(): string {
    const c: Crypto | undefined = globalThis.crypto;
    if (c !== undefined && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }

    const bytes = new Uint8Array(16);
    fillRandomBytes(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC-4122 variant
    return formatUuid(bytes);
}

/**
 * A time-sortable UUIDv7 (Increment G8b) — the client_submission_uuid / attachment_local_id format for the
 * offline outbox. The leading 48-bit big-endian millisecond timestamp makes queued ids coarsely chronological
 * across many offline devices (useful for oldest-first replay + conflict review, architecture §4.2) while the
 * 74 trailing random bits keep cross-device collisions astronomically unlikely. A valid RFC-4122 UUID, so it
 * passes the server's `uuid` validation + the Postgres `uuid` column (a ULID would not). We never use
 * `crypto.randomUUID()` here — that mints a v4; v7 must be assembled from bytes.
 */
export function uuidv7(): string {
    const bytes = new Uint8Array(16);
    fillRandomBytes(bytes);

    // 48-bit big-endian Unix-epoch milliseconds in bytes 0..5.
    const ms = Date.now();
    bytes[0] = (ms / 0x10000000000) & 0xff;
    bytes[1] = (ms / 0x100000000) & 0xff;
    bytes[2] = (ms / 0x1000000) & 0xff;
    bytes[3] = (ms / 0x10000) & 0xff;
    bytes[4] = (ms / 0x100) & 0xff;
    bytes[5] = ms & 0xff;

    bytes[6] = (bytes[6] & 0x0f) | 0x70; // version 7
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC-4122 variant
    return formatUuid(bytes);
}
