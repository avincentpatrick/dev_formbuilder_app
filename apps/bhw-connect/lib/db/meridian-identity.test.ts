import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const { requestHeaders } = vi.hoisted(() => ({ requestHeaders: { cookie: null as string | null } }));
vi.mock("next/headers", () => ({
  headers: async () => ({ get: (name: string) => (name === "cookie" ? requestHeaders.cookie : null) }),
}));

const {
  clearMeridianIdentityCache,
  getMeridianIdentity,
  lookupMeridianIdentity,
  sessionCookiePair,
  STUB_USER,
  WHOAMI_CACHE_MS,
} = await import("./meridian-identity");

const BASE = "https://staging.example.test";
const USER = { id: "0195f3c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f", name: "Ana Reyes", email: "ana@example.test" };
const PAIR = "meridian-session=eyJpdiI6IjEyMyJ9%3D%2B";

function answer(status: number, body: unknown = {}) {
  return vi.fn<typeof fetch>(
    async () => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } }),
  );
}

beforeEach(() => {
  clearMeridianIdentityCache();
  requestHeaders.cookie = null;
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("sessionCookiePair", () => {
  it("takes Meridian's session cookie, byte for byte, and nothing else from the jar", () => {
    expect(sessionCookiePair(`XSRF-TOKEN=abc; ${PAIR}; theme=dark`)).toBe(PAIR);
  });

  it("finds nothing in an absent header, an empty value, or a cookie whose name only starts the same", () => {
    expect(sessionCookiePair(null)).toBeNull();
    expect(sessionCookiePair("meridian-session=")).toBeNull();
    expect(sessionCookiePair("meridian-session-old=x; other=y")).toBeNull();
  });
});

describe("lookupMeridianIdentity", () => {
  it("forwards only the session pair to whoami, as AJAX, and reads the person back", async () => {
    const fetchImpl = answer(200, { data: USER });

    const identity = await lookupMeridianIdentity(PAIR, { baseUrl: `${BASE}/`, fetchImpl });

    expect(identity).toEqual({ status: "signed_in", user: USER });
    expect(fetchImpl).toHaveBeenCalledTimes(1);
    const [url, init] = fetchImpl.mock.calls[0];
    expect(url).toBe(`${BASE}/api/v1/auth/whoami`);
    expect(init?.headers).toEqual({
      cookie: PAIR,
      accept: "application/json",
      "x-requested-with": "XMLHttpRequest",
    });
    expect(init?.redirect).toBe("manual");
    expect(init?.signal).toBeInstanceOf(AbortSignal);
  });

  it("makes no call at all without a cookie or without Meridian's address", async () => {
    const fetchImpl = answer(200, { data: USER });

    expect(await lookupMeridianIdentity(null, { baseUrl: BASE, fetchImpl })).toEqual({ status: "signed_out" });
    expect(await lookupMeridianIdentity(PAIR, { baseUrl: "", fetchImpl })).toEqual({ status: "signed_out" });
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it("reads 401 as signed out and 403 as refused", async () => {
    expect(await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: answer(401) })).toEqual({
      status: "signed_out",
    });
    clearMeridianIdentityCache();
    expect(await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: answer(403) })).toEqual({
      status: "refused",
    });
  });

  it("fails closed on a timeout, a 5xx, or an answer that is not a person with a uuid", async () => {
    const timeout = vi.fn(async () => {
      throw new DOMException("The operation was aborted due to timeout", "TimeoutError");
    });
    expect(await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: timeout })).toEqual({ status: "signed_out" });
    expect(await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: answer(503) })).toEqual({ status: "signed_out" });
    expect(
      await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: answer(200, { data: { ...USER, id: "42" } }) }),
    ).toEqual({ status: "signed_out" });
  });

  it("caches an answer for 30 seconds per cookie, and never caches a failure", async () => {
    const ok = answer(200, { data: USER });
    await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: ok, now: 1_000 });
    await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: ok, now: 1_000 + WHOAMI_CACHE_MS - 1 });
    expect(ok).toHaveBeenCalledTimes(1);
    await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: ok, now: 1_000 + WHOAMI_CACHE_MS });
    expect(ok).toHaveBeenCalledTimes(2);

    // A different cookie is a different person.
    await lookupMeridianIdentity("meridian-session=other", { baseUrl: BASE, fetchImpl: ok, now: 1_000 });
    expect(ok).toHaveBeenCalledTimes(3);

    // Meridian in its maintenance window: asked again on the next request, not pinned out for 30 s.
    clearMeridianIdentityCache();
    const down = answer(503);
    await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: down, now: 5_000 });
    await lookupMeridianIdentity(PAIR, { baseUrl: BASE, fetchImpl: down, now: 5_001 });
    expect(down).toHaveBeenCalledTimes(2);
  });
});

describe("getMeridianIdentity", () => {
  it("asks about the cookie this request carries", async () => {
    vi.stubEnv("MERIDIAN_INTERNAL_URL", BASE);
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockImplementation(answer(200, { data: USER }));
    requestHeaders.cookie = `theme=dark; ${PAIR}`;

    expect(await getMeridianIdentity()).toEqual({ status: "signed_in", user: USER });
    expect((fetchSpy.mock.calls[0][1]?.headers as Record<string, string>).cookie).toBe(PAIR);
    fetchSpy.mockRestore();
  });

  it("gives local development a fixed admin, and a production server nothing", async () => {
    vi.stubEnv("BHW_AUTH_MODE", "stub");
    expect(await getMeridianIdentity()).toEqual({ status: "signed_in", user: STUB_USER });

    vi.stubEnv("NODE_ENV", "production");
    expect(await getMeridianIdentity()).toEqual({ status: "signed_out" });
  });
});
