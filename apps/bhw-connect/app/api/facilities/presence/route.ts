import { NextResponse } from "next/server";
import { parsePresenceQuery } from "@/lib/facilities/presence-query";
import { getNhfrPresence } from "@/lib/db/nhfr-presence";
export async function GET(request: Request) {
  const query = parsePresenceQuery(new URL(request.url).searchParams);
  if (!query)
    return NextResponse.json({ error: "Invalid barangay presence selection" }, { status: 400 });
  try {
    const input = await getNhfrPresence(query.code, query.level, query.types);
    if (input) return NextResponse.json(input, { headers: { "Cache-Control": "no-store" } });
  } catch {
    /* Keep database details private. */
  }
  return NextResponse.json(
    { error: "Barangay presence could not be verified. Please try again." },
    { status: 503 },
  );
}
