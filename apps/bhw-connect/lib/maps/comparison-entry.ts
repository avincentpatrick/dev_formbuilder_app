import { barangayIndicatorSupported } from "./barangay-support";
import { comparisonHref } from "@/lib/bhw/comparison";
import { BHW_MEASURES } from "@/lib/bhw/search";
import { mapHref, type MapChoice, type MapPayload } from "./prototype";

/** Compare only a loaded child with the same public measure/source/period contract. */
export function bhwMapComparisonHref(
  choice: MapChoice,
  data: MapPayload | null | undefined,
): string | null {
  if (
    !data ||
    data.valueSupport === "navigation-only" ||
    !choice.selected ||
    !data.rows.some((row) => row.code === choice.selected)
  )
    return null;
  if (choice.dataset !== "bhw-2025" && choice.dataset !== "bhw-stepzero-2026") return null;
  if (
    data.childLevel === "barangay" &&
    !barangayIndicatorSupported(choice.dataset, choice.indicator)
  )
    return null;
  const spec = BHW_MEASURES.find((item) => item.id === choice.indicator);
  if (
    !spec ||
    spec.source !== choice.dataset ||
    choice.period !== "2025" ||
    !spec.levels.includes(data.childLevel)
  )
    return null;
  return comparisonHref(spec.id, [choice.selected], undefined, mapHref(choice).split("?")[1]);
}
