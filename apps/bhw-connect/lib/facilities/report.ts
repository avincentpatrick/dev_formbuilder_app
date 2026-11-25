import { z } from "zod";
import { geoLevelSchema } from "@/lib/filters/schema";
import { headlineSchema, type HeadlineSummary } from "./headline";
import { facilityMix, validCount } from "./analysis";
import type { NhfrTypeCount } from "@/lib/db/nhfr";
import type { AreaFigures } from "./area-figures";
export const REPORT_SCOPE = "All facility types. Individual card filters do not apply.";
export const REPORT_SNAPSHOT = "September 2026";
export const REPORT_SOURCE = "DOH National Health Facility Registry";
export const REPORT_ROWS = 6;
export type ReportLevel = "national" | "region" | "province" | "citymun";
export type FacilityReport = {
  code: string;
  level: ReportLevel;
  name: string;
  summary: HeadlineSummary;
  types: NhfrTypeCount[];
  areas: AreaFigures | null;
};
export const reportStatusSchema = z.object({
  code: z.string(),
  level: z.enum(["national", "region", "province", "citymun"]),
  name: z.string().min(1),
  facilities: z.number().int().nonnegative(),
  types: z.number().int().nonnegative(),
  areas: z.number().int().nonnegative(),
});
export function parseReportQuery(params: URLSearchParams, download = false) {
  const allowed = download ? ["geoCode", "geoLevel", "format", "notes"] : ["geoCode", "geoLevel"];
  if ([...params.keys()].some((k) => !allowed.includes(k) || params.getAll(k).length !== 1))
    return null;
  const parsed = z
    .object({ geoCode: z.string().regex(/^(PH|\d{2,10})$/), geoLevel: geoLevelSchema })
    .safeParse({ geoCode: params.get("geoCode"), geoLevel: params.get("geoLevel") });
  if (
    !parsed.success ||
    parsed.data.geoLevel === "barangay" ||
    (parsed.data.geoLevel === "national") !== (parsed.data.geoCode === "PH")
  )
    return null;
  return { code: parsed.data.geoCode, level: parsed.data.geoLevel };
}
/** Reject a report if any table or accepted headline is incomplete. */
export function validateReport(r: FacilityReport | null): r is FacilityReport {
  if (
    !r ||
    !r.code ||
    !r.name ||
    !parseReportQuery(new URLSearchParams({ geoCode: r.code, geoLevel: r.level })) ||
    !headlineSchema.safeParse(r.summary).success ||
    r.summary.count !== r.summary.total
  )
    return false;
  const types =
    r.summary.count > 0 ? facilityMix(r.types, r.summary.count) : r.types.length === 0 ? [] : null;
  if (
    !types ||
    r.types.some((t) => !r.summary.types.includes(t.facilityType)) ||
    r.types.reduce((n, t) => n + t.nGovernment, 0) !== r.summary.government ||
    r.types.reduce((n, t) => n + t.nPrivate, 0) !== r.summary.private
  )
    return false;
  if (r.level === "citymun") return r.areas === null;
  const a = r.areas;
  const child = { national: "region", region: "province", province: "citymun" }[r.level];
  return (
    !!a &&
    a.rows.length > 0 &&
    a.parent === r.summary.count &&
    a.count === r.summary.count &&
    a.present === r.summary.present &&
    a.barangays === r.summary.barangays &&
    new Set(a.rows.map((x) => x.code)).size === a.rows.length &&
    a.rows.every(
      (x) =>
        x.level === child &&
        [x.count, x.government, x.private, x.present, x.barangays].every(validCount) &&
        x.government + x.private === x.count &&
        x.present <= x.count &&
        x.present <= x.barangays,
    ) &&
    a.selectedAreas === a.rows.length &&
    a.rows.reduce((n, x) => n + x.count, 0) === r.summary.count &&
    a.rows.reduce((n, x) => n + x.present, 0) === r.summary.present &&
    a.rows.reduce((n, x) => n + x.barangays, 0) === r.summary.barangays &&
    a.rows.every(
      (x) =>
        x.facilityShare === (r.summary.count > 0 ? (100 * x.count) / r.summary.count : null) &&
        x.presenceShare === (x.barangays > 0 ? (100 * x.present) / x.barangays : null),
    ) &&
    a.rows.reduce((n, x) => n + x.government, 0) === r.summary.government &&
    a.rows.reduce((n, x) => n + x.private, 0) === r.summary.private
  );
}
export function reportStatus(r: FacilityReport) {
  return {
    code: r.code,
    level: r.level,
    name: r.name,
    facilities: r.summary.count,
    types: r.types.length,
    areas: r.areas?.rows.length ?? 0,
  };
}
