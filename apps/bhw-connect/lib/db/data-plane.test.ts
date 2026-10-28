import { describe, expect, it, vi } from "vitest";
import { dataPlaneFetch, NO_DATA_SOURCE_BODY, NO_DATA_SOURCE_STATUS } from "./data-plane";

const SUPABASE = "https://example.supabase.co";

function recordingFetch() {
  const calls: string[] = [];
  const fn = vi.fn(async (input: RequestInfo | URL) => {
    calls.push(typeof input === "string" ? input : input instanceof URL ? input.href : input.url);
    return new Response("[]", { status: 200 });
  });
  return { fn: fn as unknown as typeof fetch, calls };
}

describe("dataPlaneFetch", () => {
  it("is absent when no REST URL and no build-without-data are set, so supabase-js keeps its own fetch", () => {
    expect(dataPlaneFetch({ NEXT_PUBLIC_SUPABASE_URL: SUPABASE })).toBeUndefined();
  });

  it("is absent without a Supabase URL, whatever else is set (the clients refuse that themselves)", () => {
    expect(dataPlaneFetch({ SUPABASE_REST_URL: "http://127.0.0.1:3001" })).toBeUndefined();
  });

  it("sends a table call to PostgREST with its path and query intact", async () => {
    const { fn, calls } = recordingFetch();
    const fetcher = dataPlaneFetch(
      { NEXT_PUBLIC_SUPABASE_URL: `${SUPABASE}/`, SUPABASE_REST_URL: "http://127.0.0.1:3001/" },
      fn,
    )!;
    await fetcher(`${SUPABASE}/rest/v1/dim_geo?select=geo_code&geo_level=in.(region,province)`);
    await fetcher(`${SUPABASE}/rest/v1/rpc/search_geo`, { method: "POST", body: "{}" });
    expect(calls).toEqual([
      "http://127.0.0.1:3001/dim_geo?select=geo_code&geo_level=in.(region,province)",
      "http://127.0.0.1:3001/rpc/search_geo",
    ]);
  });

  it("rewrites a Request object too, keeping its method", async () => {
    const seen: Request[] = [];
    const base = (async (input: RequestInfo | URL) => {
      seen.push(input as Request);
      return new Response("[]");
    }) as typeof fetch;
    const fetcher = dataPlaneFetch(
      { NEXT_PUBLIC_SUPABASE_URL: SUPABASE, SUPABASE_REST_URL: "http://127.0.0.1:3001" },
      base,
    )!;
    await fetcher(new Request(`${SUPABASE}/rest/v1/feedback`, { method: "POST", body: "{}" }));
    expect(seen[0].url).toBe("http://127.0.0.1:3001/feedback");
    expect(seen[0].method).toBe("POST");
  });

  it("leaves everything that is not a REST call alone", async () => {
    const { fn, calls } = recordingFetch();
    const fetcher = dataPlaneFetch(
      { NEXT_PUBLIC_SUPABASE_URL: SUPABASE, SUPABASE_REST_URL: "http://127.0.0.1:3001" },
      fn,
    )!;
    await fetcher(`${SUPABASE}/auth/v1/user`);
    await fetcher(`${SUPABASE}/storage/v1/object/feedback-screenshots/x.png`);
    await fetcher("https://generativelanguage.googleapis.com/v1beta/models");
    expect(calls).toEqual([
      `${SUPABASE}/auth/v1/user`,
      `${SUPABASE}/storage/v1/object/feedback-screenshots/x.png`,
      "https://generativelanguage.googleapis.com/v1beta/models",
    ]);
  });

  describe("a build with no data source (BHW_DATA_SOURCE=none)", () => {
    it("answers every REST call at once with a status supabase-js does not retry, and never touches the network", async () => {
      const { fn, calls } = recordingFetch();
      const fetcher = dataPlaneFetch(
        { NEXT_PUBLIC_SUPABASE_URL: SUPABASE, BHW_DATA_SOURCE: "none", NODE_ENV: "test" },
        fn,
      )!;
      const res = await fetcher(`${SUPABASE}/rest/v1/dim_geo?select=*`);
      expect(res.status).toBe(NO_DATA_SOURCE_STATUS);
      expect(await res.json()).toEqual(NO_DATA_SOURCE_BODY);
      expect(calls).toEqual([]);
      // 503 and 520 are the two statuses postgrest-js retries; 501 must stay outside them.
      expect([503, 520]).not.toContain(NO_DATA_SOURCE_STATUS);
    });

    it("is honoured inside next build", () => {
      expect(
        dataPlaneFetch({
          NEXT_PUBLIC_SUPABASE_URL: SUPABASE,
          BHW_DATA_SOURCE: "none",
          NODE_ENV: "production",
          NEXT_PHASE: "phase-production-build",
        }),
      ).toBeTypeOf("function");
    });

    it("refuses to start a production server with no data source", () => {
      expect(() =>
        dataPlaneFetch({
          NEXT_PUBLIC_SUPABASE_URL: SUPABASE,
          BHW_DATA_SOURCE: "none",
          NODE_ENV: "production",
        }),
      ).toThrow(/CI builds only/);
    });
  });
});
