import "server-only";
import { readFile } from "node:fs/promises";
import path from "node:path";
import { cache } from "react";
import { getNhfrCounts, getNhfrFacilities, getNhfrChildGeos } from "./nhfr";
import { getGeoByCode, getGeoAncestors } from "./geo";
import { getProvinceBarangayCentroids } from "@/lib/geo/barangay-centroids";
import { withBasePath } from "@/lib/base-path";
import { validExploration, type ExplorationInput } from "@/lib/facilities/exploration";
export const getNhfrExploration = cache(async (code: string): Promise<ExplorationInput | null> => {
  try {
    const [geo, counts, facilities, barangays, ancestors] = await Promise.all([
      getGeoByCode(code),
      getNhfrCounts(code, "citymun"),
      getNhfrFacilities(code),
      getNhfrChildGeos(code, "citymun"),
      getGeoAncestors(code, "citymun"),
    ]);
    if (!geo || geo.geoLevel !== "citymun" || !counts || !facilities) return null;
    const centroids = ancestors.province
      ? await getProvinceBarangayCentroids(ancestors.province.geoCode)
      : new Map<string, [number, number]>();
    let outline: unknown[] = [];
    if (ancestors.province && /^[0-9]+$/.test(ancestors.province.geoCode)) {
      try {
        const file = JSON.parse(
          await readFile(
            path.join(
              process.cwd(),
              "public",
              "geo",
              "citymun",
              `${ancestors.province.geoCode}.json`,
            ),
            "utf8",
          ),
        );
        outline = (file.features ?? [])
          .filter((f: { properties?: { geo_code?: string } }) => f.properties?.geo_code === code)
          .map((f: { geometry: unknown }) => f.geometry);
      } catch {
        /* An unavailable outline does not invalidate the inventory. */
      }
    }
    const input: ExplorationInput = {
      outline,
      code,
      name: geo.geoName,
      expected: counts.nFacilities,
      expectedBarangays: counts.nBarangays,
      facilities,
      barangays,
      centroids: [...centroids].filter(([key]) => barangays.some((b) => b.geoCode === key)),
      outlineUrl: ancestors.province
        ? withBasePath(`/geo/citymun/${ancestors.province.geoCode}.json`)
        : null,
    };
    return validExploration(input) ? input : null;
  } catch {
    return null;
  }
});
