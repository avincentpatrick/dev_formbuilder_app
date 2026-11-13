export type SourceMetadata = {
  reportingPeriod?: string;
  grain?: string;
  unit?: string;
  denominator?: string;
  geographyVersion?: string;
  joinKeys?: string;
  crs?: string;
};
export type SourcePage = { page: number; locator: string; text: string };
export type SourceTable = {
  name: string;
  columns: string[];
  rows: Record<string, unknown>[];
  profile: {
    rowCount: number;
    columns: Array<{ name: string; type: string; missing: number; unit: string }>;
    warnings: string[];
  };
};
export type Extraction = {
  status: "ready" | "blocked";
  error?: string;
  pages: SourcePage[];
  tables: SourceTable[];
  profile: { tables: unknown[]; warnings: string[]; title?: string; publicationDate?: string };
  preparations: Array<{ action: string; detail: string; table?: string }>;
  derivedHash?: string;
};
export type ProjectSource = {
  id: string;
  project_id: string;
  actor_id: string;
  kind: "file" | "web";
  name: string;
  sha256: string;
  bytes: number;
  url: string | null;
  provenance?: { publisher?: string; retrievedAt?: string };
  metadata: SourceMetadata;
  status: string;
  error: string | null;
  created_at: string;
  content?: Extraction;
  profile?: Extraction["profile"];
  preparations?: Extraction["preparations"];
  lease_id?: string;
};
