import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join, relative, sep } from "node:path";
import ts from "typescript";

/**
 * Every root-relative URL in the app that Next's `basePath` will NOT prefix, found by parsing the
 * source rather than by matching text (a `<Link>` split over two lines is the same URL as a raw
 * `<a>` to a grep, and only one of them is a bug).
 *
 * Next prefixes `next/link`, `router.push`, `redirect()` and its own assets by itself. It does not
 * prefix a raw `<a href="/…">`, a `fetch("/…")`, a `window.location` assignment, a
 * `new URL("/…", request.url)` handed to a redirect, or the URL of a file under `public/` handed to a
 * fetch. Those go through `withBasePath()` (`lib/base-path.ts`);
 * `base-path-census.test.ts` holds the census at zero, so a new raw "/…" in one of these places
 * fails the suite instead of shipping a link that 404s under `/bhw-connect`.
 *
 * What it cannot see, and does not pretend to: a root-relative string stored in a variable first
 * and handed over later (`const href = "/x"; <a href={href}>`). Those sites are wrapped where the
 * string is built, and review is the guard for new ones.
 */
export type CensusHit = { file: string; line: number; kind: string; text: string };

const ROOTS = ["app", "components", "lib"];
const ROOT_FILES = ["proxy.ts"];
const PUBLIC_FILE = /^\/(geo|img|fonts)\//;

function isRootRelative(text: string): boolean {
  return text.startsWith("/") && !text.startsWith("//");
}

/** The leading literal text of a string or template literal, or null for anything else. */
function leadingText(node: ts.Node): string | null {
  if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) return node.text;
  if (ts.isTemplateExpression(node)) return node.head.text;
  return null;
}

function isWrapped(node: ts.Node): boolean {
  let current: ts.Node | undefined = node.parent;
  while (current && (ts.isParenthesizedExpression(current) || ts.isAsExpression(current))) {
    current = current.parent;
  }
  return (
    !!current &&
    ts.isCallExpression(current) &&
    ts.isIdentifier(current.expression) &&
    current.expression.text === "withBasePath"
  );
}

function sourceFiles(root: string, dir: string, out: string[]): void {
  for (const entry of readdirSync(join(root, dir), { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      const skip =
        entry.name === "node_modules" ||
        entry.name === "__fixtures__" ||
        entry.name.startsWith(".");
      if (!skip) sourceFiles(root, path, out);
    } else if (/\.(ts|tsx)$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) {
      out.push(path);
    }
  }
}

export function baseCensus(appRoot: string): CensusHit[] {
  const files: string[] = [];
  for (const dir of ROOTS) if (existsSync(join(appRoot, dir))) sourceFiles(appRoot, dir, files);
  files.push(...ROOT_FILES.filter((file) => existsSync(join(appRoot, file))));

  const hits: CensusHit[] = [];
  for (const file of files) {
    const text = readFileSync(join(appRoot, file), "utf8");
    const source = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    const hit = (node: ts.Node, kind: string) => {
      const { line } = source.getLineAndCharacterOfPosition(node.getStart(source));
      hits.push({
        file: relative(appRoot, join(appRoot, file)).split(sep).join("/"),
        line: line + 1,
        kind,
        text: node.getText(source).slice(0, 80),
      });
    };

    const visit = (node: ts.Node): void => {
      // <a href="/…"> and <a href={`/…`}> — lowercase tag names only: those are raw elements
      // (HTML and SVG), while components such as <Link> prefix by themselves.
      if (ts.isJsxAttribute(node) && node.name.getText(source) === "href" && node.initializer) {
        const element = node.parent.parent;
        const tag =
          ts.isJsxOpeningElement(element) || ts.isJsxSelfClosingElement(element)
            ? element.tagName.getText(source)
            : "";
        if (tag === "a") {
          const value = ts.isJsxExpression(node.initializer)
            ? node.initializer.expression
            : node.initializer;
          const lead = value ? leadingText(value) : null;
          if (value && lead !== null && isRootRelative(lead) && !isWrapped(value))
            hit(node, "raw <a href>");
        }
      }

      if (ts.isCallExpression(node)) {
        const callee = node.expression.getText(source);
        const first = node.arguments[0];
        const lead = first ? leadingText(first) : null;
        if (first && lead !== null && isRootRelative(lead) && !isWrapped(first)) {
          if (callee === "fetch") hit(node, "fetch");
          if (/(^|\.)location\.(assign|replace)$/.test(callee)) hit(node, "location");
        }
      }

      if (ts.isNewExpression(node) && node.expression.getText(source) === "URL") {
        const first = node.arguments?.[0];
        const lead = first ? leadingText(first) : null;
        if (first && lead !== null && isRootRelative(lead) && (node.arguments?.length ?? 0) > 1) {
          hit(node, "new URL");
        }
      }

      if (
        ts.isBinaryExpression(node) &&
        node.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
        /(^|\.)location(\.href)?$/.test(node.left.getText(source))
      ) {
        const lead = leadingText(node.right);
        if (lead !== null && isRootRelative(lead) && !isWrapped(node.right)) hit(node, "location");
      }

      // A file under public/ named by URL: it reaches a fetch or a map library, never next/link.
      const lead = leadingText(node);
      if (lead !== null && PUBLIC_FILE.test(lead) && !isWrapped(node)) hit(node, "public file");

      ts.forEachChild(node, visit);
    };
    visit(source);
  }
  return hits;
}
