/** Traverse GeoJSON positions, not serialized numbers (which lose integers and altitude). */
export function geometryPositions(geometry: unknown): [number, number][] {
  if (!geometry || typeof geometry !== "object") return [];
  const g = geometry as { type?: string; coordinates?: unknown; geometries?: unknown[] };
  if (g.type === "GeometryCollection") return (g.geometries ?? []).flatMap(geometryPositions);
  const depths: Record<string, number> = {
    Point: 0,
    MultiPoint: 1,
    LineString: 1,
    MultiLineString: 2,
    Polygon: 2,
    MultiPolygon: 3,
  };
  const depth = g.type ? depths[g.type] : undefined;
  if (depth === undefined) return [];
  const result: [number, number][] = [];
  function walk(value: unknown, remaining: number) {
    if (!Array.isArray(value)) return;
    if (remaining > 0) {
      value.forEach((v) => walk(v, remaining - 1));
      return;
    }
    const [x, y] = value;
    if (
      typeof x === "number" &&
      typeof y === "number" &&
      Number.isFinite(x) &&
      Number.isFinite(y) &&
      Math.abs(x) <= 180 &&
      Math.abs(y) <= 90
    )
      result.push([x, y]);
  }
  walk(g.coordinates, depth);
  return result;
}
