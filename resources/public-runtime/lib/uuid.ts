/**
 * A UUIDv4 that works in ANY browsing context. `crypto.randomUUID()` is only defined in SECURE contexts
 * (HTTPS or localhost); the guest runtime can be served over plain HTTP on a custom host (e.g. a tenant
 * subdomain in dev/CI), where it is `undefined`. `crypto.getRandomValues()` is NOT secure-context-gated, so
 * we build the id from it, falling back to `Math.random` only if crypto is entirely absent.
 */
export function randomUuid(): string {
    const c: Crypto | undefined = globalThis.crypto;
    if (c !== undefined && typeof c.randomUUID === 'function') {
        return c.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (c !== undefined && typeof c.getRandomValues === 'function') {
        c.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40; // version 4
    bytes[8] = (bytes[8] & 0x3f) | 0x80; // RFC-4122 variant

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
    return [
        hex.slice(0, 4).join(''),
        hex.slice(4, 6).join(''),
        hex.slice(6, 8).join(''),
        hex.slice(8, 10).join(''),
        hex.slice(10, 16).join(''),
    ].join('-');
}
