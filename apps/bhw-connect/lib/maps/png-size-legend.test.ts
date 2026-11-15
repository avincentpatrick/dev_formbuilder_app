import { afterEach, expect, it, vi } from "vitest";
import { captureMapFrame, composeMapPng, type MapFrame } from "./png";
import { datasetMapExport } from "./export";
import { initialMapChoice, type MapPayload } from "./prototype";

const data: MapPayload = {
  state: "available",
  area: "Synthetic region",
  childLevel: "province",
  rows: [
    { code: "01", name: "Large", value: 100 },
    { code: "02", name: "Small", value: 25 },
    { code: "03", name: "Zero", value: 0 },
  ],
  total: 125,
  source: "Synthetic",
  date: null,
  license: "Test only",
  geometryUrl: "unused",
};
function canvasFixture() {
  const ctx = {
    measureText: (s: string) => ({ width: s.length * 8 }),
    fillText: vi.fn(),
    fillRect: vi.fn(),
    drawImage: vi.fn(),
    beginPath: vi.fn(),
    arc: vi.fn(),
    fill: vi.fn(),
    stroke: vi.fn(),
  };
  const canvas = {
    width: 1200,
    height: 500,
    getContext: () => ctx,
    toBlob: (callback: (blob: Blob) => void) =>
      callback(new Blob(["synthetic"], { type: "image/png" })),
  };
  vi.stubGlobal("document", { createElement: () => canvas });
  return { ctx, canvas };
}
afterEach(() => vi.unstubAllGlobals());
it("draws proportional references and a separate zero marker at the captured CSS scale", async () => {
  const { ctx } = canvasFixture();
  const frame = { width: 1200, height: 500, cssPixelScale: 3 } as MapFrame;
  const payload = datasetMapExport(
    { ...initialMapChoice("bhw-stepzero-2026"), valueMin: 50 },
    data,
    [],
    ["01"],
  );
  await composeMapPng(frame, payload);
  const radii = ctx.arc.mock.calls.map((args) => args[2]);
  expect(radii).toEqual([36, 72, 9]);
  expect(radii[0] ** 2 / radii[1] ** 2).toBe(25 / 100);
  expect(ctx.fill).toHaveBeenCalledTimes(2); // Zero is hollow, never a positive magnitude.
  expect(ctx.stroke).toHaveBeenCalledTimes(3);
  const text = ctx.fillText.mock.calls.map((args) => args[0]).join("\n");
  expect(text).toContain("25 people"); // Full-set reference survives filtering and missing geometry.
  expect(text).toContain("100 people");
  expect(text).toContain("0 — reported zero");
});
it("does not invent positive circles for missing/zero data or use circles in choropleth exports", async () => {
  const { ctx } = canvasFixture();
  const frame = { width: 1200, height: 500 } as MapFrame;
  await composeMapPng(
    frame,
    datasetMapExport(
      initialMapChoice("bhw-stepzero-2026"),
      {
        ...data,
        rows: [
          { code: "01", name: "Missing", value: null },
          { code: "02", name: "Zero", value: 0 },
        ],
      },
      [],
      [],
    ),
  );
  expect(ctx.arc.mock.calls.map((args) => args[2])).toEqual([3]);
  expect(ctx.fill).not.toHaveBeenCalled();
  expect(
    ctx.fillText.mock.calls.some((args) => String(args[0]).includes("No positive counts")),
  ).toBe(true);
  ctx.arc.mockClear();
  await composeMapPng(
    frame,
    datasetMapExport(
      { ...initialMapChoice("bhw-stepzero-2026"), view: "choropleth" },
      data,
      [],
      [],
    ),
  );
  expect(ctx.arc).not.toHaveBeenCalled();
});
it("captures CSS scaling independently of the WebGL device pixel ratio", async () => {
  canvasFixture();
  const listeners = new Map<string, () => void>();
  const map = {
    on: (name: string, fn: () => void) => listeners.set(name, fn),
    off: vi.fn(),
    loaded: () => true,
    getCanvas: () => ({ width: 800, height: 600, clientWidth: 400 }),
    triggerRepaint: () => listeners.get("render")?.(),
  };
  const frame = await captureMapFrame(map as unknown as Parameters<typeof captureMapFrame>[0]);
  expect(frame.cssPixelScale).toBe(3);
  expect(frame.height).toBe(900);
});
