import { NextResponse } from "next/server";
import { searchGeo } from "@/lib/db/search";
import { publicPlace } from "@/lib/bhw/comparison";

export async function GET(request: Request) {
  const q = new URL(request.url).searchParams.get("q")?.trim() ?? "";
  if (!q || q.length > 100)
    return NextResponse.json(
      { error: "Enter a place name or code (up to 100 characters)." },
      { status: 400 },
    );
  try {
    const matches = await searchGeo(q, 12, { throwOnError: true });
    return NextResponse.json({ results: matches.map(publicPlace) });
  } catch {
    return NextResponse.json(
      { error: "Place search could not be loaded. Please retry." },
      { status: 503 },
    );
  }
}
