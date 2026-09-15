import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

/**
 * M96 — A PAGE THAT PAGINATES ON THE SERVER DECLARES NO `sortable` COLUMN.
 *
 * ── WHAT THIS EXISTS TO CATCH ────────────────────────────────────────────────────────────────────────────
 * `MdsDataTable` sorts only the rows it was handed, client-side. On a page that renders `MdsPagination` those
 * rows are ONE page of a server-ordered set, so a sortable header reorders that page and announces, through
 * `aria-sort`, an order over the whole dataset that does not exist. The submissions inbox and the webhook
 * delivery log both shipped it. The user's decision of 2026-08-18 is to drop the sort on such a table and
 * state its fixed order in one line of prose, following `audit/Index.vue`; server-side sorting was explicitly
 * NOT the chosen path.
 *
 * ── WHY A SOURCE SCAN ─────────────────────────────────────────────────────────────────────────────────────
 * The two mount tests (`submissions/inbox.test.ts`, `webhooks/show.test.ts`) pin the two pages that had the
 * defect. They cannot see the NEXT page to pair a pager with a sortable column — the forms list is the
 * obvious candidate, since its sortable columns are honest only while the server hands it every form. The
 * shape is what makes the defect, so the gate is the shape.
 *
 * ⚠️ ITS REACH, STATED NARROWLY. It reads pages under `resources/js/Pages` that import `MdsPagination` from the
 * design system, and it looks for a literal `sortable:` key. A table paginated by some other pager, a set
 * capped with a server-side limit, or a sortable key assembled outside an object literal (spread in from a
 * shared helper) all pass it. It is a tripwire for the shape that shipped, not a proof that no page lies.
 */

/** Blank a region out while preserving its newlines, so match indices still map to real line numbers. */
function blank(region: string): string {
    return region.replace(/[^\n]/g, ' ');
}

/**
 * The source with every comment masked: HTML comments, block comments, and line comments that open at the
 * start of a line or after whitespace. The last condition keeps a URL's `https://` in code rather than
 * masking the rest of its line — which could hide a real key sitting after it.
 *
 * ⚠️ LOAD-BEARING, NOT TIDINESS. `audit/Index.vue`'s docblock explains in prose why none of its columns are
 * sortable, and a gate that reports the one page documenting the correct decision gets deleted.
 */
function codeOnly(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, blank)
        .replace(/\/\*[\s\S]*?\*\//g, blank)
        .replace(/(^|[ \t])\/\/[^\n]*/gm, (match: string, lead: string) => lead + blank(match.slice(lead.length)));
}

function vueFilesUnder(root: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(root, { withFileTypes: true })) {
        if (entry.name === 'node_modules') continue;

        const path = join(root, entry.name);
        if (entry.isDirectory()) found.push(...vueFilesUnder(path));
        else if (entry.name.endsWith('.vue')) found.push(path);
    }

    return found;
}

const IMPORTS_PAGINATION = /import\s*\{[^}]*\bMdsPagination\b[^}]*\}\s*from\s*['"]@meridian\/design-system['"]/;
const SORTABLE_KEY = /\bsortable\s*:/;

function repoPath(path: string): string {
    return relative(process.cwd(), path).split(sep).join('/');
}

describe('resources/js/Pages — a server-paginated table declares no sortable column', () => {
    const files = vueFilesUnder(join(process.cwd(), 'resources', 'js', 'Pages'));
    const paginated = files
        .map((path) => ({ file: repoPath(path), code: codeOnly(readFileSync(path, 'utf8')) }))
        .filter((page) => IMPORTS_PAGINATION.test(page.code));

    /*
    | ⚠️ THE NON-VACUITY BLOCK. A walk that silently matched nothing reports `passed` and is indistinguishable
    | from a scan that ran. So the walk must find the pages, and the import detector must find the ledger that
    | set the precedent AND both pages that had the defect — by name, because a pooled count would let the
    | two that matter drop out while the rest kept the number up.
    */
    it('actually walked the pages and actually found the paginated ones', () => {
        expect(files.length, 'no .vue files found under Pages — the walk is broken, not the tree (40 today)').toBeGreaterThan(30);

        const names = paginated.map((page) => page.file);
        expect(names, 'the MdsPagination import detector is broken (7 pages today)').toEqual(
            expect.arrayContaining([
                'resources/js/Pages/audit/Index.vue',
                'resources/js/Pages/submissions/Inbox.vue',
                'resources/js/Pages/webhooks/Show.vue',
            ]),
        );
    });

    it('masks comments without masking code, so prose about sorting is not a column', () => {
        const prose = "// { key: 'a', sortable: true }\n/* sortable: true */\n<!-- sortable: true -->\n";
        expect(SORTABLE_KEY.test(codeOnly(prose)), 'a comment was read as code').toBe(false);

        const code = "const columns = [{ key: 'a', href: 'https://example.test/x', sortable: true }];\n";
        expect(SORTABLE_KEY.test(codeOnly(code)), 'code after a URL was masked as a comment').toBe(true);
    });

    it('never pairs MdsPagination with a sortable column', () => {
        const offenders = paginated.flatMap((page) =>
            page.code.split('\n').flatMap((text, index) => (SORTABLE_KEY.test(text) ? [`${page.file} line ${index + 1} — ${text.trim()}`] : [])),
        );

        expect(
            offenders,
            'A page that renders MdsPagination is handed ONE page of a server-ordered set, and MdsDataTable sorts ' +
                'only the rows it was handed — so a sortable column there reorders a page and announces an order ' +
                'the dataset does not have. Drop the key and state the fixed order in one line of prose above the ' +
                'table (user decision 2026-08-18; precedent audit/Index.vue). Do NOT add server-side sorting ' +
                'without re-opening that decision.',
        ).toEqual([]);
    });
});
