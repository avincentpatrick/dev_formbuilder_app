import { describe, it, expect, vi, afterEach } from "vitest";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { resolve } from "node:path";
import { statisticSpec, type StatisticalResult } from "./types";
import { auditComputedNarrative } from "./audit";
import { statisticCells, statisticCsv, statisticSvg } from "./export";
import { executeStatistic, hash } from "./execute";
const spec = statisticSpec.parse({
  method: "describe",
  title: "Synthetic count summary",
  x: "count",
  unit: "people",
  design: { population: "census", unit: "area", independent: false },
  designStatement: "One row represents one disjoint area.",
});
const fixture: StatisticalResult = {
  kind: "statistical-result",
  id: "0123456789abcdef",
  engine: "test",
  method: "describe",
  title: "Synthetic <summary>",
  unit: "people",
  claims: { mean: { value: 2.2, display: "2.200", label: "mean", unit: "people" } },
  rows: [
    {
      label: '=HYPERLINK("https://example.invalid")',
      metric: "mean",
      unit: "people",
      estimate: 2.2,
      lower: null,
      upper: null,
      p: null,
    },
  ],
  plot: [
    {
      label: "<script>",
      metric: "mean",
      unit: "people",
      estimate: 2.2,
      lower: 1,
      upper: 3,
      p: null,
    },
  ],
  diagnostics: {},
  notes: ["Complete input; no imputation."],
  narrative: ["Mean = 2.200 people."],
  software: {},
  parameters: spec,
  inputRows: 250,
  excluded: 0,
  provenance: { name: "Synthetic data", capturedAt: "2026-11-13" },
  inputHash: "hash",
  resultHash: "hash",
};
afterEach(() => vi.unstubAllEnvs());
describe("statistical evidence validation and exports", () => {
  it("binds rounded values to result fields and rejects invented numbers or fields", () => {
    const text =
      "The mean was [[0123456789abcdef:mean]] people. The mean was 98. The mean was [[0123456789abcdef:invented]].";
    const result = auditComputedNarrative(text, [fixture]);
    expect(result.text).toBe("The mean was 2.200 people.");
    expect(result.rejectedSentences).toHaveLength(2);
  });
  it("does not accept a guessed number merely because it appears in a result", () => {
    expect(auditComputedNarrative("The rate was 2.2%.", [fixture]).text).toBe("");
  });
  it("exports matching cells, source notes and formula-safe CSV", () => {
    const csv = statisticCsv(fixture);
    expect(csv).toContain('"2.200"');
    expect(csv).toContain("'=HYPERLINK");
    expect(csv).toContain("Source: Synthetic data");
  });
  it("escapes all SVG markup and includes title, source and interval notes", () => {
    const svg = statisticSvg(fixture);
    expect(svg).toContain("&lt;script&gt;");
    expect(svg).not.toContain("<script>");
    expect(svg).toContain("Synthetic &lt;summary&gt;");
    expect(svg).toContain("Source: Synthetic data");
  });
  it("reports absent worker configuration honestly", async () => {
    vi.stubEnv("BHW_STATISTICS_PYTHON", "");
    expect(
      await executeStatistic({ spec, rows: [{ count: 1 }], provenance: fixture.provenance }),
    ).toMatchObject({ kind: "unsupported-statistical-analysis" });
  });
  it.skipIf(!process.env.BHW_STATISTICS_PYTHON)(
    "runs independent Python references for every method family",
    async () => {
      const { stdout, stderr } = await promisify(execFile)(
        process.env.BHW_STATISTICS_PYTHON!,
        [resolve("scripts/statistics/test_engine.py")],
        { timeout: 120000 },
      );
      expect(stdout + stderr).toContain("OK");
    },
    125000,
  );
  it.skipIf(!process.env.BHW_STATISTICS_PYTHON)(
    "repeats a saved full-table snapshot exactly through the isolated executable",
    async () => {
      const manifest = {
        spec,
        rows: [
          ...Array.from({ length: 100 }, () => ({ count: 1 })),
          ...Array.from({ length: 150 }, () => ({ count: 3 })),
        ],
        provenance: fixture.provenance,
      };
      const a = await executeStatistic(manifest),
        b = await executeStatistic(manifest);
      expect(a.kind).toBe("statistical-result");
      expect(b).toEqual(a);
      if (a.kind === "statistical-result") {
        expect(a.inputRows).toBe(250);
        expect(a.rows.find((r) => r.metric === "mean")?.estimate).toBe(2.2);
      }
    },
    125000,
  );
});

it("canonical hashes survive JSONB object key reordering", () => {
  expect(hash({ rows: [{ x: 1, y: 2 }], spec: { a: 1, b: 2 } })).toBe(
    hash({ spec: { b: 2, a: 1 }, rows: [{ y: 2, x: 1 }] }),
  );
  expect(hash({ rows: [1, 2] })).not.toBe(hash({ rows: [2, 1] }));
});
it("preserves very small nonzero estimates in scientific notation", () => {
  expect(
    statisticCells({ ...fixture, rows: [{ ...fixture.rows[0], estimate: 0.000001 }] })[0][2],
  ).toBe("1.000e-6");
});
