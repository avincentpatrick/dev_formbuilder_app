import { beforeEach, describe, expect, it, vi } from "vitest";
import type { MeridianIdentity } from "./meridian-identity";

const { identity, getMeridianIdentity } = vi.hoisted(() => {
  const identity = { current: { status: "signed_out" } as MeridianIdentity };
  const getMeridianIdentity = vi.fn(async () => identity.current);
  return { identity, getMeridianIdentity };
});
vi.mock("./meridian-identity", () => ({ getMeridianIdentity }));

const { fakeAdminRow, lookedUp, createSupabaseServiceClient } = vi.hoisted(() => {
  const fakeAdminRow = { current: null as { role: string } | null };
  const lookedUp: unknown[] = [];
  const createSupabaseServiceClient = vi.fn(() => ({
    from: () => ({
      select: () => ({
        eq: function (_column: string, value: unknown) {
          lookedUp.push(value);
          return this;
        },
        maybeSingle: async () => ({ data: fakeAdminRow.current }),
      }),
    }),
  }));
  return { fakeAdminRow, lookedUp, createSupabaseServiceClient };
});
vi.mock("./service-client", () => ({ createSupabaseServiceClient }));

const { getAdminAuthResult, getAdminUser } = await import("./require-admin");

const MEMBER = { id: "0195f3c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f", name: "Ana Reyes", email: "ana@example.test" };

beforeEach(() => {
  identity.current = { status: "signed_out" };
  fakeAdminRow.current = null;
  lookedUp.length = 0;
});

describe("getAdminAuthResult", () => {
  it("reports signed_out when nobody is signed in to Meridian", async () => {
    expect(await getAdminAuthResult()).toEqual({ status: "signed_out" });
    expect(await getAdminUser()).toBeNull();
  });

  it("reports not_admin when signed in to Meridian but not in admin_users", async () => {
    identity.current = { status: "signed_in", user: MEMBER };
    expect(await getAdminAuthResult()).toEqual({ status: "not_admin", email: "ana@example.test" });
    expect(await getAdminUser()).toBeNull();
  });

  it("reports ok when the Meridian account's id has an admin_users row", async () => {
    identity.current = { status: "signed_in", user: MEMBER };
    fakeAdminRow.current = { role: "admin" };
    const admin = { id: MEMBER.id, email: "ana@example.test", role: "admin" };
    expect(await getAdminAuthResult()).toEqual({ status: "ok", admin });
    expect(await getAdminUser()).toEqual(admin);
    // admin_users is keyed by the Meridian user id: the lookup used exactly that.
    expect(lookedUp).toContain(MEMBER.id);
  });

  it("treats an account Meridian refuses as signed in but not an admin, never as signed out", async () => {
    // "Signed out" would send it to sign in again, where it already is: a circle.
    identity.current = { status: "refused" };
    fakeAdminRow.current = { role: "admin" };
    expect(await getAdminAuthResult()).toEqual({ status: "not_admin", email: null });
  });

  it("fails closed (signed_out) rather than throwing when the service client is unconfigured", async () => {
    identity.current = { status: "signed_in", user: MEMBER };
    createSupabaseServiceClient.mockImplementationOnce(() => {
      throw new Error("unconfigured");
    });
    expect(await getAdminAuthResult()).toEqual({ status: "signed_out" });
  });

  it("fails closed (signed_out) rather than throwing when asking Meridian throws", async () => {
    getMeridianIdentity.mockImplementationOnce(async () => {
      throw new Error("headers() outside a request");
    });
    expect(await getAdminAuthResult()).toEqual({ status: "signed_out" });
  });
});
