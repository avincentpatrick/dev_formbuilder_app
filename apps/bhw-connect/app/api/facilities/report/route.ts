import { NextResponse } from "next/server";
import { parseReportQuery, reportStatus } from "@/lib/facilities/report";
import { readNhfrReport } from "@/lib/db/nhfr-report";
export async function GET(request: Request) {
  const query = parseReportQuery(new URL(request.url).searchParams);
  if (!query)
    return NextResponse.json({ error: "Choose a supported full location" }, { status: 400 });
  const report = await readNhfrReport(query.code, query.level);
  return report
    ? NextResponse.json(reportStatus(report), { headers: { "Cache-Control": "no-store" } })
    : NextResponse.json(
        { error: "The complete location report could not be verified. Please try again." },
        { status: 503 },
      );
}
