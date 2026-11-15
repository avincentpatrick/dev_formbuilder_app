import { beforeEach, afterEach, describe, it, expect, vi } from "vitest";
import { EventEmitter } from "node:events";
import { PassThrough } from "node:stream";
import { gzipSync } from "node:zlib";
const m = vi.hoisted(() => ({ request: vi.fn(), resolve: vi.fn() }));
vi.mock("node:https", () => ({ request: m.request }));
vi.mock("node:dns/promises", () => ({ lookup: m.resolve }));
const { publicFetch } = await import("./web");
let responses: Array<{ status?: number; headers?: Record<string, string>; bytes?: Buffer }>;
beforeEach(() => {
  vi.resetAllMocks();
  responses = [];
  m.resolve.mockResolvedValue([{ address: "8.8.8.8", family: 4 }]);
  m.request.mockImplementation((_url, _options, callback) => {
    const req = new EventEmitter() as EventEmitter & {
      end: () => void;
      destroy: (err: Error) => void;
    };
    req.destroy = (err) => {
      req.emit("error", err);
      req.emit("close");
    };
    req.end = () => {
      const data = responses.shift();
      if (!data) return;
      const res = new PassThrough() as PassThrough & {
        statusCode: number;
        headers: Record<string, string>;
      };
      res.statusCode = data.status ?? 200;
      res.headers = data.headers ?? { "content-type": "text/html" };
      callback(res);
      queueMicrotask(() => {
        res.end(data.bytes ?? Buffer.from("Policy text"));
        req.emit("close");
      });
    };
    return req;
  });
});
afterEach(() => {
  vi.useRealTimers();
});
describe("pinned public HTTPS transport", () => {
  it("pins the validated IP and preserves the original TLS/Host identity without ambient cookies", async () => {
    responses.push({});
    await publicFetch("https://doh.gov.ph/issuance", { allowedHost: "doh.gov.ph" });
    const [target, options] = m.request.mock.calls[0];
    expect(target.hostname).toBe("8.8.8.8");
    expect(options.servername).toBe("doh.gov.ph");
    expect(options.headers.Host).toBe("doh.gov.ph");
    expect(options.headers.Cookie).toBeUndefined();
    expect(options.headers.Authorization).toBeUndefined();
  });
  it.each(["https://127.0.0.1/secret", "https://evil.example/secret"])(
    "refuses unsafe redirect %s before another request",
    async (location) => {
      responses.push({ status: 302, headers: { location } });
      await expect(
        publicFetch("https://doh.gov.ph/policy", { allowedHost: "doh.gov.ph" }),
      ).rejects.toThrow();
      expect(m.request).toHaveBeenCalledTimes(1);
    },
  );
  it("never forwards a search API credential to a redirected origin", async () => {
    responses.push({ status: 302, headers: { location: "https://doh.gov.ph/a" } });
    await expect(
      publicFetch("https://api.search.brave.com/query", {
        headers: { "X-Subscription-Token": "synthetic" },
        sensitiveOrigin: "https://api.search.brave.com",
      }),
    ).rejects.toThrow("Credential");
    expect(m.request).toHaveBeenCalledTimes(1);
  });
  it("limits decompressed bytes rather than compressed Content-Length", async () => {
    responses.push({
      headers: { "content-type": "text/html", "content-encoding": "gzip" },
      bytes: gzipSync("x".repeat(5000)),
    });
    await expect(publicFetch("https://doh.gov.ph/a", { maxBytes: 100 })).rejects.toThrow(
      "size limit",
    );
  });
  it("bounds a server that never sends a response", async () => {
    vi.useFakeTimers();
    const result = publicFetch("https://doh.gov.ph/a");
    const assertion = expect(result).rejects.toThrow("timed out");
    await vi.advanceTimersByTimeAsync(21000);
    await assertion;
  });
});
