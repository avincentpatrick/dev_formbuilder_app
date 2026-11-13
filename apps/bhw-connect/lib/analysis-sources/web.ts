import "server-only";
import { analysisUsage } from "@/lib/analysis-projects/usage";
import { lookup } from "node:dns/promises";
import { request as httpsRequest } from "node:https";
import { createGunzip, createInflate, createBrotliDecompress } from "node:zlib";
import { HttpsProxyAgent } from "https-proxy-agent";
import ipaddr from "ipaddr.js";
const MAX_BYTES = 8 * 1024 * 1024;
export async function validatePublicUrl(raw: string, resolver: typeof lookup = lookup) {
  const url = new URL(raw);
  const host = url.hostname.replace(/^\[|\]$/g, "");
  if (
    url.protocol !== "https:" ||
    url.username ||
    url.password ||
    (url.port && url.port !== "443") ||
    /(^|\.)(localhost|local|internal|invalid|test|onion)$/.test(host)
  )
    throw new Error("Only public HTTPS sources are supported");
  const addresses = ipaddr.isValid(host)
    ? [{ address: host, family: ipaddr.parse(host).kind() === "ipv4" ? 4 : 6 }]
    : await resolver(host, { all: true });
  if (
    !addresses.length ||
    addresses.some(
      (a) => !ipaddr.isValid(a.address) || ipaddr.process(a.address).range() !== "unicast",
    )
  )
    throw new Error("Private or special network addresses cannot be retrieved");
  url.hash = "";
  return { url, address: addresses[0].address };
}
function proxyFor(host: string) {
  const bypass = (process.env.NO_PROXY ?? process.env.no_proxy ?? "").split(",").some((s) => {
    const token = s.trim().split(":")[0].replace(/^\./, "");
    return token === "*" || (token && (host === token || host.endsWith(`.${token}`)));
  });
  return bypass
    ? undefined
    : (process.env.HTTPS_PROXY ??
        process.env.https_proxy ??
        process.env.HTTP_PROXY ??
        process.env.http_proxy);
}
export async function publicFetch(
  raw: string,
  options: { headers?: Record<string, string>; sensitiveOrigin?: string } = {},
) {
  let next = raw;
  for (let hop = 0; hop < 4; hop++) {
    const { url, address } = await validatePublicUrl(next);
    if (options.sensitiveOrigin && url.origin !== options.sensitiveOrigin)
      throw new Error("Credential-bearing redirect refused");
    const target = new URL(url);
    target.hostname = address.includes(":") ? `[${address}]` : address;
    const proxy = proxyFor(url.hostname);
    const response = await new Promise<{
      status: number;
      location?: string;
      contentType: string;
      bytes: Buffer;
    }>((resolve, reject) => {
      // Pin the validated destination IP, including CONNECT through an inherited proxy. Preserve
      // the original TLS server name and Host so certificate verification still checks the domain.
      const req = httpsRequest(
        target,
        {
          servername: url.hostname,
          agent: proxy ? new HttpsProxyAgent(proxy) : undefined,
          headers: {
            Host: url.host,
            Accept: "text/html,application/pdf,application/json,text/plain",
            "Accept-Encoding": "identity",
            "User-Agent": "BHW-Connect-Analysis/1.0",
            ...options.headers,
          },
        },
        (res) => {
          const status = res.statusCode ?? 0;
          if (status >= 300 && status < 400) {
            res.resume();
            resolve({
              status,
              location: res.headers.location,
              contentType: "",
              bytes: Buffer.alloc(0),
            });
            return;
          }
          const encoding = res.headers["content-encoding"];
          const stream =
            encoding === "gzip"
              ? res.pipe(createGunzip())
              : encoding === "deflate"
                ? res.pipe(createInflate())
                : encoding === "br"
                  ? res.pipe(createBrotliDecompress())
                  : res;
          const chunks: Buffer[] = [];
          let size = 0;
          stream.on("data", (chunk: Buffer) => {
            size += chunk.length;
            if (size > MAX_BYTES) {
              stream.destroy();
              req.destroy(new Error("Source exceeds retrieval size limit"));
            } else chunks.push(chunk);
          });
          stream.on("error", reject);
          res.on("error", reject);
          stream.on("end", () =>
            resolve({
              status,
              contentType: String(res.headers["content-type"] ?? ""),
              bytes: Buffer.concat(chunks),
            }),
          );
        },
      );
      const deadline = setTimeout(
        () => req.destroy(new Error("Source retrieval timed out")),
        20000,
      );
      req.on("error", reject);
      req.on("close", () => clearTimeout(deadline));
      req.end();
    });
    if (response.status >= 300 && response.status < 400 && response.location) {
      next = new URL(response.location, url).href;
      continue;
    }
    if (response.status !== 200) throw new Error(`Source returned HTTP ${response.status}`);
    return {
      ...response,
      url: url.href,
      publisher: url.hostname,
      retrievedAt: new Date().toISOString(),
    };
  }
  throw new Error("Source redirected too many times");
}
export async function searchOnline(query: string) {
  const key = process.env.BHW_WEB_SEARCH_API_KEY;
  if (!key)
    return {
      available: false,
      error:
        "Online search is not configured. Dashboard data, attachments and direct public source URLs can still be used.",
    };
  const url = new URL("https://api.search.brave.com/res/v1/web/search");
  url.searchParams.set("q", query.slice(0, 500));
  url.searchParams.set("count", "5");
  analysisUsage
    .getStore()
    ?.push({
      provider: "brave-search",
      model: "web-v1",
      billingUnit: "request",
      inputTokens: null,
      outputTokens: null,
    });
  const response = await publicFetch(url.href, {
    headers: { "X-Subscription-Token": key },
    sensitiveOrigin: url.origin,
  });
  const data = JSON.parse(response.bytes.toString("utf8")) as {
    web?: { results?: Array<{ title: string; url: string; description: string }> };
  };
  return {
    available: true,
    provider: "Brave Search",
    retrievedAt: response.retrievedAt,
    results: (data.web?.results ?? []).slice(0, 5).map((r) => ({
      title: r.title,
      url: r.url,
      snippet: r.description,
      warning: "Search snippet only. Retrieve the page before citing factual claims.",
    })),
  };
}
