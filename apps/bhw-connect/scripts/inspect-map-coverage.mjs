/** Read-only public map coverage audit. Node 24; no credentials or observations are printed.
 * node scripts/inspect-map-coverage.mjs 'https://HOST/bhw-connect/api/dataset-map?dataset=SLUG&indicator=ID&period=PERIOD&level=national&code=PH'
 */
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { geometryPositions } from '../lib/geo/positions.ts';

async function readJson(url, limit, request) {
  const response = await request(url, { redirect: 'error', signal: AbortSignal.timeout(20000) });
  try {
    if (!['application/json', 'application/geo+json'].includes(response.headers.get('content-type')?.split(';')[0].trim()))
      throw new Error('Expected JSON, not an application/login fallback');
    if (!response.body) throw new Error('Missing response body');
    const reader = response.body.getReader();
    const chunks = [];
    let length = 0;
    try {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        length += value.length;
        if (length > limit) throw new Error('Response exceeds audit size limit');
        chunks.push(Buffer.from(value));
      }
    } finally { await reader.cancel(); reader.releaseLock(); }
    const raw = Buffer.concat(chunks);
    return { status: response.status, value: JSON.parse(raw.toString()),
      sha256: createHash('sha256').update(raw).digest('hex'), bytes: length };
  } finally { if (!response.body?.locked) await response.body?.cancel(); }
}

