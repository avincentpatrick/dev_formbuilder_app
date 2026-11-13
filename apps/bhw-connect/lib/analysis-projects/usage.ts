import "server-only";
import { AsyncLocalStorage } from "node:async_hooks";
import type { ProviderCompletion } from "@/lib/ai/providers/types";
export type ProviderUse = {
  provider: string;
  billingUnit?: "tokens" | "request";
  model: string | null;
  inputTokens: number | null;
  outputTokens: number | null;
};
export const analysisUsage = new AsyncLocalStorage<ProviderUse[]>();
export function observeProviderUse(provider: string, completion: ProviderCompletion) {
  analysisUsage.getStore()?.push({
    provider,
    model: completion.model ?? null,
    inputTokens: completion.usage?.inputTokens ?? null,
    outputTokens: completion.usage?.outputTokens ?? null,
  });
}
export function summariseUsage(calls: ProviderUse[], pricesText = process.env.BHW_AI_PRICES_JSON) {
  let prices: Record<
    string,
    { inputPerMillion?: number; outputPerMillion?: number; perRequest?: number }
  > = {};
  try {
    prices = JSON.parse(pricesText ?? "{}");
  } catch {
    /* Unconfigured prices are unknown, never zero. */
  }
  let cost = 0;
  let known = calls.length > 0;
  for (const c of calls) {
    const p = prices?.[`${c.provider}/${c.model}`];
    if (c.billingUnit === "request") {
      if (
        p &&
        typeof p.perRequest === "number" &&
        Number.isFinite(p.perRequest) &&
        p.perRequest >= 0
      )
        cost += p.perRequest;
      else known = false;
      continue;
    }
    if (
      !p ||
      typeof p.inputPerMillion !== "number" ||
      typeof p.outputPerMillion !== "number" ||
      c.inputTokens === null ||
      c.outputTokens === null ||
      !Number.isFinite(p.inputPerMillion) ||
      !Number.isFinite(p.outputPerMillion) ||
      p.inputPerMillion < 0 ||
      p.outputPerMillion < 0
    ) {
      known = false;
      continue;
    }
    cost += (c.inputTokens * p.inputPerMillion + c.outputTokens * p.outputPerMillion) / 1_000_000;
  }
  return {
    requests: calls.length,
    calls,
    estimatedCostUsd: known ? cost : null,
    note: known
      ? "Estimate using configured provider prices; excludes unrecorded hosting and other service charges."
      : "Cost unavailable: provider usage or matching model prices are missing. This does not mean usage is free.",
  };
}
