import { z } from "zod";
export const statisticSpec = z.object({
  method: z.enum([
    "describe",
    "proportion",
    "rate",
    "time",
    "welch",
    "paired",
    "mann_whitney",
    "pearson",
    "spearman",
    "chi_square",
    "fisher",
    "ols",
    "logistic",
    "poisson",
  ]),
  title: z.string().trim().min(5).max(180),
  x: z.string().min(1).max(100).optional(),
  y: z.string().min(1).max(100).optional(),
  group: z.string().max(100).optional(),
  pairId: z.string().max(100).optional(),
  predictors: z.array(z.string().min(1).max(100)).max(10).optional(),
  exposure: z.string().max(100).optional(),
  seed: z.number().int().min(0).max(2147483647).default(0),
  confidence: z.number().min(0.8).max(0.99).default(0.95),
  unit: z.string().trim().min(1).max(100),
  denominatorDefinition: z.string().min(3).max(500).optional(),
  periodDefinition: z.string().min(3).max(500).optional(),
  contrast: z.literal("y minus x").optional(),
  independentGroups: z.boolean().optional(),
  scale: z.number().positive().max(1000000).optional(),
  multiplicity: z.enum(["none", "holm"]).default("none"),
  designStatement: z.string().trim().min(10).max(1000),
  design: z.object({
    population: z.enum(["sample", "census"]),
    unit: z.enum(["individual", "area", "aggregate", "pair"]),
    independent: z.boolean(),
    weighted: z.boolean().default(false),
    clustered: z.boolean().default(false),
    disjoint: z.boolean().optional(),
  }),
});
export type StatisticSpec = z.infer<typeof statisticSpec>;
export type StatisticalRow = {
  label: string;
  metric: string;
  unit: string;
  estimate: number | null;
  lower: number | null;
  upper: number | null;
  p: number | null;
};
export type StatisticalResult = {
  kind: "statistical-result";
  id: string;
  engine: string;
  method: string;
  title: string;
  unit: string;
  claims: Record<string, { value: number; display: string; label: string; unit: string }>;
  rows: StatisticalRow[];
  plot: StatisticalRow[];
  diagnostics: Record<string, unknown>;
  notes: string[];
  narrative: string[];
  software: Record<string, string>;
  parameters: StatisticSpec;
  inputRows: number;
  excluded: number;
  provenance: {
    name: string;
    sourceId?: string;
    originalHash?: string;
    derivedHash?: string;
    table?: string;
    capturedAt: string;
    metadata?: Record<string, unknown>;
  };
  inputHash: string;
  resultHash: string;
};
export type StatisticalManifest = {
  spec: StatisticSpec;
  rows: Record<string, unknown>[];
  provenance: StatisticalResult["provenance"];
};
export type SavedStatistic = { result: StatisticalResult; manifest: StatisticalManifest };
export function collectStatistics(payloads: unknown[]): StatisticalResult[] {
  return payloads.flatMap((p) =>
    p && typeof p === "object" && (p as StatisticalResult).kind === "statistical-result"
      ? [p as StatisticalResult]
      : [],
  );
}
