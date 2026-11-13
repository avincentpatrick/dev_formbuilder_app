import "server-only";
import { z } from "zod";
import type { Tool } from "@/lib/ai/tools";
import { policyCall } from "./evidence";
import type { PolicyContext } from "./schema";
export type PolicyHit = {
  chunk_id: number;
  document: string;
  document_title: string;
  page: number;
  page_to: number;
  heading: string | null;
  content: string;
  char_start: number;
  char_end: number;
  version_id: string;
  extraction_id: string;
  revision: number;
  publication_date: string | null;
  effective_date: string | null;
  supersedes_id: string | null;
  warnings: unknown[];
};
export function policyToken(hit: PolicyHit) {
  return `[[policy:${hit.version_id}:${hit.extraction_id}:${hit.chunk_id}:${hit.page}]]`;
}
export function toPolicyHit(hit: PolicyHit, full = false) {
  const chars = Array.from(hit.content),
    truncated = !full && chars.length > 1200;
  return {
    chunkId: hit.chunk_id,
    document: hit.document,
    documentTitle: hit.document_title,
    asOf: hit.publication_date,
    versionId: hit.version_id,
    extractionId: hit.extraction_id,
    revision: hit.revision,
    page: hit.page,
    ...(hit.page_to !== hit.page ? { pageRange: `${hit.page}-${hit.page_to}` } : {}),
    heading: hit.heading,
    text: truncated ? chars.slice(0, 1200).join("") : hit.content,
    truncated,
    charStart: hit.char_start,
    charEnd: truncated ? hit.char_start + 1200 : hit.char_end,
    effectiveDate: hit.effective_date,
    supersedesId: hit.supersedes_id,
    warnings: hit.warnings.slice(0, 20),
    warningCount: hit.warnings.length,
    evidenceToken: policyToken(hit),
    citation: `${hit.document_title} · version ${hit.version_id} · extraction ${hit.extraction_id} (revision ${hit.revision}) · physical PDF page ${hit.page}`,
  };
}
export function createPolicyTools(context: PolicyContext): Tool[] {
  const read = async (operation: string, args: Record<string, unknown>, full: boolean) => {
    try {
      const rows = await policyCall<PolicyHit[]>(context.actor, operation, {
        ...args,
        keys: context.keys,
        historical: context.sources.historical,
      });
      return {
        count: rows.length,
        results: rows.map((r) => toPolicyHit(r, full)),
        warnings: rows.some((r) => r.warnings.length)
          ? [
              "OCR/layout/unreadable-page limitations remain. This retrieval is not complete collection coverage.",
            ]
          : [],
        scope: "Selected policy revisions only",
      };
    } catch {
      return {
        error:
          "Policy evidence unavailable, archived, changed, or outside the selected scope. Do not infer absence of a requirement.",
      };
    }
  };
  return [
    {
      definition: {
        name: "listPolicies",
        description:
          "List the frozen policy revisions eligible for this question, including dates, issuer, supersession metadata and quality limits. Inventory is not evidence that every page has been examined.",
        parameters: { type: "object", properties: {} },
      },
      async execute() {
        // Recheck current admin authorization even for the already-resolved inventory.
        try {
          await policyCall(context.actor, "authorize", {});
          return {
            policies: context.records.map((r) => ({
              ...r,
              warnings: r.warnings.slice(0, 20),
              warningCount: r.warnings.length,
            })),
            coverage: "Inventory only; not examined in full",
          };
        } catch {
          return { error: "Policy access is no longer available" };
        }
      },
    },
    {
      definition: {
        name: "searchDocuments",
        description:
          "Search only the selected policy revisions. Hits may be truncated. Use readPolicyPassage for full text or neighboring sections. Cite policy requirements using the exact evidenceToken, never a model-authored version/page label.",
        parameters: {
          type: "object",
          properties: { query: { type: "string" }, limit: { type: "integer" } },
          required: ["query"],
        },
      },
      async execute(args) {
        const parsed = z
          .object({
            query: z.string().min(2).max(400),
            limit: z.number().int().min(1).max(25).default(6),
          })
          .safeParse(args);
        return parsed.success
          ? read("search", parsed.data, false)
          : { error: "Use a query of 2–400 characters and a limit of 1–25" };
      },
    },
    {
      definition: {
        name: "readPolicyPassage",
        description:
          "Read a stored passage in full, plus up to two neighboring chunks on each side, without replacing source text with a summary. Only IDs from the selected policy revisions are allowed. Returned evidenceToken binds version, extraction, chunk and physical PDF page.",
        parameters: {
          type: "object",
          properties: { chunkId: { type: "integer" }, neighbors: { type: "integer" } },
          required: ["chunkId"],
        },
      },
      async execute(args) {
        const parsed = z
          .object({
            chunkId: z.number().int().positive(),
            neighbors: z.number().int().min(0).max(2).default(1),
          })
          .safeParse(args);
        return parsed.success
          ? read("read", { chunk: parsed.data.chunkId, neighbors: parsed.data.neighbors }, true)
          : { error: "Choose a valid chunk ID and 0–2 neighbors" };
      },
    },
  ];
}
