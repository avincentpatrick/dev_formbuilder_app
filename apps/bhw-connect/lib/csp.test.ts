import { describe, expect, it } from "vitest";
import { contentSecurityPolicy, createNonce } from "./csp";

function directives(policy: string): Map<string, string[]> {
  return new Map(
    policy.split("; ").map((part) => {
      const [name, ...values] = part.split(" ");
      return [name, values] as const;
    }),
  );
}

describe("contentSecurityPolicy", () => {
  const production = directives(contentSecurityPolicy("abc123", { dev: false }));

  it("runs only scripts that carry this request's nonce, and never inline or eval'd ones", () => {
    const script = production.get("script-src")!;
    expect(script).toContain("'nonce-abc123'");
    expect(script).toContain("'strict-dynamic'");
    expect(script).not.toContain("'unsafe-inline'");
    expect(script).not.toContain("'unsafe-eval'");
  });

  it("lets nothing frame the app, change its base URL, post off-origin, or load a plugin", () => {
    expect(production.get("frame-ancestors")).toEqual(["'none'"]);
    expect(production.get("base-uri")).toEqual(["'self'"]);
    expect(production.get("form-action")).toEqual(["'self'"]);
    expect(production.get("object-src")).toEqual(["'none'"]);
  });

  it("talks to its own origin only", () => {
    expect(production.get("connect-src")).toEqual(["'self'"]);
    expect(production.get("default-src")).toEqual(["'self'"]);
  });

  it("allows maplibre's blob worker", () => {
    expect(production.get("worker-src")).toContain("blob:");
  });

  it("adds eval and the dev server's socket only in development", () => {
    const dev = directives(contentSecurityPolicy("abc123", { dev: true }));
    expect(dev.get("script-src")).toContain("'unsafe-eval'");
    expect(dev.get("connect-src")).toContain("ws:");
  });
});

describe("createNonce", () => {
  it("is fresh each time and long enough to be unguessable", () => {
    const a = createNonce();
    const b = createNonce();
    expect(a).not.toBe(b);
    expect(atob(a)).toHaveLength(16);
  });
});
