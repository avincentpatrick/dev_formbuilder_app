/**
 * A short, human-shareable reference derived deterministically from a submission's UUIDv7 (UX §9) — no new
 * stored column; the id is already time-sortable and a Crockford-base32 slice of it is enough for a guest to
 * quote in a support request. Format: `MER-XXXXXX` (6 chars, no I/L/O/U to avoid confusion).
 */

// Crockford base32, minus the ambiguous letters.
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function deriveReference(uuid: string): string {
    const hex = uuid.replace(/-/g, '');
    // A 40-bit slice past the pure-timestamp prefix (still fully deterministic; well within a safe integer).
    let n = Number.parseInt(hex.slice(12, 22), 16);
    if (!Number.isFinite(n)) {
        n = 0;
    }

    let out = '';
    for (let i = 0; i < 6; i += 1) {
        out = ALPHABET[n % 32] + out;
        n = Math.floor(n / 32);
    }

    return `MER-${out}`;
}
