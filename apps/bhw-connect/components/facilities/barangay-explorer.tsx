"use client";
import { useState } from "react";
import dynamic from "next/dynamic";
import { exploreFacilities, type ExplorationInput } from "@/lib/facilities/exploration";
import { pointRadius } from "@/lib/geo/facility-points";
import { formatCount } from "@/lib/format";
import { withBasePath } from "@/lib/base-path";
import { Modal } from "@/components/ui/modal";
import { FacilityList } from "./facility-list";
const PointMap = dynamic(() => import("@/components/maps/point-map").then((m) => m.PointMap), {
  ssr: false,
  loading: () => <p role="status">Loading map… The barangay table is available below.</p>,
});
const button = "min-h-11 rounded border border-border px-3 text-sm";
export function BarangayExplorer({
  input,
  query,
  areaHref,
}: {
  input: ExplorationInput | null;
  query: string;
  areaHref: string;
}) {
  const [types, setTypes] = useState<string[]>([]);
  const [ownership, setOwnership] = useState("");
  const [selected, setSelected] = useState("");
  const [search, setSearch] = useState("");
  const [order, setOrder] = useState("name");
  const [page, setPage] = useState(0);
  const [mode, setMode] = useState<"inline" | "maximize" | "present">("inline");
  if (!input)
    return (
      <p role="status">
        The facility map and list are unavailable until the complete facility inventory and barangay
        roster can be verified. This is not a reported zero. Reload to try again.
      </p>
    );
  const result = exploreFacilities(input, { types, ownership });
  if (!result)
    return <p role="status">Facility counts could not be verified. Reload to try again.</p>;
  const rows = result.rows
    .filter((r) => r.geoName.toLocaleLowerCase().includes(search.toLocaleLowerCase()))
    .sort(
      (a, b) =>
        (order === "highest" ? b.count - a.count : order === "lowest" ? a.count - b.count : 0) ||
        a.geoName.localeCompare(b.geoName) ||
        a.geoCode.localeCompare(b.geoCode),
    );
  const currentPage = Math.min(page, Math.max(0, Math.ceil(rows.length / 20) - 1));
  const selectedName = input.barangays.find((b) => b.geoCode === selected)?.geoName;
  const list = result.facilities.filter((f) => !selected || f.barangayGeoCode === selected);
  const scope = `${types.length ? types.join("; ") : "All recorded types"} · ${ownership || "All ownership"}`;
  const params = new URLSearchParams({ code: input.code, ownership });
  types.forEach((t) => params.append("type", t));
  const content = (presenting = false) => (
    <div className="min-w-0 space-y-4">
      <p className="break-words text-sm">
        {input.name} · DOH NHFR · September 2026 snapshot
        <br />
        {scope}
      </p>
      {!presenting && (
        <div className="flex flex-wrap items-start gap-3">
          <details>
            <summary className={`${button} cursor-pointer content-center`}>
              Facility types: {types.length || "All"}
            </summary>
            <fieldset>
              <legend className="sr-only">Map and list facility types, multiple selection</legend>
              {[...new Set(input.facilities.map((f) => f.facilityType))].sort().map((t) => (
                <label
                  key={t}
                  className="flex min-h-11 max-w-full items-center gap-2 break-words text-sm"
                >
                  <input
                    type="checkbox"
                    checked={types.includes(t)}
                    onChange={(e) => {
                      setTypes(e.target.checked ? [...types, t] : types.filter((v) => v !== t));
                      setPage(0);
                    }}
                  />
                  {t}
                </label>
              ))}
            </fieldset>
            <button className={button} onClick={() => setTypes([])}>
              All recorded types
            </button>
          </details>
          <label className="grid min-w-0 gap-1 text-sm">
            Ownership
            <select
              className={`${button} max-w-full bg-background`}
              aria-label="Ownership"
              value={ownership}
              onChange={(e) => {
                setOwnership(e.target.value);
                setPage(0);
              }}
            >
              <option value="">All ownership</option>
              {[...new Set(input.facilities.map((f) => f.ownershipMajor))].sort().map((o) => (
                <option key={o}>{o}</option>
              ))}
            </select>
          </label>
          <button
            className={button}
            onClick={() => {
              setTypes([]);
              setOwnership("");
              setSelected("");
              setSearch("");
              setPage(0);
              setOrder("name");
            }}
          >
            Reset map and list filters
          </button>
        </div>
      )}
      <p className="text-sm text-muted">
        Type and ownership choices apply to this map, barangay table and facility list only. Other
        cards and the full-location report keep their own scope.
      </p>
      <div role="status" className="space-y-1 text-sm">
        <p>
          {formatCount(result.facilities.length)} selected{" "}
          {result.facilities.length === 1 ? "facility" : "facilities"} ·{" "}
          {formatCount(result.present)} of {formatCount(result.rows.length)}{" "}
          {result.rows.length === 1 ? "barangay has" : "barangays have"} a recorded facility
          matching these filters.
        </p>
        <p>
          {formatCount(result.facilities.length - result.data.nFacilitiesNotPlaced)} facilities
          represented on the map; {formatCount(result.missingGeometry)} assigned to barangays
          without usable geometry; {formatCount(result.unassigned)} without a matched barangay.
        </p>
      </div>
      <p className="text-sm font-medium">
        Each circle represents a barangay, not an exact facility location.
      </p>
      {result.data.points.length ? (
        <PointMap
          points={result.data.points}
          maxFacilities={result.data.maxFacilities}
          outlineUrl={input.outlineUrl}
          outlineGeoCode={input.code}
          selectedCode={selected}
          onSelect={setSelected}
        />
      ) : (
        <p role="status">
          Map geometry is unavailable for these barangays. Counts and the complete barangay table
          remain available below.
        </p>
      )}
      <div className="flex flex-wrap items-center gap-4 text-xs" aria-label="Map size legend">
        {[...new Set([0, 1, Math.round(result.data.maxFacilities / 2), result.data.maxFacilities])]
          .filter((n) => n <= result.data.maxFacilities)
          .sort((a, b) => a - b)
          .map((n) => (
            <span key={n} className="flex items-center gap-2">
              <svg width="40" height="40" aria-hidden="true">
                <circle
                  cx="20"
                  cy="20"
                  r={pointRadius(n, result.data.maxFacilities)}
                  fill={n ? "#0a6e6e" : "none"}
                  stroke="#57616a"
                />
              </svg>
              {n
                ? `${formatCount(n)} ${n === 1 ? "facility" : "facilities"}`
                : "No facility recorded"}
            </span>
          ))}
      </div>
      <p className="text-xs text-muted">
        Presence does not establish access, services or adequacy.
      </p>
      <div className="rounded border border-border p-3 text-sm" role="status">
        {selectedName ? (
          <>
            Selected barangay: <strong>{selectedName}</strong>. {formatCount(list.length)} matching
            facilities.{" "}
            <button className={button} onClick={() => setSelected("")}>
              Clear barangay
            </button>
          </>
        ) : (
          "Select a barangay on the map or in the table to see its facilities below."
        )}
      </div>
      <section aria-label="Exact barangay figures" className="space-y-3">
        <h3 className="font-semibold">Exact barangay figures</h3>
        <div className="flex flex-wrap gap-3">
          <label className="grid text-sm">
            Search barangays
            <input
              type="search"
              className={`${button} min-w-0 max-w-full bg-background`}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(0);
              }}
            />
          </label>
          <label className="grid text-sm">
            Order barangays
            <select
              aria-label="Order barangays"
              value={order}
              className={`${button} bg-background`}
              onChange={(e) => {
                setOrder(e.target.value);
                setPage(0);
              }}
            >
              <option value="name">Name (A–Z)</option>
              <option value="highest">Most facilities first</option>
              <option value="lowest">Fewest facilities first</option>
            </select>
          </label>
        </div>
        <p className="text-xs">
          Barangay search changes this table only. Zero counts include all barangays in the verified
          roster.
        </p>
        <table className="w-full table-fixed text-sm">
          <caption className="sr-only">Barangay counts and mapping status</caption>
          <thead>
            <tr className="text-left">
              <th scope="col">Barangay</th>
              <th scope="col" className="w-20">
                Facilities
              </th>
              <th scope="col" className="w-24">
                Map status
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.slice(currentPage * 20, (currentPage + 1) * 20).map((r) => (
              <tr key={r.geoCode} className="border-t border-border">
                <th scope="row" className="py-2 text-left font-normal">
                  <button
                    className="min-h-11 break-words text-left underline"
                    aria-pressed={selected === r.geoCode}
                    onClick={() => setSelected(r.geoCode)}
                  >
                    {r.geoName}
                  </button>
                </th>
                <td>{formatCount(r.count)}</td>
                <td>{r.mapped ? "Mapped" : "No geometry"}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!rows.length && <p role="status">No barangays match this search.</p>}
        {rows.length > 20 && (
          <nav aria-label="Barangay table pages" className="flex flex-wrap items-center gap-3">
            <button
              className={button}
              disabled={!currentPage}
              onClick={() => setPage(currentPage - 1)}
            >
              Previous barangays
            </button>
            <span className="text-sm">
              Page {currentPage + 1} of {Math.max(1, Math.ceil(rows.length / 20))} ·{" "}
              {formatCount(rows.length)} barangays
            </span>
            <button
              className={button}
              disabled={(currentPage + 1) * 20 >= rows.length}
              onClick={() => setPage(currentPage + 1)}
            >
              Next barangays
            </button>
          </nav>
        )}
      </section>
      <details>
        <summary className="min-h-11 cursor-pointer content-center text-sm">About this map</summary>
        <p className="text-sm">
          Circle sizes use a minimum visible radius; compare exact counts in the table. Missing map
          points do not mean missing facilities. The loaded September 2026 inventory supplies
          barangay assignments, not facility coordinates. Representative points come from
          faeldon/philippines-json-maps (2023 PSGC series) barangay boundaries; outlines are
          simplified context. All barangays remain in the table, including those with no matching
          recorded facility. Unmatched assignments are retained in the full facility list. Map
          exports show the complete geographic extent rather than the current zoom.
        </p>
      </details>
      <div className="flex flex-wrap gap-3 text-sm">
        {["csv", "xlsx"].map((format) => (
          <a
            key={format}
            className="min-h-11 content-center underline"
            href={withBasePath(
              `/api/export/facilities/exploration?${params}&table=facilities&barangay=${encodeURIComponent(selected)}&search=${encodeURIComponent(query)}&format=${format}`,
            )}
          >
            Download matching facilities ({format.toUpperCase()})
          </a>
        ))}
      </div>
      <p className="text-xs text-muted">
        Facility downloads include every matching row, across all pages, with the selected barangay
        and list search.
      </p>
      <FacilityList
        key={`${types.join("|")}|${ownership}|${selected}|${query}`}
        facilities={list}
        expectedCount={list.length}
        query={query}
        areaHref={areaHref}
        filtered
      />
    </div>
  );
  return (
    <section
      aria-label="Barangay facility explorer"
      className="space-y-4 rounded-lg border border-border p-4 sm:p-6"
      data-testid="barangay-explorer"
    >
      <h2 className="font-semibold">Where are facilities recorded by barangay?</h2>
      <div className="flex flex-wrap gap-2">
        <button className={button} onClick={() => setMode("maximize")}>
          Maximize map
        </button>
        <button className={button} onClick={() => setMode("present")}>
          Present map
        </button>
        <details>
          <summary className={`${button} cursor-pointer content-center`}>
            Download selected map and tables
          </summary>
          <div className="grid gap-2 p-2">
            {["png", "svg", "pdf", "pptx", "csv", "xlsx"].map((format) => (
              <a
                key={format}
                className="min-h-11 content-center text-sm underline"
                href={withBasePath(`/api/export/facilities/exploration?${params}&format=${format}`)}
              >
                {format.toUpperCase()} ·{" "}
                {format === "csv" || format === "xlsx" ? "all filtered barangays" : "selected map"}
              </a>
            ))}
          </div>
          <p className="max-w-xs text-xs">
            All barangays under the type/ownership filters. Table search and selected barangay do
            not limit these downloads.
          </p>
        </details>
      </div>
      {mode === "inline" && content()}
      <Modal
        open={mode !== "inline"}
        onClose={() => setMode("inline")}
        title="Facilities recorded by barangay"
        fullScreen={mode === "present"}
      >
        {mode !== "inline" && content(mode === "present")}
      </Modal>
    </section>
  );
}
