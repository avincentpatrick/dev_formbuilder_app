import { analysisChat } from "@/lib/ai/analysis-chat";

export const runtime = "nodejs";

// Compatibility endpoints share the same approval check before any answer path.
export const POST = analysisChat;
