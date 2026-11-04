import { describe, expect, it } from "vitest";
import { NextRequest } from "next/server";
import { proxy } from "./proxy";

function request(path: string, cookie?: string): NextRequest {
  return new NextRequest(new URL(path, "https://staging.example.test"), {
    headers: cookie ? { cookie } : {},
  });
}

describe("proxy", () => {
  it("sends a visitor with no Meridian session from an admin page to the sign-in page, and asks nobody", () => {
    const response = proxy(request("/admin/feedback"));

    expect(response.status).toBe(307);
    expect(new URL(response.headers.get("location")!).pathname).toBe("/admin/login");
  });

  it("lets a visitor carrying a Meridian session through: the layout decides who is an admin", () => {
    const response = proxy(request("/admin/feedback", "meridian-session=abc"));

    expect(response.headers.get("location")).toBeNull();
    expect(response.headers.get("content-security-policy")).toContain("script-src");
  });

  it("never redirects the sign-in page itself, or any page outside /admin", () => {
    expect(proxy(request("/admin/login")).headers.get("location")).toBeNull();
    expect(proxy(request("/explore")).headers.get("location")).toBeNull();
  });

  it("gives every page the policy, with a fresh nonce on the request", () => {
    const first = proxy(request("/explore")).headers.get("content-security-policy");
    const second = proxy(request("/explore")).headers.get("content-security-policy");

    expect(first).toMatch(/'nonce-[^']+'/);
    expect(first).not.toBe(second);
    expect(first).not.toMatch(/supabase/i);
  });
});
