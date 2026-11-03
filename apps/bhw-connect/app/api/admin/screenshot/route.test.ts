import { beforeEach, describe, expect, it, vi } from "vitest";

const { admin, getAdminUser } = vi.hoisted(() => {
  const admin = { current: null as { id: string; email: string | null; role: "admin" } | null };
  return { admin, getAdminUser: vi.fn(async () => admin.current) };
});
vi.mock("@/lib/db/require-admin", () => ({ getAdminUser }));

const { readScreenshot } = vi.hoisted(() => ({
  readScreenshot: vi.fn(async (path: string) =>
    path === "3f8a1c2e-5b6d-4e7f-8a9b-0c1d2e3f4a5b/0d6fead7-616a-43d9-88ef-55f315eb964f.jpg"
      ? { bytes: Buffer.from([0xff, 0xd8, 0xff]), contentType: "image/jpeg" as const }
      : null,
  ),
}));
vi.mock("@/lib/storage/screenshots", () => ({ readScreenshot }));

const { GET } = await import("./route");

const PATH = "3f8a1c2e-5b6d-4e7f-8a9b-0c1d2e3f4a5b/0d6fead7-616a-43d9-88ef-55f315eb964f.jpg";

function get(path: string): Request {
  return new Request(`http://localhost/api/admin/screenshot?path=${encodeURIComponent(path)}`);
}

beforeEach(() => {
  admin.current = null;
  readScreenshot.mockClear();
});

describe("GET /api/admin/screenshot", () => {
  it("serves an admin the image, uncached and unsniffable", async () => {
    admin.current = { id: "0195f3c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f", email: "ana@example.test", role: "admin" };

    const response = await GET(get(PATH));

    expect(response.status).toBe(200);
    expect(response.headers.get("content-type")).toBe("image/jpeg");
    expect(response.headers.get("cache-control")).toBe("private, no-store");
    expect(response.headers.get("x-content-type-options")).toBe("nosniff");
    expect(new Uint8Array(await response.arrayBuffer())).toEqual(new Uint8Array([0xff, 0xd8, 0xff]));
  });

  it("answers anyone else 404 without reading the disk", async () => {
    const response = await GET(get(PATH));

    expect(response.status).toBe(404);
    expect(readScreenshot).not.toHaveBeenCalled();
  });

  it("answers an admin 404 for a path that is not a screenshot, the same as for a missing one", async () => {
    admin.current = { id: "0195f3c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f", email: null, role: "admin" };

    expect((await GET(get("../../.env"))).status).toBe(404);
    expect((await GET(get(""))).status).toBe(404);
  });
});
