import type { PublicValue } from "@/lib/catalog/types";

// Order only categories whose sequence is defined by the accepted source.
// Do not fill absent categories: missing or withheld observations are not zero.
const ageBands = ["<30", "30-39", "40-49", "50-59", "60+"];
const paymentBands = [
  "None",
  "₱1-4,000",
  "₱4,001-8,000",
  "₱8,001-12,000",
  "₱12,001-16,000",
  "₱16,001-20,000",
  "₱20,001-24,000",
  "Over ₱24,000",
];

export function categoryAxis(id: string): string | undefined {
  if (id === "bhw.demographics.age_band") return "Age group";
  if (id.startsWith("bhw.cohorts.")) return "Year";
  if (id === "bhw.combined-honorarium.bands") return "Monthly allowance band";
}

export function orderedCategoryValues(id: string, values: PublicValue[]): PublicValue[] {
  if (!categoryAxis(id)) return values;
  const rank = (label: string | undefined): number => {
    if (id.startsWith("bhw.cohorts."))
      return label && /^\d{4}$/.test(label) ? Number(label) : Infinity;
    const index = (id === "bhw.demographics.age_band" ? ageBands : paymentBands).indexOf(
      label ?? "",
    );
    return index < 0 ? Infinity : index;
  };
  // Stable sort keeps unrecognized source labels at the end without dropping them.
  return [...values].sort((a, b) => {
    const left = rank(a.label);
    const right = rank(b.label);
    return left === right ? 0 : left < right ? -1 : 1;
  });
}
