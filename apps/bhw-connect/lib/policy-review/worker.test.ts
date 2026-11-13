import { beforeEach, afterEach, describe, it, expect, vi } from "vitest";
import type { ReviewClaim } from "./types";
const m = vi.hoisted(() => ({ db: vi.fn(), loop: vi.fn(), registry: vi.fn(), query: vi.fn() }));
vi.mock("./db", () => ({ reviewWorkerCall: m.db }));
vi.mock("@/lib/ai/agent-loop", () => ({ runToolLoop: m.loop }));
vi.mock("@/lib/db/dataset-registry", () => ({ getRegisteredDataset: m.registry }));
vi.mock("@/lib/ai/dataset-tools", () => ({
  createDatasetTools: () => [
    { definition: { name: "listDatasets" }, execute: vi.fn() },
    { definition: { name: "queryDataset" }, execute: m.query },
  ],
}));
const { processOnePolicyReview, datasetDefinition } = await import("./worker");
const version = "00000000-0000-4000-8000-000000000001",
  extraction = "00000000-0000-4000-8000-000000000002";
const token = `[[policy:${version}:${extraction}:77:1]]`;
const source = "Clinics must retain signed referrals for 20 days.";
const dataset = {
  tableName: "agg_test",
  title: "Synthetic data",
  grain: "one place",
  notesMd: null,
  datasetSlug: "test",
  columns: [],
};
let claim: ReviewClaim;
const response = (
  text = JSON.stringify({
    relevant: true,
    findings: [
      {
        kind: "policy_requirement",
        topic: "retention",
        text: `Clinics must retain signed referrals for 20 days. ${token}`,
        quote: source,
        comparisonValue: "20 days",
      },
    ],
    limitations: [],
  }),
) => ({ finalText: text, toolPayloads: [], allCapped: false });
beforeEach(() => {
  vi.resetAllMocks();
  claim = {
    run: {
      id: "run",
      question: "What must clinics retain?",
      dataset_snapshot: { definitions: [datasetDefinition(dataset as never)] },
    },
    section: {
      id: 77,
      lease: "lease",
      page: 1,
      char_start: 0,
      char_end: source.length,
      content_sha256: "sha",
    },
    document: {
      metadata: {
        title: "Synthetic policy",
        document: `upload:${extraction}`,
        version_id: version,
        extraction_id: extraction,
        revision: 1,
        publication_date: null,
        effective_date: null,
        supersedes_id: null,
      },
      warnings: [],
    },
    text: source,
    integrity_ok: true,
  } as unknown as ReviewClaim;
  m.db.mockImplementation(async (op) => (op === "claim" ? claim : { active: true }));
  m.loop.mockResolvedValue(response());
  m.registry.mockResolvedValue(dataset);
  m.query.mockResolvedValue({ rows: [{ count: 20 }] });
});
afterEach(() => {
  vi.useRealTimers();
});
describe("bounded full-policy section examination", () => {
  it("supplies the whole exact section independently of retrieval and persists grounded outcomes", async () => {
    expect(await processOnePolicyReview()).toBe(true);
    const messages = m.loop.mock.calls[0][0];
    expect(messages.at(-1).content).toContain(source);
    expect(messages[0].content).toContain("ONE section only");
    expect(m.db).toHaveBeenCalledWith(
      "finish",
      77,
      "lease",
      undefined,
      expect.objectContaining({
        outcome: "examined",
        evidence: expect.objectContaining({
          policy: expect.objectContaining({
            versionId: version,
            extractionId: extraction,
            sha256: "sha",
          }),
        }),
      }),
    );
    expect(m.db.mock.calls.some((c) => c[0] === "fail")).toBe(false);
  });
  it("distinguishes examined-no-finding from errors and unavailable providers", async () => {
    m.loop.mockResolvedValue(response(JSON.stringify({ relevant: false, findings: [] })));
    await processOnePolicyReview();
    expect(m.db).toHaveBeenCalledWith(
      "finish",
      77,
      "lease",
      undefined,
      expect.objectContaining({ outcome: "no_finding" }),
    );
    m.db.mockClear();
    m.loop.mockResolvedValue({ allCapped: true, finalText: null });
    await processOnePolicyReview();
    expect(m.db.mock.calls.some((c) => c[0] === "finish")).toBe(false);
    expect(m.db.mock.calls.some((c) => c[0] === "fail")).toBe(true);
  });
  it.each([
    "wrong quote",
    "wrong token",
    "invented figure",
    "comparison not quoted",
    "inconsistent relevant",
    "malformed JSON",
  ])("rejects %s without marking examined", async (kind) => {
    const value = JSON.parse(response().finalText);
    if (kind === "wrong quote") value.findings[0].quote = "An invented source passage";
    if (kind === "wrong token") value.findings[0].text = "A claim [[policy:wrong]]";
    if (kind === "invented figure")
      value.findings[0].text = `Clinics must retain signed referrals for 999 days. ${token}`;
    if (kind === "comparison not quoted") value.findings[0].comparisonValue = "90 days";
    if (kind === "inconsistent relevant") value.relevant = false;
    m.loop.mockResolvedValue(
      response(kind === "malformed JSON" ? "broken" : JSON.stringify(value)),
    );
    await processOnePolicyReview();
    expect(m.db.mock.calls.some((c) => c[0] === "finish")).toBe(false);
    expect(m.db.mock.calls.some((c) => c[0] === "fail")).toBe(true);
  });
  it("refuses changed evidence before contacting a provider", async () => {
    claim.integrity_ok = false;
    await processOnePolicyReview();
    expect(m.loop).not.toHaveBeenCalled();
    expect(m.db.mock.calls.some((c) => c[0] === "fail")).toBe(true);
  });
  it("only queries frozen registered definitions, comparing object values independent of JSON key order", async () => {
    // Reorder top-level keys as PostgreSQL jsonb does; keep all fields.
    claim.run.dataset_snapshot.definitions = [
      Object.fromEntries(Object.entries(datasetDefinition(dataset as never)).reverse()),
    ];
    m.loop.mockImplementation(async (_messages, _event, tools) => {
      expect(await tools[0].execute({})).toEqual({
        datasets: claim.run.dataset_snapshot.definitions,
      });
      expect(await tools[1].execute({ table: "private_table" })).toHaveProperty("error");
      expect(m.query).not.toHaveBeenCalled();
      const good = await tools[1].execute({ table: "agg_test" });
      expect(good).toMatchObject({
        rows: [{ count: 20 }],
        exactRowsFrozen: false,
        queriedAt: expect.any(String),
      });
      m.registry.mockResolvedValue({ ...dataset, grain: "changed grain" });
      expect(await tools[1].execute({ table: "agg_test" })).toHaveProperty("error");
      expect(m.query).toHaveBeenCalledTimes(1);
      return response();
    });
    await processOnePolicyReview();
    expect(m.db.mock.calls.some((c) => c[0] === "finish")).toBe(true);
  });
  it("aborts outstanding work on lease loss and cannot publish", async () => {
    vi.useFakeTimers();
    m.db.mockImplementation(async (op) => (op === "claim" ? claim : { active: false }));
    m.loop.mockImplementation(async (_messages, _event, _tools, options) => {
      await vi.advanceTimersByTimeAsync(10000);
      expect(options.signal.aborted).toBe(true);
      options.signal.throwIfAborted();
    });
    await processOnePolicyReview();
    expect(m.db.mock.calls.some((c) => c[0] === "finish")).toBe(false);
  });
  it("returns no-work without provider calls", async () => {
    m.db.mockResolvedValue(null);
    expect(await processOnePolicyReview()).toBe(false);
    expect(m.loop).not.toHaveBeenCalled();
  });
});
