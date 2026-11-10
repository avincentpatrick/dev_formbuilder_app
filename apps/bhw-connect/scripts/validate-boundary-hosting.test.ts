import { afterEach, expect, it } from 'vitest';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer, type Server } from 'node:http';
import { validateBoundaryHosting } from './validate-boundary-hosting.mjs';

let dir: string;
let server: Server | undefined;
afterEach(async () => {
  if (server) { server.closeAllConnections(); await new Promise<void>((resolve) => server!.close(() => resolve())); server = undefined; }
  if (dir) await rm(dir, { recursive: true, force: true });
});
const hash = (value: Buffer) => createHash('sha256').update(value).digest('hex');
async function fixture() {
  dir = await mkdtemp(join(tmpdir(), 'bhw-boundary-host-'));
  const shard = Buffer.from('{"type":"FeatureCollection","features":[]}');
  const coverage = Buffer.from('{"reference":[],"unmatched_source":[]}');
  const manifest = { schema_version: 1, release: 'test-v1', publication_ready: false,
    coverage_file: 'coverage.json', coverage_sha256: hash(coverage),
    areas: [{ citymun_code: '0100101', candidate_count: 1, file: 'citymun/0100101.json', bytes: shard.length, sha256: hash(shard) },
      { citymun_code: '0100102', candidate_count: 0, file: null, bytes: 0 }] };
  const raw = Buffer.from(JSON.stringify(manifest));
  const path = join(dir, 'manifest.json'); await writeFile(path, raw);
  const files = new Map([['manifest.json', raw], ['coverage.json', coverage], ['citymun/0100101.json', shard]]);
  const urls: string[] = [];
  const request: typeof fetch = async (url, init) => {
    const name = new URL(String(url)).pathname.split('/test-v1/')[1]; urls.push(name);
    const data = files.get(name);
    if (!data) return new Response(null, { status: 404 });
    const headers = { 'Content-Type': 'application/json', 'Cache-Control': 'public, max-age=31536000, immutable', ETag: `"${hash(data)}"` };
    if (new Headers(init?.headers).get('If-None-Match') === headers.ETag) return new Response(null, { status: 304, headers });
    return new Response(data, { headers });
  };
  return { path, pin: hash(raw), files, urls, request };
}
const base = 'https://example.test/bhw-connect/maps/test-v1/';
it('checks all files, empty areas, conditionals and a missing asset without approving geometry', async () => {
  const f = await fixture();
  const result = await validateBoundaryHosting(base, f.path, f.pin, f.request);
  expect(result).toMatchObject({ files: 3, conditional: 304, missing: 404, publication_ready: false, geometry_acceptance: 'not-tested' });
  expect(f.urls).not.toContain('citymun/0100102.json');
  expect(f.urls).toHaveLength(7);
});
it('exercises the probe over real local HTTP', async () => {
  const f = await fixture();
  server = createServer(async (req, res) => {
    const response = await f.request(`http://localhost${req.url}`, { headers: req.headers as HeadersInit });
    res.writeHead(response.status, Object.fromEntries(response.headers));
    res.end(Buffer.from(await response.arrayBuffer()));
  });
  await new Promise<void>((resolve) => server!.listen(0, '127.0.0.1', resolve));
  const address = server.address();
  if (!address || typeof address === 'string') throw new Error('No port');
  expect(await validateBoundaryHosting(`http://127.0.0.1:${address.port}/bhw-connect/maps/test-v1/`, f.path, f.pin)).toMatchObject({ files: 3 });
});
it.each([
  ['wrong bytes', 'Hosted checksum mismatch', (r: Response) => new Response('wrong', { headers: r.headers })],
  ['oversized body', 'byte limit', (r: Response) => new Response('x'.repeat(4096), { headers: r.headers })],
  ['weak ETag', 'strong ETag', (r: Response) => { r.headers.set('ETag', 'W/"weak"'); return r; }],
  ['private caching', 'cache policy', (r: Response) => { r.headers.set('Cache-Control', 'public, private, max-age=10, immutable'); return r; }],
  ['HTML fallback', 'MIME', (r: Response) => { r.headers.set('Content-Type', 'text/html'); return r; }],
  ['encoded bytes', 'identity', (r: Response) => { r.headers.set('Content-Encoding', 'gzip'); return r; }],
])('rejects %s', async (_name, message, mutate) => {
  const f = await fixture();
  await expect(validateBoundaryHosting(base, f.path, f.pin, async (url, init) => mutate(await f.request(url, init)))).rejects.toThrow(message);
});
it('rejects broken conditionals and a missing-file app fallback', async () => {
  const f = await fixture();
  await expect(validateBoundaryHosting(base, f.path, f.pin, async (url, init) => {
    if (new Headers(init?.headers).has('If-None-Match')) return new Response(null, { status: 200 });
    return f.request(url, init);
  })).rejects.toThrow('conditional 304');
  await expect(validateBoundaryHosting(base, f.path, f.pin, async (url, init) => {
    const r = await f.request(url, init);
    return r.status === 404 ? new Response('app fallback') : r;
  })).rejects.toThrow('Missing asset');
});
it('fails before network for changed pins, unversioned paths and mismatched releases', async () => {
  const f = await fixture();
  await expect(validateBoundaryHosting(base, f.path, '0'.repeat(64), f.request)).rejects.toThrow('pin');
  for (const url of ['https://example.test/maps/test-v1/', base.replace('test-v1', 'latest'), base.replace('test-v1', 'test-v2')])
    await expect(validateBoundaryHosting(url, f.path, f.pin, f.request)).rejects.toThrow();
  expect(f.urls).toHaveLength(0);
});
it('rejects manifest path traversal before any request', async () => {
  const f = await fixture();
  const manifest = JSON.parse(f.files.get('manifest.json')!.toString());
  manifest.areas[0].file = '../secret';
  const raw = Buffer.from(JSON.stringify(manifest)); await writeFile(f.path, raw);
  await expect(validateBoundaryHosting(base, f.path, hash(raw), f.request)).rejects.toThrow('path/size');
  expect(f.urls).toHaveLength(0);
});
