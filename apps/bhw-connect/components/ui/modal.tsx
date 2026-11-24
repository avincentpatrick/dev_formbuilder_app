"use client";

import { useEffect, useId, useRef, type ReactNode } from "react";

/**
 * Reusable "enlarge" modal built on the native <dialog> element — focus trap
 * and focus restore come free with showModal()/close(), and Escape closes it
 * natively. No modal library needed (matches the repo's native-elements-first
 * convention, e.g. components/glossary/glossary-term.tsx).
 */
export function Modal({
  open,
  onClose,
  title,
  caption,
  fullScreen = false,
  children,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  /** Optional WPSAR-style Person/Place/Time line, e.g. "N = 270,917 validated
   * profiles · Philippines · 2025 snapshot" — matches FigureCard's caption. */
  caption?: string;
  fullScreen?: boolean;
  children: ReactNode;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const captionId = useId();

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={dialogRef}
      aria-labelledby={titleId}
      aria-describedby={caption ? captionId : undefined}
      onClose={onClose}
      onClick={(e) => {
        if (e.target === dialogRef.current) onClose();
      }}
      className={`fixed inset-0 m-auto hidden border border-border bg-background p-0 text-foreground shadow-xl backdrop:bg-foreground/40 open:flex open:flex-col ${fullScreen ? "h-[100dvh] max-h-none w-screen max-w-none" : "max-h-[92vh] min-h-[40vh] w-[96vw] max-w-6xl rounded-lg"}`}
    >
      <div className="flex shrink-0 items-start justify-between gap-3 border-b border-border p-3 sm:p-5">
        <div>
          <h2 id={titleId} className="text-base font-semibold tracking-tight sm:text-lg">
            {title}
          </h2>
          {caption && (
            <p id={captionId} className="mt-0.5 text-xs text-muted">
              {caption}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close"
          className="shrink-0 rounded-md px-2 py-1 text-muted hover:bg-surface"
        >
          ✕
        </button>
      </div>
      <div
        role="region"
        aria-label="Figure content"
        tabIndex={0}
        className="flex flex-1 flex-col overflow-x-hidden overflow-y-auto p-3 sm:p-5"
      >
        {children}
      </div>
    </dialog>
  );
}
