"use client";
import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import type { PolicySources } from "@/lib/policy-analysis/schema";
import type { ReviewReport, ReviewRun } from "@/lib/policy-review/types";
import { withBasePath } from "@/lib/base-path";

async function api<T>(suffix: string, payload?: unknown, signal?: AbortSignal): Promise<T> {
  const res = await fetch(
    withBasePath(`/api/admin/policy-reviews${suffix}`),
    payload
      ? {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        }
      : { cache: "no-store", signal },
  );
  const data = await res.json();
  if (!res.ok) throw Error(data.error ?? "Review unavailable.");
  return data as T;
}
export function PolicyReviewPanel({ sources }: { sources: PolicySources }) {
  const [question, setQuestion] = useState("");
  const [budget, setBudget] = useState(200);
  const [runs, setRuns] = useState<ReviewRun[]>([]),
    [run, setRun] = useState("");
  const [report, setReport] = useState<ReviewReport | null>(null),
    [offset, setOffset] = useState(0),
    [topic, setTopic] = useState("");
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  const request = useRef<{ key: string; signature: string } | null>(null);
  const refresh = useCallback(
    async (signal: AbortSignal) => {
      const [list, result] = await Promise.all([
        api<ReviewRun[]>("", undefined, signal),
        run
          ? api<ReviewReport>(
              `?run=${run}&offset=${offset}&topic=${encodeURIComponent(topic)}`,
              undefined,
              signal,
            )
          : Promise.resolve(null),
      ]);
      if (!signal.aborted) {
        setRuns(list);
        setReport(result);
      }
    },
    [run, offset, topic],
  );
  useEffect(() => {
    const controller = new AbortController();
    let fetching = false;
    const poll = async () => {
      if (fetching) return;
      fetching = true;
      try {
        await refresh(controller.signal);
      } catch (e) {
        if (!controller.signal.aborted)
          setError(e instanceof Error ? e.message : "Review unavailable.");
      } finally {
        fetching = false;
      }
    };
    void poll();
    const timer = setInterval(() => void poll(), 3000);
    return () => {
      controller.abort();
      clearInterval(timer);
    };
  }, [refresh]);
  async function change(operation: "create" | "cancel" | "resume") {
    setBusy(true);
    setError("");
    try {
      if (operation === "create") {
        const signature = JSON.stringify({ question, sources, budget });
        if (request.current?.signature !== signature)
          request.current = { signature, key: crypto.randomUUID() };
        const created = await api<{ id: string }>("", {
          operation,
          requestKey: request.current.key,
          question,
          sources,
          budget,
        });
        setRun(created.id);
        setOffset(0);
        setTopic("");
        request.current = null;
      } else {
        await api("", {
          operation,
          run,
          ...(operation === "resume" ? { extraBudget: budget, retryFailed: true } : {}),
        });
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Review unavailable.");
    } finally {
      setBusy(false);
    }
  }
  const c = report?.coverage;
  const totalPages = c ? Math.ceil(c.total / 50) : 0;
  return (
    <section aria-label="Full policy review" className="grid gap-4 rounded border p-4">
      <h2 className="font-semibold">Review all policies</h2>
      <p className="text-sm">
        Examine every extracted section in the chosen scope. The document inventory and dataset
        definitions freeze when you start. Later uploads are excluded. Dataset rows are queried at
        execution time and saved with timestamps.
      </p>
      <form
        className="grid gap-3"
        onSubmit={(e) => {
          e.preventDefault();
          void change("create");
        }}
      >
        <label>
          Review question
          <textarea
            required
            maxLength={4000}
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            className="block w-full rounded border p-2"
          />
        </label>
        <label>
          Section-attempt budget
          <input
            type="number"
            min={1}
            max={1000}
            required
            value={budget}
            onChange={(e) => setBudget(Number(e.target.value))}
            className="ml-2 rounded border p-2"
          />
        </label>
        <p className="text-xs text-muted">
          Retries also consume this budget. Resume adds this many attempts, up to 10,000 lifetime.
          Work continues independently of this browser when the server worker is scheduled.
        </p>
        <button disabled={busy} className="rounded border p-2">
          Start full review
        </button>
      </form>
      {error && <p role="alert">{error}</p>}
      <label>
        Your saved reviews
        <select
          className="block w-full rounded border p-2"
          value={run}
          onChange={(e) => {
            setRun(e.target.value);
            setOffset(0);
            setTopic("");
          }}
        >
          <option value="">Choose review</option>
          {runs.map((r) => (
            <option key={r.id} value={r.id}>
              {r.status} · {r.question.slice(0, 80)} · {new Date(r.created_at).toLocaleString()}
            </option>
          ))}
        </select>
      </label>
      {report && c && (
        <>
          <h3 className="font-semibold">{report.run.question}</h3>
          <p role="status">
            {report.complete ? "All frozen extracted sections examined" : "Review is not complete"}{" "}
            · {report.run.status}
          </p>
          <p>{report.run.reason}</p>
          <p>
            {c.examined + c.no_finding} / {c.total} sections examined · {c.no_finding} with no
            relevant finding · {c.pending} pending · {c.running} running · {c.failed} failed ·{" "}
            {c.unreadable} unreadable
          </p>
          <p>
            {report.run.used} / {report.run.budget} section attempts used
          </p>
          <p className="text-xs text-muted">
            Coverage records machine examination, not legal verification or a guarantee that every
            relevant meaning was recognized. Unreadable, failed, excluded and quality-limited
            material prevents a complete coverage result.
          </p>
          <div className="flex gap-3">
            {["queued", "running"].includes(report.run.status) && (
              <button
                disabled={busy}
                onClick={() => void change("cancel")}
                className="rounded border p-2"
              >
                Cancel review
              </button>
            )}
            {["partial", "cancelled"].includes(report.run.status) && (
              <button
                disabled={busy}
                onClick={() => void change("resume")}
                className="rounded border p-2"
              >
                Resume and retry failed sections
              </button>
            )}
          </div>
          <details>
            <summary>Frozen scope and document coverage ({report.documents.length})</summary>
            <ul className="grid gap-2">
              {report.documents.map((d) => (
                <li key={d.id}>
                  {d.metadata.title} · {d.examined_sections}/{d.sections} sections examined ·{" "}
                  {d.exclusion ?? "included"} · {d.warnings.length} extraction warnings{" "}
                  <span className="break-all text-xs">
                    version {d.version_id ?? "unavailable"} · extraction{" "}
                    {d.extraction_id ?? "unavailable"}
                  </span>
                </li>
              ))}
            </ul>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap text-xs">
              {JSON.stringify(
                { sources: report.run.settings, datasets: report.run.dataset_snapshot },
                null,
                2,
              )}
            </pre>
          </details>
          <h3 className="font-semibold">Synthesis by subject</h3>
          <p className="text-sm">
            All accepted findings are grouped below. Different quoted requirements across versions
            are comparison candidates; scope, definitions, dates and declared supersession need
            human assessment.
          </p>
          <ul className="grid gap-2">
            {report.groups.map((g) => (
              <li key={g.topic}>
                <button
                  className="underline"
                  onClick={() => {
                    setTopic(g.topic);
                    setOffset(0);
                  }}
                >
                  {g.topic}
                </button>{" "}
                · {g.findings} findings across {g.versions} versions ·{" "}
                {g.comparison_needed
                  ? "Different quoted requirements: compare scope, dates and supersession"
                  : "No differing quoted requirement detected; absence of a candidate does not establish consistency"}
              </li>
            ))}
          </ul>
          {!report.groups.length && (
            <p>
              No accepted relevant findings yet. Check coverage before interpreting this as an
              absence of relevant policy.
            </p>
          )}
          <label>
            Finding subject
            <select
              className="block w-full rounded border p-2"
              value={topic}
              onChange={(e) => {
                setTopic(e.target.value);
                setOffset(0);
              }}
            >
              <option value="">All sections</option>
              {report.groups.map((g) => (
                <option key={g.topic} value={g.topic}>
                  {g.topic} · {g.findings} findings / {g.versions} versions
                  {g.comparison_needed ? " · comparison needed" : ""}
                </option>
              ))}
            </select>
          </label>
          <ul className="grid gap-4">
            {report.sections.map((s) => (
              <li key={s.id} className="grid gap-2 rounded border p-3">
                <Link className="underline" href={`/ask-the-data/reviews/${run}/sections/${s.id}`}>
                  {s.metadata.title} · PDF page {s.page} · characters {s.char_start}–{s.char_end}
                </Link>
                <p>
                  {s.status} · {s.attempts} attempts {s.safe_error ? `· ${s.safe_error}` : ""}
                </p>
                {s.status === "no_finding" && <p>Examined: no relevant finding in this section.</p>}
                {s.result?.findings.map((f, i) => (
                  <div key={i}>
                    <p className="font-medium">
                      {f.topic} · {f.kind.replaceAll("_", " ")}
                    </p>
                    <p className="whitespace-pre-wrap break-words">{f.text}</p>
                    <blockquote className="border-l-2 pl-2">{f.quote}</blockquote>
                    {f.comparisonValue && <p>Quoted comparison: {f.comparisonValue}</p>}
                  </div>
                ))}
                {!!s.result?.limitations.length && <p>Gaps: {s.result.limitations.join("; ")}</p>}
                {s.evidence && (
                  <details>
                    <summary>Saved evidence, dataset query results and usage</summary>
                    <pre className="max-h-80 overflow-auto whitespace-pre-wrap break-words text-xs">
                      {JSON.stringify(s.evidence, null, 2)}
                    </pre>
                  </details>
                )}
              </li>
            ))}
          </ul>
          <div className="flex gap-3">
            <button
              disabled={offset === 0}
              onClick={() => setOffset(Math.max(0, offset - 50))}
              className="rounded border p-2"
            >
              Previous sections
            </button>
            <span>
              Page {Math.floor(offset / 50) + 1}
              {!topic ? ` of ${Math.max(1, totalPages)}` : ""}
            </span>
            <button
              disabled={report.sections.length < 50}
              onClick={() => setOffset(offset + 50)}
              className="rounded border p-2"
            >
              Next sections
            </button>
          </div>
        </>
      )}
    </section>
  );
}
