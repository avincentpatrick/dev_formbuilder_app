import "server-only";
import { randomUUID } from "node:crypto";
import { link, mkdir, readFile, unlink, writeFile } from "node:fs/promises";
import { join, resolve, sep } from "node:path";
import { storageRoot } from "./screenshots";

export const DOCUMENT_LIMITS = {
  bytes: 25 * 1024 * 1024,
  pages: 300,
  extractionSeconds: 600,
  attempts: 3,
} as const;

const KEY = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/;
function locate(key: string, root?: string): string {
  if (!KEY.test(key)) throw new Error("Invalid document storage key");
  const folder = join(/*turbopackIgnore: true*/ storageRoot(root), "documents");
  const full = resolve(/*turbopackIgnore: true*/ folder, key);
  if (!full.startsWith(folder + sep)) throw new Error("Invalid document storage key");
  return full;
}

/** Internal only. Caller must authorize strict admin before accepting bytes. No overwrite.
 * Write a sibling temporary file, then publish with an atomic no-clobber hard link.
 * Disk must support hard links (NTFS on the deployment). A DB failure may leave an
 * orphan original for reconciliation; it must never produce an available version.
 */
export async function saveDocument(bytes: Uint8Array, root?: string): Promise<string> {
  if (!bytes.length || bytes.length > DOCUMENT_LIMITS.bytes)
    throw new Error("PDF must be between 1 byte and 25 MiB");
  if (Buffer.from(bytes.subarray(0, 5)).toString("ascii") !== "%PDF-")
    throw new Error("Only PDF files are accepted");
  const key = `${randomUUID()}.pdf`;
  const full = locate(key, root);
  const folder = join(/*turbopackIgnore: true*/ storageRoot(root), "documents");
  await mkdir(/*turbopackIgnore: true*/ folder, { recursive: true });
  const temporary = `${full}.${randomUUID()}.tmp`;
  try {
    await writeFile(/*turbopackIgnore: true*/ temporary, bytes, { flag: "wx", mode: 0o600 });
    await link(/*turbopackIgnore: true*/ temporary, full);
  } finally {
    await unlink(/*turbopackIgnore: true*/ temporary).catch(() => undefined);
  }
  return key;
}

/** Trusted server use only; downloads must authorize strict admin independently. */
export async function readDocument(key: string, root?: string): Promise<Buffer> {
  return readFile(/*turbopackIgnore: true*/ locate(key, root));
}

/** Only remove a newly written exact-duplicate original after DB confirms reuse. */
export async function removeDocument(key: string, root?: string): Promise<void> {
  await unlink(/*turbopackIgnore: true*/ locate(key, root));
}
