import type { ReactNode } from "react";
import type { HeadlineSummary } from "@/lib/facilities/headline";
import { formatCount, formatPct, percentageFormatter, shareOf } from "@/lib/format";
import { StatValue } from "@/components/home/stat-value";
import {
  NHFR_DEFINITION,
  NHFR_BEDS,
  NHFR_PRESENCE,
  NHFR_ATTRIBUTION,
} from "@/lib/catalog/nhfr-reading";

export function HeadlineNote({ children }: { children: ReactNode }) {
  return (
    <details className="mt-3 text-sm text-muted print:hidden">
      <summary className="min-h-11 cursor-pointer content-center font-medium hover:text-accent">
        About this figure
      </summary>
      <div className="mt-2 space-y-2 rounded-md bg-surface p-3">{children}</div>
    </details>
  );
}
export function HeadlineBar({
  first,
  total,
  second = false,
  label,
}: {
  first: number;
  total: number;
  second?: boolean;
  label: string;
}) {
  if (total <= 0) return null;
  return (
    <div role="img" aria-label={label} className="my-3 flex h-3 overflow-hidden rounded bg-border">
      <span className="h-full bg-accent" style={{ width: `${(first / total) * 100}%` }} />
      {second && (
        <span
          className="h-full bg-muted/50"
          style={{ width: `${((total - first) / total) * 100}%` }}
        />
      )}
    </div>
  );
}
export function HeadlineValues({
  summary: s,
  types,
  locationName,
  presenting = false,
}: {
  summary: HeadlineSummary;
  types: string[];
  locationName: string;
  presenting?: boolean;
}) {
  const filtered = types.length > 0;
  const presence = shareOf(s.present, s.barangays);
  const ownershipFormat = percentageFormatter(
    s.count > 0 ? [(s.government / s.count) * 100, (s.private / s.count) * 100] : [],
  );
  const hideBeds = filtered && types.every((t) => t === "Barangay Health Station");
  return (
    <div className={presenting ? "[&_details]:hidden" : undefined}>
      <div className="grid min-w-0 gap-6 @xl:grid-cols-[0.85fr_1.15fr]">
        <section className="min-w-0">
          <h3 className="text-sm font-medium">
            {filtered
              ? types.length === 1
                ? `Registered facilities: ${types[0]}`
                : "Registered facilities of the selected types"
              : "Registered facilities"}
          </h3>
          <StatValue hero value={formatCount(s.count)} />
          <p className="mt-2 text-sm">
            The registry lists {formatCount(s.count)}{" "}
            {filtered ? "facilities of the selected types" : "health facilities"} in {locationName}.
          </p>
          {filtered && (
            <div className="mt-4">
              <p className="text-sm">
                <strong>
                  {s.total > 0
                    ? formatPct((s.count / s.total) * 100, "single")
                    : "Percentage unavailable"}
                </strong>{" "}
                of all registered facilities here
              </p>
              <HeadlineBar
                first={s.count}
                total={s.total}
                label={`${s.count} of ${s.total} facilities are of the selected types`}
              />
            </div>
          )}
          <HeadlineNote>
            <p>{NHFR_DEFINITION}</p>
            <p>
              These figures do not show staffing, service quality or whether capacity is enough.
            </p>
            {filtered && (
              <p>
                Selected facility types are combined using OR: each facility is counted once. The
                share uses all {formatCount(s.total)} registered facilities in this location.
              </p>
            )}
          </HeadlineNote>
        </section>
        <section className="min-w-0 border-t border-border pt-5 @xl:border-0 @xl:pt-0">
          <h3 className="text-sm font-medium">
            Barangays with a recorded {filtered ? "facility of the selected types" : "facility"}
          </h3>
          <StatValue
            value={presence === null ? "Percentage unavailable" : formatPct(presence, "single")}
          />
          <p className="mt-1 text-sm text-muted">
            {formatCount(s.present)} of {formatCount(s.barangays)} barangays
          </p>
          <p className="mt-2 text-sm">
            {presence === null
              ? "No barangay denominator is available for this area."
              : s.present === 0
                ? "The registry attributes no facilities in this selection to a barangay here."
                : `${formatPct(presence, "single")} of barangays have at least one ${filtered ? "facility of the selected types" : "facility"} recorded in the registry.`}
          </p>
          <HeadlineBar
            first={s.present}
            total={s.barangays}
            second
            label={`${s.present} barangays with an attributed facility; ${s.barangays - s.present} without one attributed`}
          />
          <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div>
              <dt>With a recorded facility</dt>
              <dd className="mt-1 font-semibold tabular-nums">{formatCount(s.present)}</dd>
            </div>
            <div>
              <dt>No facility attributed</dt>
              <dd className="mt-1 font-semibold tabular-nums">
                {formatCount(s.barangays - s.present)}
              </dd>
            </div>
          </dl>
          <HeadlineNote>
            <p>{NHFR_PRESENCE}</p>
            <p>{NHFR_ATTRIBUTION}</p>
            <p>
              Each barangay is counted once even when it contains several selected facility types.
              The denominator remains all barangays in this location.
            </p>
            {s.unattributed > 0 && (
              <p>
                {formatCount(s.unattributed)} selected facilities have no usable barangay
                attribution.
              </p>
            )}
          </HeadlineNote>
        </section>
      </div>
      <div
        className={`mt-5 grid min-w-0 gap-6 border-t border-border pt-5 ${hideBeds ? "" : "@xl:grid-cols-[1.15fr_0.85fr]"}`}
      >
        <section className="min-w-0">
          <h3 className="text-sm font-medium">Who owns these facilities?</h3>
          <HeadlineBar
            first={s.government}
            total={s.count}
            second
            label={`Government: ${s.government}; private: ${s.private}`}
          />
          <dl className="grid grid-cols-2 gap-3 text-sm">
            {[
              ["Government", s.government],
              ["Private", s.private],
            ].map(([label, value]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd className="mt-1 whitespace-nowrap font-semibold tabular-nums">
                  {formatCount(Number(value))}
                  {s.count > 0 ? ` (${ownershipFormat((Number(value) / s.count) * 100)})` : ""}
                </dd>
              </div>
            ))}
          </dl>
          <p className="mt-2 text-sm">
            {s.count > 0
              ? `${ownershipFormat((s.government / s.count) * 100)} of these registered facilities are government-owned.`
              : "There are no selected facilities to divide into ownership shares."}
          </p>
          <HeadlineNote>
            <p>
              Government and private describe ownership, not cost or eligibility for care. Both
              shares use the {formatCount(s.count)} facilities in this selection.
            </p>
          </HeadlineNote>
        </section>
        {!hideBeds && (
          <section className="min-w-0 border-t border-border pt-5 @xl:border-0 @xl:pt-0">
            <h3 className="text-sm font-medium">Recorded bed capacity</h3>
            <StatValue value={formatCount(s.beds)} />
            <p className="mt-2 text-sm">
              {s.beds > 0
                ? `Facilities in this selection report a combined capacity of ${formatCount(s.beds)} beds.`
                : "No bed capacity is recorded for this selection."}
            </p>
            <HeadlineNote>
              <p>{NHFR_BEDS}</p>
              <p>
                The source cleaning stores blank capacity as zero, so completeness cannot be
                determined from this total.
              </p>
            </HeadlineNote>
          </section>
        )}
      </div>
    </div>
  );
}
