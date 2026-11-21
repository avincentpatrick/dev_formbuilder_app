/**
 * M245 (`R-d0bbb7bb`) — the glossary on the client. `glossaryKey()` is `App\Support\Forms\Glossary::key()`'s twin;
 * both read `tests/fixtures/glossary-term-keys.json`, so a change to either half that the fixture does not carry
 * reddens a suite.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { findEntry, glossaryEntries, glossaryKey } from './glossary';

const FIXTURE = join(process.cwd(), 'tests', 'fixtures', 'glossary-term-keys.json');
const { vectors } = JSON.parse(readFileSync(FIXTURE, 'utf8')) as { vectors: [string, string][] };

describe('glossaryKey', () => {
    it('reads a fixture that is not empty, so the vectors below can never pass by being absent', () => {
        expect(vectors).toHaveLength(13);
    });

    it.each(vectors)('keys %j as %j, as the server does', (input, key) => {
        expect(glossaryKey(input)).toBe(key);
    });
});

describe('glossaryEntries', () => {
    it('reads entries defensively, keeping the order they arrived in', () => {
        expect(glossaryEntries(undefined)).toEqual([]);
        expect(glossaryEntries('x')).toEqual([]);
        expect(
            glossaryEntries([{ term: 'b', definition: '2' }, { term: 1 }, null, 'junk', { term: 'a', definition: '1', extra: true }]),
        ).toEqual([
            { term: 'b', definition: '2' },
            { term: 'a', definition: '1' },
        ]);
    });
});

describe('findEntry', () => {
    const entries = [
        { term: 'TWG', definition: 'The technical working group.' },
        { term: 'Barangay Health Worker', definition: 'A community volunteer.' },
    ];

    it('finds a term ignoring case and spacing, and nothing for an inflected form', () => {
        expect(findEntry(entries, 'twg')?.definition).toBe('The technical working group.');
        expect(findEntry(entries, 'barangay  health worker')?.term).toBe('Barangay Health Worker');
        expect(findEntry(entries, 'TWGs')).toBeNull();
        expect(findEntry([], 'TWG')).toBeNull();
    });
});
