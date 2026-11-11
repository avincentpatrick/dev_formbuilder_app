"use client";
import dynamic from "next/dynamic";
import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import type { DistrictMapIndex, DistrictMapProvince } from "@/app/api/district-map/route";
import { withBasePath } from "@/lib/base-path";
import {
  DISTRICT_MAP_SLUG,
  OUTSIDE_VALUE,
  districtIndicatorOptions,
  districtMemberLayer,
  districtNote,
  districtViewHref,
  memberBins,
  provinceDistrictLayer,
  shortDistrictName,
  type DistrictView,
} from "@/lib/maps/district-view";
import { getDatasetMap } from "@/lib/maps/definitions";
import { mapHref, type MapPayload } from "@/lib/maps/prototype";
import { MapLegend } from "./map-legend";

const DatasetCanvas = dynamic(() => import("./dataset-canvas").then((m) => m.DatasetCanvas), {
  ssr: false,
  loading: () => (
    <p role="status" className="h-64">
      Loading map…
    </p>
  ),
});

type Load<T> = { key: string; data: T | { state: "unavailable" | "failed" } } | null;

function useJson<T extends { state: string }>(url: string | null): Load<T> {
  const [result, setResult] = useState<Load<T>>(null);
  useEffect(() => {
    if (!url) return;
    const controller = new AbortController();
    fetch(withBasePath(url), { signal: controller.signal })
      .then(async (response) => {
        const data = await response.json().catch(() => null);
        if (!data || (data.state !== "available" && data.state !== "unavailable"))
          throw new Error("Read failed");
        if (!controller.signal.aborted) setResult({ key: url, data });
      })
      .catch(() => {
        if (!controller.signal.aborted) setResult({ key: url, data: { state: "failed" } });
      });
    return () => controller.abort();
  }, [url]);
  return result?.key === url ? result : null;
}

const available = <T,>(load: Load<T>): T | null =>
  load && (load.data as { state: string }).state === "available" ? (load.data as T) : null;

/**
 * `/map`'s legislative-district view (docs/DECISIONS.md, 2026-11-11): districts shown through their
 * member cities and municipalities, never as an outline and never as a district total.
 */
