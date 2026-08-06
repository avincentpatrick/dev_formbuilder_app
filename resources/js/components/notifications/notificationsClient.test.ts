import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    fetchNotificationFeed,
    markAllNotificationsRead,
    markNotificationRead,
} from './notificationsClient';

/**
 * The bell's transport, and the one property it must have that no other `builderClient` consumer needs:
 * IT CANNOT THROW. Every other consumer is a click handler with a user watching, so a re-throw surfaces as
 * an error state someone reads. This one is called from a sixty-second interval — a re-throw there is an
 * unhandled promise rejection every minute for as long as the tab is offline, and the browser console
 * fills with them while the bell shows nothing wrong.
 *
 * Mocks the global `fetch` rather than `builderClient` itself, matching `builderClient.test.ts`: mocking a
 * layer down would let a broken client pass this suite.
 */
function jsonResponse(status: number, body: unknown): Response {
    return {
        status,
        ok: status >= 200 && status < 300,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

function fetchMock(): ReturnType<typeof vi.fn> {
    const mock = vi.fn();
    vi.stubGlobal('fetch', mock);

    return mock;
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('fetchNotificationFeed', () => {
    it('reads GET /notifications through builderClient’s JSON contract', async () => {
        const mock = fetchMock();
        mock.mockResolvedValue(jsonResponse(200, { unread_count: 3, items: [] }));

        await expect(fetchNotificationFeed()).resolves.toEqual({ unread_count: 3, items: [] });

        const [url, init] = mock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/notifications');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect((init.headers as Record<string, string>).Accept).toBe('application/json');
    });

    it('returns null on a 403 rather than throwing into the poll', async () => {
        fetchMock().mockResolvedValue(jsonResponse(403, { message: 'This action is unauthorized.' }));

        await expect(fetchNotificationFeed()).resolves.toBeNull();
    });

    it('returns null when fetch itself rejects — an offline tab must not raise every sixty seconds', async () => {
        fetchMock().mockRejectedValue(new TypeError('Failed to fetch'));

        await expect(fetchNotificationFeed()).resolves.toBeNull();
    });

    it('returns null when a redirect hands back HTML instead of JSON', async () => {
        // A tenant that cannot be identified 302s to the central domain; `fetch` follows it, reports ok,
        // and `response.json()` then throws a SyntaxError on an HTML body. That must be indistinguishable
        // from any other "ask again later".
        fetchMock().mockResolvedValue({
            status: 200,
            ok: true,
            json: () => Promise.reject(new SyntaxError('Unexpected token <')),
        } as unknown as Response);

        await expect(fetchNotificationFeed()).resolves.toBeNull();
    });
});

describe('the mark-read writes', () => {
    it('POSTs one row and reports success on a 204', async () => {
        const mock = fetchMock();
        mock.mockResolvedValue(jsonResponse(204, null));

        await expect(markNotificationRead('abc')).resolves.toBe(true);

        const [url, init] = mock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/notifications/abc/read');
        expect(init.method).toBe('POST');
    });

    it('POSTs the bulk route', async () => {
        const mock = fetchMock();
        mock.mockResolvedValue(jsonResponse(204, null));

        await expect(markAllNotificationsRead()).resolves.toBe(true);
        expect((mock.mock.calls[0] as [string, RequestInit])[0]).toBe('/notifications/read-all');
    });

    it('reports failure rather than throwing when the write is refused', async () => {
        // The caller has already updated optimistically; a rejected write must not take the popover down
        // with it. The next poll reconciles.
        fetchMock().mockResolvedValue(jsonResponse(403, { message: 'This action is unauthorized.' }));

        await expect(markNotificationRead('abc')).resolves.toBe(false);
    });
});
