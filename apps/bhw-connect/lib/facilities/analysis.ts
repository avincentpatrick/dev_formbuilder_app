import type { NhfrChild, NhfrTypeCount } from "@/lib/db/nhfr";

export const validCount = (n: number) => Number.isSafeInteger(n) && n >= 0;

/** A complete partition is required before deriving shares or comparing categories. */
export function facilityMix(items: NhfrTypeCount[] | null, total: number) {
  if (
    !items ||
    !validCount(total) ||
    total === 0 ||
    new Set(items.map((i) => i.facilityType)).size !== items.length ||
    items.some(
      (i) =>
        !i.facilityType ||
        ![i.nFacilities, i.nGovernment, i.nPrivate].every(validCount) ||
        i.nGovernment + i.nPrivate !== i.nFacilities,
    ) ||
    items.reduce((sum, i) => sum + i.nFacilities, 0) !== total
  )
    return null;
  return [...items].sort(
    (a, b) => b.nFacilities - a.nFacilities || a.facilityType.localeCompare(b.facilityType),
  );
}

/** Never rank an incomplete child roster or silently substitute rounded percentages. */
export function facilityPlaces(items: NhfrChild[], total: number, expectedCodes: string[]) {
  if (
    !expectedCodes.length ||
    !validCount(total) ||
    items.length !== expectedCodes.length ||
    new Set(items.map((i) => i.geoCode)).size !== items.length ||
    items.some(
      (i) =>
        !expectedCodes.includes(i.geoCode) ||
        ![i.nFacilities, i.nBarangays, i.nBarangaysWithFacility].every(validCount) ||
        i.nBarangaysWithFacility > i.nBarangays,
    ) ||
    items.reduce((sum, i) => sum + i.nFacilities, 0) !== total
  )
    return null;
  return items.map((i) => ({
    ...i,
    presence: i.nBarangays > 0 ? (100 * i.nBarangaysWithFacility) / i.nBarangays : null,
  }));
}
