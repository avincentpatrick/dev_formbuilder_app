import type { Metadata } from "next";
import { EditionLink as Link } from "@/components/uuc-phc/edition-link";
import { NATIONAL_GEO_CODE } from "@/lib/filters/schema";
import { formatCount } from "@/lib/format";
import { getUucPhcCounts } from "@/lib/db/uuc-phc";
import {
  countValueChanges,
  getUucPhcListChanges,
  getUucPhcValueChanges,
  groupChangesByProvince,
  summariseChanges,
} from "@/lib/db/uuc-phc-changes";
import { UUC_PHC_INDICATORS } from "@/lib/db/uuc-phc-indicator-dist";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";
import { previousEdition } from "@/lib/uuc-phc/editions";

type SearchParams = Promise<Record<string, string | string[] | undefined>>;

// Per request: the page compares the edition its `?edition=` names with the one before it.
export const dynamic = "force-dynamic";

/** Plain names for the recorded values a barangay can change on. */
const VALUE_LABEL: Record<string, string> = {
  ...Object.fromEntries(UUC_PHC_INDICATORS.map((m) => [m.key, m.label])),
  elcac_brgy: "ELCAC designation",
  health_indicators: "Criterion (d) score (health indicators worse than the province, of 7)",
};

export async function generateMetadata({
  searchParams,
}: {
  searchParams: SearchParams;
}): Promise<Metadata> {
  const { edition } = await resolveUucEdition(searchParams);
  const previous = previousEdition(edition);
  return {
    title: {
      absolute: `Changes since ${previous?.year ?? "the previous list"} · UUC for PHC ${edition.year}`,
    },
    description: previous
      ? `Which barangays joined and left the UUC for PHC list between ${previous.year} and ${edition.year}, by region, province and city/municipality.`
      : `The ${edition.year} UUC for PHC list is the earliest loaded, so there is no earlier list to compare it with.`,
  };
}

