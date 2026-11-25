import "server-only";
import { readNhfrHeadline } from "./nhfr-headline";
import { getNhfrTypes } from "./nhfr";
import { getNhfrDistribution } from "./nhfr-distribution";
import { getNhfrPresence } from "./nhfr-presence";
import { selectAreaFigures } from "@/lib/facilities/area-figures";
import { validateReport, type FacilityReport, type ReportLevel } from "@/lib/facilities/report";
export async function readNhfrReport(
  code: string,
  level: ReportLevel,
): Promise<FacilityReport | null> {
  try {
    const [summary, types, distribution, presence] = await Promise.all([
      readNhfrHeadline({ mode: "psgc", code, level }, []),
      getNhfrTypes(code, level),
      level === "citymun" ? Promise.resolve(null) : getNhfrDistribution(code, level),
      level === "citymun" ? Promise.resolve(null) : getNhfrPresence(code, level),
    ]);
    if (!summary || !types) return null;
    const areas =
      level === "citymun" ? null : selectAreaFigures(distribution, presence, [], [], "name");
    if (
      level !== "citymun" &&
      (!distribution ||
        !areas ||
        distribution.types.length !== types.length ||
        types.some(
          (t) =>
            !distribution.types.some(
              (d) =>
                d.type === t.facilityType &&
                d.count === t.nFacilities &&
                d.government === t.nGovernment &&
                d.private === t.nPrivate,
            ),
        ))
    )
      return null;
    const report: FacilityReport = {
      code,
      level,
      name: summary.locationName,
      summary,
      types: [...types].sort((a, b) => a.facilityType.localeCompare(b.facilityType, "en")),
      areas,
    };
    return validateReport(report) ? report : null;
  } catch {
    return null;
  }
}
