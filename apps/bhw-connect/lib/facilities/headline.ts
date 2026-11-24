import { z } from "zod";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import { nhfrReading } from "@/lib/catalog/nhfr-reading";
import { catalogHref } from "@/lib/catalog/navigation";

const count = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER);
export const headlineSchema = z
  .object({
    total: count,
    count,
    government: count,
    private: count,
    beds: count,
    present: count,
    barangays: count,
    unattributed: count,
    types: z.array(z.string().min(1).max(200)).max(200),
  })
  .refine(
    (r) =>
      r.count <= r.total &&
      r.government + r.private === r.count &&
      r.present <= r.barangays &&
      r.present <= r.count - r.unattributed &&
      r.unattributed <= r.count &&
      new Set(r.types).size === r.types.length,
  );
export type HeadlineSummary = z.infer<typeof headlineSchema>;

/** Partial/withheld/invalid accepted figures cannot become filterable full totals. */
export function acceptedHeadline(dataset: CatalogDataset, selection: CatalogSelection) {
  const get = (id: string) => {
    const item = dataset.indicators.find((i) => i.id === `nhfr.${id}`);
    return item?.state === "available" && nhfrReading(item, selection) ? item.values[0] : null;
  };
  const facilities = get("facilities"),
    government = get("government"),
    privateCount = get("private"),
    beds = get("beds"),
    presence = get("barangay-presence");
  if (
    !facilities ||
    !government ||
    !privateCount ||
    !beds ||
    !presence ||
    presence.denominator == null
  )
    return null;
  const parsed = headlineSchema.safeParse({
    total: facilities.value,
    count: facilities.value,
    government: government.value,
    private: privateCount.value,
    beds: beds.value,
    present: presence.value,
    barangays: presence.denominator,
    unattributed: 0,
    types: [],
  });
  return parsed.success ? parsed.data : null;
}

export function headlineQuery(selection: CatalogSelection, types: string[] = []) {
  const params = new URLSearchParams(catalogHref(selection).split("?")[1]);
  for (const type of [...new Set(types)].sort()) params.append("type", type);
  return params;
}

export function summaryMatches(actual: HeadlineSummary, accepted: HeadlineSummary) {
  return (
    actual.total === accepted.total &&
    actual.count === accepted.count &&
    actual.government === accepted.government &&
    actual.private === accepted.private &&
    actual.beds === accepted.beds &&
    actual.present === accepted.present &&
    actual.barangays === accepted.barangays
  );
}
