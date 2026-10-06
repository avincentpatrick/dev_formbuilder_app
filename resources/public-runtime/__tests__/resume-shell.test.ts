import { IDBFactory } from 'fake-indexeddb';
import { openDB } from 'idb';
import { describe, expect, it } from 'vitest';
import { CacheExpiration } from 'workbox-expiration';
import { blankResumeToken, purgeTokenKeyedResumeShells, resumeShellCacheKey, resumeTokenFromPath } from '../lib/resume-shell';
import { SHELL_CACHE, SHELL_EXPIRATION } from '../lib/shell-cache';

/*
 * M140 (`R-68656155`, `D20` = 2) — the resume shell cached under one token-free key. `sw.test.ts` asserts the route
 * that uses these; this file asserts the pieces themselves, and the clean-up of what older workers left behind.
 */

// Origins no other case in the suite uses: Workbox's expiry store has a fixed name, so cases filter to their own URLs.
const ORIGIN = 'https://m140-resume.test';
const TOKEN_A = `${ORIGIN}/f/resume/tok-a`;
const TOKEN_B = `${ORIGIN}/f/resume/tok-b`;
const ONE_KEY = `${ORIGIN}/f/resume/`;
const OTHER_SHELL = `${ORIGIN}/f/intake`;

/** A one-cache CacheStorage over $urls that records what is deleted. */
function fakeCaches(urls: string[]): { caches: CacheStorage; deleted: string[] } {
    const deleted: string[] = [];
    const caches = {
        has: (name: string) => Promise.resolve(name === SHELL_CACHE),
        open: () =>
            Promise.resolve({
                keys: () => Promise.resolve(urls.map((url) => ({ url }) as Request)),
                delete: (request: Request) => {
                    deleted.push(request.url);

                    return Promise.resolve(true);
                },
            } as unknown as Cache),
    } as unknown as CacheStorage;

    return { caches, deleted };
}

/** Every URL in this file's origin that Workbox's expiry store holds a stamp for, read back from the store itself. */
async function stampedUrls(): Promise<string[]> {
    const db = await openDB('workbox-expiration', 1);

    if (!db.objectStoreNames.contains('cache-entries')) {
        db.close();

        return [];
    }

    const rows = (await db.getAllFromIndex('cache-entries', 'cacheName', SHELL_CACHE)) as Array<{ url: string }>;
    db.close();

    return rows.map((row) => row.url).filter((url) => url.startsWith(ORIGIN)).sort();
}

describe('the resume shell key and copy', () => {
    it('keys every resume link to the same entry, with no token, query or fragment in it', () => {
        expect(resumeShellCacheKey('https://acme.test/f/resume/tok-a?x=1#y')).toBe('https://acme.test/f/resume/');
        expect(resumeShellCacheKey('https://acme.test/f/resume/tok-b')).toBe('https://acme.test/f/resume/');
    });

    it('blanks the resume token and nothing else', () => {
        expect(blankResumeToken('<div data-share-token="s-1" data-resume-token="tok-secret"></div>')).toBe(
            '<div data-share-token="s-1" data-resume-token=""></div>',
        );
        expect(blankResumeToken('<div data-share-token="s-1"></div>')).toBe('<div data-share-token="s-1"></div>');
    });
});

describe('resumeTokenFromPath', () => {
    it('reads the token of a resume address', () => {
        expect(resumeTokenFromPath('/f/resume/eyJhbGciOi.some.token')).toBe('eyJhbGciOi.some.token');
        expect(resumeTokenFromPath('/f/resume/tok-a/')).toBe('tok-a');
        expect(resumeTokenFromPath('/f/resume/a%2Fb')).toBe('a/b');
    });

    it('reads nothing from any other address, or from a malformed one', () => {
        expect(resumeTokenFromPath('/f/resume/')).toBe('');
        expect(resumeTokenFromPath('/f/resume')).toBe('');
        expect(resumeTokenFromPath('/f/resumed/tok-a')).toBe('');
        expect(resumeTokenFromPath('/f/resume/tok-a/extra')).toBe('');
        expect(resumeTokenFromPath('/f/clinic-intake')).toBe('');
        expect(resumeTokenFromPath('/f/resume/%E0%A4%A')).toBe('');
    });
});

describe('purgeTokenKeyedResumeShells', () => {
    it('deletes the token-keyed resume shells and their expiry stamps, and keeps the one key and every other shell', async () => {
        const expiration = new CacheExpiration(SHELL_CACHE, { ...SHELL_EXPIRATION });
        for (const url of [TOKEN_A, TOKEN_B, ONE_KEY, OTHER_SHELL]) {
            await expiration.updateTimestamp(url);
        }
        // The floor: the store really holds all four before the purge, so the assertion after it is not vacuous.
        expect(await stampedUrls()).toEqual([ONE_KEY, OTHER_SHELL, TOKEN_A, TOKEN_B].sort());

        const { caches, deleted } = fakeCaches([TOKEN_A, ONE_KEY, OTHER_SHELL, TOKEN_B]);
        await purgeTokenKeyedResumeShells(caches, indexedDB);

        expect(deleted).toEqual([TOKEN_A, TOKEN_B]);
        expect(await stampedUrls()).toEqual([ONE_KEY, OTHER_SHELL].sort());
    });

    it("⛔ never creates Workbox's database where none exists", async () => {
        // An empty version-1 `workbox-expiration` made here would stop Workbox's own upgrade from ever creating its
        // store, and expiry would stop for every cache on the device.
        const factory = new IDBFactory();
        await purgeTokenKeyedResumeShells(fakeCaches([]).caches, factory);

        expect((await factory.databases()).map((db) => db.name)).not.toContain('workbox-expiration');

        // The control: this factory does report a database once one is opened, so the absence above is measured.
        await new Promise<void>((resolve) => {
            const request = factory.open('control');
            request.onsuccess = () => {
                request.result.close();
                resolve();
            };
        });
        expect((await factory.databases()).map((db) => db.name)).toContain('control');
    });

    it('settles quietly when Cache Storage and IndexedDB both refuse', async () => {
        const refusing = { has: () => Promise.reject(new Error('refused')) } as unknown as CacheStorage;
        const blocked = {
            open: () => {
                throw new Error('blocked');
            },
        } as unknown as IDBFactory;

        await expect(purgeTokenKeyedResumeShells(refusing, blocked)).resolves.toBeUndefined();
    });
});
