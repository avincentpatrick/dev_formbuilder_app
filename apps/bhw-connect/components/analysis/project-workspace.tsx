"use client";
import Link from "next/link";
import { SourcePanel } from "./source-panel";
import { useCallback, useEffect, useRef, useState } from "react";
import { withBasePath } from "@/lib/base-path";
import { AnswerMarkdown } from "@/components/admin/answer-markdown";
import { AssistantFigureView } from "@/components/admin/assistant-figure";
import type { AssistantFigure } from "@/lib/ai/figure-from-payload";
import type { ProjectContext, ProjectSummary, SavedProject } from "@/lib/analysis-projects/types";
async function api(body?: Record<string, unknown>) {
  const response = await fetch(
    withBasePath("/api/analysis/projects"),
    body
      ? {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        }
      : { cache: "no-store" },
  );
  const data = await response.json();
  if (!response.ok)
    throw Object.assign(new Error(data.error ?? "Project request failed"), {
      status: response.status,
    });
  return data;
}
export function ProjectWorkspace({
  projectId,
  context,
}: {
  projectId?: string;
  context: ProjectContext;
}) {
  const [projects, setProjects] = useState<ProjectSummary[]>([]);
  const [project, setProject] = useState<SavedProject | null>(null);
  const [activeId, setActiveId] = useState(projectId);
  const [question, setQuestion] = useState("");
  const [title, setTitle] = useState("");
  const [shareId, setShareId] = useState("");
  const [shareRole, setShareRole] = useState("viewer");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [deleted, setDeleted] = useState(false);
  const requestKey = useRef<string | null>(null);
  const createKey = useRef<string | null>(null);
  const refresh = useCallback(async () => {
    const list = (await api()) as ProjectSummary[];
    setProjects(list);
    if (activeId) {
      const data = (await api({ operation: "get", projectId: activeId })) as SavedProject;
      setProject((previous) => {
        const jobs = new Map(previous?.id === data.id ? previous.jobs.map((j) => [j.id, j]) : []);
        for (const j of data.jobs) jobs.set(j.id, j);
        return {
          ...data,
          jobs: [...jobs.values()].sort((a, b) => a.created_at.localeCompare(b.created_at)),
        };
      });
    }
  }, [activeId]);
  useEffect(() => {
    let live = true;
    const load = () =>
      void refresh().catch((e) => {
        if (live) {
          setError(e.message);
          if (e.status === 401 || e.status === 403) {
            setProject(null);
            setProjects([]);
          }
        }
      });
    load();
    const timer = setInterval(load, 3000);
    return () => {
      live = false;
      clearInterval(timer);
    };
  }, [refresh]);
  const change = async (body: Record<string, unknown>) => {
    setBusy(true);
    setError("");
    try {
      await api({ ...body, projectId: activeId });
      await refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  async function submit() {
    if (!question.trim() || busy) return;
    setBusy(true);
    setError("");
    try {
      let id = activeId;
      let current = project;
      if (!id) {
        createKey.current ??= crypto.randomUUID();
        const created = await api({ operation: "create", requestId: createKey.current, context });
        id = created.id;
        setActiveId(id);
        current = await api({ operation: "get", projectId: id });
      }
      requestKey.current ??= crypto.randomUUID();
      await api({
        operation: "enqueue",
        projectId: id,
        requestId: requestKey.current,
        question,
        revision: current!.revision,
        context: current!.state,
      });
      requestKey.current = null;
      setQuestion("");
      await refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const canEdit = project?.role !== "viewer";
  const running = project?.jobs.some((j) => j.status === "queued" || j.status === "running");
  return (
    <div className="flex flex-col gap-5">
      <nav aria-label="Analysis projects" className="flex flex-wrap gap-3 text-sm">
        <Link
          href="/ask-the-data"
          onClick={() => {
            setActiveId(undefined);
            setProject(null);
            setDeleted(false);
            setQuestion("");
            setError("");
            createKey.current = null;
            requestKey.current = null;
          }}
          className="underline"
        >
          New project
        </Link>
        {projects.map((p) => (
          <Link
            key={p.id}
            href={`/ask-the-data/projects/${p.id}`}
            className="max-w-full break-words underline"
          >
            {p.title} ({p.role})
          </Link>
        ))}
      </nav>
      {error && <p role="alert">{error}</p>}
      {!activeId && !deleted && (
        <button
          disabled={busy}
          className="rounded border border-border p-2"
          onClick={() => {
            setBusy(true);
            createKey.current ??= crypto.randomUUID();
            void api({ operation: "create", requestId: createKey.current, context })
              .then((created) => setActiveId(created.id))
              .catch((e) => setError(e.message))
              .finally(() => setBusy(false));
          }}
        >
          Create a project to attach files
        </button>
      )}
      {project && !deleted && <SourcePanel projectId={project.id} canEdit={canEdit} />}
      {deleted ? (
        <p role="status">
          Project deleted. Its active content and results have been removed. Backup copies expire
          under the server backup retention policy.
        </p>
      ) : (
        <>
          {activeId && (
            <p className="text-sm">
              <Link href={`/ask-the-data/projects/${activeId}`} className="underline">
                Permanent project link
              </Link>{" "}
              · {project?.role ?? "Loading"}
            </p>
          )}
          {project && (
            <>
              <h2 className="text-lg font-semibold">{project.title}</h2>
              {canEdit && (
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    void change({ operation: "rename", title, revision: project.revision });
                  }}
                  className="flex flex-wrap gap-2"
                >
                  <label htmlFor="project-title" className="sr-only">
                    New project title
                  </label>
                  <input
                    id="project-title"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Rename project"
                    maxLength={200}
                    required
                    className="min-w-0 flex-1 rounded border border-border bg-background p-2"
                  />
                  <button disabled={busy} className="rounded border border-border px-3">
                    Rename
                  </button>
                </form>
              )}
              {project.role === "owner" && (
                <details className="rounded border border-border p-3">
                  <summary>Sharing and deletion</summary>
                  <p className="my-2 text-sm">
                    Private until shared. Only approved users can access a shared project. Viewers
                    can read and download; editors can add analyses. Only you can manage sharing and
                    delete this project.
                  </p>
                  <form
                    onSubmit={(e) => {
                      e.preventDefault();
                      void change({ operation: "share", userId: shareId, role: shareRole });
                    }}
                    className="flex flex-col gap-2"
                  >
                    <label htmlFor="share-user">Approved user’s sharing ID</label>
                    <input
                      id="share-user"
                      value={shareId}
                      onChange={(e) => setShareId(e.target.value)}
                      required
                      className="rounded border border-border bg-background p-2"
                    />
                    <label htmlFor="share-role">Permission</label>
                    <select
                      id="share-role"
                      value={shareRole}
                      onChange={(e) => setShareRole(e.target.value)}
                      className="rounded border border-border bg-background p-2"
                    >
                      <option value="viewer">Viewer</option>
                      <option value="editor">Editor</option>
                    </select>
                    <button disabled={busy} className="rounded border border-border p-2">
                      Share
                    </button>
                  </form>
                  <ul>
                    {project.members.map((m) => (
                      <li key={m.user_id} className="my-2 break-all text-sm">
                        {m.user_id} · {m.role}{" "}
                        <button
                          disabled={busy}
                          onClick={() => void change({ operation: "unshare", userId: m.user_id })}
                          className="underline"
                        >
                          Remove access
                        </button>
                      </li>
                    ))}
                  </ul>
                  <button
                    disabled={busy}
                    className="mt-3 rounded border border-border p-2"
                    onClick={() => {
                      if (
                        window.confirm("Delete this project and its conversations and results?")
                      ) {
                        setBusy(true);
                        void api({ operation: "delete", projectId: activeId })
                          .then(() => {
                            setProject(null);
                            setActiveId(undefined);
                            setDeleted(true);
                            return api();
                          })
                          .then(setProjects)
                          .catch((e) => setError(e.message))
                          .finally(() => setBusy(false));
                      }
                    }}
                  >
                    Delete project
                  </button>
                </details>
              )}
              {project.jobs.length >= 200 && (
                <button
                  className="underline"
                  onClick={() =>
                    void api({
                      operation: "get",
                      projectId: activeId,
                      before: project.jobs[0].created_at,
                    })
                      .then((older: SavedProject) =>
                        setProject((p) => (p ? { ...p, jobs: [...older.jobs, ...p.jobs] } : p)),
                      )
                      .catch((e) => setError(e.message))
                  }
                >
                  Load older analyses
                </button>
              )}
              {project.jobs.map((job) => (
                <article
                  key={job.id}
                  className="flex flex-col gap-3 rounded border border-border p-4"
                >
                  <h3 className="break-words font-semibold">{job.question}</h3>
                  <p
                    role={job.status === "running" ? "status" : undefined}
                    className="text-sm text-muted"
                  >
                    {job.status} · {job.progress}
                  </p>
                  {job.result && (
                    <>
                      <AnswerMarkdown text={job.result.answer} />
                      {job.result.events
                        .filter((e) => e.type === "figure")
                        .map((e, i) => (
                          <AssistantFigureView key={i} figure={e.figure as AssistantFigure} />
                        ))}
                      {job.result.events
                        .filter((e) => e.type === "citations")
                        .map((e, i) => (
                          <details key={i}>
                            <summary>Source passages</summary>
                            {(
                              e.citations as Array<{
                                chunkId: number;
                                sourceId?: string;
                                sourceKind?: string;
                                url?: string;
                                asOf?: string;
                                retrievedAt?: string;
                                derivedHash?: string;
                                label: string;
                                text: string;
                              }>
                            ).map((citation) => (
                              <div key={citation.chunkId} className="my-3 text-sm">
                                <p className="font-medium">{citation.label}</p>
                                {citation.sourceId && (
                                  <p className="text-xs text-muted">
                                    {citation.sourceKind === "web"
                                      ? "Online evidence"
                                      : "Project attachment"}{" "}
                                    · Reporting period: {citation.asOf ?? "not documented"} ·
                                    Retrieved/stored: {citation.retrievedAt ?? "not recorded"}
                                  </p>
                                )}
                                {citation.url && (
                                  <a href={citation.url} className="break-all text-xs underline">
                                    Original online location
                                  </a>
                                )}
                                <p className="whitespace-pre-wrap">{citation.text}</p>
                                <Link
                                  href={
                                    citation.sourceId
                                      ? `/ask-the-data/projects/${project.id}/sources/${citation.sourceId}?version=${citation.derivedHash ?? ""}`
                                      : `/ask-the-data/source/${citation.chunkId}`
                                  }
                                  className="underline"
                                >
                                  Read source
                                </Link>
                              </div>
                            ))}
                          </details>
                        ))}
                      <a
                        href={withBasePath(
                          `/api/analysis/projects/${project.id}/artifacts/${job.id}`,
                        )}
                        className="underline"
                      >
                        Download analysis record (JSON)
                      </a>
                    </>
                  )}
                  {job.error && <p>{job.error}</p>}
                  {job.usage && (
                    <p className="text-xs text-muted">
                      Provider requests recorded: {job.usage.requests}.{" "}
                      {job.usage.estimatedCostUsd === null
                        ? "Cost unavailable."
                        : `Estimated cost: USD ${job.usage.estimatedCostUsd.toFixed(6)}.`}{" "}
                      {job.usage.note}
                    </p>
                  )}
                  {canEdit && (job.status === "queued" || job.status === "running") && (
                    <button
                      disabled={busy}
                      className="underline"
                      onClick={() => void change({ operation: "cancel", jobId: job.id })}
                    >
                      Cancel analysis
                    </button>
                  )}
                  {canEdit && (job.status === "failed" || job.status === "cancelled") && (
                    <button
                      disabled={busy || running}
                      className="underline"
                      onClick={() => void change({ operation: "retry", jobId: job.id })}
                    >
                      Retry analysis
                    </button>
                  )}
                </article>
              ))}
            </>
          )}
          {(!activeId || project) && canEdit && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void submit();
              }}
              className="flex flex-col gap-2"
            >
              <label htmlFor="project-question">Ask a question</label>
              <textarea
                id="project-question"
                value={question}
                onChange={(e) => {
                  setQuestion(e.target.value);
                  requestKey.current = null;
                }}
                maxLength={4000}
                required
                disabled={busy || running}
                className="min-h-24 rounded border border-border bg-background p-3"
              />
              <button
                disabled={busy || running}
                className="rounded bg-accent px-4 py-2 text-accent-foreground"
              >
                {busy ? "Saving…" : "Analyse and save"}
              </button>
              <p className="text-xs text-muted">
                Questions and results are saved automatically. You can close this page and return
                later. Cancellation prevents publication; requests already sent to providers may
                still incur charges.
              </p>
            </form>
          )}
        </>
      )}
    </div>
  );
}
