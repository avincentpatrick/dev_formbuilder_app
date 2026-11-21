import { NHFR_DEFINITION } from "@/lib/catalog/nhfr-reading";
import { StatValue } from "@/components/home/stat-value";
import { countAndShare, shareOf } from "@/lib/format";
import type { NhfrCounts } from "@/lib/db/nhfr";

/**
 * The headline count and ownership split. Types appear once in the full breakdown.
 *
 * **No "% licensed" tile, deliberately.** 63% of the register carries no licensing status at all
 * — overwhelmingly Barangay Health Stations, which are not a licensed facility type — so any
 * percentage would divide by a denominator this export cannot support and would read as a
 * compliance figure. The facility list states each facility's status where the source states one.
 */
export function FacilityStats({ counts }: { counts: NhfrCounts }) {
  return (
    <div role="group" aria-label="Facility totals" className="flex flex-col gap-5">
      <p className="text-xs text-muted">{NHFR_DEFINITION}</p>
      <div className="space-y-1">
        <StatValue value={counts.nFacilities.toLocaleString("en-PH")} />
        <span className="text-muted">
          {counts.nFacilities === 1 ? "health facility" : "health facilities"}
        </span>
      </div>

      <dl className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <div className="flex items-center gap-2">
          <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-accent" />
          <dt className="text-muted">Government</dt>
          <dd className="whitespace-nowrap font-semibold tabular-nums">
            {countAndShare(counts.nGovernment, shareOf(counts.nGovernment, counts.nFacilities))}
          </dd>
        </div>
        <div className="flex items-center gap-2">
          <span
            aria-hidden="true"
            className="h-2.5 w-2.5 rounded-full bg-surface ring-1 ring-border"
          />
          <dt className="text-muted">Private</dt>
          <dd className="whitespace-nowrap font-semibold tabular-nums">
            {countAndShare(counts.nPrivate, shareOf(counts.nPrivate, counts.nFacilities))}
          </dd>
        </div>
      </dl>
    </div>
  );
}
