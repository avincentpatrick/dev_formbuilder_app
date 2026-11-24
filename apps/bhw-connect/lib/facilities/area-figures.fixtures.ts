import type { DistributionInput } from "./distribution";
import type { PresenceInput } from "./presence";
/** Generated public aggregates only; no source records. */
export function areaFixture() {
  const input: DistributionInput = {
    code: "PH",
    level: "national",
    name: "Synthetic parent",
    total: { count: 0, government: 0, private: 0 },
    areas: [],
    types: [],
    cells: [],
  };
  const presence: PresenceInput = {
    code: "PH",
    level: "national",
    name: "Synthetic parent",
    types: ["Hospital", "Station"],
    selectedTypes: [],
    rows: [],
  };
  for (let i = 0; i < 18; i++) {
    const code = String(i + 1).padStart(2, "0"),
      hospital = i === 17 ? 0 : i + 1,
      station = i === 17 ? 0 : 18 - i,
      count = hospital + station;
    input.areas.push({
      code,
      name: `Area ${code}`,
      level: "region",
      count,
      government: station,
      private: hospital,
    });
    if (hospital)
      input.cells.push({
        code,
        type: "Hospital",
        count: hospital,
        government: 0,
        private: hospital,
      });
    if (station)
      input.cells.push({ code, type: "Station", count: station, government: station, private: 0 });
    input.total.count += count;
    input.total.government += station;
    input.total.private += hospital;
    presence.rows.push({
      code,
      name: `Area ${code}`,
      level: "region",
      present: i === 17 ? 0 : i + 3,
      barangays: i === 17 ? 0 : i === 0 ? 10 : i === 1 ? 20 : 30,
    });
  }
  for (const type of presence.types) {
    const cells = input.cells.filter((c) => c.type === type);
    input.types.push({
      type,
      count: cells.reduce((n, c) => n + c.count, 0),
      government: cells.reduce((n, c) => n + c.government, 0),
      private: cells.reduce((n, c) => n + c.private, 0),
    });
  }
  return { input, presence };
}
export function filteredAreaPresence(types: string[]) {
  const { input, presence } = areaFixture();
  presence.selectedTypes = types;
  if (types.length === 1)
    presence.rows = presence.rows.map((p) => ({
      ...p,
      present: Math.min(
        p.present,
        input.cells.find((c) => c.code === p.code && c.type === types[0])?.count ?? 0,
      ),
    }));
  return presence;
}
