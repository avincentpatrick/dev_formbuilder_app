import { afterEach, describe, expect, it, vi } from 'vitest';
import { randomUuid, uuidv7 } from '../lib/uuid';

const UUID_RE = /^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/;

describe('uuidv7', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('is a valid RFC-4122 UUID with version 7 + the correct variant', () => {
        const id = uuidv7();
        expect(id).toMatch(UUID_RE);
        expect(id[14]).toBe('7'); // version nibble
        expect(['8', '9', 'a', 'b']).toContain(id[19]); // variant nibble
    });

    it('encodes the current time in the leading 48 bits (coarsely chronological)', () => {
        const before = Date.now();
        const id = uuidv7();
        const after = Date.now();
        const ms = parseInt(id.slice(0, 8) + id.slice(9, 13), 16);
        expect(ms).toBeGreaterThanOrEqual(before);
        expect(ms).toBeLessThanOrEqual(after);
    });

    it('mints distinct ids with a non-decreasing timestamp prefix', () => {
        const a = uuidv7();
        const b = uuidv7();
        expect(a).not.toBe(b);
        const ts = (id: string) => parseInt(id.slice(0, 8) + id.slice(9, 13), 16);
        expect(ts(b)).toBeGreaterThanOrEqual(ts(a)); // equal within a ms; increasing across ms
    });

    it('still produces a valid UUID when crypto is entirely absent (Math.random fallback)', () => {
        vi.stubGlobal('crypto', undefined);
        const id = uuidv7();
        expect(id).toMatch(UUID_RE);
        expect(id[14]).toBe('7');
    });
});

describe('randomUuid', () => {
    it('is a valid v4 UUID (still used for ephemeral, never-sent instance ids)', () => {
        const id = randomUuid();
        expect(id).toMatch(UUID_RE);
        expect(id[14]).toBe('4');
    });
});
