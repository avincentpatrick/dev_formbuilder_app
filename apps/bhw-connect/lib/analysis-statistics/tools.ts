import "server-only";
import { z } from "zod";
import type { Tool } from "@/lib/ai/tools";
import { sourceCall } from "@/lib/analysis-sources/db";
import type { ProjectSource } from "@/lib/analysis-sources/types";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import { getRegisteredDataset } from "@/lib/db/dataset-registry";
import { executeStatistic } from "./execute";
import { statisticSpec, type StatisticalManifest, type SavedStatistic } from "./types";
const input = z.object({
  sourceId: z.uuid().optional(),
  tableIndex: z.number().int().min(0).default(0),
  datasetTable: z
    .string()
    .regex(/^[a-z][a-z0-9_]*$/)
    .optional(),
  filters: z
    .array(
      z.object({
        column: z.string().min(1).max(100),
        value: z.union([z.string().max(200), z.number(), z.boolean()]),
      }),
    )
    .max(20)
    .default([]),
  spec: statisticSpec,
});
// The existing Gemini adapter accepts the common OpenAPI schema subset, not JSON Schema's
// $schema/default/additionalProperties keywords. Runtime Zod validation remains authoritative.
function providerSchema(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(providerSchema);
  if (!value || typeof value !== "object") return value;
  const result: Record<string, unknown> = {};
  for (const [key, child] of Object.entries(value)) {
    if (key === "properties")
      result[key] = Object.fromEntries(
        Object.entries(child as Record<string, unknown>).map(([name, schema]) => [
          name,
          providerSchema(schema),
        ]),
      );
    else if (
      [
        "type",
        "items",
        "required",
        "enum",
        "description",
        "minimum",
        "maximum",
        "minItems",
        "maxItems",
      ].includes(key)
    )
      result[key] = providerSchema(child);
  }
  return result;
}
export function createStatisticalTools(
  actor: string,
  project: string,
  onSaved: (saved: SavedStatistic) => void,
): Tool[] {
  return [
    {
      definition: {
        name: "calculateStatistics",
        description:
          "Execute a fixed reproducible statistical method over a complete project table or registered dashboard table, never a preview. Use describe for census/area/aggregate data; inference needs documented independent sample observations. Inspect definitions first; ask only when design/units/pairing/denominator facts are missing. Do not invent sampling design to obtain p-values. Supply sourceId OR datasetTable plus spec. Methods: describe, proportion, rate, time, welch, paired, mann_whitney, pearson, spearman, chi_square, fisher, ols, logistic, poisson. Use wide numeric columns x/y for two-group comparisons; categorical individual rows for contingency tests. No arbitrary code or SQL. Numeric narrative claims MUST use [[resultID:fieldID]] tokens returned in claims; do not write calculated numbers from memory.",
        parameters: providerSchema(z.toJSONSchema(input)) as Tool["definition"]["parameters"],
      },
      execute: async (args) => {
        try {
          const parsed = input.parse(args);
          if (Boolean(parsed.sourceId) === Boolean(parsed.datasetTable))
            throw new Error("Choose exactly one attachment or registered dashboard table");
          // Fresh project access, including for dashboard-only calculations.
          await sourceCall(actor, project, "access");
          let rows: Record<string, unknown>[], provenance: StatisticalManifest["provenance"];
          if (parsed.sourceId) {
            const source = (await sourceCall(actor, project, "get", {
              sourceId: parsed.sourceId,
            })) as unknown as ProjectSource;
            if (source.status !== "ready") throw new Error("Source is not ready");
            const table = source.content?.tables[parsed.tableIndex];
            if (!table) throw new Error("Structured table not found");
            const measurements = [
              parsed.spec.x,
              parsed.spec.y,
              ...(parsed.spec.predictors ?? []),
            ].filter(Boolean);
            if (
              table.profile?.columns.some(
                (column) => measurements.includes(column.name) && column.unit === "mixed",
              ) &&
              (!source.metadata.unit || source.metadata.unit !== parsed.spec.unit)
            )
              throw new Error(
                "Mixed measurement units need documented source definitions before calculation.",
              );
            rows = table.rows;
            provenance = {
              name: source.name,
              sourceId: source.id,
              originalHash: source.sha256,
              derivedHash: source.content?.derivedHash,
              capturedAt: source.created_at,
              table: table.name,
              metadata: {
                ...source.metadata,
                filters: parsed.filters,
                qualityWarnings: source.content?.profile?.warnings ?? [],
                preparations: source.content?.preparations ?? [],
                tableProfile: table.profile ?? null,
              },
            };
          } else {
            const dataset = await getRegisteredDataset(parsed.datasetTable!, "internal");
            if (!dataset) throw new Error("Approved dataset is unavailable");
            const columns = [
              ...new Set(
                [
                  parsed.spec.x,
                  parsed.spec.y,
                  parsed.spec.group,
                  parsed.spec.pairId,
                  parsed.spec.exposure,
                  ...(parsed.spec.predictors ?? []),
                  ...parsed.filters.map((filter) => filter.column),
                ].filter((c): c is string => Boolean(c)),
              ),
            ];
            if (
              !columns.length ||
              columns.some((c) => !dataset.columns.some((d) => d.name === c && d.isQueryable))
            )
              throw new Error("Select approved queryable columns only");
            const client = createSupabaseServiceClient();
            // One database statement/snapshot, not offset pagination over changing data. Abort on API row caps.
            let query = client
              .from(dataset.tableName as "dim_geo")
              .select(columns.join(","), { count: "exact" })
              .range(0, 49999);
            for (const { column, value } of parsed.filters) query = query.eq(column, value);
            const { data, error, count } = await query;
            if (error || count === null || count > 50000 || data?.length !== count)
              throw new Error(
                "Complete table could not be read within processing/API limits. Apply explicit filters or attach a complete exported table; no preview was analysed.",
              );
            rows = data as unknown as Record<string, unknown>[];
            provenance = {
              name: dataset.title,
              table: dataset.tableName,
              capturedAt: new Date().toISOString(),
              metadata: {
                grain: dataset.grain,
                notes: dataset.notesMd,
                filters: parsed.filters,
                dictionary: dataset.columns.filter((c) => columns.includes(c.name)),
              },
            };
          }
          if (parsed.filters.length && parsed.sourceId)
            rows = rows.filter((row) =>
              parsed.filters.every(({ column, value }) => row[column] === value),
            );
          if (rows.length > 50000) throw new Error("Too many input rows; specify narrower filters");
          const manifest: StatisticalManifest = { spec: parsed.spec, rows, provenance };
          const result = await executeStatistic(manifest);
          if (result.kind === "statistical-result") {
            onSaved({ result, manifest });
            return result;
          }
          return result;
        } catch (e) {
          return {
            kind: "unsupported-statistical-analysis",
            error:
              e instanceof z.ZodError
                ? "Invalid statistical specification. Supply method, title, unit, designStatement and design (population, unit, independent)."
                : e instanceof Error
                  ? e.message
                  : "Calculation unavailable",
          };
        }
      },
    },
  ];
}
