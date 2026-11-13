import { z } from "zod";
export const policySourcesSchema = z
  .object({
    mode: z.enum(["active", "selected"]).default("active"),
    versions: z.array(z.uuid()).max(100).default([]),
    revisions: z.array(z.uuid()).max(100).default([]),
    collection: z.string().trim().max(100).default(""),
    historical: z.boolean().default(false),
    datasetMode: z.enum(["automatic", "restricted"]).default("automatic"),
    tables: z
      .array(
        z
          .string()
          .regex(/^[a-z][a-z0-9_]*$/)
          .max(100),
      )
      .max(100)
      .default([]),
    online: z.boolean().default(false),
  })
  .superRefine((value, ctx) => {
    if (value.mode === "selected" && !value.versions.length && !value.revisions.length)
      ctx.addIssue({ code: "custom", message: "Select at least one policy revision" });
    if (value.versions.length + value.revisions.length > 100)
      ctx.addIssue({ code: "custom", message: "Select at most 100 policy revisions" });
    if (value.mode === "active" && value.historical)
      ctx.addIssue({ code: "custom", message: "Historical evidence must be explicitly selected" });
  });
export type PolicySources = z.infer<typeof policySourcesSchema>;
export type PolicyRecord = {
  title: string;
  issuer: string | null;
  document_type: string | null;
  issuance_number: string | null;
  collection_key: string | null;
  document: string;
  version_id: string;
  extraction_id: string;
  revision: number;
  page_count: number;
  warnings: unknown[];
  publication_date: string | null;
  effective_date: string | null;
  supersedes_id: string | null;
  uploaded_at: string;
  archived: boolean;
  current_revision: boolean;
};
export type PolicyContext = {
  actor: string;
  sources: PolicySources;
  records: PolicyRecord[];
  keys: string[];
  tables?: string[];
};
