import { afterEach, describe, expect, it, vi } from 'vitest';
import { checkScanFiles, uploadScan, type ScanLimits } from './scan-upload';

/**
 * M129 — the scans page's checks before a long upload, and the upload itself. The checks are a courtesy that
 * mirrors the server's; the server re-checks every one, so each case here pairs a refusal with an acceptance.
 */

const LIMITS: ScanLimits = {
    max_pages: 5,
    accepted_types: ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    max_bytes_per_file: 7_000_000,
    max_bytes_per_scan: 25_000_000,
};

function file(name: string, type: string, size = 1000): File {
    const blob = new File([new Uint8Array(1)], name, { type });
    Object.defineProperty(blob, 'size', { value: size });
    return blob;
}

describe('checkScanFiles', () => {
    it('accepts up to the page limit of photos, or one PDF', () => {
        expect(checkScanFiles([file('1.png', 'image/png'), file('2.jpg', 'image/jpeg')], LIMITS)).toBeNull();
        expect(checkScanFiles([file('scan.pdf', 'application/pdf')], LIMITS)).toBeNull();
    });

    it('refuses nothing chosen, too many pages, and a PDF among photos', () => {
        expect(checkScanFiles([], LIMITS)).toBe('Choose the photos or the PDF of one filled-in form.');
        expect(checkScanFiles(Array.from({ length: 6 }, (_, i) => file(`${i}.png`, 'image/png')), LIMITS)).toBe(
            'A scan can have at most 5 pages.',
        );
        expect(checkScanFiles([file('scan.pdf', 'application/pdf'), file('1.png', 'image/png')], LIMITS)).toContain(
            'Upload a PDF scan on its own.',
        );
    });

    it('refuses a type the reader cannot open, a page that is too large, and a scan that is too large', () => {
        expect(checkScanFiles([file('photo.heic', 'image/heic')], LIMITS)).toBe('“photo.heic” is not a JPEG, PNG, WebP or PDF file.');
        expect(checkScanFiles([file('big.png', 'image/png', 8_000_000)], LIMITS)).toBe('“big.png” is larger than 7 MB.');
        expect(
            checkScanFiles(Array.from({ length: 4 }, (_, i) => file(`${i}.png`, 'image/png', 6_500_000)), LIMITS),
        ).toBe('The pages of one scan can add up to at most 25 MB.');
    });
});

describe('uploadScan', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts every page as `pages[]` with the XSRF header, and resolves the scan id', async () => {
        document.cookie = 'XSRF-TOKEN=token%3D1';
        const fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify({ data: { id: 'scan-1' } }), { status: 202 })));
        vi.stubGlobal('fetch', fetchMock);

        await expect(uploadScan('/forms/f/ocr/scans', [file('1.png', 'image/png'), file('2.png', 'image/png')])).resolves.toEqual({ id: 'scan-1' });

        const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        const body = init.body as FormData;
        expect(url).toBe('/forms/f/ocr/scans');
        expect(init.method).toBe('POST');
        expect(body.getAll('pages[]')).toHaveLength(2);
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('token=1');
    });

    it('rejects with the server\'s own sentence, from either refusal envelope', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ error: { code: 'ocr_scanning_off', message: 'This form does not accept scans.' } }), { status: 422 }))));
        await expect(uploadScan('/x', [file('1.png', 'image/png')])).rejects.toThrow('This form does not accept scans.');

        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(new Response(JSON.stringify({ message: 'Invalid.', errors: { 'pages.0': ['The page must be an image or a PDF.'] } }), { status: 422 }))));
        await expect(uploadScan('/x', [file('1.png', 'image/png')])).rejects.toThrow('The page must be an image or a PDF.');
    });

    it('says the connection failed when the request never arrived', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.reject(new TypeError('Failed to fetch'))));

        await expect(uploadScan('/x', [file('1.png', 'image/png')])).rejects.toThrow('The upload failed. Check your connection and try again.');
    });
});
