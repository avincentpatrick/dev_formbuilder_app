import type { GeoLevel } from "@/lib/filters/schema";

export type CatalogId = "bhw" | "nhfr" | "uuc-phc" | "fhsis" | "population" | "poverty";
export type CatalogSelection =
  | { mode: "all" }
  | { mode: "psgc"; level: GeoLevel; code: string }
  | { mode: "district"; districtCode: string; citymunCode?: string; barangayCode?: string };
export type DataState =
  "available" | "partial" | "unsupported" | "unavailable" | "suppressed" | "failed";
export type PublicSource = {
  slug: string;
  name: string;
  sourceName: string | null;
  asOfDate: string | null;
  lastUpdatedAt: string | null;
};
export type PublicValue = {
  value: number;
  label?: string;
  numerator?: number | null;
  denominator?: number | null;
  ciLow?: number | null;
  ciHigh?: number | null;
};
export type CatalogIndicator = {
  id: string;
  label: string;
  unit: string;
  state: DataState;
  reason: string | null;
  sourceSlug: string;
  definition?: { numerator: string; denominator: string; sourceWorkbook: string };
  /** Empty for All locations. Presence is probed; no sample is passed off as a national value. */
  values: PublicValue[];
  /** Scalar area observations (one for a selected area); overlapping areas must not be summed. */
  reportedAreaRecords: number | null;
};
export type CatalogDataset = {
  id: CatalogId;
  title: string;
  category: string;
  state: DataState;
  availableIndicators: number;
  totalIndicators: number;
  supportedLevels: readonly GeoLevel[];
  /** Null when an existing route cannot preserve this scope. */
  detailHref: string | null;
  sources: PublicSource[];
  indicators: CatalogIndicator[];
};
export type PublicCatalog = {
  selection: CatalogSelection;
  datasets: CatalogDataset[];
  overview: {
    availableDatasets: number;
    availableIndicators: number;
    totalDatasets: number;
    totalIndicators: number;
    failedIndicators: number;
    suppressedIndicators: number;
    /** Per source: never merge different source rosters or imply land/population coverage. */
    coverage: { datasetId: CatalogId; indicatorId: string; reportedAreaRecords: number | null }[];
    freshness: {
      oldestSourceDate: string | null;
      newestSourceDate: string | null;
      latestUpdate: string | null;
      sourcesWithUnknownDate: number;
    };
  };
};
