import { afterEach, expect, it, vi } from "vitest";
import { readMapResponse } from "./read-map-response";

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it.each([502, 503, 504])(
  "recovers once from %s without changing the requested scope",
  async (status) => {
    vi.useFakeTimers();
    const recovered = Response.json({ state: "available" });
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(new Response("Unavailable", { status }))
      .mockResolvedValueOnce(recovered);
    vi.stubGlobal("fetch", fetcher);
    const signal = new AbortController().signal;
    const url = "/bhw-connect/api/dataset-map?level=province&code=07311";
    const result = readMapResponse(url, signal);
    await vi.advanceTimersByTimeAsync(499);
    expect(fetcher).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(await result).toBe(recovered);
    expect(fetcher.mock.calls).toEqual([
      [url, { signal }],
      [url, { signal }],
    ]);
  },
);

it("stops after a second transient failure", async () => {
  vi.useFakeTimers();
  const failure = new Response("Unavailable", { status: 503 });
  const fetcher = vi.fn().mockResolvedValue(failure);
  vi.stubGlobal("fetch", fetcher);
  const result = readMapResponse("/bhw-connect/api/dataset-map", new AbortController().signal);
  await vi.runAllTimersAsync();
  expect(await result).toBe(failure);
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it.each([
  Response.json({ state: "unavailable" }),
  new Response("Unsupported", { status: 400 }),
  new Response("Forbidden", { status: 403 }),
  new Response("Rate limited", { status: 429 }),
  new Response("Failed", { status: 500 }),
  new Response("Wait", { status: 503, headers: { "Retry-After": "60" } }),
])("does not retry non-transient responses or override server backoff", async (response) => {
  const fetcher = vi.fn().mockResolvedValue(response);
  vi.stubGlobal("fetch", fetcher);
  expect(await readMapResponse("/bhw-connect/api/dataset-map", new AbortController().signal)).toBe(
    response,
  );
  expect(fetcher).toHaveBeenCalledTimes(1);
});

it("cancels a pending retry when the scope is abandoned", async () => {
  vi.useFakeTimers();
  const fetcher = vi.fn().mockResolvedValue(new Response("Unavailable", { status: 503 }));
  vi.stubGlobal("fetch", fetcher);
  const controller = new AbortController();
  const result = readMapResponse("/bhw-connect/api/dataset-map", controller.signal);
  const rejected = expect(result).rejects.toMatchObject({ name: "AbortError" });
  await vi.advanceTimersByTimeAsync(200);
  controller.abort();
  await rejected;
  await vi.runAllTimersAsync();
  expect(fetcher).toHaveBeenCalledTimes(1);
});
