import { describe, expect, it } from 'vitest';
import { deriveReference } from '../lib/reference-number';

describe('deriveReference', () => {
    it('is deterministic and formatted MER-XXXXXX', () => {
        const uuid = '0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f';
        const ref = deriveReference(uuid);
        expect(ref).toMatch(/^MER-[0-9A-HJKMNP-TV-Z]{6}$/); // Crockford base32, no I/L/O/U
        expect(deriveReference(uuid)).toBe(ref); // stable
    });

    it('differs for different ids', () => {
        expect(deriveReference('0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f')).not.toBe(
            deriveReference('0192f1a2-b3c4-7d5e-8f90-ffffffffffff'),
        );
    });

    it('never throws on a malformed id', () => {
        expect(() => deriveReference('not-a-uuid')).not.toThrow();
        expect(deriveReference('')).toMatch(/^MER-/);
    });
});
