import { beforeEach, expect, it, vi } from "vitest";
import { GET } from "./route";
const mock = vi.hoisted(() => ({ read: vi.fn() }));
vi.mock("@/lib/db/nhfr-presence", () => ({ getNhfrPresence: mock.read }));
beforeEach(() => {
  vi.clearAllMocks();
});
it("passes OR type selection to the verified public summary and marks failures unavailable", async () => {
  mock.read.mockResolvedValue({ code: "PH" });
  let response = await GET(
    new Request(
      "http://localhost/?mode=psgc&location=national:PH&type=Hospital&type=Station&type=Station",
    ),
  );
  expect(response.status).toBe(200);
  expect(mock.read).toHaveBeenCalledWith("PH", "national", ["Hospital", "Station"]);
  expect(response.headers.get("cache-control")).toBe("no-store");
  mock.read.mockResolvedValue(null);
  response = await GET(new Request("http://localhost/?mode=psgc&location=national:PH"));
  expect(response.status).toBe(503);
  mock.read.mockRejectedValue(new Error("private detail"));
  response = await GET(new Request("http://localhost/?mode=psgc&location=national:PH"));
  expect(response.status).toBe(503);
  expect(await response.text()).not.toContain("private detail");
});
it("rejects unsupported geography, duplicate scalar parameters and malformed types", async () => {
  for (const query of [
    "mode=all",
    "mode=psgc&location=citymun:0102801",
    "mode=psgc&location=national:PH&location=region:01",
    "mode=psgc&location=national:PH&type=",
    "mode=psgc&location=national:PH&order=bad",
  ]) {
    expect((await GET(new Request("http://localhost/?" + query))).status).toBe(400);
  }
  expect(mock.read).not.toHaveBeenCalled();
});
