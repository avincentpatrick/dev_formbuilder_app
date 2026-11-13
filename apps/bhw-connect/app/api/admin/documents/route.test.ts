import { beforeEach, expect, it, vi } from "vitest";
const { admin, call, save, remove } = vi.hoisted(() => ({
  admin: vi.fn(),
  call: vi.fn(),
  save: vi.fn(),
  remove: vi.fn(),
}));
vi.mock("@/lib/db/require-admin", () => ({ getStrictAdminUser: admin }));
vi.mock("@/lib/documents/library", async () => {
  const actual =
    await vi.importActual<typeof import("@/lib/documents/library")>("@/lib/documents/library");
  return { ...actual, libraryCall: call };
});
vi.mock("@/lib/storage/documents", () => ({
  DOCUMENT_LIMITS: { bytes: 25 * 1024 * 1024 },
  saveDocument: save,
  removeDocument: remove,
}));
const { GET, POST } = await import("./route");
const actor = "11111111-1111-4111-8111-111111111111";
const attempt = "22222222-2222-4222-8222-222222222222";
function upload(text = "%PDF-1.7 synthetic", filename = "synthetic.pdf") {
  const form = new FormData();
  form.set("title", "Synthetic policy");
  form.set("file", new File([text], filename, { type: "application/pdf" }));
  return new Request("http://localhost/api/admin/documents", { method: "POST", body: form });
}
beforeEach(() => {
  vi.resetAllMocks();
  admin.mockResolvedValue({ id: actor, role: "admin" });
  save.mockResolvedValue("generated.pdf");
  remove.mockResolvedValue(undefined);
  call.mockImplementation(async (_actor, operation) =>
    operation === "attempt"
      ? { id: attempt }
      : operation === "register"
        ? { duplicate: false, versionId: actor }
        : {},
  );
});
it("denies metadata and uploads before receiving bytes from non-admins", async () => {
  admin.mockResolvedValue(null);
  expect((await GET(new Request("http://localhost/api/admin/documents"))).status).toBe(403);
  expect((await POST(upload())).status).toBe(403);
  expect(call).not.toHaveBeenCalled();
  expect(save).not.toHaveBeenCalled();
});
it("records a rejected PDF without saving its invalid contents", async () => {
  const response = await POST(upload("not PDF", "fake.pdf"));
  expect(response.status).toBe(400);
  expect(call).toHaveBeenCalledWith(
    actor,
    "attempt",
    expect.objectContaining({ outcome: "rejected", reason: "Only PDF files are accepted" }),
  );
  expect(save).not.toHaveBeenCalled();
});
it("queues a valid upload after original storage and records the detected hash", async () => {
  expect((await POST(upload())).status).toBe(201);
  expect(save).toHaveBeenCalledOnce();
  expect(call).toHaveBeenCalledWith(
    actor,
    "register",
    expect.objectContaining({ attempt, sha256: expect.stringMatching(/^[a-f0-9]{64}$/) }),
  );
});
it("removes only the newly saved duplicate after DB confirms reuse", async () => {
  call.mockImplementation(async (_actor, operation) =>
    operation === "attempt" ? { id: attempt } : { duplicate: true, versionId: actor },
  );
  expect((await POST(upload())).status).toBe(200);
  expect(remove).toHaveBeenCalledWith("generated.pdf");
});
it("keeps possibly committed originals when the DB response is uncertain", async () => {
  call.mockImplementation(async (_actor, operation) => {
    if (operation === "register") throw new Error("network uncertain");
    return { id: attempt };
  });
  expect((await POST(upload())).status).toBe(503);
  expect(remove).not.toHaveBeenCalled();
  expect(call).toHaveBeenCalledWith(actor, "fail_attempt", { attempt });
});
it("rejects cross-origin mutation requests and invalid date metadata", async () => {
  const request = new Request("http://localhost/api/admin/documents", {
    method: "POST",
    headers: { origin: "https://elsewhere.example", "content-type": "application/json" },
    body: "{}",
  });
  expect((await POST(request)).status).toBe(403);
  const form = new FormData();
  form.set("title", "test");
  form.set("publicationDate", "yesterday");
  form.set("file", new File(["%PDF-test"], "file.pdf"));
  expect(
    (
      await POST(
        new Request("http://localhost/api/admin/documents", { method: "POST", body: form }),
      )
    ).status,
  ).toBe(400);
});