export default async function UucPhcChangesPage({ searchParams }: { searchParams: SearchParams }) {
  const { edition, available } = await resolveUucEdition(searchParams);
  const previous = previousEdition(edition);
  const previousLoaded = previous && available.some((e) => e.slug === previous.slug);

  if (!previous || !previousLoaded) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">What changed</h1>
        <p className="max-w-2xl text-muted">
          The {edition.year} list is the earliest edition loaded here, so there is no earlier list
          to compare it with.
        </p>
      </div>
    );
  }

  const [changes, valueChanges, currentCounts, previousCounts] = await Promise.all([
    getUucPhcListChanges(edition.slug),
    getUucPhcValueChanges(edition.slug),
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", edition.slug),
    getUucPhcCounts(NATIONAL_GEO_CODE, "national", previous.slug),
  ]);

  // A failed read must never print as "nothing changed" — that is the one wrong answer a changes
  // page can give that looks exactly like a right one.
  if (!changes || !currentCounts || !previousCounts) {
    return (
      <div className="flex flex-col gap-4">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
          What changed between {previous.year} and {edition.year}
        </h1>
        <p className="text-muted">
          The comparison could not be read right now. That is a failure to load it, not a finding
          that the two lists are the same.
        </p>
      </div>
    );
  }

  const nAdded = changes.filter((c) => c.change === "added").length;
  const nRemoved = changes.length - nAdded;
  const regions = summariseChanges(changes, "region");
  const provinces = groupChangesByProvince(changes);
  const valueCounts = valueChanges ? countValueChanges(valueChanges) : null;
  const net = currentCounts.nListed - previousCounts.nListed;

  return (
    <div className="flex flex-col gap-8">
      <section className="flex flex-col gap-3">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
          What changed between {previous.year} and {edition.year}
        </h1>
        <p className="max-w-2xl text-muted">
          The {previous.year} list named {formatCount(previousCounts.nListed)} barangays; the{" "}
          {edition.year} list names {formatCount(currentCounts.nListed)}. Below is which barangays
          joined the list and which left it, matched by their geographic code, and which barangays
          on both lists had different values recorded. It says <strong>what</strong> moved, not why:
          the list records which barangays are on it, not the assessment behind each decision.
        </p>
      </section>

      <section className="grid gap-3 sm:grid-cols-4" aria-label="Summary">
        {[
          { label: `Joined the ${edition.year} list`, value: formatCount(nAdded) },
          { label: `Left after ${previous.year}`, value: formatCount(nRemoved) },
          { label: "Net change", value: `${net > 0 ? "+" : ""}${formatCount(net)}` },
          {
            label: "On both lists, values changed",
            value: valueChanges ? formatCount(valueChanges.length) : "—",
          },
        ].map((card) => (
          <div key={card.label} className="rounded-lg border border-border bg-background p-4">
            <p className="text-2xl font-semibold tabular-nums">{card.value}</p>
            <p className="mt-1 text-xs text-muted">{card.label}</p>
          </div>
        ))}
      </section>

      {changes.length === 0 ? (
        <p className="rounded-lg border border-border bg-surface p-5 text-sm text-muted">
          No barangay joined or left the list between {previous.year} and {edition.year}.
        </p>
      ) : (
        <>
          <section className="rounded-lg border border-border bg-background p-5 sm:p-6">
            <h2 className="text-sm font-semibold">By region</h2>
            <p className="mt-1 text-xs text-muted">
              Only regions where a barangay joined or left are listed.
            </p>
            <div className="mt-2 overflow-x-auto">
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs text-muted">
                    <th scope="col" className="py-2 pr-3 font-medium">
                      Region
                    </th>
                    <th scope="col" className="py-2 pr-3 text-right font-medium">
                      Joined
                    </th>
                    <th scope="col" className="py-2 pr-3 text-right font-medium">
                      Left
                    </th>
                    <th scope="col" className="py-2 text-right font-medium">
                      Net
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {regions.map((r) => (
                    <tr key={r.code} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3">
                        <Link
                          href={`/uuc-phc/region/${r.code}`}
                          className="font-medium hover:text-accent hover:underline"
                        >
                          {r.name}
                        </Link>
                      </td>
                      <td className="py-2 pr-3 text-right tabular-nums">{formatCount(r.added)}</td>
                      <td className="py-2 pr-3 text-right tabular-nums">
                        {formatCount(r.removed)}
                      </td>
                      <td className="py-2 text-right font-medium tabular-nums">
                        {r.added - r.removed > 0 ? "+" : ""}
                        {formatCount(r.added - r.removed)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="rounded-lg border border-border bg-background p-5 sm:p-6">
            <h2 className="text-sm font-semibold">By province — the barangays themselves</h2>
            <div className="mt-2 overflow-x-auto">
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs text-muted">
                    <th scope="col" className="py-2 pr-3 font-medium">
                      Province
                    </th>
                    <th scope="col" className="py-2 pr-3 font-medium">
                      Joined the {edition.year} list
                    </th>
                    <th scope="col" className="py-2 font-medium">
                      Left after {previous.year}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {provinces.map((p) => (
                    <tr
                      key={p.provinceCode}
                      className="border-b border-border align-top last:border-0"
                    >
                      <th scope="row" className="py-2 pr-3 text-left font-medium">
                        <Link
                          href={`/uuc-phc/province/${p.provinceCode}`}
                          className="hover:text-accent hover:underline"
                        >
                          {p.provinceName}
                        </Link>
                        <span className="block text-xs font-normal text-muted">
                          +{formatCount(p.nAdded)} / −{formatCount(p.nRemoved)}
                        </span>
                      </th>
                      <td className="py-2 pr-3">
                        <GroupList groups={p.added} />
                      </td>
                      <td className="py-2">
                        <GroupList groups={p.removed} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}

      <section className="rounded-lg border border-border bg-background p-5 sm:p-6">
        <h2 className="text-sm font-semibold">On both lists, with different recorded values</h2>
        {valueChanges === null || valueCounts === null ? (
          <p className="mt-2 text-sm text-muted">This comparison could not be read right now.</p>
        ) : valueChanges.length === 0 ? (
          <p className="mt-2 text-sm text-muted">
            Every barangay on both lists carries the same recorded values in both years.
          </p>
        ) : (
          <>
            <p className="mt-1 max-w-2xl text-sm text-muted">
              {formatCount(valueChanges.length)} barangays are on both lists with at least one
              recorded value that differs. Values are compared as published here — after cleaning
              bounded the impossible ones — so a value capped in both years reads as unchanged.
            </p>
            <ul className="mt-3 flex flex-col gap-1 text-sm">
              {valueCounts.map(([key, n]) => (
                <li
                  key={key}
                  className="flex justify-between gap-4 border-b border-border py-1.5 last:border-0"
                >
                  <span>{VALUE_LABEL[key] ?? key}</span>
                  <span className="tabular-nums text-muted">{formatCount(n)} barangays</span>
                </li>
              ))}
            </ul>
            <details className="mt-3">
              <summary className="cursor-pointer text-sm font-medium hover:text-accent">
                Show the {formatCount(valueChanges.length)} barangays
              </summary>
              <ul className="mt-2 flex flex-col gap-1 text-sm text-muted">
                {valueChanges.map((v) => (
                  <li key={v.geoCode}>
                    <span className="text-foreground">{v.barangayName}</span>, {v.citymunName},{" "}
                    {v.provinceName} —{" "}
                    {v.changedIndicators.map((k) => VALUE_LABEL[k] ?? k).join("; ")}
                  </li>
                ))}
              </ul>
            </details>
          </>
        )}
      </section>

      <p className="text-sm text-muted">
        Matching is by geographic code, not by name, so a barangay spelled differently in the two
        lists is still the same barangay. Sulu&rsquo;s barangays are under Region IX in both years.
        How each list was loaded is on the{" "}
        <Link href="/uuc-phc/methodology" className="underline hover:text-accent">
          methodology
        </Link>{" "}
        page.
      </p>
    </div>
  );
}

function GroupList({ groups }: { groups: { citymunName: string; barangays: string[] }[] }) {
  if (groups.length === 0) return <span className="text-muted">—</span>;
  return (
    <ul className="flex flex-col gap-1">
      {groups.map((g) => (
        <li key={g.citymunName}>
          <span className="font-medium">{g.citymunName}:</span>{" "}
          <span className="text-muted">{g.barangays.join(", ")}</span>
        </li>
      ))}
    </ul>
  );
}
