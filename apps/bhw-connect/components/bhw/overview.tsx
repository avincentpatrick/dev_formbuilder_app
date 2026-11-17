import Link from "next/link";
import { comparisonHref } from "@/lib/bhw/comparison";
import { bhwScopedCatalogHref, restoredBhwMapHref } from "@/lib/maps/catalog-return";
import type { CatalogDataset, CatalogIndicator, CatalogSelection } from "@/lib/catalog/types";
import {
  overviewMeasures,
  publicValues,
  scalar,
  measureStatus,
  countAndShare,
  shareCount,
  shareOf,
} from "@/lib/bhw/overview";
import { DatasetMapCard } from "@/components/maps/dataset-map-card";
import { parseMapChoice } from "@/lib/maps/prototype";
import { subjectMapHref } from "@/lib/maps/subjects";
import { StatHero } from "@/components/home/stat-hero";
import { StatTile } from "@/components/home/stat-tile";
import { Donut } from "@/components/home/mini-viz";
import { FigureView } from "@/components/charts/figure-view";
import { FigureCard } from "@/components/narrative/figure-card";
import { SourceDates } from "@/components/catalog/catalog-results";
import { PresentationProvider } from "@/components/present/presentation-context";
import { PresentationSlide } from "@/components/present/presentation-slide";
import { PresentButton } from "@/components/present/present-button";

const mixLabels = ["Registered only", "Registered and accredited", "Nonregistered (LGU-reported)"];
const mixColors = ["var(--seq-3)", "var(--seq-6)", "var(--seq-1)"];

function Status({ indicator }: { indicator: CatalogIndicator | undefined }) {
  const message = measureStatus(indicator);
  return message ? <p className="mt-2 text-sm text-muted">{message}</p> : null;
}

function ShareTile({
  indicator,
  label,
  population,
}: {
  indicator: CatalogIndicator | undefined;
  label: string;
  population: string;
}) {
  const value = scalar(indicator);
  return (
    <StatTile
      label={label}
      value={countAndShare(shareCount(indicator), value)}
      caption={population}
      visual={
        value !== null && value >= 0 && value <= 100 ? (
          <Donut
            pct={value}
            ariaLabel={`${label}: ${countAndShare(shareCount(indicator), value)}`}
          />
        ) : undefined
      }
      context={<Status indicator={indicator} />}
    />
  );
}

/** This component receives catalog-approved values only. It never imports legacy
 * DB readers, AI caches or export endpoints with a different disclosure contract. */
