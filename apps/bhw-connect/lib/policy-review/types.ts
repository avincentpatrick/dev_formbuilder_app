import { z } from "zod";
import { policySourcesSchema } from "@/lib/policy-analysis/schema";
export const reviewCreateSchema = z.object({
  requestKey: z.uuid(),
  question: z.string().trim().min(1).max(4000),
  sources: policySourcesSchema,
  budget: z.number().int().min(1).max(1000).default(200),
});
export const findingSchema = z.object({
  kind: z.enum([
    "policy_requirement",
    "documentary_figure",
    "dataset_observation",
    "analytical_conclusion",
  ]),
  topic: z.string().trim().min(1).max(80),
  text: z.string().trim().min(1).max(2000),
  quote: z.string().trim().min(8).max(1000),
  comparisonValue: z.string().max(200).default(""),
});
export const sectionResultSchema = z
  .object({
    relevant: z.boolean(),
    findings: z.array(findingSchema).max(5),
    limitations: z.array(z.string().max(300)).max(5).default([]),
  })
  .refine((r) => r.relevant === r.findings.length > 0, "Relevant and finding count must agree");
export type SectionResult = z.infer<typeof sectionResultSchema>;
export type ReviewDocument = {
  id: string;
  version_id: string | null;
  extraction_id: string | null;
  metadata: {
    title: string;
    issuer?: string | null;
    version_id: string;
    extraction_id: string;
    document: string;
    revision: number;
    publication_date: string | null;
    effective_date: string | null;
    supersedes_id: string | null;
  };
  warnings: unknown[];
  exclusion: string | null;
  sections: number;
  examined_sections: number;
};
export type ReviewRun = {
  id: string;
  actor_id: string;
  question: string;
  settings: z.infer<typeof policySourcesSchema>;
  dataset_snapshot: { capturedAt: string; exactRowsFrozen: false; definitions: unknown[] };
  status: string;
  reason: string | null;
  used: number;
  budget: number;
  created_at: string;
};
export type ReviewSection = {
  id: number;
  run_id: string;
  document_id: string;
  page: number;
  char_start: number;
  char_end: number;
  content_sha256: string;
  status: string;
  attempts: number;
  lease: string;
  lease_until: string;
  result: SectionResult | null;
  safe_error: string | null;
  evidence: Record<string, unknown> | null;
  metadata: ReviewDocument["metadata"];
};
export type ReviewReport = {
  run: ReviewRun;
  documents: ReviewDocument[];
  sections: ReviewSection[];
  coverage: {
    total: number;
    examined: number;
    no_finding: number;
    unreadable: number;
    failed: number;
    pending: number;
    running: number;
  };
  groups: Array<{ topic: string; findings: number; versions: number; comparison_needed: boolean }>;
  complete: boolean;
  dataset_rows_frozen: false;
};
export type ReviewClaim = {
  run: ReviewRun;
  section: ReviewSection;
  document: ReviewDocument;
  text: string;
  integrity_ok: boolean;
};
