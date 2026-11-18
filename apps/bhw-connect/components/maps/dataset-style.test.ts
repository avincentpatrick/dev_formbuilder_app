import { expect, it, vi } from "vitest";
import { createExpression } from "@maplibre/maplibre-gl-style-spec";
import { applyDatasetStyle } from "./dataset-style";
import { NO_DATA_COLOR } from "@/lib/charts/color-scale";

it("paints exact shared boundaries and filters symbols/labels without replacing their data", () => {
  const map = {
    getLayer: vi.fn(() => true),
    setPaintProperty: vi.fn(),
    setFilter: vi.fn(),
    setLayoutProperty: vi.fn(),
  };
  const rows = [
    { code: "zero", name: "Zero", value: 0 },
    { code: "edge", name: "Edge", value: 25 },
    { code: "missing", name: "Missing", value: null },
  ];
  applyDatasetStyle(
    map as never,
    rows,
    [
      { min: 0, max: 25, color: "#111111" },
      { min: 25, max: 100, color: "#222222" },
    ],
    ["edge"],
    "choropleth",
  );
  const evaluate = (expression: unknown, code: string, value: number | null) => {
    const parsed = createExpression(expression, "test");
    if (parsed.result !== "success") throw new Error(JSON.stringify(parsed.value));
    return parsed.value.evaluate({ zoom: 0 }, { type: 1, properties: { geo_code: code, value } });
  };
  const paint = map.setPaintProperty.mock.calls[0][2];
  expect(map.setLayoutProperty).toHaveBeenCalledWith("counts", "visibility", "none");
  expect(map.setLayoutProperty).toHaveBeenCalledWith("zeros", "visibility", "none");
  expect(map.setLayoutProperty).toHaveBeenCalledWith("place-labels", "text-radial-offset", 0.4);
  expect(evaluate(paint, "zero", 0)).toBe("#111111");
  expect(evaluate(paint, "edge", 25)).toBe("#ffffff");
  expect(evaluate(paint, "missing", null)).toBe(NO_DATA_COLOR);
  const filters = new Map(
    map.setFilter.mock.calls.map(([layer, expression]) => [layer, expression]),
  );
  expect(evaluate(filters.get("counts"), "edge", 25)).toBe(false);
  expect(evaluate(filters.get("zeros"), "zero", 0)).toBe(true);
  expect(evaluate(filters.get("place-labels"), "missing", null)).toBe(true);
  expect(evaluate(filters.get("place-labels"), "edge", 25)).toBe(false);
  map.setPaintProperty.mockClear();
  applyDatasetStyle(
    map as never,
    rows,
    [
      { min: 0, max: 25, color: "#111111" },
      { min: 25, max: 100, color: "#222222" },
    ],
    [],
    "choropleth",
  );
  expect(evaluate(map.setPaintProperty.mock.calls[0][2], "edge", 25)).toBe("#222222");
  applyDatasetStyle(map as never, rows, [], [], "circles");
  expect(map.setLayoutProperty).toHaveBeenCalledWith("counts", "visibility", "visible");
  expect(map.setLayoutProperty).toHaveBeenCalledWith("zeros", "visibility", "visible");
  expect(map.setLayoutProperty).toHaveBeenCalledWith("place-labels", "text-radial-offset", 1.8);
});
