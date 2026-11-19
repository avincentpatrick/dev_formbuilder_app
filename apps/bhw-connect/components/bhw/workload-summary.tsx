import type { CatalogDataset } from "@/lib/catalog/types";
import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";
import { scalar } from "@/lib/bhw/overview";
import { formatValue } from "@/components/catalog/presentation";

export function WorkloadSummary({ dataset }: { dataset: CatalogDataset }) {
  const values = ["p25", "median", "p75"].map((stat) => {
    const item = dataset.indicators.find((i) => i.id === `bhw.workload.${stat}`);
    return item?.sourceSlug === ACCEPTED_RELEASES.bhw && item.unit === "households"
      ? scalar(item)
      : null;
  });
  const [lower, middle, upper] = values;
  const ordered =
    lower !== null &&
    middle !== null &&
    upper !== null &&
    lower > 0 &&
    lower <= middle &&
    middle <= upper;
  return (
    <div className="rounded-lg border border-border bg-surface p-4 space-y-2">
      {middle !== null && middle > 0 ? (
        <p>
          The middle recorded assignment (median) is{" "}
          <strong>{formatValue({ value: middle }, "households")} per worker</strong>.
        </p>
      ) : (
        <p>A public middle assignment is unavailable here. See the detailed statuses below.</p>
      )}
      {ordered && (
        <p>
          The middle half runs from {formatValue({ value: lower }, "households")} to{" "}
          {formatValue({ value: upper }, "households")}. These are the 25th and 75th percentile
          boundaries; ties can put more than half of records within them.
        </p>
      )}
      <p className="text-sm text-muted">
        BHW Profiles 2025, using positive recorded household assignments. Missing and zero
        assignments are excluded. This describes recorded assignments, not actual visits, staffing
        needs, or the area’s total households divided by its workforce.
      </p>
    </div>
  );
}
