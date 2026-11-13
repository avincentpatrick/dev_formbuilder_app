import { NextResponse } from "next/server";
import { z } from "zod";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { projectCall } from "@/lib/analysis-projects/db";
import type { SavedStatistic } from "@/lib/analysis-statistics/types";
import {
  statisticCsv,
  statisticSvg,
  statisticNotes,
  statisticColumns,
} from "@/lib/analysis-statistics/export";
import { executeStatistic, hash } from "@/lib/analysis-statistics/execute";
import { resvgFont } from "@/lib/exports/render-png";
export const runtime = "nodejs";
type Context = { params: Promise<{ projectId: string; jobId: string; index: string }> };
async function load(context: Context) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    throw Object.assign(new Error("Access required"), {
      status: auth.status === "signed_out" ? 401 : 403,
    });
  const p = z
    .object({
      projectId: z.uuid(),
      jobId: z.uuid(),
      index: z.coerce.number().int().min(0).max(100),
    })
    .parse(await context.params);
  const artifact = (await projectCall(auth.user.id, "artifact", p.projectId, {
    jobId: p.jobId,
  })) as unknown as { result: { statistics?: SavedStatistic[] } | null };
  const saved = artifact.result?.statistics?.[p.index];
  if (!saved) throw Object.assign(new Error("Statistical result unavailable"), { status: 404 });
  if (hash(saved.manifest) !== saved.result.inputHash)
    throw new Error("Stored calculation input failed integrity verification");
  return saved;
}
const fail = (e: unknown) =>
  NextResponse.json(
    { error: (e as Error).message || "Result unavailable" },
    {
      status: (e as { status?: number }).status ?? 403,
      headers: { "Cache-Control": "private, no-store" },
    },
  );
export async function GET(request: Request, context: Context) {
  try {
    const saved = await load(context);
    const r = saved.result;
    const format = new URL(request.url).searchParams.get("format") ?? "json";
    const headers: Record<string, string> = {
      "Cache-Control": "private, no-store",
      "X-Content-Type-Options": "nosniff",
      "Content-Disposition": `attachment; filename="statistics-${r.id}.${format}"`,
    };
    if (format === "csv")
      return new Response(statisticCsv(r), {
        headers: { ...headers, "Content-Type": "text/csv; charset=utf-8" },
      });
    if (format === "svg")
      return new Response(statisticSvg(r), {
        headers: { ...headers, "Content-Type": "image/svg+xml" },
      });
    if (format === "png") {
      const { Resvg } = await import("@resvg/resvg-js");
      const bytes = new Resvg(statisticSvg(r), {
        font: resvgFont(),
        fitTo: { mode: "width", value: 3300 },
      })
        .render()
        .asPng();
      return new Response(new Uint8Array(bytes), {
        headers: { ...headers, "Content-Type": "image/png" },
      });
    }
    if (format === "xlsx") {
      const { default: ExcelJS } = await import("exceljs");
      const workbook = new ExcelJS.Workbook();
      const sheet = workbook.addWorksheet("Analysis");
      sheet.addRow([r.title]);
      sheet.addRow(statisticColumns);
      for (const row of r.rows) {
        const cells = sheet.addRow([
          row.label,
          row.metric,
          row.estimate ?? "–",
          row.lower ?? "–",
          row.upper ?? "–",
          row.p === null ? "–" : row.p < 0.001 ? "<0.001" : row.p,
          row.unit,
        ]);
        for (const index of [3, 4, 5, 6]) {
          const cell = cells.getCell(index);
          if (typeof cell.value === "number")
            cell.numFmt =
              index === 3 &&
              ["n", "missing", "numerator", "denominator"].includes(row.metric) &&
              Number.isInteger(cell.value)
                ? "0"
                : cell.value !== 0 && (Math.abs(cell.value) < 0.001 || Math.abs(cell.value) >= 1e9)
                  ? "0.000E+00"
                  : "0.000";
        }
      }
      sheet.addRow([]);
      for (const n of statisticNotes(r)) sheet.addRow([n]);
      sheet.getRow(1).font = { bold: true, size: 14 };
      sheet.getRow(2).font = { bold: true };
      sheet.columns.forEach((c, i) => {
        c.width = i === 0 ? 42 : 24;
      });
      return new Response(new Uint8Array(await workbook.xlsx.writeBuffer()), {
        headers: {
          ...headers,
          "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        },
      });
    }
    if (format !== "json") throw new Error("Unsupported export format");
    return new Response(JSON.stringify(saved, null, 2), {
      headers: { ...headers, "Content-Type": "application/json" },
    });
  } catch (e) {
    return fail(e);
  }
}
export async function POST(_request: Request, context: Context) {
  try {
    const saved = await load(context);
    const replay = await executeStatistic(saved.manifest);
    if (replay.kind !== "statistical-result") return NextResponse.json(replay, { status: 422 });
    await load(context); // Recheck revocation/deletion after the potentially long calculation.
    return NextResponse.json(
      {
        reproduced: replay.resultHash === saved.result.resultHash,
        result: replay,
        note: "Comparison includes software versions. A changed runtime may change the result hash; the saved result is retained.",
      },
      { headers: { "Cache-Control": "private, no-store" } },
    );
  } catch (e) {
    return fail(e);
  }
}
