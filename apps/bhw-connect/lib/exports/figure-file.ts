import "server-only";
import { resvgFont } from "./render-png";
export type ExportPage = { svg: string; height: number };

/** SVG/PNG keep the complete chart; PDF/PPTX can use readable, complete pages. */
export async function figureFile(
  format: "svg" | "png" | "pdf" | "pptx",
  figure: ExportPage,
  title: string,
  subject: string,
  pages: ExportPage[] = [figure],
) {
  if (format === "svg") return { body: figure.svg, mime: "image/svg+xml" };
  const { Resvg } = await import("@resvg/resvg-js");
  const raster = (page: ExportPage) =>
    new Resvg(page.svg, { font: resvgFont(), fitTo: { mode: "width", value: 1600 } })
      .render()
      .asPng();
  if (format === "png") return { body: raster(figure), mime: "image/png" };
  if (format === "pdf") {
    const { PDFDocument } = await import("pdf-lib");
    const document = await PDFDocument.create();
    document.setTitle(title);
    document.setSubject(subject);
    for (const page of pages) {
      const image = await document.embedPng(raster(page));
      const sheet = document.addPage([600, page.height * 0.6]);
      sheet.drawImage(image, { x: 0, y: 0, width: 600, height: page.height * 0.6 });
    }
    return { body: await document.save(), mime: "application/pdf" };
  }
  const { default: PptxGenJS } = await import("pptxgenjs");
  const deck = new PptxGenJS();
  const maxHeight = Math.max(...pages.map((p) => p.height));
  const height = Math.min(maxHeight / 100, 50);
  const scale = height / (maxHeight / 100);
  deck.defineLayout({ name: "FIGURE", width: 10 * scale, height });
  deck.layout = "FIGURE";
  deck.title = title;
  deck.subject = subject;
  for (const page of pages)
    deck.addSlide().addImage({
      data: `image/png;base64,${Buffer.from(raster(page)).toString("base64")}`,
      x: 0,
      y: 0,
      w: 10 * scale,
      h: (page.height / 100) * scale,
    });
  return {
    body: new Uint8Array((await deck.write({ outputType: "uint8array" })) as Uint8Array),
    mime: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
  };
}
