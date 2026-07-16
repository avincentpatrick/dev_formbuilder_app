import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { openDb, type MeridianDb } from '../lib/db';
import { enqueue, type EnqueueInput } from '../lib/outbox';
import { createSyncOutbox, type SyncOutbox } from '../composables/useSyncOutbox';

let n = 0;
let db: MeridianDb;
const drivers: SyncOutbox[] = [];

function res(status: number, body: unknown): Response {
    const r = { ok: status >= 200 && status < 300, status, headers: new Headers(), json: async () => body, clone: () => r };
    return r as unknown as Response;
}

function okFetch(): typeof fetch {
    const fetchFn = vi.fn(async (url: string) => {
        if (url.startsWith('/f/')) {
            return res(200, { shareToken: 'tok', expiresAt: '', form: { id: 'f', title: 'T' } });
        }
        return res(201, { data: { id: 'srv-1', status: 'submitted' } });
    });
    return fetchFn as unknown as typeof fetch;
}

function fakeEnv({ online = true, usage = 0, quota = 0 }: { online?: boolean; usage?: number; quota?: number } = {}) {
    const listeners: Record<string, Array<() => void>> = {};
    const add = (t: string, h: () => void) => void (listeners[t] ??= []).push(h);
    const remove = (t: string, h: () => void) => void (listeners[t] = (listeners[t] ?? []).filter((x) => x !== h));
    const nav = { onLine: online, storage: { estimate: async () => ({ usage, quota }) } } as unknown as Navigator;
    const win = { addEventListener: add, removeEventListener: remove } as unknown as Window;
    const doc = { visibilityState: 'visible', addEventListener: add, removeEventListener: remove } as unknown as Document;
    return { nav, win, doc, fire: (t: string) => (listeners[t] ?? []).forEach((h) => h()) };
}

function input(uuid: string): EnqueueInput {
    return {
        client_submission_uuid: uuid,
        slug: 's',
        form_version_id: 'v1',
        checksum: 'c1',
        answers: { a: '1' },
        locale: 'en',
        device_id: 'dev',
        app_version: 'test',
    };
}

function makeDriver(env: ReturnType<typeof fakeEnv>, fetchFn: typeof fetch): SyncOutbox {
    const driver = createSyncOutbox(db, { fetch: fetchFn, navigator: env.nav, window: env.win, document: env.doc });
    drivers.push(driver);
    return driver;
}

beforeEach(() => {
    db = openDb(`sync-test-${(n += 1)}`);
});

afterEach(() => {
    drivers.splice(0).forEach((d) => d.dispose());
});

describe('createSyncOutbox', () => {
    it('refresh() reflects the live pending count', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.refresh();
        expect(driver.pending.value).toBe(1);
    });

    it('syncNow() drains the queue', async () => {
        await enqueue(db, input('u1'));
        const driver = makeDriver(fakeEnv({ online: false }), okFetch());
        await driver.syncNow();
        expect(driver.pending.value).toBe(0);
        expect(await db.outbox.get('u1')).toBeUndefined();
    });

    it('replays automatically on the online event', async () => {
        await enqueue(db, input('u1'));
        const env = fakeEnv({ online: false });
        const driver = makeDriver(env, okFetch());
        await driver.refresh();
        expect(driver.pending.value).toBe(1);
        env.fire('online');
        await vi.waitFor(() => expect(driver.pending.value).toBe(0));
    });

    it('surfaces a storage-quota warning past 80% usage', async () => {
        const driver = makeDriver(fakeEnv({ online: false, usage: 90, quota: 100 }), okFetch());
        await driver.refresh();
        expect(driver.quotaWarning.value).toContain('90%');
    });

    it('does not warn on healthy storage', async () => {
        const driver = makeDriver(fakeEnv({ online: false, usage: 10, quota: 100 }), okFetch());
        await driver.refresh();
        expect(driver.quotaWarning.value).toBeNull();
    });
});
