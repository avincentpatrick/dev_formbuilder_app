"use client";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { withBasePath } from "@/lib/base-path";
import Form from "next/form";
import Link from "next/link";
import { useEffect, useId, useRef, useState } from "react";

type Props = { query?: string; compact?: boolean; mapState?: string | string[] };
type Suggestion = { label: string; detail: string; href: string };
export function BhwSearchForm(props: Props) {
  return <SearchForm key={props.query ?? ""} {...props} />;
}
function SearchForm({ query = "", compact = false, mapState }: Props) {
  const snapshot = restoredBhwMapHref(mapState)?.split("?")[1];
  const options = useRef<(HTMLAnchorElement | null)[]>([]);
  const id = useId();
  const [value, setValue] = useState(query);
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(-1);
  const [result, setResult] = useState<{
    query: string;
    suggestions: Suggestion[];
    failed: boolean;
  } | null>(null);
  const text = value.trim();
  const requestKey = `${text}:${snapshot ?? ""}`;
  useEffect(() => {
    if (!open || text.length < 2) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const params = new URLSearchParams({ q: text });
        if (snapshot) params.set("mapState", snapshot);
        const response = await fetch(withBasePath(`/api/bhw/suggestions?${params}`), {
          signal: controller.signal,
        });
        if (!response.ok) throw new Error("Suggestions unavailable");
        const data = await response.json();
        if (!controller.signal.aborted)
          setResult({
            query: requestKey,
            suggestions: data.suggestions,
            failed: data.placesFailed,
          });
      } catch {
        if (!controller.signal.aborted)
          setResult({ query: requestKey, suggestions: [], failed: true });
      }
    }, 250);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [text, snapshot, requestKey, open]);
  const current = result?.query === requestKey ? result : null;
  const suggestions = current?.suggestions ?? [];
  const visible = open && text.length >= 2;
  useEffect(() => {
    if (visible && active >= 0) options.current[active]?.scrollIntoView({ block: "nearest" });
  }, [active, visible]);
  return (
    <Form
      action="/bhw/search"
      prefetch={false}
      role="search"
      className="space-y-2"
      onSubmit={() => setOpen(false)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
      }}
    >
      {snapshot && <input type="hidden" name="mapState" value={snapshot} />}
      <label htmlFor={`${id}-search`} className="block font-medium">
        Search BHW places and indicators
      </label>
      <div className="flex gap-2">
        <input
          id={`${id}-search`}
          name="q"
          type="search"
          role="combobox"
          autoComplete="off"
          value={value}
          onChange={(event) => {
            setValue(event.target.value);
            setActive(-1);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          maxLength={100}
          placeholder="Cebu accreditation"
          aria-describedby={`${id}-help`}
          aria-autocomplete="list"
          aria-expanded={visible}
          aria-controls={visible ? `${id}-suggestions` : undefined}
          aria-activedescendant={
            visible && suggestions[active] ? `${id}-option-${active}` : undefined
          }
          onKeyDown={(event) => {
            if (event.key === "Escape") {
              event.preventDefault();
              setOpen(false);
              setActive(-1);
              return;
            }
            if ((event.key === "ArrowDown" || event.key === "ArrowUp") && suggestions.length) {
              event.preventDefault();
              setOpen(true);
              setActive((index) =>
                event.key === "ArrowDown"
                  ? (index + 1) % suggestions.length
                  : index <= 0
                    ? suggestions.length - 1
                    : index - 1,
              );
            }
            if (event.key === "Enter" && visible && suggestions[active]) {
              event.preventDefault();
              options.current[active]?.click();
            }
          }}
          className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-3"
        />
        <button className="rounded-md bg-accent px-4 py-3 font-medium text-white">Search</button>
      </div>
      {visible && (
        <div className="rounded-md border border-border bg-background p-1">
          <div
            id={`${id}-suggestions`}
            role="listbox"
            aria-label="BHW search suggestions"
            className="max-h-[35svh] overflow-y-auto"
          >
            {suggestions.map((suggestion, index) => (
              <Link
                href={suggestion.href}
                prefetch={false}
                ref={(element) => {
                  options.current[index] = element;
                }}
                key={suggestion.href}
                id={`${id}-option-${index}`}
                role="option"
                aria-selected={index === active}
                tabIndex={-1}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => setOpen(false)}
                className="block min-h-11 w-full rounded px-3 py-2 text-left hover:bg-surface aria-selected:bg-surface"
              >
                <span className="block font-medium">{suggestion.label}</span>
                <span className="block text-xs text-muted">{suggestion.detail}</span>
              </Link>
            ))}
          </div>
          <p role="status" className="px-3 py-1 text-sm text-muted">
            {!current
              ? "Finding suggestions…"
              : current.failed
                ? "Place suggestions are unavailable. You can still submit Search."
                : suggestions.length
                  ? `${suggestions.length} suggestions. Use arrow keys and Enter, or tap a result.`
                  : "No suggestions. Submit Search for full results and comparison queries."}
          </p>
        </div>
      )}
      <p id={`${id}-help`} className="text-sm text-muted">
        {compact
          ? "Place, PSGC code, or indicator."
          : "Try a place name or PSGC code, “headcount”, “Cebu accreditation”, or “Compare Cebu and Bohol accreditation”. Choose among matching places and measures before opening a figure."}
      </p>
    </Form>
  );
}
