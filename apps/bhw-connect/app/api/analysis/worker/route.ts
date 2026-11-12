import { timingSafeEqual } from "node:crypto";
import { NextResponse } from "next/server";
import { analysisInputSchema, analysisResponse } from "@/lib/ai/analysis-chat";
import { workerCall } from "@/lib/analysis-projects/db";
import { analysisUsage, summariseUsage, type ProviderUse } from "@/lib/analysis-projects/usage";
import type { Json } from "@/lib/db/database.types";
export const runtime = "nodejs";
export async function POST(request: Request) {
  const secret = process.env.CRON_SECRET;
  const expected = Buffer.from(`Bearer ${secret ?? ""}`);
  const supplied = Buffer.from(request.headers.get("authorization") ?? "");
  if (!secret || expected.length !== supplied.length || !timingSafeEqual(expected, supplied))
    return NextResponse.json({ error: "Unauthorized" }, { status: 401 });
  const claimed = (await workerCall("claim")) as {
    id: string;
    lease_id: string;
    input: unknown;
  } | null;
  if (!claimed) return NextResponse.json({ worked: false });
  const uses: ProviderUse[] = [];
  let active = true;
  let progress = "Analysing sources";
  let busy = false;
  const heartbeat = async () => {
    if (busy) return;
    busy = true;
    try {
      const state = (await workerCall("heartbeat", claimed.id, claimed.lease_id, { progress })) as {
        active: boolean;
      };
      active = state.active;
    } catch {
      active = false;
    } finally {
      busy = false;
    }
  };
  const timer = setInterval(() => void heartbeat(), 10_000);
  try {
    const input = analysisInputSchema.parse(claimed.input);
    let evidence: unknown[] = [];
    const text = await analysisUsage.run(uses, async () => {
      const response = await analysisResponse(
        input,
        (value) => {
          evidence = value;
        },
        (event) => {
          if (!active) throw new Error("Job is no longer active");
          progress =
            event.type === "tool_call" ? "Looking up data and sources" : "Composing the answer";
        },
      );
      return response.text();
    });
    const events = text
      .split("\n")
      .filter(Boolean)
      .map((line) => JSON.parse(line) as Record<string, unknown>);
    const failed = events.find((e) => e.type === "capacity" || e.type === "error");
    const message = events.find((e) => e.type === "message");
    if (failed || !message)
      throw new Error(
        typeof failed?.message === "string" ? failed.message : "No answer was returned.",
      );
    progress = "Saving results";
    await heartbeat();
    if (!active) return NextResponse.json({ worked: true, published: false });
    const route = events.find((e) => e.type === "route")?.route as { scope?: unknown } | undefined;
    const result = { answer: message.content, events, evidence };
    const saved = (await workerCall("finish", claimed.id, claimed.lease_id, {
      result: result as Json,
      usage: summariseUsage(uses) as Json,
      context: route ? ({ carriedScope: route.scope ?? null } as Json) : {},
    })) as { active: boolean };
    return NextResponse.json({ worked: true, published: saved.active });
  } catch (error) {
    await workerCall("fail", claimed.id, claimed.lease_id, {
      error: error instanceof Error ? error.message : "Analysis failed",
      usage: summariseUsage(uses) as Json,
    });
    return NextResponse.json({ worked: true, published: false });
  } finally {
    clearInterval(timer);
  }
}
