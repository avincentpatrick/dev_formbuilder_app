import { expect, it } from "vitest";
import { boundaryCenter, mapView, sizeLegend, symbolRadius } from "./symbols";
import { initialMapChoice, mapHref, parseMapChoice, type MapPayload } from "./prototype";
import { datasetMapExport } from "./export";
import { pngNotes } from "./png";

it("encodes magnitude by area and treats zero independently", () => {
  expect(symbolRadius(100, 100) ** 2 / symbolRadius(25, 100) ** 2).toBe(4);
  expect(symbolRadius(0, 100)).toBe(0);
  expect(symbolRadius(0, 0)).toBe(0);
  expect(
    sizeLegend([
      { code: "01", name: "Zero", value: 0 },
      { code: "02", name: "Missing", value: null },
    ]),
  ).toEqual([]);
});
it("defaults BHW counts to circles and preserves explicit view links", () => {
  const choice = initialMapChoice("bhw-stepzero-2026");
  expect(mapView(choice)).toBe("circles");
  const shared = { ...choice, view: "choropleth" as const };
  expect(parseMapChoice(new URLSearchParams(mapHref(shared).split("?")[1]))).toEqual(shared);
  expect(mapView({ ...choice, indicator: "bhw.declared-accreditation" })).toBe("choropleth");
  for (const query of [
    "dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&view=circles",
    "dataset=bhw-stepzero-2026&view=unknown",
    "dataset=bhw-stepzero-2026&view=circles&view=choropleth",
  ])
    expect(parseMapChoice(new URLSearchParams(query))).toBeNull();
  expect(mapView(initialMapChoice("nhfr-2026-09"))).toBe("choropleth");
});
it("leaves district view links to their separate parser", () => {
  for (const view of ["province", "district"]) {
    expect(
      parseMapChoice(
        new URLSearchParams(`dataset=ph-legislative-districts&view=${view}&district=syn-1st`),
      ),
    ).toEqual(initialMapChoice("ph-legislative-districts"));
  }
});
it("does not fabricate positions for missing geometry", () => {
  expect(boundaryCenter(null)).toBeNull();
  expect(
    boundaryCenter({
      type: "Polygon",
      coordinates: [
        [
          [120, 10],
          [122, 10],
          [122, 12],
          [120, 10],
        ],
      ],
    }),
  ).toEqual([121, 11]);
});
it("retains Region VII HUC values without inventing symbols and exports effective size semantics", () => {
  const choice = { ...initialMapChoice("bhw-stepzero-2026"), level: "region" as const, code: "07" };
  const data: MapPayload = {
    state: "available",
    rows: [
      { code: "07012", name: "Bohol", value: 0 },
      { code: "07022", name: "Cebu", value: 100 },
      { code: "07306", name: "Cebu City", value: 50 },
      { code: "07311", name: "Lapu-Lapu", value: null },
      { code: "07313", name: "Mandaue", value: 25 },
    ],
    total: 175,
    area: "Region VII",
    childLevel: "province",
    source: "Synthetic",
    date: "2025-12-31",
    license: null,
    geometryUrl: "synthetic",
  };
  const output = datasetMapExport(choice, data, [], ["07012", "07022"]);
  expect(output.selection.view).toBe("circles");
  expect(output.legend.method).toBe("circle-area-proportional-to-count");
  expect(output.legend.symbols?.maximum).toBe(100);
  expect(output.rows.filter((r) => r.geometry === "missing")).toHaveLength(3);
  expect(output.rows.find((r) => r.code === "07306")?.value).toBe(50);
  expect(output.total).toBe(175);
  expect(pngNotes(output).join(" ")).toContain("not worker addresses");
});