export function BhwOverview({
  dataset,
  mapState,
  selection = { mode: "psgc", level: "national", code: "PH" },
  areaName = "Philippines",
}: {
  dataset: CatalogDataset;
  mapState?: string | string[];
  selection?: Extract<CatalogSelection, { mode: "psgc" }>;
  areaName?: string;
}) {
  const national = selection.level === "national";
  const caption = `${areaName} · 2025 · validated profiles`;
  const { get, total, parts } = overviewMeasures(dataset);
  const profiles = scalar(get("bhw.validated-profiles"));
  const registrationMix = parts?.map((value, index) => ({
    label: mixLabels[index],
    value,
    color: mixColors[index],
  }));
  const certification = [
    ["bhw.certification.ref_manual_trained", "BHW Reference Manual training"],
    ["bhw.certification.tesda_nc2", "TESDA BHS NC II training"],
    ["bhw.certification.tesda_certified", "TESDA BHS NC II certification"],
  ].map(([id, label]) => ({ indicator: get(id), label }));
  const certificationData = certification.flatMap(({ indicator, label }) => {
    const value = scalar(indicator);
    return value === null
      ? []
      : [
          {
            label,
            value,
            shortLabel: label
              .replace("BHW Reference Manual training", "Manual training")
              .replace("TESDA BHS NC II training", "NC II training")
              .replace("TESDA BHS NC II certification", "NC II certified"),
          },
        ];
  });
  const payers = ["region", "province", "citymun", "barangay"].map((payer) =>
    get(`bhw.honorarium.${payer}`),
  );
  const payerData = payers.flatMap((indicator) => {
    const value = scalar(indicator);
    return value === null || !indicator
      ? []
      : [
          {
            label: indicator.label,
            value,
            shortLabel: indicator.label
              .replace("Honorarium receipt from ", "")
              .replace("citymun", "City/municipality"),
          },
        ];
  });
  const education = get("bhw.demographics.education");
  const educationData = publicValues(education).map((v) => ({
    label: v.label ?? "Category",
    value: v.value,
    shortLabel: (v.label ?? "Category").length > 16 ? `${v.label!.slice(0, 15)}…` : v.label,
  }));
  const previousMapHref = restoredBhwMapHref(mapState);
  const compareHref = comparisonHref(
    "bhw.total",
    national ? [] : [selection.code],
    undefined,
    mapState,
  );
  const mapHref = subjectMapHref("bhw", selection)!;
  return (
    <PresentationProvider
      meta={{
        pageLabel: "BHW overview",
        areaName,
        filterChips: [],
        captionLine: "BHW Headcount and Profiles · 2025 · distinct populations",
      }}
    >
      <div className="space-y-8" data-testid="bhw-overview">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <nav
            aria-label="BHW analysis"
            className="flex flex-wrap gap-3 text-sm underline underline-offset-4"
          >
            <Link href="#workforce">Workforce distribution</Link>
            <Link href="#qualifications">Qualifications and training</Link>
            <Link href="#support">Support and compensation</Link>
            <Link href={compareHref}>Compare places</Link>
            <Link href={previousMapHref ?? mapHref}>
              Map explorer{previousMapHref ? " · previous settings" : ""}
            </Link>
            <Link href="#methodology">Data and methodology</Link>
          </nav>
        </div>
        <section
          id="workforce"
          aria-labelledby="workforce-heading"
          className="scroll-mt-20 space-y-4"
        >
          <h2 id="workforce-heading" className="text-2xl font-semibold">
            {national ? "National workforce" : `Workforce in ${areaName}`}
          </h2>
          <p className="text-sm text-muted">
            Headcount and validated profiles describe different populations. Do not add them or read
            them as change over time.
          </p>
          <PresentationSlide
            id="national-figures"
            title={national ? "National workforce figures" : `Workforce figures · ${areaName}`}
          >
            <div className="grid gap-4 sm:grid-cols-2" data-testid="bhw-first-visual">
              <StatHero
                label="LGU-reported BHW headcount"
                value={countAndShare(total, shareOf(total, total))}
                caption={`${areaName} · BHW Headcount 2025 · % of area headcount`}
                registrationMix={registrationMix}
                context={
                  <>
                    <Status indicator={get("bhw.total")} />
                    <p className="mt-2 text-sm text-muted">
                      A low count alone does not establish a staffing shortage.
                    </p>
                  </>
                }
                enlarge={
                  registrationMix
                    ? {
                        title: "LGU-reported registration",
                        caption: `${areaName} · BHW Headcount 2025`,
                        chartData: registrationMix.map(({ label, value }) => ({ label, value })),
                        xLabel: "BHWs",
                        yLabel: "Registration",
                      }
                    : undefined
                }
              />
              <StatTile
                label="Validated profiles"
                value={countAndShare(profiles, shareOf(profiles, total))}
                caption={`${areaName} · 2025 · % of LGU-reported headcount (distinct sources)`}
                context={<Status indicator={get("bhw.validated-profiles")} />}
              />
              <ShareTile
                indicator={get("bhw.declared-accreditation")}
                label="LGU-reported accreditation"
                population="Registered and accredited / LGU-reported headcount · 2025"
              />
            </div>
          </PresentationSlide>
          <section aria-label="Workforce distribution preview" className="space-y-3">
            <h3 className="text-xl font-semibold">Where are BHWs reported?</h3>
            <p className="text-sm text-muted">
              Explore LGU-reported headcount within {areaName}. Circles show area totals, not worker
              locations. Use Areas and data for ranked counts and exact values. Preview choices do
              not change the dashboard headline figures; enlarge the map to continue exploring.
            </p>
            <DatasetMapCard
              key={`${selection.level}:${selection.code}`}
              dataset="bhw-stepzero-2026"
              initial={parseMapChoice(new URLSearchParams(mapHref.split("?")[1])) ?? undefined}
              overviewPreview
            />
          </section>
          <div className="flex flex-wrap items-center gap-4">
            <Link
              href={mapHref}
              className="inline-block text-sm font-semibold text-accent underline"
            >
              Explore {national ? "national headcount" : `headcount for ${areaName}`} on the BHW map
              →
            </Link>
            <PresentButton variant="secondary" />
          </div>
        </section>
        <section
          id="qualifications"
          aria-labelledby="qualifications-heading"
          className="scroll-mt-20 space-y-4"
        >
          <h2 id="qualifications-heading" className="text-2xl font-semibold">
            Qualifications and training
          </h2>
          <ShareTile
            indicator={get("bhw.profile-accreditation")}
            label="Accreditation among validated profiles"
            population={`Accredited profiles / validated profiles · ${areaName} · 2025`}
          />
          <PresentationSlide id="certification" title="Training and certification">
            <FigureCard
              title="Training and certification"
              caption={caption}
              headline="These qualifications overlap: a person can have more than one. Do not add the percentages."
            >
              {certificationData.length ? (
                <FigureView
                  data={certificationData}
                  title="Training and certification"
                  caption={caption}
                  xLabel="% of validated profiles"
                  yLabel="Qualification"
                  valueSuffix="%"
                />
              ) : (
                <p>No accepted training or certification observations are available.</p>
              )}
              {certification.map(({ indicator, label }) => (
                <div key={label}>
                  <span className="sr-only">{label}: </span>
                  <Status indicator={indicator} />
                </div>
              ))}
            </FigureCard>
          </PresentationSlide>
          <PresentationSlide id="education" title="Educational attainment">
            <FigureCard
              title="Educational attainment"
              caption={caption}
              headline="Only public, disclosure-checked categories are shown."
            >
              {educationData.length ? (
                <FigureView
                  data={educationData}
                  title="Educational attainment"
                  caption={caption}
                  xLabel="Validated profiles"
                  yLabel="Education"
                />
              ) : null}
              <Status indicator={education} />
            </FigureCard>
          </PresentationSlide>
        </section>
        <section id="support" aria-labelledby="support-heading" className="scroll-mt-20 space-y-4">
          <h2 id="support-heading" className="text-2xl font-semibold">
            Support and compensation
          </h2>
          <ShareTile
            indicator={get("bhw.any-honorarium")}
            label="Receiving any honorarium"
            population={`Share of validated profiles · ${areaName} · 2025`}
          />
          <PresentationSlide id="honorarium" title="Honorarium receipt">
            <FigureCard
              title="Honorarium receipt by paying level"
              caption={caption}
              headline="Payers overlap. Receipt does not establish the amount paid or whether it is sufficient."
            >
              {payerData.length > 0 && (
                <FigureView
                  data={payerData}
                  title="Honorarium receipt by paying level"
                  caption={caption}
                  xLabel="% of validated profiles"
                  yLabel="Paying level"
                  valueSuffix="%"
                />
              )}
              <ul className="mt-3 space-y-2 text-sm">
                {payers
                  .filter((i) => scalar(i) === null)
                  .map((indicator, index) => (
                    <li key={indicator?.id ?? index}>
                      <strong>{indicator?.label ?? "Paying level"}</strong>
                      <Status indicator={indicator} />
                    </li>
                  ))}
              </ul>
            </FigureCard>
          </PresentationSlide>
        </section>
        <section aria-labelledby="examine-heading" className="space-y-3">
          <h2 id="examine-heading" className="text-2xl font-semibold">
            Places to examine more closely
          </h2>
          <p className="text-sm text-muted">
            Start with the map and compare places using the same measure and period. Check reporting
            coverage before interpreting differences; this overview does not assign a staffing-need
            score.
          </p>
          <Link href={compareHref} className="text-sm font-semibold text-accent underline">
            Compare places →
          </Link>
        </section>
        <details id="methodology" className="scroll-mt-20 rounded-lg border border-border p-5">
          <summary className="cursor-pointer font-semibold">
            Sources, coverage and detailed data
          </summary>
          <p className="mt-3 text-sm">
            Figures use the accepted record for {areaName}, not the sum of overlapping location
            records. Headcount is never replaced with profile counts. Missing and failed reads are
            not zero.
          </p>
          <p className="mt-3 text-sm">
            Demographic counts and honorarium receipt shares show all stored aggregate values.
            Values absent from the accepted source remain unavailable. Individual records are not
            included.
          </p>
          <SourceDates dataset={dataset} />
          <Link
            prefetch={false}
            href={bhwScopedCatalogHref(selection, mapState)}
            className="mt-4 inline-block text-sm underline"
          >
            View{" "}
            {national
              ? "national indicators and availability"
              : `indicators and availability for ${areaName}`}{" "}
            →
          </Link>
        </details>
      </div>
    </PresentationProvider>
  );
}
