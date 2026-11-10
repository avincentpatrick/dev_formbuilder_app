import type { MapRow } from "./prototype";
import type { ColorBin } from "@/lib/charts/color-scale";

export const INCOME_SOURCE_SLUG = "dof-blgf-income-2024";
/** Published categories, not computed bins, income amounts or relative ranks. */
export const INCOME_CLASSES = [
  { value: 1, label: "First class", color: "#0072b2" },
  { value: 2, label: "Second class", color: "#e69f00" },
  { value: 3, label: "Third class", color: "#009e73" },
  { value: 4, label: "Fourth class", color: "#cc79a7" },
  { value: 5, label: "Fifth class", color: "#56b4e9" },
];
export const INCOME_BINS: ColorBin[] = INCOME_CLASSES.map((c) => ({
  min: c.value,
  max: c.value,
  color: c.color,
}));
export function incomeClassLabel(value: number | null): string | null {
  return INCOME_CLASSES.find((c) => c.value === value)?.label ?? null;
}
/** Match only accepted reclassification rows; never consult old_class_dof or dim_geo.income_class. */
export function joinIncomeRows(
  children: { geo_code: string; geo_name: string }[],
  rows: Record<string, unknown>[],
): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of rows) {
    if (typeof row.geo_code !== "string") continue;
    index.set(row.geo_code, [...(index.get(row.geo_code) ?? []), row]);
  }
  return children.map((child) => {
    const matches = index.get(child.geo_code) ?? [];
    const row = matches.length === 1 ? matches[0] : undefined;
    const base = { code: child.geo_code, name: child.geo_name, value: null };
    if (!row)
      return {
        ...base,
        note: matches.length ? "Ambiguous source records" : "No matched source record",
      };
    if (row.new_class === null) return { ...base, note: "Unclassified in source" };
    if (
      typeof row.new_class !== "number" ||
      !incomeClassLabel(row.new_class) ||
      !["province", "city", "municipality"].includes(String(row.dof_kind))
    )
      return { ...base, note: "Invalid source classification" };
    return {
      ...base,
      value: row.new_class,
      note: `Source LGU type: ${row.dof_kind}${row.converted === true ? "; classified as municipality in source" : ""}`,
    };
  });
}
