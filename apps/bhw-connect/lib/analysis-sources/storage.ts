import "server-only";
import { createHash } from "node:crypto";
import { mkdir, writeFile, readFile, rm } from "node:fs/promises";
import { join } from "node:path";
import { storageRoot } from "@/lib/storage/screenshots";
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export function sourcePath(project: string, source?: string) {
  if (!uuid.test(project) || (source && !uuid.test(source))) throw new Error("Invalid storage ID");
  return join(
    /*turbopackIgnore:true*/ storageRoot(),
    "analysis-projects",
    project.toLowerCase(),
    ...(source ? [`${source.toLowerCase()}.bin`] : []),
  );
}
export async function saveOriginal(project: string, source: string, bytes: Uint8Array) {
  await mkdir(/*turbopackIgnore:true*/ sourcePath(project), { recursive: true });
  try {
    await writeFile(/*turbopackIgnore:true*/ sourcePath(project, source), bytes, { flag: "wx" });
  } catch (e) {
    if ((e as NodeJS.ErrnoException).code !== "EEXIST") throw e;
    const existing = await readFile(/*turbopackIgnore:true*/ sourcePath(project, source));
    if (
      createHash("sha256").update(existing).digest("hex") !==
      createHash("sha256").update(bytes).digest("hex")
    )
      throw new Error("Stored original hash mismatch");
  }
}
export async function readOriginal(project: string, source: string, expectedHash?: string) {
  const bytes = await readFile(/*turbopackIgnore:true*/ sourcePath(project, source));
  if (expectedHash && createHash("sha256").update(bytes).digest("hex") !== expectedHash)
    throw new Error("Original content hash mismatch");
  return bytes;
}
export const deleteOriginal = (project: string, source?: string) =>
  rm(/*turbopackIgnore:true*/ sourcePath(project, source), { force: true, recursive: !source });
