import Link from "next/link";
import { facilityContext, publishedInterval } from "@/lib/facilities/context";
import type { CatalogIndicator, PublicCatalog, CatalogId } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { formatCount, formatPct, percentageFormatter } from "@/lib/format";

function Note({ item }: { item: CatalogIndicator | null }) {
  return (
    <>
      {item?.state === "partial" && (
        <p className="mt-2 text-sm font-medium">Partial total — incomplete coverage</p>
      )}
      {item?.reason && <p className="mt-2 text-sm text-muted">{item.reason}</p>}
    </>
  );
}
export function FacilityContext({
  catalog,
  mapState,
}: {
  catalog: PublicCatalog;
  mapState?: string;
}) {
  const context = facilityContext(catalog);
  if (!context)
    return (
      <p className="text-sm text-muted">
        Linked comparisons are available for supported national, regional, provincial and municipal
        selections. No parent population or poverty figure is substituted for a barangay or
        district.
      </p>
    );
  const {
    facilities,
    residents,
    population,
    density,
    workforce,
    uuc,
    uucYear,
    uucShare,
    povertyItems,
  } = context;
  const href = (dataset: CatalogId) => catalogJourneyHref(catalog.selection, dataset, mapState);
  return (
    <section
      aria-label="Facilities in local context"
      className="space-y-4"
      data-testid="facility-context"
    >
      <h3 className="text-xl font-semibold">
        How do facilities relate to the people and programmes here?
      </h3>
      <p className="text-sm text-muted">
        These sources refer to the selected place but different years and reporting populations.
        They provide context, not evidence that facility counts cause poverty, that BHWs work at
        these facilities, or that listed communities lack a facility.
      </p>
      <div className="grid gap-4 md:grid-cols-2">
        <section
          className="min-w-0 rounded-lg border border-border p-4"
          aria-label="Population context"
        >
          <h4 className="font-semibold">Population and registered facilities</h4>
          <p className="mt-2 text-sm">
            PSA census, July 2024:{" "}
            <span className="whitespace-nowrap font-semibold">
              {residents ? `${formatCount(residents.values[0].value)} people` : "No usable count"}
            </span>
          </p>
          {population?.partial && (
            <p className="mt-2 text-sm font-medium">
              Partial population total — matched census areas only
            </p>
          )}
          <Note item={residents} />
          {density !== null ? (
            <>
              <p className="mt-3 font-semibold">
                <span className="whitespace-nowrap">
                  {density > 0 && density < 0.01
                    ? "<0.01"
                    : new Intl.NumberFormat("en-PH", { maximumFractionDigits: 2 }).format(density)}
                </span>{" "}
                registered facilities per 100,000 census residents
              </p>
              <p className="mt-2 text-sm">
                Calculated as {formatCount(facilities!.values[0].value)} facilities (September 2026)
                ÷ {formatCount(residents!.values[0].value)} residents (July 2024) × 100,000. This
                uses an older population denominator, not a 2026 population estimate.
              </p>
              <p className="mt-2 text-sm text-muted">
                Inventory density, not service capacity or access. Registry entries include
                different kinds and sizes of facilities; residents may seek care elsewhere.
              </p>
            </>
          ) : (
            <p className="mt-3 text-sm">
              No facilities-per-population rate is shown. It requires complete accepted municipal
              counts, a positive census denominator and verified source dates. Broader population
              totals may be partial.
            </p>
          )}
          <Link className="mt-3 inline-block text-sm underline" href={href("population")}>
            Explore population for this place →
          </Link>
        </section>
        <section
          className="min-w-0 rounded-lg border border-border p-4"
          aria-label="Workforce context"
        >
          <h4 className="font-semibold">BHW workforce alongside facilities</h4>
          <p className="mt-2 text-sm">
            BHW Headcount 2025, LGU-reported:{" "}
            <span className="whitespace-nowrap font-semibold">
              {workforce ? `${formatCount(workforce.values[0].value)} BHWs` : "No usable count"}
            </span>
          </p>
          <Note item={workforce} />
          <p className="mt-2 text-sm text-muted">
            This is the LGU headcount, not the individual profiling count. It does not identify
            facility assignments, staffing per facility or workload.
          </p>
          <Link className="mt-3 inline-block text-sm underline" href={href("bhw")}>
            Explore BHW data for this place →
          </Link>
        </section>
        <section
          className="min-w-0 rounded-lg border border-border p-4"
          aria-label="Primary care context"
        >
          <h4 className="font-semibold">Communities listed for primary care</h4>
          <p className="mt-2 text-sm">
            DOH UUC-PHC list{uucYear ? `, ${uucYear}` : ""}:{" "}
            <span className="whitespace-nowrap font-semibold">
              {uuc
                ? `${formatCount(uuc.values[0].value)} barangays${uucShare === null ? "" : ` (${formatPct(uucShare)})`}`
                : "No usable count"}
            </span>
          </p>
          {uucShare !== null && (
            <p className="mt-2 text-sm">
              Denominator: {formatCount(uuc!.values[0].denominator!)} barangays in this source’s
              reporting area.
            </p>
          )}
          <Note item={uuc} />
          <p className="mt-2 text-sm text-muted">
            UUC listing and NHFR facility presence measure different things. These totals do not
            identify how many listed barangays have a facility; that requires a validated
            barangay-level join.
          </p>
          <Link className="mt-3 inline-block text-sm underline" href={href("uuc-phc")}>
            Explore primary care listing for this place →
          </Link>
        </section>
        <section
          className="min-w-0 rounded-lg border border-border p-4"
          aria-label="Poverty context"
        >
          <h4 className="font-semibold">Poverty estimates alongside facility presence</h4>
          {povertyItems.length ? (
            povertyItems.map((item) => {
              const interval = publishedInterval(item);
              const format = percentageFormatter([item.values[0].value, ...(interval ?? [])]);
              const sae = item.id === "poverty.incidence";
              return (
                <div key={item.id} className="mt-2 text-sm">
                  <p>
                    PSA 2023{" "}
                    {sae
                      ? "municipal small-area estimate"
                      : "official population poverty incidence"}
                    : <strong>{format(item.values[0].value)}</strong>
                  </p>
                  <p className="mt-1">
                    {sae ? "90%" : "95%"} confidence interval:{" "}
                    {interval
                      ? `${format(interval[0])} to ${format(interval[1])}`
                      : "not available"}
                    .
                  </p>
                  <Note item={item} />
                </div>
              );
            })
          ) : (
            <p className="mt-2 text-sm">
              No usable published estimate for this selection. Missing or withheld estimates are not
              zero.
            </p>
          )}
          <p className="mt-2 text-sm text-muted">
            These are estimates of people below the poverty threshold, not counts of households.
            Different source boundaries and years prevent a causal interpretation or a combined
            priority score.
          </p>
          <Link className="mt-3 inline-block text-sm underline" href={href("poverty")}>
            Explore poverty estimates and source notes →
          </Link>
        </section>
      </div>
    </section>
  );
}
