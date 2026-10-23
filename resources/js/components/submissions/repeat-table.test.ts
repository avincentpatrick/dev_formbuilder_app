/**
 * M185 (`R-0a7275ce`, `D129`) — the browser's answer to "can this repeatable section be a table", read from the cases the
 * server's `RepeatTableTest.php` reads (`tests/fixtures/repeat-table.json`).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { drawsAsTable, repeatTableBlocker, repeatTableColumns, type RepeatMember } from './repeat-table';

type Member = RepeatMember & { key: string };

const cases = (
    JSON.parse(readFileSync(join(process.cwd(), 'tests', 'fixtures', 'repeat-table.json'), 'utf-8')) as {
        cases: { name: string; members: Member[]; columns: string[]; blocker: string | null }[];
    }
).cases;

describe('repeat-table', () => {
    it('reads the shared cases — a floor, so an emptied fixture cannot pass', () => {
        expect(cases).toHaveLength(7);
    });

    it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, c) => {
        expect(repeatTableColumns(c.members).map((m) => m.key)).toEqual(c.columns);
        expect(repeatTableBlocker(c.members)?.key ?? null).toBe(c.blocker);
    });

    it('draws a table only when set to one, with a column, and nothing that keeps the cards', () => {
        const fits: Member[] = [{ key: 'name', field_type: 'short_text' }];

        expect(drawsAsTable('table', fits)).toBe(true);
        expect(drawsAsTable(null, fits)).toBe(false);
        expect(drawsAsTable('table', [])).toBe(false);
        expect(drawsAsTable('table', [...fits, { key: 'n', field_type: 'long_text' }])).toBe(false);
        expect(drawsAsTable('table', [{ key: 'n', field_type: 'long_text', visibility: 'hidden' }, ...fits])).toBe(true);
    });
});
