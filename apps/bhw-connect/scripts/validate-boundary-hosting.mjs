/** Read-only delivery verification for a pinned municipality package.
 * This does not approve candidate geometry, historical joins or publication.
 */
import { readFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

function cachePolicy(value) {
  const directives = (value ?? '').toLowerCase().split(',').map((s) => s.trim());
  return directives.includes('public') && directives.includes('immutable') &&
    directives.some((s) => /^max-age=[1-9][0-9]*$/.test(s)) &&
    !directives.some((s) => /^(private|no-store|no-cache)(=|$)/.test(s));
}

async function get(url, headers, request) {
  return request(url, { headers, redirect: 'error', signal: AbortSignal.timeout(20000) });
}

async function checkFile(url, hash, limit, request) {
  const response = await get(url, { 'Accept-Encoding': 'identity' }, request);
  try {
    if (response.status !== 200) throw new Error('Expected HTTP 200');
    if (!['application/json', 'application/geo+json'].includes(response.headers.get('content-type')?.split(';')[0].trim()))
      throw new Error('Unexpected JSON MIME type');
    const encoding = response.headers.get('content-encoding');
    if (encoding && encoding !== 'identity') throw new Error('Expected identity encoding for byte comparison');
    if (!cachePolicy(response.headers.get('cache-control'))) throw new Error('Expected public immutable cache policy');
    const etag = response.headers.get('etag');
    if (!etag || !/^"[^"\r\n]+"$/.test(etag)) throw new Error('Expected strong ETag');
    if (!response.body) throw new Error('Missing response body');
    const reader = response.body.getReader();
    const digest = createHash('sha256');
    let bytes = 0;
    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        bytes += value.length;
        if (bytes > limit) throw new Error('Response exceeds expected byte limit');
        digest.update(value);
      }
    } finally {
      await reader.cancel();
      reader.releaseLock();
    }
    if (digest.digest('hex') !== hash) throw new Error('Hosted checksum mismatch');
    const conditional = await get(url, { 'If-None-Match': etag, 'Accept-Encoding': 'identity' }, request);
    await conditional.body?.cancel();
    if (conditional.status !== 304 || conditional.headers.get('etag') !== etag)
      throw new Error('Expected conditional 304 with unchanged ETag');
    return bytes;
  } finally {
    if (!response.body?.locked) await response.body?.cancel();
  }
}

export async function validateBoundaryHosting(baseUrl, manifestPath, manifestHash, request = fetch) {
  const base = new URL(baseUrl);
  if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash ||
      !/^\/bhw-connect\/(?:[a-zA-Z0-9._-]+\/)+$/.test(base.pathname) ||
      base.pathname.split('/').some((part) => part.toLowerCase() === 'latest'))
    throw new Error('Supply a versioned /bhw-connect/ directory URL ending in /');
  const raw = await readFile(manifestPath);
  if (!/^[a-f0-9]{64}$/.test(manifestHash) || sha256(raw) !== manifestHash) throw new Error('Manifest pin mismatch');
  const manifest = JSON.parse(raw);
  if (manifest.schema_version !== 1 || !/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,100}$/.test(manifest.release) ||
      base.pathname.split('/').at(-2) !== manifest.release || manifest.coverage_file !== 'coverage.json' ||
      !Array.isArray(manifest.areas)) throw new Error('Invalid manifest or release URL');
  const entries = [{ file: 'manifest.json', hash: manifestHash, limit: raw.length },
    { file: 'coverage.json', hash: manifest.coverage_sha256, limit: 32 * 1024 * 1024 }];
  const cities = new Set();
  for (const area of manifest.areas) {
    if (!/^[0-9]{7}$/.test(area.citymun_code) || cities.has(area.citymun_code)) throw new Error('Invalid/duplicate municipality');
    cities.add(area.citymun_code);
    if (area.file === null) {
      if (area.candidate_count !== 0 || area.bytes !== 0) throw new Error('Invalid empty area');
      continue;
    }
    if (area.file !== `citymun/${area.citymun_code}.json` || !Number.isSafeInteger(area.bytes) ||
        area.bytes <= 0 || area.bytes > 32 * 1024 * 1024) throw new Error('Invalid shard path/size');
    entries.push({ file: area.file, hash: area.sha256, limit: area.bytes });
  }
  if (entries.some((entry) => !/^[a-f0-9]{64}$/.test(entry.hash))) throw new Error('Invalid artifact checksum');
  let bytes = 0;
  // Sequential and bounded: never request every municipality concurrently.
  for (const entry of entries) {
    try { bytes += await checkFile(new URL(entry.file, base), entry.hash, entry.limit, request); }
    catch (error) { throw new Error(`${entry.file}: ${error.message}`, { cause: error }); }
  }
  const missing = await get(new URL(`missing-${manifestHash}.json`, base), { 'Accept-Encoding': 'identity' }, request);
  await missing.body?.cancel();
  if (missing.status !== 404) throw new Error('Missing asset must return 404, not an app fallback');
  return { release: manifest.release, files: entries.length, bytes, checksums: 'matched', conditional: 304,
    missing: 404, publication_ready: false, geometry_acceptance: 'not-tested', rendering: 'not-tested',
    compression: 'not-tested', performance: 'not-tested' };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [url, manifest, hash] = process.argv.slice(2);
  if (!url || !manifest || !hash) throw new Error('Supply versioned directory URL, local manifest and recorded SHA-256');
  console.log(JSON.stringify(await validateBoundaryHosting(url, manifest, hash), null, 2));
}
