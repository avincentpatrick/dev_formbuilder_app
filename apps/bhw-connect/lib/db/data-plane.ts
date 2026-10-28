/**
 * The data plane: where supabase-js's REST calls actually go.
 *
 * Both data clients (`supabase.ts`, `service-client.ts`) hand this `fetch` to supabase-js as
 * `global.fetch`. supabase-js composes every table and RPC call as
 * `${NEXT_PUBLIC_SUPABASE_URL}/rest/v1/<table>`. On the testing box the app is served by a plain
 * PostgREST (Meridian's ADR-0024), which has no `/rest/v1` prefix and no option to add one. So the
 * one bridge lives here, and the 189 `.from()`/`.rpc()` call sites change by nothing:
 *
 * - **`SUPABASE_REST_URL` unset** — `dataPlaneFetch()` returns `undefined` and supabase-js keeps its
 *   own fetch: Supabase, exactly as on a laptop and on Vercel.
 * - **`SUPABASE_REST_URL` set** — a request under `${NEXT_PUBLIC_SUPABASE_URL}/rest/v1/` goes to
 *   `${SUPABASE_REST_URL}/` instead, path and query intact. Anything else (auth, storage) is left
 *   alone.
 * - **`BHW_DATA_SOURCE=none`** — a build with no data source at all, which is what CI's build is:
 *   every REST call is answered at once with a 501, a status supabase-js does not retry. Measured:
 *   supabase-js 2.110 retries a failed GET three times (after 1 s, 2 s and 4 s), so against an
 *   unreachable database `/bhw` outlasted Next's 60-second prerender limit and the build failed;
 *   answered at once, every page degrades as it does on any query error, and the build took 20 s.
 *   It is honoured only inside `next build` and in tests: a server started with it throws instead
 *   of serving empty pages.
 */
export type DataPlaneEnv = Record<string, string | undefined>;

export const NO_DATA_SOURCE_STATUS = 501;

export const NO_DATA_SOURCE_BODY = {
  code: "BHW00",
  message: "No data source in this build (BHW_DATA_SOURCE=none).",
  details: null,
  hint: null,
};

function trimTrailingSlash(value: string | undefined): string {
  return (value ?? "").trim().replace(/\/+$/, "");
}

function requestUrl(input: RequestInfo | URL): string {
  if (typeof input === "string") return input;
  if (input instanceof URL) return input.href;
  return input.url;
}

export function dataPlaneFetch(
  env: DataPlaneEnv = process.env,
  baseFetch: typeof fetch = (...args) => globalThis.fetch(...args),
): typeof fetch | undefined {
  const supabaseUrl = trimTrailingSlash(env.NEXT_PUBLIC_SUPABASE_URL);
  const restUrl = trimTrailingSlash(env.SUPABASE_REST_URL);
  const noDataSource = env.BHW_DATA_SOURCE === "none";

  if (
    noDataSource &&
    env.NODE_ENV === "production" &&
    env.NEXT_PHASE !== "phase-production-build"
  ) {
    throw new Error(
      "BHW_DATA_SOURCE=none is for CI builds only; a running server must have a data source.",
    );
  }
  if (!supabaseUrl || (!restUrl && !noDataSource)) return undefined;

  const restBase = `${supabaseUrl}/rest/v1/`;

  return async (input, init) => {
    const url = requestUrl(input);
    if (!url.startsWith(restBase)) return baseFetch(input, init);

    if (noDataSource) {
      return new Response(JSON.stringify(NO_DATA_SOURCE_BODY), {
        status: NO_DATA_SOURCE_STATUS,
        headers: { "content-type": "application/json" },
      });
    }

    const target = `${restUrl}/${url.slice(restBase.length)}`;
    return input instanceof Request
      ? baseFetch(new Request(target, input), init)
      : baseFetch(target, init);
  };
}
