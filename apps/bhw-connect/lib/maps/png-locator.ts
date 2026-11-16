import type { LocatorMap } from "@/lib/geo/locator-shapes";
export type PngLocator = { map: LocatorMap; name: string; selected: boolean };

/** Draw the same simplified paths and small-shape ring as the live locator. */
export function drawPngLocator(
  ctx: CanvasRenderingContext2D,
  locator: LocatorMap,
  x: number,
  y: number,
) {
  const [left, top, width, height] = locator.viewBox.split(/\s+/).map(Number);
  if (![left, top, width, height].every(Number.isFinite) || width <= 0 || height <= 0)
    throw new Error("Invalid locator bounds");
  const scale = 216 / Math.max(width, height);
  ctx.save();
  ctx.translate(x + 12 - left * scale, y + 12 - top * scale);
  ctx.scale(scale, scale);
  ctx.fillStyle = "#e5e7eb";
  ctx.strokeStyle = "white";
  ctx.lineWidth = 0.75 / scale;
  const context = new Path2D(locator.contextPath);
  ctx.fill(context);
  ctx.stroke(context);
  ctx.fillStyle = "#0c747b";
  ctx.strokeStyle = "#0c747b";
  ctx.lineWidth = 1 / scale;
  const highlight = new Path2D(locator.highlightPath);
  ctx.fill(highlight);
  ctx.stroke(highlight);
  if (locator.marker) {
    ctx.beginPath();
    ctx.arc(locator.marker.cx, locator.marker.cy, 6, 0, Math.PI * 2);
    ctx.lineWidth = 2 / scale;
    ctx.stroke();
  }
  ctx.restore();
}
