import { mkdtemp, readdir, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { expect, it } from "vitest";
import { DOCUMENT_LIMITS, readDocument, saveDocument } from "./documents";

it("publishes complete originals with generated keys and rejects filesystem paths", async () => {
  const root = await mkdtemp(join(tmpdir(), "bhw-documents-"));
  try {
    const bytes = Buffer.from("%PDF-1.7\nsynthetic storage test");
    const key = await saveDocument(bytes, root);
    expect(await readDocument(key, root)).toEqual(bytes);
    expect(await readdir(join(root, "documents"))).toEqual([key]);
    for (const bad of ["../secret", "/etc/passwd", "..\\secret", `${key}/../x`]) {
      await expect(readDocument(bad, root)).rejects.toThrow("Invalid document storage key");
    }
    await expect(saveDocument(Buffer.from("not PDF"), root)).rejects.toThrow("Only PDF");
    await expect(saveDocument(new Uint8Array(DOCUMENT_LIMITS.bytes + 1), root)).rejects.toThrow(
      "25 MiB",
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
