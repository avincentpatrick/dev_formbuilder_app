import type { AssistantFigure } from "@/lib/ai/figure-from-payload";
export type ProjectContext = {
  dataset?: string;
  carriedScope?: { geoCode: string; geoLevel: string; geoName: string } | null;
};
export type ProjectSummary = {
  id: string;
  title: string;
  role: "owner" | "editor" | "viewer";
  revision: number;
  updated_at: string;
};
export type SavedJob = {
  created_at: string;
  id: string;
  question: string;
  status: "queued" | "running" | "completed" | "failed" | "cancelled";
  progress: string;
  attempt: number;
  error: string | null;
  result: {
    answer: string;
    events: Array<Record<string, unknown>>;
    figure?: AssistantFigure;
    evidence: unknown[];
  } | null;
  usage: { requests: number; estimatedCostUsd: number | null; note: string } | null;
};
export type SavedProject = ProjectSummary & {
  owner_id: string;
  state: ProjectContext;
  members: Array<{ user_id: string; role: "editor" | "viewer" }>;
  jobs: SavedJob[];
};
