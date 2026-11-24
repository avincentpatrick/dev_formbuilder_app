import { NextResponse } from "next/server";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import { readNhfrHeadline } from "@/lib/db/nhfr-headline";

export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const scalar = ["mode", "location", "district", "citymun", "barangay"];
  const types = [...new Set(params.getAll("type"))];
  if (
    scalar.some((k) => params.getAll(k).length > 1) ||
    types.length > 200 ||
    types.some((t) => !t || t.length > 200)
  )
    return NextResponse.json({ error: "Invalid headline selection" }, { status: 400 });
  const parsed = parseCatalogParams(Object.fromEntries(params));
  if (!parsed || parsed.selection.mode === "all")
    return NextResponse.json({ error: "Choose a supported location" }, { status: 400 });
  try {
    const summary = await readNhfrHeadline(parsed.selection, types);
    if (summary) return NextResponse.json(summary, { headers: { "Cache-Control": "no-store" } });
  } catch {
    /* Keep database errors private. */
  }
  return NextResponse.json(
    { error: "The selected facility summary could not be verified. Please try again." },
    { status: 503 },
  );
}
