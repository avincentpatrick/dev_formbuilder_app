import type { GeoAncestors } from "@/lib/db/geo";
import type { MapScope } from "./prototype";
export type NamedMapScope = MapScope & { name: string };

/** Only resolved ancestor records establish hierarchy; never infer parents from PSGC prefixes. */
export function mapParents(scope: MapScope, ancestors: GeoAncestors | null) {
  const levels =
    scope.level === "citymun"
      ? (["region", "province"] as const)
      : scope.level === "province"
        ? (["region"] as const)
        : [];
  const parents: NamedMapScope[] = [];
  for (const level of levels) {
    const geo = ancestors?.[level];
    if (geo?.geoLevel === level && geo.geoCode !== scope.code && geo.geoName.trim())
      parents.push({ level, code: geo.geoCode, name: geo.geoName });
  }
  return { parents, complete: parents.length === levels.length };
}