export async function inspectMapCoverage(input, request = fetch) {
  const url = new URL(input);
  if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password || url.hash ||
      url.pathname !== '/bhw-connect/api/dataset-map') throw new Error('Expected a public prefixed map API URL without credentials');
  const allowed = ['dataset', 'indicator', 'period', 'level', 'code'];
  if ([...url.searchParams.keys()].some(key => !allowed.includes(key)) ||
      allowed.some(key => url.searchParams.getAll(key).length !== 1 || !url.searchParams.get(key)))
    throw new Error('Supply exactly dataset, indicator, period, level and code');
  const choice = Object.fromEntries(url.searchParams);
  const levels = { national: ['PH', 'region', '/geo/regions.json'],
    region: [/^[0-9]{2}$/, 'province', `/geo/provinces/${choice.code}.json`],
    province: [/^[0-9]{5}$/, 'citymun', `/geo/citymun/${choice.code}.json`] };
  const scope = levels[choice.level];
  if (!scope || !(typeof scope[0] === 'string' ? choice.code === scope[0] : scope[0].test(choice.code)))
    throw new Error('Unsupported audit geography');
  const data = await readJson(url, 2 * 1024 * 1024, request);
  const report = { measuredAt: new Date().toISOString(), origin: url.origin, choice,
    httpStatus: data.status, state: data.value.state, responseSha256: data.sha256,
    observationValuesIncluded: false, historicalCompatibility: 'not-tested', productionReadiness: 'not-certified' };
  if (['unavailable', 'failed', 'unsupported'].includes(data.value.state)) {
    const expected = { unavailable: 200, failed: 503, unsupported: 400 }[data.value.state];
    if (data.status !== expected) throw new Error('Inconsistent API status/state');
    return { ...report, coverage: null, geometry: null, reason: data.value.state === 'unsupported'
      ? 'Choice is not supported by this deployed API; not evidence of absent source rows.'
      : 'Public API could not supply this dataset; no inference about underlying database contents.' };
  }
  if (data.status !== 200 || data.value.state !== 'available') throw new Error('Unexpected API state');
  const payload = data.value;
  if (!Array.isArray(payload.rows) || payload.rows.length >= 1000 || payload.childLevel !== scope[1] ||
      typeof payload.source !== 'string' || !payload.source ||
      ![undefined, 'supported', 'navigation-only'].includes(payload.valueSupport)) throw new Error('Invalid map payload');
  const seen = new Set();
  for (const row of payload.rows) {
    const length = { region: 2, province: 5, citymun: 7 }[payload.childLevel];
    if (typeof row.code !== 'string' || !new RegExp(`^[0-9]{${length}}$`).test(row.code) || seen.has(row.code) ||
        typeof row.name !== 'string' || !(row.value === null || (typeof row.value === 'number' && Number.isFinite(row.value) && row.value >= 0)))
      throw new Error('Invalid or duplicate map row');
    seen.add(row.code);
  }
  if (!(payload.total === null || (typeof payload.total === 'number' && Number.isFinite(payload.total) && payload.total >= 0)))
    throw new Error('Invalid reported parent total');
  const declaredRate = (choice.dataset === 'bhw-stepzero-2026' && choice.indicator === 'bhw.declared-accreditation') ||
    (choice.dataset === 'bhw-2025' && choice.indicator === 'bhw.profile-accreditation');
  if (declaredRate) {
    const validRate = value => value === null || (typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 100);
    if (payload.total !== null || !validRate(payload.parentRate) || payload.rows.some(row => !validRate(row.value)))
      throw new Error('Invalid published rate or rate mislabeled as a total');
  } else if (payload.parentRate !== undefined) {
    throw new Error('Unexpected parent rate for this indicator');
  }
  const navigationOnly = payload.valueSupport === 'navigation-only';
  if (navigationOnly && (payload.total !== null || (payload.parentRate != null) || payload.rows.some(row => row.value !== null)))
    throw new Error('Navigation-only geography must not claim values');
  const expectedGeometry = `/bhw-connect${scope[2]}`;
  if (payload.geometryUrl !== expectedGeometry) throw new Error('Unexpected geometry path; refusing another origin/scope');
  const coverage = { rows: payload.rows.length,
    reportedRows: payload.rows.filter(row => row.value !== null).length,
    zeroRows: payload.rows.filter(row => row.value === 0).length,
    missingOrUnavailableRows: navigationOnly ? 0 : payload.rows.filter(row => row.value === null).length,
    navigationOnlyRows: navigationOnly ? payload.rows.length : 0,
    hasReportedParentTotal: payload.total !== null,
    ...(declaredRate ? { hasReportedParentRate: payload.parentRate !== null } : {}) };
  let geometry;
  try {
    const shapes = await readJson(new URL(expectedGeometry, url.origin), 32 * 1024 * 1024, request);
    if (shapes.status !== 200 || shapes.value.type !== 'FeatureCollection' || !Array.isArray(shapes.value.features))
      throw new Error('Geometry unavailable or not a FeatureCollection');
    const codes = new Set();
    const usable = new Set();
    for (const feature of shapes.value.features) {
      const code = feature.properties?.geo_code;
      if (typeof code !== 'string' || codes.has(code)) throw new Error('Invalid/duplicate geometry code');
      codes.add(code);
      if (['Polygon', 'MultiPolygon'].includes(feature.geometry?.type) && geometryPositions(feature.geometry).length)
        usable.add(code);
    }
    geometry = { state: 'checked', sha256: shapes.sha256, bytes: shapes.bytes,
      rowsWithGeometry: payload.rows.filter(row => usable.has(row.code)).length,
      rowsWithoutGeometry: payload.rows.filter(row => !usable.has(row.code)).length,
      reportedRowsWithoutGeometry: payload.rows.filter(row => row.value !== null && !usable.has(row.code)).length,
      note: 'Basic polygon/coordinate presence only; topology and rendering are not certified.' };
  } catch (error) {
    geometry = { state: 'not-checked', reason: error.message };
  }
  return { ...report, source: { title: payload.source, date: payload.date ?? null, license: payload.license ?? null },
    coverage, geometry };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  if (process.argv.length !== 3) throw new Error('Supply one explicit dataset-map query URL');
  const result = await inspectMapCoverage(process.argv[2]);
  console.log(JSON.stringify(result, null, 2));
  if (result.state !== 'available' || result.geometry?.state !== 'checked') process.exitCode = 1;
}
