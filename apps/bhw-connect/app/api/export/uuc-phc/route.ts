import { NextResponse } from "next/server";
import { z } from "zod";
import { geoLevelSchema } from "@/lib/filters/schema";
import { slugify } from "@/lib/exports/query";
import { buildUucPhcFigure, renderUucPhcPng } from "@/lib/exports/uuc-phc-figure";
import { resolveUucEdition } from "@/lib/db/uuc-phc-edition";

export const runtime = "nodejs";

const querySchema = z.object({
  geoCode: z.string().min(1),
  geoLevel: geoLevelSchema,
});

/** One-page PNG summary of one edition of the UUC for PHC list (`?edition=`, default newest) for a chosen area: the count against its
 * barangay denominator, the listed/not-listed split, and either a child-unit breakdown or, for a
 * city/municipality, the listed barangays by name. */
export async function GET(request: Request) {
  const { searchParams } = new URL(request.url);
  const parsed = querySchema.safeParse({
    geoCode: searchParams.get("geoCode"),
    geoLevel: searchParams.get("geoLevel"),
  });
  if (!parsed.success) {
    return NextResponse.json({ error: "Invalid export parameters" }, { status: 400 });
  }

  const { edition } = await resolveUucEdition(Object.fromEntries(searchParams));
  const figure = await buildUucPhcFigure(parsed.data.geoCode, parsed.data.geoLevel, edition);
  if (!figure) {
    return NextResponse.json({ error: "No UUC for PHC data for this area" }, { status: 404 });
  }

  const png = await renderUucPhcPng(figure);

  return new NextResponse(new Uint8Array(png), {
    headers: {
      "Content-Type": "image/png",
      "Content-Disposition": `attachment; filename="${slugify(...figure.filenameParts)}.png"`,
    },
  });
}
