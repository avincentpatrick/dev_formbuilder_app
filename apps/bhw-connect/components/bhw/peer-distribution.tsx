"use client";
import { DotStrip } from "@/components/home/mini-viz";
import { peerRankSentence } from "@/components/explore/peer-rank-chip";
import { peerDistribution } from "@/lib/bhw/peer-distribution";
import { percentageFormatter } from "@/lib/format";
import { formatValue } from "@/components/catalog/presentation";
import type { MapPayload } from "@/lib/maps/prototype";
import type { CountRange } from "@/lib/maps/value-filter";

const labels = {
  region: ["region", "regions"],
  province: ["province-level area", "province-level areas"],
  citymun: ["city/municipality", "cities/municipalities"],
  barangay: ["barangay", "barangays"],
} as const;
export function BhwPeerDistribution({
  data,
  unit,
  label,
  period,
  selected,
  range = {},
  onSelect,
}: {
  data: MapPayload;
  unit: string;
  label: string;
  period: string;
  selected: string | null;
  range?: CountRange;
  onSelect: (code: string) => void;
}) {
  const peers = peerDistribution(data.rows, unit, range);
  const chosen = peers.ranked.find((r) => r.code === selected);
  const selectedStatus = peers.statuses.find((r) => r.code === selected)?.status;
  const [singular, plural] = labels[data.childLevel];
  const percent = percentageFormatter(peers.ranked.map((r) => r.value));
  const display = (value: number) => (unit === "%" ? percent(value) : formatValue({ value }, unit));
  const sentence = chosen
    ? peerRankSentence({
        rank: { rankPosition: chosen.rank, nSiblings: peers.ranked.length, nTotal: null },
        geoName: chosen.name,
        parentName: data.area,
        siblingPlural: plural,
        indicatorLabel: label,
      })
    : null;
  return (
    <section
      aria-label="Locality position among peers"
      className="space-y-3 rounded border border-border p-3"
    >
      <h3 className="font-semibold">Where does the selected locality sit among peers?</h3>
      <p className="text-sm">
        {label} · {period} · {unit} · {plural} within {data.area}
      </p>
      {data.childLevel === "province" && (
        <p className="text-sm text-muted">
          Province-level areas include separately reported cities where supplied by the source.
        </p>
      )}
      {!peers.validUniverse || data.valueSupport === "navigation-only" ? (
        <p role="status">
          A comparable peer universe is unavailable for this view. No ranks or distribution are
          shown.
        </p>
      ) : (
        <>
          <p className="text-sm">
            {peers.ranked.length} of {peers.total} listed {plural} are eligible. Excluded:{" "}
            {peers.unavailable} without a usable public value, {peers.smallSample} flagged as small
            samples, {peers.filtered} outside the selected value range. Areas without shapes stay
            eligible. This is the returned child-area universe, not a claim of complete nationwide
            reporting.
          </p>
          <p className="text-sm text-muted">
            Largest value first; equal raw values share a rank and the next rank skips those places.
            Names order tied rows. This describes relative values, not quality, staffing need or
            change over time. The published parent figure is unchanged.
          </p>
          {sentence ? (
            <p role="status">
              {sentence}{" "}
              {chosen!.tied > 1
                ? `${chosen!.tied} places share this rank.`
                : "No tie at this value."}
            </p>
          ) : (
            <p role="status">
              {selected
                ? selectedStatus === "small-sample"
                  ? "The selected locality is flagged as a small sample and is not ranked."
                  : selectedStatus === "filtered"
                    ? "The selected locality is outside the chosen value range and is not ranked."
                    : "No usable public peer value is available for the selected locality; it is not ranked."
                : "Select an area on the map or in the table to mark its position."}
            </p>
          )}
          {peers.ranked.length ? (
            <>
              <div className="px-2">
                <DotStrip
                  points={peers.ranked.map((r) => r.value)}
                  marker={chosen?.value ?? null}
                  markerLabel={chosen?.name}
                  pointLabel={singular}
                  pointPlural={plural}
                  formatValue={display}
                  ariaLabel={`Observed ${label.toLowerCase()} among ${peers.ranked.length} eligible ${plural} in ${data.area}; lowest ${display(peers.ranked.at(-1)!.value)}, highest ${display(peers.ranked[0].value)}.${chosen ? ` Selected ${chosen.name}: ${display(chosen.value)}.` : " No selected marker."}`}
                />
              </div>
              <p className="text-xs text-muted">
                One dot per eligible area; overlapping dots can represent ties. The exact table
                lists every eligible area.
              </p>
              <div
                role="region"
                aria-label="Peer ranks and exact values"
                tabIndex={0}
                className="max-h-72 overflow-auto"
              >
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">
                    {label}: peer ranks within {data.area}
                  </caption>
                  <thead>
                    <tr>
                      <th scope="col" className="p-2">
                        Rank
                      </th>
                      <th scope="col" className="p-2">
                        Area
                      </th>
                      <th scope="col" className="p-2">
                        Value
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {peers.ranked.map((row) => (
                      <tr key={row.code} className="border-t border-border">
                        <td className="p-2">
                          {row.rank}
                          {row.tied > 1 ? " (tied)" : ""}
                        </td>
                        <th scope="row" className="p-2 font-normal">
                          <button
                            aria-pressed={row.code === selected}
                            className="min-h-11 text-left underline"
                            onClick={() => onSelect(row.code)}
                          >
                            {row.name} ({row.code})
                          </button>
                        </th>
                        <td className="whitespace-nowrap p-2">{display(row.value)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          ) : (
            <p>No eligible peer values. This does not mean zero.</p>
          )}
        </>
      )}
      <p className="text-xs text-muted">
        Source: {data.source} · source date: {data.date ?? "not supplied"}. Missing or withheld
        observations are never reconstructed from peers.
      </p>
    </section>
  );
}
