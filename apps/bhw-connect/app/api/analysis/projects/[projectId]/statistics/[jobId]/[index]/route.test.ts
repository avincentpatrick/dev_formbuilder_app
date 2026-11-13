import { it, expect, vi, beforeEach } from "vitest";
const { auth, call, execute } = vi.hoisted(() => ({
  auth: vi.fn(),
  call: vi.fn(),
  execute: vi.fn(),
}));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: auth }));
vi.mock("@/lib/analysis-projects/db", () => ({ projectCall: call }));
vi.mock("@/lib/analysis-statistics/execute", () => ({
  executeStatistic: execute,
  hash: () => "input-hash",
}));
import { GET, POST } from "./route";
const params = {
  params: Promise.resolve({
    projectId: "00000000-0000-4000-8000-000000000001",
    jobId: "00000000-0000-4000-8000-000000000002",
    index: "0",
  }),
};
beforeEach(() => {
  vi.resetAllMocks();
  auth.mockResolvedValue({ status: "approved", user: { id: "actor" } });
  call.mockResolvedValue({
    result: {
      statistics: [
        {
          manifest: {},
          result: {
            id: "id",
            title: "Synthetic summary",
            inputHash: "input-hash",
            resultHash: "same",
            method: "describe",
            parameters: { confidence: 0.95 },
            rows: [],
            notes: [],
            provenance: { name: "Synthetic source", capturedAt: "2026-11-13" },
          },
        },
      ],
    },
  });
});
it("denies anonymous and unapproved users before reading private results", async () => {
  for (const status of ["signed_out", "pending"]) {
    auth.mockResolvedValueOnce({ status });
    expect((await GET(new Request("https://example.test?format=json"), params)).status).toBe(
      status === "signed_out" ? 401 : 403,
    );
  }
  expect(call).not.toHaveBeenCalled();
});
it("respects project ACL and deleted-source invalidation", async () => {
  call.mockRejectedValueOnce(new Error("Forbidden"));
  expect((await GET(new Request("https://example.test"), params)).status).toBe(403);
  call.mockResolvedValueOnce({ result: null });
  expect((await GET(new Request("https://example.test"), params)).status).toBe(404);
});
it("downloads only the authorized saved calculation with private cache headers", async () => {
  const response = await GET(new Request("https://example.test?format=csv"), params);
  expect(response.status).toBe(200);
  expect(response.headers.get("Cache-Control")).toContain("no-store");
  expect(await response.text()).toContain("Synthetic source");
  expect(call).toHaveBeenCalledWith("actor", "artifact", (await params.params).projectId, {
    jobId: (await params.params).jobId,
  });
});
it("replays the stored manifest and reports exact result agreement", async () => {
  execute.mockResolvedValueOnce({ kind: "statistical-result", resultHash: "same" });
  const response = await POST(new Request("https://example.test", { method: "POST" }), params);
  expect(await response.json()).toMatchObject({ reproduced: true });
  expect(execute).toHaveBeenCalledWith({});
});

it("exports numeric Excel values with scientific display precision and harmless text labels", async () => {
  const artifact = await call();
  artifact.result.statistics[0].result.rows = [
    {
      label: "=not-a-formula",
      metric: "mean",
      estimate: 2.2,
      lower: null,
      upper: null,
      p: null,
      unit: "people",
    },
  ];
  call.mockResolvedValueOnce(artifact);
  const response = await GET(new Request("https://example.test?format=xlsx"), params);
  expect(response.status).toBe(200);
  const { default: ExcelJS } = await import("exceljs");
  const book = new ExcelJS.Workbook();
  await book.xlsx.load(await response.arrayBuffer());
  expect(book.worksheets[0].getCell("A3").value).toBe("=not-a-formula");
  expect(book.worksheets[0].getCell("C3").value).toBe(2.2);
  expect(book.worksheets[0].getCell("C3").numFmt).toBe("0.000");
});
