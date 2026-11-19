import Link from "next/link";
import { contextReview, type ContextRow } from "@/lib/bhw/context-review";
import { contextHref, type ContextChoice } from "@/lib/bhw/context";
const number = (v: number) => v.toLocaleString("en", { maximumFractionDigits: 1 });
export function ContextReview({
  rows,
  choice,
  label,
  unit,
}: {
  rows: ContextRow[];
  choice: ContextChoice;
  label: string;
  unit: string;
}) {
  const review = contextReview(rows);
  const lists = [
    {
      title: "Check reporting gaps",
      rows: review.gaps,
      reason: (r: ContextRow) =>
        `${r.headcount === null ? "Headcount unavailable or flagged. " : ""}${r.context === null ? `${label} unavailable or flagged. ` : ""}Excluded from paired comparisons; this is not a zero-workforce finding.`,
      empty: "No unusable pair in the returned observations.",
    },
    {
      title: "Check reported zero headcounts",
      rows: review.zeros,
      reason: (r: ContextRow) =>
        `The source records zero BHWs and ${number(r.context!)} ${unit}. Confirm the report before interpreting workforce presence.`,
      empty: "No eligible place reports zero headcount.",
    },
    {
      title: "Compare lower headcount with larger context",
      rows: review.contrasts,
      reason: (r: ContextRow) =>
        `${number(r.headcount!)} BHWs is below the paired-place median ${number(review.headMedian!)}; ${number(r.context!)} ${unit} is above the paired-place median ${number(review.contextMedian!)}.`,
      empty:
        review.eligibleCount < 4
          ? "At least four paired places are required for this exploratory comparison."
          : "No place meets both strict comparisons. Equal-to-median values are not included.",
    },
  ];
  return (
    <section aria-label="Places for closer examination" className="space-y-4">
      <h2 className="text-xl font-semibold">Places for closer examination</h2>
      <p>
        These lists explain checks to make, not where staff must be added. They use the current
        returned geography and {review.eligibleCount} eligible pairs. Lists can overlap, are
        alphabetical and are not a combined score or priority ranking.
      </p>
      <p className="text-sm">
        The median is the middle value among eligible places (the average of the two middle values
        for an even number). Comparisons use unrounded values; the thresholds are exploratory, not
        official staffing standards. Larger population or household totals do not measure individual
        BHW workload.
      </p>
      {lists.map((list) => (
        <section key={list.title} className="space-y-2 rounded border border-border p-3">
          <h3 className="font-semibold">
            {list.title} · {list.rows.length}
          </h3>
          {list.rows.length ? (
            <div
              role="region"
              aria-label={list.title}
              tabIndex={0}
              className="max-h-80 overflow-auto"
            >
              <ul className="space-y-3">
                {list.rows.map((row) => (
                  <li key={row.code}>
                    <Link
                      className="inline-block min-h-11 underline"
                      href={contextHref({ ...choice, map: { ...choice.map, selected: row.code } })}
                    >
                      {row.name} ({row.code})
                    </Link>
                    <p className="text-sm">{list.reason(row)}</p>
                  </li>
                ))}
              </ul>
            </div>
          ) : (
            <p>{list.empty}</p>
          )}
        </section>
      ))}
    </section>
  );
}
