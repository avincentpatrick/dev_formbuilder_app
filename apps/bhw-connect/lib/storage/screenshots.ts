import "server-only";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, join, resolve, sep } from "node:path";

/**
 * Feedback screenshots, on this server's own disk (Meridian's `bhw-p3-identity`). Until then they were
 * objects in a private Supabase Storage bucket, `feedback-screenshots`; the box downloaded all 34 into
 * this same layout, so `feedback.screenshot_path` keeps meaning what it meant.
 *
 * Root: `BHW_STORAGE_DIR` (`C:\meridian\bhw\storage` on the box), else `./.local-storage`, which is
 * git-ignored for exactly this. A path is accepted only in the one shape the feedback route writes —
 * `<session uuid>/<uuid>.png|jpg` — and must still resolve inside the folder, so nothing a request
 * names can reach any other file.
 *
 * Every file-system call names its path at run time, so each carries `turbopackIgnore`: without it the
 * build traced the whole project into the release (Meridian's `bhw-screenshot-trace`: 3,404 files and
 * 60 MB of standalone output, source and docs included, on the build of `d7ca70a0`).
 */

export const SCREENSHOT_FOLDER = "feedback-screenshots";

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}";
const SHAPE = new RegExp(`^${UUID}/${UUID}\\.(png|jpg)$`);

export function isScreenshotPath(path: string): boolean {
  return SHAPE.test(path);
}

export function storageRoot(dir: string | undefined = process.env.BHW_STORAGE_DIR): string {
  return resolve(/*turbopackIgnore: true*/ dir?.trim() || ".local-storage");
}

function locate(path: string, root?: string): string | null {
  if (!isScreenshotPath(path)) return null;
  const folder = join(/*turbopackIgnore: true*/ storageRoot(root), SCREENSHOT_FOLDER);
  const full = resolve(/*turbopackIgnore: true*/ folder, path);
  return full.startsWith(folder + sep) ? full : null;
}

/** Write a new screenshot. Never overwrites one (the bucket was written with `upsert: false`). */
export async function saveScreenshot(path: string, bytes: Uint8Array, root?: string): Promise<boolean> {
  const full = locate(path, root);
  if (!full) return false;
  await mkdir(/*turbopackIgnore: true*/ dirname(full), { recursive: true });
  await writeFile(/*turbopackIgnore: true*/ full, bytes, { flag: "wx" });
  return true;
}

/** Read one back, with the type its extension names, or null when it is absent or not a screenshot path. */
export async function readScreenshot(
  path: string,
  root?: string,
): Promise<{ bytes: Buffer; contentType: "image/png" | "image/jpeg" } | null> {
  const full = locate(path, root);
  if (!full) return null;
  try {
    const bytes = await readFile(/*turbopackIgnore: true*/ full);
    return { bytes, contentType: path.endsWith(".png") ? "image/png" : "image/jpeg" };
  } catch {
    return null;
  }
}
