import "server-only";
import { parseHTML } from "linkedom";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { access, mkdtemp, writeFile, readFile, stat, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
const exec = promisify(execFile);
export async function extractOnline(bytes: Buffer, type: string, signal: AbortSignal) {
  if (type.includes("pdf") || bytes.subarray(0, 5).toString() === "%PDF-") {
    const dir = await mkdtemp(join(tmpdir(), "bhw-policy-web-"));
    try {
      const direct = join(process.cwd(), "analysis-extractor.mjs");
      let script = join(process.cwd(), ".next", "standalone", "analysis-extractor.mjs");
      try {
        await access(/*turbopackIgnore:true*/ direct);
        script = direct;
      } catch {}
      const input = join(dir, "source.pdf"),
        output = join(dir, "result.json");
      await writeFile(input, bytes, { mode: 0o600 });
      await exec(
        process.execPath,
        ["--max-old-space-size=512", script, input, "source.pdf", "{}", output],
        { signal, timeout: 20000, maxBuffer: 65536, windowsHide: true },
      );
      if ((await stat(output)).size > 8 * 1024 * 1024) throw Error("Extraction too large");
      const value = JSON.parse(await readFile(output, "utf8"));
      return {
        title:
          typeof value.profile?.title === "string"
            ? value.profile.title.slice(0, 300)
            : "Retrieved original PDF",
        publicationDate: null as string | null,
        effectiveDate: null as string | null,
        pages: (value.pages as { page: number; text: string }[]).slice(0, 6),
        warnings: ["PDF text excerpts only; scanned text and unread pages are not verified."],
      };
    } finally {
      await rm(dir, { recursive: true, force: true });
    }
  }
  if (!/text\/html|application\/xhtml\+xml|text\/plain/i.test(type))
    throw Error("Unsupported online content");
  const raw = new TextDecoder("utf-8", { fatal: true }).decode(bytes);
  if (type.includes("text/plain"))
    return {
      title: "Retrieved plain text",
      publicationDate: null,
      effectiveDate: raw.match(/effective date\s*:\s*(\d{4}-\d{2}-\d{2})/i)?.[1] ?? null,
      pages: [{ page: 1, text: raw.slice(0, 100000) }],
      warnings: ["Plain text excerpt; no publisher metadata verified."],
    };
  const { document } = parseHTML(raw);
  const title =
    document.querySelector("title")?.textContent?.trim().slice(0, 300) ??
    "Retrieved official webpage";
  const date = document.querySelector(
    'meta[property="article:published_time"],meta[name="date"],time[datetime]',
  );
  const published =
    (date?.getAttribute("content") ?? date?.getAttribute("datetime") ?? "").match(
      /^\d{4}-\d{2}-\d{2}/,
    )?.[0] ?? null;
  document
    .querySelectorAll("script,style,noscript,nav,footer,header,iframe,form")
    .forEach((n) => n.remove());
  const text = (document.querySelector("main,article") ?? document.body).textContent
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, 100000);
  return {
    title,
    publicationDate: published,
    effectiveDate: text.match(/effective date\s*:\s*(\d{4}-\d{2}-\d{2})/i)?.[1] ?? null,
    pages: [{ page: 1, text }],
    warnings: [
      "Publisher-reported dates and extracted text are unverified; excerpts are not a complete web review.",
    ],
  };
}
