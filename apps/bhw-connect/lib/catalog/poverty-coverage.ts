import type { MapPayload } from "@/lib/maps/prototype";
export function povertyCoverage(payload: MapPayload | { state: string }): string {
  if (payload.state === "loading") return "Checking coverage…";
  if (payload.state === "failed") return "Coverage could not be loaded — try again";
  if (payload.state === "suppressed") return "Withheld";
  if (payload.state === "unsupported") return "Municipal comparison unsupported";
  if (payload.state !== "available") return "Accepted municipal source unavailable";
  const data = payload as MapPayload;
  if (
    data.state !== "available" ||
    data.childLevel !== "citymun" ||
    data.valueSupport !== "supported" ||
    !Array.isArray(data.rows)
  )
    return "Coverage could not be verified";
  const count = data.rows.filter(
    (row) =>
      typeof row.value === "number" &&
      Number.isFinite(row.value) &&
      row.value >= 0 &&
      row.value <= 100,
  ).length;
  return `${count} of ${data.rows.length} cities/municipalities have usable SAE estimates`;
}
