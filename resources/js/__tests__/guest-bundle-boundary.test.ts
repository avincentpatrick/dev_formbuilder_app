/**
 * M185 (`R-7418e6bd`) — no builder COMPONENT reaches the guest form.
 *
 * The respondent grid (`components/submissions/MatrixGrid.vue`) ships in the guest bundle (`resources/public-runtime`)
 * and on the encode page as well as in the builder's Preview, and the Preview's grid editing is injected into it rather
 * than imported (`components/submissions/grid-authoring.ts`). Nothing else guards that: no bundle size gate exists. So
 * this reads every file the guest bundle can reach — `resources/public-runtime/**` and `resources/js/components/
 * submissions/**` — and refuses an import of a `.vue` file under `components/builder/`.
 *
 * ⚠️ PURE BUILDER MODULES ARE ALLOWED, AND THAT IS MEASURED, NOT ASSUMED: `submissions/note-content.ts` already reads
 * `builder/content-blocks` and `builder/content-markup` (the note's block shape and its link check). Only components
 * pull a builder screen's code — and the design system's editors with it — into the bundle.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';

const ROOT = join(__dirname, '..', '..');
const REACHABLE = [join(ROOT, 'public-runtime'), join(ROOT, 'js', 'components', 'submissions')];

function sources(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) return name === '__tests__' || name === 'node_modules' ? [] : sources(path);

        return /\.(ts|vue)$/.test(name) && !/\.test\.ts$/.test(name) ? [path] : [];
    });
}

const IMPORT = /\bfrom\s+['"]([^'"]+)['"]|\bimport\(\s*['"]([^'"]+)['"]\s*\)/g;

describe('the guest bundle’s boundary', () => {
    it('reads the files the guest bundle reaches — a floor, so a blind walk cannot pass', () => {
        expect(sources(REACHABLE[0]).length).toBeGreaterThan(40);
        expect(sources(REACHABLE[1]).map((path) => relative(REACHABLE[1], path))).toContain('MatrixGrid.vue');
    });

    it('imports no builder component', () => {
        const offences: string[] = [];
        for (const file of REACHABLE.flatMap(sources)) {
            for (const match of readFileSync(file, 'utf8').matchAll(IMPORT)) {
                const target = match[1] ?? match[2] ?? '';
                if (/components\/builder\/|^\.\.\/builder\//.test(target) && target.endsWith('.vue')) {
                    offences.push(`${relative(ROOT, file)} → ${target}`);
                }
            }
        }

        expect(offences).toEqual([]);
    });
});
