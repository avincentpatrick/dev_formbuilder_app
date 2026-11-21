import type { NhfrFacility } from "@/lib/db/nhfr";

export function facilitySearchQuery(value: string | string[] | undefined): string {
  return typeof value === "string" ? value.trim().slice(0, 200) : "";
}

/** Filters public list fields only; never changes the area's total, map or downloads. */
export function searchFacilities(facilities: NhfrFacility[], query: string): NhfrFacility[] {
  const words = query.toLocaleLowerCase("en-PH").split(/\s+/).filter(Boolean);
  return facilities
    .filter((facility) => {
      const text = [facility.facilityName, facility.facilityType, facility.barangayName ?? ""]
        .join(" ")
        .toLocaleLowerCase("en-PH");
      return words.every((word) => text.includes(word));
    })
    .sort(
      (a, b) =>
        a.facilityName.localeCompare(b.facilityName, "en", { numeric: true }) ||
        a.facilityCode.localeCompare(b.facilityCode),
    );
}
