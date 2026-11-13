import "server-only";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { access, mkdtemp, readFile, rm, stat } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { createHash } from "node:crypto";
import { sourceWorker } from "./db";
import { sourcePath, deleteOriginal, readOriginal } from "./storage";
import type { Extraction, ProjectSource } from "./types";
import type { Json } from "@/lib/db/database.types";
const exec = promisify(execFile);
export async function extractStoredSource(source: ProjectSource): Promise<Extraction> {
  await readOriginal(source.project_id, source.id, source.sha256);
  const direct = join(process.cwd(), "analysis-extractor.mjs");
  let script = join(process.cwd(), ".next", "standalone", "analysis-extractor.mjs");
  try {
    await access(/*turbopackIgnore:true*/ direct);
    script = direct;
  } catch {
    /* Development/CI checkout uses the bundled standalone copy. */
  }
  const dir = await mkdtemp(join(tmpdir(), "bhw-extract-"));
  try {
    const output = join(dir, "result.json");
    await exec(
      process.execPath,
      [
        "--max-old-space-size=512",
        script,
        sourcePath(source.project_id, source.id),
        source.name,
        JSON.stringify({ crs: source.metadata.crs }),
        output,
      ],
      { timeout: 90000, maxBuffer: 1024 * 1024, windowsHide: true },
    );
    if ((await stat(output)).size > 64 * 1024 * 1024)
      throw new Error("Extracted content exceeds processing size");
    const text = await readFile(output, "utf8");
    const content = JSON.parse(text) as Extraction;
    content.derivedHash = createHash("sha256").update(text).digest("hex");
    return content;
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}
export async function cleanupDeletedSources() {
  const pending = (await sourceWorker("cleanup")) as Array<{
    project_id: string;
    source_id: string | null;
  }>;
  for (const item of pending) {
    try {
      await deleteOriginal(item.project_id, item.source_id ?? undefined);
      await sourceWorker("cleaned", item.source_id ?? undefined, undefined, {
        projectId: item.project_id,
      });
    } catch {
      /* Retried on the next worker pass; deleted records cannot be downloaded. */
    }
  }
}
export async function processNextSource(sourceId?: string) {
  const source = (await sourceWorker("claim", sourceId)) as ProjectSource | null;
  if (!source) return false;
  let active = true;
  let pending = false;
  const timer = setInterval(() => {
    if (pending) return;
    pending = true;
    void sourceWorker("heartbeat", source.id, source.lease_id)
      .then((value) => {
        active = Boolean((value as { active: boolean }).active);
      })
      .catch(() => {
        active = false;
      })
      .finally(() => {
        pending = false;
      });
  }, 10000);
  try {
    const content = await extractStoredSource(source);
    if (active)
      await sourceWorker("finish", source.id, source.lease_id, {
        content: content as unknown as Json,
      });
  } catch {
    await sourceWorker("fail", source.id, source.lease_id, {
      error:
        "Extraction could not complete within the processing limits. Check the file format, encryption and converter configuration.",
    });
  } finally {
    clearInterval(timer);
  }
  return true;
}