export function DistrictMapCard({ initial }: { initial: DistrictView }) {
  const definition = getDatasetMap(DISTRICT_MAP_SLUG)!;
  const options = useMemo(() => districtIndicatorOptions(), []);
  const [state, setState] = useState(initial);
  const [selected, setSelected] = useState<string | null>(null);
  const [geometry, setGeometry] = useState<{ url: string; codes: string[] } | null>(null);
  const [renderAttempt, setRenderAttempt] = useState(0);
  // Keep the address shareable without a server round trip (Next keeps its router in step).
  useEffect(() => {
    window.history.replaceState(null, "", withBasePath(districtViewHref(state)));
  }, [state]);

  const index = useJson<DistrictMapIndex>("/api/district-map");
  const indexData = available<DistrictMapIndex>(index);
  const placeUrl =
    state.view === "district" && state.district
      ? `/api/district-map?district=${state.district}`
      : state.view === "province" && state.province
        ? `/api/district-map?province=${state.province}`
        : null;
  const place = useJson<DistrictMapProvince>(placeUrl);
  const placeData = available<DistrictMapProvince>(place);
  const option = options.find(
    (o) => o.dataset === state.dataset && o.indicator === state.indicator,
  );
  const valuesUrl =
    state.view === "district" && placeData && option
      ? `/api/dataset-map?${
          mapHref({
            dataset: option.dataset,
            indicator: option.indicator,
            period: option.period,
            level: "province",
            code: placeData.province.code,
            selected: null,
          }).split("?")[1]
        }`
      : null;
  const values = useJson<MapPayload>(valuesUrl);
  const valueData = available<MapPayload>(values);

  const provinceLayer = useMemo(
    () =>
      placeData && state.view === "province"
        ? provinceDistrictLayer(placeData.municipalities, placeData.districts)
        : null,
    [placeData, state.view],
  );
  const memberLayer = useMemo(
    () =>
      placeData && state.view === "district" && state.district && valueData && option
        ? districtMemberLayer(
            valueData.rows,
            placeData.municipalities,
            state.district,
            placeData.districts,
            (v) => memberBins(option.dataset, option.indicator, v),
          )
        : null,
    [placeData, state.view, state.district, valueData, option],
  );
  const canvasRows = provinceLayer?.rows ?? memberLayer?.canvasRows ?? null;
  const bins = provinceLayer?.bins ?? memberLayer?.bins ?? [];
  const tableRows = provinceLayer?.rows ?? memberLayer?.table ?? [];
  const shapeCodes =
    geometry && placeData && geometry.url === placeData.geometryUrl ? geometry.codes : null;
  const missingShapes = shapeCodes ? tableRows.filter((r) => !shapeCodes.includes(r.code)) : [];
  const districtName = (code: string) => {
    const found =
      placeData?.districts.find((d) => d.code === code) ??
      indexData?.districts.find((d) => d.code === code);
    return found ? shortDistrictName(found.name) : code;
  };
  const provinceName = (code: string) =>
    indexData?.provinces.find((p) => p.code === code)?.name ?? code;
  const municipality = placeData?.municipalities.find((m) => m.code === selected);
  const selectedRow = tableRows.find((r) => r.code === selected);
  const update = (next: Partial<DistrictView>) => {
    setSelected(null);
    setState((s) => ({ ...s, ...next }));
  };
  const loading = (load: Load<unknown>, url: string | null) => url !== null && load === null;
  const failedState = (load: Load<{ state: string }>) =>
    load && (load.data as { state: string }).state !== "available"
      ? (load.data as { state: string }).state
      : null;

  return (
    <article
      aria-label={`${definition.title} map`}
      className="min-w-0 space-y-4 rounded-xl border border-border bg-background p-5"
    >
      <h2 className="text-xl font-semibold">{definition.title}</h2>
      <div className="rounded-md border border-border bg-surface p-3 text-sm">
        <p>
          <strong>No district outlines are drawn.</strong> No district boundary has been validated
          yet, so each district is shown through its member cities and municipalities, as one public
          source lists them (see{" "}
          <Link href="/districts" className="underline">
            the mapping and its known gaps
          </Link>
          ). A city divided between districts is drawn whole and marked as divided. Figures are each
          municipality&apos;s own published figures; no district totals are calculated.
        </p>
      </div>

      <fieldset className="flex flex-wrap gap-4 text-sm">
        <legend className="mb-1 font-medium">View</legend>
        <label className="flex items-center gap-2">
          <input
            type="radio"
            name="district-view"
            checked={state.view === "province"}
            onChange={() =>
              update({
                view: "province",
                province: placeData?.province.code ?? state.province,
              })
            }
          />
          Districts in a province
        </label>
        <label className="flex items-center gap-2">
          <input
            type="radio"
            name="district-view"
            checked={state.view === "district"}
            onChange={() => update({ view: "district" })}
          />
          One district&apos;s municipalities, with a figure
        </label>
      </fieldset>

      {failedState(index) && (
        <p role="status">
          Province and district lists could not be loaded. This does not mean there are none.
        </p>
      )}

      {state.view === "province" ? (
        <label className="block text-sm">
          Province or highly urbanized city
          <select
            aria-label="District map province"
            className="mt-1 block w-full rounded border border-border bg-background p-2"
            value={state.province ?? ""}
            onChange={(e) => update({ province: e.target.value || null })}
          >
            <option value="">Choose a province</option>
            {indexData?.provinces.map((p) => (
              <option key={p.code} value={p.code}>
                {p.name}
              </option>
            ))}
          </select>
        </label>
      ) : (
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">
            District
            <select
              aria-label="District map district"
              className="mt-1 block w-full rounded border border-border bg-background p-2"
              value={state.district ?? ""}
              onChange={(e) => update({ district: e.target.value || null })}
            >
              <option value="">Choose a district</option>
              {indexData?.districts.map((d) => (
                <option key={d.code} value={d.code}>
                  {shortDistrictName(d.name)}
                </option>
              ))}
            </select>
          </label>
          <label className="block text-sm">
            Figure for each municipality
            <select
              aria-label="District map figure"
              className="mt-1 block w-full rounded border border-border bg-background p-2"
              value={`${state.dataset}|${state.indicator}`}
              onChange={(e) => {
                const [dataset, indicator] = e.target.value.split("|");
                update({ dataset, indicator });
              }}
            >
              {[...new Set(options.map((o) => o.datasetTitle))].map((title) => (
                <optgroup key={title} label={title}>
                  {options
                    .filter((o) => o.datasetTitle === title)
                    .map((o) => (
                      <option
                        key={`${o.dataset}|${o.indicator}`}
                        value={`${o.dataset}|${o.indicator}`}
                      >
                        {o.label}
                      </option>
                    ))}
                </optgroup>
              ))}
            </select>
          </label>
        </div>
      )}

      {!placeUrl ? (
        <p role="status" className="min-h-32 rounded-lg bg-surface p-4">
          {state.view === "province"
            ? "Choose a province to see its cities and municipalities coloured by district."
            : "Choose a district to see its cities and municipalities with their own figures."}
        </p>
      ) : loading(place, placeUrl) || (valuesUrl && loading(values, valuesUrl)) ? (
        <p role="status" className="min-h-32 rounded-lg bg-surface p-4">
          Loading district map…
        </p>
      ) : failedState(place) || failedState(values) ? (
        <p role="status" className="min-h-32 rounded-lg bg-surface p-4">
          {failedState(place) === "unavailable"
            ? "This district or province has no recorded cities or municipalities to show."
            : failedState(values) === "unavailable"
              ? "This figure's source is unavailable. No values are shaded."
              : "The district map could not be loaded. This does not mean there are no districts or no figures."}
        </p>
      ) : placeData && canvasRows ? (
        <>
          <p className="text-sm">
            {placeData.province.name}
            {state.view === "district" && state.district && (
              <>
                {" "}
                · {districtName(state.district)} · {option?.label} · {option?.period} ·{" "}
                {option?.unit}
              </>
            )}
          </p>
          {state.view === "district" && placeData.otherProvinces.length > 0 && (
            <p className="text-sm text-muted">
              This district also has members in{" "}
              {placeData.otherProvinces.map(provinceName).join(", ")}. Only{" "}
              {placeData.province.name}, which holds most of them, is drawn here.
            </p>
          )}
          {state.view === "district" && memberLayer?.table.length === 0 && (
            <p role="status" className="text-sm">
              The mapping places no city or municipality of {placeData.province.name} in this
              district.
            </p>
          )}
          <DatasetCanvas
            key={`${placeData.geometryUrl}:${renderAttempt}`}
            url={placeData.geometryUrl}
            rows={canvasRows}
            bins={bins}
            selected={selected}
            onSelect={setSelected}
            onGeometry={(codes) => setGeometry({ url: placeData.geometryUrl, codes })}
            onCapture={() => {}}
            onRetry={() => {
              setGeometry(null);
              setRenderAttempt((n) => n + 1);
            }}
            expanded
            emphasis={memberLayer?.table.map((r) => r.code)}
          />
          <MapLegend
            bins={bins}
            categoryLabels={
              provinceLayer ? provinceLayer.labels : { [OUTSIDE_VALUE]: "Outside this district" }
            }
            valueSuffix={option?.unit === "%" ? "%" : ""}
            noDataLabel={
              provinceLayer ? "No district recorded — see table" : "Not reported — see table"
            }
            hasNoData={tableRows.some((r) => r.value === null)}
            hasSmallN={tableRows.some((r) => r.smallSample === true)}
          />
          <p className="text-xs text-muted">
            {provinceLayer
              ? "Colours are districts, not figures."
              : "The district's members are outlined. Quantiles across its members, or the same fixed breaks the other maps use for published shares. Gray means no published figure, not zero."}{" "}
            {missingShapes.length > 0 &&
              `${missingShapes.length} of ${tableRows.length} have no shape on this map and stay in the table. `}
            Use Ctrl/⌘ + scroll to zoom; two fingers to pan.
          </p>

          {municipality && (
            <div className="space-y-2 rounded-md border border-border p-3 text-sm">
              <p className="font-medium">{municipality.name}</p>
              {state.view === "district" &&
              selectedRow &&
              memberLayer?.table.includes(selectedRow) ? (
                <p>
                  {selectedRow.value === null
                    ? "Not reported"
                    : `${selectedRow.value.toLocaleString()} ${option?.unit ?? ""}`}
                  {selectedRow.note ? ` · ${selectedRow.note}` : ""}
                </p>
              ) : (
                <p>{districtNote(municipality, placeData.districts)}</p>
              )}
              <div className="flex flex-wrap gap-3">
                {municipality.districts
                  .filter((code) => !(state.view === "district" && code === state.district))
                  .map((code) => (
                    <button
                      key={code}
                      type="button"
                      className="underline"
                      onClick={() => update({ view: "district", district: code })}
                    >
                      Show {districtName(code)} with a figure
                    </button>
                  ))}
                {municipality.districts.map((code) => (
                  <Link key={code} className="underline" href={`/districts/${code}`}>
                    {districtName(code)} page
                  </Link>
                ))}
              </div>
            </div>
          )}

          <details>
            <summary className="cursor-pointer text-sm font-medium">
              {state.view === "province"
                ? "Cities and municipalities"
                : "Member cities and municipalities"}{" "}
              ({tableRows.length})
            </summary>
            <div
              className="max-h-80 overflow-auto"
              tabIndex={0}
              role="region"
              aria-label="District map table"
            >
              <table className="w-full text-left text-sm">
                <thead>
                  <tr>
                    <th scope="col">City or municipality</th>
                    {state.view === "district" && <th scope="col">Figure ({option?.unit})</th>}
                    <th scope="col">{state.view === "province" ? "District" : "Notes"}</th>
                    <th scope="col">Geometry</th>
                  </tr>
                </thead>
                <tbody>
                  {tableRows.map((r) => (
                    <tr key={r.code}>
                      <th scope="row" className="py-2 font-normal">
                        {r.name}
                      </th>
                      {state.view === "district" && (
                        <td>{r.value === null ? "Not reported" : r.value.toLocaleString()}</td>
                      )}
                      <td>{r.note ?? "—"}</td>
                      <td>
                        {shapeCodes
                          ? shapeCodes.includes(r.code)
                            ? "Available"
                            : "Missing"
                          : "Not checked"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </details>
          <p className="text-xs text-muted">
            District membership: {placeData.source.replace(/[.\s]+$/, "")}. Source date:{" "}
            {placeData.date ?? "Not provided"}. License: {placeData.license ?? "Not provided"}.
            {valueData &&
              ` Figures: ${valueData.source.replace(/[.\s]+$/, "")}. Source date: ${valueData.date ?? "Not provided"}.`}
          </p>
        </>
      ) : null}
      <p className="text-xs text-muted">{definition.disclosure}</p>
      <div className="flex flex-wrap gap-4 text-sm underline">
        <Link prefetch={false} href={districtViewHref(state)}>
          Share this map selection
        </Link>
        <Link href="/districts">All districts</Link>
      </div>
    </article>
  );
}
