import { afterEach, expect, it, vi } from "vitest";
import { drawPngLocator } from "./png-locator";
import { nationalLocator } from "./orientation";
import type { BoundaryCollection } from "@/lib/geo/locator-shapes";
const shapes: BoundaryCollection = { features: [{ properties:{geo_code:"01"},geometry:{type:"Polygon",coordinates:[[[120,10],[121,10],[121,11],[120,11],[120,10]]]}}] };
afterEach(()=>vi.unstubAllGlobals());
it("draws existing projected paths and the small-shape ring without a new position",()=>{
  class Path { constructor(public data:string) {} }
  vi.stubGlobal("Path2D",Path);
  const ctx={save:vi.fn(),restore:vi.fn(),translate:vi.fn(),scale:vi.fn(),fill:vi.fn(),stroke:vi.fn(),beginPath:vi.fn(),arc:vi.fn()};
  const locator={viewBox:"0 0 100 200",contextPath:"M0 0L100 0L100 200Z",highlightPath:"",marker:{cx:25,cy:80}};
  drawPngLocator(ctx as unknown as CanvasRenderingContext2D,locator,24,50);
  expect(ctx.scale).toHaveBeenCalledWith(1.08,1.08);
  expect(ctx.fill.mock.calls[0][0]).toEqual(new Path(locator.contextPath));
  expect(ctx.arc).toHaveBeenCalledWith(25,80,6,0,Math.PI*2);
  expect(ctx.restore).toHaveBeenCalledOnce();
});
it("shares the live locator's missing-shape behavior",()=>{
  expect(nationalLocator(shapes,shapes,"07306")).toBeNull();
  const locator=nationalLocator(shapes,shapes,null)!;
  expect(locator.highlightPath).not.toBe("");
  expect(()=>drawPngLocator({} as CanvasRenderingContext2D,{...locator,viewBox:"0 0 0 NaN"},0,0)).toThrow("Invalid locator bounds");
});
