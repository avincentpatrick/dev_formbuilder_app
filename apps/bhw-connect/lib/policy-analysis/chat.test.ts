import { expect, it, vi } from "vitest";
import { policySourcesSchema, type PolicyContext } from "./schema";
import { toPolicyHit, type PolicyHit } from "./tools";
const { loop } = vi.hoisted(() => ({ loop: vi.fn() }));
vi.mock("@/lib/ai/agent-loop", () => ({ runToolLoop: loop }));
vi.mock("@/lib/ai/dataset-tools", () => ({ createInternalTools: () => [] }));
vi.mock("@/lib/ai/route-request", () => ({
  routeRequest: async () => ({
    lane: "policy",
    scope: null,
    output: "answer",
    confidence: "matched",
  }),
}));
const { analysisResponse } = await import("@/lib/ai/analysis-chat");
const a: PolicyHit = {
  chunk_id: 1,
  document: "upload:22222222-2222-4222-8222-222222222222",
  document_title: "Policy Alpha",
  page: 1,
  page_to: 1,
  heading: null,
  content: "Keep records for 20 days.",
  char_start: 0,
  char_end: 25,
  version_id: "11111111-1111-4111-8111-111111111111",
  extraction_id: "22222222-2222-4222-8222-222222222222",
  revision: 1,
  publication_date: "2024-01-01",
  effective_date: null,
  supersedes_id: null,
  warnings: [],
};
const b: PolicyHit = {
  ...a,
  chunk_id: 2,
  document: "upload:44444444-4444-4444-8444-444444444444",
  document_title: "Policy Beta",
  content: "Keep records for 30 days.",
  version_id: "33333333-3333-4333-8333-333333333333",
  extraction_id: "44444444-4444-4444-8444-444444444444",
  publication_date: "2025-01-01",
};
async function answer(text: string, hits: PolicyHit[], data: unknown[] = []) {
  const results = hits.map((h) => toPolicyHit(h, true));
  const sources = policySourcesSchema.parse({ mode: "active" });
  const context: PolicyContext = {
    actor: "admin",
    sources,
    keys: hits.map((h) => h.document),
    records: hits.map((h) => ({
      title: h.document_title,
      document: h.document,
      version_id: h.version_id,
      extraction_id: h.extraction_id,
      revision: 1,
      page_count: 1,
      warnings: [],
      publication_date: h.publication_date,
      effective_date: null,
      supersedes_id: null,
      uploaded_at: "2026-01-01",
      archived: false,
      current_revision: true,
      issuer: null,
      document_type: null,
      issuance_number: null,
      collection_key: null,
    })),
  };
  loop.mockResolvedValue({
    finalText: text,
    toolPayloads: [{ results }, ...data],
    allCapped: false,
    provider: "gemini",
  });
  const response = await analysisResponse(
    {
      messages: [{ role: "user", content: "Compare the policies and applicable data" }],
      policySources: sources,
    },
    undefined,
    undefined,
    [],
    true,
    undefined,
    "admin",
    context,
  );
  return (await response.text())
    .split("\n")
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}
it("returns traceable policy-only and conflicting-policy answers without inferring legal supersession", async () => {
  const ha = toPolicyHit(a, true),
    hb = toPolicyHit(b, true);
  const one = await answer(`Policy requirement: “Keep records for 20 days.” ${ha.evidenceToken}`, [
    a,
  ]);
  expect(one.find((e) => e.type === "message").content).toContain(a.version_id);
  const both = await answer(
    `Policy requirement: “Keep records for 20 days.” ${ha.evidenceToken}\nPolicy requirement: “Keep records for 30 days.” ${hb.evidenceToken}\nAnalytical conclusion: the provisions conflict; a newer publication alone does not establish legal supersession.`,
    [a, b],
  );
  const message = both.find((e) => e.type === "message").content;
  expect(message).toContain(a.version_id);
  expect(message).toContain(b.version_id);
  expect(message).toContain("does not establish legal supersession");
  expect(both.find((e) => e.type === "citations").citations).toHaveLength(2);
});
it("combines computed dataset observations with policy evidence and withholds invented figures", async () => {
  const hit = toPolicyHit(a, true);
  const events = await answer(
    `Policy requirement: keep records for 20 days ${hit.evidenceToken}. Dataset observation: 42 workers were counted. Dataset observation: 97 workers were counted. Analytical conclusion: this count does not establish compliance.`,
    [a],
    [{ rows: [{ workers: 42 }] }],
  );
  const text = events.find((e) => e.type === "message").content;
  expect(text).toContain("42 workers");
  expect(text).not.toContain("97 workers");
  expect(text).toContain("withheld");
  expect(text).toContain("does not establish compliance");
});
