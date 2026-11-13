import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import { z } from "zod";

export const metadataSchema = z.object({
  title: z.string().trim().min(1).max(300),
  issuer: z.string().trim().max(200).default(""),
  type: z.string().trim().max(100).default(""),
  number: z.string().trim().max(100).default(""),
  collection: z.string().trim().max(100).default(""),
  tags: z.array(z.string().trim().min(1).max(80)).max(30).default([]),
  publicationDate: z.union([z.iso.date(), z.literal("")]).default(""),
  effectiveDate: z.union([z.iso.date(), z.literal("")]).default(""),
  supersedes: z.union([z.uuid(), z.literal("")]).default(""),
});
export type Metadata = z.infer<typeof metadataSchema>;
export type VersionRow = {
  id: string;
  document_id: string;
  title: string;
  issuer: string | null;
  document_type: string | null;
  issuance_number: string | null;
  tags: string[];
  collection_key: string | null;
  uploaded_at: string;
  publication_date: string | null;
  effective_date: string | null;
  archived: boolean;
  availability: string;
  embedding_status: string;
  stage: string;
  progress: number;
  safe_error: string | null;
};
export type Attempt = {
  id: string;
  original_filename: string;
  outcome: string;
  safe_reason: string | null;
  version_id: string | null;
  duplicate_version_id: string | null;
};
export type Catalog = { versions: VersionRow[]; attempts: Attempt[] };
export type Extraction = {
  id: string;
  canonical_text: string;
  revision: number;
  page_map: Array<{
    page: number;
    char_start: number;
    char_end: number;
    method: string;
    warnings: string[];
  }>;
  warnings: Array<{ page: number; code: string }>;
  parser_version: string;
  ocr_version: string | null;
  languages: string[];
};
export type Detail = VersionRow & {
  storage_key: string;
  byte_count: number;
  supersedes_id: string | null;
  extraction: Extraction | null;
  revisions: Array<{ id: string; revision: number; created_at: string }>;
  jobs: Array<{
    id: string;
    kind: string;
    stage: string;
    progress: number;
    attempts: number;
    safe_error: string | null;
  }>;
};

/** Only trusted server callers may supply actor; HTTP handlers resolve it from Meridian. */
export async function libraryCall<T>(
  actor: string,
  operation: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  const { data, error } = await createSupabaseServiceClient().rpc(
    "document_library_api" as never,
    {
      p_actor: actor,
      p_operation: operation,
      p_payload: payload,
    } as never,
  );
  if (error) throw new Error("Document library request failed");
  return data as T;
}
